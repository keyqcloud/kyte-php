<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class ModelTest extends TestCase
{
    public function testInitDB() {
        $this->assertIsObject(\Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB'));
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
            'temperature' => 78.3,
            'kyte_account' => 1,
        ]));
    }

    public function testModelObjectRetrieve() {
        $model = new \Kyte\Core\ModelObject(TestTable);

        $this->assertTrue($model->retrieve('name', 'Test'));
    }

    public function testModelObjectRetrieveWithCondition() {
        $model = new \Kyte\Core\ModelObject(TestTable);

        $this->assertTrue($model->retrieve('name', 'Test', [['field' => 'category', 'value' => 'test']]));
    }

    public function testDBSelectAll() {
        $data = \Kyte\Core\DBI::select('TestTable');
        $this->assertTrue(count($data) > 0 ? true : false);
    }

    public function testDBCustomQuery() {
        $data = \Kyte\Core\DBI::query('* FROM `TestTable`;');
        $this->assertTrue(count($data) > 0 ? true : false);
    }

    public function testModelObjectUpdate() {
        $model = new \Kyte\Core\ModelObject(TestTable);

        $this->assertTrue($model->retrieve('name', 'Test'));
        $this->assertTrue($model->save([
            'name' => 'Test1',
            'category' => 'ABC',
            'temperature' => 78.3,
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
            'temperature' => 78.3,
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

        $this->assertTrue($model->purge('name', 'Test2'));
    }

    public function testModelObjectGetParam() {
        $model = new \Kyte\Core\ModelObject(TestTable);

        $this->assertTrue($model->create([
            'name' => 'Test',
            'category' => 'Test',
            'temperature' => 78.3,
            'kyte_account' => 1,
        ]));

        $this->assertEquals('Test', $model->getParam('name'));
    }

    public function testModelObjectGetParams() {
        $model = new \Kyte\Core\ModelObject(TestTable);
        $this->assertTrue($model->retrieve('name', 'Test'));

        $this->assertArrayHasKey('category', $model->getParams(['name','category']));
    }

    public function testModelObjectGetAllParams() {
        $model = new \Kyte\Core\ModelObject(TestTable);
        $this->assertTrue($model->retrieve('name', 'Test'));

        $this->assertArrayHasKey('category', $model->getAllParams());
    }

    public function testModelObjectGetAllParamsWithDateFormat() {
        $model = new \Kyte\Core\ModelObject(TestTable);
        $this->assertTrue($model->retrieve('name', 'Test'));

        $this->assertArrayHasKey('category', $model->getAllParams('Y/m/d'));
    }

    public function testModelObjectGetParamKeys() {
        $model = new \Kyte\Core\ModelObject(TestTable);
        $this->assertTrue($model->retrieve('name', 'Test'));

        $this->assertContains('category', $model->paramKeys());
    }

    /*
     * Model tests
     * 
     * */
    public function testModelRetrieve() {
        // create one more entry
        $model = new \Kyte\Core\ModelObject(TestTable);

        $this->assertTrue($model->create([
            'name' => 'Test2',
            'category' => 'Test',
            'temperature' => 78.3,
            'kyte_account' => 1,
        ]));

        $model = new \Kyte\Core\Model(TestTable);

        $this->assertTrue($model->retrieve('category', 'Test'));

        $this->assertEquals(2, $model->count());
    }

    public function testModelRetrieveWithCondition() {
        $model = new \Kyte\Core\Model(TestTable);

        $this->assertTrue($model->retrieve('category', 'Test', false, [['field' => 'name', 'value' => 'Test2']]));

        $this->assertEquals(1, $model->count());
    }

    public function testModelRetrieveAll() {
        $model = new \Kyte\Core\ModelObject(TestTable);
        // create and delete
        $this->assertTrue($model->create([
            'name' => 'Test3',
            'category' => 'Test',
            'temperature' => 78.3,
            'kyte_account' => 1,
        ]));

        $this->assertTrue($model->delete('name', 'Test3', 0));

        $model = new \Kyte\Core\Model(TestTable);
        $this->assertTrue($model->retrieve('category', 'Test', false, null, true));

        $this->assertEquals(3, $model->count());
    }

    public function testModelRetrieveOrderBy() {
        $model = new \Kyte\Core\Model(TestTable);
        $this->assertTrue($model->retrieve('category', 'Test', false, null, true, ['field' => 'category', 'direction' => 'asc']));

        $this->assertEquals(3, $model->count());
    }

    public function testModelRetrieveLike() {
        $model = new \Kyte\Core\Model(TestTable);

        $this->assertTrue($model->retrieve('name', 'Test', true,));

        $this->assertEquals(2, $model->count());
    }

    public function testModelRetrieveAndReturnFirst() {
        $model = new \Kyte\Core\Model(TestTable);

        $this->assertTrue($model->retrieve('category', 'Test'));

        $this->assertIsObject($model->returnFirst());
    }

    public function testModelRetrieveGroupBy() {
        $model = new \Kyte\Core\Model(TestTable);

        $this->assertCount(1, $model->groupBy('category'));
    }

    public function testModelRetrieveGroupByWithCondition() {
        $model = new \Kyte\Core\Model(TestTable);

        $this->assertCount(1, $model->groupBy('category', [['field' => 'category', 'value' => 'Test']]));
    }

    public function testModelRetrieveGroupByAll() {
        $model = new \Kyte\Core\Model(TestTable);

        $this->assertCount(1, $model->groupBy('category', null, true));
    }

    public function testModelCustomSelect() {
        $model = new \Kyte\Core\Model(TestTable);
        $data = $model->customSelect('* FROM `TestTable`;');
        $this->assertIsArray($data);
        $this->assertCount(3, $data);
    }

    public function testModelSearch() {
        $model = new \Kyte\Core\Model(TestTable);
        $this->assertTrue($model->search(['name'], ['Test']));

        $this->assertEquals(2, $model->count());
    }

    public function testModelSearchAll() {
        $model = new \Kyte\Core\Model(TestTable);
        $this->assertTrue($model->search(['name'], ['Test'], true));

        $this->assertEquals(3, $model->count());
    }

    public function testModelRetrieveFrom() {
        $model = new \Kyte\Core\Model(TestTable);
        $this->assertTrue($model->from('date_created', 0, time()));

        $this->assertEquals(0, $model->count());
    }

    public function testModelRetrieveFromEquals() {
        $model = new \Kyte\Core\Model(TestTable);
        $this->assertTrue($model->from('date_created', 0, time(), true));

        $this->assertEquals(2, $model->count());
    }

    public function testModelRetrieveFromEqualsAlls() {
        $model = new \Kyte\Core\Model(TestTable);
        $this->assertTrue($model->from('date_created', 0, time(), true, true));

        $this->assertEquals(3, $model->count());
    }
}

?>