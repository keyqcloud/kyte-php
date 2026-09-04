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
     * ⚠️ MCP KNOWLEDGE BASE — KEEP IN SYNC. This guide is verified against the
     * kyte-api-js SDK (kyte-source.js: get/post/put/delete, sessionCreate/Destroy,
     * response + error handling). Any MATERIAL change to that SDK's method
     * signatures, response/error shape, or the injected `k` bootstrap MUST update
     * this guide in the same change, or AI-generated page JS will drift out of
     * spec. Treat this as part of the SDK's public contract.
     *
     * @return array<string,mixed>
     */
    #[McpTool(name: 'get_kytejs_guide', description: 'How to write JavaScript for Kyte pages/scripts. Kyte injects a global API client `k` into every published page; page/script JS calls the backend via k.get/k.post/k.put/k.delete(model, ...). Returns the exact signatures, the response shape, session helpers, and a worked example. Call this BEFORE writing any page or script JavaScript.')]
    #[RequiresScope('read')]
    public function getKytejsGuide(): array
    {
        return [
            'overview' =>
                'Kyte publishes each page with an API client already CONSTRUCTED and INITIALISED as '
                . 'the GLOBAL variable `k` (the page bootstrap runs new Kyte(...) then k.init() for '
                . 'you). In page HTML/JS and in site scripts, use `k` directly. Do NOT construct your '
                . 'own client, do NOT call k.init() again, and do NOT hard-code the API URL/keys or '
                . 'use fetch()/REST — `k` owns the endpoint + credentials.',
            'data_model' =>
                'Access is MODEL-based, not URL-based. The first argument to every call is a '
                . 'model/controller NAME string (e.g. "Task"). Use the EXACT name as defined in the '
                . 'app (from get_app_info / list_models / list_controllers) — do not guess casing; '
                . 'resolution happens server-side.',
            'methods' => [
                'get'    => 'k.get(model, field, value, headers, onSuccess, onError) — READ. field+value '
                    . 'filters (e.g. "id", 42); field=null & value=null returns all. CAVEAT: a falsy '
                    . 'value (0 or "") is dropped from the request — do not filter by a literal 0/"".',
                'post'   => 'k.post(model, data, formData, headers, onSuccess, onError) — CREATE.',
                'put'    => 'k.put(model, field, value, data, formData, headers, onSuccess, onError) — '
                    . 'UPDATE the row(s) matching field=value with `data`.',
                'delete' => 'k.delete(model, field, value, headers, onSuccess, onError) — DELETE the '
                    . 'row(s) matching field=value.',
            ],
            'custom_scripts' =>
                'Adding a standalone JS/CSS asset file (separate from a page\'s inline JS) is done '
                . 'ENTIRELY via MCP — never tell the user to upload to S3 manually. Flow: '
                . 'create_script(site_id, name, filename) -> write_script_content(script_id, source) -> '
                . 'commit_draft(surface="script", draft_id) to PUBLISH the asset to S3/CloudFront -> '
                . 'assign_script(script_id, page_id) to include it on a page (adds the <script>/<link> tag). '
                . 'A script renders on a page only once it is BOTH published AND assigned. Set '
                . 'include_all=true on create_script to auto-load it on every page instead of assigning per page.',
            'arguments' => [
                'headers'   => '`headers` is a REQUIRED positional slot BEFORE the callbacks — always '
                    . 'pass [] when you have none. If you omit it and pass a callback in its place, the '
                    . 'callback is silently treated as headers and never fires (a common bug).',
                'data'      => '`data` (post/put) is a flat plain object {column: value}; it is '
                    . 'URL-encoded for you. Not JSON, not nested.',
                'formData'  => '`formData` is NOT a browser FormData object — it is a pre-serialized '
                    . 'URL-encoded string appended to the body. Pass null unless you specifically need it.',
                'callbacks' => 'onSuccess is required (positional); onError is optional.',
            ],
            'response' =>
                'onSuccess receives the WHOLE response object (not just data) — it also carries '
                . 'total_count, total_filtered, account_id, etc. The SHAPE of response.data depends on '
                . 'the controller: DEFAULT CRUD returns an ARRAY of row objects (a single filtered '
                . 'record is response.data[0]); a CUSTOM controller (a get override) returns whatever '
                . 'it set — often a plain object or scalar. Do NOT assume response.data is an array '
                . 'when calling a custom controller — match how that controller sets its data.',
            'foreign_keys' =>
                'CRITICAL and easy to miss: a FOREIGN-KEY column comes back EXPANDED as the full '
                . 'nested referenced object, NOT the scalar id you wrote. If Loan has an `asset` FK, '
                . 'then loan.asset is the whole Asset row ({ id, name, ... }) — loan.asset === 42 is '
                . 'ALWAYS false, so id comparisons/lookups silently match nothing and you get a wrong '
                . 'answer with NO error (the worst failure for an agent build). Expansion is ONE level '
                . 'deep: the FK columns INSIDE that nested object stay scalar ids. Normalise before '
                . 'comparing: `function fkId(v){ return (v && typeof v === "object") ? v.id : v; }` then '
                . 'use fkId(loan.asset) === asset.id. (Reading loan.asset.id directly also works when '
                . 'you know it is expanded — fkId() is the safe form that tolerates either shape.)',
            'raw_requests' =>
                'NEVER hand-roll a request with fetch()/XHR/axios — always go through `k`. `k` attaches '
                . 'the auth headers the server REQUIRES, and they are not obvious: in JWT mode every call '
                . 'sends x-kyte-appid (always) + Authorization: Bearer <jwt> (when a session exists); in '
                . 'HMAC mode it sends a computed x-kyte-signature + x-kyte-identity that you CANNOT '
                . 'reproduce client-side (they come from k.sign()). A raw request missing x-kyte-appid '
                . 'never even enters the anonymous app-context path server-side, so it silently goes '
                . 'nowhere — no useful error. If you think you need a raw request, you do not: use '
                . 'k.get/k.post/k.put/k.delete.',
            'errors' =>
                'onError usually receives the server error message STRING, but on transport / '
                . 'token-refresh failures it may receive an OBJECT (jqXHR or {error, detail}), and on a '
                . 'null / non-JSON error it may not fire at all. Normalise it: '
                . 'onError(err => { var msg = typeof err === "string" ? err : ((err && (err.error || '
                . '(err.responseJSON && err.responseJSON.error))) || "Request failed"); ... }). On HTTP '
                . '403 the SDK auto-runs session-destroy + redirect-to-login — do NOT write your own 403 re-login.',
            'session' => [
                'create'  => 'k.sessionCreate(identity, onSuccess, onError) — log in. identity is an '
                    . 'object (e.g. {email, password}). Optional 4th arg = a custom login/session controller '
                    . 'name (defaults to the built-in "Session").',
                'signup'  => 'MEMBERSHIP app: a not-yet-logged-in visitor registers with an ANONYMOUS create — '
                    . 'k.post("User", {email, password, name}, null, [], onOk, onErr) — pointed at your user '
                    . 'model / signup controller. This only works when the app is in JWT auth mode with anonymous '
                    . 'access enabled AND a requireAuth=false signup controller permits it. In HMAC mode there is '
                    . 'NO anonymous request path, so public signup cannot work. Call get_auth_guide for the full '
                    . 'server+client recipe (auth_mode=jwt, allow_public=2, signup controller, then these calls).',
                'destroy' => 'k.sessionDestroy(onComplete) — takes ONE callback that runs after logout '
                    . 'whether it succeeded or failed; put your redirect there: '
                    . 'k.sessionDestroy(function(){ location.href = "/"; }). It is NOT (onSuccess, onError) '
                    . '— a redirect passed as a 2nd arg is ignored. (Or k.addLogoutHandler(selector) wires '
                    . 'a logout+redirect click handler for you.)',
                'check'   => 'k.checkSession() returns a BOOLEAN — true when a session is active. Use it to '
                    . 'gate protected pages (redirect unauthenticated visitors). The page bootstrap already '
                    . 'ran k.init(), so an existing session is loaded.',
            ],
            'example' => implode("\n", [
                "// READ (default CRUD) — response.data is an ARRAY of rows",
                "k.get('Task', null, null, [], function (response) {",
                "    response.data.forEach(function (t) { renderTask(t); });",
                "}, function (err) { console.error(err); });",
                "",
                "// CREATE — default CRUD returns a one-element array",
                "k.post('Task', { title: 'Buy milk', quadrant: 'urgent_important', done: 0 }, null, [], function (response) {",
                "    var created = response.data[0];",
                "}, function (err) {});",
                "",
                "// UPDATE / DELETE by id (note headers = [] before the callbacks)",
                "k.put('Task', 'id', taskId, { done: 1 }, null, [], function (r) {}, function (e) {});",
                "k.delete('Task', 'id', taskId, [], function (r) {}, function (e) {});",
                "",
                "// CUSTOM controller (get override) — response.data is whatever it returns (here an object)",
                "k.get('TaskStats', null, null, [], function (response) {",
                "    var stats = response.data;   // e.g. { total: 10, done: 4 } — NOT an array",
                "}, function (err) {});",
                "",
                "// FOREIGN KEYS come back EXPANDED — normalise before comparing ids",
                "function fkId(v){ return (v && typeof v === 'object') ? v.id : v; }",
                "k.get('Loan', null, null, [], function (response) {",
                "    var onLoan = response.data.filter(function (loan) {",
                "        return fkId(loan.asset) === assetId;   // loan.asset is the whole Asset row, not 42",
                "    });",
                "}, function (err) {});",
            ]),
            'rules' => [
                'Use the injected global `k` (already constructed + init()-ed) — never new Kyte(...), never hard-code endpoint/keys.',
                'First arg is the exact model/controller NAME string (not a URL); get names from get_app_info / list_models / list_controllers.',
                '`headers` is a required positional [] before the callbacks.',
                'response.data is an ARRAY for default CRUD, but whatever the controller sets for a custom override — do not assume.',
                'A foreign-key column returns the FULL nested object, not a scalar id (loan.asset is the Asset row) — normalise with fkId() before any id comparison, or lookups silently match nothing.',
                'Never hand-roll fetch()/XHR — `k` attaches required auth headers (x-kyte-appid, Bearer, or HMAC signature) you cannot reliably reproduce; a raw request silently goes nowhere.',
                'onSuccess gets the full response object; onError may get a string OR an object OR not fire at all.',
                '`formData` is a URL-encoded string, not a browser FormData object.',
                'k.sessionDestroy takes ONE completion callback (put the redirect there).',
                'Building login / signup / a membership app? Call get_auth_guide — the full recipe spans app settings (auth_mode=jwt, allow_public=2) + a signup controller + these client calls, and is easy to assemble wrong.',
            ],
        ];
    }
}
