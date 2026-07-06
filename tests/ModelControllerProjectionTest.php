<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;
use Kyte\Mvc\Controller\ModelController;

/**
 * Unit tests for ModelController::resolveProjection (KYTE-#190) — the pure
 * projection-resolution logic behind the generic X-Kyte-Fields wiring and the
 * controller default-exclude (server-side deferral). No DB / request state.
 */
class ModelControllerProjectionTest extends TestCase
{
    /** @var array<string,mixed> */
    private $struct = [
        'id'           => ['type' => 'i'],
        'name'         => ['type' => 's'],
        'client'       => ['type' => 'i', 'fk' => ['model' => 'Client', 'field' => 'id']],
        'body'         => ['type' => 'lt'],
        'kyte_account' => ['type' => 'i'],
        'date_created' => ['type' => 'i'],
    ];

    /** @var array<int,string> */
    private $always = ['id', 'kyte_account', 'date_created'];

    public function testNoHeaderNoExcludeReturnsNull(): void
    {
        // full row — historical behaviour preserved
        $this->assertNull(ModelController::resolveProjection($this->struct, null, $this->always, []));
    }

    public function testEmptyHeaderReturnsNull(): void
    {
        $this->assertNull(ModelController::resolveProjection($this->struct, '   ', $this->always, []));
    }

    public function testHeaderProjectsRequestedPlusAlways(): void
    {
        $p = ModelController::resolveProjection($this->struct, 'name', $this->always, []);
        $this->assertContains('name', $p);
        $this->assertContains('id', $p);             // always-included
        $this->assertContains('kyte_account', $p);
        $this->assertNotContains('body', $p);        // large column not requested
    }

    public function testDottedFkPathContributesBaseColumn(): void
    {
        $p = ModelController::resolveProjection($this->struct, 'name,client.name', $this->always, []);
        $this->assertContains('client', $p);         // base column of client.name
        $this->assertNotContains('body', $p);
    }

    public function testUnknownFieldsDropped(): void
    {
        $p = ModelController::resolveProjection($this->struct, 'name,bogus,also.bad', $this->always, []);
        $this->assertContains('name', $p);
        $this->assertNotContains('bogus', $p);
        $this->assertNotContains('also', $p);
    }

    public function testAllUnknownFieldsFallBackToNull(): void
    {
        // header present but nothing valid => full row, not an empty projection
        $this->assertNull(ModelController::resolveProjection($this->struct, 'bogus,nope', $this->always, []));
    }

    public function testDefaultExcludeProjectsStructMinusExcludes(): void
    {
        $p = ModelController::resolveProjection($this->struct, null, $this->always, ['body']);
        $this->assertNotContains('body', $p);        // excluded
        $this->assertContains('name', $p);           // everything else present
        $this->assertContains('id', $p);
        $this->assertContains('client', $p);
    }

    public function testHeaderTakesPrecedenceOverDefaultExclude(): void
    {
        // a client that explicitly asks for `body` wins over the default exclude
        $p = ModelController::resolveProjection($this->struct, 'body', $this->always, ['body']);
        $this->assertContains('body', $p);
    }

    public function testNoDuplicateColumns(): void
    {
        // `id` is both always-included and requested — it must appear once
        $p = ModelController::resolveProjection($this->struct, 'id,name', $this->always, []);
        $this->assertSame(count($p), count(array_unique($p)));
    }

    public function testAlwaysIncludeColumnsKeepsIdAuditAndForeignKeys(): void
    {
        // Decision C ("id + FK ids + audit"): FK columns must always be kept so
        // FK expansion still runs for hooks that read expanded FK data (e.g.
        // KytePage/KyteScript/Media read $r['site']['region']).
        $always = ModelController::alwaysIncludeColumns($this->struct);
        $this->assertContains('id', $always);
        $this->assertContains('kyte_account', $always);   // account/audit
        $this->assertContains('date_created', $always);
        $this->assertContains('client', $always);          // FK column always kept
        $this->assertNotContains('name', $always);         // plain column not auto-included
        $this->assertNotContains('body', $always);         // large column not auto-included
        $this->assertSame(count($always), count(array_unique($always)));
    }

    public function testProjectionKeepsForeignKeysSoExpansionStillRuns(): void
    {
        // End-to-end of the fix: a client projecting only `name` still gets the
        // FK `client` back (via always-include), so FK expansion isn't dropped.
        $p = ModelController::resolveProjection(
            $this->struct,
            'name',
            ModelController::alwaysIncludeColumns($this->struct),
            []
        );
        $this->assertContains('name', $p);
        $this->assertContains('client', $p);   // FK preserved even though unrequested
        $this->assertNotContains('body', $p);  // large column still trimmed
    }

    public function testStripEagerHelpersRemovesObjectKeysButKeepsRealFields(): void
    {
        // Eager-load attaches `<fk>_object` helper props that getAllParams
        // surfaces; they must not reach the response (KYTE-#190 — a bzip2
        // section-template `<fk>_object` carries binary that breaks json_encode).
        $struct = ['id' => [], 'parent' => ['fk' => []], 'name' => []];
        $row = [
            'id'            => 1,
            'parent'        => 5,
            'name'          => 'x',
            'parent_object' => (object) ['name' => 'P'],   // eager helper (object)
            'header_object' => "\xff\xfebinary",           // eager helper (binary)
        ];
        $out = ModelController::stripEagerHelpers($row, $struct);
        $this->assertArrayHasKey('id', $out);
        $this->assertArrayHasKey('parent', $out);          // real FK field kept
        $this->assertArrayHasKey('name', $out);
        $this->assertArrayNotHasKey('parent_object', $out); // helper stripped
        $this->assertArrayNotHasKey('header_object', $out); // helper stripped
        // the survivors must be JSON-encodable (the whole point)
        $this->assertNotFalse(json_encode($out));
    }
}
