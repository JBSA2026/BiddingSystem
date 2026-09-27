<?php
/** License information and renewal. Always reachable, even when the admin portal is locked. */
declare(strict_types=1);
require __DIR__ . '/_init.php';

$admin = Auth::requireAdmin();
if (is_post()) {
    Csrf::verify();
    if (!Rbac::can($admin['role'], 'settings.manage')) {
        abort(403, 'Only a Super Admin can install a license key.');
    }
    $err = License::install((string) ($_POST['license_key'] ?? ''), $admin);
    if ($err) {
        flash('error', $err);
    } else {
        flash('success', 'License installed successfully. Thank you!');
    }
    redirect('admin/license.php');
}
$s = License::status();
$p = $s['payload'];
$stateBadge = [
    'valid' => '<span class="badge badge-green">Active</span>',
    'expiring' => '<span class="badge badge-amber">Expiring soon</span>',
    'grace' => '<span class="badge badge-red">Expired — grace period</span>',
    'expired' => '<span class="badge badge-red">Expired</span>',
    'invalid' => '<span class="badge badge-red">Invalid</span>',
    'missing' => '<span class="badge badge-grey">Not activated</span>',
][$s['state']];
$maxAdmins = $p['max_admins'] ?? null;
$maxProps = $p['max_properties'] ?? null;
$usedAdmins = License::activeAdmins();
$usedProps = License::activeProperties();
$meter = static function (int $used, $max): string {
    if (!$max) {
        return '<small class="muted">' . $used . ' in use · unlimited</small>';
    }
    $pct = min(100, (int) round($used / (int) $max * 100));
    return '<small>' . $used . ' of ' . (int) $max . ' used</small><div class="meter' . ($used >= (int) $max ? ' full' : '') . '"><span style="width:' . $pct . '%"></span></div>';
};
$history = DB::all("SELECT created_at, action, actor_name, new_value FROM audit_logs WHERE entity_type = 'license' ORDER BY id DESC LIMIT 15");

View::adminHeader('License');
?>
<?php if (!$s['enforced']): ?>
  <div class="alert alert-info"><strong>Test environment:</strong> licensing is shown here for testing but <strong>not enforced</strong> (no lock, no plan limits). On the live site the rules below apply.</div>
<?php endif; ?>
<?php if ($s['locked']): ?>
  <div class="alert alert-error"><strong>The admin portal is locked.</strong> <?= e($s['message']) ?> Public pages, existing bids and records are not affected. <?= can('settings.manage') ? 'Paste a valid license key below to unlock.' : 'Please ask your Super Admin to install a valid license.' ?></div>
<?php endif; ?>

<div class="grid grid-2">
  <div class="card">
    <div class="card-header"><h2>License status</h2><?= $stateBadge ?></div>
    <p><?= e($s['message']) ?></p>
    <table class="kv">
      <tr><th>Licensed to</th><td><strong><?= e($p['licensee'] ?? '—') ?></strong></td></tr>
      <tr><th>License ID</th><td><?= e($p['lid'] ?? '—') ?></td></tr>
      <tr><th>Plan</th><td><?= e($p['plan'] ?? '—') ?></td></tr>
      <tr><th>Licensed domain(s)</th><td><?= e($p ? implode(', ', (array) $p['domains']) : '—') ?></td></tr>
      <tr><th>This site's domain</th><td><code><?= e(License::siteHost()) ?></code></td></tr>
      <tr><th>Issued</th><td><?= e($p ? fmt_dt($p['issued'] ?? null, 'F j, Y') : '—') ?></td></tr>
      <tr><th>Expires</th><td><?= e($p ? fmt_dt($p['expires'], 'F j, Y') : '—') ?><?php if ($s['days_left'] !== null): ?> <small class="muted">(<?= $s['days_left'] >= 0 ? (int) $s['days_left'] . ' day(s) left' : abs((int) $s['days_left']) . ' day(s) ago' ?>)</small><?php endif; ?></td></tr>
      <tr><th>Grace period after expiry</th><td><?= (int) ($p['grace_days'] ?? License::DEFAULT_GRACE_DAYS) ?> day(s)</td></tr>
      <?php if (!empty($p['notes'])): ?><tr><th>Notes</th><td><?= e($p['notes']) ?></td></tr><?php endif; ?>
    </table>
  </div>
  <div class="card">
    <h2>Plan usage</h2>
    <table class="kv">
      <tr><th>Active administrators</th><td><?= $meter($usedAdmins, $maxAdmins) ?></td></tr>
      <tr><th>Active published properties</th><td><?= $meter($usedProps, $maxProps) ?></td></tr>
    </table>
    <p class="small muted mt-2">"Active published properties" are published, non-archived properties that are upcoming, open, closed or under evaluation. Awarded, cancelled and archived properties do not count.</p>
    <h3 class="mt-3">Renewal</h3>
    <p class="small">To renew or upgrade, send the vendor your License ID and this site's domain (<strong><?= e(License::siteHost()) ?></strong>). You will receive a new license key to paste below. Renewing never affects your data.</p>
  </div>
</div>

<?php if (can('settings.manage')): ?>
<form method="post" class="card"><?= Csrf::field() ?>
  <h2><?= $p ? 'Install a new or renewed license key' : 'Activate your license' ?></h2>
  <div class="form-group"><label class="form-label" for="license_key">License key</label>
    <textarea class="form-control" id="license_key" name="license_key" rows="5" required placeholder="CLB1-…" style="font-family:Consolas,Menlo,monospace;font-size:.8rem"></textarea>
    <span class="form-help">Paste the full key exactly as issued. It is verified immediately; an invalid key is never saved.</span></div>
  <button class="btn btn-gold" type="submit">Verify &amp; install license</button>
</form>
<?php endif; ?>

<?php if ($history): ?>
<div class="card"><h2>License history</h2>
  <div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>Event</th><th>By</th><th>Details</th></tr></thead><tbody>
  <?php foreach ($history as $h): $v = json_decode((string) $h['new_value'], true) ?: []; ?>
    <tr><td class="nowrap"><small><?= e(fmt_dt($h['created_at'])) ?></small></td><td><?= e(status_label(str_replace('license_', '', $h['action']))) ?></td><td><small><?= e($h['actor_name']) ?></small></td>
      <td><small><?= e(isset($v['lid']) ? $v['lid'] . ' · ' . ($v['plan'] ?? '') . ' · until ' . ($v['expires'] ?? '') : ($v['error'] ?? '')) ?></small></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>
<?php View::adminFooter();
