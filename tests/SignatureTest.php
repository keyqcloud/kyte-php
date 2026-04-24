<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for the HMAC-SHA256 signature algorithm used by
 * the current auth path in Kyte\Core\Api.
 *
 * These tests lock in the current behavior so that the Phase 1 middleware
 * refactor (strategy dispatcher) can proceed without changing observable
 * auth semantics. If any of these tests fail after a refactor, something
 * customer-facing has changed.
 *
 * See docs/design/kyte-mcp-and-auth-migration.md Phase 0.
 */
class SignatureTest extends TestCase
{
    private const FIXED_SECRET     = 'test-secret-key-12345';
    private const FIXED_PUBLIC_KEY = 'test-public-key';
    private const FIXED_IDENTIFIER = 'test-identifier';
    private const FIXED_ACCOUNT    = 'test-account-42';
    private const FIXED_TOKEN      = '0';
    private const FIXED_EPOCH      = '1712500000';

    private $api;

    protected function setUp(): void {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');

        $this->api = new \Kyte\Core\Api();

        \Kyte\Core\DBI::createTable(KyteAPIKey);
        \Kyte\Core\DBI::createTable(KyteAccount);

        \Kyte\Core\DBI::query("DELETE FROM `KyteAPIKey` WHERE public_key = '" . self::FIXED_PUBLIC_KEY . "'");
        \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number = '" . self::FIXED_ACCOUNT . "'");

        $account = new \Kyte\Core\ModelObject(KyteAccount);
        $account->create([
            'name' => 'SignatureTest Account',
            'number' => self::FIXED_ACCOUNT,
        ]);

        $apiKey = new \Kyte\Core\ModelObject(KyteAPIKey);
        $apiKey->create([
            'identifier' => self::FIXED_IDENTIFIER,
            'public_key' => self::FIXED_PUBLIC_KEY,
            'secret_key' => self::FIXED_SECRET,
            'epoch'      => 0,
            'kyte_account' => $account->id,
        ]);
    }

    /**
     * The algorithm itself. Three layers of HMAC-SHA256:
     *   hash1 = HMAC(token, secret_key)
     *   hash2 = HMAC(identifier, hash1)
     *   sig   = HMAC(unix_epoch_string, hash2)
     *
     * If this test fails, the crypto primitive has changed.
     */
    public function testSignatureAlgorithmIsThreeLayerHmacSha256() {
        $hash1 = hash_hmac('SHA256', self::FIXED_TOKEN, self::FIXED_SECRET, true);
        $hash2 = hash_hmac('SHA256', self::FIXED_IDENTIFIER, $hash1, true);
        $signature = hash_hmac('SHA256', self::FIXED_EPOCH, $hash2);

        $this->assertSame(64, strlen($signature), 'Signature should be 64-char hex (SHA-256)');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature);

        $recomputed = hash_hmac(
            'SHA256',
            self::FIXED_EPOCH,
            hash_hmac('SHA256', self::FIXED_IDENTIFIER, hash_hmac('SHA256', self::FIXED_TOKEN, self::FIXED_SECRET, true), true)
        );
        $this->assertSame($signature, $recomputed, 'Algorithm must be deterministic');
    }

    /**
     * Api::generateSignature (the flow-A step 1 endpoint) produces a
     * signature that matches the three-layer HMAC algorithm.
     *
     * Uses reflection to invoke the private method and inspect state.
     */
    public function testGenerateSignatureProducesExpectedValue() {
        $ref = new \ReflectionClass(\Kyte\Core\Api::class);

        $keyProp = $ref->getProperty('key');
        $keyProp->setAccessible(true);
        $key = new \Kyte\Core\ModelObject(KyteAPIKey);
        $key->retrieve('public_key', self::FIXED_PUBLIC_KEY);
        $keyProp->setValue($this->api, $key);

        $requestProp = $ref->getProperty('request');
        $requestProp->setAccessible(true);
        $requestProp->setValue($this->api, 'POST');

        $dataProp = $ref->getProperty('data');
        $dataProp->setAccessible(true);
        $dataProp->setValue($this->api, [
            'key'        => self::FIXED_PUBLIC_KEY,
            'identifier' => self::FIXED_IDENTIFIER,
            'token'      => self::FIXED_TOKEN,
            'time'       => gmdate('D, d M Y H:i:s', (int) self::FIXED_EPOCH) . ' GMT',
        ]);

        $responseProp = $ref->getProperty('response');
        $responseProp->setAccessible(true);
        $responseProp->setValue($this->api, []);

        $method = $ref->getMethod('generateSignature');
        $method->setAccessible(true);
        $method->invoke($this->api);

        $response = $responseProp->getValue($this->api);

        $hash1 = hash_hmac('SHA256', self::FIXED_TOKEN, self::FIXED_SECRET, true);
        $hash2 = hash_hmac('SHA256', self::FIXED_IDENTIFIER, $hash1, true);
        $expected = hash_hmac('SHA256', self::FIXED_EPOCH, $hash2);

        $this->assertArrayHasKey('signature', $response);
        $this->assertSame($expected, $response['signature']);
    }

    /**
     * Api::verifySignature accepts a correctly computed signature and
     * raises no exception. Also asserts the inverse: a wrong signature
     * throws SessionException.
     */
    public function testVerifySignatureAcceptsCorrectAndRejectsWrong() {
        $ref = new \ReflectionClass(\Kyte\Core\Api::class);

        $key = new \Kyte\Core\ModelObject(KyteAPIKey);
        $key->retrieve('public_key', self::FIXED_PUBLIC_KEY);
        $keyProp = $ref->getProperty('key');
        $keyProp->setAccessible(true);
        $keyProp->setValue($this->api, $key);

        $responseProp = $ref->getProperty('response');
        $responseProp->setAccessible(true);
        $responseProp->setValue($this->api, ['token' => self::FIXED_TOKEN]);

        $utcDate = new \DateTime('@' . self::FIXED_EPOCH, new \DateTimeZone('UTC'));
        $utcProp = $ref->getProperty('utcDate');
        $utcProp->setAccessible(true);
        $utcProp->setValue($this->api, $utcDate);

        $hash1 = hash_hmac('SHA256', self::FIXED_TOKEN, self::FIXED_SECRET, true);
        $hash2 = hash_hmac('SHA256', self::FIXED_IDENTIFIER, $hash1, true);
        $correctSignature = hash_hmac('SHA256', self::FIXED_EPOCH, $hash2);

        $sigProp = $ref->getProperty('signature');
        $sigProp->setAccessible(true);
        $sigProp->setValue($this->api, $correctSignature);

        $verify = $ref->getMethod('verifySignature');
        $verify->setAccessible(true);

        $verify->invoke($this->api);
        $this->addToAssertionCount(1);

        $sigProp->setValue($this->api, str_repeat('0', 64));

        $this->expectException(\Kyte\Exception\SessionException::class);
        $verify->invoke($this->api);
    }

    /**
     * parseIdentityString rejects an expired timestamp (well past the boundary).
     */
    public function testParseIdentityStringRejectsExpiredTimestamp() {
        $staleDate = gmdate('D, d M Y H:i:s', time() - 7200) . ' GMT';
        $identity = base64_encode(self::FIXED_PUBLIC_KEY . '%0%' . $staleDate . '%' . self::FIXED_ACCOUNT);

        $this->invokeParseIdentity($identity, \Kyte\Exception\SessionException::class, 'API request has expired');
    }

    /**
     * parseIdentityString rejects a string that doesn't split into
     * exactly four parts on '%'.
     */
    public function testParseIdentityStringRejectsMalformedString() {
        $this->invokeParseIdentity(base64_encode('only%three%parts'), \Kyte\Exception\SessionException::class, 'Invalid identity string');
    }

    /**
     * parseIdentityString accepts a timestamp that is exactly at the
     * SIGNATURE_TIMEOUT boundary (inclusive). Uses time() - SIGNATURE_TIMEOUT.
     *
     * Locks in the current boundary semantics: the check is `time() > utcDate + SIGNATURE_TIMEOUT`,
     * so `time() == utcDate + SIGNATURE_TIMEOUT` must pass.
     */
    public function testParseIdentityStringAcceptsTimestampAtBoundary() {
        $boundaryEpoch = time() - SIGNATURE_TIMEOUT;
        $boundaryDate = gmdate('D, d M Y H:i:s', $boundaryEpoch) . ' GMT';
        $identity = base64_encode(self::FIXED_PUBLIC_KEY . '%0%' . $boundaryDate . '%' . self::FIXED_ACCOUNT);

        $this->invokeParseIdentity($identity);
        $this->addToAssertionCount(1);
    }

    /**
     * parseIdentityString rejects a timestamp one second past the
     * SIGNATURE_TIMEOUT boundary.
     */
    public function testParseIdentityStringRejectsTimestampOneSecondPastBoundary() {
        $staleEpoch = time() - SIGNATURE_TIMEOUT - 1;
        $staleDate = gmdate('D, d M Y H:i:s', $staleEpoch) . ' GMT';
        $identity = base64_encode(self::FIXED_PUBLIC_KEY . '%0%' . $staleDate . '%' . self::FIXED_ACCOUNT);

        $this->invokeParseIdentity($identity, \Kyte\Exception\SessionException::class, 'API request has expired');
    }

    /**
     * parseIdentityString throws a plain \Exception with "API key not found"
     * when the public key in the identity string doesn't match any row
     * in KyteAPIKey.
     *
     * Note: this is \Exception, not SessionException — a distinction worth
     * preserving through the refactor.
     */
    public function testParseIdentityStringRejectsUnknownApiKey() {
        $now = gmdate('D, d M Y H:i:s') . ' GMT';
        $identity = base64_encode('nonexistent-key%0%' . $now . '%' . self::FIXED_ACCOUNT);

        $this->invokeParseIdentity($identity, \Exception::class, 'API key not found');
    }

    /**
     * parseIdentityString throws a plain \Exception when the account number
     * in the identity string doesn't match any row in KyteAccount.
     */
    public function testParseIdentityStringRejectsUnknownAccount() {
        $now = gmdate('D, d M Y H:i:s') . ' GMT';
        $identity = base64_encode(self::FIXED_PUBLIC_KEY . '%0%' . $now . '%nonexistent-account');

        $this->invokeParseIdentity($identity, \Exception::class, 'Unable to find account');
    }

    /**
     * A session token of the literal string "undefined" is coerced to "0"
     * (anonymous). This quirk exists because some front-end clients send
     * the JavaScript value `undefined` as a string.
     */
    public function testParseIdentityStringTreatsUndefinedSessionAsZero() {
        $now = gmdate('D, d M Y H:i:s') . ' GMT';
        $identity = base64_encode(self::FIXED_PUBLIC_KEY . '%undefined%' . $now . '%' . self::FIXED_ACCOUNT);

        $this->invokeParseIdentity($identity);

        $ref = new \ReflectionClass(\Kyte\Core\Api::class);
        $responseProp = $ref->getProperty('response');
        $responseProp->setAccessible(true);
        $response = $responseProp->getValue($this->api);

        $this->assertSame('0', $response['session']);
    }

    /**
     * A session token of "0" means anonymous (pre-login). No session
     * validation is performed; $this->user is not populated.
     */
    public function testParseIdentityStringWithZeroSessionDoesNotPopulateUser() {
        $now = gmdate('D, d M Y H:i:s') . ' GMT';
        $identity = base64_encode(self::FIXED_PUBLIC_KEY . '%0%' . $now . '%' . self::FIXED_ACCOUNT);

        $this->invokeParseIdentity($identity);

        $ref = new \ReflectionClass(\Kyte\Core\Api::class);
        $userProp = $ref->getProperty('user');
        $userProp->setAccessible(true);
        $user = $userProp->getValue($this->api);

        $this->assertNull($user, 'Anonymous session should not populate $this->user');
    }

    /**
     * Shared helper: sets up the minimum Api state and invokes
     * parseIdentityString. Optionally asserts an expected exception.
     */
    private function invokeParseIdentity(string $rawIdentity, ?string $exceptionClass = null, ?string $exceptionMessage = null): void {
        $ref = new \ReflectionClass(\Kyte\Core\Api::class);

        $accountProp = $ref->getProperty('account');
        $accountProp->setAccessible(true);
        $accountProp->setValue($this->api, new \Kyte\Core\ModelObject(KyteAccount));

        $keyProp = $ref->getProperty('key');
        $keyProp->setAccessible(true);
        $keyProp->setValue($this->api, new \Kyte\Core\ModelObject(KyteAPIKey));

        $responseProp = $ref->getProperty('response');
        $responseProp->setAccessible(true);
        $responseProp->setValue($this->api, []);

        $method = $ref->getMethod('parseIdentityString');
        $method->setAccessible(true);

        if ($exceptionClass !== null) {
            $this->expectException($exceptionClass);
            if ($exceptionMessage !== null) {
                $this->expectExceptionMessage($exceptionMessage);
            }
        }

        $method->invoke($this->api, urlencode($rawIdentity));
    }
}
