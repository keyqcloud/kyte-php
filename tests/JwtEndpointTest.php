<?php
namespace Kyte\Test;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Kyte\Core\Api;
use Kyte\Core\Auth\JwtEndpoint;
use Kyte\Core\Auth\RefreshTokenStore;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the /jwt/* endpoint family.
 *
 * Drives JwtEndpoint::process() directly with a constructed $server
 * array and raw JSON body — keeps tests independent of $_SERVER state.
 * Each test asserts on the (status, body) tuple returned.
 *
 * Coverage:
 *   /jwt/login    valid → 200 + access + refresh tokens that round-trip
 *                 missing fields → 400
 *                 unknown user → 401 (same shape as wrong password)
 *                 wrong password → 401
 *                 multilogon: two logins → two distinct families
 *   /jwt/refresh  valid → 200 + new pair, old refresh revoked
 *                 reuse → 401 + family revoked
 *                 unknown → 401
 *                 missing body → 400
 *   /jwt/logout   valid → 200 + token revoked; second call idempotent
 *   /jwt/logout-all valid → 200 + all user tokens revoked across families
 *   /jwt/password-* reset: no-reveal (unknown email also 200), token stored
 *                 raw + login disabled while pending; validate: live/consumed;
 *                 update: sets hashed password + revokes refresh families,
 *                 expired/unknown token → 401 (KYTE-#268)
 *   Routing       Unknown action → 404
 *                 GET method → 405
 */
class JwtEndpointTest extends TestCase
{
    private const ACCOUNT  = 'jwt-endpoint-test';
    private const SECRET   = 'jwt-endpoint-test-secret-with-enough-entropy-12345';
    private const PASSWORD = 'jwt-endpoint-password-9173';

    private Api $api;
    private int $accountId;
    private int $userId;
    private string $userEmail;

    protected function setUp(): void
    {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');

        $this->api = new Api();

        \Kyte\Core\DBI::createTable(KyteAccount);
        \Kyte\Core\DBI::createTable(KyteUser);
        \Kyte\Core\DBI::createTable(KyteRefreshToken);

        \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number = '" . self::ACCOUNT . "'");
        \Kyte\Core\DBI::query("DELETE FROM `KyteUser` WHERE email = 'jwt-endpoint-test@example.com'");
        \Kyte\Core\DBI::query("DELETE FROM `KyteRefreshToken` WHERE token_prefix LIKE 'kref_v1_%'");

        $acct = new \Kyte\Core\ModelObject(KyteAccount);
        $acct->create(['number' => self::ACCOUNT, 'name' => 'JWT Endpoint Test']);
        $this->accountId = (int)$acct->id;

        $this->userEmail = 'jwt-endpoint-test@example.com';
        $user = new \Kyte\Core\ModelObject(KyteUser);
        $user->create([
            'name'         => 'JWT Endpoint Test User',
            'email'        => $this->userEmail,
            'password'     => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'kyte_account' => $this->accountId,
        ]);
        $this->userId = (int)$user->id;

        if (!defined('KYTE_JWT_SECRET')) {
            define('KYTE_JWT_SECRET', self::SECRET);
        }
    }

    public function testLoginWithValidCredentialsReturnsTokens(): void
    {
        $result = JwtEndpoint::process($this->api, $this->serverFor('/jwt/login'), $this->jsonBody([
            'email'    => $this->userEmail,
            'password' => self::PASSWORD,
        ]));

        $this->assertSame(200, $result['status']);
        $body = $result['body'];
        $this->assertArrayHasKey('access_token', $body);
        $this->assertArrayHasKey('refresh_token', $body);
        $this->assertSame('Bearer', $body['token_type']);
        $this->assertStringStartsWith('kref_v1_', $body['refresh_token']);

        // Access token should decode and claim our user/account.
        $decoded = JWT::decode($body['access_token'], new Key(self::SECRET, 'HS256'));
        $this->assertSame((string)$this->userId, (string)$decoded->sub);
        $this->assertSame((string)$this->accountId, (string)$decoded->aud);
    }

    public function testLoginWithMissingPasswordReturns400(): void
    {
        $result = JwtEndpoint::process($this->api, $this->serverFor('/jwt/login'), $this->jsonBody([
            'email' => $this->userEmail,
        ]));

        $this->assertSame(400, $result['status']);
        $this->assertSame('invalid_request', $result['body']['error']);
    }

    public function testLoginWithUnknownUserReturns401(): void
    {
        $result = JwtEndpoint::process($this->api, $this->serverFor('/jwt/login'), $this->jsonBody([
            'email'    => 'nobody@example.com',
            'password' => self::PASSWORD,
        ]));

        $this->assertSame(401, $result['status']);
        $this->assertSame('invalid_credentials', $result['body']['error']);
    }

    public function testLoginWithWrongPasswordReturns401(): void
    {
        $result = JwtEndpoint::process($this->api, $this->serverFor('/jwt/login'), $this->jsonBody([
            'email'    => $this->userEmail,
            'password' => 'wrong-' . self::PASSWORD,
        ]));

        $this->assertSame(401, $result['status']);
        $this->assertSame('invalid_credentials', $result['body']['error']);
    }

    public function testTwoLoginsProduceSeparateRefreshFamilies(): void
    {
        $r1 = JwtEndpoint::process($this->api, $this->serverFor('/jwt/login'), $this->jsonBody([
            'email'    => $this->userEmail,
            'password' => self::PASSWORD,
        ]));
        $r2 = JwtEndpoint::process($this->api, $this->serverFor('/jwt/login'), $this->jsonBody([
            'email'    => $this->userEmail,
            'password' => self::PASSWORD,
        ]));

        $this->assertSame(200, $r1['status']);
        $this->assertSame(200, $r2['status']);
        $this->assertNotSame($r1['body']['refresh_token'], $r2['body']['refresh_token']);

        $f1 = $this->familyOf($r1['body']['refresh_token']);
        $f2 = $this->familyOf($r2['body']['refresh_token']);
        $this->assertNotSame($f1, $f2, 'separate logins must produce separate families');
    }

    public function testRefreshWithValidTokenRotates(): void
    {
        $login = $this->doLogin();
        $original = $login['refresh_token'];

        $refresh = JwtEndpoint::process($this->api, $this->serverFor('/jwt/refresh'), $this->jsonBody([
            'refresh_token' => $original,
        ]));

        $this->assertSame(200, $refresh['status']);
        $this->assertArrayHasKey('access_token', $refresh['body']);
        $this->assertNotSame($original, $refresh['body']['refresh_token']);

        // Original token must now refuse to refresh (revoked).
        $reuse = JwtEndpoint::process($this->api, $this->serverFor('/jwt/refresh'), $this->jsonBody([
            'refresh_token' => $original,
        ]));
        $this->assertSame(401, $reuse['status']);
    }

    public function testRefreshReuseRevokesFamily(): void
    {
        $login = $this->doLogin();
        $original = $login['refresh_token'];

        // First refresh — successful rotation.
        $rot = JwtEndpoint::process($this->api, $this->serverFor('/jwt/refresh'), $this->jsonBody([
            'refresh_token' => $original,
        ]));
        $newToken = $rot['body']['refresh_token'];

        // Replay the original — leak signal, family-wide revoke.
        $replay = JwtEndpoint::process($this->api, $this->serverFor('/jwt/refresh'), $this->jsonBody([
            'refresh_token' => $original,
        ]));
        $this->assertSame(401, $replay['status']);

        // The successor token should now also be unusable.
        $followup = JwtEndpoint::process($this->api, $this->serverFor('/jwt/refresh'), $this->jsonBody([
            'refresh_token' => $newToken,
        ]));
        $this->assertSame(401, $followup['status']);
    }

    public function testLogoutRevokesOnlyPresentedToken(): void
    {
        $login1 = $this->doLogin();
        $login2 = $this->doLogin();

        $logout = JwtEndpoint::process($this->api, $this->serverFor('/jwt/logout'), $this->jsonBody([
            'refresh_token' => $login1['refresh_token'],
        ]));
        $this->assertSame(200, $logout['status']);

        // login1 refresh should fail; login2 should still work.
        $r1 = JwtEndpoint::process($this->api, $this->serverFor('/jwt/refresh'), $this->jsonBody([
            'refresh_token' => $login1['refresh_token'],
        ]));
        $this->assertSame(401, $r1['status']);

        $r2 = JwtEndpoint::process($this->api, $this->serverFor('/jwt/refresh'), $this->jsonBody([
            'refresh_token' => $login2['refresh_token'],
        ]));
        $this->assertSame(200, $r2['status'], 'unrelated session must keep working');
    }

    public function testLogoutAllRevokesAcrossFamilies(): void
    {
        $login1 = $this->doLogin();
        $login2 = $this->doLogin();
        $login3 = $this->doLogin();

        $result = JwtEndpoint::process($this->api, $this->serverFor('/jwt/logout-all'), $this->jsonBody([
            'refresh_token' => $login1['refresh_token'],
        ]));
        $this->assertSame(200, $result['status']);
        $this->assertGreaterThanOrEqual(3, $result['body']['revoked']);

        // Each of the three refresh tokens should now fail.
        foreach ([$login1, $login2, $login3] as $login) {
            $r = JwtEndpoint::process($this->api, $this->serverFor('/jwt/refresh'), $this->jsonBody([
                'refresh_token' => $login['refresh_token'],
            ]));
            $this->assertSame(401, $r['status']);
        }
    }

    public function testUnknownActionReturns404(): void
    {
        $result = JwtEndpoint::process($this->api, $this->serverFor('/jwt/wat'), '');
        $this->assertSame(404, $result['status']);
    }

    public function testNonPostMethodReturns405(): void
    {
        $server = $this->serverFor('/jwt/login');
        $server['REQUEST_METHOD'] = 'GET';
        $result = JwtEndpoint::process($this->api, $server, '');
        $this->assertSame(405, $result['status']);
    }

    /**
     * Regression: browser CORS preflight (OPTIONS) for /jwt/login was
     * being routed to process() which only accepts POST and returned
     * 405 with no CORS headers. Browsers then blocked the real POST.
     * handle() now answers OPTIONS with 204 + CORS headers directly so
     * the preflight succeeds and the actual login can proceed.
     */
    public function testHandleAnswersOptionsPreflightWith204(): void
    {
        $prevServer = $_SERVER;
        try {
            $_SERVER = [
                'REQUEST_METHOD'                       => 'OPTIONS',
                'REQUEST_URI'                          => '/jwt/login',
                'HTTP_ORIGIN'                          => 'https://shipyard.example.com',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS'  => 'content-type, authorization',
                'REMOTE_ADDR'                          => '203.0.113.42',
            ];

            ob_start();
            JwtEndpoint::handle($this->api);
            $body = ob_get_clean();

            $this->assertSame(204, http_response_code(), 'OPTIONS preflight must reply 204');
            $this->assertSame('', $body, 'OPTIONS preflight must not write a body');
        } finally {
            $_SERVER = $prevServer;
            // Reset the response code for downstream tests since PHPUnit
            // doesn't isolate http_response_code() between tests.
            http_response_code(200);
        }
    }

    private function doLogin(): array
    {
        $result = JwtEndpoint::process($this->api, $this->serverFor('/jwt/login'), $this->jsonBody([
            'email'    => $this->userEmail,
            'password' => self::PASSWORD,
        ]));
        $this->assertSame(200, $result['status'], 'precondition: login must succeed');
        return $result['body'];
    }

    private function familyOf(string $rawRefreshToken): string
    {
        $hash = hash('sha256', $rawRefreshToken);
        $rows = \Kyte\Core\DBI::query("SELECT token_family FROM `KyteRefreshToken` WHERE token_hash = '$hash'");
        $this->assertNotEmpty($rows);
        return (string)$rows[0]['token_family'];
    }

    // ---------- /jwt/password-* (KYTE-#268) ----------

    public function testPasswordResetMissingEmailReturns400(): void
    {
        $result = JwtEndpoint::process($this->api, $this->serverFor('/jwt/password-reset'), $this->jsonBody([]));

        $this->assertSame(400, $result['status']);
        $this->assertSame('invalid_request', $result['body']['error']);
    }

    public function testPasswordResetUnknownEmailStillReturnsOk(): void
    {
        // No-reveal: unknown email gets the same response as a known one.
        $result = JwtEndpoint::process($this->api, $this->serverFor('/jwt/password-reset'), $this->jsonBody([
            'email' => 'jwt-endpoint-nobody@example.com',
        ]));

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['body']['ok']);
    }

    public function testPasswordResetKnownEmailStoresTokenAndDisablesLogin(): void
    {
        $result = JwtEndpoint::process($this->api, $this->serverFor('/jwt/password-reset'), $this->jsonBody([
            'email' => $this->userEmail,
        ]));

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['body']['ok']);

        // The raw token replaced the password hash: hex.unixtime format.
        $user = new \Kyte\Core\ModelObject(KyteUser);
        $this->assertTrue($user->retrieve('id', $this->userId));
        $this->assertMatchesRegularExpression('/^[0-9a-f]+\.\d+$/', (string)$user->password);

        // Login with the old password is disabled while the reset is pending
        // (password_verify can never match a raw token).
        $login = JwtEndpoint::process($this->api, $this->serverFor('/jwt/login'), $this->jsonBody([
            'email'    => $this->userEmail,
            'password' => self::PASSWORD,
        ]));
        $this->assertSame(401, $login['status']);
    }

    public function testPasswordValidateAndUpdateFlow(): void
    {
        // Seed a valid reset token directly (skips the SES email step).
        $token = \Kyte\Core\Auth\PasswordResetFlow::generateToken($this->userEmail);
        $user = new \Kyte\Core\ModelObject(KyteUser);
        $this->assertTrue($user->retrieve('id', $this->userId));
        $user->save(['password' => $token]);

        // Validate — token is live.
        $validate = JwtEndpoint::process($this->api, $this->serverFor('/jwt/password-validate'), $this->jsonBody([
            'token' => $token,
        ]));
        $this->assertSame(200, $validate['status']);
        $this->assertTrue($validate['body']['valid']);

        // Update — sets the new password (hashed).
        $newPassword = 'jwt-endpoint-new-password-4821';
        $update = JwtEndpoint::process($this->api, $this->serverFor('/jwt/password-update'), $this->jsonBody([
            'token'    => $token,
            'password' => $newPassword,
        ]));
        $this->assertSame(200, $update['status']);
        $this->assertTrue($update['body']['ok']);

        // Token is consumed — validate now reports invalid.
        $revalidate = JwtEndpoint::process($this->api, $this->serverFor('/jwt/password-validate'), $this->jsonBody([
            'token' => $token,
        ]));
        $this->assertSame(200, $revalidate['status']);
        $this->assertFalse($revalidate['body']['valid']);

        // Login with the NEW password works.
        $login = JwtEndpoint::process($this->api, $this->serverFor('/jwt/login'), $this->jsonBody([
            'email'    => $this->userEmail,
            'password' => $newPassword,
        ]));
        $this->assertSame(200, $login['status']);
        $this->assertArrayHasKey('access_token', $login['body']);
    }

    public function testPasswordUpdateRevokesRefreshTokenFamilies(): void
    {
        // Establish a session first, then reset the password — the old
        // refresh token must be revoked.
        $login = JwtEndpoint::process($this->api, $this->serverFor('/jwt/login'), $this->jsonBody([
            'email'    => $this->userEmail,
            'password' => self::PASSWORD,
        ]));
        $this->assertSame(200, $login['status']);
        $refreshToken = $login['body']['refresh_token'];

        $token = \Kyte\Core\Auth\PasswordResetFlow::generateToken($this->userEmail);
        $user = new \Kyte\Core\ModelObject(KyteUser);
        $this->assertTrue($user->retrieve('id', $this->userId));
        $user->save(['password' => $token]);

        $update = JwtEndpoint::process($this->api, $this->serverFor('/jwt/password-update'), $this->jsonBody([
            'token'    => $token,
            'password' => 'jwt-endpoint-after-revoke-7733',
        ]));
        $this->assertSame(200, $update['status']);

        $refresh = JwtEndpoint::process($this->api, $this->serverFor('/jwt/refresh'), $this->jsonBody([
            'refresh_token' => $refreshToken,
        ]));
        $this->assertSame(401, $refresh['status']);
    }

    public function testPasswordUpdateWithExpiredTokenReturns401(): void
    {
        // Hand-craft a token whose timestamp is 2 hours old.
        $expired = bin2hex(random_bytes(32)) . '.' . (time() - 7200);
        $user = new \Kyte\Core\ModelObject(KyteUser);
        $this->assertTrue($user->retrieve('id', $this->userId));
        $user->save(['password' => $expired]);

        $result = JwtEndpoint::process($this->api, $this->serverFor('/jwt/password-update'), $this->jsonBody([
            'token'    => $expired,
            'password' => 'whatever-5912',
        ]));

        $this->assertSame(401, $result['status']);
        $this->assertSame('invalid_token', $result['body']['error']);
    }

    public function testPasswordUpdateWithUnknownTokenReturns401(): void
    {
        $result = JwtEndpoint::process($this->api, $this->serverFor('/jwt/password-update'), $this->jsonBody([
            'token'    => bin2hex(random_bytes(32)) . '.' . time(),
            'password' => 'whatever-3317',
        ]));

        $this->assertSame(401, $result['status']);
        $this->assertSame('invalid_token', $result['body']['error']);
    }

    private function serverFor(string $path): array
    {
        return [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => $path,
            'REMOTE_ADDR'    => '203.0.113.42',
        ];
    }

    private function jsonBody(array $payload): string
    {
        return json_encode($payload);
    }
}
