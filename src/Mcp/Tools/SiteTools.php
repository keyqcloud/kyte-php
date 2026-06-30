<?php
namespace Kyte\Mcp\Tools;

use Kyte\Core\Api;
use Kyte\Mcp\Attribute\RequiresScope;
use Mcp\Capability\Attribute\McpTool;

/**
 * Site provisioning tools (create / delete / update / read a single site).
 *
 * Mutations are gated by the `provision` scope (held separately from content
 * read/draft/commit, so a token can author pages/scripts without the right to
 * spin up or tear down infrastructure). Reads use `read`.
 *
 * The actual AWS work (2 S3 buckets + 2 CloudFront distributions, + ACM on
 * delete) runs OUT OF BAND in the SiteProvisioningWorker cron job (KYTE-#201) —
 * these tools only flip the KyteSite row's status (creating/deleting) and return
 * immediately. Callers poll read_site until status reaches "active"/"deleted"
 * (CloudFront deploy/teardown takes minutes). Each create/delete/update goes
 * through KyteSiteController in INTERNAL mode (no session — the controller is
 * constructed with the internal flag, mirroring DraftService's publish path),
 * and every caller-supplied id is re-scoped to the token's account before use.
 *
 * Custom-domain / aliasDomain assignment is intentionally NOT exposed here —
 * that's the ACM + DNS path (KYTE-#320), a separate tool.
 *
 * Infra fields (s3BucketName, cfDistributionId, etc.) are omitted from output
 * for the same reason PageTools omits them — deployment details, not user-facing
 * site attributes. cfDomain (the live *.cloudfront.net URL) IS surfaced.
 */
final class SiteTools
{
    public function __construct(private readonly Api $api)
    {
    }

    /**
     * Create a new site in a Kyte application.
     *
     * Provisioning (S3 + CloudFront) runs in the background; the returned site
     * starts at status "creating". Poll read_site until status is "active"
     * (CloudFront deploy is typically 5-15 minutes).
     *
     * @param int    $application_id Application id (from list_applications).
     * @param string $name           Human-facing site name.
     * @param string $region         AWS region for the site's S3 buckets, e.g. "us-east-1", "us-east-2", "eu-west-1".
     * @return array{created: bool, site?: array<string,mixed>, message?: string, error?: string}
     */
    #[McpTool(name: 'create_site', description: 'Create a new site in a Kyte application. Provisioning (S3 + CloudFront) runs in the background — poll read_site until status is "active".')]
    #[RequiresScope('provision')]
    public function createSite(int $application_id, string $name, string $region): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->applicationBelongsToAccount($application_id, $accountId)) {
            return ['created' => false, 'error' => 'Application not found in this account.'];
        }

        $api  = $this->api;
        $resp = [];
        try {
            $controller = new \Kyte\Mvc\Controller\KyteSiteController(\KyteSite, $api, 'm/d/Y H:i:s', $resp, true);
            // kyte_account is auto-filled from the token's account by ModelController::new.
            $controller->new([
                'application' => $application_id,
                'name'        => $name,
                'region'      => $region,
            ]);
        } catch (\Throwable $e) {
            return ['created' => false, 'error' => $e->getMessage()];
        }

        $newId = isset($resp['data'][0]['id']) ? (int)$resp['data'][0]['id'] : 0;
        if ($newId === 0) {
            return ['created' => false, 'error' => 'Site was not created.'];
        }

        $site = new \Kyte\Core\ModelObject(\KyteSite);
        $site->retrieve('id', $newId);
        return [
            'created' => true,
            'site'    => $this->siteToArray($site),
            'message' => 'Site created; provisioning in the background (status "creating"). Poll read_site until status is "active" (CloudFront deploy ~5-15 min).',
        ];
    }

    /**
     * Delete a site (and tear down its AWS resources).
     *
     * Flips the site to "deleting"; the worker empties+deletes the buckets,
     * disables+deletes the distributions, and removes any ACM certs out of band.
     * Poll read_site until status is "deleted" (teardown ~10-30 min).
     *
     * @param int $site_id Site id (from list_sites / read_site).
     * @return array{deleting: bool, site_id?: int, message?: string, error?: string}
     */
    #[McpTool(name: 'delete_site', description: 'Delete a site and tear down its AWS resources (S3 + CloudFront + ACM) in the background. Poll read_site until status is "deleted".')]
    #[RequiresScope('provision')]
    public function deleteSite(int $site_id): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->siteBelongsToAccount($site_id, $accountId)) {
            return ['deleting' => false, 'error' => 'Site not found in this account.'];
        }

        $api  = $this->api;
        $resp = [];
        try {
            $controller = new \Kyte\Mvc\Controller\KyteSiteController(\KyteSite, $api, 'm/d/Y H:i:s', $resp, true);
            $controller->delete('id', $site_id);
        } catch (\Throwable $e) {
            return ['deleting' => false, 'error' => $e->getMessage()];
        }

        return [
            'deleting' => true,
            'site_id'  => $site_id,
            'message'  => 'Site teardown started (status "deleting"). Poll read_site until status is "deleted" (CloudFront teardown ~10-30 min).',
        ];
    }

    /**
     * Update a site's settings. Only the listed fields are editable here;
     * custom-domain (aliasDomain) assignment is a separate tool (ACM + DNS).
     *
     * @param int          $site_id      Site id.
     * @param string|null  $description  New description.
     * @param string|null  $default_lang Default language code (e.g. "en").
     * @param string|null  $ga_code      Google Analytics measurement id.
     * @param string|null  $gtm_code     Google Tag Manager container id.
     * @return array{updated: bool, site?: array<string,mixed>, error?: string}
     */
    #[McpTool(name: 'update_site', description: 'Update a site\'s settings (description, default_lang, ga_code, gtm_code). Custom domains are handled by a separate tool.')]
    #[RequiresScope('provision')]
    public function updateSite(int $site_id, ?string $description = null, ?string $default_lang = null, ?string $ga_code = null, ?string $gtm_code = null): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->siteBelongsToAccount($site_id, $accountId)) {
            return ['updated' => false, 'error' => 'Site not found in this account.'];
        }

        $data = [];
        if ($description !== null)  { $data['description']  = $description; }
        if ($default_lang !== null) { $data['default_lang'] = $default_lang; }
        if ($ga_code !== null)      { $data['ga_code']      = $ga_code; }
        if ($gtm_code !== null)     { $data['gtm_code']     = $gtm_code; }
        if (empty($data)) {
            return ['updated' => false, 'error' => 'No updatable fields provided (description, default_lang, ga_code, gtm_code).'];
        }

        $api  = $this->api;
        $resp = [];
        try {
            $controller = new \Kyte\Mvc\Controller\KyteSiteController(\KyteSite, $api, 'm/d/Y H:i:s', $resp, true);
            $controller->update('id', $site_id, $data);
        } catch (\Throwable $e) {
            return ['updated' => false, 'error' => $e->getMessage()];
        }

        $site = new \Kyte\Core\ModelObject(\KyteSite);
        $site->retrieve('id', $site_id);
        return ['updated' => true, 'site' => $this->siteToArray($site)];
    }

    /**
     * Read a single site by id, including provisioning status. Use this to poll
     * after create_site (until "active") or delete_site (until "deleted").
     *
     * @param int $site_id Site id.
     * @return array{site: array<string,mixed>|null}
     */
    #[McpTool(name: 'read_site', description: 'Read a single site by id, including provisioning status (creating/active/deleting/deleted/failed). Poll this after create_site/delete_site.')]
    #[RequiresScope('read')]
    public function readSite(int $site_id): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->siteBelongsToAccount($site_id, $accountId)) {
            return ['site' => null];
        }
        $site = new \Kyte\Core\ModelObject(\KyteSite);
        if (!$site->retrieve('id', $site_id)) {
            return ['site' => null];
        }
        return ['site' => $this->siteToArray($site)];
    }

    /**
     * Map a KyteSite to the response shape. Surfaces the live cfDomain URL and
     * provisioning status/message; omits raw infra ids (bucket/distribution).
     *
     * @return array<string,mixed>
     */
    private function siteToArray(\Kyte\Core\ModelObject $s): array
    {
        return [
            'id'                   => (int)$s->id,
            'name'                 => (string)($s->name ?? ''),
            'status'               => (string)($s->status ?? ''),
            'region'               => $s->region !== null ? (string)$s->region : null,
            'default_lang'         => $s->default_lang !== null ? (string)$s->default_lang : null,
            'description'          => $s->description !== null ? (string)$s->description : null,
            'ga_code'              => $s->ga_code !== null ? (string)$s->ga_code : null,
            'gtm_code'             => $s->gtm_code !== null ? (string)$s->gtm_code : null,
            'alias_domain'         => $s->aliasDomain !== null ? (string)$s->aliasDomain : null,
            'cloudfront_domain'    => $s->cfDomain !== null ? (string)$s->cfDomain : null,
            'provisioning_message' => $s->provisioning_message !== null ? (string)$s->provisioning_message : null,
        ];
    }

    private function accountIdOrZero(): int
    {
        return isset($this->api->account->id) ? (int)$this->api->account->id : 0;
    }

    private function applicationBelongsToAccount(int $applicationId, int $accountId): bool
    {
        $app = new \Kyte\Core\ModelObject(\Application);
        return $app->retrieve('id', $applicationId) && (int)$app->kyte_account === $accountId;
    }

    private function siteBelongsToAccount(int $siteId, int $accountId): bool
    {
        $site = new \Kyte\Core\ModelObject(\KyteSite);
        return $site->retrieve('id', $siteId) && (int)$site->kyte_account === $accountId;
    }
}
