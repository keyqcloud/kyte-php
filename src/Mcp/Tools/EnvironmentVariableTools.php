<?php
namespace Kyte\Mcp\Tools;

use Kyte\Core\Api;
use Kyte\Mcp\Attribute\RequiresScope;
use Mcp\Capability\Attribute\McpTool;

/**
 * Per-application environment-variable tools (KYTE_APP_ENV).
 *
 * A Kyte app can hold key/value environment variables (the KyteEnvironmentVariable
 * model). At request time the framework loads them into the global PHP constant
 * KYTE_APP_ENV (Api::defineAppEnvironmentConstants), so controller/function code
 * reads config + secrets as KYTE_APP_ENV['KEY'] instead of hard-coding them.
 * Before these tools, env vars were settable only in the Shipyard UI, so an
 * MCP-driven build could write a controller that needs (say) a Stripe key but
 * could not provision it. These close that gap.
 *
 * SECRETS: values are treated as write-only over MCP. list_app_env_vars returns
 * the KEYS (which the AI needs to reference KYTE_APP_ENV['KEY']) but never the
 * values — only whether each is set. Mutations are gated by `provision` and every
 * application_id is re-scoped to the token's account.
 */
final class EnvironmentVariableTools
{
    public function __construct(private readonly Api $api)
    {
    }

    /**
     * List an app's environment-variable KEYS (values are never returned).
     *
     * @param int $application_id Application id (from list_applications).
     * @return array{application_id?: int, count?: int, variables: array<int,array{id:int,key:string,has_value:bool}>, error?: string}
     */
    #[McpTool(name: 'list_app_env_vars', description: 'List an application\'s environment-variable KEYS (the names a controller reads as KYTE_APP_ENV[\'KEY\']). Values are secrets and are NOT returned — each entry reports only whether a value is set (has_value). Use this to see what config an app already has before referencing or setting it.')]
    #[RequiresScope('read')]
    public function listAppEnvVars(int $application_id): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->appBelongsToAccount($application_id, $accountId)) {
            return ['error' => 'Application not found in this account.', 'variables' => []];
        }

        $m = new \Kyte\Core\Model(\KyteEnvironmentVariable);
        $m->retrieve('application', $application_id, false, [['field' => 'kyte_account', 'value' => $accountId]]);
        $vars = [];
        foreach ($m->objects as $o) {
            $vars[] = [
                'id'        => (int)$o->id,
                'key'       => (string)$o->key,
                'has_value' => !empty($o->value),
            ];
        }
        return ['application_id' => $application_id, 'count' => count($vars), 'variables' => $vars];
    }

    /**
     * Create or update (upsert) one environment variable on an app. The value is
     * stored as-is and exposed to controller/function code as KYTE_APP_ENV['<key>'].
     *
     * @param int    $application_id Application id (from list_applications).
     * @param string $key            Variable name (e.g. "STRIPE_KEY"); 1-255 chars.
     * @param string $value          Variable value (secret); up to 512 chars.
     * @return array{updated: bool, application_id?: int, key?: string, action?: string, env_var_id?: int, note?: string, error?: string}
     */
    #[McpTool(name: 'set_app_env_var', description: 'Create or update one environment variable on an app (upsert by key). Controller/function PHP reads it as KYTE_APP_ENV[\'KEY\'] — use this for config + secrets (API keys, endpoints) instead of hard-coding them in controller code. The value is write-only: it is stored but never echoed back by this or any read tool. Gated by provision scope.')]
    #[RequiresScope('provision')]
    public function setAppEnvVar(int $application_id, string $key, string $value): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->appBelongsToAccount($application_id, $accountId)) {
            return ['updated' => false, 'error' => 'Application not found in this account.'];
        }

        $key = trim($key);
        if ($key === '' || strlen($key) > 255) {
            return ['updated' => false, 'error' => 'key must be a non-empty string of at most 255 characters.'];
        }
        if (strlen($value) > 512) {
            return ['updated' => false, 'error' => 'value must be at most 512 characters.'];
        }

        try {
            $createdBy = isset($this->api->user->id) ? (int)$this->api->user->id : 0;
            $existing  = new \Kyte\Core\ModelObject(\KyteEnvironmentVariable);
            $found     = $existing->retrieve('key', $key, [
                ['field' => 'application',  'value' => $application_id],
                ['field' => 'kyte_account', 'value' => $accountId],
            ]);

            if ($found) {
                $existing->save(['value' => $value, 'modified_by' => $createdBy, 'date_modified' => time()]);
                $action = 'updated';
                $id     = (int)$existing->id;
            } else {
                $obj = new \Kyte\Core\ModelObject(\KyteEnvironmentVariable);
                $obj->create([
                    'key'          => $key,
                    'value'        => $value,
                    'application'  => $application_id,
                    'kyte_account' => $accountId,
                    'created_by'   => $createdBy,
                    'date_created' => time(),
                ]);
                $action = 'created';
                $id     = (int)$obj->id;
            }
        } catch (\Throwable $e) {
            return ['updated' => false, 'error' => $e->getMessage()];
        }

        return [
            'updated'        => true,
            'application_id' => $application_id,
            'key'            => $key,
            'action'         => $action,
            'env_var_id'     => $id,
            'note'           => "Available to controllers/functions as KYTE_APP_ENV['{$key}']. Value stored but not echoed back.",
        ];
    }

    /**
     * Delete one environment variable from an app (soft-delete).
     *
     * @param int    $application_id Application id (from list_applications).
     * @param string $key            Variable name to remove.
     * @return array{deleted: bool, application_id?: int, key?: string, error?: string}
     */
    #[McpTool(name: 'delete_app_env_var', description: 'Delete one environment variable from an app by key. Controllers referencing KYTE_APP_ENV[\'KEY\'] will then read an undefined key — guard with isset(). Gated by provision scope.')]
    #[RequiresScope('provision')]
    public function deleteAppEnvVar(int $application_id, string $key): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->appBelongsToAccount($application_id, $accountId)) {
            return ['deleted' => false, 'error' => 'Application not found in this account.'];
        }

        $key = trim($key);
        try {
            $obj   = new \Kyte\Core\ModelObject(\KyteEnvironmentVariable);
            $found = $obj->retrieve('key', $key, [
                ['field' => 'application',  'value' => $application_id],
                ['field' => 'kyte_account', 'value' => $accountId],
            ]);
            if (!$found) {
                return ['deleted' => false, 'error' => 'No such environment variable for this application.'];
            }
            $obj->delete(null, null, isset($this->api->user->id) ? (int)$this->api->user->id : 0);
        } catch (\Throwable $e) {
            return ['deleted' => false, 'error' => $e->getMessage()];
        }

        return ['deleted' => true, 'application_id' => $application_id, 'key' => $key];
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
