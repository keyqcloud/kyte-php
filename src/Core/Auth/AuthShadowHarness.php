<?php
namespace Kyte\Core\Auth;

use Kyte\Core\Api;
use Kyte\Core\ModelObject;

/**
 * Live shadow-mode harness: re-runs auth via the new AuthDispatcher
 * against a controlled-reset Api state, compares the result to the
 * legacy path's outcome, and logs any discrepancy.
 *
 * Enabled by setting AUTH_STRATEGY_DISPATCHER=shadow. Dev-only.
 *
 * Runs AFTER the legacy path has completed. The legacy result is
 * restored before we return, so the response served to the client is
 * always the legacy one. Shadow never affects the served response.
 *
 * Known side effects during shadow:
 * - SessionManager::validate() runs twice per request (legacy, then new).
 *   Each call may update a session's last-activity timestamp. Acceptable
 *   during the Phase 1 soak on dev; not for production customers.
 * - DB reads for account + api key occur twice per request.
 */
class AuthShadowHarness
{
    /**
     * Compare the new dispatcher's auth result against the legacy result
     * already applied to $api. Restores legacy state on exit.
     *
     * @param array $entryResponse $api->response as it was BEFORE legacy
     *   auth ran — so the new path starts from the same initial state.
     */
    public static function runAndCompare(Api $api, array $entryResponse): void
    {
        $legacyFingerprint = self::fingerprint($api);

        $legacyKey       = $api->key;
        $legacyAccount   = $api->account;
        $legacyUser      = $api->user;
        $legacyResponse  = $api->response;
        $legacySignature = $api->signature;
        $legacyUtcDate   = $api->utcDate;

        // Reset to the state Api::route() leaves us in just before validateRequest.
        $api->key       = new ModelObject(KyteAPIKey);
        $api->account   = new ModelObject(KyteAccount);
        $api->user      = null;
        $api->signature = null;
        $api->utcDate   = null;
        $api->response  = $entryResponse;

        $strategy = null;
        try {
            $strategy = AuthDispatcher::buildDefault()->select();
            if ($strategy !== null) {
                $strategy->preAuth($api);
                $strategy->verify($api);
            }
            $newFingerprint = self::fingerprint($api);

            $diff = self::diff($legacyFingerprint, $newFingerprint);
            if (!empty($diff)) {
                self::logDiff($diff, $strategy);
            }
        } catch (\Throwable $e) {
            self::logException($e, $strategy);
        } finally {
            $api->key       = $legacyKey;
            $api->account   = $legacyAccount;
            $api->user      = $legacyUser;
            $api->response  = $legacyResponse;
            $api->signature = $legacySignature;
            $api->utcDate   = $legacyUtcDate;
        }
    }

    private static function fingerprint(Api $api): array
    {
        return [
            'key_id'     => ($api->key && isset($api->key->id)) ? $api->key->id : null,
            'account_id' => ($api->account && isset($api->account->id)) ? $api->account->id : null,
            'user_id'    => ($api->user && isset($api->user->id)) ? $api->user->id : null,
            'session'    => $api->response['session'] ?? null,
            'token'      => $api->response['token'] ?? null,
            'uid'        => $api->response['uid'] ?? null,
            'name'       => $api->response['name'] ?? null,
            'email'      => $api->response['email'] ?? null,
            'signature'  => $api->signature,
            'utc_epoch'  => $api->utcDate ? $api->utcDate->format('U') : null,
        ];
    }

    private static function diff(array $legacy, array $new): array
    {
        $out = [];
        foreach ($legacy as $k => $v) {
            $n = $new[$k] ?? null;
            if ($v !== $n) {
                $out[$k] = ['legacy' => $v, 'new' => $n];
            }
        }
        return $out;
    }

    private static function logDiff(array $diff, ?AuthStrategy $strategy): void
    {
        try {
            \Kyte\Core\ActivityLogger::getInstance()->log(
                'AUTH_SHADOW_DIFF',
                'AuthShadowHarness',
                'strategy',
                $strategy ? $strategy->name() : 'null',
                null,
                0,
                'shadow',
                json_encode($diff)
            );
        } catch (\Throwable $e) {
            error_log('AuthShadowHarness: failed to log diff - ' . $e->getMessage());
        }
    }

    private static function logException(\Throwable $e, ?AuthStrategy $strategy): void
    {
        try {
            \Kyte\Core\ActivityLogger::getInstance()->log(
                'AUTH_SHADOW_EXCEPTION',
                'AuthShadowHarness',
                'strategy',
                $strategy ? $strategy->name() : 'null',
                null,
                0,
                'shadow',
                get_class($e) . ': ' . $e->getMessage()
            );
        } catch (\Throwable $inner) {
            error_log('AuthShadowHarness: failed to log exception - ' . $inner->getMessage());
        }
    }
}
