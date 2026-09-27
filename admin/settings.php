<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$admin = Auth::requireAdmin('settings.manage');
$text = [
    'site_name' => 'Website name', 'company_name' => 'Company name', 'contact_email' => 'Public contact email', 'contact_phone' => 'Public contact phone',
    'contact_address' => 'Office address', 'dpo_name' => 'Data Protection Officer name', 'dpo_email' => 'DPO email', 'dpo_phone' => 'DPO phone',
    'admin_notification_emails' => 'Admin notification emails (comma-separated; blank = all relevant admins)',
    'closing_reminder_hours' => 'Send closing reminder N hours before close', 'data_retention_years' => 'Data retention (years)',
    'session_idle_minutes' => 'Session idle timeout (minutes)', 'dup_ip_threshold' => 'Flag when this many accounts register from one IP in 30 days (0 = off)',
];
$bools = [
    'require_admin_approval' => 'Bidder accounts must be approved by Cityland before bidding',
    'require_docs_approved' => 'Required documents must be APPROVED (not just uploaded) before bidding',
    'require_mobile_otp' => 'Require mobile SMS OTP verification (only effective when an SMS provider is configured)',
    'dual_auth_award' => 'Dual authorization for awards (recommender and approver must be different officers)',
    'dual_auth_schedule' => 'Dual authorization for schedule changes after bidding starts',
    'admin_2fa_required' => 'Require two-factor authentication (TOTP) for ALL administrators',
    'bid_security_enabled' => 'Enable bid security / reservation deposit feature',
];

if (is_post()) {
    Csrf::verify();
    if (input('action') === 'test_email') {
        try {
            Mailer::send($admin['email'], $admin['name'], 'Cityland Bidding — SMTP test', Notifier::layout('SMTP test', 'Hello ' . $admin['name'] . ',', ['This is a test email from the Cityland Online Property Bidding System. Your email configuration works.'], null, ''));
            flash('success', 'Test email sent to ' . $admin['email'] . '.');
        } catch (Throwable $e) {
            flash('error', 'Email failed: ' . $e->getMessage());
        }
        redirect('admin/settings.php');
    }
    $old = Settings::all();
    $changes = [];
    foreach (array_keys($text) as $k) {
        $v = mb_substr(input($k), 0, 1000);
        if ((string) ($old[$k] ?? '') !== $v) {
            Settings::set($k, $v);
            $changes[$k] = [$old[$k] ?? null, $v];
        }
    }
    foreach (array_keys($bools) as $k) {
        $v = empty($_POST[$k]) ? '0' : '1';
        if ((string) ($old[$k] ?? '') !== $v) {
            Settings::set($k, $v);
            $changes[$k] = [$old[$k] ?? null, $v];
        }
    }
    $instr = mb_substr((string) ($_POST['payment_instructions'] ?? ''), 0, 5000);
    if ((string) ($old['payment_instructions'] ?? '') !== $instr) {
        Settings::set('payment_instructions', $instr);
        $changes['payment_instructions'] = ['(changed)', '(changed)'];
    }
    if ($changes) {
        Audit::log('settings_changed', 'settings', null, array_map(static fn($c) => $c[0], $changes), array_map(static fn($c) => $c[1], $changes));
    }
    flash('success', 'Settings saved.');
    redirect('admin/settings.php');
}
$s = Settings::all();
$triggers = (int) DB::val("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg\\_%'");
View::adminHeader('Settings');
?>
<form method="post" class="card"><?= Csrf::field() ?>
  <h2>General &amp; contact</h2>
  <div class="form-row"><?php foreach ($text as $k => $label): ?><div class="form-group"><label class="form-label" for="<?= $k ?>"><?= e($label) ?></label><input class="form-control" id="<?= $k ?>" name="<?= $k ?>" value="<?= e($s[$k] ?? '') ?>"></div><?php endforeach; ?></div>
  <h2 class="mt-2">Policies</h2>
  <?php foreach ($bools as $k => $label): ?><label class="check"><input type="checkbox" name="<?= $k ?>" value="1" <?= ($s[$k] ?? '0') === '1' ? 'checked' : '' ?>><span><?= e($label) ?></span></label><?php endforeach; ?>
  <h2 class="mt-2">Bid security payment instructions</h2>
  <div class="form-group"><textarea class="form-control" name="payment_instructions" rows="4" placeholder="Official bank account(s), GCash/Maya merchant details, QR Ph instructions…"><?= e($s['payment_instructions'] ?? '') ?></textarea>
    <span class="form-help">Automatic payment processing is <strong>not active</strong> (gateway: <?= e($s['payment_gateway'] ?? 'none') ?>). Bidders submit references manually and admins reconcile under Bid Security.</span></div>
  <button class="btn btn-primary" type="submit">Save settings</button>
</form>
<div class="grid grid-2">
  <div class="card"><h2>Server configuration <small class="muted">(app/config.php)</small></h2>
    <table class="kv">
      <tr><th>Site URL</th><td><?= e(base_url()) ?></td></tr>
      <tr><th>HTTPS</th><td><?= is_https() ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-red">Not active</span>' ?> · force: <?= yes_no(config('app.force_https')) ?></td></tr>
      <tr><th>Timezone</th><td><?= e(date_default_timezone_get()) ?> · DB time <?= e((string) DB::val('SELECT NOW()')) ?></td></tr>
      <tr><th>Email</th><td><?= e(config('mail.driver')) ?> · <?= e(config('mail.host')) ?>:<?= (int) config('mail.port') ?> (<?= e(config('mail.encryption')) ?>) · from <?= e(config('mail.from_email')) ?></td></tr>
      <tr><th>SMS provider</th><td><?= e(config('sms.provider')) ?> <?= Sms::enabled() ? '<span class="badge badge-green">Enabled</span>' : '<span class="badge badge-grey">Disabled</span>' ?></td></tr>
      <tr><th>CAPTCHA</th><td><?= e(Captcha::provider()) ?></td></tr>
      <tr><th>Storage path</th><td><small><?= e(storage_path()) ?></small> <?= is_writable(storage_path()) ? '<span class="badge badge-green">Writable</span>' : '<span class="badge badge-red">Not writable</span>' ?></td></tr>
      <tr><th>Immutability triggers</th><td><?= $triggers >= 8 ? '<span class="badge badge-green">Installed</span>' : '<span class="badge badge-amber">Not installed</span> <small>import install/triggers.sql</small>' ?></td></tr>
      <tr><th>Email queue</th><td><?= (int) DB::val("SELECT COUNT(*) FROM email_queue WHERE status='queued'") ?> queued · <?= (int) DB::val("SELECT COUNT(*) FROM email_queue WHERE status='failed'") ?> failed</td></tr>
      <tr><th>Last cron run</th><td><?= e($s['last_cron_run'] ?? 'never') ?></td></tr>
      <tr><th>PHP</th><td><?= e(PHP_VERSION) ?></td></tr>
    </table>
    <form method="post" class="mt-2"><?= Csrf::field() ?><input type="hidden" name="action" value="test_email"><button class="btn btn-outline btn-sm">Send test email to me</button></form>
  </div>
  <div class="card"><h2>Recent email log</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th>Time</th><th>To</th><th>Subject</th><th>Status</th></tr></thead><tbody>
    <?php foreach (DB::all('SELECT * FROM email_queue ORDER BY id DESC LIMIT 15') as $m): ?><tr><td class="nowrap"><small><?= e(fmt_dt($m['created_at'])) ?></small></td><td><small><?= e($m['to_email']) ?></small></td><td><small><?= e($m['subject']) ?></small></td><td><?= status_badge($m['status'] === 'sent' ? 'verified' : ($m['status'] === 'failed' ? 'rejected' : 'pending')) ?><?= $m['last_error'] ? '<br><small>' . e(mb_substr($m['last_error'], 0, 80)) . '</small>' : '' ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
</div>
<?php View::adminFooter();
