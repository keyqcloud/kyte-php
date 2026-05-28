<?php
namespace Kyte\Core\Auth;

use Kyte\Core\Model;
use Kyte\Core\ModelObject;
use Kyte\Exception\SessionException;

/**
 * Issue, rotate, and revoke opaque refresh tokens.
 *
 * Refresh tokens are random bearer secrets (prefix `kref_v1_`) stored
 * only as sha256 hashes. The raw token is returned ONCE at issuance.
 * Single-use rotation: every successful refresh revokes the presented
 * token and issues a new one in the same family. Reuse of a revoked
 * token revokes the entire family (RFC 6819).
 *
 * Multilogon: separate families per login. A user logging in from two
 * devices gets two independent families — revoking one device's tokens
 * does not affect the other.
 *
 * See src/Mvc/Model/KyteRefreshToken.php for the storage spec.
 */
final class RefreshTokenStore
{
    private const PREFIX = 'kref_v1_';

    // Default refresh-token TTL. The token slides forward this many seconds
    // on every successful rotation — it acts as the inactivity timeout. Set
    // to 4h (14400s) so that closing the browser at 5pm forces a re-login
    // the next morning. Override with KYTE_JWT_REFRESH_TTL for deployments
    // that need longer (e.g., consumer mobile apps with "remember me").
    private const DEFAULT_REFRESH_TTL = 14400; // 4 hours

    // Default absolute cap on how long a single rotation family may live
    // before forced re-authentication, regardless of activity. Anchored to
    // `family_started_at`, NOT to the current token's expires_at — so
    // sliding rotation cannot extend it. Override with
    // KYTE_JWT_FAMILY_MAX_LIFETIME. 12h aligns with AWS/Microsoft admin
    // session caps and OWASP ASVS V3 "absolute timeout MUST exist".
    private const DEFAULT_FAMILY_MAX_LIFETIME = 43200; // 12 hours

    /**
     * Issue a brand new refresh token for a user.
     *
     * @return array{raw:string, id:int, family:string, expires_at:int}
     */
    public static function issue(int $userId, int $accountId, ?int $appId, string $ip = ''): array
    {
        $family = bin2hex(random_bytes(32));
        // New family — anchor family_started_at to now. Rotation copies
        // this forward unchanged so the absolute cap remains tied to the
        // original /jwt/login moment.
        return self::issueInFamily($userId, $accountId, $appId, $family, $ip, time());
    }

    /**
     * Rotate a refresh token: validate, revoke, issue successor.
     *
     * Reuse detection: presenting a revoked token triggers family-wide
     * revocation. Expired tokens are marked expired but the family is
     * NOT revoked (expiration is expected behavior, not a leak signal).
     *
     * @return array{raw:string, id:int, family:string, expires_at:int, user_id:int, account_id:int, app_id:?int}
     * @throws SessionException on any validation failure
     */
    public static function rotate(string $rawToken, string $ip = ''): array
    {
        $hash = hash('sha256', $rawToken);

        $token = new ModelObject(KyteRefreshToken);
        if (!$token->retrieve('token_hash', $hash)) {
            throw new SessionException('Refresh token not recognized.');
        }

        // Reuse detection: a revoked token presented again is a leak signal.
        if ((int)($token->revoked_at ?? 0) !== 0) {
            self::revokeFamily((string)$token->token_family, 'reuse_detected');
            throw new SessionException('Refresh token reuse detected; all sessions in this chain have been revoked.');
        }

        // Expiration check (expired-but-not-revoked path).
        $now = time();
        if ((int)$token->expires_at !== 0 && (int)$token->expires_at < $now) {
            $token->save([
                'revoked_at'     => $now,
                'revoked_reason' => 'expired',
            ]);
            throw new SessionException('Refresh token expired.');
        }

        // Absolute family lifetime cap. Anchored to family_started_at —
        // the moment of original /jwt/login — so it cannot be extended
        // by sliding rotation. Once the cap is crossed, revoke the whole
        // family (not just this token) so any concurrent device on the
        // same family is also forced to re-login. Mirrors AWS/Microsoft
        // admin-session absolute caps; satisfies OWASP ASVS V3.
        //
        // Legacy tokens: rows issued before the family_started_at column
        // existed have family_started_at = 0. We do NOT give them a free
        // pass — that let pre-upgrade 7-day sessions survive uncapped for
        // up to a full week after deploy. Instead, anchor the cap to the
        // token's date_created (the best available proxy for family birth).
        // A pre-upgrade session older than the cap is revoked on its next
        // rotation, exactly like a native session would be. Effect: the
        // first deploy of this code logs out any session whose original
        // login was more than KYTE_JWT_FAMILY_MAX_LIFETIME ago.
        $familyStartedAt = (int)($token->family_started_at ?? 0);
        if ($familyStartedAt === 0) {
            $familyStartedAt = (int)($token->date_created ?? $now);
        }
        $familyMaxLifetime = defined('KYTE_JWT_FAMILY_MAX_LIFETIME')
            ? (int)KYTE_JWT_FAMILY_MAX_LIFETIME
            : self::DEFAULT_FAMILY_MAX_LIFETIME;
        if ($now - $familyStartedAt > $familyMaxLifetime) {
            self::revokeFamily((string)$token->token_family, 'family_max_lifetime');
            throw new SessionException('Session has reached its maximum lifetime; please log in again.');
        }

        // Normal rotation: issue successor with same family, then mark
        // current revoked with rotated_to = successor id.
        $userId    = (int)$token->user;
        $accountId = (int)$token->kyte_account;
        $appId     = $token->application !== null ? (int)$token->application : null;
        $family    = (string)$token->token_family;

        $successor = self::issueInFamily($userId, $accountId, $appId, $family, $ip, $familyStartedAt);

        $token->save([
            'revoked_at'     => $now,
            'revoked_reason' => 'rotated',
            'rotated_to'     => $successor['id'],
            'last_used_at'   => $now,
            'last_used_ip'   => $ip !== '' ? $ip : null,
        ]);

        return array_merge($successor, [
            'user_id'    => $userId,
            'account_id' => $accountId,
            'app_id'     => $appId,
        ]);
    }

    /**
     * Revoke a single refresh token. Idempotent — already-revoked tokens
     * are left unchanged. Used by /jwt/logout to invalidate just the
     * presented session.
     */
    public static function revokeByToken(string $rawToken, string $reason): bool
    {
        $hash = hash('sha256', $rawToken);
        $token = new ModelObject(KyteRefreshToken);
        if (!$token->retrieve('token_hash', $hash)) {
            return false;
        }
        if ((int)($token->revoked_at ?? 0) !== 0) {
            return true; // already revoked, idempotent
        }
        $token->save([
            'revoked_at'     => time(),
            'revoked_reason' => $reason,
        ]);
        return true;
    }

    /**
     * Revoke every active token in a family. Returns the count of
     * tokens revoked. Used by reuse detection and by family-level admin
     * revocation.
     */
    public static function revokeFamily(string $family, string $reason): int
    {
        $model = new Model(KyteRefreshToken);
        $model->retrieve('token_family', $family, false, [
            ['field' => 'revoked_at', 'value' => 0],
        ]);
        $now = time();
        $count = 0;
        foreach ($model->objects as $t) {
            $t->save([
                'revoked_at'     => $now,
                'revoked_reason' => $reason,
            ]);
            $count++;
        }
        return $count;
    }

    /**
     * Revoke every active refresh token for a user. Used by /jwt/logout-all
     * to terminate all sessions across all devices.
     */
    public static function revokeAllForUser(int $userId, int $accountId, string $reason = 'logout_all'): int
    {
        $model = new Model(KyteRefreshToken);
        $model->retrieve('user', $userId, false, [
            ['field' => 'kyte_account', 'value' => $accountId],
            ['field' => 'revoked_at',   'value' => 0],
        ]);
        $now = time();
        $count = 0;
        foreach ($model->objects as $t) {
            $t->save([
                'revoked_at'     => $now,
                'revoked_reason' => $reason,
            ]);
            $count++;
        }
        return $count;
    }

    /**
     * Internal: create a refresh token row in a specific family.
     * Used by both issue() (new family) and rotate() (existing family).
     *
     * $familyStartedAt is set once at family birth (issue()) and copied
     * forward unchanged on each rotation. Callers MUST pass it — there
     * is no default — so the absolute-cap anchor cannot accidentally
     * reset to "now" mid-rotation and silently extend the session.
     */
    private static function issueInFamily(int $userId, int $accountId, ?int $appId, string $family, string $ip, int $familyStartedAt): array
    {
        $raw = self::PREFIX . self::randomTokenBody();
        $hash = hash('sha256', $raw);
        $ttl = defined('KYTE_JWT_REFRESH_TTL') ? (int)KYTE_JWT_REFRESH_TTL : self::DEFAULT_REFRESH_TTL;
        $now = time();
        $expiresAt = $now + $ttl;

        $token = new ModelObject(KyteRefreshToken);
        $token->create([
            'token_hash'        => $hash,
            'token_prefix'      => substr($raw, 0, 24),
            'token_family'      => $family,
            'user'              => $userId,
            'application'       => $appId,
            'expires_at'        => $expiresAt,
            'family_started_at' => $familyStartedAt,
            'last_used_at'      => $now,
            'last_used_ip'      => $ip !== '' ? $ip : null,
            'kyte_account'      => $accountId,
        ]);

        return [
            'raw'                => $raw,
            'id'                 => (int)$token->id,
            'family'             => $family,
            'expires_at'         => $expiresAt,
            'family_started_at'  => $familyStartedAt,
        ];
    }

    /**
     * Base64url-encoded 32 random bytes — RFC 4648 §5 alphabet, no
     * padding. URL-safe, unambiguous, ~43 chars.
     */
    private static function randomTokenBody(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
