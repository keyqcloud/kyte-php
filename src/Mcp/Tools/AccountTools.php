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

    /**
     * The KyteJS reference for writing page/script JavaScript. Kyte apps talk to
     * the backend through an injected client, NOT REST URLs — this tool gives an
     * AI client the exact API so generated page/script JS actually works.
     *
     * @return array<string,mixed>
     */
    #[McpTool(name: 'get_kytejs_guide', description: 'How to write JavaScript for Kyte pages/scripts. Kyte injects a global API client `k` into every published page; page/script JS calls the backend via k.get/k.post/k.put/k.delete(model, ...). Returns the exact signatures, the response shape, session helpers, and a worked example. Call this BEFORE writing any page or script JavaScript.')]
    #[RequiresScope('read')]
    public function getKytejsGuide(): array
    {
        return [
            'overview' =>
                'Kyte publishes each page with an API client already instantiated as the '
                . 'GLOBAL variable `k` (a Kyte instance, from the kyte-api-js SDK loaded on the '
                . 'page). In page HTML/JS and in site scripts, use `k` directly for all backend '
                . 'calls. Do NOT create your own client (no `new Kyte(...)`), and do NOT hard-code '
                . 'the API URL, keys, or fetch()/REST paths — `k` is pre-configured with the '
                . "app's endpoint + credentials.",
            'data_model' =>
                'Access is MODEL-based, not URL-based. The first argument to every call is a '
                . 'model/controller NAME (a string, e.g. "Task", "UserProfile") — the same name '
                . 'you gave create_model / create_controller. Kyte routes it to that controller.',
            'methods' => [
                'get'    => 'k.get(model, field, value, headers, onSuccess, onError) — READ. '
                    . 'Pass field+value to filter (e.g. "id", 42), or field=null & value=null for all rows.',
                'post'   => 'k.post(model, data, formData, headers, onSuccess, onError) — CREATE. '
                    . '`data` is a plain object of column→value; pass formData=null unless uploading files.',
                'put'    => 'k.put(model, field, value, data, formData, headers, onSuccess, onError) — '
                    . 'UPDATE the row(s) matching field=value with the `data` object.',
                'delete' => 'k.delete(model, field, value, headers, onSuccess, onError) — DELETE the '
                    . 'row(s) matching field=value.',
            ],
            'callbacks' =>
                'onSuccess(response): response.data is ALWAYS an array — a single record is '
                . 'response.data[0]. onError(error): error is a string message (or object). Both '
                . 'callbacks are required for robust code. `headers` is usually an empty array [].',
            'session' => [
                'create'  => 'k.sessionCreate(credentials, onSuccess, onError) — log a user in.',
                'destroy' => 'k.sessionDestroy(onSuccess, onError) — log out; then redirect.',
                'note'    => 'Session state is managed by `k`; authenticated calls carry it automatically.',
            ],
            'example' => implode("\n", [
                "// Read all Task rows for the current user",
                "k.get('Task', null, null, [], function (response) {",
                "    const tasks = response.data;           // always an array",
                "    tasks.forEach(t => renderTask(t));",
                "}, function (err) {",
                "    console.error('load failed:', err);",
                "});",
                "",
                "// Create a Task",
                "k.post('Task', { title: 'Buy milk', quadrant: 'urgent_important', done: 0 }, null, [], function (response) {",
                "    const created = response.data[0];       // the new row",
                "    addTaskToDom(created);",
                "}, function (err) { showError(err); });",
                "",
                "// Update a Task",
                "k.put('Task', 'id', taskId, { done: 1 }, null, [], function (r) { /* ok */ }, function (e) {});",
                "",
                "// Delete a Task",
                "k.delete('Task', 'id', taskId, [], function (r) { /* ok */ }, function (e) {});",
            ]),
            'rules' => [
                'Use the injected global `k` — never instantiate a client or hard-code the endpoint/keys.',
                'First arg is a model/controller NAME string, not a URL path.',
                'response.data is always an array (single record = response.data[0]).',
                'Attach both success and error callbacks.',
                'Get the endpoint/identifier/site URLs from get_app_info; you do not put them in JS yourself.',
            ],
        ];
    }
}
