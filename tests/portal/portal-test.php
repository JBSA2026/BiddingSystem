<?php
// End-to-end test of license-portal/ against the mock PayMongo. Run via tests/portal/run.sh
declare(strict_types=1);
$W = getenv('LP_WORK'); $P = getenv('LP_BASE'); $M = getenv('MOCK_BASE'); $root = dirname(__DIR__, 2);
$fails = 0;
function check(string $l, bool $ok, string $x = ''): void { global $fails; echo ($ok ? 'PASS ' : 'FAIL ') . $l . ($ok || $x === '' ? '' : "  → $x") . "\n"; if (!$ok) $fails++; }
function http(string $url, ?array $post = null, array $headers = [], ?string $raw = null): array {
    global $W;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => "$W/cookies", CURLOPT_COOKIEFILE => "$W/cookies", CURLOPT_HTTPHEADER => $headers]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    if ($raw !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $raw); }
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    $head = substr($r, 0, $hs); preg_match('/^Location: (.*)$/mi', $head, $m);
    return ['code' => $code, 'body' => substr($r, $hs), 'location' => trim($m[1] ?? '')];
}
function issue(array $args): string {
    global $W, $root;
    $cmd = 'php ' . escapeshellarg("$root/license-tools/generate.php") . ' issue --key=' . escapeshellarg("$W/cityland-license-signing-key.json");
    foreach ($args as $k => $v) { $cmd .= ' --' . $k . '=' . escapeshellarg((string) $v); }
    return trim((string) shell_exec($cmd . ' 2>/dev/null'));
}
function payload(string $key): array { [$p] = explode('.', substr($key, 5)); return json_decode(base64_decode(strtr($p, '-_', '+/')), true); }
function csrf(string $html): string { preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m); return $m[1] ?? ''; }
function buy(string $query, array $fields): array {
    global $P;
    $r = http("$P/index.php?$query");
    $post = $fields + ['csrf' => csrf($r['body'])];
    if (preg_match('/name="key" value="([^"]*)"/', $r['body'], $m)) { $post += ['key' => html_entity_decode($m[1])]; }
    if (preg_match('/name="return" value="([^"]*)"/', $r['body'], $m)) { $post += ['return' => html_entity_decode($m[1])]; }
    return [$r, http("$P/index.php", $post)];
}
$today = new DateTimeImmutable('today');

// 1. Early renewal of an active Standard license (expires in 100 days) → +1 year from current expiry, upgraded plan.
$cur = issue(['licensee' => 'Cityland Test Corp', 'domains' => 'bid.example.com', 'plan' => 'Standard', 'expires' => $today->modify('+100 days')->format('Y-m-d'), 'lid' => 'CLB-2026-TEST01', 'max-admins' => 5, 'max-properties' => 25]);
check('Test license issued', str_starts_with($cur, 'CLB1-'), $cur);
[$page, $r] = buy(http_build_query(['key' => $cur, 'domain' => 'bid.example.com', 'return' => 'https://bid.example.com/admin/license.php']), ['plan' => 'Professional', 'years' => '1', 'email' => 'admin@example.com']);
check('Renew page shows current license', str_contains($page['body'], 'Cityland Test Corp') && str_contains($page['body'], 'CLB-2026-TEST01') && str_contains($page['body'], 'Renewing early is safe'));
check('Order redirects to PayMongo checkout', $r['code'] === 303 && str_starts_with($r['location'], "$M/pay?cs="), $r['code'] . ' ' . $r['location'] . ' ' . strip_tags(substr($r['body'], 0, 400)));
$store = json_decode(file_get_contents(getenv('MOCK_STORE')), true);
$sess = end($store)['attributes'];
check('Checkout amount = Professional 1 year (₱60,000)', $sess['line_items'][0]['amount'] === 6000000 && $sess['line_items'][0]['currency'] === 'PHP');
check('Checkout offers GCash/Maya/card/QR Ph', count(array_intersect(['card', 'gcash', 'paymaya', 'qrph'], $sess['payment_method_types'])) === 4);
$token = $sess['metadata']['order_token'];
$s = http("$P/success.php?order=$token");
check('Unpaid order is not issued', str_contains($s['body'], 'Waiting for payment') && !str_contains($s['body'], 'CLB1-'));
$pay = http($r['location']);
$s = http($pay['location']);
check('Success page shows issued key after payment', str_contains($s['body'], 'payment received') && preg_match('/(CLB1-[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+)/', $s['body'], $km) === 1);
$newKey = $km[1] ?? '';
$np = payload($newKey);
$expect = $today->modify('+100 days')->modify('+1 year')->format('Y-m-d');
check('Early renewal adds to current expiry', ($np['expires'] ?? '') === $expect, ($np['expires'] ?? '') . " vs $expect");
check('Renewed key keeps License ID, licensee, domain; new plan limits', $np['lid'] === 'CLB-2026-TEST01' && $np['licensee'] === 'Cityland Test Corp' && $np['domains'] === ['bid.example.com'] && $np['plan'] === 'Professional' && $np['max_admins'] === 15 && $np['max_properties'] === 100);
$v = shell_exec('php ' . escapeshellarg("$root/license-tools/generate.php") . ' inspect ' . escapeshellarg($newKey) . ' --key=' . escapeshellarg("$W/cityland-license-signing-key.json"));
check('Issued key signature is valid', str_contains((string) $v, 'Signature: VALID'));
check('Back-to-admin link offered', str_contains($s['body'], 'https://bid.example.com/admin/license.php'));
$s2 = http("$P/success.php?order=$token");
check('Reloading success page is idempotent (same key)', str_contains($s2['body'], $newKey));

// 2. Client fetch API
$a = json_decode(http("$P/api.php?lid=CLB-2026-TEST01&domain=bid.example.com")['body'], true);
check('API returns renewed key for lid + domain', ($a['ok'] ?? false) && $a['license_key'] === $newKey);
$a = json_decode(http("$P/api.php?lid=CLB-2026-TEST01&domain=other.com")['body'], true);
check('API refuses a different domain', ($a['ok'] ?? true) === false);

// 3. Webhook
$wbody = json_encode(['data' => ['attributes' => ['type' => 'checkout_session.payment.paid', 'data' => ['id' => array_key_last($store), 'attributes' => ['metadata' => ['order_token' => $token]]]]]]);
$w = http("$P/webhook.php", null, ['Paymongo-Signature: t=' . time() . ',te=deadbeef'], $wbody);
check('Webhook with bad signature rejected (401)', $w['code'] === 401);
$t = (string) time();
$w = http("$P/webhook.php", null, ['Paymongo-Signature: t=' . $t . ',te=' . hash_hmac('sha256', "$t.$wbody", 'whsk_test'), 'Content-Type: application/json'], $wbody);
check('Webhook with valid signature accepted (already issued)', $w['code'] === 200 && str_contains($w['body'], 'issued'), $w['code'] . ' ' . $w['body']);
$old = time() - 3600;
$w = http("$P/webhook.php", null, ['Paymongo-Signature: t=' . $old . ',te=' . hash_hmac('sha256', "$old.$wbody", 'whsk_test')], $wbody);
check('Replayed old webhook rejected', $w['code'] === 401);

// 4. Webhook-only fulfilment (customer closed the browser before the success page)
$trial = issue(['licensee' => 'Trial Co', 'domains' => 'trial.example.com', 'plan' => 'Trial', 'expires' => $today->modify('-3 days')->format('Y-m-d'), 'lid' => 'CLB-2026-TRIAL1']);
[$page, $r] = buy(http_build_query(['key' => $trial, 'domain' => 'trial.example.com']), ['plan' => 'Standard', 'years' => '2', 'email' => 'trial@example.com']);
check('Expired trial can subscribe', str_contains($page['body'], 'expired') && $r['code'] === 303);
$store = json_decode(file_get_contents(getenv('MOCK_STORE')), true);
$cs = array_key_last($store);
check('2-year term discount applied (₱30,000 × 2 − 5%)', $store[$cs]['attributes']['line_items'][0]['amount'] === 5700000);
$tok = $store[$cs]['attributes']['metadata']['order_token'];
http("$M/pay?cs=$cs"); // pays, but we do not follow the redirect
$wbody = json_encode(['data' => ['attributes' => ['type' => 'checkout_session.payment.paid', 'data' => ['id' => $cs, 'attributes' => ['metadata' => ['order_token' => $tok]]]]]]);
$t = (string) time();
$w = http("$P/webhook.php", null, ['Paymongo-Signature: t=' . $t . ',li=' . hash_hmac('sha256', "$t.$wbody", 'whsk_test')], $wbody);
check('Webhook alone issues the key', $w['code'] === 200 && str_contains($w['body'], 'issued'), $w['body']);
$a = json_decode(http("$P/api.php?lid=CLB-2026-TRIAL1&domain=trial.example.com")['body'], true);
check('Trial → paid starts today (+2 years)', ($a['ok'] ?? false) && payload($a['license_key'])['expires'] === $today->modify('+2 years')->format('Y-m-d') && $a['plan'] === 'Standard');

// 5. Underpayment is never issued
[, $r] = buy(http_build_query(['key' => $cur, 'domain' => 'bid.example.com']), ['plan' => 'Enterprise', 'years' => '1', 'email' => 'admin@example.com']);
$pay = http($r['location'] . '&amount=100');
$s = http($pay['location']);
check('Underpaid order not issued', !preg_match('/CLB1-/', $s['body']));

// 6. New purchase without a key; open-redirect protection; tampered key
[$page, $r] = buy(http_build_query(['domain' => 'new.example.com', 'return' => 'https://evil.example.net/']), ['plan' => 'Standard', 'years' => '1', 'email' => 'new@example.com', 'licensee' => 'New Buyer Inc.', 'domains' => 'new.example.com']);
check('New purchase (no key) goes to checkout', str_contains($page['body'], 'Buy a license') && $r['code'] === 303);
$pay = http($r['location']); $s = http($pay['location']);
check('Foreign return URL is not offered', !str_contains($s['body'], 'evil.example.net') && str_contains($s['body'], 'CLB1-'));
$bad = substr($cur, 0, -3) . 'AAA';
$page = http("$P/index.php?" . http_build_query(['key' => $bad]));
check('Tampered key is not trusted', str_contains($page['body'], 'could not be verified') && !str_contains($page['body'], 'Cityland Test Corp'));
$r = http("$P/index.php", ['csrf' => 'x', 'plan' => 'Standard', 'years' => '1', 'email' => 'a@b.co', 'licensee' => 'X', 'domains' => 'x.com']);
check('CSRF enforced on order form', str_contains($r['body'], 'session expired') && $r['code'] === 200);

echo $fails ? "\n{$fails} FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
