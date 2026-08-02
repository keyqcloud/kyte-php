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

    /**
     * Connection + deployment info for building against a Kyte app.
     *
     * QA hit this gap: nothing told the client the API endpoint, so it had to
     * be hand-typed. This returns the API endpoint (what kyte-api-js is
     * initialised with), the MCP endpoint, the account number, and — when an
     * application_id is given — the app identifier plus each site with its live
     * URL(s). Omit application_id for account-level info (endpoint + app list).
     *
     * @param int|null $application_id Optional Application id (from list_applications).
     * @return array<string,mixed>
     */
    #[McpTool(name: 'get_app_info', description: 'Connection + deployment info for building against a Kyte app: the API endpoint (for kyte-api-js init), the MCP endpoint, account number, and — with an application_id — the app identifier and each site with its live URL(s). Call with no application_id for account-level info. Use this instead of guessing the API endpoint.')]
    #[RequiresScope('read')]
    public function getAppInfo(?int $application_id = null): array
    {
        $accountId = isset($this->api->account->id) ? (int)$this->api->account->id : 0;
        if ($accountId === 0) {
            return ['error' => 'No account context.'];
        }

        $host = (defined('API_URL') && API_URL) ? (string)API_URL : (string)($_SERVER['HTTP_HOST'] ?? '');
        $endpoint = $host !== '' ? 'https://' . $host : '';

        $out = [
            'api_endpoint' => $endpoint,
            'mcp_endpoint' => $endpoint !== '' ? $endpoint . '/mcp' : '',
            'account'      => [
                'id'     => $accountId,
                'number' => isset($this->api->account->number) ? (string)$this->api->account->number : '',
            ],
        ];

        if ($application_id === null) {
            $apps = new \Kyte\Core\Model(\Application);
            $apps->retrieve('kyte_account', $accountId);
            $out['applications'] = [];
            foreach ($apps->objects as $a) {
                $out['applications'][] = [
                    'id'         => (int)$a->id,
                    'name'       => (string)($a->name ?? ''),
                    'identifier' => (string)($a->identifier ?? ''),
                ];
            }
            return $out;
        }

        $app = new \Kyte\Core\ModelObject(\Application);
        if (!$app->retrieve('id', $application_id) || (int)$app->kyte_account !== $accountId) {
            $out['error'] = 'Application not found in this account.';
            return $out;
        }
        $identifier = (string)($app->identifier ?? '');
        $out['application'] = [
            'id'         => (int)$app->id,
            'name'       => (string)($app->name ?? ''),
            'identifier' => $identifier,
        ];
        // How the frontend SDK is initialised — the endpoint + identifier pair
        // the client would otherwise have to be told out of band.
        $out['kyte_api_js_init'] = $endpoint !== ''
            ? sprintf("new Kyte('%s', '%s', ...)", $endpoint, $identifier)
            : '';

        $sites = new \Kyte\Core\Model(\KyteSite);
        $sites->retrieve('application', (int)$app->id, false, [
            ['field' => 'kyte_account', 'value' => $accountId],
            ['field' => 'deleted',      'value' => 0],
        ]);
        $siteOut = [];
        foreach ($sites->objects as $s) {
            $cf    = isset($s->cfDomain) ? (string)$s->cfDomain : '';
            $alias = isset($s->aliasDomain) ? (string)$s->aliasDomain : '';
            $custom = [];
            $doms = new \Kyte\Core\Model(\Domain);
            $doms->retrieve('site', (int)$s->id, false, [['field' => 'deleted', 'value' => 0]]);
            foreach ($doms->objects as $d) {
                if (!empty($d->domainName)) {
                    $custom[] = (string)$d->domainName;
                }
            }
            $primary = $alias !== '' ? $alias : $cf;
            $siteOut[] = [
                'id'                => (int)$s->id,
                'name'              => (string)($s->name ?? ''),
                'region'            => isset($s->region) ? (string)$s->region : null,
                'cloudfront_domain' => $cf !== '' ? $cf : null,
                'alias_domain'      => $alias !== '' ? $alias : null,
                'custom_domains'    => $custom,
                'url'               => $primary !== '' ? 'https://' . $primary : null,
            ];
        }
        $out['sites'] = $siteOut;
        return $out;
    }
}
