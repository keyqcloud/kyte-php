<?php
namespace Kyte\Mcp\Tools;

use Kyte\Core\Api;
use Kyte\Mcp\Attribute\RequiresScope;
use Mcp\Capability\Attribute\McpTool;

/**
 * Controller- and function-scoped read tools.
 *
 * Tools dispatch under whichever Kyte account the bearer token resolved
 * to. Every tool independently re-asserts that the requested entity
 * belongs to $api->account — never trust the caller's id alone. Without
 * that re-check, a token holder could enumerate any account's
 * controllers / functions by trying integer ids in sequence.
 *
 * Code-bearing fields (Controller::code, Function::code,
 * KyteFunctionVersionContent::code) are intentionally returned in full.
 * The whole point of MCP read access is letting Claude reason over the
 * source — withholding code here would defeat the design. Token scope
 * gates this; Shipyard issues 'read' tokens deliberately.
 */
final class ControllerTools
{
    public function __construct(private readonly Api $api)
    {
    }

    /**
     * List controllers attached to a Kyte application.
     *
     * Returns metadata only (no code) — keep tools/list responses small;
     * the caller fetches code via read_controller when it picks one to
     * inspect. Virtual controllers (no dataModel) appear too.
     *
     * @param int $application_id Application id from list_applications.
     * @return array{controllers: array<int, array{id:int, name:string, description:?string, dataModel:?int, kyte_locked:bool}>}
     */
    #[McpTool(name: 'list_controllers', description: 'List controllers in a Kyte application. Returns metadata only — call read_controller for code.')]
    #[RequiresScope('read')]
    public function listControllers(int $application_id): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->applicationBelongsToAccount($application_id, $accountId)) {
            return ['controllers' => []];
        }

        $model = new \Kyte\Core\Model(\Controller);
        $model->retrieve('application', $application_id, false, [
            ['field' => 'kyte_account', 'value' => $accountId],
        ]);

        $out = [];
        foreach ($model->objects as $controller) {
            $out[] = [
                'id'          => (int)$controller->id,
                'name'        => (string)($controller->name ?? ''),
                'description' => $controller->description !== null ? (string)$controller->description : null,
                'dataModel'   => $controller->dataModel !== null ? (int)$controller->dataModel : null,
                'kyte_locked' => (int)$controller->kyte_locked === 1,
            ];
        }
        return ['controllers' => $out];
    }

    /**
     * Read a controller's full record, including its PHP source code.
     *
     * Account scoping is re-verified — supplying a controller_id from
     * another account returns null rather than the foreign record.
     *
     * @param int $controller_id Controller id from list_controllers.
     * @return array{id:int, name:string, description:?string, dataModel:?int, application:?int, code:string, kyte_locked:bool}|null
     */
    #[McpTool(name: 'read_controller', description: 'Read a controller including its PHP source code.')]
    #[RequiresScope('read')]
    public function readController(int $controller_id): ?array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0) {
            return null;
        }

        $controller = new \Kyte\Core\ModelObject(\Controller);
        if (!$controller->retrieve('id', $controller_id) || (int)$controller->kyte_account !== $accountId) {
            return null;
        }

        return [
            'id'          => (int)$controller->id,
            'name'        => (string)($controller->name ?? ''),
            'description' => $controller->description !== null ? (string)$controller->description : null,
            'dataModel'   => $controller->dataModel !== null ? (int)$controller->dataModel : null,
            'application' => $controller->application !== null ? (int)$controller->application : null,
            'code'        => (string)($controller->code ?? ''),
            'kyte_locked' => (int)$controller->kyte_locked === 1,
        ];
    }

    /**
     * List functions (hooks + custom) attached to a controller.
     *
     * Function `type` distinguishes hooks ('hook_init', 'hook_preprocess'
     * etc.), method overrides ('new', 'update', 'get', 'delete'), and
     * 'custom' helpers. The skill bundle docs lay out which type slots
     * exist and what they do; this tool just surfaces what's there.
     *
     * @param int $controller_id Controller id from list_controllers.
     * @return array{functions: array<int, array{id:int, name:string, type:string, description:?string, kyte_locked:bool}>}
     */
    #[McpTool(name: 'list_functions', description: 'List functions (hooks + custom) attached to a Kyte controller.')]
    #[RequiresScope('read')]
    public function listFunctions(int $controller_id): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->controllerBelongsToAccount($controller_id, $accountId)) {
            return ['functions' => []];
        }

        $model = new \Kyte\Core\Model(constant('Function'));
        $model->retrieve('controller', $controller_id, false, [
            ['field' => 'kyte_account', 'value' => $accountId],
        ]);

        $out = [];
        foreach ($model->objects as $fn) {
            $out[] = [
                'id'          => (int)$fn->id,
                'name'        => (string)($fn->name ?? ''),
                'type'        => (string)($fn->type ?? ''),
                'description' => $fn->description !== null ? (string)$fn->description : null,
                'kyte_locked' => (int)$fn->kyte_locked === 1,
            ];
        }
        return ['functions' => $out];
    }

    /**
     * Read a function's source code, optionally at a specific historical version.
     *
     * Without `version_number`, returns the live Function row's `code`.
     * With `version_number`, looks up the matching KyteFunctionVersion
     * snapshot and joins to KyteFunctionVersionContent for the source as
     * it was at that version. Returns null if either the function or the
     * requested version doesn't exist (or belongs to another account).
     *
     * Versioning was added per the design doc 3.3 draft model — this
     * tool gives Claude a way to inspect prior states when reasoning
     * about a change, without requiring Shipyard.
     *
     * @param int      $function_id   Function id from list_functions.
     * @param int|null $version_number Optional KyteFunctionVersion.version_number.
     * @return array{id:int, name:string, type:string, description:?string, code:string, version:?int, version_type:?string}|null
     */
    #[McpTool(name: 'read_function', description: 'Read a function source. Pass version_number to retrieve a specific historical snapshot, or omit for the live source.')]
    #[RequiresScope('read')]
    public function readFunction(int $function_id, ?int $version_number = null): ?array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0) {
            return null;
        }

        $fn = new \Kyte\Core\ModelObject(constant('Function'));
        if (!$fn->retrieve('id', $function_id) || (int)$fn->kyte_account !== $accountId) {
            return null;
        }

        $base = [
            'id'           => (int)$fn->id,
            'name'         => (string)($fn->name ?? ''),
            'type'         => (string)($fn->type ?? ''),
            'description'  => $fn->description !== null ? (string)$fn->description : null,
            'version'      => null,
            'version_type' => null,
        ];

        if ($version_number === null) {
            return array_merge($base, ['code' => (string)($fn->code ?? '')]);
        }

        $version = new \Kyte\Core\ModelObject(\KyteFunctionVersion);
        $found = $version->retrieve('function', $function_id, [
            ['field' => 'version_number', 'value' => $version_number],
            ['field' => 'kyte_account',   'value' => $accountId],
        ]);
        if (!$found) {
            return null;
        }

        $content = new \Kyte\Core\ModelObject(\KyteFunctionVersionContent);
        if (!$content->retrieve('content_hash', (string)$version->content_hash)) {
            return null;
        }

        // array_merge — not the `+` union operator — so that the version
        // overrides below replace the nulls in $base. PHP's `+` keeps the
        // left-hand value on key collision, which would silently drop the
        // version metadata.
        return array_merge($base, [
            'code'         => (string)($content->code ?? ''),
            'version'      => (int)$version->version_number,
            'version_type' => (string)($version->version_type ?? ''),
        ]);
    }

    private function accountIdOrZero(): int
    {
        return isset($this->api->account->id) ? (int)$this->api->account->id : 0;
    }

    /**
     * Defensive precondition for application-scoped lookups. Cheap (one
     * indexed read by id) and prevents enumeration of foreign apps.
     */
    private function applicationBelongsToAccount(int $applicationId, int $accountId): bool
    {
        $app = new \Kyte\Core\ModelObject(\Application);
        return $app->retrieve('id', $applicationId) && (int)$app->kyte_account === $accountId;
    }

    private function controllerBelongsToAccount(int $controllerId, int $accountId): bool
    {
        $controller = new \Kyte\Core\ModelObject(\Controller);
        return $controller->retrieve('id', $controllerId) && (int)$controller->kyte_account === $accountId;
    }
}
