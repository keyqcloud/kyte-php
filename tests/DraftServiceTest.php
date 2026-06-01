<?php
namespace Kyte\Test;

use Kyte\Core\Api;
use Kyte\Mcp\Service\DraftService;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the MCP draft authoring loop (DraftService).
 *
 * Exercises the page surface end-to-end at the service layer: write a part
 * (creates a draft), write a second part (accumulates into the SAME draft),
 * read it back with a diff, list it, and discard it — asserting throughout
 * that the live current version is never mutated. The commit path is NOT
 * covered here because it publishes to S3/CloudFront (AWS); it is validated
 * separately against the dev environment.
 *
 * Account scoping is set manually (we don't go through Api::route), mirroring
 * ActivityLoggerSensitivityTest's approach.
 */
class DraftServiceTest extends TestCase
{
    private const ACCOUNT = 'draft-svc-test';

    private Api $api;
    private int $accountId;
    private int $pageId;

    protected function setUp(): void
    {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');

        $this->api = new Api();

        \Kyte\Core\DBI::createTable(KyteAccount);
        \Kyte\Core\DBI::createTable(KytePage);
        \Kyte\Core\DBI::createTable(KytePageVersion);
        \Kyte\Core\DBI::createTable(KytePageVersionContent);

        \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number = '" . self::ACCOUNT . "'");

        $acct = new \Kyte\Core\ModelObject(KyteAccount);
        $acct->create(['number' => self::ACCOUNT, 'name' => 'Draft Svc Test']);
        $this->accountId = (int)$acct->id;

        // Clean any stray rows from a prior run for this account.
        \Kyte\Core\DBI::query("DELETE FROM `KytePageVersion` WHERE kyte_account = {$this->accountId}");
        \Kyte\Core\DBI::query("DELETE FROM `KytePage` WHERE kyte_account = {$this->accountId}");

        // A page with a current (live) version so we exercise the live-base path.
        $page = new \Kyte\Core\ModelObject(KytePage);
        $page->create([
            'title'        => 'Draft Test Page',
            'state'        => 1,
            'kyte_account' => $this->accountId,
        ]);
        $this->pageId = (int)$page->id;

        // Seed a current version + its content.
        $liveHtml = '<p>live html</p>';
        $liveCss  = 'body{color:#000}';
        $liveJs   = '';
        $hash = hash('sha256', $liveHtml . $liveCss . $liveJs . '');
        $content = new \Kyte\Core\ModelObject(KytePageVersionContent);
        $content->create([
            'content_hash'    => $hash,
            'html'            => bzcompress($liveHtml, 9),
            'stylesheet'      => bzcompress($liveCss, 9),
            'javascript'      => bzcompress($liveJs, 9),
            'block_layout'    => bzcompress('', 9),
            'reference_count' => 1,
            'last_referenced' => time(),
            'kyte_account'    => $this->accountId,
        ]);
        $cur = new \Kyte\Core\ModelObject(KytePageVersion);
        $cur->create([
            'page'           => $this->pageId,
            'version_number' => 1,
            'version_type'   => 'initial',
            'content_hash'   => $hash,
            'is_current'     => 1,
            'draft'          => 0,
            'kyte_account'   => $this->accountId,
        ]);

        $this->api->account = new \Kyte\Core\ModelObject(KyteAccount);
        $this->api->account->retrieve('id', $this->accountId);
    }

    private function svc(): DraftService
    {
        return new DraftService($this->api);
    }

    public function testWriteCreatesDraftAndAccumulatesAcrossParts(): void
    {
        $svc = $this->svc();
        $S = DraftService::pageSurface();

        $first = $svc->writePart($S, $this->pageId, 'html', '<p>draft html</p>');
        $this->assertNotNull($first);
        $this->assertTrue($first['created'], 'first write creates the draft');

        $second = $svc->writePart($S, $this->pageId, 'javascript', "console.log('x');");
        $this->assertNotNull($second);
        $this->assertFalse($second['created'], 'second write reuses the same draft');
        $this->assertSame($first['draft_id'], $second['draft_id'], 'same draft id');

        // Exactly one open draft for the page.
        $rows = \Kyte\Core\DBI::query("SELECT COUNT(*) c FROM KytePageVersion WHERE page = {$this->pageId} AND draft = 1 AND deleted = 0");
        $this->assertSame(1, (int)$rows[0]['c']);

        $read = $svc->readDraft($S, $first['draft_id']);
        $this->assertNotNull($read);
        $this->assertSame('<p>draft html</p>', $read['content']['html']);
        $this->assertSame("console.log('x');", $read['content']['javascript']);
        $this->assertContains('html', $read['changed_parts']);
        $this->assertContains('javascript', $read['changed_parts']);
        // The stylesheet was never written, so it carries over from live and is unchanged.
        $this->assertNotContains('stylesheet', $read['changed_parts']);
    }

    public function testDraftDoesNotMutateLiveVersion(): void
    {
        $svc = $this->svc();
        $S = DraftService::pageSurface();

        $live = $svc->currentLiveContent($S, $this->pageId);
        $svc->writePart($S, $this->pageId, 'html', '<p>totally different</p>');
        $liveAfter = $svc->currentLiveContent($S, $this->pageId);

        $this->assertSame($live['html'], $liveAfter['html'], 'live html unchanged by drafting');
        $this->assertSame('<p>live html</p>', $liveAfter['html']);

        // The current version is still version 1.
        $cur = new \Kyte\Core\ModelObject(KytePageVersion);
        $this->assertTrue($cur->retrieve('page', $this->pageId, [['field' => 'is_current', 'value' => 1]]));
        $this->assertSame(1, (int)$cur->version_number);
    }

    public function testListDraftsAndDiscard(): void
    {
        $svc = $this->svc();
        $S = DraftService::pageSurface();

        $w = $svc->writePart($S, $this->pageId, 'html', '<p>draft</p>');
        $draftId = $w['draft_id'];

        $discard = $svc->discardDraft($S, $draftId);
        $this->assertNotNull($discard);
        $this->assertTrue($discard['discarded']);

        $rows = \Kyte\Core\DBI::query("SELECT COUNT(*) c FROM KytePageVersion WHERE page = {$this->pageId} AND draft = 1 AND deleted = 0");
        $this->assertSame(0, (int)$rows[0]['c'], 'no open drafts after discard');

        $read = $svc->readDraft($S, $draftId);
        $this->assertNull($read, 'discarded draft is not readable');
    }

    public function testCrossAccountWriteIsDenied(): void
    {
        $svc = $this->svc();
        $S = DraftService::pageSurface();

        // A page id that does not belong to this account.
        $other = new \Kyte\Core\ModelObject(KytePage);
        $other->create(['title' => 'Other', 'state' => 1, 'kyte_account' => $this->accountId + 99999]);

        $result = $svc->writePart($S, (int)$other->id, 'html', 'x');
        $this->assertNull($result, 'writing to a page outside the account is denied');
    }

    public function testFunctionSurfaceAuthoring(): void
    {
        \Kyte\Core\DBI::createTable(constant('Function'));
        \Kyte\Core\DBI::createTable(KyteFunctionVersion);
        \Kyte\Core\DBI::createTable(KyteFunctionVersionContent);

        $fn = new \Kyte\Core\ModelObject(constant('Function'));
        $fn->create([
            'name'         => 'al_draft_test_fn',
            'controller'   => 1,
            'code'         => bzcompress('echo 1;', 9),
            'kyte_account' => $this->accountId,
        ]);
        $fnId = (int)$fn->id;

        $hash = hash('sha256', 'echo 1;');
        (new \Kyte\Core\ModelObject(KyteFunctionVersionContent))->create([
            'content_hash' => $hash, 'code' => bzcompress('echo 1;', 9),
            'reference_count' => 1, 'last_referenced' => time(), 'kyte_account' => $this->accountId,
        ]);
        (new \Kyte\Core\ModelObject(KyteFunctionVersion))->create([
            'function' => $fnId, 'version_number' => 1, 'version_type' => 'initial',
            'content_hash' => $hash, 'is_current' => 1, 'draft' => 0, 'kyte_account' => $this->accountId,
        ]);

        $svc = $this->svc();
        $S = DraftService::functionSurface();

        $w = $svc->writePart($S, $fnId, 'code', 'echo 2;');
        $this->assertNotNull($w);
        $this->assertTrue($w['created']);

        $read = $svc->readDraft($S, $w['draft_id']);
        $this->assertSame('echo 2;', $read['content']['code']);
        $this->assertContains('code', $read['changed_parts']);

        // Live function code unchanged by drafting.
        $this->assertSame('echo 1;', $svc->currentLiveContent($S, $fnId)['code']);

        $svc->discardDraft($S, $w['draft_id']);
        $this->assertNull($svc->readDraft($S, $w['draft_id']));
    }

    public function testScriptSurfaceAuthoring(): void
    {
        \Kyte\Core\DBI::createTable(KyteScript);
        \Kyte\Core\DBI::createTable(KyteScriptVersion);
        \Kyte\Core\DBI::createTable(KyteScriptVersionContent);

        $sc = new \Kyte\Core\ModelObject(KyteScript);
        $sc->create([
            'name'         => 'al_draft_test_script',
            'site'         => 1,
            's3key'        => 'assets/js/al-draft-test.js',
            'content'      => bzcompress('let a = 1;', 9),
            'kyte_account' => $this->accountId,
        ]);
        $scId = (int)$sc->id;

        $hash = hash('sha256', 'let a = 1;');
        (new \Kyte\Core\ModelObject(KyteScriptVersionContent))->create([
            'content_hash' => $hash, 'content' => bzcompress('let a = 1;', 9),
            'reference_count' => 1, 'last_referenced' => time(), 'kyte_account' => $this->accountId,
        ]);
        (new \Kyte\Core\ModelObject(KyteScriptVersion))->create([
            'script' => $scId, 'version_number' => 1, 'version_type' => 'initial',
            'content_hash' => $hash, 'is_current' => 1, 'draft' => 0, 'kyte_account' => $this->accountId,
        ]);

        $svc = $this->svc();
        $S = DraftService::scriptSurface();

        $w = $svc->writePart($S, $scId, 'content', 'let a = 2;');
        $this->assertNotNull($w);
        $this->assertTrue($w['created']);

        $read = $svc->readDraft($S, $w['draft_id']);
        $this->assertSame('let a = 2;', $read['content']['content']);
        $this->assertContains('content', $read['changed_parts']);

        // Live script content unchanged by drafting.
        $this->assertSame('let a = 1;', $svc->currentLiveContent($S, $scId)['content']);

        $svc->discardDraft($S, $w['draft_id']);
        $this->assertNull($svc->readDraft($S, $w['draft_id']));
    }
}
