<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$user = Auth::requireBidder();
$ref = query('ref');
$bid = DB::one('SELECT * FROM bids WHERE bid_ref = ? AND user_id = ?', [$ref, $user['id']]);
if (!$bid) {
    abort(404, 'Bid acknowledgment not found.');
}
$p = Bidding::property((int) $bid['property_id']);
$isNew = query('new') === '1';

$ackHtml = static function () use ($bid, $p, $user): string {
    ob_start(); ?>
  <div class="ack">
    <div class="ack-head">
      <div><strong style="font-size:1.2rem;color:#0b2e59"><?= e(setting('company_name', 'Cityland')) ?></strong><br><span class="muted">Online Property Bidding — Bid Acknowledgment</span></div>
      <div class="text-right"><div class="muted small">Bid reference</div><div class="big-ref"><?= e($bid['bid_ref']) ?></div></div>
    </div>
    <table class="kv">
      <tr><th>Property</th><td><strong><?= e($p['name']) ?></strong></td></tr>
      <tr><th>Property reference no.</th><td><?= e($p['ref_no']) ?></td></tr>
      <tr><th>Location</th><td><?= e($p['location']) ?></td></tr>
      <tr><th>Submission type</th><td><?= e(status_label($bid['bid_type'])) ?></td></tr>
      <tr><th>Bid amount</th><td><strong style="font-size:1.25rem"><?= e(money($bid['amount'])) ?></strong></td></tr>
      <tr><th>Submission timestamp (server)</th><td><?= e(fmt_dt_precise($bid['submitted_at'])) ?> <?= e(date('T')) ?></td></tr>
      <tr><th>Bidder</th><td><?= e($user['full_name']) ?><?= $user['company_name'] ? ' / ' . e($user['company_name']) : '' ?> (<?= e($user['bidder_no']) ?>)</td></tr>
      <tr><th>Terms accepted</th><td>General Terms v<?= e($bid['terms_version'] ?? '—') ?> · Property terms v<?= (int) $bid['property_terms_version'] ?> · Privacy Notice v<?= e($bid['privacy_version'] ?? '—') ?></td></tr>
      <tr><th>Location record</th><td><?= $bid['gps_status'] === 'granted' ? 'Shared with consent' : e($bid['gps_note'] ?: Bidding::GPS_DENIED_NOTE) ?></td></tr>
      <tr><th>Integrity hash</th><td class="hash"><?= e($bid['hash']) ?></td></tr>
    </table>
    <p class="small muted mt-2">This acknowledgment confirms receipt of your bid only. Ranking is for evaluation purposes; the award is subject to Cityland evaluation of eligibility, documents and compliance, and approval by authorized officers. Competing bids are confidential.</p>
  </div>
<?php return (string) ob_get_clean();
};

if (query('download') === '1') {
    $css = (string) file_get_contents(APP_ROOT . '/assets/css/app.css');
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="Bid-Acknowledgment-' . preg_replace('/[^A-Z0-9-]/', '', $bid['bid_ref']) . '.html"');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Bid Acknowledgment ' . e($bid['bid_ref']) . '</title><style>' . $css . '</style></head><body style="background:#fff"><div class="container narrow section">' . $ackHtml() . '</div></body></html>';
    exit;
}

View::header('Bid Acknowledgment');
?>
<section class="container narrow section">
  <?php if ($isNew): ?>
    <ol class="process light mb-2 no-print"><li class="done">Register</li><li class="done">Verify</li><li class="done">Review Terms</li><li class="done">Submit Bid</li><li class="current">Bid Confirmation</li><li>Monitor Status</li><li>Evaluation / Award</li></ol>
    <div class="alert alert-success no-print"><strong>Your bid has been received.</strong> A confirmation email has been sent to <?= e(mask_email($user['email'])) ?>.</div>
  <?php endif; ?>
  <?= $ackHtml() ?>
  <div class="btn-row mt-2 no-print">
    <button type="button" class="btn btn-primary" data-print>Print / Save as PDF</button>
    <a class="btn btn-outline" href="<?= e(url('acknowledgment.php?ref=' . rawurlencode($bid['bid_ref']) . '&download=1')) ?>">Download</a>
    <a class="btn btn-outline" href="<?= e(url('property.php?ref=' . rawurlencode($p['ref_no']))) ?>">Back to property</a>
    <a class="btn btn-link" href="<?= e(url('dashboard.php')) ?>">My dashboard</a>
  </div>
</section>
<?php View::footer();
