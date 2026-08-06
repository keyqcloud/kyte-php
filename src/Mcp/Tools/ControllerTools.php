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
     * Delete a single function from a controller. Removes the function + its
     * versions and regenerates the parent controller's code without it. To
     * remove a whole controller (and all its functions) use delete_controller.
     *
     * @param int $function_id Function id (from list_functions).
     * @return array{deleted: bool, function_id?: int, error?: string}
     */
    #[McpTool(name: 'delete_function', description: 'Delete a single function from a controller (removes its versions and regenerates the controller code). Use delete_controller to remove a whole controller.')]
    #[RequiresScope('schema')]
    public function deleteFunction(int $function_id): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0) {
            return ['deleted' => false, 'error' => 'No account context.'];
        }
        $fn = new \Kyte\Core\ModelObject(constant('Function'));
        if (!$fn->retrieve('id', $function_id) || (int)$fn->kyte_account !== $accountId) {
            return ['deleted' => false, 'error' => 'Function not found in this account.'];
        }

        // FunctionController's delete cleans up versions (content refcounting uses
        // $api->user) — bind a representative account user, restored after.
        $api = $this->api;
        $priorUser = isset($api->user) ? $api->user : null;
        $acctUser = new \Kyte\Core\ModelObject(\KyteUser);
        if ($acctUser->retrieve('kyte_account', $accountId)) {
            $api->user = $acctUser;
        }

        $resp = [];
        try {
            $ctrl = new \Kyte\Mvc\Controller\FunctionController(constant('Function'), $api, 'm/d/Y H:i:s', $resp, true);
            $ctrl->delete('id', $function_id);
        } catch (\Throwable $e) {
            return ['deleted' => false, 'error' => $e->getMessage()];
        } finally {
            $api->user = $priorUser;
        }
        return ['deleted' => true, 'function_id' => $function_id];
    }

    /**
     * The authoring reference for controller/function PHP. The signatures,
     * by-reference params, available $this context, and query API of a Kyte
     * controller are template-specific — an AI writing function code needs them
     * to produce code that runs. Call before write_function_code.
     *
     * ⚠️ MCP KNOWLEDGE BASE — KEEP IN SYNC. This guide is verified against
     * FunctionController::FUNCTION_TYPES (the hook/override templates) and
     * ModelController (hook firing order, $this context, account scoping) plus
     * Core/Model.php + Core/ModelObject.php (query API). Any MATERIAL change to a
     * hook/override signature, the hook dispatch order, the $this context, the
     * Model/ModelObject query API, or the response/error contract MUST update this
     * guide in the same change, or AI-generated controller code will drift.
     *
     * @return array<string,mixed>
     */
    #[McpTool(name: 'get_controller_guide', description: 'How to write PHP for Kyte controllers/functions: the exact hook + method-override signatures (which params are by-reference), the $this context ($this->user / $this->account / $this->response / $this->model), the Model/ModelObject query API, error handling, and worked examples. Call this BEFORE writing controller function code with write_function_code.')]
    #[RequiresScope('read')]
    public function getControllerGuide(): array
    {
        return [
            'overview' =>
                'A Kyte controller extends \\Kyte\\Mvc\\Controller\\ModelController and is bound to a '
                . 'data model. The base implements default CRUD (new/update/get/delete) INCLUDING '
                . 'automatic per-account (kyte_account) tenant scoping, auth, FK handling, and '
                . 'populating $this->response. You customise via HOOKS (fire around the default flow) '
                . 'or METHOD OVERRIDES (replace a default operation). Author each with create_function '
                . '+ write_function_code + commit_draft. Write the COMPLETE method — the full '
                . '`public function ...(...) { ... }` matching the template signature (for a custom '
                . 'function you write the whole method). The framework wraps your functions in the '
                . 'controller class, so do NOT add a class wrapper. '
                . '(Building a signup/login controller for a membership app? Call get_auth_guide for the '
                . 'end-to-end recipe — it needs app settings auth_mode=jwt + allow_public=2 alongside this controller.)',
            'context' => [
                '$this->user'     => 'ALWAYS a ModelObject — when there is no session it is an EMPTY '
                    . 'object with no id (it is NEVER literally null). Guard with isset($this->user->id) '
                    . '(NOT !$this->user, which is always false). For default CRUD, auth is already '
                    . 'enforced (requireAuth defaults true) so $this->user->id is set there.',
                '$this->account'  => 'The Kyte account ModelObject ($this->account->id, ->number). Use ->id to scope your queries.',
                '$this->response' => "The response envelope (array). Default CRUD sets \$this->response['data'] "
                    . "to a LIST of row arrays (one element even for a single create/update). In a custom "
                    . "get/override you may set it to whatever shape your page JS reads (a list, a plain "
                    . "object, or a scalar) — just keep the k.get response.data handling in sync.",
                '$this->model'    => "The resolved model-definition ARRAY (\$this->model['name'], "
                    . "\$this->model['struct'][<col>]), set by shipyard_init() — it is the array value, not the constant name.",
                '$this->api'      => 'The Api instance.',
            ],
            'hooks' => [
                'hook_init()' => 'Runs during construction, BEFORE authentication — do NOT assume a '
                    . 'logged-in user here ($this->user->id may be unset). Use for controller config (flags, allowableActions).',
                'hook_auth()' => 'Runs AFTER the session is validated — post-auth checks.',
                'hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order)' =>
                    'Fires immediately before the DB query in GET and UPDATE ONLY ($method is "get" or '
                    . '"update"). It is NOT called for new (no query) or delete. Mutate the by-ref params '
                    . "to scope/filter — e.g. \$field='id'; \$value=\$this->user->id;. To constrain a "
                    . 'delete, override delete() or add conditions in hook_response_data("delete", ...).',
                'hook_preprocess($method, &$r, &$o = null)' =>
                    'Fires before the WRITE for new ($o is null) and update ($o = the existing row). NOT '
                    . 'called for get or delete. $r is the incoming data BY-REFERENCE (validate/transform/'
                    . 'inject). throw \\Exception to abort.',
                'hook_response_data($method, $o, &$r = null, &$d = null)' =>
                    'For new/update/get: fires AFTER the op — $o = affected row, &$r = the outgoing '
                    . 'response row (augment/redact), $d = original request data. FOR DELETE IT DIFFERS: '
                    . 'it fires BEFORE the delete and &$r is the $autodelete BOOLEAN (default true) — set '
                    . '$r=false to VETO the delete; there is no response row or $d for delete.',
                'hook_process_get_response(&$r)' =>
                    'Fires once at the end of get() with the assembled list ($r BY-REFERENCE) — final shaping of the GET response.',
            ],
            'method_overrides' => [
                '_warning' => 'An override COMPLETELY REPLACES the base method — the base is NOT called '
                    . 'for you, so you LOSE automatic kyte_account scoping, the auth gate, FK handling, '
                    . 'and the default $this->response population. Either call the parent (parent::new'
                    . '($data), parent::get($field,$value), ...) and adjust, OR re-implement it: on WRITES '
                    . 'set $data["kyte_account"] = $this->account->id; on READS add a kyte_account '
                    . 'condition; and set $this->response["data"]. FORGETTING ACCOUNT SCOPING LEAKS OR '
                    . 'WRITES CROSS-TENANT DATA.',
                'new($data)'                 => 'Replace create. $data = the posted object.',
                'update($field, $value, $data)' => 'Replace update of the row(s) where $field=$value with $data.',
                'get($field, $value)'        => "Replace read (filter by \$field=\$value, or both null for all). Set \$this->response['data'].",
                'delete($field, $value)'     => 'Replace delete of the row(s) where $field=$value.',
                'custom'                     => 'A custom function is a HELPER method on the controller — '
                    . 'it is NOT reachable from the API by name. Only POST->new, PUT->update, GET->get, '
                    . 'DELETE->delete are dispatched. Call a custom function yourself from a hook/override. '
                    . 'To expose new behavior to the client, override one of the four CRUD methods (or '
                    . 'branch on request state inside a hook).',
            ],
            'query_api' => [
                'model_multi'   => "new \\Kyte\\Core\\Model(ModelName) — MANY rows. "
                    . "->retrieve(\$field=null, \$value=null, \$isLike=false, \$conditions=null, \$all=false, "
                    . "\$order=null, \$limit=null); then ->objects (array of ModelObject) and ->count() "
                    . "(number RETRIEVED, not the DB total). \$all=true includes soft-deleted rows.",
                'object_single' => "new \\Kyte\\Core\\ModelObject(ModelName) — ONE row. "
                    . "->retrieve(\$field, \$value, \$conditions=null, \$id=null, \$all=false) — NOTE the 3rd "
                    . "arg is \$conditions, NOT \$isLike (this DIFFERS from Model::retrieve — do not copy "
                    . "its arg order); returns bool. ->create(\$params, \$user=null) (auto-stamps "
                    . "deleted=0/date_created/created_by). ->save(\$params, \$user=null) (retrieve first). "
                    . "->delete(null,null,\$userId) is a SOFT delete (sets deleted=1); ->purge() hard-deletes.",
                'conditions'    => "\$conditions = [['field'=>..., 'value'=>..., 'operator'=>'>=' (optional)]] "
                    . "AND-clauses; \$order = [['field'=>..., 'direction'=>'asc|desc']]. ModelName is the "
                    . "model's bare CONSTANT (e.g. Task), not a string.",
                'scoping'       => 'Your ad-hoc Model/ModelObject queries are NOT auto-scoped by account — '
                    . 'add the condition yourself: ->retrieve("f", $v, false, [["field"=>"kyte_account","value"=>$this->account->id]]).',
            ],
            'errors' =>
                'Throw \\Exception with a user-facing message to fail a request — the framework returns '
                . 'HTTP 400 with {error: message} (a SessionException gives 403), delivered to the '
                . "frontend's k.* error callback. Do not echo or return; use exceptions + \$this->response.",
            'example_get_override' => implode("\n", [
                "// A custom get override. Reachable from JS as k.get('SubdomainCheck', 'subdomain', value, [], ok, err).",
                "public function get(\$field, \$value) {",
                "    if (!isset(\$this->user->id)) { throw new \\Exception('auth required'); }  // NOT !\$this->user",
                "    if (\$field !== 'subdomain') { throw new \\Exception('invalid field'); }",
                "    \$sub = strtolower(trim(\$value));",
                "    \$sites = new \\Kyte\\Core\\Model(Site);",
                "    // scope your own queries by account",
                "    \$sites->retrieve('subdomain', \$sub, false, [['field' => 'kyte_account', 'value' => \$this->account->id]]);",
                "    // custom shape — the page reads response.data.available (this override returns an object, not a list)",
                "    \$this->response['data'] = ['subdomain' => \$sub, 'available' => (\$sites->count() === 0)];",
                "}",
            ]),
            'example_hook_prequery' => implode("\n", [
                "// hook_prequery fires for get + update ONLY (never new/delete)",
                "public function hook_prequery(\$method, &\$field, &\$value, &\$conditions, &\$all, &\$order) {",
                "    switch (\$method) {",
                "        case 'get':",
                "        case 'update':",
                "            \$field = 'id';                 // scope every read/update to the caller",
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
