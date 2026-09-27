<?php
/**
 * Cityland Online Property Bidding — Web Installer
 * 1) Upload the package to public_html (or a sub-folder)  2) Create a MySQL database + user in cPanel
 * 3) Open https://your-domain/install.php                  4) DELETE install.php after installation
 */
declare(strict_types=1);

// Friendly stop on old PHP versions (must stay compatible with PHP 7.x syntax up to this point).
if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><title>PHP upgrade required</title><div style="font-family:sans-serif;max-width:620px;margin:60px auto;padding:24px;border:2px solid #b42318;border-radius:8px">'
        . '<h2>PHP 8.1 or newer is required</h2><p>This server is running PHP <strong>' . htmlspecialchars(PHP_VERSION) . '</strong> for this site.</p>'
        . '<p>In cPanel open <strong>Select PHP Version</strong> (or <strong>MultiPHP Manager</strong>), choose <strong>8.1, 8.2 or 8.3</strong> for this domain, click <em>Set as current</em>, then reload this page.</p></div>');
}

$root = __DIR__;
$configFile = $root . '/app/config.php';
$lockFile = $root . '/storage/installed.lock';

function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** Split an SQL script into statements, respecting quotes, comments and DELIMITER. */
function split_sql(string $sql): array
{
    $stmts = [];
    $buf = '';
    $delim = ';';
    $len = strlen($sql);
    $inStr = false;
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if (!$inStr && $c === '-' && substr($sql, $i, 3) === '-- ') {
            $nl = strpos($sql, "\n", $i);
            $i = $nl === false ? $len : $nl;
            continue;
        }
        if (!$inStr && ($i === 0 || $sql[$i - 1] === "\n") && strncasecmp(substr($sql, $i, 10), 'DELIMITER ', 10) === 0) {
            $nl = strpos($sql, "\n", $i);
            $delim = trim(substr($sql, $i + 10, ($nl === false ? $len : $nl) - $i - 10));
            $i = $nl === false ? $len : $nl;
            continue;
        }
        if ($c === "'") {
            if ($inStr && ($sql[$i + 1] ?? '') === "'") {
                $buf .= "''";
                $i++;
                continue;
            }
            $inStr = !$inStr;
        }
        if (!$inStr && substr($sql, $i, strlen($delim)) === $delim) {
            if (trim($buf) !== '') {
                $stmts[] = trim($buf);
            }
            $buf = '';
            $i += strlen($delim) - 1;
            continue;
        }
        $buf .= $c;
    }
    if (trim($buf) !== '') {
        $stmts[] = trim($buf);
    }
    return $stmts;
}

if (is_file($lockFile) && is_file($configFile)) {
    http_response_code(403);
    exit('<!doctype html><meta charset="utf-8"><title>Installed</title><div style="font-family:sans-serif;max-width:600px;margin:60px auto"><h2>Already installed</h2><p>The system is already installed. For security, <strong>delete install.php</strong> from the server.</p></div>');
}

$checks = [
    'PHP 8.1 or newer' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO MySQL extension' => extension_loaded('pdo_mysql'),
    'mbstring extension' => extension_loaded('mbstring'),
    'OpenSSL extension' => extension_loaded('openssl'),
    'fileinfo extension' => extension_loaded('fileinfo'),
    'cURL extension (reCAPTCHA / SMS)' => extension_loaded('curl'),
    'GD extension (image processing / CAPTCHA) — recommended' => extension_loaded('gd'),
    'Zip extension (file backups) — recommended' => class_exists('ZipArchive'),
    'app/ folder writable (to create config.php)' => is_writable($root . '/app'),
    'storage/ folder writable' => is_writable($root . '/storage'),
];
$required = array_slice(array_keys($checks), 0, 5);
$requiredOk = true;
foreach ($required as $r) {
    $requiredOk = $requiredOk && $checks[$r];
}
$requiredOk = $requiredOk && $checks['app/ folder writable (to create config.php)'] && $checks['storage/ folder writable'];

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$guessUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$errors = [];
$done = null;
$v = static fn(string $k, string $d = '') => h($_POST[$k] ?? $d);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $requiredOk) {
    $in = static fn(string $k) => trim((string) ($_POST[$k] ?? ''));
    $url = rtrim($in('app_url'), '/');
    $testMode = ($_POST['install_type'] ?? '') === 'testing';
    $adminPw = (string) ($_POST['admin_password'] ?? '');
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $errors[] = 'Enter a valid site URL.';
    }
    if (!filter_var($in('admin_email'), FILTER_VALIDATE_EMAIL) || $in('admin_name') === '') {
        $errors[] = 'Enter the Super Admin name and a valid email.';
    }
    if (strlen($adminPw) < 10 || !preg_match('/[A-Z]/', $adminPw) || !preg_match('/[a-z]/', $adminPw) || !preg_match('/\d/', $adminPw)) {
        $errors[] = 'Super Admin password must be at least 10 characters with upper/lower case letters and a number.';
    }
    $pdo = null;
    if (!$errors) {
        try {
            $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $in('db_host'), (int) ($in('db_port') ?: 3306), $in('db_name')), $in('db_user'), (string) ($_POST['db_pass'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        } catch (PDOException $e) {
            $errors[] = 'Database connection failed: ' . $e->getMessage();
        }
    }
    if (!$errors && $pdo) {
        try {
            if ($pdo->query("SHOW TABLES LIKE 'bids'")->fetch()) {
                throw new RuntimeException('The database already contains the bidding tables. Use an empty database, or restore from backup instead.');
            }
            $pdo->exec("SET time_zone = '+08:00'");
            foreach (split_sql((string) file_get_contents($root . '/install/schema.sql')) as $stmt) {
                $pdo->exec($stmt);
            }
            $triggerNote = 'Immutability triggers installed.';
            try {
                foreach (split_sql((string) file_get_contents($root . '/install/triggers.sql')) as $stmt) {
                    $pdo->exec($stmt);
                }
            } catch (PDOException $e) {
                $triggerNote = 'Could not install database triggers (' . $e->getMessage() . '). The application still enforces immutability and hash-chains records; ask your host to allow triggers and import install/triggers.sql later.';
            }
            $upd = $pdo->prepare('UPDATE settings SET svalue = ? WHERE skey = ?');
            foreach (['company_name' => $in('company_name') ?: 'Cityland', 'contact_email' => $in('contact_email') ?: $in('admin_email'), 'site_name' => ($in('company_name') ?: 'Cityland') . ' Online Property Bidding'] as $k => $val) {
                $upd->execute([$val, $k]);
            }
            $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
            $pdo->prepare('INSERT INTO admins (name, email, password_hash, role, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())')
                ->execute([$in('admin_name'), strtolower($in('admin_email')), password_hash($adminPw, $algo), 'super_admin']);

            $config = [
                'app' => ['url' => $url, 'timezone' => 'Asia/Manila', 'env' => $testMode ? 'testing' : 'production', 'debug' => false, 'force_https' => str_starts_with($url, 'https://'),
                    'secret_key' => bin2hex(random_bytes(32)), 'storage_path' => '__DIR__/../storage', 'max_upload_mb' => 10],
                'db' => ['host' => $in('db_host'), 'port' => (int) ($in('db_port') ?: 3306), 'name' => $in('db_name'), 'user' => $in('db_user'), 'pass' => (string) ($_POST['db_pass'] ?? ''), 'charset' => 'utf8mb4'],
                // Test installs keep emails in the Test mailbox (the sample accounts use non-deliverable @test.local addresses).
                'mail' => ['driver' => $testMode ? 'log' : ($in('mail_driver') ?: 'smtp'), 'host' => $in('mail_host'), 'port' => (int) ($in('mail_port') ?: 465), 'encryption' => $in('mail_encryption'),
                    'username' => $in('mail_username'), 'password' => (string) ($_POST['mail_password'] ?? ''), 'from_email' => $in('mail_from') ?: $in('mail_username'),
                    'from_name' => ($in('company_name') ?: 'Cityland') . ' Property Bidding', 'timeout' => 15],
                'sms' => ['provider' => 'none', 'api_key' => '', 'sender_name' => 'CITYLAND', 'http_url' => '', 'http_method' => 'POST'],
                'captcha' => ['provider' => 'builtin', 'site_key' => '', 'secret_key' => ''],
                'security' => ['max_login_attempts' => 5, 'max_ip_attempts' => 20, 'lockout_minutes' => 15, 'session_idle_minutes' => 30,
                    'session_absolute_hours' => 8, 'password_min_length' => 10, 'cron_key' => bin2hex(random_bytes(16)), 'trusted_proxies' => []],
            ];
            $php = "<?php\n// Generated by install.php on " . date('c') . ". See app/config.sample.php for documentation of each option.\nreturn " . var_export($config, true) . ";\n";
            $php = str_replace("'__DIR__/../storage'", "__DIR__ . '/../storage'", $php);
            if (file_put_contents($configFile, $php, LOCK_EX) === false) {
                throw new RuntimeException('Could not write app/config.php.');
            }
            @chmod($configFile, 0640);
            foreach (['uploads', 'backups', 'logs', 'sessions'] as $d) {
                @mkdir($root . '/storage/' . $d, 0750, true);
            }
            file_put_contents($lockFile, date('c'));
            $done = ['url' => $url, 'cron_key' => $config['security']['cron_key'], 'triggers' => $triggerNote, 'test' => $testMode, 'seed' => []];
            if ($testMode) {
                @set_time_limit(300);
                require $root . '/app/bootstrap.php';
                $done['seed'] = TestEnv::seed();
            }
        } catch (Throwable $e) {
            $errors[] = 'Installation failed: ' . $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Install · Cityland Online Property Bidding</title><link rel="icon" href="assets/img/favicon-64.png" type="image/png"><script src="assets/js/theme.js"></script><link rel="stylesheet" href="assets/css/app.css"></head>
<body><header class="site-header"><div class="container header-inner"><span class="brand"><span class="logo-chip"><img src="assets/img/logo.png" alt="" width="46" height="46"></span><span class="brand-text"><strong>Cityland</strong><small>Installer</small></span></span></div></header>
<main class="container medium section">
<?php if ($done): ?>
  <div class="card"><h1>Installation complete<?= $done['test'] ? ' — TEST environment' : '' ?></h1>
    <div class="alert alert-success">The database was created and your Super Admin account is ready. <?= h($done['triggers']) ?></div>
    <?php if ($done['test']): ?>
      <div class="alert alert-warning"><strong>Test mode is ON.</strong> A red TEST ENVIRONMENT banner shows on every page, emails go to the
        <a href="<?= h($done['url']) ?>/dev/mailbox.php">test mailbox</a>, and admins get a <strong>Test tools</strong> page. Loaded: <?= h(implode('; ', $done['seed'])) ?>.</div>
      <h2>Test accounts</h2>
      <table class="kv">
        <tr><th>Your Super Admin</th><td><?= h($_POST['admin_email'] ?? '') ?> (the password you chose)</td></tr>
        <?php foreach (TestEnv::ADMINS as [$n, $em, $r]): ?><tr><th><?= h(Rbac::label($r)) ?></th><td><?= h($em) ?> / <code><?= h(TestEnv::ADMIN_PASSWORD) ?></code></td></tr><?php endforeach; ?>
        <?php foreach (TestEnv::BIDDERS as [$n, , $em, , $st]): ?><tr><th>Bidder (<?= h($st) ?>)</th><td><?= h($em) ?> / <code><?= h(TestEnv::BIDDER_PASSWORD) ?></code></td></tr><?php endforeach; ?>
      </table>
      <p class="mt-2">Password-protect this test site in cPanel → <em>Directory Privacy</em> so only your testers can open it. See <code>docs/TESTING.md</code> for the UAT checklist.</p>
    <?php endif; ?>
    <h2>Next steps</h2>
    <ol>
      <li><strong>Delete <code>install.php</code></strong> from the server now.</li>
      <li>Add this cron job in cPanel → <em>Cron Jobs</em> (every minute):<br><code>* * * * * php <?= h($root) ?>/cron.php &gt;/dev/null 2&gt;&amp;1</code><br>or via URL: <code><?= h($done['url']) ?>/cron.php?key=<?= h($done['cron_key']) ?></code></li>
      <li>Enable SSL (cPanel → <em>SSL/TLS Status</em> → AutoSSL) and make sure the site URL starts with https://.</li>
      <li>Sign in to <a href="<?= h($done['url']) ?>/admin/">the admin portal</a>, open <strong>Settings</strong>, send a test email, and review policies, Terms and the Privacy Notice.</li>
      <li>Recommended: move the <code>storage</code> folder outside <code>public_html</code> and update <code>storage_path</code> in <code>app/config.php</code>.</li>
    </ol></div>
<?php else: ?>
  <div class="card"><h1>Install Cityland Online Property Bidding</h1>
    <h2>1. Server requirements</h2>
    <table class="kv"><?php foreach ($checks as $label => $ok): ?><tr><th><?= h($label) ?></th><td><?= $ok ? '<span class="badge badge-green">OK</span>' : (in_array($label, $required, true) || str_contains($label, 'writable') ? '<span class="badge badge-red">Missing</span>' : '<span class="badge badge-amber">Not available</span>') ?></td></tr><?php endforeach; ?></table>
    <?php if (!$requiredOk): ?><div class="alert alert-error mt-2">Please fix the missing requirements (in cPanel → <em>Select PHP Version</em> / file permissions) and reload this page.</div><?php endif; ?>
  </div>
  <?php if ($requiredOk): ?>
  <?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $er): ?><li><?= h($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <form method="post" class="card" autocomplete="off">
    <h2>2. Installation type</h2>
    <label class="check"><input type="radio" name="install_type" value="production" <?= ($_POST['install_type'] ?? 'production') === 'production' ? 'checked' : '' ?>><span><strong>Live site</strong> — clean installation for real bidding.</span></label>
    <label class="check"><input type="radio" name="install_type" value="testing" <?= ($_POST['install_type'] ?? '') === 'testing' ? 'checked' : '' ?>><span><strong>Test / staging site</strong> — turns on test mode (TEST banner, test mailbox, Test tools) and loads sample accounts and properties in every bidding status. Emails stay in the test mailbox. Use a separate subdomain and database, never the live one.</span></label>
    <h2 class="mt-2">Site</h2>
    <div class="form-row"><div class="form-group"><label class="form-label" for="app_url">Site URL (no trailing slash)</label><input class="form-control" id="app_url" name="app_url" value="<?= $v('app_url', $guessUrl) ?>" required></div>
      <div class="form-group"><label class="form-label" for="company_name">Company name</label><input class="form-control" id="company_name" name="company_name" value="<?= $v('company_name', 'Cityland') ?>"></div></div>
    <div class="form-group"><label class="form-label" for="contact_email">Public contact email</label><input class="form-control" type="email" id="contact_email" name="contact_email" value="<?= $v('contact_email') ?>"></div>
    <h2>3. Database (cPanel → MySQL® Databases)</h2>
    <div class="form-row"><div class="form-group"><label class="form-label" for="db_host">Host</label><input class="form-control" id="db_host" name="db_host" value="<?= $v('db_host', 'localhost') ?>" required></div>
      <div class="form-group"><label class="form-label" for="db_port">Port</label><input class="form-control" id="db_port" name="db_port" value="<?= $v('db_port', '3306') ?>"></div></div>
    <div class="form-row-3"><div class="form-group"><label class="form-label" for="db_name">Database name</label><input class="form-control" id="db_name" name="db_name" value="<?= $v('db_name') ?>" required placeholder="cpaneluser_bidding"></div>
      <div class="form-group"><label class="form-label" for="db_user">Database user</label><input class="form-control" id="db_user" name="db_user" value="<?= $v('db_user') ?>" required></div>
      <div class="form-group"><label class="form-label" for="db_pass">Password</label><input class="form-control" type="password" id="db_pass" name="db_pass"></div></div>
    <h2>4. Email (SMTP) <small class="muted">— can be changed later in app/config.php</small></h2>
    <div class="form-row-3"><div class="form-group"><label class="form-label" for="mail_driver">Driver</label><select class="form-control" id="mail_driver" name="mail_driver"><option value="smtp">SMTP (recommended)</option><option value="mail">PHP mail()</option><option value="log">Log only (testing)</option></select></div>
      <div class="form-group"><label class="form-label" for="mail_host">SMTP host</label><input class="form-control" id="mail_host" name="mail_host" value="<?= $v('mail_host', 'mail.' . preg_replace('/^www\./', '', (string) ($_SERVER['HTTP_HOST'] ?? 'example.com'))) ?>"></div>
      <div class="form-group"><label class="form-label" for="mail_port">Port / encryption</label><div class="btn-row"><input class="form-control" id="mail_port" name="mail_port" value="<?= $v('mail_port', '465') ?>" style="max-width:90px"><select class="form-control" name="mail_encryption" style="max-width:120px"><option value="ssl">SSL</option><option value="tls">STARTTLS</option><option value="">None</option></select></div></div></div>
    <div class="form-row-3"><div class="form-group"><label class="form-label" for="mail_username">SMTP username</label><input class="form-control" id="mail_username" name="mail_username" value="<?= $v('mail_username') ?>"></div>
      <div class="form-group"><label class="form-label" for="mail_password">SMTP password</label><input class="form-control" type="password" id="mail_password" name="mail_password"></div>
      <div class="form-group"><label class="form-label" for="mail_from">From address</label><input class="form-control" id="mail_from" name="mail_from" value="<?= $v('mail_from') ?>"></div></div>
    <h2>5. Super Admin account</h2>
    <div class="form-row-3"><div class="form-group"><label class="form-label" for="admin_name">Full name</label><input class="form-control" id="admin_name" name="admin_name" value="<?= $v('admin_name') ?>" required></div>
      <div class="form-group"><label class="form-label" for="admin_email">Email</label><input class="form-control" type="email" id="admin_email" name="admin_email" value="<?= $v('admin_email') ?>" required></div>
      <div class="form-group"><label class="form-label" for="admin_password">Password</label><input class="form-control" type="password" id="admin_password" name="admin_password" required minlength="10" autocomplete="new-password"></div></div>
    <button class="btn btn-gold btn-lg" type="submit">Install</button>
  </form>
  <?php endif; ?>
<?php endif; ?>
</main></body></html>
