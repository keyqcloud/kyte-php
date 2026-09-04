<?php
namespace Kyte\Test;

use Kyte\Core\Api;
use Kyte\Mcp\Tools\ScriptTools;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ScriptTools per-page assignment (assign_script / unassign_script).
 *
 * Uses UNPUBLISHED pages (state != 1) so the scoping + assignment-row logic is
 * exercised without the live S3/CloudFront regeneration path (that path is covered
 * by the dev19 end-to-end test). Verifies: a per-page KyteScriptAssignment row is
 * created with global_scope=0 (never the buggy global path), idempotency, unassign
 * soft-deletes it, and cross-account / cross-site requests are rejected.
 */
class McpScriptAssignTest extends TestCase
{
    private const OWN   = 'mcp-scrasg-own';
    private const OTHER = 'mcp-scrasg-other';

    private Api $api;
    private ScriptTools $tools;
    private int $ownAccountId;
    private int $otherAccountId;
    private int $ownSiteId;
    private int $ownSite2Id;
    private int $otherSiteId;
    private int $ownScriptId;
    private int $otherScriptId;
    private int $ownPageId;
    private int $ownPageSite2Id;
    private int $otherPageId;

    protected function setUp(): void
    {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');
        $this->api = new Api();

        foreach ([KyteAccount, Application, KyteSite, KytePage, KyteScript, KyteScriptAssignment, KyteUser] as $m) {
            \Kyte\Core\DBI::createTable($m);
        }
        \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number IN ('" . self::OWN . "','" . self::OTHER . "')");
        \Kyte\Core\DBI::query("DELETE FROM `Application` WHERE identifier LIKE 'mcp-scrasg-%'");
        \Kyte\Core\DBI::query("DELETE FROM `KyteSite` WHERE name LIKE 'McpScrAsg%'");
        \Kyte\Core\DBI::query("DELETE FROM `KytePage` WHERE title LIKE 'McpScrAsg%'");
        \Kyte\Core\DBI::query("DELETE FROM `KyteScript` WHERE name LIKE 'McpScrAsg%'");

        $this->ownAccountId   = $this->mkAccount(self::OWN);
        $this->otherAccountId = $this->mkAccount(self::OTHER);
        $this->mkUser($this->ownAccountId, 'own@scrasg.test');

        $ownApp   = $this->mkApp('mcp-scrasg-own',   $this->ownAccountId);
        $otherApp = $this->mkApp('mcp-scrasg-other', $this->otherAccountId);

        $this->ownSiteId   = $this->mkSite('McpScrAsgOwnSite',   $ownApp,   $this->ownAccountId);
        $this->ownSite2Id  = $this->mkSite('McpScrAsgOwnSite2',  $ownApp,   $this->ownAccountId);
        $this->otherSiteId = $this->mkSite('McpScrAsgOtherSite', $otherApp, $this->otherAccountId);

        $this->ownScriptId   = $this->mkScript('McpScrAsgOwnScript',   $this->ownSiteId,   $this->ownAccountId);
        $this->otherScriptId = $this->mkScript('McpScrAsgOtherScript', $this->otherSiteId, $this->otherAccountId);

        $this->ownPageId      = $this->mkPage('McpScrAsgOwnPage',  $this->ownSiteId,  $this->ownAccountId);
        $this->ownPageSite2Id = $this->mkPage('McpScrAsgOwnPage2', $this->ownSite2Id, $this->ownAccountId);
        $this->otherPageId    = $this->mkPage('McpScrAsgOtherPage', $this->otherSiteId, $this->otherAccountId);

        $this->api->account = new \Kyte\Core\ModelObject(KyteAccount);
        $this->api->account->retrieve('id', $this->ownAccountId);
        $this->api->mcpScopes = ['read', 'schema'];
        $this->tools = new ScriptTools($this->api);
        $_SERVER = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'test.local'];
    }

    public function testAssignCreatesGlobalScopeZeroRow(): void
    {
        $r = $this->tools->assignScript($this->ownScriptId, $this->ownPageId);
        $this->assertTrue($r['ok'], $r['error'] ?? 'assign failed');
        $this->assertSame('assign', $r['action']);
        $this->assertFalse($r['page_regenerated'], 'unpublished page must not regenerate (no S3)');
        $this->assertArrayHasKey('note', $r, 'unpublished script should return a note to commit_draft');

        $rows = \Kyte\Core\DBI::query("SELECT global_scope, deleted FROM KyteScriptAssignment WHERE script={$this->ownScriptId} AND page={$this->ownPageId}");
        $this->assertCount(1, $rows);
        $this->assertSame(0, (int)$rows[0]['global_scope'], 'per-page assignment must be global_scope=0');
        $this->assertSame(0, (int)$rows[0]['deleted']);
    }

    public function testAssignIsIdempotent(): void
    {
        $this->tools->assignScript($this->ownScriptId, $this->ownPageId);
        $this->tools->assignScript($this->ownScriptId, $this->ownPageId);
        $rows = \Kyte\Core\DBI::query("SELECT id FROM KyteScriptAssignment WHERE script={$this->ownScriptId} AND page={$this->ownPageId} AND deleted=0");
        $this->assertCount(1, $rows, 'assigning twice must not create a duplicate');
    }

    public function testUnassignSoftDeletes(): void
    {
        $this->tools->assignScript($this->ownScriptId, $this->ownPageId);
        $u = $this->tools->unassignScript($this->ownScriptId, $this->ownPageId);
        $this->assertTrue($u['ok'], $u['error'] ?? 'unassign failed');
        $this->assertSame('unassign', $u['action']);
        $active = \Kyte\Core\DBI::query("SELECT id FROM KyteScriptAssignment WHERE script={$this->ownScriptId} AND page={$this->ownPageId} AND deleted=0");
        $this->assertCount(0, $active, 'unassign must soft-delete the row');
    }

    public function testRejectsForeignScript(): void
    {
        $r = $this->tools->assignScript($this->otherScriptId, $this->ownPageId);
        $this->assertFalse($r['ok'], 'cannot assign another account\'s script');
    }

    public function testRejectsForeignPage(): void
    {
        $r = $this->tools->assignScript($this->ownScriptId, $this->otherPageId);
        $this->assertFalse($r['ok'], 'cannot assign to another account\'s page');
    }

    public function testRejectsCrossSite(): void
    {
        $r = $this->tools->assignScript($this->ownScriptId, $this->ownPageSite2Id);
        $this->assertFalse($r['ok'], 'cross-site assignment must be rejected');
        $this->assertStringContainsString('same site', (string)($r['error'] ?? ''));
    }

    private function mkAccount(string $number): int
    {
        $o = new \Kyte\Core\ModelObject(KyteAccount);
        $o->create(['number' => $number, 'name' => $number]);
        return (int)$o->id;
    }

    private function mkUser(int $acct, string $email): void
    {
        $o = new \Kyte\Core\ModelObject(KyteUser);
        $o->create(['name' => 'T', 'email' => $email, 'username' => '', 'password' => 'x', 'kyte_account' => $acct]);
    }

    private function mkApp(string $ident, int $acct): int
    {
        $o = new \Kyte\Core\ModelObject(Application);
        $o->create(['name' => 'App ' . $ident, 'identifier' => $ident, 'kyte_account' => $acct]);
        return (int)$o->id;
    }

    private function mkSite(string $name, int $app, int $acct): int
    {
        $o = new \Kyte\Core\ModelObject(KyteSite);
        $o->create(['name' => $name, 'status' => 'active', 'application' => $app, 'kyte_account' => $acct]);
        return (int)$o->id;
    }

    private function mkScript(string $name, int $site, int $acct): int
    {
        $o = new \Kyte\Core\ModelObject(KyteScript);
        $o->create(['name' => $name, 's3key' => strtolower($name) . '.js', 'script_type' => 'js', 'state' => 0, 'site' => $site, 'kyte_account' => $acct]);
        return (int)$o->id;
    }

    private function mkPage(string $title, int $site, int $acct): int
    {
        $o = new \Kyte\Core\ModelObject(KytePage);
        $o->create(['title' => $title, 's3key' => strtolower($title) . '.html', 'state' => 0, 'site' => $site, 'kyte_account' => $acct]);
        return (int)$o->id;
    }
}
