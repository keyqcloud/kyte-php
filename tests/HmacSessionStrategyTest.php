<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

/**
 * Parallel characterization tests for Kyte\Core\Auth\HmacSessionStrategy.
 *
 * Mirrors the cases in SignatureTest.php that cover parseIdentityString and
 * verifySignature, but exercises them via the new strategy surface instead
 * of via reflection on Api's private methods. Together the two files form
 * the Phase 1 contract: HmacSessionStrategy must behave identically to the
 * legacy inline auth code, in every branch.
 *
 * See docs/design/kyte-mcp-and-auth-migration.md Phase 1.
 */
class HmacSessionStrategyTest extends TestCase
{
    private const FIXED_SECRET     = 'test-secret-key-12345';
    private const FIXED_PUBLIC_KEY = 'test-public-key-strat';
    private const FIXED_IDENTIFIER = 'test-identifier-strat';
    private const FIXED_ACCOUNT    = 'test-account-strat';

    /** @var \Kyte\Core\Api */
    private $api;

    /** @var \Kyte\Core\Auth\HmacSessionStrategy */
    private $strategy;

    protected function setUp(): void
    {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');

        $this->api = new \Kyte\Core\Api();
        $this->strategy = new \Kyte\Core\Auth\HmacSessionStrategy();

        \Kyte\Core\DBI::createTable(KyteAPIKey);
        \Kyte\Core\DBI::createTable(KyteAccount);

        \Kyte\Core\DBI::query("DELETE FROM `KyteAPIKey` WHERE public_key = '" . self::FIXED_PUBLIC_KEY . "'");
        \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number = '" . self::FIXED_ACCOUNT . "'");

        $account = new \Kyte\Core\ModelObject(KyteAccount);
        $account->create([
            'name'   => 'HmacStrategy Test Account',
            'number' => self::FIXED_ACCOUNT,
        ]);

        $apiKey = new \Kyte\Core\ModelObject(KyteAPIKey);
        $apiKey->create([
            'identifier'   => self::FIXED_IDENTIFIER,
            'public_key'   => self::FIXED_PUBLIC_KEY,
            'secret_key'   => self::FIXED_SECRET,
            'epoch'        => 0,
            'kyte_account' => $account->id,
        ]);

        // Match the preconditions Api::route() establishes before validateRequest runs.
        $this->api->key = new \Kyte\Core\ModelObject(KyteAPIKey);
        $this->api->account = new \Kyte\Core\ModelObject(KyteAccount);
        $this->api->response = ['session' => '0', 'token' => '0', 'uid' => '0'];

        $_SERVER = [];
    }

    private function identityHeader(string $publicKey, string $session, int $epoch, string $account): string
    {
        $date = gmdate('D, d M Y H:i:s', $epoch) . ' GMT';
        return urlencode(base64_encode($publicKey . '%' . $session . '%' . $date . '%' . $account));
    }

    private function signatureFor(string $token, string $secret, string $identifier, int $epoch): string
    {
        $h1 = hash_hmac('SHA256', $token, $secret, true);
        $h2 = hash_hmac('SHA256', $identifier, $h1, true);
        return hash_hmac('SHA256', (string)$epoch, $h2);
    }

    public function testMatchesReturnsTrueWithSignatureAndIdentityInPrivateMode(): void
    {
        $_SERVER['HTTP_X_KYTE_SIGNATURE'] = 'anything';
        $_SERVER['HTTP_X_KYTE_IDENTITY']  = 'anything';
        $this->assertTrue($this->strategy->matches());
    }

    public function testMatchesReturnsFalseWhenPrivateAndNoSignature(): void
    {
        $_SERVER['HTTP_X_KYTE_IDENTITY'] = 'anything';
        // No HTTP_X_KYTE_SIGNATURE — this is the /sign helper path.
        $this->assertFalse($this->strategy->matches());
    }

    public function testPreAuthAcceptsValidIdentityAndPopulatesState(): void
    {
        $now = time();
        $_SERVER['HTTP_X_KYTE_SIGNATURE'] = 'placeholder';
        $_SERVER['HTTP_X_KYTE_IDENTITY']  = $this->identityHeader(self::FIXED_PUBLIC_KEY, '0', $now, self::FIXED_ACCOUNT);

        $this->strategy->preAuth($this->api);

        $this->assertSame(self::FIXED_PUBLIC_KEY, $this->api->key->public_key);
        $this->assertSame(self::FIXED_ACCOUNT, $this->api->account->number);
        $this->assertSame('0', $this->api->response['session']);
        $this->assertNull($this->api->user, 'Anonymous session should not populate user');
    }

    public function testPreAuthRejectsExpiredTimestamp(): void
    {
        $stale = time() - 7200;
        $_SERVER['HTTP_X_KYTE_SIGNATURE'] = 'placeholder';
        $_SERVER['HTTP_X_KYTE_IDENTITY']  = $this->identityHeader(self::FIXED_PUBLIC_KEY, '0', $stale, self::FIXED_ACCOUNT);

        $this->expectException(\Kyte\Exception\SessionException::class);
        $this->expectExceptionMessage('API request has expired');
        $this->strategy->preAuth($this->api);
    }

    public function testPreAuthAcceptsTimestampAtBoundary(): void
    {
        $boundary = time() - SIGNATURE_TIMEOUT;
        $_SERVER['HTTP_X_KYTE_SIGNATURE'] = 'placeholder';
        $_SERVER['HTTP_X_KYTE_IDENTITY']  = $this->identityHeader(self::FIXED_PUBLIC_KEY, '0', $boundary, self::FIXED_ACCOUNT);

        $this->strategy->preAuth($this->api);
        $this->addToAssertionCount(1);
    }

    public function testPreAuthRejectsTimestampOneSecondPastBoundary(): void
    {
        $stale = time() - SIGNATURE_TIMEOUT - 1;
        $_SERVER['HTTP_X_KYTE_SIGNATURE'] = 'placeholder';
        $_SERVER['HTTP_X_KYTE_IDENTITY']  = $this->identityHeader(self::FIXED_PUBLIC_KEY, '0', $stale, self::FIXED_ACCOUNT);

        $this->expectException(\Kyte\Exception\SessionException::class);
        $this->expectExceptionMessage('API request has expired');
        $this->strategy->preAuth($this->api);
    }

    public function testPreAuthRejectsMalformedIdentity(): void
    {
        $_SERVER['HTTP_X_KYTE_SIGNATURE'] = 'placeholder';
        $_SERVER['HTTP_X_KYTE_IDENTITY']  = urlencode(base64_encode('only%three%parts'));

        $this->expectException(\Kyte\Exception\SessionException::class);
        $this->expectExceptionMessage('Invalid identity string');
        $this->strategy->preAuth($this->api);
    }

    public function testPreAuthRejectsUnknownApiKey(): void
    {
        $now = time();
        $_SERVER['HTTP_X_KYTE_SIGNATURE'] = 'placeholder';
        $_SERVER['HTTP_X_KYTE_IDENTITY']  = $this->identityHeader('nonexistent-key', '0', $now, self::FIXED_ACCOUNT);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('API key not found');
        $this->strategy->preAuth($this->api);
    }

    public function testPreAuthRejectsUnknownAccount(): void
    {
        $now = time();
        $_SERVER['HTTP_X_KYTE_SIGNATURE'] = 'placeholder';
        $_SERVER['HTTP_X_KYTE_IDENTITY']  = $this->identityHeader(self::FIXED_PUBLIC_KEY, '0', $now, 'nonexistent-account');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unable to find account');
        $this->strategy->preAuth($this->api);
    }

    public function testPreAuthTreatsUndefinedSessionAsZero(): void
    {
        $now = time();
        $date = gmdate('D, d M Y H:i:s', $now) . ' GMT';
        $_SERVER['HTTP_X_KYTE_SIGNATURE'] = 'placeholder';
        $_SERVER['HTTP_X_KYTE_IDENTITY']  = urlencode(base64_encode(
            self::FIXED_PUBLIC_KEY . '%undefined%' . $date . '%' . self::FIXED_ACCOUNT
        ));

        $this->strategy->preAuth($this->api);
        $this->assertSame('0', $this->api->response['session']);
    }

    public function testVerifyAcceptsCorrectSignature(): void
    {
        $now = time();
        $_SERVER['HTTP_X_KYTE_IDENTITY']  = $this->identityHeader(self::FIXED_PUBLIC_KEY, '0', $now, self::FIXED_ACCOUNT);
        $_SERVER['HTTP_X_KYTE_SIGNATURE'] = $this->signatureFor('0', self::FIXED_SECRET, self::FIXED_IDENTIFIER, $now);

        $this->strategy->preAuth($this->api);
        $this->strategy->verify($this->api);
        $this->addToAssertionCount(1);
    }

    public function testVerifyRejectsWrongSignature(): void
    {
        $now = time();
        $_SERVER['HTTP_X_KYTE_IDENTITY']  = $this->identityHeader(self::FIXED_PUBLIC_KEY, '0', $now, self::FIXED_ACCOUNT);
        $_SERVER['HTTP_X_KYTE_SIGNATURE'] = str_repeat('0', 64);

        $this->strategy->preAuth($this->api);

        $this->expectException(\Kyte\Exception\SessionException::class);
        $this->strategy->verify($this->api);
    }
}
