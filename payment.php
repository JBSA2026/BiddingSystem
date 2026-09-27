<?php
/**
 * Bid security / reservation deposit — MANUAL mode (no automatic payment processing).
 * The bidder pays through the channels published by Cityland and submits the payment
 * reference and proof; an authorized administrator reconciles it. Gateway integrations
 * (GCash, Maya, QR Ph, online banking) can later populate the same `payments` records.
 */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$user = Auth::requireBidder();
$p = Bidding::property(query_int('property') ?: input_int('property_id'));
if (!$p || !Eligibility::depositRequired($p)) {
    abort(404, 'Bid security is not required for this property.');
}
$pid = (int) $p['id'];
$methods = ['bank_transfer' => 'Bank transfer / deposit', 'online_banking' => 'Online banking', 'gcash' => 'GCash', 'maya' => 'Maya', 'qrph' => 'QR Ph', 'other' => 'Other'];

if (is_post()) {
    Csrf::verify();
    $method = input('method');
    $refNo = mb_substr(input('reference_no'), 0, 100);
    if (!isset($methods[$method]) || $refNo === '') {
        flash('error', 'Please select the payment method and enter the payment reference number.');
        redirect('payment.php?property=' . $pid);
    }
    if (DB::val("SELECT 1 FROM payments WHERE user_id = ? AND property_id = ? AND status IN ('pending','verified')", [$user['id'], $pid])) {
        flash('info', 'You already have a payment submission pending or verified for this property.');
        redirect('payment.php?property=' . $pid);
    }
    $proof = null;
    if ($files = Upload::files('proof')) {
        try {
            $proof = Upload::store($files[0], 'payments', Upload::BIDDER_DOC_TYPES);
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('payment.php?property=' . $pid);
        }
    }
    $id = DB::insert('payments', ['user_id' => $user['id'], 'property_id' => $pid, 'purpose' => 'bid_security', 'amount' => $p['deposit_amount'],
        'method' => $method, 'reference_no' => $refNo, 'proof_path' => $proof['path'] ?? null, 'proof_name' => $proof['original'] ?? null,
        'gateway' => 'manual', 'status' => 'pending', 'created_at' => now()]);
    Audit::log('payment_submitted', 'payment', $id, null, ['property' => $p['ref_no'], 'amount' => $p['deposit_amount'], 'method' => $method, 'reference_no' => $refNo]);
    Notifier::admins('payment_submitted', 'Bid security submitted: ' . $p['ref_no'], "Bidder {$user['bidder_no']} submitted a bid security payment reference ({$refNo}) for {$p['name']}. Please reconcile.", 'admin/payments.php');
    flash('success', 'Payment details submitted. Cityland will verify your payment and notify you.');
    redirect('payment.php?property=' . $pid);
}
$mine = DB::all('SELECT * FROM payments WHERE user_id = ? AND property_id = ? ORDER BY id DESC', [$user['id'], $pid]);
View::header('Bid Security Deposit');
?>
<section class="container narrow section">
  <h1>Bid security deposit</h1>
  <div class="card">
    <table class="kv">
      <tr><th>Property</th><td><?= e($p['name']) ?> (<?= e($p['ref_no']) ?>)</td></tr>
      <tr><th>Amount required</th><td><strong><?= e(money($p['deposit_amount'])) ?></strong> — <?= (int) $p['deposit_refundable'] ? 'refundable per bidding terms' : 'non-refundable' ?></td></tr>
    </table>
    <div class="prose mt-2"><?= nl2p(setting('payment_instructions', 'Please pay using the official Cityland payment channels provided by our bidding team. Never send payment to personal accounts. After paying, submit your payment reference number and proof below.')) ?></div>
  </div>
  <?php if ($mine): ?>
    <div class="card"><h2>My submissions</h2>
      <div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($mine as $m): ?><tr><td><?= e(fmt_dt($m['created_at'])) ?></td><td><?= e($methods[$m['method']] ?? $m['method']) ?></td><td><?= e($m['reference_no']) ?></td><td class="num"><?= e(money($m['amount'])) ?></td><td><?= status_badge($m['status']) ?><?= $m['remarks'] ? '<br><small>' . e($m['remarks']) . '</small>' : '' ?></td></tr><?php endforeach; ?>
      </tbody></table></div></div>
  <?php endif; ?>
  <?php if (!DB::val("SELECT 1 FROM payments WHERE user_id = ? AND property_id = ? AND status IN ('pending','verified')", [$user['id'], $pid])): ?>
  <form method="post" enctype="multipart/form-data" class="card"><?= Csrf::field() ?><input type="hidden" name="property_id" value="<?= $pid ?>">
    <h2>Submit payment details</h2>
    <div class="form-row">
      <div class="form-group"><label class="form-label" for="method">Payment method</label><select class="form-control" id="method" name="method" required><option value="">Select…</option><?php foreach ($methods as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label class="form-label" for="reference_no">Payment reference / transaction no.</label><input class="form-control" id="reference_no" name="reference_no" required maxlength="100"></div>
    </div>
    <div class="form-group"><label class="form-label" for="proof">Proof of payment (PDF/JPG/PNG)</label><input class="form-control" type="file" id="proof" name="proof" accept=".pdf,.jpg,.jpeg,.png"></div>
    <button class="btn btn-primary" type="submit">Submit for verification</button>
  </form>
  <?php endif; ?>
  <a href="<?= e(url('property.php?ref=' . rawurlencode($p['ref_no']))) ?>">&laquo; Back to property</a>
</section>
<?php View::footer();
