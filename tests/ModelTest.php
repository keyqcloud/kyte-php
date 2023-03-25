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

        // create entry
        $model = new \Kyte\Core\ModelObject(TestTable);
        $this->assertTrue($model->create([
            'name' => 'Test',
            'category' => 'Test',
            'temperature' => 78.3,
            'kyte_account' => 1,
        ]));

        // test retrieve
        $model = new \Kyte\Core\ModelObject(TestTable);
        $this->assertTrue($model->retrieve('name', 'Test'));

        // test retrieve with conditions
        $model = new \Kyte\Core\ModelObject(TestTable);
        $this->assertTrue($model->retrieve('name', 'Test', [['field' => 'category', 'value' => 'test']]));

        // test custom select
        $data = \Kyte\Core\DBI::select('TestTable');
        $this->assertTrue(count($data) > 0 ? true : false);

        // test custom query
        $data = \Kyte\Core\DBI::query('SELECT * FROM `TestTable`;');
        $this->assertTrue(count($data) > 0 ? true : false);

        // test udpate entry
        $model = new \Kyte\Core\ModelObject(TestTable);
        $this->assertTrue($model->retrieve('name', 'Test'));
        $this->assertTrue($model->save([
            'name' => 'Test1',
            'category' => 'ABC',
            'temperature' => 78.3,
            'kyte_account' => 1,
        ]));

        // test retrieve and delete
        $model = new \Kyte\Core\ModelObject(TestTable);
        $this->assertTrue($model->retrieve('name', 'Test1'));
        $this->assertTrue($model->delete(null, null, 0));

        // test delete with conditions
        $model = new \Kyte\Core\ModelObject(TestTable);
        $this->assertTrue($model->create([
            'name' => 'Test2',
            'category' => 'Test',
            'temperature' => 78.3,
            'kyte_account' => 1,
        ]));
        $this->assertTrue($model->delete('name', 'Test2', 0));

        // test retrieve and purge
        $model = new \Kyte\Core\ModelObject(TestTable);
        $this->assertTrue($model->retrieve('name', 'Test1', null, null, true));
        $this->assertTrue($model->purge());

        // test purge with conditions
        $model = new \Kyte\Core\ModelObject(TestTable);
        $this->assertTrue($model->purge('name', 'Test2'));

        // test getParam
        $model = new \Kyte\Core\ModelObject(TestTable);
        $this->assertTrue($model->create([
            'name' => 'Test',
            'category' => 'Test',
            'temperature' => 78.3,
            'kyte_account' => 1,
        ]));
        $this->assertEquals('Test', $model->getParam('name'));

        // test getParams
        $model = new \Kyte\Core\ModelObject(TestTable);
        $this->assertTrue($model->retrieve('name', 'Test'));
        $this->assertArrayHasKey('category', $model->getParams(['name','category']));

        // test getAllParams
        $model = new \Kyte\Core\ModelObject(TestTable);
        $this->assertTrue($model->retrieve('name', 'Test'));
        $this->assertArrayHasKey('category', $model->getAllParams());

        // test getAllParams with date time format
        $model = new \Kyte\Core\ModelObject(TestTable);
        $this->assertTrue($model->retrieve('name', 'Test'));
        $this->assertArrayHasKey('category', $model->getAllParams('Y/m/d'));

        // test get paramKeys
        $model = new \Kyte\Core\ModelObject(TestTable);
        $this->assertTrue($model->retrieve('name', 'Test'));
        $this->assertContains('category', $model->paramKeys());

        /*
        * Model tests
        *
        * */

        // test retrieve
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

        // test retrieve with conditions
        $model = new \Kyte\Core\Model(TestTable);
        $this->assertTrue($model->retrieve('category', 'Test', false, [['field' => 'name', 'value' => 'Test2']]));
        $this->assertEquals(1, $model->count());

        // test delete then retrieve all
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

        // test retrieve order by
        $model = new \Kyte\Core\Model(TestTable);
        $this->assertTrue($model->retrieve('category', 'Test', false, null, true, ['field' => 'category', 'direction' => 'asc']));
        $this->assertEquals(3, $model->count());

        // test retrieve like
        $model = new \Kyte\Core\Model(TestTable);
        $this->assertTrue($model->retrieve('name', 'Test', true));
        $this->assertEquals(2, $model->count());

        // test return first
        $model = new \Kyte\Core\Model(TestTable);
        $this->assertTrue($model->retrieve('category', 'Test'));
        $this->assertIsObject($model->returnFirst());

        // test group by
        $model = new \Kyte\Core\Model(TestTable);
        $this->assertCount(1, $model->groupBy('category'));

        // test group by with conditions
        $model = new \Kyte\Core\Model(TestTable);
        $this->assertCount(1, $model->groupBy('category', [['field' => 'category', 'value' => 'Test']]));

        //test group by all
        $model = new \Kyte\Core\Model(TestTable);
        $this->assertCount(1, $model->groupBy('category', null, true));

        // test custom select
        $model = new \Kyte\Core\Model(TestTable);
        $data = $model->customQuery('* FROM `TestTable`;');
        $this->assertIsArray($data);
        $this->assertCount(3, $data);

        // test search
        $model = new \Kyte\Core\Model(TestTable);
        $this->assertTrue($model->search(['name'], ['Test']));
        $this->assertEquals(2, $model->count());

        // test search all
        $model = new \Kyte\Core\Model(TestTable);
        $this->assertTrue($model->search(['name'], ['Test'], true));
        $this->assertEquals(3, $model->count());

        // test retrieve from
        $model = new \Kyte\Core\Model(TestTable);
        $this->assertTrue($model->from('date_created', 0, time()));
        $this->assertEquals(0, $model->count());

        // test retrieve from all
        $model = new \Kyte\Core\Model(TestTable);
        $this->assertTrue($model->from('date_created', 0, time(), true));
        $this->assertEquals(2, $model->count());

        // test retrieve from all equals
        $model = new \Kyte\Core\Model(TestTable);
        $this->assertTrue($model->from('date_created', 0, time(), true, true));
        $this->assertEquals(3, $model->count());
    }
}
