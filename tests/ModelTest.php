<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class ModelTest extends TestCase
{
    public function testInitDB() {
        $this->assertTrue(\Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB'));
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
    }

    public function testModelObjectRetrieve() {
        $model = new \Kyte\Core\ModelObject(TestTable);

        $this->assertTrue($model->retrieve('name', 'Test'));
    }

<<<<<<< HEAD
    public function testModelObjectRetrieveWithCondition() {
=======
    public function testDBSelectAll() {
        $data = \Kyte\Core\DBI::select('TestTable');
        $this->assertTrue(count($data) > 0 ? true : false);
    }

    public function testDBCustomQuery() {
        $data = \Kyte\Core\DBI::query('SELECT * FROM `TestTable`;');
        $this->assertTrue(count($data) > 0 ? true : false);
    }

    public function testModelObjectRetrieve() {
>>>>>>> e419ae72186ec9807d9ba7327bb477a839e59814
        $model = new \Kyte\Core\ModelObject(TestTable);

        $this->assertTrue($model->retrieve('name', 'Test', ['field' => 'category', 'value' => 'test']));
    }

    public function testModelObjectUpdate() {
        $model = new \Kyte\Core\ModelObject(TestTable);

        $this->assertTrue($model->retrieve('name', 'Test'));
        $this->assertTrue($model->save([
            'name' => 'Test1',
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
        $this->assertTrue($model->create([
            'name' => 'Test2',
            'category' => 'Test',
            'kyte_account' => 1,
        ]));

        $this->assertTrue($model->delete('name', 'Test2', 0));
    }

    public function testModelObjectPurgeByRetrieve() {
        $model = new \Kyte\Core\ModelObject(TestTable);

        $this->assertTrue($model->retrieve('name', 'Test1', null, null, true));
        $this->assertTrue($model->purge());
    }

    public function testModelObjectPurgeByQuery() {
        $model = new \Kyte\Core\ModelObject(TestTable);
        $this->assertTrue($model->create([
            'name' => 'Test2',
            'category' => 'Test',
            'kyte_account' => 1,
        ]));

        $this->assertTrue($model->purge('name', 'Test2'));
    }

    public function testModeltRetrieve() {
        $model = new \Kyte\Core\Model(TestTable);

        $this->assertTrue($model->retrieve('category', 'Test'));
    }
}

?>