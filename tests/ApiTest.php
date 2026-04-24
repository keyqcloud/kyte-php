<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class APiTest extends TestCase
{

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
}
