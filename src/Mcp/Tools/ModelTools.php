<?php
namespace Kyte\Mcp\Tools;

use Kyte\Core\Api;
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
     * @return array<int, array{id:int, name:string, kyte_locked:bool}>
     */
    #[McpTool(name: 'list_models', description: 'List data models in a Kyte application. Returns metadata only — call read_model for the full schema.')]
    #[RequiresScope('read')]
    public function listModels(int $application_id): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->applicationBelongsToAccount($application_id, $accountId)) {
            return [];
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
            ];
        }
        return $out;
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
    #[McpTool(name: 'read_model', description: 'Read a data model including its full schema definition (decoded from JSON).')]
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

        $raw = $dm->model_definition !== null ? (string)$dm->model_definition : null;
        $decoded = null;
        if ($raw !== null && $raw !== '') {
            $tryDecode = json_decode($raw, true);
            if (is_array($tryDecode)) {
                $decoded = $tryDecode;
            }
        }

        return [
            'id'             => (int)$dm->id,
            'name'           => (string)($dm->name ?? ''),
            'application'    => $dm->application !== null ? (int)$dm->application : null,
            'kyte_locked'    => (int)$dm->kyte_locked === 1,
            'definition'     => $decoded,
            'raw_definition' => $decoded === null ? $raw : null,
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
}
