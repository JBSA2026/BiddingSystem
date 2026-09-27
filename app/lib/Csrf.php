<?php
declare(strict_types=1);

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = random_token(32);
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    /** Verify the token on every state-changing request. Aborts with 419 on failure. */
    public static function verify(): void
    {
        $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!is_string($sent) || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $sent)) {
            abort(419, 'Your session has expired or the form is no longer valid. Please go back, refresh the page and try again.');
        }
    }

    /** For POST handlers: require POST method and a valid token. */
    public static function requirePost(): void
    {
        if (!is_post()) {
            abort(405);
        }
        self::verify();
    }
}
