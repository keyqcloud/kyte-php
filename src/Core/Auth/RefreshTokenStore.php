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

    private const DEFAULT_REFRESH_TTL = 604800; // 7 days

    /**
     * Issue a brand new refresh token for a user.
     *
     * @return array{raw:string, id:int, family:string, expires_at:int}
     */
    public static function issue(int $userId, int $accountId, ?int $appId, string $ip = ''): array
    {
        $family = bin2hex(random_bytes(32));
        return self::issueInFamily($userId, $accountId, $appId, $family, $ip);
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

        // Normal rotation: issue successor with same family, then mark
        // current revoked with rotated_to = successor id.
        $userId    = (int)$token->user;
        $accountId = (int)$token->kyte_account;
        $appId     = $token->application !== null ? (int)$token->application : null;
        $family    = (string)$token->token_family;

        $successor = self::issueInFamily($userId, $accountId, $appId, $family, $ip);

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
     */
    private static function issueInFamily(int $userId, int $accountId, ?int $appId, string $family, string $ip): array
    {
        $raw = self::PREFIX . self::randomTokenBody();
        $hash = hash('sha256', $raw);
        $ttl = defined('KYTE_JWT_REFRESH_TTL') ? (int)KYTE_JWT_REFRESH_TTL : self::DEFAULT_REFRESH_TTL;
        $now = time();
        $expiresAt = $now + $ttl;

        $token = new ModelObject(KyteRefreshToken);
        $token->create([
            'token_hash'    => $hash,
            'token_prefix'  => substr($raw, 0, 24),
            'token_family'  => $family,
            'user'          => $userId,
            'application'   => $appId,
            'expires_at'    => $expiresAt,
            'last_used_at'  => $now,
            'last_used_ip'  => $ip !== '' ? $ip : null,
            'kyte_account'  => $accountId,
        ]);

        return [
            'raw'        => $raw,
            'id'         => (int)$token->id,
            'family'     => $family,
            'expires_at' => $expiresAt,
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
