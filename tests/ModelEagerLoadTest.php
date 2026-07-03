<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

/**
 * KYTE-#190 / #340: batched FK eager-loading in Model::with()/eagerLoadRelations
 * must (a) attach the related object for each row (fixing the N+1) and (b) apply
 * the same account/org scoping the lazy path uses, so it can NEVER surface a
 * cross-account FK row.
 */
class ModelEagerLoadTest extends TestCase
{
    protected function setUp(): void
    {
        new \Kyte\Core\Api();
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');

        if (!defined('EagerParent')) {
            define('EagerParent', [
                'name' => 'EagerParent',
                'struct' => [
                    'id'           => ['type' => 'i', 'required' => true, 'pk' => true, 'size' => 11, 'date' => false],
                    'name'         => ['type' => 's', 'required' => false, 'size' => 255, 'date' => false],
                    'kyte_account' => ['type' => 'i', 'required' => false, 'size' => 11, 'unsigned' => true, 'date' => false],
                    'created_by'   => ['type' => 'i', 'required' => false, 'date' => true],
                    'date_created' => ['type' => 'i', 'required' => false, 'date' => true],
                    'modified_by'  => ['type' => 'i', 'required' => false, 'date' => true],
                    'date_modified' => ['type' => 'i', 'required' => false, 'date' => true],
                    'deleted_by'   => ['type' => 'i', 'required' => false, 'date' => true],
                    'date_deleted' => ['type' => 'i', 'required' => false, 'date' => true],
                    'deleted'      => ['type' => 'i', 'required' => false, 'size' => 1, 'unsigned' => true, 'default' => 0, 'date' => false],
                ],
            ]);
        }
        if (!defined('EagerChild')) {
            define('EagerChild', [
                'name' => 'EagerChild',
                'struct' => [
                    'id'           => ['type' => 'i', 'required' => true, 'pk' => true, 'size' => 11, 'date' => false],
                    'parent'       => ['type' => 'i', 'required' => false, 'size' => 11, 'unsigned' => true, 'date' => false, 'fk' => ['model' => 'EagerParent', 'field' => 'id']],
                    'kyte_account' => ['type' => 'i', 'required' => false, 'size' => 11, 'unsigned' => true, 'date' => false],
                    'created_by'   => ['type' => 'i', 'required' => false, 'date' => true],
                    'date_created' => ['type' => 'i', 'required' => false, 'date' => true],
                    'modified_by'  => ['type' => 'i', 'required' => false, 'date' => true],
                    'date_modified' => ['type' => 'i', 'required' => false, 'date' => true],
                    'deleted_by'   => ['type' => 'i', 'required' => false, 'date' => true],
                    'date_deleted' => ['type' => 'i', 'required' => false, 'date' => true],
                    'deleted'      => ['type' => 'i', 'required' => false, 'size' => 1, 'unsigned' => true, 'default' => 0, 'date' => false],
                ],
            ]);
        }

        \Kyte\Core\DBI::query('DROP TABLE IF EXISTS `EagerChild`');
        \Kyte\Core\DBI::query('DROP TABLE IF EXISTS `EagerParent`');
        \Kyte\Core\DBI::createTable(EagerParent);
        \Kyte\Core\DBI::createTable(EagerChild);
    }

    public function testEagerLoadAttachesRelatedObjectForEachRow(): void
    {
        // Two parents + two children (account 1), each referencing a parent.
        $pa = new \Kyte\Core\ModelObject(EagerParent);
        $pa->create(['name' => 'Alpha', 'kyte_account' => 1]);
        $pb = new \Kyte\Core\ModelObject(EagerParent);
        $pb->create(['name' => 'Beta', 'kyte_account' => 1]);

        $ca = new \Kyte\Core\ModelObject(EagerChild);
        $ca->create(['parent' => $pa->id, 'kyte_account' => 1]);
        $cb = new \Kyte\Core\ModelObject(EagerChild);
        $cb->create(['parent' => $pb->id, 'kyte_account' => 1]);

        $m = new \Kyte\Core\Model(EagerChild);
        $m->with(['parent']);
        $this->assertTrue($m->retrieve('kyte_account', 1, false, null, true));
        $this->assertEquals(2, $m->count());

        foreach ($m->objects as $child) {
            $this->assertTrue(isset($child->parent_object), 'each child gets its parent eager-loaded');
        }
    }

    public function testScopedEagerLoadDoesNotLeakCrossAccountParent(): void
    {
        // Parent belongs to account 2; a child in account 1 references it.
        $p = new \Kyte\Core\ModelObject(EagerParent);
        $p->create(['name' => 'Secret', 'kyte_account' => 2]);
        $c = new \Kyte\Core\ModelObject(EagerChild);
        $c->create(['parent' => $p->id, 'kyte_account' => 1]);

        // With account-1 scoping, the account-2 parent must NOT be eager-loaded.
        $scoped = new \Kyte\Core\Model(EagerChild);
        $scoped->with(['parent'], ['parent' => [['field' => 'kyte_account', 'value' => 1]]]);
        $this->assertTrue($scoped->retrieve('kyte_account', 1, false, null, true));
        $child = $scoped->first();
        $this->assertFalse(isset($child->parent_object), 'cross-account parent must NOT leak when scoped');

        // Control: without scoping the batched load DOES pull it — proving the
        // scoping conditions are what prevent the leak.
        $unscoped = new \Kyte\Core\Model(EagerChild);
        $unscoped->with(['parent']);
        $this->assertTrue($unscoped->retrieve('kyte_account', 1, false, null, true));
        $child2 = $unscoped->first();
        $this->assertTrue(isset($child2->parent_object), 'unscoped eager-load loads the parent (control)');
        $this->assertEquals('Secret', $child2->parent_object->getParam('name'));
    }
}
