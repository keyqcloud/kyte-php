<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class APiTest extends TestCase
{
    protected function setUp(): void {
        parent::setUp();
        
        // setup account and apikey
        require_once(__DIR__ . '/../src/Mvc/Model/APIKey.php');
        require_once(__DIR__ . '/../src/Mvc/Model/Account.php');

        $mockAPI = $APIKey;
        $mockAccount = $Account;
        // add primary keys
        \Kyte\Core\Api::addPrimaryKey($mockAPI);
        \Kyte\Core\Api::addPrimaryKey($mockAccount);

        \Kyte\Core\DBI::createTable($mockAPI);
        \Kyte\Core\DBI::createTable($mockAccount);

        // create dummy key
        $model = new \Kyte\Core\ModelObject($mockAPI);

        $model->create([
            'identifier' => 'FOO',
            'public_key' => 'BAR',
            'secret_key' => 'BAZ',
            'epoch' => 0,
            'kyte_account' => 1,
        ]);

        $model = new \Kyte\Core\ModelObject($mockAccount);

        $model->create([
            'name' => 'FOO',
            'number' => 'BAR',
        ]);
    }
    
    public function testInitApiFail() {
        $api = new \Kyte\Core\Api();
        $this->assertFalse($api->init('FOO'));
    }

    public function testInitApiSuccess() {
        $api = new \Kyte\Core\Api();
        $this->assertTrue($api->init('BAR'));
    }
}

?>  