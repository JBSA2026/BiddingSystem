<?php
/** PayMongo returns here after payment. The order is confirmed with PayMongo's API, never from the browser. */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$order = order_by_token((string) ($_GET['order'] ?? ''));
if (!$order) {
    http_response_code(404);
    page_start('Order not found');
    echo '<div class="alert err">Order not found.</div>';
    page_end();
    exit;
}
$error = null;
try {
    $order = fulfil($order);
} catch (Throwable $e) {
    $error = $e->getMessage();
    log_line("order {$order['id']}: success page check failed: {$error}");
}
page_start('Payment');
?>
<?php if ($order['status'] === 'issued'): ?>
  <h1>Thank you — payment received</h1>
  <div class="card">
    <p>Your license <strong><?= h($order['lid']) ?></strong> (<?= h($order['plan']) ?>) is valid until <strong><?= h(date('F j, Y', strtotime($order['new_expires']))) ?></strong>. A copy has been emailed to <?= h($order['email']) ?>.</p>
    <h2>Install it (one click)</h2>
    <ol>
      <li>Go back to your site's admin portal → <strong>License</strong>.</li>
      <li>Click <strong>Check for my renewed license</strong>. The new key is downloaded and installed automatically.</li>
    </ol>
    <?php if ($order['return_url']): ?><p><a class="btn" href="<?= h($order['return_url']) ?>">Back to my admin portal</a></p><?php endif; ?>
    <h2>Or paste the key manually</h2>
    <textarea class="key" readonly rows="6" id="key"><?= h($order['license_key']) ?></textarea>
    <p><button class="btn ghost" type="button" data-copy="key">Copy key</button></p>
  </div>
<?php elseif ($order['status'] === 'paid'): ?>
  <h1>Thank you — payment received</h1>
  <div class="card"><p>Your payment for license <strong><?= h($order['lid']) ?></strong> was received. Your new license key will be emailed to <strong><?= h($order['email']) ?></strong> within one business day.</p>
  <p>After you receive it, open your admin portal → <strong>License</strong> and click <strong>Check for my renewed license</strong>, or paste the key.</p></div>
<?php else: ?>
  <h1>Waiting for payment confirmation</h1>
  <div class="card">
    <p>We have not received confirmation from PayMongo yet. This usually takes a few seconds (e-wallet and QR Ph payments can take a little longer).</p>
    <?php if ($error): ?><p class="muted small">(<?= h($error) ?>)</p><?php endif; ?>
    <p><a class="btn" href="success.php?order=<?= h($order['token']) ?>">Check again</a></p>
    <p class="muted small">If you were charged and this page does not update, email <?= h(cfg('vendor_email')) ?> with reference CLB-ORDER-<?= (int) $order['id'] ?>.</p>
  </div>
<?php endif; ?>
<?php page_end();
