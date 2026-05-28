<?php
namespace Kyte\Test;

use Kyte\Core\Api;
use Kyte\Core\Auth\RefreshTokenStore;
use Kyte\Exception\SessionException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the refresh token rotation + revocation logic.
 *
 * Matrix:
 *   - issue() returns raw + id + family + expires_at
 *   - issue() creates a row with the expected hash
 *   - rotate() returns a new raw + revokes the original + sets rotated_to
 *   - rotate() with revoked input triggers FAMILY-WIDE revocation (reuse detection)
 *   - rotate() with expired token throws + marks expired (no family kill)
 *   - rotate() with unknown token throws
 *   - revokeByToken() marks one token revoked; idempotent on re-call
 *   - revokeFamily() revokes all active tokens in a family
 *   - revokeAllForUser() spans multiple families (logout-all UX)
 *   - Separate logins → separate families (multilogon UX)
 */
class RefreshTokenStoreTest extends TestCase
{
    private const ACCOUNT = 'jwt-refresh-test';

    private Api $api;
    private int $accountId;
    private int $userId;
    private int $otherUserId;

    protected function setUp(): void
    {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');

        $this->api = new Api();

        \Kyte\Core\DBI::createTable(KyteAccount);
        \Kyte\Core\DBI::createTable(KyteUser);
        \Kyte\Core\DBI::createTable(KyteRefreshToken);

        \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number = '" . self::ACCOUNT . "'");
        \Kyte\Core\DBI::query("DELETE FROM `KyteUser` WHERE email IN ('jwt-refresh-test@example.com', 'jwt-refresh-test-other@example.com')");
        \Kyte\Core\DBI::query("DELETE FROM `KyteRefreshToken` WHERE token_prefix LIKE 'kref_v1_%'");

        $acct = new \Kyte\Core\ModelObject(KyteAccount);
        $acct->create(['number' => self::ACCOUNT, 'name' => 'Refresh Store Test']);
        $this->accountId = (int)$acct->id;

        $user = new \Kyte\Core\ModelObject(KyteUser);
        $user->create([
            'name'         => 'Refresh Test User',
            'email'        => 'jwt-refresh-test@example.com',
            'password'     => password_hash('placeholder', PASSWORD_DEFAULT),
            'kyte_account' => $this->accountId,
        ]);
        $this->userId = (int)$user->id;

        $other = new \Kyte\Core\ModelObject(KyteUser);
        $other->create([
            'name'         => 'Other Refresh Test User',
            'email'        => 'jwt-refresh-test-other@example.com',
            'password'     => password_hash('placeholder', PASSWORD_DEFAULT),
            'kyte_account' => $this->accountId,
        ]);
        $this->otherUserId = (int)$other->id;
    }

    public function testIssueReturnsRawAndPersistsHashedRow(): void
    {
        $before = time();
        $result = RefreshTokenStore::issue($this->userId, $this->accountId, null, '1.2.3.4');

        $this->assertArrayHasKey('raw', $result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('family', $result);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertArrayHasKey('family_started_at', $result);
        $this->assertStringStartsWith('kref_v1_', $result['raw']);
        $this->assertSame(64, strlen($result['family']), 'family is 64 hex chars');

        $row = $this->loadById($result['id']);
        $this->assertSame(hash('sha256', $result['raw']), $row['token_hash']);
        $this->assertSame(0, (int)$row['revoked_at']);
        $this->assertSame((string)$this->userId, $row['user']);
        $this->assertGreaterThan(time(), (int)$row['expires_at']);
        $this->assertGreaterThanOrEqual($before, (int)$row['family_started_at']);
        $this->assertLessThanOrEqual(time(), (int)$row['family_started_at']);
    }

    public function testRotateRevokesOldAndIssuesSuccessorInSameFamily(): void
    {
        $original = RefreshTokenStore::issue($this->userId, $this->accountId, null);
        $successor = RefreshTokenStore::rotate($original['raw'], '5.6.7.8');

        $this->assertNotSame($original['raw'], $successor['raw']);
        $this->assertSame($original['family'], $successor['family'], 'family preserved across rotation');

        $oldRow = $this->loadById($original['id']);
        $this->assertNotSame(0, (int)$oldRow['revoked_at'], 'original token revoked');
        $this->assertSame('rotated', $oldRow['revoked_reason']);
        $this->assertSame((string)$successor['id'], $oldRow['rotated_to'], 'rotation chain recorded');
        $this->assertSame('5.6.7.8', $oldRow['last_used_ip']);
    }

    public function testRotateOnRevokedTokenRevokesEntireFamily(): void
    {
        // Issue, rotate once (revokes original, issues successor).
        $original = RefreshTokenStore::issue($this->userId, $this->accountId, null);
        $successor = RefreshTokenStore::rotate($original['raw']);

        // Present the original AGAIN — that's reuse. Family revocation expected.
        try {
            RefreshTokenStore::rotate($original['raw']);
            $this->fail('Reused refresh token should have thrown.');
        } catch (SessionException $e) {
            $this->assertStringContainsString('reuse detected', strtolower($e->getMessage()));
        }

        // Successor should now also be revoked with reason='reuse_detected'.
        $successorRow = $this->loadById($successor['id']);
        $this->assertNotSame(0, (int)$successorRow['revoked_at']);
        $this->assertSame('reuse_detected', $successorRow['revoked_reason']);
    }

    public function testRotationPreservesFamilyStartedAt(): void
    {
        $original = RefreshTokenStore::issue($this->userId, $this->accountId, null);
        $originalRow = $this->loadById($original['id']);
        $originalAnchor = (int)$originalRow['family_started_at'];
        $this->assertGreaterThan(0, $originalAnchor);

        // Sleep would slow tests; instead force-shift expires_at so rotate()
        // is willing to fire even if we run two rotations in the same second.
        $successor = RefreshTokenStore::rotate($original['raw']);
        $successorRow = $this->loadById($successor['id']);

        $this->assertSame($originalAnchor, (int)$successorRow['family_started_at'],
            'family_started_at must be copied forward, not reset to now');
    }

    public function testRotateRejectsWhenFamilyMaxLifetimeExceeded(): void
    {
        $token = RefreshTokenStore::issue($this->userId, $this->accountId, null);

        // Force family_started_at far enough in the past that the default
        // 12h cap is blown. Keep expires_at fresh so we hit the cap check,
        // not the per-token expiration check.
        $longAgo = time() - 86400; // 24h ago
        \Kyte\Core\DBI::query(
            "UPDATE `KyteRefreshToken` SET family_started_at = " . $longAgo . " WHERE id = " . (int)$token['id']
        );

        try {
            RefreshTokenStore::rotate($token['raw']);
            $this->fail('Rotation past family_max_lifetime should have thrown.');
        } catch (SessionException $e) {
            $this->assertStringContainsString('maximum lifetime', strtolower($e->getMessage()));
        }

        $row = $this->loadById($token['id']);
        $this->assertSame('family_max_lifetime', $row['revoked_reason'],
            'whole family revoked with family_max_lifetime reason');
    }

    public function testRotateAllowsRefreshBeforeFamilyMaxLifetime(): void
    {
        $token = RefreshTokenStore::issue($this->userId, $this->accountId, null);

        // Set family_started_at to just inside the 12h window — refresh
        // should still succeed.
        $oneHourAgo = time() - 3600;
        \Kyte\Core\DBI::query(
            "UPDATE `KyteRefreshToken` SET family_started_at = " . $oneHourAgo . " WHERE id = " . (int)$token['id']
        );

        $successor = RefreshTokenStore::rotate($token['raw']);
        $this->assertNotSame($token['raw'], $successor['raw']);

        $successorRow = $this->loadById($successor['id']);
        $this->assertSame($oneHourAgo, (int)$successorRow['family_started_at'],
            'family_started_at anchor preserved across allowed rotations');
    }

    public function testLegacyTokenWithinCapAnchorsToDateCreated(): void
    {
        $token = RefreshTokenStore::issue($this->userId, $this->accountId, null);

        // Simulate a pre-upgrade token: family_started_at = 0, but
        // date_created is recent (within the 12h cap). Should rotate
        // successfully and the successor inherits the date_created anchor
        // (NOT now) so the absolute clock counts from the legacy login.
        $createdAt = time() - 3600; // 1h ago — inside the cap
        \Kyte\Core\DBI::query(
            "UPDATE `KyteRefreshToken` SET family_started_at = 0, date_created = " . $createdAt . " WHERE id = " . (int)$token['id']
        );

        $successor = RefreshTokenStore::rotate($token['raw']);
        $successorRow = $this->loadById($successor['id']);

        $this->assertSame($createdAt, (int)$successorRow['family_started_at'],
            'legacy token anchors the cap to date_created, not to now');
    }

    public function testLegacyTokenPastCapIsRevoked(): void
    {
        $token = RefreshTokenStore::issue($this->userId, $this->accountId, null);

        // Pre-upgrade token whose original login (date_created) is older
        // than the 12h cap. Even though family_started_at = 0 (legacy),
        // it must NOT get a free pass — it's revoked on next rotation.
        // This is the bug that let week-old sessions survive on dev.
        $longAgo = time() - 86400; // 24h ago
        \Kyte\Core\DBI::query(
            "UPDATE `KyteRefreshToken` SET family_started_at = 0, date_created = " . $longAgo . " WHERE id = " . (int)$token['id']
        );

        try {
            RefreshTokenStore::rotate($token['raw']);
            $this->fail('Legacy token older than the cap should have thrown.');
        } catch (SessionException $e) {
            $this->assertStringContainsString('maximum lifetime', strtolower($e->getMessage()));
        }

        $row = $this->loadById($token['id']);
        $this->assertSame('family_max_lifetime', $row['revoked_reason'],
            'legacy token past the cap revoked with family_max_lifetime reason');
    }

    public function testRotateOnExpiredTokenMarksExpiredAndDoesNotKillFamily(): void
    {
        $token = RefreshTokenStore::issue($this->userId, $this->accountId, null);

        // Force expiry in the DB.
        \Kyte\Core\DBI::query(
            "UPDATE `KyteRefreshToken` SET expires_at = " . (time() - 3600) . " WHERE id = " . (int)$token['id']
        );

        try {
            RefreshTokenStore::rotate($token['raw']);
            $this->fail('Expired token should have thrown.');
        } catch (SessionException $e) {
            $this->assertStringContainsString('expired', strtolower($e->getMessage()));
        }

        $row = $this->loadById($token['id']);
        $this->assertSame('expired', $row['revoked_reason'], 'expired tokens marked with expired reason, NOT reuse_detected');
    }

    public function testRotateOnUnknownTokenThrows(): void
    {
        $this->expectException(SessionException::class);
        RefreshTokenStore::rotate('kref_v1_definitely-not-a-real-token');
    }

    public function testRevokeByTokenIsIdempotent(): void
    {
        $token = RefreshTokenStore::issue($this->userId, $this->accountId, null);

        $this->assertTrue(RefreshTokenStore::revokeByToken($token['raw'], 'logout'));
        $row1 = $this->loadById($token['id']);
        $this->assertNotSame(0, (int)$row1['revoked_at']);
        $firstRevokedAt = (int)$row1['revoked_at'];

        // Second call: no-op, returns true (idempotent).
        $this->assertTrue(RefreshTokenStore::revokeByToken($token['raw'], 'logout'));
        $row2 = $this->loadById($token['id']);
        $this->assertSame($firstRevokedAt, (int)$row2['revoked_at'], 're-revoke does not change timestamp');
    }

    public function testRevokeFamilyHitsAllActiveInFamily(): void
    {
        // Issue + rotate twice in same family.
        $a = RefreshTokenStore::issue($this->userId, $this->accountId, null);
        $b = RefreshTokenStore::rotate($a['raw']);
        $c = RefreshTokenStore::rotate($b['raw']);
        // After two rotations: a and b are revoked (rotated), c is active.

        $count = RefreshTokenStore::revokeFamily($a['family'], 'admin_revoke');

        // Only c was active and should have been revoked here.
        $this->assertSame(1, $count, 'only the active token in the family gets newly-revoked');
        $cRow = $this->loadById($c['id']);
        $this->assertSame('admin_revoke', $cRow['revoked_reason']);
    }

    public function testRevokeAllForUserSpansFamilies(): void
    {
        // Two separate logins for the same user → two families.
        $login1 = RefreshTokenStore::issue($this->userId, $this->accountId, null);
        $login2 = RefreshTokenStore::issue($this->userId, $this->accountId, null);
        $this->assertNotSame($login1['family'], $login2['family'], 'separate logins get separate families');

        // A login for a DIFFERENT user — must not be affected.
        $otherLogin = RefreshTokenStore::issue($this->otherUserId, $this->accountId, null);

        $count = RefreshTokenStore::revokeAllForUser($this->userId, $this->accountId);
        $this->assertSame(2, $count, 'both of this user\'s active tokens revoked');

        $otherRow = $this->loadById($otherLogin['id']);
        $this->assertSame(0, (int)$otherRow['revoked_at'], 'other user\'s token untouched');
    }

    public function testMultipleLoginsGetSeparateFamilies(): void
    {
        $login1 = RefreshTokenStore::issue($this->userId, $this->accountId, null);
        $login2 = RefreshTokenStore::issue($this->userId, $this->accountId, null);

        $this->assertNotSame($login1['family'], $login2['family']);

        // Revoking one family should not touch the other.
        RefreshTokenStore::revokeFamily($login1['family'], 'logout');
        $row2 = $this->loadById($login2['id']);
        $this->assertSame(0, (int)$row2['revoked_at']);
    }

    private function loadById(int $id): array
    {
        $rows = \Kyte\Core\DBI::query("SELECT * FROM `KyteRefreshToken` WHERE id = " . $id);
        $this->assertNotEmpty($rows, "expected refresh token row id=$id");
        return $rows[0];
    }
}
