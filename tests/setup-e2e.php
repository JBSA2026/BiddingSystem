<?php
/**
 * Prepares the ISOLATED end-to-end test environment (called by tests/run-e2e.sh):
 * writes a temporary test config, recreates the `cityland_e2e` database, installs the schema,
 * creates the test super admin and loads the demo properties the suite expects.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
$cfgFile = (string) getenv('CL_CONFIG');
$storage = (string) getenv('E2E_STORAGE');
$db = ['host' => getenv('E2E_DB_HOST'), 'port' => (int) (getenv('E2E_DB_PORT') ?: 3306), 'name' => getenv('E2E_DB_NAME'), 'user' => getenv('E2E_DB_USER'), 'pass' => (string) getenv('E2E_DB_PASS'), 'charset' => 'utf8mb4'];
if (!str_contains((string) $db['name'], 'e2e')) {
    exit("Refusing: the e2e database name must contain 'e2e' (it is dropped and recreated).\n");
}
foreach (['uploads', 'backups', 'logs', 'sessions'] as $d) {
    @mkdir("{$storage}/{$d}", 0777, true);
}
file_put_contents($cfgFile, '<?php return ' . var_export([
    'app' => ['url' => rtrim((string) getenv('E2E_BASE'), '/'), 'timezone' => 'Asia/Manila', 'env' => 'testing', 'debug' => false, 'force_https' => false,
        'secret_key' => bin2hex(random_bytes(32)), 'storage_path' => $storage, 'max_upload_mb' => 10],
    'db' => $db,
    'mail' => ['driver' => 'log', 'host' => '', 'port' => 25, 'encryption' => '', 'username' => '', 'password' => '', 'from_email' => 'bidding@test.local', 'from_name' => 'E2E', 'timeout' => 5],
    'sms' => ['provider' => 'none', 'api_key' => ''],
    'captcha' => ['provider' => 'builtin'],
    'security' => ['max_login_attempts' => 5, 'max_ip_attempts' => 20, 'lockout_minutes' => 15, 'session_idle_minutes' => 30, 'session_absolute_hours' => 8,
        'password_min_length' => 10, 'cron_key' => 'e2e', 'trusted_proxies' => []],
], true) . ';');

$pdo = new PDO("mysql:host={$db['host']};port={$db['port']};charset=utf8mb4", $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("DROP DATABASE IF EXISTS `{$db['name']}`");
$pdo->exec("CREATE DATABASE `{$db['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

require dirname(__DIR__) . '/app/bootstrap.php';
TestEnv::installSchema();
DB::insert('admins', ['name' => 'Super Admin', 'email' => 'admin@example.com', 'password_hash' => Security::hashPassword('AdminPass123'), 'role' => 'super_admin', 'created_at' => now()]);
passthru('php ' . escapeshellarg(dirname(__DIR__) . '/tools/seed_demo.php') . ' > /dev/null');
echo "E2E environment prepared.\n";
