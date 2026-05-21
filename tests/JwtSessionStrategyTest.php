<?php
namespace Kyte\Test;

use Firebase\JWT\JWT;
use Kyte\Core\Api;
use Kyte\Core\Auth\JwtSessionStrategy;
use Kyte\Exception\SessionException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for JwtSessionStrategy.
 *
 * matches() matrix:
 *   - No KYTE_JWT_SECRET defined         → false
 *   - No Authorization header            → false
 *   - Authorization but not Bearer       → false
 *   - Bearer kmcp_live_... (MCP token)   → false (defers to McpTokenStrategy)
 *   - Bearer <opaque non-JWT>            → false (wrong shape)
 *   - Bearer <valid-looking JWT>         → true
 *
 * preAuth matrix:
 *   - Valid JWT for known user/account   → Api state populated
 *   - Tampered signature                 → SessionException
 *   - Expired token                      → SessionException
 *   - Wrong issuer                       → SessionException
 *   - Unknown account                    → SessionException
 *   - Unknown user                       → SessionException
 *   - User/account mismatch              → SessionException
 */
class JwtSessionStrategyTest extends TestCase
{
    private const ACCOUNT = 'jwt-strat-test';
    private const SECRET  = 'jwt-strat-test-secret-with-enough-entropy-12345';
    private const ISSUER  = 'kyte';

    private Api $api;
    private int $accountId;
    private int $userId;

    protected function setUp(): void
    {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');

        $this->api = new Api();

        \Kyte\Core\DBI::createTable(KyteAccount);
        \Kyte\Core\DBI::createTable(KyteUser);

        \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number = '" . self::ACCOUNT . "'");
        \Kyte\Core\DBI::query("DELETE FROM `KyteUser` WHERE email = 'jwt-strat-test@example.com'");

        $acct = new \Kyte\Core\ModelObject(KyteAccount);
        $acct->create(['number' => self::ACCOUNT, 'name' => 'JWT Strategy Test']);
        $this->accountId = (int)$acct->id;

        $user = new \Kyte\Core\ModelObject(KyteUser);
        $user->create([
            'name'         => 'JWT Test User',
            'email'        => 'jwt-strat-test@example.com',
            'password'     => password_hash('not-tested-here', PASSWORD_DEFAULT),
            'kyte_account' => $this->accountId,
        ]);
        $this->userId = (int)$user->id;

        if (!defined('KYTE_JWT_SECRET')) {
            define('KYTE_JWT_SECRET', self::SECRET);
        }

        $_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];
    }

    public function testMatchesReturnsFalseWhenNoAuthorizationHeader(): void
    {
        $strategy = new JwtSessionStrategy();
        $this->assertFalse($strategy->matches());
    }

    public function testMatchesReturnsFalseForKmcpPrefixedToken(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer kmcp_live_abc123.def456.ghi789';
        $strategy = new JwtSessionStrategy();
        $this->assertFalse($strategy->matches(),
            'JWT strategy must not claim MCP tokens — that is McpTokenStrategy territory');
    }

    public function testMatchesReturnsFalseForNonBearerScheme(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';
        $strategy = new JwtSessionStrategy();
        $this->assertFalse($strategy->matches());
    }

    public function testMatchesReturnsFalseForOpaqueNonJwtBearer(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer arbitrary-opaque-string-not-a-jwt';
        $strategy = new JwtSessionStrategy();
        $this->assertFalse($strategy->matches());
    }

    public function testMatchesReturnsTrueForValidLookingJwt(): void
    {
        $jwt = $this->mintToken();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;
        $strategy = new JwtSessionStrategy();
        $this->assertTrue($strategy->matches());
    }

    public function testPreAuthPopulatesApiStateForValidJwt(): void
    {
        $jwt = $this->mintToken();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;

        $strategy = new JwtSessionStrategy();
        $strategy->preAuth($this->api);

        $this->assertNotNull($this->api->account);
        $this->assertSame($this->accountId, (int)$this->api->account->id);
        $this->assertNotNull($this->api->user);
        $this->assertSame($this->userId, (int)$this->api->user->id);
        $this->assertSame('jwt', $this->api->response['session']);
    }

    public function testPreAuthRejectsTamperedSignature(): void
    {
        $jwt = $this->mintToken();
        // Flip a character in the signature segment.
        $parts = explode('.', $jwt);
        $parts[2] = strtr($parts[2], 'a', 'b');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . implode('.', $parts);

        $strategy = new JwtSessionStrategy();
        $this->expectException(SessionException::class);
        $strategy->preAuth($this->api);
    }

    public function testPreAuthRejectsExpiredToken(): void
    {
        $jwt = $this->mintToken(['exp' => time() - 60]);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;

        $strategy = new JwtSessionStrategy();
        $this->expectException(SessionException::class);
        $strategy->preAuth($this->api);
    }

    public function testPreAuthRejectsWrongIssuer(): void
    {
        $jwt = $this->mintToken(['iss' => 'not-kyte']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;

        $strategy = new JwtSessionStrategy();
        $this->expectException(SessionException::class);
        $strategy->preAuth($this->api);
    }

    public function testPreAuthRejectsUnknownAccount(): void
    {
        $jwt = $this->mintToken(['aud' => 999999999]);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;

        $strategy = new JwtSessionStrategy();
        $this->expectException(SessionException::class);
        $strategy->preAuth($this->api);
    }

    public function testPreAuthRejectsUnknownUser(): void
    {
        $jwt = $this->mintToken(['sub' => 999999999]);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;

        $strategy = new JwtSessionStrategy();
        $this->expectException(SessionException::class);
        $strategy->preAuth($this->api);
    }

    public function testPreAuthRejectsUserBelongingToDifferentAccount(): void
    {
        // Create a second account and a user under it.
        $otherAcct = new \Kyte\Core\ModelObject(KyteAccount);
        $otherAcct->create(['number' => 'jwt-strat-test-other', 'name' => 'Other']);
        $otherAccountId = (int)$otherAcct->id;

        // Mint a token claiming our user but a foreign account.
        $jwt = $this->mintToken(['aud' => $otherAccountId]);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;

        $strategy = new JwtSessionStrategy();
        $this->expectException(SessionException::class);
        try {
            $strategy->preAuth($this->api);
        } finally {
            \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number = 'jwt-strat-test-other'");
        }
    }

    public function testNameReturnsExpectedLabel(): void
    {
        $this->assertSame('jwt_session', (new JwtSessionStrategy())->name());
    }

    public function testVerifyIsNoOp(): void
    {
        // preAuth does the full verification; verify is a no-op in this strategy.
        $strategy = new JwtSessionStrategy();
        $strategy->verify($this->api);
        $this->assertTrue(true);
    }

    private function mintToken(array $overrides = []): string
    {
        $now = time();
        $payload = array_merge([
            'iss'   => self::ISSUER,
            'sub'   => $this->userId,
            'aud'   => $this->accountId,
            'exp'   => $now + 900,
            'nbf'   => $now,
            'iat'   => $now,
            'jti'   => bin2hex(random_bytes(8)),
            'email' => 'jwt-strat-test@example.com',
        ], $overrides);
        return JWT::encode($payload, self::SECRET, 'HS256');
    }
}
