<?php
namespace Kyte\Mcp\Tools;

use Kyte\Core\Api;
use Kyte\Mcp\Attribute\RequiresScope;
use Kyte\Mcp\Util\Bz2Codec;
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
                // Surface the sensitive flag so callers (including AI clients)
                // know up front which controllers will have source withheld
                // by read_controller. Names and metadata are not themselves
                // sensitive — only code is gated.
                'sensitive'   => (int)($controller->sensitive ?? 0) === 1,
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
    #[McpTool(name: 'read_controller', description: 'Read a controller including its PHP source code. Code is withheld when the controller is flagged sensitive — the metadata still returns and the `sensitive` field is true.')]
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

        $isSensitive = (int)($controller->sensitive ?? 0) === 1;

        return [
            'id'          => (int)$controller->id,
            'name'        => (string)($controller->name ?? ''),
            'description' => $controller->description !== null ? (string)$controller->description : null,
            'dataModel'   => $controller->dataModel !== null ? (int)$controller->dataModel : null,
            'application' => $controller->application !== null ? (int)$controller->application : null,
            // Source withheld when sensitive — same logic that drops body
            // from activity/error logs. AI clients should treat a null code
            // with sensitive:true as "exists, source intentionally gated."
            'code'        => $isSensitive ? null : Bz2Codec::decompressIfBz2($controller->code),
            'kyte_locked' => (int)$controller->kyte_locked === 1,
            'sensitive'   => $isSensitive,
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
    #[McpTool(name: 'read_function', description: 'Read a function source. Pass version_number to retrieve a specific historical snapshot, or omit for the live source. Code is withheld when the parent controller is flagged sensitive.')]
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

        // If the parent controller is sensitive the function source is
        // gated regardless of which version is requested. Historical
        // snapshots that pre-date the flag would still be off-limits;
        // the flag applies to current policy, not the row state at
        // snapshot time.
        $parentSensitive = false;
        if ($fn->controller !== null) {
            $parent = new \Kyte\Core\ModelObject(\Controller);
            if ($parent->retrieve('id', (int)$fn->controller) && (int)$parent->kyte_account === $accountId) {
                $parentSensitive = (int)($parent->sensitive ?? 0) === 1;
            }
        }

        $base = [
            'id'           => (int)$fn->id,
            'name'         => (string)($fn->name ?? ''),
            'type'         => (string)($fn->type ?? ''),
            'description'  => $fn->description !== null ? (string)$fn->description : null,
            'version'      => null,
            'version_type' => null,
            'sensitive'    => $parentSensitive,
        ];

        if ($version_number === null) {
            return array_merge($base, [
                'code' => $parentSensitive ? null : Bz2Codec::decompressIfBz2($fn->code),
            ]);
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
            'code'         => $parentSensitive ? null : Bz2Codec::decompressIfBz2($content->code),
            'version'      => (int)$version->version_number,
            'version_type' => (string)($version->version_type ?? ''),
        ]);
    }

    /**
     * Create a new custom API controller in an application. It is created with
     * its generated base code; attach behaviour afterward with
     * write_function_code (hooks / method overrides / custom helpers). Optionally
     * bind a data model so the generated controller wires up shipyard_init.
     *
     * @param int         $application_id Application id (from list_applications).
     * @param string      $name           Controller name — unique within the app; must not collide with a built-in controller class.
     * @param int|null    $data_model_id  Optional DataModel id to bind (from list_models).
     * @param string|null $description    Optional description.
     * @return array{created: bool, controller?: array<string,mixed>|null, error?: string}
     */
    #[McpTool(name: 'create_controller', description: 'Create a new custom API controller in a Kyte application (with generated base code). Optionally bind a data model. Add behaviour afterward with write_function_code.')]
    #[RequiresScope('schema')]
    public function createController(int $application_id, string $name, ?int $data_model_id = null, ?string $description = null): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->applicationBelongsToAccount($application_id, $accountId)) {
            return ['created' => false, 'error' => 'Application not found in this account.'];
        }
        if ($data_model_id !== null && !$this->dataModelBelongsToApp($data_model_id, $application_id, $accountId)) {
            return ['created' => false, 'error' => 'Data model not found in this application.'];
        }

        $api  = $this->api;
        $resp = [];
        try {
            $controller = new \Kyte\Mvc\Controller\ControllerController(\Controller, $api, 'm/d/Y H:i:s', $resp, true);
            $data = ['name' => $name, 'application' => $application_id];
            if ($data_model_id !== null) { $data['dataModel']  = $data_model_id; }
            if ($description !== null)   { $data['description'] = $description; }
            $controller->new($data);
        } catch (\Throwable $e) {
            return ['created' => false, 'error' => $e->getMessage()];
        }

        $newId = isset($resp['data'][0]['id']) ? (int)$resp['data'][0]['id'] : 0;
        if ($newId === 0) {
            return ['created' => false, 'error' => 'Controller was not created.'];
        }
        return ['created' => true, 'controller' => $this->readController($newId)];
    }

    /**
     * Update a controller's name, description, or bound data model. Changing the
     * name or bound model regenerates the controller's base code. Controller
     * behaviour (functions) is edited with write_function_code, not here.
     *
     * @param int         $controller_id Controller id.
     * @param string|null $name          New name (unique within the app).
     * @param string|null $description   New description.
     * @param int|null    $data_model_id New DataModel id to bind.
     * @return array{updated: bool, controller?: array<string,mixed>|null, error?: string}
     */
    #[McpTool(name: 'update_controller', description: 'Update a controller\'s name, description, or bound data model. Edit controller behaviour (functions) with write_function_code.')]
    #[RequiresScope('schema')]
    public function updateController(int $controller_id, ?string $name = null, ?string $description = null, ?int $data_model_id = null): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->controllerBelongsToAccount($controller_id, $accountId)) {
            return ['updated' => false, 'error' => 'Controller not found in this account.'];
        }

        $ctrl = new \Kyte\Core\ModelObject(\Controller);
        $ctrl->retrieve('id', $controller_id);
        $appId = (int)$ctrl->application;

        $data = [];
        // A name change is validated against the app scope by
        // ControllerController::validateControllerUpdate, which reads
        // application off the payload — so carry it whenever name is set.
        if ($name !== null)        { $data['name'] = $name; $data['application'] = $appId; }
        if ($description !== null) { $data['description'] = $description; }
        if ($data_model_id !== null) {
            if (!$this->dataModelBelongsToApp($data_model_id, $appId, $accountId)) {
                return ['updated' => false, 'error' => 'Data model not found in this controller\'s application.'];
            }
            $data['dataModel'] = $data_model_id;
        }
        if (empty($data)) {
            return ['updated' => false, 'error' => 'No updatable fields provided (name, description, data_model_id).'];
        }

        $api  = $this->api;
        $resp = [];
        try {
            $controller = new \Kyte\Mvc\Controller\ControllerController(\Controller, $api, 'm/d/Y H:i:s', $resp, true);
            $controller->update('id', $controller_id, $data);
        } catch (\Throwable $e) {
            return ['updated' => false, 'error' => $e->getMessage()];
        }
        return ['updated' => true, 'controller' => $this->readController($controller_id)];
    }

    /**
     * Delete a controller and all of its functions.
     *
     * @param int $controller_id Controller id.
     * @return array{deleted: bool, controller_id?: int, error?: string}
     */
    #[McpTool(name: 'delete_controller', description: 'Delete a controller and all its functions.')]
    #[RequiresScope('schema')]
    public function deleteController(int $controller_id): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->controllerBelongsToAccount($controller_id, $accountId)) {
            return ['deleted' => false, 'error' => 'Controller not found in this account.'];
        }
        $api  = $this->api;
        $resp = [];
        try {
            $controller = new \Kyte\Mvc\Controller\ControllerController(\Controller, $api, 'm/d/Y H:i:s', $resp, true);
            $controller->delete('id', $controller_id);
        } catch (\Throwable $e) {
            return ['deleted' => false, 'error' => $e->getMessage()];
        }
        return ['deleted' => true, 'controller_id' => $controller_id];
    }

    /**
     * Add a function to a controller: a hook, a CRUD method override, or a
     * custom helper. Created with the generated stub for its type; add real
     * behaviour afterward with write_function_code, then publish with
     * commit_draft (which regenerates the controller). Mirrors how Shipyard's
     * function editor creates functions.
     *
     * Types: hooks — hook_init, hook_auth, hook_prequery, hook_preprocess,
     * hook_response_data, hook_process_get_response; method overrides — new,
     * update, get, delete; and custom (arbitrary helper). Hooks and overrides
     * are unique per controller (one each); custom allows many.
     *
     * @param int         $controller_id Controller id (from list_controllers).
     * @param string      $type          Function type (see list above).
     * @param string      $name          Function name — the PHP method name for a
     *                                    custom function; a label for hooks/overrides
     *                                    (the type is the slot).
     * @param string|null $description   Optional description.
     * @return array{created: bool, function?: array<string,mixed>|null, error?: string}
     */
    #[McpTool(name: 'create_function', description: 'Add a function to a controller: a hook (hook_preprocess / hook_response_data / hook_init / hook_auth / hook_prequery / hook_process_get_response), a CRUD method override (new / update / get / delete), or a custom helper. Created as a stub — add behaviour with write_function_code then publish with commit_draft. Hooks and overrides are unique per controller; custom allows multiple.')]
    #[RequiresScope('schema')]
    public function createFunction(int $controller_id, string $type, string $name, ?string $description = null): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->controllerBelongsToAccount($controller_id, $accountId)) {
            return ['created' => false, 'error' => 'Controller not found in this account.'];
        }

        $validTypes = [
            'hook_init', 'hook_auth', 'hook_prequery', 'hook_preprocess',
            'hook_response_data', 'hook_process_get_response',
            'new', 'update', 'get', 'delete', 'custom',
        ];
        if (!in_array($type, $validTypes, true)) {
            return ['created' => false, 'error' => "Invalid function type '{$type}'. Valid types: " . implode(', ', $validTypes) . '.'];
        }
        if (trim($name) === '') {
            return ['created' => false, 'error' => 'Function name is required.'];
        }

        // FunctionController's initial-version write attributes created_by to
        // $api->user (and kyte_account to $api->account). MCP tokens populate
        // account but NOT user, so bind a representative account user for the
        // internal call and restore it after — without it the version write
        // dereferences null. (create_controller doesn't need this; its
        // controller never versions on create.)
        $api = $this->api;
        $priorUser = isset($api->user) ? $api->user : null;
        $acctUser = new \Kyte\Core\ModelObject(\KyteUser);
        if (!$acctUser->retrieve('kyte_account', $accountId)) {
            return ['created' => false, 'error' => 'No user is available for this account to attribute the change to.'];
        }
        $api->user = $acctUser;

        $resp = [];
        try {
            $fnCtrl = new \Kyte\Mvc\Controller\FunctionController(constant('Function'), $api, 'm/d/Y H:i:s', $resp, true);
            $data = ['name' => $name, 'controller' => $controller_id, 'type' => $type];
            if ($description !== null) {
                $data['description'] = $description;
            }
            $fnCtrl->new($data);
        } catch (\Throwable $e) {
            return ['created' => false, 'error' => $e->getMessage()];
        } finally {
            $api->user = $priorUser;
        }

        $newId = isset($resp['data'][0]['id']) ? (int)$resp['data'][0]['id'] : 0;
        if ($newId === 0) {
            return ['created' => false, 'error' => 'Function was not created.'];
        }
        return [
            'created'  => true,
            'function' => $this->readFunction($newId),
            'note'     => 'Stub created. Add behaviour with write_function_code, then publish with commit_draft.',
        ];
    }

    /**
     * The authoring reference for controller/function PHP. The signatures,
     * by-reference params, available $this context, and query API of a Kyte
     * controller are template-specific — an AI writing function code needs them
     * to produce code that runs. Call before write_function_code.
     *
     * @return array<string,mixed>
     */
    #[McpTool(name: 'get_controller_guide', description: 'How to write PHP for Kyte controllers/functions: the exact hook + method-override signatures (which params are by-reference), the $this context ($this->user / $this->account / $this->response / $this->model), the Model/ModelObject query API, error handling, and worked examples. Call this BEFORE writing controller function code with write_function_code.')]
    #[RequiresScope('read')]
    public function getControllerGuide(): array
    {
        return [
            'overview' =>
                'A Kyte controller extends the base ModelController and is bound to a data model. '
                . 'The base already implements default CRUD (new/update/get/delete) over the bound '
                . 'model — you only add what you need: HOOKS (fire around the default flow) or '
                . 'METHOD OVERRIDES (replace a default operation). Author each as a function '
                . '(create_function to add the slot, write_function_code to fill it, commit_draft '
                . 'to publish). Write ONLY the function body/signature shown — it is spliced into '
                . 'the generated controller class.',
            'context' => [
                '$this->user'     => 'The authenticated user object, or null if unauthenticated. ALWAYS guard: if (!$this->user || !isset($this->user->id)) { throw new \\Exception("auth required"); }. For app endpoints this is the app user_model row.',
                '$this->account'  => 'The Kyte account (->id, ->number). Scope cross-model queries by it where relevant.',
                '$this->response' => "The response envelope. For get/custom endpoints, set your payload with \$this->response['data'] = [...]; (an array/object). Default CRUD fills this for you.",
                '$this->model'    => 'The bound model definition constant. The base CRUD operates on it.',
                '$this->api'      => 'The Api instance (advanced use).',
            ],
            'hooks' => [
                'hook_init()' => 'Runs when the controller initialises. No params.',
                'hook_auth()' => 'Custom authentication gate. No params.',
                'hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order)' =>
                    'Fires BEFORE the query. $field/$value/$conditions/$all/$order are BY-REFERENCE — '
                    . 'mutate them to scope/filter. Classic use: force a row to the current user — '
                    . "\$field='id'; \$value=\$this->user->id;. \$method is 'new'|'update'|'get'|'delete'.",
                'hook_preprocess($method, &$r, &$o = null)' =>
                    'Fires BEFORE a create/update write. $r is the incoming data (BY-REFERENCE — '
                    . 'validate/transform/inject fields). $o is the existing row on update/delete. '
                    . 'throw \\Exception to abort the write.',
                'hook_response_data($method, $o, &$r = null, &$d = null)' =>
                    'Fires AFTER the operation. $o is the affected row; $r is the response row '
                    . '(BY-REFERENCE — augment/redact it); $d is the original request data.',
                'hook_process_get_response(&$r)' =>
                    'Shape the assembled GET response ($r is BY-REFERENCE).',
            ],
            'method_overrides' => [
                'new($data)'                 => 'Replace create. $data = the posted object. Set $this->response[\'data\'] with the result.',
                'update($field, $value, $data)' => 'Replace update of the row(s) where $field=$value with $data.',
                'get($field, $value)'        => "Replace read. Filter by \$field=\$value (or both null for all). Return via \$this->response['data'] = [...].",
                'delete($field, $value)'     => 'Replace delete of the row(s) where $field=$value.',
                'custom'                     => 'A custom function is any additional method — a custom endpoint / helper. Its name is the method name.',
            ],
            'query_api' => [
                'multi'  => "\$m = new \\Kyte\\Core\\Model(ModelName); \$m->retrieve('field', \$value, \$isLike=false, \$conditions=[], \$all=false, \$order=[]); then \$m->objects (array) and \$m->count().",
                'single' => "\$o = new \\Kyte\\Core\\ModelObject(ModelName); \$o->retrieve('id', \$id); \$o->create([...]); \$o->save([...]); \$o->delete();",
                'conditions' => "\$conditions is an array of ['field'=>..., 'value'=>...] AND-clauses. ModelName is the model's bare CONSTANT (e.g. Task), not a string.",
            ],
            'errors' =>
                'Throw \\Exception with a user-facing message to fail a request — it is delivered to '
                . "the frontend's k.* error callback. Do not echo or return; use exceptions + "
                . '$this->response.',
            'example_get_override' => implode("\n", [
                "public function get(\$field, \$value) {",
                "    if (!\$this->user || !isset(\$this->user->id)) { throw new \\Exception('auth required'); }",
                "    if (\$field !== 'subdomain') { throw new \\Exception('invalid field'); }",
                "    \$sub = strtolower(trim(\$value));",
                "    \$sites = new \\Kyte\\Core\\Model(Site);",
                "    \$sites->retrieve('subdomain', \$sub, false);",
                "    \$this->response['data'] = ['subdomain' => \$sub, 'available' => (\$sites->count() === 0)];",
                "}",
            ]),
            'example_hook_prequery' => implode("\n", [
                "public function hook_prequery(\$method, &\$field, &\$value, &\$conditions, &\$all, &\$order) {",
                "    switch (\$method) {",
                "        case 'update':",
                "        case 'get':",
                "            \$field = 'id';               // scope every read/update to the caller",
                "            \$value = (int)\$this->user->id;",
                "            break;",
                "    }",
                "}",
            ]),
        ];
    }

    private function dataModelBelongsToApp(int $modelId, int $applicationId, int $accountId): bool
    {
        $m = new \Kyte\Core\ModelObject(\DataModel);
        return $m->retrieve('id', $modelId)
            && (int)$m->application === $applicationId
            && (int)$m->kyte_account === $accountId;
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
