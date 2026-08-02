<?php

namespace Kyte\Mvc\Controller;

class ApplicationController extends ModelController
{
    // public function hook_init() {}
    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                // Resolve the AWS credential for this application.
                //
                // Two paths: the Shipyard create form supplies a key inline; the
                // MCP create_app tool does NOT pass secrets and instead relies on
                // the account's already-configured key. Either way the resolved
                // key's public/private values are copied onto the Application row
                // (aws_public_key/aws_private_key) — the denormalized copy the
                // publish/media/CloudFront paths read today.
                //
                // FORWARD-LOOKING (KYTE-#205): this is the single resolution point
                // for application AWS credentials. When the credential model is
                // consolidated — a platform default via the EC2 instance role plus
                // an optional per-account override — only this block changes; the
                // rest of app creation is credential-agnostic.
                $aws = new \Kyte\Core\ModelObject(KyteAWSKey);
                $createdBy = isset($this->user->id) ? $this->user->id : null;
                if (isset($r['aws_public_key'], $r['aws_private_key'], $r['aws_username'])) {
                    // Inline key (Shipyard): reuse the account's matching row or create it.
                    if (!$aws->retrieve('private_key', $r['aws_private_key'], [['field' => 'public_key', 'value' => $r['aws_public_key']], ['field' => 'kyte_account', 'value' => $this->account->id]])) {
                        if (!$aws->create([
                            'private_key'  => $r['aws_private_key'],
                            'public_key'   => $r['aws_public_key'],
                            'username'     => $r['aws_username'],
                            'created_by'   => $createdBy,
                            'kyte_account' => $this->account->id,
                        ])) {
                            throw new \Exception("Unable to create new AWS credentials.");
                        }
                    }
                } else {
                    // No inline key (MCP): use the account's existing credential.
                    if (!$aws->retrieve('kyte_account', $this->account->id)) {
                        throw new \Exception('No AWS credentials are configured for this account. Add them in Shipyard before creating an application.');
                    }
                    $r['aws_public_key']  = $aws->public_key;
                    $r['aws_private_key'] = $aws->private_key;
                }
                $r['aws_key'] = $aws->id;

                // Application identifier + isolated tenant database (on the
                // platform RDS — no S3 credentials needed for app creation).
                $r['identifier']  = uniqid();
                $r['db_name']     = $r['identifier'] . '_' . $this->account->number;
                $r['db_username'] = 'db' . $r['identifier'];
                if (empty($r['db_password'])) {
                    $r['db_password'] = bin2hex(random_bytes(16));
                }

                \Kyte\Core\DBI::createDatabase($r['db_name'], $r['db_username'], $r['db_password']);

                break;
            
            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'update':
                if (isset($d['republish_kyte_connect']) && $d['republish_kyte_connect'] == 1) {
                    $sites = new \Kyte\Core\Model(KyteSite);
                    $sites->retrieve('application', $o->id);

                    // Fault-isolated republish summary. A single bad page must NOT abort the
                    // whole batch and strand later pages on the stale connect string. See KYTE-#181.
                    $republishSummary = ['succeeded' => 0, 'failed' => 0, 'failures' => []];

                    foreach($sites->objects as $site) {
                        $credential = new \Kyte\Aws\Credentials($site->region, $o->aws_public_key, $o->aws_private_key);
                        $s3 = new \Kyte\Aws\S3($credential, $site->s3BucketName);

                        $pages = new \Kyte\Core\Model(KytePage);
                        $pages->retrieve("state", 1, false, [['field' => 'site', 'value' => $site->id]]);

                        foreach($pages->objects as $page) {
                            // Re-stamp each page independently; collect failures and continue
                            // instead of throwing and aborting the remaining pages/sites.
                            try {
                                $params = $this->getObject($page);
                                $pd = new \Kyte\Core\ModelObject(KytePageData);
                                if (!$pd->retrieve('page', $page->id)) {
                                    throw new \Exception("Unable to find page data (KytePageData) for page {$page->id}.");
                                }

                                $params['html'] = bzdecompress($pd->html);
                                $params['stylesheet'] = bzdecompress($pd->stylesheet);
                                $params['javascript'] = bzdecompress($pd->javascript);
                                // footers and headers
                                if ($params['footer'] && isset($params['footer']['html'], $params['footer']['stylesheet'], $params['footer']['javascript'], $params['footer']['block_layout'])) {
                                    $params['footer']['html'] = bzdecompress($params['footer']['html']);
                                    $params['footer']['stylesheet'] = bzdecompress($params['footer']['stylesheet']);
                                    $params['footer']['javascript'] = bzdecompress($params['footer']['javascript']);
                                    $params['footer']['block_layout'] = bzdecompress($params['footer']['block_layout']);
                                }
                                if ($params['header'] && isset($params['header']['html'], $params['header']['stylesheet'], $params['header']['javascript'], $params['header']['block_layout'])) {
                                    $params['header']['html'] = bzdecompress($params['header']['html']);
                                    $params['header']['stylesheet'] = bzdecompress($params['header']['stylesheet']);
                                    $params['header']['javascript'] = bzdecompress($params['header']['javascript']);
                                    $params['header']['block_layout'] = bzdecompress($params['header']['block_layout']);
                                }
                                // compile html file
                                $data = \Kyte\Mvc\Controller\KytePageController::createHtml($params);
                                // write to file
                                $s3->write($page->s3key, $data);
                                $republishSummary['succeeded']++;
                            } catch (\Throwable $e) {
                                $republishSummary['failed']++;
                                $republishSummary['failures'][] = [
                                    'page' => $page->id,
                                    's3key' => $page->s3key,
                                    'site' => $site->id,
                                    'reason' => $e->getMessage(),
                                ];
                                error_log("Republish: failed to re-stamp page {$page->id} (s3key {$page->s3key}, site {$site->id}): " . $e->getMessage());
                                continue;
                            }
                        }

                        // Invalidate THIS site's CloudFront. Previously this ran once outside the
                        // sites loop, so only the last site was invalidated — multi-site apps left
                        // every other site's cache stale. See KYTE-#181.
                        try {
                            $invalidationPaths = ['/*'];
                            $cf = new \Kyte\Aws\CloudFront($credential);
                            $cf->createInvalidation($site->cfDistributionId, $invalidationPaths);
                        } catch (\Throwable $e) {
                            error_log("Republish: CloudFront invalidation failed for site {$site->id}: " . $e->getMessage());
                        }
                    }

                    // Surface the result so the caller (Shipyard) can show a real summary
                    // instead of trusting a silent all-or-nothing hook.
                    $r['republish_summary'] = $republishSummary;
                    if ($republishSummary['failed'] > 0) {
                        error_log("Republish completed with {$republishSummary['failed']} failure(s) of " . ($republishSummary['succeeded'] + $republishSummary['failed']) . " page(s): " . json_encode($republishSummary['failures']));
                    }
                }
                break;
            case 'delete':
                // // get AWS credentials
                // $credentials = new \Kyte\Aws\Credentials('us-east-1');

                // // disable distribution
                // $cf = new \Kyte\Aws\CloudFront($credentials, $o->cfDistribution);
                // $cf->disable();

                // // delete distribution
                // $cf->delete();

                // delete database from cluster
                \Kyte\Core\DBI::query("DROP DATABASE `{$o->db_name}`;");

                // // delete acm certificate
                // $acm = new \Kyte\Aws\Acm($credentials, $o->AcmArn);
                // $acm->delete();

                // // delete s3 bucket
                // $s3 = new \Kyte\Aws\S3($credentials, $o->s3bucket, 'public');
                // // $s3->emptyBucket(); <- create method
                // // $s3->deleteBucket();

                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}
}
