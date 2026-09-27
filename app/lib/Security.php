<?php
declare(strict_types=1);

final class Security
{
    public static function sendHeaders(): void
    {
        if (config('app.force_https') && !is_https() && PHP_SAPI !== 'cli-server') {
            $host = $_SERVER['HTTP_HOST'] ?? parse_url(base_url(), PHP_URL_HOST);
            header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
            exit;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(self), camera=(), microphone=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        if (is_https()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        $csp = [
            "default-src 'self'",
            "script-src 'self' https://cdnjs.cloudflare.com https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/ https://challenges.cloudflare.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data: blob:",
            "frame-src https://www.google.com/recaptcha/ https://recaptcha.google.com https://challenges.cloudflare.com",
            "connect-src 'self'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
        ];
        header('Content-Security-Policy: ' . implode('; ', $csp));
    }

    /** Validate password strength; returns error message or null. */
    public static function passwordError(string $pw): ?string
    {
        $min = (int) config('security.password_min_length', 10);
        if (mb_strlen($pw) < $min) {
            return "Password must be at least {$min} characters.";
        }
        if (!preg_match('/[A-Z]/', $pw) || !preg_match('/[a-z]/', $pw) || !preg_match('/\d/', $pw)) {
            return 'Password must contain upper-case and lower-case letters and a number.';
        }
        if (mb_strlen($pw) > 200) {
            return 'Password is too long.';
        }
        return null;
    }

    public static function hashPassword(string $pw): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        return password_hash($pw, $algo);
    }

    /** Device fingerprint cookie (random, first-party) for duplicate-account detection. */
    public static function deviceHash(): string
    {
        $id = $_COOKIE['cl_dev'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id)) {
            $id = bin2hex(random_bytes(16));
            setcookie('cl_dev', $id, [
                'expires' => time() + 86400 * 730, 'path' => '/', 'secure' => is_https(),
                'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
        return secure_hash('device:' . $id);
    }

    /** Encrypt a secret (e.g. SMTP password) for storage in the database, keyed by app.secret_key. */
    public static function encryptSecret(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $key = hash('sha256', 'secret-store:' . config('app.secret_key'), true);
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    public static function decryptSecret(string $stored): string
    {
        if (!str_starts_with($stored, 'v1:')) {
            return '';
        }
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $key = hash('sha256', 'secret-store:' . config('app.secret_key'), true);
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? '' : $plain;
    }
}
