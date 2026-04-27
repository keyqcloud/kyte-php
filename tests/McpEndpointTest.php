<?php
namespace Kyte\Test;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Integration tests for Kyte\Mcp\Endpoint.
 *
 * Drives the endpoint with crafted PSR-7 requests instead of going through
 * SapiEmitter. The pure process() method returns a PSR-7 response we can
 * inspect — no header capture, no stdout buffering.
 *
 * The full multi-step MCP handshake (initialize → notifications/initialized
 * → tools/list → tools/call) was already verified end-to-end on dev against
 * a real PHP HTTP server during the SDK evaluation. These tests prove the
 * Kyte-side wiring: auth, account scoping, server bootstrap, error envelope.
 */
class McpEndpointTest extends TestCase
{
    private const RAW_TOKEN     = 'kmcp_live_endpoint_test_xyz1234567890';
    private const FIXED_ACCOUNT = 'test-account-mcp-endpoint';
    private const APP_IDENT     = 'mcp-endpoint-test-app';

    /** @var \Kyte\Core\Api */
    private $api;

    /** @var Psr17Factory */
    private $psr17;

    /** @var int */
    private $accountId;

    protected function setUp(): void
    {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');

        $this->api = new \Kyte\Core\Api();
        $this->psr17 = new Psr17Factory();

        \Kyte\Core\DBI::createTable(KyteAccount);
        \Kyte\Core\DBI::createTable(KyteMCPToken);
        \Kyte\Core\DBI::createTable(Application);

        \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number = '" . self::FIXED_ACCOUNT . "'");
        \Kyte\Core\DBI::query("DELETE FROM `KyteMCPToken` WHERE token_prefix LIKE 'kmcp_live_%'");
        \Kyte\Core\DBI::query("DELETE FROM `Application` WHERE identifier = '" . self::APP_IDENT . "'");

        $account = new \Kyte\Core\ModelObject(KyteAccount);
        $account->create([
            'name'   => 'MCP Endpoint Test Account',
            'number' => self::FIXED_ACCOUNT,
        ]);
        $this->accountId = $account->id;

        $token = new \Kyte\Core\ModelObject(KyteMCPToken);
        $token->create([
            'token_hash'   => hash('sha256', self::RAW_TOKEN),
            'token_prefix' => substr(self::RAW_TOKEN, 0, 16),
            'name'         => 'Endpoint test token',
            'scopes'       => 'read,draft',
            'expires_at'   => time() + 3600,
            'revoked_at'   => 0,
            'kyte_account' => $this->accountId,
        ]);

        $app = new \Kyte\Core\ModelObject(Application);
        $app->create([
            'name'         => 'Endpoint Test App',
            'identifier'   => self::APP_IDENT,
            'kyte_account' => $this->accountId,
        ]);

        $this->api->key = new \Kyte\Core\ModelObject(KyteAPIKey);
        $this->api->account = new \Kyte\Core\ModelObject(KyteAccount);

        $_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];

        // Each test starts with a clean MCP session directory. The SDK's
        // session machinery rejects a second initialize for the same client
        // when prior session files linger; wiping in setUp avoids
        // cross-test interference now that multiple tests do a real
        // initialize round-trip (the Origin tests added below).
        $sessionDir = sys_get_temp_dir() . '/kyte-mcp-sessions';
        if (is_dir($sessionDir)) {
            foreach (glob($sessionDir . '/*') as $f) {
                @unlink($f);
            }
        }
    }

    private function rpcRequest(string $authHeader, array $body): ServerRequestInterface
    {
        $json = json_encode($body);

        // Mirror the header into $_SERVER so McpTokenStrategy's $_SERVER read
        // sees the same value as the PSR-7 request. In production, both come
        // from the same SAPI; in tests we set them in lockstep.
        if ($authHeader !== '') {
            $_SERVER['HTTP_AUTHORIZATION'] = $authHeader;
        } else {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        }

        return $this->psr17->createServerRequest('POST', '/mcp')
            ->withHeader('Authorization', $authHeader)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody($this->psr17->createStream($json));
    }

    public function testRequestWithoutOriginPasses(): void
    {
        // CLI clients (Claude Code, curl) don't send Origin. We must not
        // require it — see Endpoint::checkOrigin docblock for the full
        // rationale. This is the canonical happy-path; the existing
        // initialize tests below also exercise it transitively.
        $request = $this->rpcRequest('Bearer ' . self::RAW_TOKEN, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'no-origin', 'version' => '0.0.1']],
        ]);

        $response = \Kyte\Mcp\Endpoint::process($this->api, $request);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRequestWithUnknownOriginRejectedWith403(): void
    {
        // Default state: MCP_ALLOWED_ORIGINS undefined → empty allowlist →
        // every browser Origin gets denied.
        $request = $this->rpcRequest('Bearer ' . self::RAW_TOKEN, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'malicious', 'version' => '0.0.1']],
        ])->withHeader('Origin', 'https://attacker.example');

        $response = \Kyte\Mcp\Endpoint::process($this->api, $request);

        $this->assertSame(403, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        $this->assertSame(-32011, $payload['error']['code']);
        $this->assertStringContainsString('attacker.example', $payload['error']['message']);
    }

    public function testRequestWithAllowlistedOriginPasses(): void
    {
        // Define the constant for this test only. PHP doesn't allow undefining
        // constants, so subsequent tests in the same run will see this allowlist
        // — but each test that cares either sets a non-overlapping origin or
        // sends no Origin at all, so there's no cross-test interference.
        if (!defined('MCP_ALLOWED_ORIGINS')) {
            define('MCP_ALLOWED_ORIGINS', 'https://claude.ai,https://app.example.com');
        }

        $request = $this->rpcRequest('Bearer ' . self::RAW_TOKEN, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'browser', 'version' => '0.0.1']],
        ])->withHeader('Origin', 'https://claude.ai');

        $response = \Kyte\Mcp\Endpoint::process($this->api, $request);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testInitializeReturnsServerCapabilitiesAndSessionId(): void
    {
        $request = $this->rpcRequest('Bearer ' . self::RAW_TOKEN, [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
            'params'  => [
                'protocolVersion' => '2025-11-25',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => ['name' => 'phpunit', 'version' => '0.0.1'],
            ],
        ]);

        $response = \Kyte\Mcp\Endpoint::process($this->api, $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($response->getHeaderLine('Mcp-Session-Id'));

        $payload = json_decode((string)$response->getBody(), true);
        $this->assertSame('2.0', $payload['jsonrpc']);
        $this->assertSame(1, $payload['id']);
        $this->assertArrayHasKey('result', $payload);
        $this->assertArrayHasKey('tools', $payload['result']['capabilities']);
        $this->assertSame('Kyte MCP', $payload['result']['serverInfo']['name']);
    }

    public function testInitializeAlsoPopulatesApiAccountFromToken(): void
    {
        $request = $this->rpcRequest('Bearer ' . self::RAW_TOKEN, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'phpunit', 'version' => '0.0.1'],
            ],
        ]);

        \Kyte\Mcp\Endpoint::process($this->api, $request);

        $this->assertSame(self::FIXED_ACCOUNT, $this->api->account->number);
        $this->assertNotNull($this->api->mcpToken);
        $this->assertSame(['read', 'draft'], $this->api->mcpScopes);
    }

    public function testMissingAuthorizationHeaderReturns401WithJsonRpcError(): void
    {
        $request = $this->rpcRequest('', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'phpunit', 'version' => '0.0.1']],
        ]);

        $response = \Kyte\Mcp\Endpoint::process($this->api, $request);

        $this->assertSame(401, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        $this->assertSame('2.0', $payload['jsonrpc']);
        $this->assertArrayHasKey('error', $payload);
        $this->assertSame(-32001, $payload['error']['code']);
        $this->assertStringContainsString('Authorization', $payload['error']['message']);
    }

    public function testInvalidTokenReturns401(): void
    {
        $request = $this->rpcRequest('Bearer kmcp_live_does_not_exist_abc123xyz', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'phpunit', 'version' => '0.0.1']],
        ]);

        $response = \Kyte\Mcp\Endpoint::process($this->api, $request);

        $this->assertSame(401, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        $this->assertStringContainsString('Invalid MCP token', $payload['error']['message']);
    }

    public function testNonMcpBearerTokenReturns401(): void
    {
        // A JWT-shaped bearer should be rejected by /mcp because no other
        // strategy claims it for now.
        $request = $this->rpcRequest('Bearer eyJhbGciOiJSUzI1NiJ9.payload.sig', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'phpunit', 'version' => '0.0.1']],
        ]);

        $response = \Kyte\Mcp\Endpoint::process($this->api, $request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testListApplicationsReturnsEmptyForUnknownAccount(): void
    {
        $tools = new \Kyte\Mcp\Tools\AccountTools($this->api);
        // $api->account is the empty ModelObject from setUp — id is unset
        $this->assertSame(['applications' => []], $tools->listApplications());
    }

    public function testListApplicationsToolReturnsAccountScopedApps(): void
    {
        // Populate $api->account as McpTokenStrategy::preAuth would in a real
        // request, then drive AccountTools directly. Equivalent to the
        // tool-dispatch path the SDK takes after initialize → tools/call, but
        // skips the multi-step session handshake — that was already verified
        // end-to-end during the SDK evaluation on dev.
        $this->api->account->retrieve('id', $this->accountId);

        $tools = new \Kyte\Mcp\Tools\AccountTools($this->api);
        $result = $tools->listApplications();
        $apps = $result['applications'];

        $this->assertCount(1, $apps);
        $this->assertSame(self::APP_IDENT, $apps[0]['identifier']);
        $this->assertSame('Endpoint Test App', $apps[0]['name']);
        $this->assertIsInt($apps[0]['id']);
    }
}
