<?php
namespace Kyte\Mcp\Tools;

use Kyte\Core\Api;
use Kyte\Mcp\Attribute\RequiresScope;
use Mcp\Capability\Attribute\McpTool;

/**
 * Application lifecycle tools (create / update / delete). Reads
 * (list_applications) live in AccountTools.
 *
 * Creating an application provisions an isolated tenant database on the platform
 * RDS and binds the account's AWS credential (ApplicationController). No S3
 * bucket is created for an app — site buckets + CloudFront are per-site,
 * provisioned by the site tools + the KYTE-#201 worker. The MCP path never
 * handles raw AWS secrets: create_application uses the account's already-
 * configured credential; a custom key must be set in Shipyard first.
 *
 * delete_application is deliberately conservative: it refuses to delete an app
 * that still has live sites, because their AWS teardown (S3 + CloudFront + ACM)
 * is asynchronous and deleting the app first would orphan that infrastructure.
 * Tear sites down with delete_site, then delete the app. Full cascade teardown +
 * an app-level lifecycle status (for in-progress UI) is tracked separately.
 *
 * Mutations gated by `provision`; every id is re-scoped to the token's account.
 */
final class AppTools
{
    public function __construct(private readonly Api $api)
    {
    }

    /**
     * Create a new Kyte application (provisions an isolated tenant database).
     *
     * @param string      $name     Application name.
     * @param string|null $language Default language code (e.g. "en"); optional.
     * @return array{created: bool, application?: array<string,mixed>|null, error?: string}
     */
    #[McpTool(name: 'create_application', description: 'Create a new Kyte application (provisions an isolated tenant database). Uses the account\'s configured AWS credential — set one in Shipyard first if none exists.')]
    #[RequiresScope('provision')]
    public function createApplication(string $name, ?string $language = null): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0) {
            return ['created' => false, 'error' => 'No account context.'];
        }

        $api  = $this->api;
        $resp = [];
        try {
            $controller = new \Kyte\Mvc\Controller\ApplicationController(\Application, $api, 'm/d/Y H:i:s', $resp, true);
            $data = ['name' => $name];
            if ($language !== null) { $data['language'] = $language; }
            $controller->new($data);
        } catch (\Throwable $e) {
            return ['created' => false, 'error' => $e->getMessage()];
        }

        $newId = isset($resp['data'][0]['id']) ? (int)$resp['data'][0]['id'] : 0;
        if ($newId === 0) {
            return ['created' => false, 'error' => 'Application was not created.'];
        }

        // Generate the app's kyte_connect snippet. Shipyard normally builds this;
        // an MCP-created app would otherwise have an EMPTY one, and since it's
        // injected into every published page (KytePageController) that leaves the
        // global `k` client undefined — all frontend JS then fails at runtime.
        $this->generateKyteConnect($newId, $accountId);

        return ['created' => true, 'application' => $this->appToArray($newId)];
    }

    /**
     * Build + persist the Application.kyte_connect snippet — the
     * `var k = new Kyte(endpoint, publicKey, identifier, accountNumber, appId);`
     * bootstrap injected into every published page so page/script JS has the
     * global `k` client. Deterministic from the account's API key + the app
     * identifier. No-op (logged) if there's no API key or no resolvable endpoint,
     * or if kyte_connect is already set.
     */
    private function generateKyteConnect(int $appId, int $accountId): void
    {
        try {
            $app = new \Kyte\Core\ModelObject(\Application);
            if (!$app->retrieve('id', $appId) || (string)($app->kyte_connect ?? '') !== '') {
                return;
            }
            $acct = new \Kyte\Core\ModelObject(\KyteAccount);
            $key  = new \Kyte\Core\ModelObject(\KyteAPIKey);
            if (!$acct->retrieve('id', $accountId) || !$key->retrieve('kyte_account', $accountId)) {
                error_log("create_application: no API key/account for kyte_connect (app {$appId}); pages will need it set before publish.");
                return;
            }
            $host = (defined('API_URL') && API_URL) ? (string)API_URL : (string)($_SERVER['HTTP_HOST'] ?? '');
            if ($host === '') {
                return;
            }
            // New apps default to HMAC (Application.auth_mode default). set_app_auth_mode
            // regenerates this snippet if the app is switched to JWT.
            $connect = $this->buildKyteConnect($host, (string)$app->identifier, 'hmac', (string)$key->public_key, (string)$key->identifier, (string)$acct->number);
            $app->save(['kyte_connect' => $connect]);
        } catch (\Throwable $e) {
            error_log('create_application: kyte_connect generation failed - ' . $e->getMessage());
        }
    }

    /**
     * Build the `kyte_connect` bootstrap (the injected global `k`) for an app's
     * auth mode. HMAC uses the signed-request keys; JWT nulls them and passes
     * { authMode: 'jwt' } so the client uses bearer sessions + the anonymous path.
     */
    private function buildKyteConnect(string $host, string $appIdentifier, string $mode, ?string $publicKey = null, ?string $keyIdentifier = null, ?string $accountNumber = null): string
    {
        if ($mode === 'jwt') {
            return sprintf(
                "let endpoint = 'https://%s';\nvar k = new Kyte(endpoint, null, null, null, '%s', { authMode: 'jwt' });\nk.init();",
                $host,
                $appIdentifier
            );
        }
        return sprintf(
            "let endpoint = 'https://%s';var k = new Kyte(endpoint, '%s', '%s', '%s', '%s');k.init();",
            $host,
            (string)$publicKey,
            (string)$keyIdentifier,
            (string)$accountNumber,
            $appIdentifier
        );
    }

    /**
     * Update a Kyte application's name or default language.
     *
     * @param int         $application_id Application id.
     * @param string|null $name           New name.
     * @param string|null $language       New default language code.
     * @return array{updated: bool, application?: array<string,mixed>|null, error?: string}
     */
    #[McpTool(name: 'update_application', description: 'Update a Kyte application\'s name or default language.')]
    #[RequiresScope('provision')]
    public function updateApplication(int $application_id, ?string $name = null, ?string $language = null): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->appBelongsToAccount($application_id, $accountId)) {
            return ['updated' => false, 'error' => 'Application not found in this account.'];
        }

        $data = [];
        if ($name !== null)     { $data['name'] = $name; }
        if ($language !== null) { $data['language'] = $language; }
        if (empty($data)) {
            return ['updated' => false, 'error' => 'No updatable fields provided (name, language).'];
        }

        $api  = $this->api;
        $resp = [];
        try {
            $controller = new \Kyte\Mvc\Controller\ApplicationController(\Application, $api, 'm/d/Y H:i:s', $resp, true);
            $controller->update('id', $application_id, $data);
        } catch (\Throwable $e) {
            return ['updated' => false, 'error' => $e->getMessage()];
        }
        return ['updated' => true, 'application' => $this->appToArray($application_id)];
    }

    /**
     * Delete a Kyte application. This starts an ASYNCHRONOUS teardown: the app
     * (and each of its sites) is marked "deleting", a background worker tears
     * down all site AWS infrastructure (S3 + CloudFront + ACM), and once that's
     * done it drops the tenant database and removes the app. Poll
     * list_applications until the app disappears.
     *
     * @param int $application_id Application id.
     * @return array{deleting: bool, application_id?: int, sites_tearing_down?: int, message?: string, error?: string}
     */
    #[McpTool(name: 'delete_application', description: 'Delete a Kyte application — starts an asynchronous teardown of its sites (S3/CloudFront/ACM) and then its tenant database. Poll list_applications until the app disappears.')]
    #[RequiresScope('provision')]
    public function deleteApplication(int $application_id): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->appBelongsToAccount($application_id, $accountId)) {
            return ['deleting' => false, 'error' => 'Application not found in this account.'];
        }

        $app = new \Kyte\Core\ModelObject(\Application);
        $app->retrieve('id', $application_id);
        if ((string)($app->status ?? '') === 'deleting') {
            return ['deleting' => true, 'application_id' => $application_id, 'message' => 'Teardown already in progress. Poll list_applications until the app disappears.'];
        }

        // ApplicationController's delete hook marks the app + its sites 'deleting'
        // (it does NOT drop anything synchronously); the SiteProvisioningWorker
        // finalizes the teardown.
        $api  = $this->api;
        $resp = [];
        try {
            $controller = new \Kyte\Mvc\Controller\ApplicationController(\Application, $api, 'm/d/Y H:i:s', $resp, true);
            $controller->delete('id', $application_id);
        } catch (\Throwable $e) {
            return ['deleting' => false, 'error' => $e->getMessage()];
        }

        $sites = new \Kyte\Core\Model(\KyteSite);
        $sites->retrieve('application', $application_id, false, [
            ['field' => 'kyte_account', 'value' => $accountId],
        ]);
        $n = 0;
        foreach ($sites->objects as $s) {
            if ((string)($s->status ?? '') === 'deleting') { $n++; }
        }

        return [
            'deleting'           => true,
            'application_id'     => $application_id,
            'sites_tearing_down' => $n,
            'message'            => "Teardown started. {$n} site(s) tearing down in the background (S3/CloudFront take minutes); the tenant database drops and the app is removed once they're gone. Poll list_applications until it disappears.",
        ];
    }

    /**
     * Configure an application's built-in login: which user data model and which
     * of its columns hold the username and password. WITHOUT this, the platform
     * login/session endpoint authenticates against the platform user table and
     * rejects your app's users — this is the usual reason "login rejects valid
     * credentials."
     *
     * Pair it with a password column flagged password=true (add_attribute /
     * update_attribute) so the framework hashes credentials for login. Your
     * signup should store the PLAINTEXT password and let Kyte hash it — do not
     * hash it yourself, or logins fail on a double-hash.
     *
     * @param int    $application_id Application id (from list_applications).
     * @param string $user_model     DataModel name that holds app users (e.g. "User").
     * @param string $username_field Column used as the login username (e.g. "email").
     * @param string $password_field Column that holds the (hashed) password (e.g. "password").
     * @return array{configured: bool, application_id?: int, user_model?: string, username_field?: string, password_field?: string, error?: string}
     */
    #[McpTool(name: 'configure_app_login', description: 'Configure an app\'s built-in login: which user data model + the username and password columns to authenticate against. Required for the login/session endpoint to accept your app\'s users (without it, login rejects valid credentials). Pair with a password column flagged password=true via add_attribute; signup should store the plaintext password and let Kyte hash it. Building login + public signup end-to-end? Call get_auth_guide first (you also need auth_mode=jwt + allow_public=2).')]
    #[RequiresScope('provision')]
    public function configureAppLogin(int $application_id, string $user_model, string $username_field, string $password_field): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->appBelongsToAccount($application_id, $accountId)) {
            return ['configured' => false, 'error' => 'Application not found in this account.'];
        }
        if (trim($user_model) === '' || trim($username_field) === '' || trim($password_field) === '') {
            return ['configured' => false, 'error' => 'user_model, username_field, and password_field are all required.'];
        }

        // Verify the named user model exists in this app (clear error on a typo).
        $dm = new \Kyte\Core\Model(\DataModel);
        $dm->retrieve('application', $application_id, false, [
            ['field' => 'name',         'value' => $user_model],
            ['field' => 'kyte_account', 'value' => $accountId],
            ['field' => 'deleted',      'value' => 0],
        ]);
        if (count($dm->objects) === 0) {
            return ['configured' => false, 'error' => "No data model named '{$user_model}' in this application."];
        }

        $app = new \Kyte\Core\ModelObject(\Application);
        if (!$app->retrieve('id', $application_id)) {
            return ['configured' => false, 'error' => 'Application not found.'];
        }
        $app->save([
            'user_model'       => $user_model,
            'username_colname' => $username_field,
            'password_colname' => $password_field,
        ]);

        return [
            'configured'     => true,
            'application_id' => $application_id,
            'user_model'     => $user_model,
            'username_field' => $username_field,
            'password_field' => $password_field,
            'note'           => 'Login now authenticates against this model. Ensure the password column is flagged password=true (add_attribute) and that signup stores the plaintext password (Kyte hashes it).',
        ];
    }

    /**
     * Set an application's anonymous (public, unauthenticated) access level.
     *
     * allow_public is a tri-state gate applied BEFORE controller auth:
     *   0 = none (default): every request must authenticate (login/session or HMAC).
     *   1 = read-only: unauthenticated callers may GET, regardless of a
     *       controller's allowableActions. Writes still require auth.
     *   2 = controller-governed: unauthenticated callers may also write IF the
     *       target controller sets $this->requireAuth = false and permits the
     *       action. This is what PUBLIC SIGNUP needs — an anonymous visitor
     *       creating their own account before they can log in.
     *
     * Security: levels 1 and 2 expose data/behavior to unauthenticated callers.
     * Use the narrowest level that works; pair level 2 with a signup controller
     * that only permits the create it needs.
     *
     * @param int $application_id Application id (from list_applications).
     * @param int $level          0 = none, 1 = read-only, 2 = controller-governed.
     * @return array{updated: bool, application_id?: int, allow_public?: int, error?: string}
     */
    #[McpTool(name: 'set_app_anonymous_access', description: 'Set an app\'s anonymous (unauthenticated) access level. This is only a GATE — it never serves anything by itself; the target model ALWAYS needs its own controller with requireAuth=false, or the default controller (requireAuth=true) still returns "Unauthorized API request." 0 = none (default, all requests need auth). 1 = read-only gate: anonymous requests may reach a requireAuth=false controller but are clamped to GET at the platform. 2 = controller-governed: the requireAuth=false controller\'s own allowableActions govern, including writes. PUBLIC SIGNUP needs level 2 + a requireAuth=false signup controller (+ auth_mode=jwt). PUBLIC READ-ONLY catalog needs level 1 + a requireAuth=false read controller. Also requires auth_mode=jwt (HMAC has no anonymous path). Use the narrowest level that works. Call get_auth_guide for the full recipe; check the current level with read_application.')]
    #[RequiresScope('provision')]
    public function setAppAnonymousAccess(int $application_id, int $level): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->appBelongsToAccount($application_id, $accountId)) {
            return ['updated' => false, 'error' => 'Application not found in this account.'];
        }
        if (!in_array($level, [0, 1, 2], true)) {
            return ['updated' => false, 'error' => 'level must be 0 (none), 1 (read-only), or 2 (controller-governed).'];
        }

        $api  = $this->api;
        $resp = [];
        try {
            $controller = new \Kyte\Mvc\Controller\ApplicationController(\Application, $api, 'm/d/Y H:i:s', $resp, true);
            $controller->update('id', $application_id, ['allow_public' => $level]);
        } catch (\Throwable $e) {
            return ['updated' => false, 'error' => $e->getMessage()];
        }

        $labels = [0 => 'none', 1 => 'read-only', 2 => 'controller-governed'];
        return [
            'updated'        => true,
            'application_id' => $application_id,
            'allow_public'   => $level,
            'note'           => "Anonymous access set to {$level} ({$labels[$level]})."
                . ($level === 1 ? ' This is only a GATE (clamped to GET): the model STILL needs a controller with requireAuth=false or reads return "Unauthorized API request." Also requires auth_mode=jwt. Call get_auth_guide for the read-only recipe.' : '')
                . ($level === 2 ? ' For PUBLIC SIGNUP this is necessary but NOT sufficient: also set auth_mode=jwt (set_app_auth_mode) and give the signup controller requireAuth=false. Call get_auth_guide for the full recipe.' : ''),
        ];
    }

    /**
     * Set an application's API auth mode: 'hmac' (default, signed requests) or
     * 'jwt' (bearer-token sessions + the anonymous/public path that PUBLIC SIGNUP
     * needs). Also REGENERATES the app's injected `k` bootstrap (kyte_connect) to
     * match — without that, published pages keep booting the old mode. Because the
     * bootstrap is baked in at publish time, existing pages must be REPUBLISHED to
     * pick up the change.
     *
     * @param int    $application_id Application id (from list_applications).
     * @param string $mode           'hmac' or 'jwt'.
     * @return array{updated: bool, application_id?: int, auth_mode?: string, error?: string}
     */
    #[McpTool(name: 'set_app_auth_mode', description: 'Set an app\'s API auth mode: "hmac" (default; signed requests, no anonymous path) or "jwt" (bearer-token sessions AND the anonymous/public path required for PUBLIC SIGNUP). Also regenerates the injected `k` client bootstrap to match — REPUBLISH pages afterward to pick it up. For a public-signup membership app you need auth_mode=jwt AND set_app_anonymous_access(2) AND a signup controller with requireAuth=false; the install must also have KYTE_JWT_SECRET set. Call get_auth_guide for the full recipe. Changing an existing app\'s mode changes how ALL its clients authenticate.')]
    #[RequiresScope('provision')]
    public function setAppAuthMode(int $application_id, string $mode): array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->appBelongsToAccount($application_id, $accountId)) {
            return ['updated' => false, 'error' => 'Application not found in this account.'];
        }
        if (!in_array($mode, ['hmac', 'jwt'], true)) {
            return ['updated' => false, 'error' => "mode must be 'hmac' or 'jwt'."];
        }

        $app = new \Kyte\Core\ModelObject(\Application);
        if (!$app->retrieve('id', $application_id)) {
            return ['updated' => false, 'error' => 'Application not found.'];
        }

        // Regenerate the injected `k` bootstrap to match the mode (else published
        // pages keep booting the old auth mode).
        $host = (defined('API_URL') && API_URL) ? (string)API_URL : (string)($_SERVER['HTTP_HOST'] ?? '');
        $connect = null;
        if ($host !== '') {
            if ($mode === 'jwt') {
                $connect = $this->buildKyteConnect($host, (string)$app->identifier, 'jwt');
            } else {
                $acct = new \Kyte\Core\ModelObject(\KyteAccount);
                $key  = new \Kyte\Core\ModelObject(\KyteAPIKey);
                if ($acct->retrieve('id', $accountId) && $key->retrieve('kyte_account', $accountId)) {
                    $connect = $this->buildKyteConnect($host, (string)$app->identifier, 'hmac', (string)$key->public_key, (string)$key->identifier, (string)$acct->number);
                }
            }
        }

        $save = ['auth_mode' => $mode];
        if ($connect !== null) { $save['kyte_connect'] = $connect; }
        try {
            $app->save($save);
        } catch (\Throwable $e) {
            return ['updated' => false, 'error' => $e->getMessage()];
        }

        return [
            'updated'                  => true,
            'application_id'           => $application_id,
            'auth_mode'                => $mode,
            'kyte_connect_regenerated' => $connect !== null,
            'note'                     => $mode === 'jwt'
                ? 'Set to JWT (bearer sessions + anonymous/public path). Bootstrap regenerated — REPUBLISH pages to apply. Public signup also needs set_app_anonymous_access(2), a requireAuth=false signup controller, and KYTE_JWT_SECRET on the install. See get_auth_guide.'
                : 'Set to HMAC (signed requests; no anonymous path). Bootstrap regenerated — REPUBLISH pages to apply.',
        ];
    }

    /**
     * Read a single application's details (name, identifier, language, status,
     * login config, auth_mode, allow_public).
     *
     * @param int $application_id Application id (from list_applications).
     * @return array<string,mixed>|null
     */
    #[McpTool(name: 'read_application', description: 'Read a single Kyte application by id: name, identifier, language, status, login config (user_model + username/password fields), auth_mode, and allow_public (anonymous-access GATE level: 0 none, 1 read-only gate, 2 controller-governed — note the gate alone never serves a request; the model still needs a requireAuth=false controller).')]
    #[RequiresScope('read')]
    public function readApplication(int $application_id): ?array
    {
        $accountId = $this->accountIdOrZero();
        if ($accountId === 0 || !$this->appBelongsToAccount($application_id, $accountId)) {
            return null;
        }
        return $this->appToArray($application_id);
    }

    /**
     * Consolidated membership recipe. Keep in sync with: set_app_auth_mode +
     * set_app_anonymous_access + configure_app_login (this file), add_attribute
     * flags (ModelTools), the signup-controller pattern (get_controller_guide /
     * KytePasswordResetController), and the client calls (get_kytejs_guide /
     * kyte-api-js sessionCreate/sessionDestroy/checkSession).
     *
     * @return array<string,mixed>
     */
    #[McpTool(name: 'get_auth_guide', description: 'How to build ANY anonymous/public or membership access on Kyte end-to-end: user login + PUBLIC SIGNUP (auth_mode=jwt, allow_public=2, signup controller) AND public READ-ONLY catalog/storefront access (allow_public=1 + a requireAuth=false read controller). Covers the required app settings, the user model + password flag, configure_app_login, the controller patterns, the load-bearing "default controllers are closed" rule, and the client `k` calls. Call this BEFORE building any login/signup/membership/public-read flow — these tools are easy to assemble wrong.')]
    #[RequiresScope('read')]
    public function getAuthGuide(): array
    {
        return [
            'overview' =>
                'A membership app lets an app\'s OWN end-users sign up + log in (separate from the Kyte platform '
                . 'account). Two app-level settings are REQUIRED and are the usual reason signup/login silently '
                . 'fails: (1) auth_mode=jwt (set_app_auth_mode) — the anonymous/public request path and bearer '
                . 'sessions only exist in JWT mode; HMAC has NO anonymous path, so public signup is impossible in '
                . 'HMAC regardless of other settings. (2) allow_public=2 (set_app_anonymous_access) — lets an '
                . 'unauthenticated visitor reach a requireAuth=false controller to create their account. Verify both '
                . 'with read_application.',
            'recipe' => [
                '1. set_app_auth_mode(app, "jwt") — enables anonymous + JWT sessions; regenerates the injected `k`. Republish pages after.',
                '2. set_app_anonymous_access(app, 2) — allow anonymous, controller-governed writes (needed for signup).',
                '3. create_model "User" + add_attribute: email (s), password (s, password=true + protected=true), plus profile fields. password=true bcrypt-hashes on write; protected=true keeps the hash out of API output.',
                '4. configure_app_login(app, "User", "email", "password") — points the login/session endpoint at your model.',
                '5. Signup controller on the User model (see signup_controller) so an anonymous visitor can create an account.',
                '6. Client pages: signup via anonymous k.post, login via k.sessionCreate, logout via k.sessionDestroy (see get_kytejs_guide).',
            ],
            'signup_controller' =>
                'Bind a controller to the User model, then override new() so anonymous visitors can register. In '
                . 'hook_init set $this->requireAuth = false and $this->allowableActions = ["new"] (ONLY create is '
                . 'public — reads/updates/deletes still require login). In new(), create the user with the PLAINTEXT '
                . 'password (Kyte hashes it via the password=true flag — do NOT hash it yourself or login double-hashes) '
                . 'and set $this->response["data"]. Mirrors KytePasswordResetController. Author with create_controller + '
                . 'create_function("hook_init") + create_function("new") + commit_draft. See get_controller_guide for exact '
                . 'signatures + the account-scoping rules.',
            'client' => [
                'signup' => 'Anonymous create (visitor not logged in yet): k.post("User", {email, password, name}, null, [], onOk, onErr). Works ONLY with auth_mode=jwt + allow_public=2 + the signup controller\'s requireAuth=false.',
                'login'  => 'k.sessionCreate({email, password}, onOk, onErr) — mints a JWT session (cookies; sent as Bearer on later calls).',
                'logout' => 'k.sessionDestroy(function(){ location.href = "/"; }) — ONE completion callback. Or k.addLogoutHandler(selector).',
                'gate'   => 'k.checkSession() returns a boolean — redirect unauthenticated visitors off protected pages.',
            ],
            'public_readonly_recipe' =>
                'DIFFERENT from signup: to expose PUBLIC READ-ONLY data (a catalog/storefront/landing page an '
                . 'anonymous visitor can browse), use allow_public=1 instead of 2. But note the load-bearing rule '
                . 'below — allow_public is only a GATE; it never serves anything by itself. Steps: (1) '
                . 'set_app_auth_mode(app, "jwt") + republish. (2) set_app_anonymous_access(app, 1) — opens the gate '
                . 'AND clamps the anonymous surface to GET at the platform (writes are refused even if a controller '
                . 'allows them). (3) Bind a controller to the model and in hook_init set $this->requireAuth = false '
                . '(scope it, e.g. $this->allowableActions = ["get"]). WITHOUT that controller the model falls to the '
                . 'default ModelController (requireAuth=true) and every anonymous read still returns "Unauthorized API '
                . 'request." (4) Client reads via k.get as usual.',
            'default_closed_rule' =>
                'LOAD-BEARING: Kyte controllers are CLOSED BY DEFAULT. allow_public (1 or 2) only lets an anonymous '
                . 'request REACH a controller — it does not grant access. The model still needs its OWN controller '
                . 'with $this->requireAuth = false, or the default ModelController (requireAuth=true) rejects it. '
                . 'Gate open + no requireAuth=false controller = still 403. This is the single most common reason '
                . '"I enabled anonymous access but it still says Unauthorized".',
            'prerequisites' =>
                'The INSTALL must have KYTE_JWT_SECRET configured for JWT sessions to mint/verify (platform config, '
                . 'not per-app). If login errors even with the recipe correct, verify the install has it.',
            'gotchas' => [
                'allow_public is only a GATE — it never serves anything alone. The target model ALWAYS needs a controller with requireAuth=false (default controllers stay closed → "Unauthorized API request.").',
                'You need BOTH: auth_mode=jwt WITHOUT allow_public=2 → anonymous signup still rejected; allow_public=2 WITHOUT auth_mode=jwt → the client cannot make the anonymous request at all.',
                'Changing auth_mode regenerates the injected `k` bootstrap — REPUBLISH existing pages or they keep booting the old mode.',
                'Do NOT hash the password in signup — the password=true flag hashes it; hashing yourself breaks login (double-hash).',
                'requireAuth=false belongs on the SIGNUP controller only, scoped to allowableActions=["new"] — never open the whole app.',
                '"Unauthorized API request." during signup/login almost always means: auth_mode not jwt, allow_public not 2, or the controller still requireAuth=true.',
            ],
            'verify' => 'read_application(app) returns auth_mode + allow_public — confirm jwt + 2 before debugging anything else.',
            'see_also' => 'Something already failing? Call get_debugging_guide — it decodes the exact error string to a cause + what to check.',
        ];
    }

    /**
     * Decode a failing request/login/signup/build. Maps the framework error
     * strings (verified against SessionManager, ModelController, AppContextStrategy,
     * Api HMAC, and the MCP endpoint) + the common SILENT wrong-answers to a likely
     * cause and exactly what to check. The error strings themselves are terse by
     * design (no server internals leaked); this decoder is for the authenticated
     * builder, it does not change them.
     *
     * @return array<string,mixed>
     */
    #[McpTool(name: 'get_debugging_guide', description: 'Decode a failing Kyte build/login/signup/request: maps the common error messages ("Unauthorized API request.", "Invalid username or password.", the anonymous-access errors, HMAC signature mismatch, /mcp auth errors) AND silent wrong-answers (FK lookups matching nothing, filter-by-0) to their likely cause and exactly what to check. Call this the moment a request, login, signup, or page is not working.')]
    #[RequiresScope('read')]
    public function getDebuggingGuide(): array
    {
        return [
            'how_to_use' => 'Match your EXACT symptom below. The error strings are intentionally terse (they leak no server internals) — decode them here rather than inferring server state from them.',
            'errors' => [
                '"Unauthorized API request." (HTTP 403)' => [
                    'means'  => 'A controller with requireAuth=true rejected the request because there is no valid session (ModelController::authenticate).',
                    'causes' => [
                        'PUBLIC endpoint with NO requireAuth=false controller: the model fell through to the DEFAULT ModelController (requireAuth=true). allow_public only OPENS THE GATE — it never serves a request by itself; the model still needs its own requireAuth=false controller.',
                        'CONTROLLER NAMING (very common, silent): a controller bound to a model must be NAMED EXACTLY the model name. Kyte routes model "User" to class UserController (= {model}Controller). A controller record named "UserController" generates class UserControllerController, which nothing routes to — so your requireAuth=false override is ignored and the default controller answers. Name it "User", not "UserController". Same trap for Task/Step/etc.',
                        'A genuinely protected endpoint hit without logging in, or after the session expired.',
                    ],
                    'check'  => [
                        'list_controllers(app): is there a controller for THIS model, named EXACTLY the model name (not <Model>Controller)?',
                        'read_controller: does hook_init set $this->requireAuth = false (and a scoped allowableActions)?',
                        'read_application: auth_mode=jwt, and allow_public 1 (read) or 2 (writes/signup)?',
                    ],
                ],
                '"Invalid username or password." (login)' => [
                    'means'  => 'SessionManager could not find the user by the configured login field, OR password_verify failed.',
                    'causes' => [
                        'The account was never actually created — signup silently failed (see "Unauthorized API request." above); there is no row to log in as.',
                        'Login points at the wrong model/fields (configure_app_login user_model / username / password).',
                        'The password was double-hashed: the signup controller hashed it AND the password=true flag hashed it again. Do NOT hash in the controller.',
                        'Genuinely wrong credentials.',
                    ],
                    'check'  => [
                        'read_application: user_model + username_field + password_field are the ones you built.',
                        'list_models / query the user model: did signup create the row?',
                        'Signup stores the PLAINTEXT password (the password=true flag hashes it once).',
                    ],
                ],
                '"Anonymous access is not enabled for this application."' => [
                    'means' => 'AppContextStrategy rejected an anonymous (appid-only) request because allow_public=0.',
                    'check' => ['set_app_anonymous_access(app, 1) for public reads or 2 for signup/writes, AND set_app_auth_mode(app, "jwt").'],
                ],
                '"Anonymous access to this application is read-only."' => [
                    'means' => 'allow_public=1 and the request was not a GET (level 1 clamps the anonymous surface to GET at the platform).',
                    'check' => ['For anonymous writes (e.g. signup) use set_app_anonymous_access(app, 2).'],
                ],
                '"Anonymous access requires a valid application context (x-kyte-appid)."' => [
                    'means'  => 'An anonymous request arrived with no x-kyte-appid header.',
                    'causes' => ['A hand-rolled fetch/XHR instead of the injected `k` client — `k` sends x-kyte-appid automatically; raw requests do not.'],
                    'check'  => ['Use k.get/k.post/k.put/k.delete, never raw fetch. See get_kytejs_guide (raw_requests).'],
                ],
                '"Calculated signature does not match provided signature."' => [
                    'means'  => 'HMAC signature mismatch (Api HMAC verification).',
                    'causes' => ['A hand-rolled request in HMAC mode — the signature comes from k.sign() and cannot be reproduced by hand.', 'Wrong/expired keys or clock skew.', 'The app is in HMAC mode when you intended JWT.'],
                    'check'  => ['Use the injected `k`. read_application: is auth_mode what you expect?'],
                ],
                '"No valid session." / "Invalid session." / "Session expired."' => [
                    'means' => 'The session token is missing, malformed, or past expiry (SessionManager).',
                    'check' => ['Log in again via k.sessionCreate; let `k` carry the cookie/bearer (do not hand-manage it). For JWT, confirm the install has KYTE_JWT_SECRET (get_auth_guide prerequisites).'],
                ],
                '"[ERROR] Unable to create controller for model: X."' => [
                    'means' => 'The resolved controller class could not be instantiated — usually a fatal PHP error inside a custom hook/override.',
                    'check' => ['read_controller / read_function on that model: look for a PHP error in a recently committed hook or override.'],
                ],
                '"[ERROR] /mcp requires an Authorization header." / "...MCP bearer token (kmcp_live_...)"' => [
                    'means' => 'The MCP endpoint itself was called without a valid bearer token — the connector is not linked or the token is missing/expired.',
                    'check' => ['Re-link the MCP connector / verify the MCP token. This is a connector-level issue, not an app build issue.'],
                ],
            ],
            'silent_wrong_answers' => [
                'A foreign-key lookup matches nothing / renders "0" with correct data present' => 'A foreign-key column returns the FULL nested object, not a scalar id (loan.asset is the whole Asset row, so loan.asset === 42 is always false). Normalise with fkId() before comparing. See get_kytejs_guide (foreign_keys).',
                'k.get filtered by 0 or "" returns everything' => 'A falsy field value is dropped from the request. Do not filter by a literal 0 or "".',
                'Signup appears to succeed but no user exists / cannot log in' => 'The signup controller was never routed (naming) or requireAuth was still true, so the default controller ran or 403d. Verify the controller NAME + requireAuth=false, then confirm the row was actually created.',
            ],
            'first_moves' => [
                'read_application(app) — auth_mode + allow_public in one call.',
                'list_controllers(app) — confirm the model has a correctly-NAMED requireAuth=false controller.',
                'Do not infer server state from an error string — they are deliberately terse.',
            ],
            'see_also' => 'get_auth_guide (membership + public-read recipes), get_controller_guide (requireAuth + naming), get_kytejs_guide (client calls, required headers, FK expansion).',
        ];
    }

    /** @return array<string,mixed>|null */
    private function appToArray(int $appId): ?array
    {
        $app = new \Kyte\Core\ModelObject(\Application);
        if (!$app->retrieve('id', $appId)) {
            return null;
        }
        return [
            'id'         => (int)$app->id,
            'name'       => isset($app->name) ? (string)$app->name : '',
            'identifier' => isset($app->identifier) ? (string)$app->identifier : '',
            'language'   => isset($app->language) ? (string)$app->language : null,
            'status'     => isset($app->status) ? (string)$app->status : 'active',
            // Built-in login config (configure_app_login). Null user_model means
            // the app has no login wired — the login endpoint won't accept app users.
            'user_model'     => !empty($app->user_model) ? (string)$app->user_model : null,
            'username_field' => !empty($app->username_colname) ? (string)$app->username_colname : null,
            'password_field' => !empty($app->password_colname) ? (string)$app->password_colname : null,
            // Access control. auth_mode = how API requests authenticate (e.g. hmac/jwt).
            // allow_public = anonymous-access level: 0 none, 1 read-only (GET), 2
            // controller-governed (anonymous writes where a controller sets
            // requireAuth=false). Public signup needs level 2. Set via set_app_anonymous_access.
            'auth_mode'    => !empty($app->auth_mode) ? (string)$app->auth_mode : null,
            'allow_public' => isset($app->allow_public) ? (int)$app->allow_public : 0,
        ];
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
