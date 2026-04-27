<?php
namespace Kyte\Mcp\Tools;

use Kyte\Core\Api;
use Kyte\Mcp\Attribute\RequiresScope;
use Mcp\Capability\Attribute\McpTool;

/**
 * Account-scoped read tools for MCP clients.
 *
 * Tools dispatch under whichever Kyte account the bearer token resolved to.
 * Account scoping comes from $api->account, which McpTokenStrategy::preAuth
 * populates from the token's kyte_account FK before the tool runs.
 */
final class AccountTools
{
    public function __construct(private readonly Api $api)
    {
    }

    /**
     * List Kyte applications owned by the authenticated account.
     *
     * Returns one entry per Application row scoped to the token's account.
     * Identifiers are stable; clients can pass the `identifier` value as the
     * X-Kyte-AppId for traditional API calls if cross-protocol bridging is
     * needed.
     *
     * Wrapped in {applications: [...]} rather than returning a bare list:
     * MCP spec requires structured tool output to be a JSON object (record),
     * not a list. The mcp/sdk's extractStructuredContent passes any returned
     * array through verbatim, so a bare list violates client-side schema
     * validation. Same wrapping pattern across every list_* tool.
     *
     * @return array{applications: array<int, array{id:int, name:string, identifier:string}>}
     */
    #[McpTool(name: 'list_applications', description: 'List Kyte applications for the authenticated account.')]
    #[RequiresScope('read')]
    public function listApplications(): array
    {
        $accountId = isset($this->api->account->id) ? (int)$this->api->account->id : 0;
        if ($accountId === 0) {
            return ['applications' => []];
        }

        $model = new \Kyte\Core\Model(\Application);
        $model->retrieve('kyte_account', $accountId);

        $out = [];
        foreach ($model->objects as $app) {
            $out[] = [
                'id'         => (int)$app->id,
                'name'       => (string)($app->name ?? ''),
                'identifier' => (string)($app->identifier ?? ''),
            ];
        }
        return ['applications' => $out];
    }
}
