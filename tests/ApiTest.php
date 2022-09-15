<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class APiTest extends TestCase
{

    public function testInitApiSuccess() {
        // create new api
        $api = new \Kyte\Core\Api();

        // create APIKey and Account tables
        \Kyte\Core\DBI::createTable(APIKey);
        \Kyte\Core\DBI::createTable(Account);

        // create test api key
        $model = new \Kyte\Core\ModelObject(APIKey);
        $model->create([
            'identifier' => 'FOO',
            'public_key' => 'BAR',
            'secret_key' => 'BAZ',
            'epoch' => 0,
            'kyte_account' => 1,
        ]);

        // create test account
        $model = new \Kyte\Core\ModelObject(Account);
        $model->create([
            'name' => 'FOO',
            'number' => 'BAR',
        ]);

        $this->assertTrue($api->init('BAR'));
    }
}
