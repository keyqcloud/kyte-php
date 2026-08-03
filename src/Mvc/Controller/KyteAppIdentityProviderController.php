<?php

namespace Kyte\Mvc\Controller;

/**
 * KyteAppIdentityProvider — per-app SSO (OIDC) provider config (KYTE-#560 P2).
 *
 * Shipyard-managed only (it carries a secret, so it is deliberately NOT exposed
 * via MCP). This controller adds the two things the generic ModelController
 * cannot do for a secret-bearing config:
 *
 *   1. Encrypt `client_secret` at rest on write (libsodium, via
 *      SsoEndpoint::encryptSecret) so the SSO callback can decrypt it server-side
 *      at token exchange. The column is also `protected`, so ModelController
 *      already blanks it in every API response — the plaintext/ciphertext never
 *      leaves the server.
 *
 *   2. Treat the secret as WRITE-ONLY on update: because a `protected` field is
 *      returned blank, the Shipyard edit form has no real value to submit, so an
 *      unchanged save posts an empty `client_secret`. Persisting that would wipe
 *      the stored secret (the #167 protected-field foot-gun). Here an empty/absent
 *      `client_secret` on update is dropped — the existing secret is kept; only a
 *      non-empty value replaces it.
 *
 * Account scoping, auth, and the rest of CRUD come from the base controller.
 */
class KyteAppIdentityProviderController extends ModelController
{
    public function hook_preprocess($method, &$r, &$o = null)
    {
        switch ($method) {
            case 'new':
                // Encrypt a supplied secret; drop an empty one (column is optional).
                if (isset($r['client_secret']) && trim((string)$r['client_secret']) !== '') {
                    $r['client_secret'] = \Kyte\Core\Auth\SsoEndpoint::encryptSecret((string)$r['client_secret']);
                } else {
                    unset($r['client_secret']);
                }
                break;

            case 'update':
                // Write-only: only (re)encrypt when a NEW secret is supplied; an
                // empty/absent value must NOT clear the stored secret.
                if (isset($r['client_secret']) && trim((string)$r['client_secret']) !== '') {
                    $r['client_secret'] = \Kyte\Core\Auth\SsoEndpoint::encryptSecret((string)$r['client_secret']);
                } else {
                    unset($r['client_secret']);
                }
                break;

            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null)
    {
        // The secret is protected (already blanked in $r). Surface a boolean so
        // the config UI can render "secret configured" vs "not set" without ever
        // seeing the value. NOTE: for 'delete', &$r is the autodelete flag (not a
        // response row) — never touch it there.
        if ($method !== 'delete' && is_array($r) && $o !== null) {
            $r['has_client_secret'] = !empty($o->client_secret);
        }
    }
}
