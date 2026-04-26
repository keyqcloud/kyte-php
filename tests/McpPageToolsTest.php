<?php
namespace Kyte\Test;

use Kyte\Core\Api;
use Kyte\Mcp\Tools\PageTools;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PageTools (list_sites / list_pages / read_page).
 *
 * Pages live two FK hops from the account (site → application →
 * account on the metadata side; page → site on the relationship side
 * with kyte_account stamped on each row). Cross-account isolation has
 * to hold at every link — these tests prove a token can't reach
 * another account's data via any of the three tools.
 *
 * read_page is exercised across all four content paths:
 *   - default-version (returns is_current=1)
 *   - explicit version_number (returns the named snapshot)
 *   - default-version when no current version exists yet (returns
 *     metadata + empty content, page still discoverable)
 *   - explicit version_number that doesn't exist (returns null)
 */
class McpPageToolsTest extends TestCase
{
    private const OWN_ACCOUNT   = 'mcp-page-test-own';
    private const OTHER_ACCOUNT = 'mcp-page-test-other';

    private Api $api;
    private PageTools $tools;
    private int $ownAccountId;
    private int $otherAccountId;
    private int $ownAppId;
    private int $otherAppId;
    private int $ownSiteId;
    private int $otherSiteId;
    private int $ownPageId;
    private int $otherPageId;
    private int $ownPageNoVersionsId;

    protected function setUp(): void
    {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');

        $this->api = new Api();

        \Kyte\Core\DBI::createTable(KyteAccount);
        \Kyte\Core\DBI::createTable(Application);
        \Kyte\Core\DBI::createTable(KyteSite);
        \Kyte\Core\DBI::createTable(KytePage);
        \Kyte\Core\DBI::createTable(KytePageVersion);
        \Kyte\Core\DBI::createTable(KytePageVersionContent);

        \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number IN ('" . self::OWN_ACCOUNT . "','" . self::OTHER_ACCOUNT . "')");
        \Kyte\Core\DBI::query("DELETE FROM `Application` WHERE identifier LIKE 'mcp-page-test-%'");
        \Kyte\Core\DBI::query("DELETE FROM `KyteSite` WHERE name LIKE 'McpPageTest%'");
        \Kyte\Core\DBI::query("DELETE FROM `KytePage` WHERE title LIKE 'McpPageTest%'");
        \Kyte\Core\DBI::query("DELETE FROM `KytePageVersion` WHERE content_hash LIKE 'mcp-page-test-%'");
        \Kyte\Core\DBI::query("DELETE FROM `KytePageVersionContent` WHERE content_hash LIKE 'mcp-page-test-%'");

        $this->ownAccountId   = $this->createAccount(self::OWN_ACCOUNT,   'Own');
        $this->otherAccountId = $this->createAccount(self::OTHER_ACCOUNT, 'Other');

        $this->ownAppId   = $this->createApp('mcp-page-test-own',   $this->ownAccountId);
        $this->otherAppId = $this->createApp('mcp-page-test-other', $this->otherAccountId);

        $this->ownSiteId   = $this->createSite('McpPageTestOwnSite',   $this->ownAppId,   $this->ownAccountId);
        $this->otherSiteId = $this->createSite('McpPageTestOtherSite', $this->otherAppId, $this->otherAccountId);

        $this->ownPageId   = $this->createPage('McpPageTestOwnHome',   $this->ownSiteId,   $this->ownAccountId);
        $this->otherPageId = $this->createPage('McpPageTestOtherHome', $this->otherSiteId, $this->otherAccountId);

        // Page that exists but has no versions yet — exercises the
        // metadata-with-empty-content path.
        $this->ownPageNoVersionsId = $this->createPage('McpPageTestOwnFresh', $this->ownSiteId, $this->ownAccountId);

        // Two versions on the own page: v1 (older) and v2 (current).
        $this->createPageVersion(
            $this->ownPageId,
            $this->ownAccountId,
            1,
            'mcp-page-test-own-v1',
            ['html' => '<h1>v1 own</h1>', 'stylesheet' => 'body{color:red}', 'javascript' => 'console.log("v1");'],
            isCurrent: false
        );
        $this->createPageVersion(
            $this->ownPageId,
            $this->ownAccountId,
            2,
            'mcp-page-test-own-v2',
            ['html' => '<h1>v2 own</h1>', 'stylesheet' => 'body{color:blue}', 'javascript' => 'console.log("v2");'],
            isCurrent: true
        );

        $this->api->account = new \Kyte\Core\ModelObject(KyteAccount);
        $this->api->account->retrieve('id', $this->ownAccountId);
        $this->api->mcpScopes = ['read'];

        $this->tools = new PageTools($this->api);

        $_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];
    }

    public function testListSitesReturnsSitesForOwnApp(): void
    {
        $rows = $this->tools->listSites($this->ownAppId);
        $this->assertCount(1, $rows);
        $this->assertSame('McpPageTestOwnSite', $rows[0]['name']);
    }

    public function testListSitesRejectsForeignApplicationId(): void
    {
        $this->assertSame([], $this->tools->listSites($this->otherAppId));
    }

    public function testListPagesReturnsPagesOnOwnSite(): void
    {
        $rows = $this->tools->listPages($this->ownSiteId);
        $this->assertCount(2, $rows, 'Should include own page and the no-versions page');
        $titles = array_column($rows, 'title');
        $this->assertContains('McpPageTestOwnHome', $titles);
        $this->assertContains('McpPageTestOwnFresh', $titles);
    }

    public function testListPagesRejectsForeignSiteId(): void
    {
        $this->assertSame([], $this->tools->listPages($this->otherSiteId), 'Foreign site_id must not return its pages');
    }

    public function testReadPageWithoutVersionReturnsCurrentContent(): void
    {
        $row = $this->tools->readPage($this->ownPageId);

        $this->assertNotNull($row);
        $this->assertSame(2, $row['version'], 'Default read should return is_current=1, which is v2 here');
        $this->assertSame('<h1>v2 own</h1>', $row['html']);
        $this->assertSame('body{color:blue}', $row['stylesheet']);
        $this->assertSame('console.log("v2");', $row['javascript']);
    }

    public function testReadPageWithVersionReturnsHistoricalSnapshot(): void
    {
        $row = $this->tools->readPage($this->ownPageId, 1);

        $this->assertNotNull($row);
        $this->assertSame(1, $row['version']);
        $this->assertSame('<h1>v1 own</h1>', $row['html']);
        $this->assertSame('body{color:red}', $row['stylesheet']);
    }

    public function testReadPageWithUnknownVersionReturnsNull(): void
    {
        $this->assertNull($this->tools->readPage($this->ownPageId, 999));
    }

    public function testReadPageReturnsEmptyContentWhenNoVersionsExist(): void
    {
        $row = $this->tools->readPage($this->ownPageNoVersionsId);

        $this->assertNotNull($row, 'Page exists, just has no current version yet — must still be discoverable');
        $this->assertSame('McpPageTestOwnFresh', $row['title']);
        $this->assertSame('', $row['html']);
        $this->assertSame('', $row['stylesheet']);
        $this->assertSame('', $row['javascript']);
        $this->assertNull($row['version']);
    }

    public function testReadPageRejectsForeignPageId(): void
    {
        $this->assertNull($this->tools->readPage($this->otherPageId));
    }

    private function createAccount(string $number, string $name): int
    {
        $obj = new \Kyte\Core\ModelObject(KyteAccount);
        $obj->create(['number' => $number, 'name' => $name]);
        return (int)$obj->id;
    }

    private function createApp(string $identifier, int $accountId): int
    {
        $obj = new \Kyte\Core\ModelObject(Application);
        $obj->create([
            'name'         => 'App ' . $identifier,
            'identifier'   => $identifier,
            'kyte_account' => $accountId,
        ]);
        return (int)$obj->id;
    }

    private function createSite(string $name, int $appId, int $accountId): int
    {
        $obj = new \Kyte\Core\ModelObject(KyteSite);
        $obj->create([
            'name'         => $name,
            'status'       => 'active',
            'application'  => $appId,
            'kyte_account' => $accountId,
        ]);
        return (int)$obj->id;
    }

    private function createPage(string $title, int $siteId, int $accountId): int
    {
        $obj = new \Kyte\Core\ModelObject(KytePage);
        $obj->create([
            'title'        => $title,
            's3key'        => strtolower(str_replace(' ', '-', $title)) . '.html',
            'site'         => $siteId,
            'kyte_account' => $accountId,
        ]);
        return (int)$obj->id;
    }

    private function createPageVersion(int $pageId, int $accountId, int $versionNumber, string $contentHash, array $content, bool $isCurrent): void
    {
        $contentRow = new \Kyte\Core\ModelObject(KytePageVersionContent);
        $contentRow->create([
            'content_hash'    => $contentHash,
            'html'            => $content['html'] ?? '',
            'stylesheet'      => $content['stylesheet'] ?? '',
            'javascript'      => $content['javascript'] ?? '',
            'reference_count' => 1,
            'last_referenced' => time(),
            'kyte_account'    => $accountId,
        ]);

        $version = new \Kyte\Core\ModelObject(KytePageVersion);
        $version->create([
            'page'           => $pageId,
            'version_number' => $versionNumber,
            'version_type'   => 'manual_save',
            'content_hash'   => $contentHash,
            'is_current'     => $isCurrent ? 1 : 0,
            'kyte_account'   => $accountId,
        ]);
    }
}
