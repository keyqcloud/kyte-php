<?php
namespace Kyte\Test;

use Kyte\Core\Api;
use Kyte\Core\Auth\AppContextStrategy;
use Kyte\Exception\SessionException;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for AppContextStrategy (JWT-mode anonymous/public access).
 *
 * matches() is a pure header truth-table. preAuth() must resolve the account
 * from the app but NEVER resolve a user or set hasSession (the security
 * invariant that keeps requireAuth=true controllers at 403), and must enforce
 * the per-app allow_public opt-in.
 */
class AppContextStrategyTest extends TestCase
{
    private function clearAuthHeaders(): void
    {
        unset(
            $_SERVER['HTTP_X_KYTE_APPID'],
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
            $_SERVER['HTTP_X_KYTE_SIGNATURE'],
            $_SERVER['HTTP_X_KYTE_IDENTITY']
        );
    }

    protected function setUp(): void
    {
        $this->clearAuthHeaders();
    }

    protected function tearDown(): void
    {
        $this->clearAuthHeaders();
    }

    // ---------- matches() truth table ----------

    public function testMatchesWhenOnlyAppidPresent(): void
    {
        $_SERVER['HTTP_X_KYTE_APPID'] = 'appctx-id';
        $this->assertTrue((new AppContextStrategy())->matches());
    }

    public function testNoMatchWithoutAppid(): void
    {
        $this->assertFalse((new AppContextStrategy())->matches());
    }

    public function testNoMatchWithBearer(): void
    {
        $_SERVER['HTTP_X_KYTE_APPID'] = 'appctx-id';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer eyJhbGciOiJIUzI1NiJ9.e30.sig';
        $this->assertFalse((new AppContextStrategy())->matches(), 'must not shadow a Bearer (JWT/MCP)');
    }

    public function testNoMatchWithSignature(): void
    {
        $_SERVER['HTTP_X_KYTE_APPID'] = 'appctx-id';
        $_SERVER['HTTP_X_KYTE_SIGNATURE'] = 'deadbeef';
        $this->assertFalse((new AppContextStrategy())->matches(), 'must not shadow signed HMAC');
    }

    public function testNoMatchWithIdentity(): void
    {
        $_SERVER['HTTP_X_KYTE_APPID'] = 'appctx-id';
        $_SERVER['HTTP_X_KYTE_IDENTITY'] = 'c29tZS1pZGVudGl0eQ==';
        $this->assertFalse((new AppContextStrategy())->matches(), 'must not shadow HMAC identity');
    }

    // ---------- preAuth() ----------

    private function makeApi(): Api
    {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');
        $api = new Api();
        \Kyte\Core\DBI::createTable(KyteAccount);
        \Kyte\Core\DBI::createTable(Application);
        $api->response = [];
        $api->user = null;
        $api->session = new \stdClass();
        $api->session->hasSession = false;
        return $api;
    }

    private function makeApp(int $accountId, int $allowPublic): \Kyte\Core\ModelObject
    {
        $app = new \Kyte\Core\ModelObject(Application);
        $app->create([
            'name'         => 'AppCtx Test App',
            'identifier'   => 'appctx-' . uniqid(),
            'allow_public' => $allowPublic,
            'kyte_account' => $accountId,
        ]);
        return $app;
    }

    public function testPreAuthResolvesAccountButNoUserOrSession(): void
    {
        $api = $this->makeApi();
        $acct = new \Kyte\Core\ModelObject(KyteAccount);
        $acct->create(['number' => 'appctx-' . uniqid(), 'name' => 'AppCtx Test']);
        $app = $this->makeApp((int)$acct->id, 1);
        $api->app = $app;
        $api->appId = $app->identifier;

        (new AppContextStrategy())->preAuth($api);

        $this->assertSame((int)$acct->id, (int)$api->account->id, 'account resolved from the app');
        $this->assertNull($api->user, 'no user is resolved (anonymous)');
        $this->assertFalse($api->session->hasSession, 'hasSession is never set — the security invariant');
        $this->assertSame('0', $api->response['session']);
        $this->assertSame('0', $api->response['uid']);
    }

    public function testPreAuthRejectsWhenNotOptedIn(): void
    {
        $api = $this->makeApi();
        $acct = new \Kyte\Core\ModelObject(KyteAccount);
        $acct->create(['number' => 'appctx-' . uniqid(), 'name' => 'AppCtx Test']);
        $app = $this->makeApp((int)$acct->id, 0); // allow_public = 0
        $api->app = $app;
        $api->appId = $app->identifier;

        $this->expectException(SessionException::class);
        (new AppContextStrategy())->preAuth($api);
    }

    public function testPreAuthRejectsWithoutApp(): void
    {
        $api = $this->makeApi();
        $api->app = null;
        $api->appId = null;

        $this->expectException(SessionException::class);
        (new AppContextStrategy())->preAuth($api);
    }
}
