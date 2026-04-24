<?php
namespace Kyte\Core\Auth;

use Kyte\Core\Api;
use Kyte\Exception\SessionException;

/**
 * Wraps the legacy three-layer HMAC-SHA256 auth flow currently inlined
 * in Api::parseIdentityString() and Api::verifySignature().
 *
 * Behavior must be bit-for-bit identical to those methods; Phase 0
 * characterization tests in tests/SignatureTest.php are the contract.
 *
 * Flavors (both handled here):
 *   - IS_PRIVATE=true  — signature + identity required
 *   - IS_PRIVATE=false — identity-only (no signature verification)
 */
class HmacSessionStrategy implements AuthStrategy
{
    public function name(): string
    {
        return 'hmac_session';
    }

    /**
     * Matches every request EXCEPT the IS_PRIVATE + missing-signature case,
     * which is the /sign helper path and falls through to Api::generateSignature.
     *
     * A missing identity header is NOT handled here — it's left for preAuth()
     * to throw "Invalid identity string" as the legacy code does. matches()
     * only decides "do we attempt auth at all"; preAuth decides "does the
     * request's auth payload make sense". This split mirrors
     * Api::validateRequest's original return-false vs. throw branches exactly.
     */
    public function matches(): bool
    {
        if (IS_PRIVATE && empty($_SERVER['HTTP_X_KYTE_SIGNATURE'])) {
            return false;
        }
        return true;
    }

    /**
     * Mirrors Api::parseIdentityString. Populates key, account, session,
     * user, and the identity-related response slots.
     */
    public function preAuth(Api $api): void
    {
        if (IS_PRIVATE) {
            $api->signature = $_SERVER['HTTP_X_KYTE_SIGNATURE'] ?? null;
        }

        $raw = $_SERVER['HTTP_X_KYTE_IDENTITY'] ?? null;
        $identity = explode('%', base64_decode(urldecode($raw)));

        if (count($identity) != 4) {
            throw new SessionException("[ERROR] Invalid identity string: {$api->request}.");
        }

        $api->utcDate = new \DateTime($identity[2], new \DateTimeZone('UTC'));

        if (time() > $api->utcDate->format('U') + SIGNATURE_TIMEOUT) {
            throw new SessionException("API request has expired.");
        }

        if (!isset($identity[0])) {
            throw new \Exception("API key is required.");
        }

        if (!$api->key->retrieve('public_key', $identity[0])) {
            throw new \Exception("API key not found.");
        }

        if (!$api->account->retrieve('number', $identity[3])) {
            throw new \Exception("[ERROR] Unable to find account for {$identity[3]}.");
        }

        $identity[1] = $identity[1] == 'undefined' ? "0" : $identity[1];
        $api->response['session'] = $identity[1];

        if ($identity[1] != "0") {
            $session_ret = $api->session->validate($identity[1]);
            $api->response['session'] = $session_ret['session']->sessionToken;
            $api->response['token'] = $session_ret['session']->txToken;

            $api->user = $session_ret['user'];
            $api->response['uid'] = $api->user->id;
            $api->response['name'] = $api->user->name;
            $api->response['email'] = $api->user->email;

            if ($api->appId === null && $api->user->kyte_account != $api->account->id) {
                if (!$api->account->retrieve('id', $api->user->kyte_account)) {
                    throw new \Exception("Unable to find account associated with the user");
                }
            }

            $finalLanguage = APP_LANG;
            if ($api->account && isset($api->account->default_language) && !empty($api->account->default_language)) {
                $finalLanguage = $api->account->default_language;
            }
            if ($api->app && isset($api->app->language) && !empty($api->app->language)) {
                $finalLanguage = $api->app->language;
            }
            if ($api->user && isset($api->user->language) && !empty($api->user->language)) {
                $finalLanguage = $api->user->language;
            }
            \Kyte\Util\I18n::setLanguage($finalLanguage);
        }
    }

    /**
     * Mirrors Api::verifySignature. No-op in IS_PRIVATE=false mode.
     */
    public function verify(Api $api): void
    {
        if (!IS_PRIVATE) {
            return;
        }

        $token = $api->response['token'];
        $secretKey = $api->key->secret_key;
        $identifier = $api->key->identifier;

        $hash1 = hash_hmac('SHA256', $token, $secretKey, true);
        $hash1str = hash_hmac('SHA256', $token, $secretKey, false);

        if (VERBOSE_LOG > 0) {
            error_log("hash1 " . hash_hmac('SHA256', $token, $secretKey));
        }

        $hash2 = hash_hmac('SHA256', $identifier, $hash1, true);
        $hash2str = hash_hmac('SHA256', $identifier, $hash1, false);

        if (VERBOSE_LOG > 0) {
            error_log("hash2 " . hash_hmac('SHA256', $identifier, $hash1));
        }

        $calculated = hash_hmac('SHA256', $api->utcDate->format('U'), $hash2);

        if (VERBOSE_LOG > 0) {
            error_log("hash3 $calculated");
            error_log("epoch " . $api->utcDate->format('U'));
        }

        if ($calculated != $api->signature) {
            throw new SessionException(
                "Calculated signature does not match provided signature.\n" .
                "Calculated: $hash1str $hash2str $calculated\n" .
                "Provided: " . $api->signature
            );
        }
    }
}
