<?php
namespace Kyte\Test;

use Kyte\Core\Api;
use Kyte\Mcp\Tools\AppTools;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AppTools' anonymous-access surface: set_app_anonymous_access
 * (the `provision`-scoped setter for Application.allow_public) and read_application's
 * exposure of allow_public + auth_mode.
 *
 * allow_public tri-state (Application struct): 0 none, 1 read-only (GET), 2
 * controller-governed (anonymous writes where a controller sets requireAuth=false)
 * — level 2 is what public signup needs. The setter must validate the level and
 * re-scope application_id to the token's account (a token cannot flip another
 * account's app open to anonymous access).
 */
class McpAppToolsTest extends TestCase
{
    private const OWN_ACCOUNT   = 'mcp-app-test-own';
    private const OTHER_ACCOUNT = 'mcp-app-test-other';

    private Api $api;
    private AppTools $tools;
    private int $ownAccountId;
    private int $otherAccountId;
    private int $ownAppId;
    private int $otherAppId;

    protected function setUp(): void
    {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');

        $this->api = new Api();

        foreach ([KyteAccount, Application, KyteAPIKey] as $model) {
            \Kyte\Core\DBI::createTable($model);
        }

        \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number IN ('" . self::OWN_ACCOUNT . "','" . self::OTHER_ACCOUNT . "')");
        \Kyte\Core\DBI::query("DELETE FROM `Application` WHERE identifier LIKE 'mcp-app-test-%'");

        $this->ownAccountId   = $this->createAccount(self::OWN_ACCOUNT,   'Own');
        $this->otherAccountId = $this->createAccount(self::OTHER_ACCOUNT, 'Other');

        $this->ownAppId   = $this->createApp('mcp-app-test-own',   $this->ownAccountId);
        $this->otherAppId = $this->createApp('mcp-app-test-other', $this->otherAccountId);

        $this->api->account = new \Kyte\Core\ModelObject(KyteAccount);
        $this->api->account->retrieve('id', $this->ownAccountId);
        $this->api->mcpScopes = ['read', 'provision'];

        $this->tools = new AppTools($this->api);

        $_SERVER = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'test.local'];
    }

    public function testDefaultAnonymousAccessIsNone(): void
    {
        $app = $this->tools->readApplication($this->ownAppId);
        $this->assertNotNull($app);
        $this->assertSame(0, $app['allow_public'], 'a fresh app defaults to no anonymous access');
        $this->assertSame('hmac', $app['auth_mode'], 'auth_mode defaults to hmac');
    }

    public function testSetAnonymousAccessControllerGoverned(): void
    {
        $result = $this->tools->setAppAnonymousAccess($this->ownAppId, 2);
        $this->assertTrue($result['updated'], $result['error'] ?? 'set failed');
        $this->assertSame(2, $result['allow_public']);

        $app = $this->tools->readApplication($this->ownAppId);
        $this->assertSame(2, $app['allow_public'], 'read_application reflects the new level');
    }

    public function testSetAnonymousAccessReadOnlyThenNone(): void
    {
        $this->tools->setAppAnonymousAccess($this->ownAppId, 1);
        $this->assertSame(1, $this->tools->readApplication($this->ownAppId)['allow_public']);

        $this->tools->setAppAnonymousAccess($this->ownAppId, 0);
        $this->assertSame(0, $this->tools->readApplication($this->ownAppId)['allow_public']);
    }

    public function testSetAnonymousAccessRejectsInvalidLevel(): void
    {
        foreach ([3, -1, 99] as $bad) {
            $result = $this->tools->setAppAnonymousAccess($this->ownAppId, $bad);
            $this->assertFalse($result['updated'], "level {$bad} must be rejected");
        }
        $this->assertSame(0, $this->tools->readApplication($this->ownAppId)['allow_public'], 'rejected levels leave the app unchanged');
    }

    public function testSetAnonymousAccessRejectsForeignApp(): void
    {
        $result = $this->tools->setAppAnonymousAccess($this->otherAppId, 2);
        $this->assertFalse($result['updated'], 'cannot open another account\'s app to anonymous access');

        // And the foreign app is untouched.
        $other = new \Kyte\Core\ModelObject(Application);
        $other->retrieve('id', $this->otherAppId);
        $this->assertSame(0, (int)$other->allow_public, 'foreign app stays at level 0');
    }

    public function testReadApplicationRejectsForeignApp(): void
    {
        $this->assertNull($this->tools->readApplication($this->otherAppId), 'a foreign app_id must not be readable');
    }

    public function testDefaultAuthModeIsHmac(): void
    {
        $this->assertSame('hmac', $this->tools->readApplication($this->ownAppId)['auth_mode']);
    }

    public function testSetAuthModeToJwtRegeneratesBootstrap(): void
    {
        $result = $this->tools->setAppAuthMode($this->ownAppId, 'jwt');
        $this->assertTrue($result['updated'], $result['error'] ?? 'set failed');
        $this->assertSame('jwt', $result['auth_mode']);
        $this->assertSame('jwt', $this->tools->readApplication($this->ownAppId)['auth_mode']);

        $app = new \Kyte\Core\ModelObject(Application);
        $app->retrieve('id', $this->ownAppId);
        $this->assertStringContainsString("authMode: 'jwt'", (string)$app->kyte_connect, 'the injected bootstrap must be regenerated for JWT');
    }

    public function testSetAuthModeBackToHmac(): void
    {
        $this->tools->setAppAuthMode($this->ownAppId, 'jwt');
        $this->tools->setAppAuthMode($this->ownAppId, 'hmac');
        $this->assertSame('hmac', $this->tools->readApplication($this->ownAppId)['auth_mode']);
    }

    public function testSetAuthModeRejectsInvalidMode(): void
    {
        $result = $this->tools->setAppAuthMode($this->ownAppId, 'saml');
        $this->assertFalse($result['updated'], 'only hmac/jwt are valid');
        $this->assertSame('hmac', $this->tools->readApplication($this->ownAppId)['auth_mode'], 'a rejected mode leaves the app unchanged');
    }

    public function testSetAuthModeRejectsForeignApp(): void
    {
        $result = $this->tools->setAppAuthMode($this->otherAppId, 'jwt');
        $this->assertFalse($result['updated'], 'cannot change another account\'s app');
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
}
