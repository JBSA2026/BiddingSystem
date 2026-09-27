<?php
/**
 * Cityland Bidding — LICENSE RENEWAL PORTAL (vendor-hosted). Shared code.
 * Deploy this folder on YOUR OWN hosting (e.g. https://license.yourdomain.com/), never on a client server.
 */
declare(strict_types=1);

const PORTAL_PRODUCT = 'cityland-bidding';

function cfg(?string $key = null, mixed $default = null): mixed
{
    static $c = null;
    if ($c === null) {
        $file = getenv('LP_CONFIG') ?: __DIR__ . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('Renewal portal not configured: copy config.sample.php to config.php and fill it in.');
        }
        $c = require $file;
    }
    if ($key === null) {
        return $c;
    }
    $v = $c;
    foreach (explode('.', $key) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) {
            return $default;
        }
        $v = $v[$k];
    }
    return $v;
}

function h(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function peso(int $centavos): string
{
    return '₱' . number_format($centavos / 100, 2);
}

function portal_url(string $path = ''): string
{
    return rtrim((string) cfg('portal_url'), '/') . '/' . ltrim($path, '/');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $file = (string) cfg('data_dir') . '/orders.sqlite';
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0700, true);
        }
        $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT NOT NULL UNIQUE,
            kind TEXT NOT NULL,              -- renewal | new
            lid TEXT NOT NULL,
            licensee TEXT NOT NULL,
            domains TEXT NOT NULL,           -- JSON array
            email TEXT NOT NULL,
            plan TEXT NOT NULL,
            years INTEGER NOT NULL,
            amount INTEGER NOT NULL,         -- centavos
            old_expires TEXT,
            new_expires TEXT NOT NULL,
            grace_days INTEGER NOT NULL DEFAULT 7,
            max_admins INTEGER NOT NULL DEFAULT 0,
            max_properties INTEGER NOT NULL DEFAULT 0,
            return_url TEXT,
            status TEXT NOT NULL DEFAULT \'pending\',  -- pending | paid (awaiting manual issue) | issued
            checkout_id TEXT,
            payment_ref TEXT,
            livemode INTEGER,
            license_key TEXT,
            created_at TEXT NOT NULL,
            paid_at TEXT,
            issued_at TEXT
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_lid ON orders (lid, status)');
    }
    return $pdo;
}

function order_by_token(string $token): ?array
{
    $st = db()->prepare('SELECT * FROM orders WHERE token = ?');
    $st->execute([$token]);
    return $st->fetch() ?: null;
}

function log_line(string $msg): void
{
    @file_put_contents((string) cfg('data_dir') . '/portal.log', date('c') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

// ------------------------------------------------------------------ license keys
function b64u(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64ud(string $s): ?string
{
    $d = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    return $d === false ? null : $d;
}

/** Signing key (JSON file from license-tools), or null when keys are issued manually. */
function signing_key(): ?array
{
    $file = (string) cfg('signing_key_file', '');
    if ($file === '' || !is_file($file)) {
        return null;
    }
    $k = json_decode((string) file_get_contents($file), true);
    return (is_array($k) && ($k['type'] ?? '') === 'cityland-bidding-license-signing-key') ? $k : null;
}

/** Public keys trusted when reading a customer's CURRENT key (expired keys are accepted — they are being renewed). */
function trusted_public_keys(): array
{
    $keys = (array) cfg('public_keys', []);
    if ($k = signing_key()) {
        $keys[] = $k['public'];
    }
    return array_values(array_unique(array_filter($keys)));
}

/** Verify a license key's signature; returns the payload or null. Expiry is NOT checked. */
function read_license(string $key): ?array
{
    $key = preg_replace('/\s+/', '', $key) ?? '';
    if (!str_starts_with($key, 'CLB1-') || substr_count($key, '.') !== 1) {
        return null;
    }
    [$p, $s] = explode('.', substr($key, 5), 2);
    $json = b64ud($p);
    $sig = b64ud($s);
    if ($json === null || $sig === null || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
        return null;
    }
    foreach (trusted_public_keys() as $pub) {
        $pk = base64_decode($pub, true);
        if ($pk !== false && strlen($pk) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && sodium_crypto_sign_verify_detached($sig, $json, $pk)) {
            $payload = json_decode($json, true);
            return (is_array($payload) && ($payload['product'] ?? '') === PORTAL_PRODUCT) ? $payload : null;
        }
    }
    return null;
}

function sign_license(array $payload, array $key): string
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $sk = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair((string) base64_decode($key['seed'])));
    return 'CLB1-' . b64u($json) . '.' . b64u(sodium_crypto_sign_detached($json, $sk));
}

// ------------------------------------------------------------------ pricing
/** @return array<string, array{price:float, max_admins:int, max_properties:int, label?:string}> */
function plans(): array
{
    return (array) cfg('plans', []);
}

/** Price in centavos for a plan and term, after the multi-year discount. */
function price_for(string $plan, int $years): int
{
    $base = (int) round(((float) (plans()[$plan]['price'] ?? 0)) * 100);
    $disc = (float) (cfg('term_discounts')[$years] ?? 0);
    return (int) round($base * $years * (100 - $disc) / 100);
}

/** New expiry: renewing early adds to the current expiry; after expiry (or from a trial) it counts from today. */
function new_expiry(?string $currentExpires, ?string $currentPlan, int $years): string
{
    $today = new DateTimeImmutable('today');
    $base = $today;
    if ($currentExpires && strcasecmp((string) $currentPlan, 'Trial') !== 0) {
        $cur = new DateTimeImmutable($currentExpires);
        if ($cur > $today) {
            $base = $cur;
        }
    }
    return $base->modify('+' . $years . ' year')->format('Y-m-d');
}

// ------------------------------------------------------------------ PayMongo
function paymongo(string $method, string $path, ?array $body = null): array
{
    $ch = curl_init(rtrim((string) cfg('paymongo.api_base', 'https://api.paymongo.com'), '/') . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERPWD => cfg('paymongo.secret_key') . ':',
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException('Could not reach PayMongo: ' . $err);
    }
    $json = json_decode((string) $raw, true);
    if ($code >= 400 || !is_array($json)) {
        $detail = $json['errors'][0]['detail'] ?? ('HTTP ' . $code);
        throw new RuntimeException('PayMongo error: ' . $detail);
    }
    return $json;
}

function create_checkout(array $order): array
{
    $plan = $order['plan'];
    $name = 'Cityland Bidding — ' . $plan . ' license, ' . $order['years'] . ' year' . ($order['years'] > 1 ? 's' : '');
    $res = paymongo('POST', '/v1/checkout_sessions', ['data' => ['attributes' => [
        'line_items' => [['currency' => 'PHP', 'amount' => (int) $order['amount'], 'name' => $name, 'quantity' => 1,
            'description' => 'License ' . $order['lid'] . ' · ' . implode(', ', json_decode($order['domains'], true)) . ' · valid until ' . $order['new_expires']]],
        'payment_method_types' => array_values((array) cfg('paymongo.payment_methods', ['card', 'gcash', 'paymaya', 'grab_pay', 'qrph'])),
        'description' => $name . ' for ' . $order['licensee'],
        'reference_number' => 'CLB-ORDER-' . $order['id'],
        'send_email_receipt' => true,
        'show_description' => true,
        'show_line_items' => true,
        'billing' => ['name' => $order['licensee'], 'email' => $order['email']],
        'success_url' => portal_url('success.php?order=' . $order['token']),
        'cancel_url' => portal_url('index.php?cancelled=' . $order['token']),
        'metadata' => ['order_token' => $order['token'], 'lid' => $order['lid']],
    ]]]);
    return ['id' => (string) $res['data']['id'], 'url' => (string) $res['data']['attributes']['checkout_url']];
}

/** Ask PayMongo whether the checkout was paid, and for how much. Never trusts the browser. */
function checkout_paid(string $checkoutId): ?array
{
    $res = paymongo('GET', '/v1/checkout_sessions/' . rawurlencode($checkoutId));
    $a = $res['data']['attributes'] ?? [];
    foreach ((array) ($a['payments'] ?? []) as $p) {
        if (($p['attributes']['status'] ?? '') === 'paid') {
            return ['amount' => (int) $p['attributes']['amount'], 'ref' => (string) ($p['id'] ?? ''), 'livemode' => !empty($a['livemode'] ?? $p['attributes']['livemode'] ?? false)];
        }
    }
    return null;
}

/** Verify the Paymongo-Signature header (t=…,te=…,li=…) against the raw request body. */
function webhook_signature_ok(string $header, string $body, string $secret): bool
{
    $parts = [];
    foreach (explode(',', $header) as $kv) {
        [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
        $parts[$k] = $v;
    }
    if (empty($parts['t']) || $secret === '') {
        return false;
    }
    if (abs(time() - (int) $parts['t']) > 600) {
        return false; // replay protection
    }
    $expected = hash_hmac('sha256', $parts['t'] . '.' . $body, $secret);
    foreach (['te', 'li'] as $k) {
        if (!empty($parts[$k]) && hash_equals($expected, $parts[$k])) {
            return true;
        }
    }
    return false;
}

// ------------------------------------------------------------------ fulfilment
/**
 * Mark an order paid (after PayMongo confirms it) and issue the renewed license key.
 * Idempotent: safe to call from both the webhook and the success page.
 */
function fulfil(array $order): array
{
    if ($order['status'] === 'issued' || !$order['checkout_id']) {
        return $order;
    }
    if ($order['status'] === 'pending') {
        $paid = checkout_paid((string) $order['checkout_id']);
        if (!$paid) {
            return $order;
        }
        if ($paid['amount'] < (int) $order['amount']) {
            log_line("order {$order['id']}: paid amount {$paid['amount']} is below {$order['amount']} — not issued");
            return $order;
        }
        $st = db()->prepare("UPDATE orders SET status = 'paid', paid_at = ?, payment_ref = ?, livemode = ? WHERE id = ? AND status = 'pending'");
        $st->execute([date('c'), $paid['ref'], $paid['livemode'] ? 1 : 0, $order['id']]);
        $justPaid = $st->rowCount() === 1;
        if ($justPaid) {
            log_line("order {$order['id']}: paid ({$paid['ref']})");
        }
        $order = order_by_token($order['token']);
        if ($justPaid && !signing_key()) {
            notify_vendor_manual($order);
        }
    }
    $key = signing_key();
    if ($order['status'] === 'paid' && $key) {
        $payload = [
            'v' => 1, 'product' => PORTAL_PRODUCT, 'lid' => $order['lid'], 'licensee' => $order['licensee'],
            'domains' => json_decode($order['domains'], true), 'plan' => $order['plan'],
            'issued' => date('Y-m-d'), 'expires' => $order['new_expires'], 'grace_days' => (int) $order['grace_days'],
            'max_admins' => (int) $order['max_admins'], 'max_properties' => (int) $order['max_properties'],
            'notes' => ($order['kind'] === 'renewal' ? 'Renewed' : 'Purchased') . ' online (order ' . $order['id'] . ', PayMongo ' . $order['payment_ref'] . ')',
        ];
        $license = sign_license($payload, $key);
        $st = db()->prepare("UPDATE orders SET status = 'issued', license_key = ?, issued_at = ? WHERE id = ? AND status = 'paid'");
        $st->execute([$license, date('c'), $order['id']]);
        if ($st->rowCount() === 1) {
            log_line("order {$order['id']}: license issued, expires {$order['new_expires']}");
            $order = order_by_token($order['token']);
            notify_issued($order);
        }
        return order_by_token($order['token']);
    }
    return $order;
}

function send_mail(string $to, string $subject, string $text): void
{
    $from = (string) cfg('mail_from', '');
    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    if ($from !== '') {
        $headers[] = 'From: ' . $from;
    }
    if (!@mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, implode("\r\n", $headers))) {
        log_line("mail to {$to} failed: {$subject}");
    }
}

function notify_issued(array $o): void
{
    $text = "Thank you for your payment.\n\n"
        . "License ID: {$o['lid']}\nLicensee: {$o['licensee']}\nDomain(s): " . implode(', ', json_decode($o['domains'], true)) . "\n"
        . "Plan: {$o['plan']}\nValid until: {$o['new_expires']}\nAmount paid: " . peso((int) $o['amount']) . "\n\n"
        . "Your license key:\n\n{$o['license_key']}\n\n"
        . "To install it: sign in to the admin portal as Super Admin, open License, and click \"Check for my renewed license\" "
        . "(or paste the key above and click Verify & install license).\n";
    send_mail($o['email'], 'Your Cityland Bidding license key (' . $o['lid'] . ')', $text);
    if ($v = (string) cfg('vendor_email', '')) {
        send_mail($v, "[License portal] Issued {$o['lid']} — {$o['licensee']} — " . peso((int) $o['amount']), $text);
    }
}

function notify_vendor_manual(array $o): void
{
    if ($v = (string) cfg('vendor_email', '')) {
        send_mail($v, "[License portal] PAID — issue license {$o['lid']} for {$o['licensee']}",
            "Order {$o['id']} was paid via PayMongo ({$o['payment_ref']}). Automatic issuing is off, so please issue this key:\n\n"
            . "License ID: {$o['lid']}\nLicensee: {$o['licensee']}\nDomain(s): " . implode(', ', json_decode($o['domains'], true)) . "\n"
            . "Plan: {$o['plan']}\nExpires: {$o['new_expires']}\nGrace days: {$o['grace_days']}\nMax admins: {$o['max_admins']}\nMax properties: {$o['max_properties']}\n"
            . "Customer email: {$o['email']}\n");
    }
}

// ------------------------------------------------------------------ page chrome
function page_start(string $title): void
{
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($title) . ' · ' . h(cfg('vendor_name', 'License portal')) . '</title><link rel="stylesheet" href="portal.css"></head><body>'
        . '<header class="top"><div class="wrap"><strong>' . h(cfg('vendor_name', 'License portal')) . '</strong><span>Cityland Bidding · License renewal</span></div></header><main class="wrap">';
}

function page_end(): void
{
    echo '</main><footer class="wrap foot">Payments are processed securely by PayMongo. Questions? '
        . h(cfg('vendor_email', '')) . '</footer><script src="portal.js"></script></body></html>';
}

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_secure' => !empty($_SERVER['HTTPS'])]);
    }
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}
