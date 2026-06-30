<?php

namespace Kyte\Cron;

use Kyte\Core\CronJobBase;
use Kyte\Core\DBI;
use Kyte\Core\ModelObject;

/**
 * SiteProvisioningWorker
 *
 * Provisions and tears down KyteSite AWS resources (2 S3 buckets + 2 CloudFront
 * distributions per site, + ACM certs on delete), replacing the SNS-driven
 * kyte-lambda-site-management state machine and the kyte-lambda-database-transaction
 * DB writer (the worker writes the DB directly). KYTE-#201 #3.
 *
 * Tick model (interval ~20-30s, allow_concurrent=0): each tick scans KyteSite rows
 * in 'creating'/'deleting' and advances each ONE actionable step. Sub-state is
 * inferred from which s3/cf columns are populated, and every AWS op is idempotent
 * (create_bucket tolerates already-owned; CF ids are persisted the instant the
 * distribution is created; bucket/CF teardown tolerates already-gone). CloudFront
 * create/delete take minutes, so deployment is POLLED across ticks (status stays
 * creating/deleting) with heartbeat() — never a blocking sleep in one execute().
 *
 * Failure handling: a per-site exception bumps provisioning_attempts + records
 * provisioning_message; after MAX_ATTEMPTS the site flips to 'failed' rather than
 * looping forever (the dashboard shows the message).
 */
class SiteProvisioningWorker extends CronJobBase
{
    /** ~ MAX_ATTEMPTS * interval should comfortably exceed CF deploy time (5-15 min). */
    private const MAX_ATTEMPTS = 120;

    public function execute()
    {
        $sites = DBI::prepared_query(
            "SELECT * FROM KyteSite WHERE status IN ('creating','deleting') AND deleted = 0 ORDER BY date_modified ASC, id ASC",
            '',
            []
        );
        if (empty($sites)) {
            return json_encode(['processed' => 0, 'message' => 'No sites provisioning/deprovisioning.']);
        }

        $summary = [];
        foreach ($sites as $i => $site) {
            $id = (int) $site['id'];
            try {
                $app = new ModelObject(Application);
                if (!$app->retrieve('id', $site['application'])) {
                    throw new \Exception("Application #{$site['application']} not found for site #{$id}.");
                }
                $credential = new \Kyte\Aws\Credentials($site['region'], $app->aws_public_key, $app->aws_private_key);

                if ($site['status'] === 'creating') {
                    $summary[$id] = $this->advanceCreate($credential, $site);
                } else {
                    $summary[$id] = $this->advanceDelete($credential, $site);
                }
                // A successful step clears any prior transient-error state so the
                // attempts counter doesn't creep toward the give-up cap and the
                // dashboard doesn't show a stale error.
                if ((int) $site['provisioning_attempts'] > 0) {
                    $this->updateSite($id, ['provisioning_attempts' => 0, 'provisioning_message' => null]);
                }
            } catch (\Throwable $e) {
                $this->recordFailure($id, (int) $site['provisioning_attempts'], $site['status'], $e->getMessage());
                $summary[$id] = 'error: ' . $e->getMessage();
                $this->log("Site #{$id} step failed: " . $e->getMessage());
            }
            $this->heartbeat();
        }

        return json_encode(['processed' => count($sites), 'sites' => $summary]);
    }

    /**
     * Advance a 'creating' site one step. Per-resource guards make this safe to
     * re-run every tick: each block runs only while its column is empty.
     *
     * @param array<string,mixed> $site
     */
    private function advanceCreate($credential, array $site): string
    {
        $id     = (int) $site['id'];
        $region = $site['region'];

        // 1. Website bucket: create + configure, then persist s3BucketName (only
        //    after full config, so a mid-way failure re-runs the whole block).
        if (empty($site['s3BucketName'])) {
            $bucket = $this->deriveBucketName($site['name'], false);
            $this->setupBucket($credential, $bucket, true);
            $this->updateSite($id, ['s3BucketName' => $bucket]);
            return "website bucket created ({$bucket})";
        }

        // 2. Media bucket.
        if (empty($site['s3MediaBucketName'])) {
            $bucket = $this->deriveBucketName($site['name'], true);
            $this->setupBucket($credential, $bucket, false);
            $this->updateSite($id, ['s3MediaBucketName' => $bucket]);
            return "media bucket created ({$bucket})";
        }

        // 3. Website CloudFront — persist the id IMMEDIATELY (create is not idempotent).
        if (empty($site['cfDistributionId'])) {
            $origin = \Kyte\Mvc\Controller\KyteSiteController::getWebsiteEndpoint($site['s3BucketName'], $region);
            [$cfId, $cfDomain] = $this->createDistribution($credential, $site['s3BucketName'], $origin, $region, true);
            $this->updateSite($id, ['cfDistributionId' => $cfId, 'cfDomain' => $cfDomain]);
            return "website distribution created ({$cfId})";
        }

        // 4. Media CloudFront.
        if (empty($site['cfMediaDistributionId'])) {
            $origin = $site['s3MediaBucketName'] . '.s3.amazonaws.com';
            [$cfId, $cfDomain] = $this->createDistribution($credential, $site['s3MediaBucketName'], $origin, $region, false);
            $this->updateSite($id, ['cfMediaDistributionId' => $cfId, 'cfMediaDomain' => $cfDomain]);
            return "media distribution created ({$cfId})";
        }

        // 5. Both distributions exist — poll until both Deployed, then activate.
        if ($this->isDeployed($credential, $site['cfDistributionId']) &&
            $this->isDeployed($credential, $site['cfMediaDistributionId'])) {
            $this->updateSite($id, ['status' => 'active', 'provisioning_message' => null, 'provisioning_attempts' => 0]);
            return 'active';
        }
        return 'awaiting CloudFront deployment';
    }

    /**
     * Advance a 'deleting' site one step. Tears down media then website (bucket
     * then its distribution), then ACM certs + Domain rows, then marks deleted.
     *
     * @param array<string,mixed> $site
     */
    private function advanceDelete($credential, array $site): string
    {
        $id = (int) $site['id'];

        // 1/2. Media bucket + distribution.
        if (!empty($site['s3MediaBucketName'])) {
            $this->teardownBucket($credential, $site['s3MediaBucketName']);
            $this->updateSite($id, ['s3MediaBucketName' => null]);
            return 'media bucket deleted';
        }
        if (!empty($site['cfMediaDistributionId'])) {
            if ($this->teardownDistribution($credential, $site['cfMediaDistributionId']) === 'deleted') {
                $this->updateSite($id, ['cfMediaDistributionId' => null]);
                return 'media distribution deleted';
            }
            return 'disabling/awaiting media distribution';
        }

        // 3/4. Website bucket + distribution.
        if (!empty($site['s3BucketName'])) {
            $this->teardownBucket($credential, $site['s3BucketName']);
            $this->updateSite($id, ['s3BucketName' => null]);
            return 'website bucket deleted';
        }
        if (!empty($site['cfDistributionId'])) {
            if ($this->teardownDistribution($credential, $site['cfDistributionId']) === 'deleted') {
                $this->updateSite($id, ['cfDistributionId' => null]);
                return 'website distribution deleted';
            }
            return 'disabling/awaiting website distribution';
        }

        // 5. All AWS resources gone — delete ACM certs (now free of the distribution),
        //    their Domain/SAN rows, then mark the site deleted.
        $this->teardownDomains($credential, $id);
        $this->updateSite($id, [
            'status'       => 'deleted',
            'deleted'      => 1,
            'date_deleted' => time(),
        ]);
        return 'deleted';
    }

    /** name → bucket name, matching KyteSiteController's scheme (sans timestamp; the row already exists). */
    private function deriveBucketName(string $name, bool $isMedia): string
    {
        $base = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '-', $name));
        // Keep the historical timestamp suffix shape so names stay unique per site.
        return $isMedia ? $base . '-static-assets-' . time() : $base . '-' . time();
    }

    /** Create + fully configure a public-read S3 bucket (website or media). Idempotent. */
    private function setupBucket($credential, string $bucket, bool $isWebsite): void
    {
        $s3 = new \Kyte\Aws\S3($credential, $bucket, 'public');
        $s3->createBucket();                 // tolerates BucketAlreadyOwnedByYou
        $s3->deletePublicAccessBlock();
        $s3->enablePublicAccess();           // public-read GetObject policy
        if ($isWebsite) {
            $s3->createWebsite('index.html', 'error.html');
        } else {
            $s3->enableCors([[
                'AllowedHeaders' => ['*'],
                'AllowedMethods' => ['GET', 'POST'],
                'AllowedOrigins' => ['*'],
            ]]);
        }
    }

    /**
     * Create a CloudFront distribution mirroring the Lambda's config (OriginShield,
     * http-only custom origin, redirect-to-https, compress, DefaultTTL 86400 / MinTTL 3600).
     *
     * @return array{0:string,1:string} [distributionId, domainName]
     */
    private function createDistribution($credential, string $bucket, string $origin, string $region, bool $isWebsite): array
    {
        $cf = new \Kyte\Aws\CloudFront($credential);
        $cf->DefaultRootObject = $isWebsite ? 'index.html' : '';
        $cf->Comment           = 'Created by Kyte for bucket ' . $bucket;
        $cf->MinTTL            = 3600;
        $cf->DefaultTTL        = 86400;
        $cf->PriceClass        = 'PriceClass_All';
        $cf->addOrigin($origin, $bucket, '', true, $region, false, 'http-only');
        $cf->create();
        return [$cf->Id, $cf->domainName];
    }

    /** True once the distribution is Deployed. */
    private function isDeployed($credential, string $distributionId): bool
    {
        $cf = new \Kyte\Aws\CloudFront($credential, $distributionId);
        $cf->getDistribution();
        return $cf->status === 'Deployed';
    }

    /** Empty + delete a bucket. Idempotent (already-gone is success). */
    private function teardownBucket($credential, string $bucket): void
    {
        $s3 = new \Kyte\Aws\S3($credential, $bucket, 'public');
        $s3->emptyBucket();
        try {
            $s3->deleteBucket();
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), 'NoSuchBucket') === false) {
                throw $e;
            }
        }
    }

    /**
     * Disable then delete a distribution across ticks.
     * @return string 'deleted' once gone, else 'pending' (disabling/deploying).
     */
    private function teardownDistribution($credential, string $distributionId): string
    {
        $cf = new \Kyte\Aws\CloudFront($credential, $distributionId);
        try {
            $cf->getDistribution();
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), 'NoSuchDistribution') !== false) {
                return 'deleted'; // already gone
            }
            throw $e;
        }

        // Mid-update (InProgress) — wait for the next tick.
        if ($cf->status !== 'Deployed') {
            return 'pending';
        }
        // Still enabled — disable, then wait for it to redeploy disabled.
        if ($cf->isEnabled()) {
            $cf->disable();
            return 'pending';
        }
        // Disabled + Deployed — safe to delete.
        $cf->delete();
        return 'deleted';
    }

    /**
     * Delete the site's ACM certs (now detached from the deleted distributions)
     * and soft-delete their Domain/SAN rows. Uses raw SQL (table names) rather than
     * model-name constants, which aren't reliably defined in the eval'd cron-worker
     * context (they resolve to the worker's namespace and fall through).
     */
    private function teardownDomains($credential, int $siteId): void
    {
        $domains = DBI::prepared_query(
            "SELECT id, certificateArn FROM Domain WHERE site = ? AND deleted = 0",
            'i',
            [$siteId]
        );
        foreach ($domains as $domain) {
            if (!empty($domain['certificateArn'])) {
                try {
                    $acm = new \Kyte\Aws\Acm($credential, $domain['certificateArn']);
                    $acm->delete();
                } catch (\Throwable $e) {
                    // Best-effort: a cert already gone (e.g. a prior tick) is fine.
                    $this->log("ACM delete for cert {$domain['certificateArn']} (site #{$siteId}): " . $e->getMessage());
                }
            }
            DBI::prepared_query(
                "UPDATE SubjectAlternativeName SET deleted = 1, date_deleted = UNIX_TIMESTAMP() WHERE domain = ? AND deleted = 0",
                'i',
                [$domain['id']]
            );
            DBI::prepared_query(
                "UPDATE Domain SET deleted = 1, date_deleted = UNIX_TIMESTAMP() WHERE id = ?",
                'i',
                [$domain['id']]
            );
        }
    }

    private function recordFailure(int $id, int $attempts, string $status, string $message): void
    {
        $attempts++;
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->updateSite($id, [
                'status'                => 'failed',
                'provisioning_attempts' => $attempts,
                'provisioning_message'  => "Gave up after {$attempts} attempts (was {$status}): " . $message,
            ]);
        } else {
            $this->updateSite($id, [
                'provisioning_attempts' => $attempts,
                'provisioning_message'  => $message,
            ]);
        }
    }

    /**
     * Direct KyteSite write (the database-transaction Lambda's job). Null values
     * are bound as SQL NULL (used to clear cf/s3 columns during teardown).
     *
     * @param array<string,mixed> $fields
     */
    private function updateSite(int $id, array $fields): void
    {
        $sets  = [];
        $types = '';
        $vals  = [];
        foreach ($fields as $col => $val) {
            $sets[]  = "`{$col}` = ?";
            $types  .= is_int($val) ? 'i' : 's';
            $vals[]  = $val;
        }
        $sets[]  = 'date_modified = UNIX_TIMESTAMP()';
        $types  .= 'i';
        $vals[]  = $id;
        DBI::prepared_query("UPDATE KyteSite SET " . implode(', ', $sets) . " WHERE id = ?", $types, $vals);
    }
}
