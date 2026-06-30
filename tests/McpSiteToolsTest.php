<?php
namespace Kyte\Test;

use Kyte\Core\Api;
use Kyte\Mcp\Tools\SiteTools;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SiteTools (create_site / read_site / update_site / delete_site,
 * the MCP `provision`-scope site lifecycle tools).
 *
 * These exercise the tools at the row level only — the actual AWS provisioning
 * runs in SiteProvisioningWorker, so here `create_site` lands a KyteSite in
 * status "creating" and `delete_site` flips it to "deleting"; no S3/CloudFront.
 *
 * The security property under test is account isolation: every caller-supplied
 * application_id / site_id must be re-scoped to the token's account, so a token
 * cannot create under, read, update, or delete another account's site.
 */
class McpSiteToolsTest extends TestCase
{
    private const OWN_ACCOUNT   = 'mcp-site-test-own';
    private const OTHER_ACCOUNT = 'mcp-site-test-other';

    private Api $api;
    private SiteTools $tools;
    private int $ownAccountId;
    private int $otherAccountId;
    private int $ownAppId;
    private int $otherAppId;
    private int $otherSiteId;

    protected function setUp(): void
    {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');

        $this->api = new Api();

        // KyteSite + the child tables KyteSiteController::delete() cascades into.
        foreach ([
            KyteAccount, Application, KyteSite,
            KytePage, KytePageData, Media,
            Navigation, NavigationItem, SideNav, SideNavItem,
            KyteSectionTemplate, KyteLibrary, KyteScript,
        ] as $model) {
            \Kyte\Core\DBI::createTable($model);
        }

        \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number IN ('" . self::OWN_ACCOUNT . "','" . self::OTHER_ACCOUNT . "')");
        \Kyte\Core\DBI::query("DELETE FROM `Application` WHERE identifier LIKE 'mcp-site-test-%'");
        \Kyte\Core\DBI::query("DELETE FROM `KyteSite` WHERE name LIKE 'McpSiteTest%'");

        $this->ownAccountId   = $this->createAccount(self::OWN_ACCOUNT,   'Own');
        $this->otherAccountId = $this->createAccount(self::OTHER_ACCOUNT, 'Other');

        $this->ownAppId   = $this->createApp('mcp-site-test-own',   $this->ownAccountId);
        $this->otherAppId = $this->createApp('mcp-site-test-other', $this->otherAccountId);

        // A site owned by the OTHER account, for cross-account rejection tests.
        $this->otherSiteId = $this->createSite('McpSiteTestOther', $this->otherAppId, $this->otherAccountId);

        $this->api->account = new \Kyte\Core\ModelObject(KyteAccount);
        $this->api->account->retrieve('id', $this->ownAccountId);
        $this->api->mcpScopes = ['read', 'provision'];

        $this->tools = new SiteTools($this->api);

        $_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];
    }

    public function testCreateSiteLandsInCreatingStatus(): void
    {
        $result = $this->tools->createSite($this->ownAppId, 'McpSiteTestNew', 'us-east-1');

        $this->assertTrue($result['created'], $result['error'] ?? 'create failed');
        $this->assertSame('McpSiteTestNew', $result['site']['name']);
        $this->assertSame('creating', $result['site']['status'], 'a new site starts at "creating" for the worker to pick up');
        $this->assertSame('us-east-1', $result['site']['region']);
    }

    public function testCreateSiteScopesToCallerAccount(): void
    {
        $result = $this->tools->createSite($this->ownAppId, 'McpSiteTestScoped', 'us-east-2');
        $this->assertTrue($result['created']);

        $site = new \Kyte\Core\ModelObject(KyteSite);
        $site->retrieve('id', (int)$result['site']['id']);
        $this->assertSame($this->ownAccountId, (int)$site->kyte_account, 'new site must be stamped with the caller account');
    }

    public function testCreateSiteRejectsForeignApplication(): void
    {
        $result = $this->tools->createSite($this->otherAppId, 'McpSiteTestEvil', 'us-east-1');
        $this->assertFalse($result['created'], 'cannot create a site under another account\'s application');
    }

    public function testCreateSiteRejectsInvalidRegion(): void
    {
        $result = $this->tools->createSite($this->ownAppId, 'McpSiteTestBadRegion', 'moon-base-1');
        $this->assertFalse($result['created'], 'an invalid AWS region is rejected by the controller');
    }

    public function testReadSiteReturnsOwnSite(): void
    {
        $created = $this->tools->createSite($this->ownAppId, 'McpSiteTestRead', 'us-east-1');
        $id = (int)$created['site']['id'];

        $read = $this->tools->readSite($id);
        $this->assertNotNull($read['site']);
        $this->assertSame('McpSiteTestRead', $read['site']['name']);
        $this->assertSame('creating', $read['site']['status']);
    }

    public function testReadSiteRejectsForeignSite(): void
    {
        $this->assertNull($this->tools->readSite($this->otherSiteId)['site'], 'a foreign site_id must not be readable');
    }

    public function testUpdateSiteUpdatesSettings(): void
    {
        $created = $this->tools->createSite($this->ownAppId, 'McpSiteTestUpd', 'us-east-1');
        $id = (int)$created['site']['id'];

        $result = $this->tools->updateSite($id, description: 'A demo site', default_lang: 'en', ga_code: 'G-TEST', gtm_code: null);
        $this->assertTrue($result['updated'], $result['error'] ?? 'update failed');
        $this->assertSame('A demo site', $result['site']['description']);
        $this->assertSame('en', $result['site']['default_lang']);
        $this->assertSame('G-TEST', $result['site']['ga_code']);
    }

    public function testUpdateSiteRejectsForeignSite(): void
    {
        $result = $this->tools->updateSite($this->otherSiteId, description: 'hijack');
        $this->assertFalse($result['updated'], 'cannot update a foreign site');
    }

    public function testUpdateSiteRejectsEmptyPayload(): void
    {
        $created = $this->tools->createSite($this->ownAppId, 'McpSiteTestNoop', 'us-east-1');
        $result = $this->tools->updateSite((int)$created['site']['id']);
        $this->assertFalse($result['updated'], 'no updatable fields provided');
    }

    public function testDeleteSiteFlipsToDeleting(): void
    {
        $created = $this->tools->createSite($this->ownAppId, 'McpSiteTestDel', 'us-east-1');
        $id = (int)$created['site']['id'];

        $result = $this->tools->deleteSite($id);
        $this->assertTrue($result['deleting'], $result['error'] ?? 'delete failed');

        // The worker hasn't run; the row should now be flagged for teardown.
        $read = $this->tools->readSite($id);
        $this->assertSame('deleting', $read['site']['status']);
    }

    public function testDeleteSiteRejectsForeignSite(): void
    {
        $result = $this->tools->deleteSite($this->otherSiteId);
        $this->assertFalse($result['deleting'], 'cannot delete a foreign site');

        // And the foreign site is untouched (still active, not "deleting").
        $other = new \Kyte\Core\ModelObject(KyteSite);
        $other->retrieve('id', $this->otherSiteId);
        $this->assertSame('active', (string)$other->status);
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
}
