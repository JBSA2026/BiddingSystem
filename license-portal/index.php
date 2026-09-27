<?php
/**
 * Renewal / purchase page. Client sites link here from Admin → License:
 *   index.php?key=<current license key>&domain=<site domain>&return=<admin license page URL>
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$token = csrf_token();
$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$currentKey = preg_replace('/\s+/', '', (string) ($src['key'] ?? '')) ?? '';
$current = $currentKey !== '' ? read_license($currentKey) : null;
$domainIn = strtolower(trim((string) ($src['domain'] ?? '')));
$domainIn = preg_replace('/^www\./', '', $domainIn) ?? '';
$return = (string) ($src['return'] ?? '');
$errors = [];

// Only return to an https page on one of the licensed domains (no open redirect).
$returnOk = static function (string $url, array $domains): bool {
    $p = parse_url($url);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
        return false;
    }
    $host = preg_replace('/^www\./', '', strtolower($p['host']));
    foreach ($domains as $d) {
        $d = preg_replace('/^www\./', '', strtolower((string) $d));
        if ($host === $d || (str_starts_with($d, '*.') && str_ends_with($host, substr($d, 1)))) {
            return true;
        }
    }
    return false;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($token, (string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Your session expired. Please try again.';
    }
    $plan = (string) ($_POST['plan'] ?? '');
    $years = (int) ($_POST['years'] ?? 0);
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if (!isset(plans()[$plan])) {
        $errors[] = 'Choose a plan.';
    }
    if (!array_key_exists($years, (array) cfg('term_discounts'))) {
        $errors[] = 'Choose a term.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address for the receipt and license key.';
    }
    if ($current) {
        $kind = 'renewal';
        $lid = (string) $current['lid'];
        $licensee = (string) $current['licensee'];
        $domains = array_values((array) $current['domains']);
        $grace = (int) ($current['grace_days'] ?? cfg('grace_days', 7));
    } else {
        $kind = 'new';
        $lid = 'CLB-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $licensee = mb_substr(trim((string) ($_POST['licensee'] ?? '')), 0, 150);
        $domains = array_values(array_filter(array_map(static fn($d) => preg_replace('/^www\./', '', strtolower(trim($d))),
            preg_split('/[\s,;]+/', (string) ($_POST['domains'] ?? '')) ?: [])));
        $grace = (int) cfg('grace_days', 7);
        if ($licensee === '') {
            $errors[] = 'Enter the company or organization name (licensee).';
        }
        if (!$domains) {
            $errors[] = 'Enter the website domain, e.g. bidding.yourcompany.com.';
        }
        foreach ($domains as $d) {
            if (!preg_match('/^(\*\.)?[a-z0-9-]+(\.[a-z0-9-]+)+$/', (string) $d)) {
                $errors[] = 'Domain "' . $d . '" is not valid.';
            }
        }
    }
    if (!$errors) {
        $p = plans()[$plan];
        $order = [
            'token' => bin2hex(random_bytes(20)), 'kind' => $kind, 'lid' => $lid, 'licensee' => $licensee,
            'domains' => json_encode($domains, JSON_UNESCAPED_SLASHES), 'email' => $email, 'plan' => $plan, 'years' => $years,
            'amount' => price_for($plan, $years), 'old_expires' => $current['expires'] ?? null,
            'new_expires' => new_expiry($current['expires'] ?? null, $current['plan'] ?? null, $years),
            'grace_days' => $grace, 'max_admins' => (int) $p['max_admins'], 'max_properties' => (int) $p['max_properties'],
            'return_url' => $returnOk($return, $domains) ? $return : null, 'created_at' => date('c'),
        ];
        if ($order['amount'] < 2000) {
            $errors[] = 'This plan has no price configured.';
        } else {
            $cols = array_keys($order);
            db()->prepare('INSERT INTO orders (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($order));
            $order['id'] = (int) db()->lastInsertId();
            try {
                $co = create_checkout($order);
                db()->prepare('UPDATE orders SET checkout_id = ? WHERE id = ?')->execute([$co['id'], $order['id']]);
                log_line("order {$order['id']}: checkout {$co['id']} for {$lid} {$plan} {$years}y " . peso($order['amount']));
                header('Location: ' . $co['url'], true, 303);
                exit;
            } catch (Throwable $e) {
                log_line("order {$order['id']}: checkout failed: " . $e->getMessage());
                $errors[] = 'We could not start the payment. Please try again in a few minutes. (' . $e->getMessage() . ')';
            }
        }
    }
}

$cancelled = isset($_GET['cancelled']);
$today = new DateTimeImmutable('today');
$curExp = $current ? new DateTimeImmutable((string) $current['expires']) : null;
page_start($current ? 'Renew license' : 'Buy a license');
?>
<h1><?= $current ? 'Renew or upgrade your license' : 'Buy a license' ?></h1>
<?php if ($cancelled): ?><div class="alert warn">The payment was cancelled. Nothing was charged. You can try again below.</div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="alert err"><?= h($er) ?></div><?php endforeach; ?>
<?php if ($currentKey !== '' && !$current): ?><div class="alert warn">The license key sent from your site could not be verified. You can still buy a new license below, or contact <?= h(cfg('vendor_email')) ?>.</div><?php endif; ?>

<form method="post" class="card">
  <input type="hidden" name="csrf" value="<?= h($token) ?>">
  <input type="hidden" name="key" value="<?= h($current ? $currentKey : '') ?>">
  <input type="hidden" name="return" value="<?= h($return) ?>">
  <?php if ($current): ?>
    <h2>Your current license</h2>
    <table class="kv">
      <tr><th>Licensed to</th><td><strong><?= h($current['licensee']) ?></strong></td></tr>
      <tr><th>License ID</th><td><?= h($current['lid']) ?></td></tr>
      <tr><th>Domain(s)</th><td><?= h(implode(', ', (array) $current['domains'])) ?></td></tr>
      <tr><th>Plan</th><td><?= h($current['plan'] ?? '—') ?></td></tr>
      <tr><th>Expires</th><td><?= h($curExp->format('F j, Y')) ?> <?= $curExp < $today ? '<span class="tag red">expired</span>' : '<span class="tag">' . (int) $today->diff($curExp)->days . ' day(s) left</span>' ?></td></tr>
    </table>
    <p class="muted small"><?= $curExp > $today && strcasecmp((string) ($current['plan'] ?? ''), 'Trial') !== 0
        ? 'Renewing early is safe: the new term is added to your current expiry date, so you do not lose any days.'
        : 'The new term starts today.' ?></p>
  <?php else: ?>
    <h2>License details</h2>
    <label>Company / organization (licensee)<input name="licensee" required maxlength="150" value="<?= h($_POST['licensee'] ?? '') ?>"></label>
    <label>Website domain(s)<input name="domains" required value="<?= h($_POST['domains'] ?? $domainIn) ?>" placeholder="bidding.yourcompany.com"><small class="muted">The address of the site where the Bidding System is installed. Separate several with commas.</small></label>
  <?php endif; ?>

  <h2>Choose a plan</h2>
  <div class="plans">
    <?php $sel = (string) ($_POST['plan'] ?? ($current['plan'] ?? '')); if (!isset(plans()[$sel])) { $sel = (string) array_key_first(plans()); }
    foreach (plans() as $name => $p): ?>
      <label class="plan"><input type="radio" name="plan" value="<?= h($name) ?>" data-price="<?= (int) round($p['price'] * 100) ?>" <?= $sel === $name ? 'checked' : '' ?>>
        <span><strong><?= h($name) ?></strong><em><?= h(peso((int) round($p['price'] * 100))) ?> / year</em><small><?= h($p['label'] ?? '') ?></small></span></label>
    <?php endforeach; ?>
  </div>
  <h2>Term</h2>
  <div class="plans terms">
    <?php $ysel = (int) ($_POST['years'] ?? 1); foreach ((array) cfg('term_discounts') as $y => $d): ?>
      <label class="plan"><input type="radio" name="years" value="<?= (int) $y ?>" data-discount="<?= h($d) ?>" <?= $ysel === (int) $y ? 'checked' : '' ?>>
        <span><strong><?= (int) $y ?> year<?= $y > 1 ? 's' : '' ?></strong><?php if ($d > 0): ?><small>Save <?= h($d) ?>%</small><?php endif; ?></span></label>
    <?php endforeach; ?>
  </div>
  <label>Email for the receipt and license key<input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>"></label>
  <p class="total">Total: <strong id="total">—</strong> <small class="muted">VAT-inclusive</small></p>
  <button class="btn" type="submit">Continue to secure payment</button>
  <p class="muted small">You can pay with GCash, Maya, GrabPay, QR Ph, or a credit/debit card through PayMongo. Your license key is shown right after payment and emailed to you.</p>
</form>
<?php page_end();
