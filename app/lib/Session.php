<?php
declare(strict_types=1);

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $path = parse_url(base_url(), PHP_URL_PATH) ?: '/';
        session_name('CLBIDSESS');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) (((int) config('security.session_absolute_hours', 8)) * 3600));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => rtrim($path, '/') . '/',
            'secure'   => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $savePath = storage_path('sessions');
        if (is_dir($savePath) || @mkdir($savePath, 0700, true)) {
            if (is_writable($savePath)) {
                session_save_path($savePath);
            }
        }
        session_start();

        $now = time();
        $idle = ((int) (setting('session_idle_minutes') ?? config('security.session_idle_minutes', 30))) * 60;
        $absolute = ((int) config('security.session_absolute_hours', 8)) * 3600;
        $last = $_SESSION['_last'] ?? $now;
        $started = $_SESSION['_started'] ?? $now;
        if (($now - $last) > $idle || ($now - $started) > $absolute) {
            $hadLogin = !empty($_SESSION['bidder_id']) || !empty($_SESSION['admin_id']);
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['_started'] = $now;
            if ($hadLogin) {
                $_SESSION['_flash'][] = ['type' => 'info', 'message' => 'Your session expired due to inactivity. Please sign in again.'];
            }
        }
        // Background polling (notification feed, countdown sync) must not keep an idle session alive.
        if (!defined('NO_SESSION_TOUCH')) {
            $_SESSION['_last'] = $now;
        }
        $_SESSION['_started'] ??= $now;
    }

    /** Call after any privilege change (login/logout) to prevent session fixation. */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
        $_SESSION['_started'] = time();
        $_SESSION['_last'] = time();
        unset($_SESSION['_csrf']);
    }
}
