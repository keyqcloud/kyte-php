<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class APiTest extends TestCase
{

    public function testInitApiSuccess() {
        // create new api
        $api = new \Kyte\Core\Api();

        // create KyteAPIKey and Account tables
        \Kyte\Core\DBI::createTable(KyteAPIKey);
        \Kyte\Core\DBI::createTable(KyteAccount);

        // create test api key
        $model = new \Kyte\Core\ModelObject(KyteAPIKey);
        $model->create([
            'identifier' => 'FOO',
            'public_key' => 'BAR',
            'secret_key' => 'BAZ',
            'epoch' => 0,
            'kyte_account' => 1,
        ]);

        // create test account
        $model = new \Kyte\Core\ModelObject(KyteAccount);
        $model->create([
            'name' => 'FOO',
            'number' => 'BAR',
        ]);

        $this->assertTrue($api->init('BAR'));
    }
}
