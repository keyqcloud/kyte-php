<?php
namespace Kyte\Core\Auth;

use Kyte\Core\ModelObject;

/**
 * Shared password-reset mechanics (KYTE-#268).
 *
 * Two surfaces drive the same reset flow and must stay byte-compatible:
 *
 *   - KytePasswordResetController — the legacy model-CRUD path (HMAC
 *     anonymous, and app_context mode 2 for app-scoped sites).
 *   - JwtEndpoint /jwt/password-* — the platform-level JWT-mode path
 *     (Shipyard is not app-scoped, so it cannot ride AppContextStrategy).
 *
 * Mechanics (unchanged from the original controller implementation):
 *   - Token format: `<uniqueHex>.<unixTimestamp>`, capped at 255 chars
 *     (the KyteUser.password column size).
 *   - The RAW token is stored in the user's password column. That is
 *     deliberate: ModelObject::save() writes raw (the struct-driven
 *     password_hash lives in ModelController's data cleaning), and a
 *     pending raw token also disables login until the reset completes,
 *     because password_verify() can never match a non-hash.
 *   - Tokens expire after TOKEN_TTL_SECONDS (1 hour).
 */
final class PasswordResetFlow
{
    public const TOKEN_TTL_SECONDS = 3600;

    /**
     * Generate a timestamped reset token for the given email.
     * Format: uniqueToken.timestamp
     */
    public static function generateToken(string $email): string
    {
        // Generate unique token part
        $randomBytes = random_bytes(24); // Reduced to 24 bytes to leave room for timestamp
        $emailHash = hash('sha256', $email, true);
        $tokenData = $emailHash . $randomBytes;
        $uniqueToken = bin2hex($tokenData);

        // Add timestamp
        $timestamp = time();
        $token = $uniqueToken . '.' . $timestamp;

        // Ensure token length is within 255 character limit
        if (strlen($token) > 255) {
            // If too long, truncate the unique part but keep the timestamp
            $maxUniqueLength = 255 - strlen('.' . $timestamp);
            $uniqueToken = substr($uniqueToken, 0, $maxUniqueLength);
            $token = $uniqueToken . '.' . $timestamp;
        }

        return $token;
    }

    /**
     * Validate token format, expiration, and match with the stored token
     * (the raw value currently in the user's password column).
     */
    public static function isValidToken(string $providedToken, ?string $storedToken): bool
    {
        // Check if stored token exists
        if (empty($storedToken)) {
            return false;
        }

        // Check if provided token matches stored token
        if ($storedToken !== $providedToken) {
            return false;
        }

        // Extract timestamp from token
        $tokenParts = explode('.', $providedToken);
        if (count($tokenParts) !== 2) {
            return false; // Invalid token format
        }

        $timestamp = (int)$tokenParts[1];
        $tokenAge = time() - $timestamp;

        // Check if token is expired
        if ($tokenAge > self::TOKEN_TTL_SECONDS) {
            return false;
        }

        return true;
    }

    /**
     * Send the reset email via SES. Requires APP_SES_REGION, APP_EMAIL,
     * and SHIPYARD_URL. Throws on configuration/delivery failure — the
     * caller decides whether that is fatal (HMAC controller) or logged
     * (JWT endpoint, which must not reveal delivery state anonymously).
     */
    public static function sendResetEmail(ModelObject $user, string $token): void
    {
        $body = self::emailHtml($user, $token);

        $credentials = new \Kyte\Aws\Credentials(APP_SES_REGION);
        $ses = new \Kyte\Aws\Ses($credentials, 'Kyte Shipyard <' . APP_EMAIL . '>');
        $ses->send([$user->email], "Kyte Shipyard - Password Reset Instructions", $body);
    }

    /**
     * Generate the HTML email body for the password reset message.
     */
    public static function emailHtml(ModelObject $user, string $token): string
    {
        $userName = htmlspecialchars($user->name ?: 'there');
        $userEmail = htmlspecialchars($user->email);
        $resetUrl = SHIPYARD_URL . '/password.html?token=' . urlencode($token);
        $resetUrlDisplay = htmlspecialchars($resetUrl);

        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kyte Shipyard - Password Reset</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f8f9fa;
        }
        .email-container {
            background-color: white;
            border-radius: 8px;
            padding: 40px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo {
            font-size: 28px;
            font-weight: bold;
            color: #2563eb;
            margin-bottom: 10px;
        }
        .subtitle {
            color: #6b7280;
            font-size: 16px;
        }
        .content {
            margin-bottom: 30px;
        }
        .greeting {
            font-size: 18px;
            margin-bottom: 20px;
            color: #1f2937;
        }
        .message {
            margin-bottom: 25px;
            color: #4b5563;
            line-height: 1.7;
        }
        .reset-button {
            display: inline-block;
            background-color: #2563eb;
            color: white;
            padding: 14px 30px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 16px;
            margin: 20px 0;
            transition: background-color 0.3s ease;
        }
        .reset-button:hover {
            background-color: #1d4ed8;
        }
        .button-container {
            text-align: center;
            margin: 30px 0;
        }
        .alternative-link {
            background-color: #f3f4f6;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 12px;
            font-family: monospace;
            font-size: 14px;
            word-break: break-all;
            color: #374151;
            margin: 15px 0;
        }
        .security-notice {
            background-color: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            margin: 25px 0;
            border-radius: 4px;
        }
        .security-notice h4 {
            margin: 0 0 8px 0;
            color: #92400e;
            font-size: 16px;
        }
        .security-notice p {
            margin: 0;
            color: #78350f;
            font-size: 14px;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            color: #6b7280;
            font-size: 14px;
        }
        .footer p {
            margin: 5px 0;
        }
        @media (max-width: 600px) {
            body {
                padding: 10px;
            }
            .email-container {
                padding: 20px;
            }
            .reset-button {
                display: block;
                text-align: center;
                width: 100%;
                box-sizing: border-box;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <div class="logo">⚓ Kyte Shipyard</div>
            <div class="subtitle">Password Reset Request</div>
        </div>

        <div class="content">
            <div class="greeting">
                Hello ' . $userName . ',
            </div>

            <div class="message">
                We received a request to reset the password for your Kyte Shipyard account associated with <strong>' . $userEmail . '</strong>.
            </div>

            <div class="message">
                Click the button below to reset your password. This link will expire in 1 hour for your security.
            </div>

            <div class="button-container">
                <a href="' . $resetUrl . '" class="reset-button">
                    Reset My Password
                </a>
            </div>

            <div class="message">
                If the button above doesn\'t work, you can copy and paste this link into your browser:
            </div>

            <div class="alternative-link">
                ' . $resetUrlDisplay . '
            </div>

            <div class="security-notice">
                <h4>🔒 Security Notice</h4>
                <p>If you didn\'t request this password reset, please ignore this email. For security reasons, this reset link will expire in 1 hour and you will need to generate a new request if it expires.</p>
            </div>
        </div>

        <div class="footer">
            <p><strong>Kyte Shipyard Team</strong></p>
            <p>This is an automated message, please do not reply to this email.</p>
            <p style="margin-top: 15px; font-size: 12px; color: #9ca3af;">
                If you\'re having trouble with the reset process, please contact our support team.
            </p>
        </div>
    </div>
</body>
</html>';
    }
}
