<?php
namespace Kyte\Mcp\Tools;

use Kyte\Core\Api;
use Kyte\Core\SensitivityPolicy;
use Kyte\Mcp\Attribute\RequiresScope;
use Mcp\Capability\Attribute\McpTool;

/**
 * DataModel-scoped read tools.
 *
 * Mirrors ControllerTools' shape: list returns shallow metadata, read
 * returns the full record. Account scoping is re-asserted on every call
 * so a token holder can't enumerate or read foreign-account models by
 * trying ids in sequence.
 *
 * `read_model` decodes the stored `model_definition` JSON for Claude's
 * convenience — that JSON is the same denormalized cache Shipyard uses,
 * kept in sync by DataModelController + ModelAttributeController. The
 * decoded structure has shape `{name, struct: {field_name: {type,
 * size, ...}, ...}}`. We don't bother with a separate
 * `list_attributes`-style tool — the decoded definition is the same
 * information Claude needs and the attribute-row view adds nothing the
 * JSON doesn't already convey.
 */
final class ModelTools
{
    public function __construct(private readonly Api $api)
    {
    }

    /**
     * List data models in a Kyte application.
     *
     * Returns metadata only (no schema definition) — the caller fetches
     * full structure via read_model when it picks one to inspect.
     *
     * @param int $application_id Application id from list_applications.
     * @return array{models: array<int, array{id:int, name:string, kyte_locked:bool}>}
     */
    #[McpTool(name: 'list_models', description: 'List data models in a Kyte application. Returns metadata only — call read_model for the full schema.')]
    #[RequiresScope('read')]
    public function listModels(int $application_id): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->applicationBelongsToAccount($application_id, $accountId)) {
            return ['models' => []];
        }

        $model = new \Kyte\Core\Model(\DataModel);
        $model->retrieve('application', $application_id, false, [
            ['field' => 'kyte_account', 'value' => $accountId],
        ]);

        $out = [];
        foreach ($model->objects as $dm) {
            $out[] = [
                'id'          => (int)$dm->id,
                'name'        => (string)($dm->name ?? ''),
                'kyte_locked' => (int)$dm->kyte_locked === 1,
                // Surface the sensitive flag so callers know up front
                // which models will have definition gated by read_model.
                'sensitive'   => (int)($dm->sensitive ?? 0) === 1,
            ];
        }
        return ['models' => $out];
    }

    /**
     * Read a data model's full record, including its decoded schema.
     *
     * `model_definition` is stored as a JSON string but returned here
     * as a decoded array so the caller doesn't have to parse it again.
     * If the stored JSON is missing or invalid, `definition` comes back
     * as null with the raw value preserved in `raw_definition` for
     * debugging — better than throwing and breaking the dispatch.
     *
     * @param int $model_id DataModel id from list_models.
     * @return array{id:int, name:string, application:?int, kyte_locked:bool, definition:?array, raw_definition:?string}|null
     */
    #[McpTool(name: 'read_model', description: 'Read a data model including its schema definition. When the model is flagged sensitive the definition is withheld; when individual fields are flagged sensitive they are stripped from the returned struct.')]
    #[RequiresScope('read')]
    public function readModel(int $model_id): ?array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0) {
            return null;
        }

        $dm = new \Kyte\Core\ModelObject(\DataModel);
        if (!$dm->retrieve('id', $model_id) || (int)$dm->kyte_account !== $accountId) {
            return null;
        }

        $modelName = (string)($dm->name ?? '');
        $isSensitive = (int)($dm->sensitive ?? 0) === 1;

        // Model flagged sensitive → definition entirely withheld. Metadata
        // (id, name, application) still returns so the caller can discover
        // that the model exists; the definition itself is gated.
        if ($isSensitive) {
            return [
                'id'              => (int)$dm->id,
                'name'            => $modelName,
                'application'     => $dm->application !== null ? (int)$dm->application : null,
                'kyte_locked'     => (int)$dm->kyte_locked === 1,
                'definition'      => null,
                'raw_definition'  => null,
                'sensitive'       => true,
                'sensitive_fields' => [],
            ];
        }

        $raw = $dm->model_definition !== null ? (string)$dm->model_definition : null;
        $decoded = null;
        if ($raw !== null && $raw !== '') {
            $tryDecode = json_decode($raw, true);
            if (is_array($tryDecode)) {
                $decoded = $tryDecode;
            }
        }

        // Field-level: strip any field flagged sensitive from definition.struct.
        // The caller still sees that those fields exist via the returned
        // sensitive_fields list, but their type / size / FK metadata is
        // withheld so it can't be reasoned over.
        $sensitiveFields = SensitivityPolicy::getInstance()->getSensitiveFields($modelName, $accountId);
        if ($decoded !== null && !empty($sensitiveFields) && isset($decoded['struct']) && is_array($decoded['struct'])) {
            $sensitiveLower = array_map('strtolower', $sensitiveFields);
            foreach (array_keys($decoded['struct']) as $fieldName) {
                if (is_string($fieldName) && in_array(strtolower($fieldName), $sensitiveLower, true)) {
                    unset($decoded['struct'][$fieldName]);
                }
            }
        }

        return [
            'id'               => (int)$dm->id,
            'name'             => $modelName,
            'application'      => $dm->application !== null ? (int)$dm->application : null,
            'kyte_locked'      => (int)$dm->kyte_locked === 1,
            'definition'       => $decoded,
            'raw_definition'   => $decoded === null ? $raw : null,
            'sensitive'        => false,
            'sensitive_fields' => $sensitiveFields,
        ];
    }

    /**
     * Create a new data model (table) in a Kyte application.
     *
     * Applies a real CREATE TABLE migration against the application's database
     * (with the standard Kyte primary key + audit columns). Add fields with
     * add_attribute. The model name must be unique within the application and
     * must not collide with a framework model name.
     *
     * @param int    $application_id Application id (from list_applications).
     * @param string $name           Model name (also the table name).
     * @return array{created: bool, model?: array<string,mixed>, error?: string}
     */
    #[McpTool(name: 'create_model', description: 'Create a new data model in a Kyte application. Applies a real CREATE TABLE migration; add fields with add_attribute.')]
    #[RequiresScope('schema')]
    public function createModel(int $application_id, string $name): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->applicationBelongsToAccount($application_id, $accountId)) {
            return ['created' => false, 'error' => 'Application not found in this account.'];
        }
        if (trim($name) === '') {
            return ['created' => false, 'error' => 'Model name is required.'];
        }

        $resp = [];
        try {
            $controller = $this->dataModelController($resp);
            // kyte_account is auto-filled from the token's account by ModelController::new.
            $controller->new(['application' => $application_id, 'name' => $name]);
        } catch (\Throwable $e) {
            return ['created' => false, 'error' => $e->getMessage()];
        }

        $newId = isset($resp['data'][0]['id']) ? (int)$resp['data'][0]['id'] : 0;
        if ($newId === 0) {
            return ['created' => false, 'error' => 'Model was not created.'];
        }

        return ['created' => true, 'model' => $this->modelSummary($newId)];
    }

    /**
     * Add an attribute (column) to a data model.
     *
     * Applies a real ADD COLUMN migration. For the decimal type ("d") both
     * precision and scale are required. For a foreign-key column pass
     * foreign_key_model (the referenced model's id); the reference always
     * targets that model's primary key.
     *
     * @param int         $model_id          DataModel id (from list_models).
     * @param string      $name              Column name.
     * @param string      $type              Column type code: i (int), bi (bigint), s (varchar — size required), d (decimal — precision+scale required), t/tt/mt/lt (text variants), b/tb/mb/lb (blob variants), date.
     * @param int|null    $size              Size/length (required for s; optional for i/bi).
     * @param int|null    $precision         Total digits — required for type d.
     * @param int|null    $scale             Fractional digits — required for type d.
     * @param bool        $unsigned          Whether a numeric column is unsigned.
     * @param bool        $required          Whether the column is NOT NULL.
     * @param string|null $default           Default value.
     * @param int|null    $foreign_key_model Referenced model id for a foreign-key column.
     * @return array{added: bool, attribute?: array<string,mixed>, error?: string}
     */
    #[McpTool(name: 'add_attribute', description: 'Add an attribute (column) to a data model. Applies a real ADD COLUMN migration. Decimal (d) needs precision+scale; varchar (s) needs size.')]
    #[RequiresScope('schema')]
    public function addAttribute(int $model_id, string $name, string $type, ?int $size = null, ?int $precision = null, ?int $scale = null, bool $unsigned = false, bool $required = false, ?string $default = null, ?int $foreign_key_model = null): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->modelBelongsToAccount($model_id, $accountId)) {
            return ['added' => false, 'error' => 'Model not found in this account.'];
        }
        if (trim($name) === '') {
            return ['added' => false, 'error' => 'Attribute name is required.'];
        }
        if ($foreign_key_model !== null && !$this->modelBelongsToAccount($foreign_key_model, $accountId)) {
            return ['added' => false, 'error' => 'Foreign-key model not found in this account.'];
        }

        $data = $this->attributeData($model_id, $name, $type, $size, $precision, $scale, $unsigned, $required, $default, $foreign_key_model);

        $resp = [];
        try {
            $controller = $this->modelAttributeController($resp);
            $controller->new($data);
        } catch (\Throwable $e) {
            return ['added' => false, 'error' => $e->getMessage()];
        }

        $newId = isset($resp['data'][0]['id']) ? (int)$resp['data'][0]['id'] : 0;
        if ($newId === 0) {
            return ['added' => false, 'error' => 'Attribute was not added.'];
        }

        return ['added' => true, 'attribute' => $this->attributeSummary($newId)];
    }

    /**
     * Update an attribute's definition (applies a real CHANGE COLUMN migration).
     *
     * Only the provided fields change; `name` must be supplied because the
     * underlying ALTER ... CHANGE rewrites the full column definition. To
     * convert to/keep a decimal column, supply precision and scale.
     *
     * @param int         $attribute_id      ModelAttribute id.
     * @param string      $name              Column name (new or unchanged — required).
     * @param string      $type              Column type code (see add_attribute).
     * @param int|null    $size              Size/length.
     * @param int|null    $precision         Total digits — required for type d.
     * @param int|null    $scale             Fractional digits — required for type d.
     * @param bool        $unsigned          Whether a numeric column is unsigned.
     * @param bool        $required          Whether the column is NOT NULL.
     * @param string|null $default           Default value.
     * @param int|null    $foreign_key_model Referenced model id for a foreign-key column.
     * @return array{updated: bool, attribute?: array<string,mixed>, error?: string}
     */
    #[McpTool(name: 'update_attribute', description: 'Update an attribute definition (applies a real CHANGE COLUMN migration). name is required since the column definition is rewritten in full.')]
    #[RequiresScope('schema')]
    public function updateAttribute(int $attribute_id, string $name, string $type, ?int $size = null, ?int $precision = null, ?int $scale = null, bool $unsigned = false, bool $required = false, ?string $default = null, ?int $foreign_key_model = null): array
    {
        $accountId = $this->accountIdOrZero();
        $attr = $this->ownedAttribute($attribute_id, $accountId);
        if ($attr === null) {
            return ['updated' => false, 'error' => 'Attribute not found in this account.'];
        }
        if (trim($name) === '') {
            return ['updated' => false, 'error' => 'Attribute name is required.'];
        }
        if ($foreign_key_model !== null && !$this->modelBelongsToAccount($foreign_key_model, $accountId)) {
            return ['updated' => false, 'error' => 'Foreign-key model not found in this account.'];
        }

        $data = $this->attributeData((int)$attr->dataModel, $name, $type, $size, $precision, $scale, $unsigned, $required, $default, $foreign_key_model);
        unset($data['dataModel']); // not editable on update

        $resp = [];
        try {
            $controller = $this->modelAttributeController($resp);
            $controller->update('id', $attribute_id, $data);
        } catch (\Throwable $e) {
            return ['updated' => false, 'error' => $e->getMessage()];
        }

        return ['updated' => true, 'attribute' => $this->attributeSummary($attribute_id)];
    }

    /**
     * Rename a data model (applies a real RENAME TABLE migration).
     *
     * @param int    $model_id DataModel id.
     * @param string $new_name New model/table name (unique within the app).
     * @return array{renamed: bool, model?: array<string,mixed>, error?: string}
     */
    #[McpTool(name: 'rename_model', description: 'Rename a data model (applies a real RENAME TABLE migration).')]
    #[RequiresScope('schema')]
    public function renameModel(int $model_id, string $new_name): array
    {
        $accountId = $this->accountIdOrZero();
        $dm = $this->ownedModel($model_id, $accountId);
        if ($dm === null) {
            return ['renamed' => false, 'error' => 'Model not found in this account.'];
        }
        if (trim($new_name) === '') {
            return ['renamed' => false, 'error' => 'New model name is required.'];
        }

        // DataModelController::update guards on `defined($o->name)` — it requires
        // the model to be registered as a runtime constant. The HTTP pipeline
        // does this via Api::loadAppModels during routing, but the MCP path
        // bypasses routing, so register the app's models here first (mirrors
        // AppModelWrapperController). clearModelCache after a prior create keeps
        // this read fresh from the DB.
        $app = new \Kyte\Core\ModelObject(\Application);
        if ($app->retrieve('id', (int)$dm->application)) {
            \Kyte\Core\Api::loadAppModels($app);
        }

        $resp = [];
        try {
            $controller = $this->dataModelController($resp);
            $controller->update('id', $model_id, [
                'name'        => $new_name,
                'application' => (int)$dm->application,
            ]);
        } catch (\Throwable $e) {
            return ['renamed' => false, 'error' => $e->getMessage()];
        }

        return ['renamed' => true, 'model' => $this->modelSummary($model_id)];
    }

    /**
     * Remove an attribute (column) from a data model.
     *
     * DESTRUCTIVE — applies a real DROP COLUMN migration and the column's data
     * is lost. Requires confirm_destructive=true; refused while another
     * attribute references this column via a foreign key.
     *
     * @param int  $attribute_id       ModelAttribute id.
     * @param bool $confirm_destructive Must be true to proceed (drops the column and its data).
     * @return array{removed: bool, attribute_id?: int, error?: string}
     */
    #[McpTool(name: 'remove_attribute', description: 'Remove an attribute (column) from a data model. DESTRUCTIVE — applies a real DROP COLUMN migration; requires confirm_destructive=true.')]
    #[RequiresScope('schema')]
    public function removeAttribute(int $attribute_id, bool $confirm_destructive = false): array
    {
        if ($confirm_destructive !== true) {
            return ['removed' => false, 'error' => 'Refused: dropping a column is destructive and loses its data. Re-call with confirm_destructive=true to proceed.'];
        }

        $accountId = $this->accountIdOrZero();
        $attr = $this->ownedAttribute($attribute_id, $accountId);
        if ($attr === null) {
            return ['removed' => false, 'error' => 'Attribute not found in this account.'];
        }

        $resp = [];
        try {
            $controller = $this->modelAttributeController($resp);
            $controller->delete('id', $attribute_id);
        } catch (\Throwable $e) {
            return ['removed' => false, 'error' => $e->getMessage()];
        }

        // The controller fails closed but does not throw on a no-op delete;
        // confirm the row is gone before reporting success.
        $check = new \Kyte\Core\ModelObject(\ModelAttribute);
        if ($check->retrieve('id', $attribute_id) && (int)$check->kyte_account === $accountId) {
            return ['removed' => false, 'error' => 'Attribute was not removed.'];
        }

        return ['removed' => true, 'attribute_id' => $attribute_id];
    }

    /**
     * Delete a data model.
     *
     * DESTRUCTIVE — applies a real DROP TABLE migration and all rows are lost.
     * Requires confirm_destructive=true; refused while another model references
     * this one via a foreign key.
     *
     * @param int  $model_id           DataModel id.
     * @param bool $confirm_destructive Must be true to proceed (drops the table and its data).
     * @return array{deleted: bool, model_id?: int, error?: string}
     */
    #[McpTool(name: 'delete_model', description: 'Delete a data model. DESTRUCTIVE — applies a real DROP TABLE migration; requires confirm_destructive=true.')]
    #[RequiresScope('schema')]
    public function deleteModel(int $model_id, bool $confirm_destructive = false): array
    {
        if ($confirm_destructive !== true) {
            return ['deleted' => false, 'error' => 'Refused: dropping a model is destructive and loses all its data. Re-call with confirm_destructive=true to proceed.'];
        }

        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || $this->ownedModel($model_id, $accountId) === null) {
            return ['deleted' => false, 'error' => 'Model not found in this account.'];
        }

        $resp = [];
        try {
            $controller = $this->dataModelController($resp);
            $controller->delete('id', $model_id);
        } catch (\Throwable $e) {
            return ['deleted' => false, 'error' => $e->getMessage()];
        }

        $check = new \Kyte\Core\ModelObject(\DataModel);
        if ($check->retrieve('id', $model_id) && (int)$check->kyte_account === $accountId) {
            return ['deleted' => false, 'error' => 'Model was not deleted.'];
        }

        return ['deleted' => true, 'model_id' => $model_id];
    }

    private function dataModelController(array &$resp): \Kyte\Mvc\Controller\DataModelController
    {
        return new \Kyte\Mvc\Controller\DataModelController(\DataModel, $this->api, 'm/d/Y H:i:s', $resp, true);
    }

    private function modelAttributeController(array &$resp): \Kyte\Mvc\Controller\ModelAttributeController
    {
        return new \Kyte\Mvc\Controller\ModelAttributeController(\ModelAttribute, $this->api, 'm/d/Y H:i:s', $resp, true);
    }

    /**
     * Build the controller payload for an attribute create/update. Optional
     * fields are only included when set so the controller / prepareModelDef
     * applies its own defaults for the rest.
     *
     * @return array<string,mixed>
     */
    private function attributeData(int $modelId, string $name, string $type, ?int $size, ?int $precision, ?int $scale, bool $unsigned, bool $required, ?string $default, ?int $foreignKeyModel): array
    {
        $data = [
            'dataModel' => $modelId,
            'name'      => $name,
            'type'      => $type,
            'required'  => $required ? 1 : 0,
            'unsigned'  => $unsigned ? 1 : 0,
        ];
        if ($size !== null)            { $data['size']      = $size; }
        if ($precision !== null)       { $data['precision'] = $precision; }
        if ($scale !== null)           { $data['scale']     = $scale; }
        if ($default !== null)         { $data['defaults']  = $default; }
        if ($foreignKeyModel !== null) { $data['foreignKeyModel'] = $foreignKeyModel; }
        return $data;
    }

    /**
     * @return array<string,mixed>
     */
    private function modelSummary(int $modelId): array
    {
        $dm = new \Kyte\Core\ModelObject(\DataModel);
        $dm->retrieve('id', $modelId);
        return [
            'id'          => (int)$dm->id,
            'name'        => (string)($dm->name ?? ''),
            'application' => $dm->application !== null ? (int)$dm->application : null,
            'kyte_locked' => (int)($dm->kyte_locked ?? 0) === 1,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function attributeSummary(int $attributeId): array
    {
        $a = new \Kyte\Core\ModelObject(\ModelAttribute);
        $a->retrieve('id', $attributeId);
        return [
            'id'        => (int)$a->id,
            'name'      => (string)($a->name ?? ''),
            'type'      => (string)($a->type ?? ''),
            'data_model' => $a->dataModel !== null ? (int)$a->dataModel : null,
            'size'      => $a->size !== null ? (int)$a->size : null,
            'precision' => $a->precision !== null ? (int)$a->precision : null,
            'scale'     => $a->scale !== null ? (int)$a->scale : null,
            'required'  => (int)($a->required ?? 0) === 1,
            'unsigned'  => (int)($a->unsigned ?? 0) === 1,
            'foreign_key_model' => $a->foreignKeyModel !== null ? (int)$a->foreignKeyModel : null,
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

    private function modelBelongsToAccount(int $modelId, int $accountId): bool
    {
        return $this->ownedModel($modelId, $accountId) !== null;
    }

    /**
     * Retrieve a DataModel only if it belongs to the given account.
     */
    private function ownedModel(int $modelId, int $accountId): ?\Kyte\Core\ModelObject
    {
        if ($accountId === 0) {
            return null;
        }
        $dm = new \Kyte\Core\ModelObject(\DataModel);
        if (!$dm->retrieve('id', $modelId) || (int)$dm->kyte_account !== $accountId) {
            return null;
        }
        return $dm;
    }

    /**
     * Retrieve a ModelAttribute only if it belongs to the given account.
     */
    private function ownedAttribute(int $attributeId, int $accountId): ?\Kyte\Core\ModelObject
    {
        if ($accountId === 0) {
            return null;
        }
        $attr = new \Kyte\Core\ModelObject(\ModelAttribute);
        if (!$attr->retrieve('id', $attributeId) || (int)$attr->kyte_account !== $accountId) {
            return null;
        }
        return $attr;
    }
}
