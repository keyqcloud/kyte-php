<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class ModelTest extends TestCase
{
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
    }

    public function testModelObjectRetrieve() {
        $model = new \Kyte\Core\ModelObject(TestTable);

        $this->assertTrue($model->retrieve('name', 'Test1'));
        $this->assertTrue($model->retrieve('name', 'Test2'));
        $this->assertTrue($model->retrieve('name', 'Test3'));
    }

    public function testModelObjectUpdate() {
        $model = new \Kyte\Core\ModelObject(TestTable);

        $this->assertTrue($model->retrieve('name', 'Test4'));
        $this->assertTrue($model->save([
            'name' => 'Test5',
            'category' => 'ABC',
            'kyte_account' => 1,
        ]));
    }

    public function testModelObjectDelete() {
        $model = new \Kyte\Core\ModelObject(TestTable);

        $this->assertTrue($model->delete('name', 'Test1', 0));
    }

    public function testModeltRetrieve() {
        $model = new \Kyte\Core\Model(TestTable);

        $this->assertTrue($model->retrieve('category', 'Test'));
    }

}

?>  