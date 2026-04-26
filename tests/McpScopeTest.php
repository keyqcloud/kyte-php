<?php
namespace Kyte\Test;

use Kyte\Core\Api;
use Kyte\Mcp\ScopeRegistry;
use Kyte\Mcp\ScopedCallToolHandler;
use Mcp\Capability\Registry as McpRegistry;
use Mcp\Capability\Registry\Container as McpContainer;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\SessionFactory;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the scope-enforcement layer in front of the MCP tool
 * dispatcher. Drives ScopedCallToolHandler directly with a fully-built
 * SDK Registry so the dispatch path matches production exactly, but
 * skips the multi-step initialize/notifications/tools-call handshake —
 * that's exercised by the integration tests in McpEndpointTest.
 *
 * Test matrix:
 *   - read-scoped token + read-tagged tool → passes through to inner handler
 *   - draft-only token + read-tagged tool → -32010 + audit row
 *   - tool with no #[RequiresScope] declared → -32010 + audit row (fail-closed)
 *   - tool registered via the SDK's auto-discovery path is reachable to
 *     the scope registry by its discovered name (smoke check that our
 *     reflection lookup matches the registry's keying)
 */
class McpScopeTest extends TestCase
{
    private const RAW_TOKEN     = 'kmcp_live_scope_test_abc1234567890';
    private const FIXED_ACCOUNT = 'test-account-mcp-scope';
    private const APP_IDENT     = 'mcp-scope-test-app';

    private Api $api;
    private McpRegistry $registry;
    private ScopedCallToolHandler $scopedHandler;
    private SessionInterface $session;
    private int $accountId;
    private int $tokenId;

    protected function setUp(): void
    {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');

        // Instantiating Api defines the global model-name constants
        // (KyteAccount, KyteMCPToken, etc.). Must come before any DBI call
        // that references them.
        $this->api = new Api();

        \Kyte\Core\DBI::createTable(KyteAccount);
        \Kyte\Core\DBI::createTable(KyteMCPToken);
        \Kyte\Core\DBI::createTable(Application);
        \Kyte\Core\DBI::createTable(KyteActivityLog);

        \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number = '" . self::FIXED_ACCOUNT . "'");
        \Kyte\Core\DBI::query("DELETE FROM `KyteMCPToken` WHERE token_prefix LIKE 'kmcp_live_scope%'");
        \Kyte\Core\DBI::query("DELETE FROM `Application` WHERE identifier = '" . self::APP_IDENT . "'");
        \Kyte\Core\DBI::query("DELETE FROM `KyteActivityLog` WHERE action = 'MCP_SCOPE_VIOLATION'");

        $account = new \Kyte\Core\ModelObject(KyteAccount);
        $account->create([
            'name'   => 'MCP Scope Test Account',
            'number' => self::FIXED_ACCOUNT,
        ]);
        $this->accountId = (int)$account->id;

        $token = new \Kyte\Core\ModelObject(KyteMCPToken);
        $token->create([
            'token_hash'   => hash('sha256', self::RAW_TOKEN),
            'token_prefix' => substr(self::RAW_TOKEN, 0, 16),
            'name'         => 'Scope test token',
            'scopes'       => 'read,draft',
            'expires_at'   => time() + 3600,
            'revoked_at'   => 0,
            'kyte_account' => $this->accountId,
        ]);
        $this->tokenId = (int)$token->id;

        $app = new \Kyte\Core\ModelObject(Application);
        $app->create([
            'name'         => 'Scope Test App',
            'identifier'   => self::APP_IDENT,
            'kyte_account' => $this->accountId,
        ]);

        $this->api->account = new \Kyte\Core\ModelObject(KyteAccount);
        $this->api->account->retrieve('id', $this->accountId);
        $this->api->mcpToken  = $token;
        $this->api->mcpScopes = ['read', 'draft'];

        // Build the same registry/handler stack that Endpoint::process builds
        // in production. setDiscovery is replaced with manual registration so
        // tests don't depend on filesystem discovery state.
        $container = new McpContainer();
        $container->set(Api::class, $this->api);

        $this->registry = new McpRegistry();
        $emptySchema = ['type' => 'object', 'properties' => new \stdClass()];
        $this->registry->registerTool(
            new \Mcp\Schema\Tool('list_applications', $emptySchema, 'List apps', null),
            [\Kyte\Mcp\Tools\AccountTools::class, 'listApplications'],
        );
        $this->registry->registerTool(
            new \Mcp\Schema\Tool('untagged_tool', $emptySchema, 'Tool with no RequiresScope', null),
            [self::class, 'fakeUntaggedTool'],
        );

        $referenceHandler = new ReferenceHandler($container);
        $inner = new CallToolHandler($this->registry, $referenceHandler);
        $this->scopedHandler = new ScopedCallToolHandler($inner, new ScopeRegistry($this->registry), $this->api);

        $store = new InMemorySessionStore(3600);
        $this->session = (new SessionFactory())->create($store);

        $_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];
    }

    /** Stand-in for a tool that someone forgot to annotate. */
    public function fakeUntaggedTool(): array
    {
        return ['ok' => true];
    }

    public function testReadScopedTokenCanCallReadTool(): void
    {
        $request = new CallToolRequest('list_applications', []);
        $this->setRequestId($request, 7);

        $result = $this->scopedHandler->handle($request, $this->session);

        $this->assertInstanceOf(Response::class, $result, 'Expected a successful Response, got Error: ' . $this->errorMessageOrEmpty($result));
        $this->assertSame(7, $result->getId());
    }

    public function testDraftOnlyTokenIsRejectedFromReadTool(): void
    {
        $this->api->mcpScopes = ['draft']; // strip read

        $request = new CallToolRequest('list_applications', []);
        $this->setRequestId($request, 8);

        $result = $this->scopedHandler->handle($request, $this->session);

        $this->assertInstanceOf(Error::class, $result);
        $this->assertSame(-32010, $result->code);
        $this->assertSame(8, $result->id);
        $this->assertStringContainsString("requires scope 'read'", $result->message);

        $this->assertViolationLogged('list_applications', 'read');
    }

    public function testToolWithoutRequiresScopeIsRejected(): void
    {
        $request = new CallToolRequest('untagged_tool', []);
        $this->setRequestId($request, 9);

        $result = $this->scopedHandler->handle($request, $this->session);

        $this->assertInstanceOf(Error::class, $result);
        $this->assertSame(-32010, $result->code);
        $this->assertStringContainsString('no scope declaration', $result->message);

        $this->assertViolationLogged('untagged_tool', '<undeclared>');
    }

    public function testEmptyScopeSetCannotCallAnyTool(): void
    {
        $this->api->mcpScopes = [];

        $request = new CallToolRequest('list_applications', []);
        $this->setRequestId($request, 10);

        $result = $this->scopedHandler->handle($request, $this->session);

        $this->assertInstanceOf(Error::class, $result);
        $this->assertSame(-32010, $result->code);
        $this->assertStringContainsString("requires scope 'read'", $result->message);
    }

    public function testScopeRegistryReadsAttributeFromDiscoveredTool(): void
    {
        // Direct check on ScopeRegistry — proves the reflection lookup matches
        // the discovered tool name. If the attribute is moved/removed/renamed
        // this test fails before any of the dispatch tests do, surfacing the
        // root cause faster.
        $registry = new ScopeRegistry($this->registry);

        $this->assertSame('read', $registry->requiredScopeFor('list_applications'));
        $this->assertNull($registry->requiredScopeFor('untagged_tool'));
        $this->assertNull($registry->requiredScopeFor('does_not_exist'));
    }

    /**
     * Wrap CallToolRequest with a deterministic id. SDK's Request::withId
     * returns a new instance — assign back into the original variable so
     * later assertions on $result->getId() see the same value.
     */
    private function setRequestId(CallToolRequest &$request, int $id): void
    {
        $request = $request->withId($id);
    }

    private function errorMessageOrEmpty(Response|Error $result): string
    {
        return $result instanceof Error ? "[{$result->code}] {$result->message}" : '';
    }

    private function assertViolationLogged(string $toolName, string $requiredScope): void
    {
        // Tool name comes from this test file as a literal — no user input
        // means no SQL-injection surface. DBI exposes no quote() helper, so
        // direct interpolation is the same pattern the existing tests use.
        $rows = \Kyte\Core\DBI::query(
            "SELECT * FROM `KyteActivityLog` WHERE action = 'MCP_SCOPE_VIOLATION' AND value = '"
            . $toolName . "' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotEmpty($rows, "Expected an MCP_SCOPE_VIOLATION row for tool '{$toolName}'");

        $row = $rows[0];
        $this->assertSame('KyteMCPToken', $row['model_name']);
        $this->assertSame('tool', $row['field']);
        $this->assertSame('403', (string)$row['response_code']);
        $this->assertSame('denied', $row['response_status']);

        $payload = json_decode($row['request_data'], true);
        $this->assertSame($requiredScope, $payload['required_scope']);
        $this->assertSame($this->tokenId, (int)$row['record_id']);
    }
}
