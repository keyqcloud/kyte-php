<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class APiTest extends TestCase
{

    public function testClampPageSize() {
        // within range -> unchanged
        $this->assertSame(25, \Kyte\Core\Api::clampPageSize(25, 100, 50));
        // over max -> clamped to max
        $this->assertSame(100, \Kyte\Core\Api::clampPageSize(1000000, 100, 50));
        $this->assertSame(100, \Kyte\Core\Api::clampPageSize(101, 100, 50));
        // exactly max -> unchanged
        $this->assertSame(100, \Kyte\Core\Api::clampPageSize(100, 100, 50));
        // non-positive / non-numeric -> default
        $this->assertSame(50, \Kyte\Core\Api::clampPageSize(0, 100, 50));
        $this->assertSame(50, \Kyte\Core\Api::clampPageSize(-5, 100, 50));
        $this->assertSame(50, \Kyte\Core\Api::clampPageSize('abc', 100, 50));
    }

    public function testInitApiSuccess() {
        $api = new \Kyte\Core\Api();

        \Kyte\Core\DBI::createTable(KyteAPIKey);
        \Kyte\Core\DBI::createTable(KyteAccount);

        $apiKey = new \Kyte\Core\ModelObject(KyteAPIKey);
        $apiKey->create([
            'identifier' => 'FOO',
            'public_key' => 'BAR',
            'secret_key' => 'BAZ',
            'epoch' => 0,
            'kyte_account' => 1,
        ]);

        $account = new \Kyte\Core\ModelObject(KyteAccount);
        $account->create([
            'name' => 'FOO',
            'number' => 'BAR',
        ]);

        $this->assertInstanceOf(\Kyte\Core\Api::class, $api);
        $this->assertNotNull($apiKey->id);
        $this->assertNotNull($account->id);
    }

    /**
     * KYTE-#613: the API response must never carry EC2 instance metadata.
     * The old fetch was gated on a non-CLI SAPI, which PHPUnit can't simulate,
     * so also guard the constructor source against the call coming back.
     */
    public function testResponseHasNoImdsKey() {
        $api = new \Kyte\Core\Api();
        $this->assertArrayNotHasKey('imds', $api->response);

        $ctor = new \ReflectionMethod(\Kyte\Core\Api::class, '__construct');
        $lines = file($ctor->getFileName());
        $body = implode('', array_slice($lines, $ctor->getStartLine() - 1, $ctor->getEndLine() - $ctor->getStartLine() + 1));
        $this->assertStringNotContainsString('IMDS::fetchMetadata', $body);
        $this->assertStringNotContainsString("response['imds']", $body);
    }
}
