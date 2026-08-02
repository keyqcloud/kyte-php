<?php
namespace Kyte\Mcp\Tools;

use Kyte\Core\Api;
use Kyte\Mcp\Attribute\RequiresScope;
use Mcp\Capability\Attribute\McpTool;

/**
 * Application lifecycle tools (create / update / delete). Reads
 * (list_applications) live in AccountTools.
 *
 * Creating an application provisions an isolated tenant database on the platform
 * RDS and binds the account's AWS credential (ApplicationController). No S3
 * bucket is created for an app — site buckets + CloudFront are per-site,
 * provisioned by the site tools + the KYTE-#201 worker. The MCP path never
 * handles raw AWS secrets: create_application uses the account's already-
 * configured credential; a custom key must be set in Shipyard first.
 *
 * delete_application is deliberately conservative: it refuses to delete an app
 * that still has live sites, because their AWS teardown (S3 + CloudFront + ACM)
 * is asynchronous and deleting the app first would orphan that infrastructure.
 * Tear sites down with delete_site, then delete the app. Full cascade teardown +
 * an app-level lifecycle status (for in-progress UI) is tracked separately.
 *
 * Mutations gated by `provision`; every id is re-scoped to the token's account.
 */
final class AppTools
{
    public function __construct(private readonly Api $api)
    {
    }

    /**
     * Create a new Kyte application (provisions an isolated tenant database).
     *
     * @param string      $name     Application name.
     * @param string|null $language Default language code (e.g. "en"); optional.
     * @return array{created: bool, application?: array<string,mixed>|null, error?: string}
     */
    #[McpTool(name: 'create_application', description: 'Create a new Kyte application (provisions an isolated tenant database). Uses the account\'s configured AWS credential — set one in Shipyard first if none exists.')]
    #[RequiresScope('provision')]
    public function createApplication(string $name, ?string $language = null): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0) {
            return ['created' => false, 'error' => 'No account context.'];
        }

        $api  = $this->api;
        $resp = [];
        try {
            $controller = new \Kyte\Mvc\Controller\ApplicationController(\Application, $api, 'm/d/Y H:i:s', $resp, true);
            $data = ['name' => $name];
            if ($language !== null) { $data['language'] = $language; }
            $controller->new($data);
        } catch (\Throwable $e) {
            return ['created' => false, 'error' => $e->getMessage()];
        }

        $newId = isset($resp['data'][0]['id']) ? (int)$resp['data'][0]['id'] : 0;
        if ($newId === 0) {
            return ['created' => false, 'error' => 'Application was not created.'];
        }
        return ['created' => true, 'application' => $this->appToArray($newId)];
    }

    /**
     * Update a Kyte application's name or default language.
     *
     * @param int         $application_id Application id.
     * @param string|null $name           New name.
     * @param string|null $language       New default language code.
     * @return array{updated: bool, application?: array<string,mixed>|null, error?: string}
     */
    #[McpTool(name: 'update_application', description: 'Update a Kyte application\'s name or default language.')]
    #[RequiresScope('provision')]
    public function updateApplication(int $application_id, ?string $name = null, ?string $language = null): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->appBelongsToAccount($application_id, $accountId)) {
            return ['updated' => false, 'error' => 'Application not found in this account.'];
        }

        $data = [];
        if ($name !== null)     { $data['name'] = $name; }
        if ($language !== null) { $data['language'] = $language; }
        if (empty($data)) {
            return ['updated' => false, 'error' => 'No updatable fields provided (name, language).'];
        }

        $api  = $this->api;
        $resp = [];
        try {
            $controller = new \Kyte\Mvc\Controller\ApplicationController(\Application, $api, 'm/d/Y H:i:s', $resp, true);
            $controller->update('id', $application_id, $data);
        } catch (\Throwable $e) {
            return ['updated' => false, 'error' => $e->getMessage()];
        }
        return ['updated' => true, 'application' => $this->appToArray($application_id)];
    }

    /**
     * Delete a Kyte application. This starts an ASYNCHRONOUS teardown: the app
     * (and each of its sites) is marked "deleting", a background worker tears
     * down all site AWS infrastructure (S3 + CloudFront + ACM), and once that's
     * done it drops the tenant database and removes the app. Poll
     * list_applications until the app disappears.
     *
     * @param int $application_id Application id.
     * @return array{deleting: bool, application_id?: int, sites_tearing_down?: int, message?: string, error?: string}
     */
    #[McpTool(name: 'delete_application', description: 'Delete a Kyte application — starts an asynchronous teardown of its sites (S3/CloudFront/ACM) and then its tenant database. Poll list_applications until the app disappears.')]
    #[RequiresScope('provision')]
    public function deleteApplication(int $application_id): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->appBelongsToAccount($application_id, $accountId)) {
            return ['deleting' => false, 'error' => 'Application not found in this account.'];
        }

        $app = new \Kyte\Core\ModelObject(\Application);
        $app->retrieve('id', $application_id);
        if ((string)($app->status ?? '') === 'deleting') {
            return ['deleting' => true, 'application_id' => $application_id, 'message' => 'Teardown already in progress. Poll list_applications until the app disappears.'];
        }

        // ApplicationController's delete hook marks the app + its sites 'deleting'
        // (it does NOT drop anything synchronously); the SiteProvisioningWorker
        // finalizes the teardown.
        $api  = $this->api;
        $resp = [];
        try {
            $controller = new \Kyte\Mvc\Controller\ApplicationController(\Application, $api, 'm/d/Y H:i:s', $resp, true);
            $controller->delete('id', $application_id);
        } catch (\Throwable $e) {
            return ['deleting' => false, 'error' => $e->getMessage()];
        }

        $sites = new \Kyte\Core\Model(\KyteSite);
        $sites->retrieve('application', $application_id, false, [
            ['field' => 'kyte_account', 'value' => $accountId],
        ]);
        $n = 0;
        foreach ($sites->objects as $s) {
            if ((string)($s->status ?? '') === 'deleting') { $n++; }
        }

        return [
            'deleting'           => true,
            'application_id'     => $application_id,
            'sites_tearing_down' => $n,
            'message'            => "Teardown started. {$n} site(s) tearing down in the background (S3/CloudFront take minutes); the tenant database drops and the app is removed once they're gone. Poll list_applications until it disappears.",
        ];
    }

    /** @return array<string,mixed>|null */
    private function appToArray(int $appId): ?array
    {
        $app = new \Kyte\Core\ModelObject(\Application);
        if (!$app->retrieve('id', $appId)) {
            return null;
        }
        return [
            'id'         => (int)$app->id,
            'name'       => isset($app->name) ? (string)$app->name : '',
            'identifier' => isset($app->identifier) ? (string)$app->identifier : '',
            'language'   => isset($app->language) ? (string)$app->language : null,
            'status'     => isset($app->status) ? (string)$app->status : 'active',
        ];
    }

    private function accountIdOrZero(): int
    {
        return isset($this->api->account->id) ? (int)$this->api->account->id : 0;
    }

    private function appBelongsToAccount(int $appId, int $accountId): bool
    {
        $app = new \Kyte\Core\ModelObject(\Application);
        return $app->retrieve('id', $appId) && (int)$app->kyte_account === $accountId;
    }
}
