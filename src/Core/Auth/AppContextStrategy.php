<?php
namespace Kyte\Core\Auth;

use Kyte\Core\Api;
use Kyte\Core\ModelObject;
use Kyte\Exception\SessionException;

/**
 * Anonymous application-context strategy — JWT-mode public/anonymous access.
 *
 * Lets a site running in JWT auth mode (endpoint + appId, no embedded HMAC
 * key/secret) serve `requireAuth=false` controllers to ANONYMOUS visitors
 * (no user, before any login) using only the `x-kyte-appid` header — no
 * Bearer, no HMAC identity/signature. This closes the gap where JWT mode
 * could not serve public/catalog browsing the way HMAC mode can.
 *
 * SECURITY INVARIANT (load-bearing): preAuth() resolves the application's
 * ACCOUNT for query scoping but deliberately resolves NO user and NEVER sets
 * `$api->session->hasSession`. ModelController::authenticate() throws unless
 * BOTH `$api->user->id` and `$api->session->hasSession` are set, so every
 * `requireAuth=true` controller keeps returning 403 to anonymous requests.
 * Only controllers that explicitly opt out (`requireAuth=false`) are
 * reachable.
 *
 * Per-app opt-in (tri-state `Application.allow_public`):
 *   0 = off (default) — appid-only requests are rejected in preAuth, so
 *       anonymous access is never silently enabled.
 *   1 = read-only — ModelController restricts the request to GET regardless
 *       of the controller's allowableActions (public catalog/storefront).
 *   2 = controller-governed — the controller's own `requireAuth=false` +
 *       `allowableActions` declaration governs, including writes (e.g.
 *       password reset / signup flows). This is the same contract controller
 *       authors have always written against: under HMAC, anonymous visitors
 *       to a public site could always reach every requireAuth=false action
 *       (the signing endpoint mints anonymous signatures from the embedded
 *       public key alone), so 2 exposes nothing HMAC did not.
 *
 * matches() is STRICT and header-only: it claims a request ONLY when an
 * `x-kyte-appid` is present AND there is no Authorization Bearer, no
 * `x-kyte-signature`, and no `x-kyte-identity`. That is mutually exclusive
 * with every authenticated flow, so it can never shadow HMAC or JWT. Slotted
 * in AuthDispatcher AFTER JwtSessionStrategy and BEFORE HmacSessionStrategy.
 */
class AppContextStrategy implements AuthStrategy
{
    public function name(): string
    {
        return 'app_context';
    }

    /**
     * Claim only genuine anonymous app-only requests: an appid is present and
     * NO authenticated credential (Bearer / HMAC signature / HMAC identity)
     * accompanies it. Pure header inspection, no side effects.
     */
    public function matches(): bool
    {
        if (empty($_SERVER['HTTP_X_KYTE_APPID'])) {
            return false;
        }
        // A Bearer token belongs to McpToken or Jwt; an identity/signature
        // belongs to Hmac. If any is present, this is not an anonymous request.
        if ($this->authorizationHeader() !== null) {
            return false;
        }
        if (!empty($_SERVER['HTTP_X_KYTE_SIGNATURE'])) {
            return false;
        }
        if (!empty($_SERVER['HTTP_X_KYTE_IDENTITY'])) {
            return false;
        }
        return true;
    }

    /**
     * Establish anonymous application context: resolve the account from the
     * already-resolved app (Api::route() resolves $api->app from x-kyte-appid
     * before auth). Resolves NO user and never sets hasSession.
     */
    public function preAuth(Api $api): void
    {
        if ($api->app === null || $api->appId === null) {
            throw new SessionException('Anonymous access requires a valid application context (x-kyte-appid).');
        }

        // Per-app opt-in. Without an explicit Application.allow_public of
        // 1 (read-only) or 2 (controller-governed), an appid-only request is
        // rejected — anonymous access is never implicit. The read-only vs
        // controller-governed distinction is enforced in ModelController.
        $allowPublic = (int)($api->app->allow_public ?? 0);
        if ($allowPublic !== 1 && $allowPublic !== 2) {
            throw new SessionException('Anonymous access is not enabled for this application.');
        }

        // Resolve the account from the application for query scoping only.
        $account = new ModelObject(KyteAccount);
        if (!$account->retrieve('id', (int)$api->app->kyte_account)) {
            throw new SessionException('Unable to resolve account for application.');
        }
        $api->account = $account;

        // Anonymous response markers. NOT setting $api->user->id and NOT
        // touching $api->session->hasSession is the security invariant.
        $api->response['session'] = '0';
        $api->response['uid'] = '0';

        // Language resolution from account/app only (no user).
        $finalLanguage = defined('APP_LANG') ? APP_LANG : 'en';
        if (isset($api->account->default_language) && !empty($api->account->default_language)) {
            $finalLanguage = $api->account->default_language;
        }
        if (isset($api->app->language) && !empty($api->app->language)) {
            $finalLanguage = $api->app->language;
        }
        \Kyte\Util\I18n::setLanguage($finalLanguage);
    }

    /**
     * No signature/token to verify — anonymous app context is fully
     * established in preAuth.
     */
    public function verify(Api $api): void
    {
    }

    /**
     * Read the Authorization header across the SAPI variants (mirrors
     * JwtSessionStrategy / McpTokenStrategy).
     */
    private function authorizationHeader(): ?string
    {
        $candidates = [
            $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
        ];
        foreach ($candidates as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        if (\function_exists('apache_request_headers')) {
            $headers = \apache_request_headers();
            if (is_array($headers)) {
                foreach ($headers as $hname => $value) {
                    if (strcasecmp($hname, 'Authorization') === 0 && is_string($value) && $value !== '') {
                        return $value;
                    }
                }
            }
        }
        return null;
    }
}
