<?php
/**
 * Application bootstrap: loaded at the top of every public, bidder and admin page.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_DIR', __DIR__);
define('APP_VERSION', '1.0.0');

if (!is_file(APP_DIR . '/config.php')) {
    if (PHP_SAPI !== 'cli' && is_file(APP_ROOT . '/install.php')) {
        header('Location: ' . (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') ? '../' : '') . 'install.php');
        exit;
    }
    exit("Configuration missing. Copy app/config.sample.php to app/config.php or run install.php.\n");
}

$GLOBALS['__config'] = require APP_DIR . '/config.php';

spl_autoload_register(static function (string $class): void {
    $file = APP_DIR . '/lib/' . basename(str_replace('\\', '/', $class)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
require APP_DIR . '/lib/helpers.php';

date_default_timezone_set((string) config('app.timezone', 'Asia/Manila'));
mb_internal_encoding('UTF-8');

// Error handling: never leak details on production.
error_reporting(E_ALL);
ini_set('display_errors', config('app.debug') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', storage_path('logs/php-error.log'));
set_exception_handler(static function (Throwable $e): void {
    error_log('[' . date('c') . '] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    $msg = config('app.debug') ? e($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()) : 'An unexpected error occurred. Please try again later.';
    echo '<!doctype html><meta charset="utf-8"><title>Error</title><div style="font-family:sans-serif;max-width:560px;margin:60px auto;padding:24px;border:1px solid #ddd;border-radius:8px"><h2>Something went wrong</h2><p>' . $msg . '</p><p><a href="' . e(url('')) . '">Return to home page</a></p></div>';
});

if (PHP_SAPI !== 'cli') {
    Security::sendHeaders();
    Session::start();
    // Keep property statuses in step with server time (cheap, indexed queries).
    Bidding::syncStatuses();
}
