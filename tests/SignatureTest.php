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
     * parseIdentityString rejects an expired timestamp.
     * SIGNATURE_TIMEOUT default is 3600s; use an epoch well outside that window.
     */
    public function testParseIdentityStringRejectsExpiredTimestamp() {
        $ref = new \ReflectionClass(\Kyte\Core\Api::class);

        $accountProp = $ref->getProperty('account');
        $accountProp->setAccessible(true);
        $accountProp->setValue($this->api, new \Kyte\Core\ModelObject(KyteAccount));

        $keyProp = $ref->getProperty('key');
        $keyProp->setAccessible(true);
        $keyProp->setValue($this->api, new \Kyte\Core\ModelObject(KyteAPIKey));

        $staleDate = gmdate('D, d M Y H:i:s', time() - 7200) . ' GMT';
        $identity = base64_encode(self::FIXED_PUBLIC_KEY . '%0%' . $staleDate . '%' . self::FIXED_ACCOUNT);

        $method = $ref->getMethod('parseIdentityString');
        $method->setAccessible(true);

        $this->expectException(\Kyte\Exception\SessionException::class);
        $this->expectExceptionMessage('API request has expired');

        $method->invoke($this->api, urlencode($identity));
    }

    /**
     * parseIdentityString rejects a string that doesn't split into
     * exactly four parts on '%'.
     */
    public function testParseIdentityStringRejectsMalformedString() {
        $ref = new \ReflectionClass(\Kyte\Core\Api::class);

        $accountProp = $ref->getProperty('account');
        $accountProp->setAccessible(true);
        $accountProp->setValue($this->api, new \Kyte\Core\ModelObject(KyteAccount));

        $keyProp = $ref->getProperty('key');
        $keyProp->setAccessible(true);
        $keyProp->setValue($this->api, new \Kyte\Core\ModelObject(KyteAPIKey));

        $method = $ref->getMethod('parseIdentityString');
        $method->setAccessible(true);

        $this->expectException(\Kyte\Exception\SessionException::class);
        $this->expectExceptionMessage('Invalid identity string');

        $method->invoke($this->api, urlencode(base64_encode('only%three%parts')));
    }
}
