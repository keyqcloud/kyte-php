<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class ModelTest extends TestCase
{

    public function testInitDB() {
        \Kyte\Core\DBI::setDbUser(KYTE_DB_USERNAME);
        \Kyte\Core\DBI::setDbPassword(KYTE_DB_PASSWORD);
        \Kyte\Core\DBI::setDbHost(KYTE_DB_HOST);
        \Kyte\Core\DBI::setDbName(KYTE_DB_DATABASE);
        \Kyte\Core\DBI::setCharset(KYTE_DB_CHARSET);
    }

    public function testCreateTable() {
        // create test user table
        // $this->assertTrue(\Kyte\Core\DBI::createTable(User));

        // sample model
        $TestTable = [
            'name' => 'TestTable',
            'struct' => [
                'id' => [
                    'type'		=> 'i',
                    'required'	=> true,
                    'pk'		=> true,
                    'size'		=> 11,
                    'date'		=> false,
                ],

                'name'		=> [
                    'type'		=> 's',
                    'required'	=> true,
                    'size'		=> 255,
                    'date'		=> false,
                ],

                'temperature' => [
                    'type'      => 'd',
                    'required'  => true,
                    'precision' => 4,
                    'scale'     => 1,
                    'date'      => false,
                ],

                'category'		=> [
                    'type'		=> 's',
                    'required'	=> true,
                    'size'		=> 255,
                    'date'		=> false,
                ],
        
                // framework attributes
        
                'kyte_account'	=> [
                    'type'		=> 'i',
                    'required'	=> true,
                    'size'		=> 11,
                    'unsigned'	=> true,
                    'date'		=> false,
                ],
        
                // audit attributes
        
                'created_by'		=> [
                    'type'		=> 'i',
                    'required'	=> false,
                    'date'		=> true,
                ],
        
                'date_created'		=> [
                    'type'		=> 'i',
                    'required'	=> false,
                    'date'		=> true,
                ],
        
                'modified_by'		=> [
                    'type'		=> 'i',
                    'required'	=> false,
                    'date'		=> true,
                ],
        
                'deleted_by'		=> [
                    'type'		=> 'i',
                    'required'	=> false,
                    'date'		=> true,
                ],
        
                'created_by'		=> [
                    'type'		=> 'i',
                    'required'	=> false,
                    'date'		=> true,
                ],
        
                'date_deleted'		=> [
                    'type'		=> 'i',
                    'required'	=> false,
                    'date'		=> true,
                ],
        
                'deleted'	=> [
                    'type'		=> 'i',
                    'required'	=> false,
                    'size'		=> 1,
                    'unsigned'	=> true,
                    'default'	=> 0,
                    'date'		=> false,
                ],
            ],
        ];

        define('TestTable', $TestTable);
        
        $this->assertTrue(\Kyte\Core\DBI::createTable(TestTable));

        return true;
    }

    public function testModelObjectCreate() {
        $model = new \Kyte\Core\ModelObject(TestTable);

        $this->assertTrue($model->create([
            'name' => 'Test',
            'category' => 'Test',
            'kyte_account' => 1,
        ]));

        $this->assertTrue($model->create([
            'name' => 'Test1',
            'category' => 'Test',
            'kyte_account' => 1,
        ]));

        $this->assertTrue($model->create([
            'name' => 'Test2',
            'category' => 'Test',
            'kyte_account' => 1,
        ]));

        $this->assertTrue($model->create([
            'name' => 'Test3',
            'category' => 'Test',
            'kyte_account' => 1,
        ]));

        $this->assertTrue($model->create([
            'name' => 'Test4',
            'category' => 'Test',
            'kyte_account' => 1,
        ]));

        $this->assertTrue($model->create([
            'name' => 'Test5',
            'category' => 'Test',
            'kyte_account' => 1,
        ]));

        $this->assertTrue($model->create([
            'name' => 'Test6',
            'category' => 'Test',
            'kyte_account' => 1,
        ]));

        $this->assertTrue($model->create([
            'name' => 'Test7',
            'category' => 'Test',
            'kyte_account' => 1,
        ]));
    }

    public function testModelObjectRetrieve() {
        $model = new \Kyte\Core\ModelObject(TestTable);

        $this->assertTrue($model->retrieve('name', 'Test1'));
        $this->assertTrue($model->retrieve('name', 'Test2'));
        $this->assertTrue($model->retrieve('name', 'Test3'));
    }

    public function testModelObjectUpdate() {
        $model = new \Kyte\Core\ModelObject(TestTable);

        $this->assertTrue($model->retrieve('name', 'Test'));
        $this->assertTrue($model->save([
            'name' => 'Test0',
            'category' => 'ABC',
            'kyte_account' => 1,
        ]));
    }

    public function testModelObjectDeleteByRetrieve() {
        $model = new \Kyte\Core\ModelObject(TestTable);

        $this->assertTrue($model->retrieve('name', 'Test1'));
        $this->assertTrue($model->delete(null, null, 0));
    }

    public function testModelObjectDeleteByQuery() {
        $model = new \Kyte\Core\ModelObject(TestTable);

        $this->assertTrue($model->delete('name', 'Test2', 0));
    }

    public function testModelObjectPurgeByRetrieve() {
        $model = new \Kyte\Core\ModelObject(TestTable);

        $this->assertTrue($model->retrieve('name', 'Test4'));
        $this->assertTrue($model->purge());
    }

    public function testModelObjectPurgeByQuery() {
        $model = new \Kyte\Core\ModelObject(TestTable);

        $this->assertTrue($model->purge('name', 'Test3'));
    }

    public function testModeltRetrieve() {
        $model = new \Kyte\Core\Model(TestTable);

        $this->assertTrue($model->retrieve('category', 'Test'));
    }

}

?>  