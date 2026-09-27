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
    if (in_array(input('action'), ['save_email', 'save_email_test'], true)) {
        $driver = in_array(input('mail_driver'), ['smtp', 'mail', 'log'], true) ? input('mail_driver') : 'smtp';
        $enc = in_array(input('mail_encryption'), ['ssl', 'tls', ''], true) ? input('mail_encryption') : 'ssl';
        $port = input_int('mail_port', 465);
        $host = mb_substr(trim(input('mail_host')), 0, 190);
        $from = mb_strtolower(trim(input('mail_from_email')));
        $user = mb_substr(trim(input('mail_username')), 0, 190);
        $errors = [];
        if ($driver === 'smtp' && ($host === '' || !preg_match('/^[a-z0-9.-]+$/i', $host))) {
            $errors[] = 'Enter a valid SMTP host, e.g. mail.yourdomain.com.';
        }
        if ($driver === 'smtp' && ($port < 1 || $port > 65535)) {
            $errors[] = 'Enter a valid SMTP port (465 for SSL, 587 for STARTTLS).';
        }
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid "From" email address (normally the same as the SMTP username).';
        }
        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect('admin/settings.php#email');
        }
        $old = ['driver' => Mailer::cfg('driver'), 'host' => Mailer::cfg('host'), 'port' => Mailer::cfg('port'), 'encryption' => Mailer::cfg('encryption'),
            'username' => Mailer::cfg('username'), 'from_email' => Mailer::cfg('from_email'), 'from_name' => Mailer::cfg('from_name'), 'verify_ssl' => Mailer::cfg('verify_ssl')];
        $new = ['driver' => $driver, 'host' => $host, 'port' => (string) $port, 'encryption' => $enc, 'username' => $user, 'from_email' => $from,
            'from_name' => mb_substr(trim(input('mail_from_name')), 0, 120) ?: 'Cityland Property Bidding', 'verify_ssl' => empty($_POST['mail_verify_ssl']) ? '0' : '1'];
        foreach ($new as $k => $v) {
            Settings::set('mail_' . $k, $v);
        }
        $pw = (string) ($_POST['mail_password'] ?? '');
        $pwChanged = false;
        if ($pw !== '') {
            Settings::set('mail_password_enc', Security::encryptSecret($pw));
            $pwChanged = true;
        } elseif (!empty($_POST['mail_password_clear'])) {
            Settings::set('mail_password_enc', '');
            $pwChanged = true;
        } elseif (Settings::get('mail_password_enc', '') === '' && (string) config('mail.password', '') !== '') {
            Settings::set('mail_password_enc', Security::encryptSecret((string) config('mail.password'))); // carry over the installer password
        }
        Audit::log('email_settings_changed', 'settings', null, $old, $new + ['password' => $pwChanged ? '(changed)' : '(unchanged)']);
        if (input('action') === 'save_email_test') {
            try {
                Mailer::send($admin['email'], $admin['name'], 'Cityland Bidding — email test', Notifier::layout('Email test', 'Hello ' . $admin['name'] . ',', ['This is a test email from the Cityland Online Property Bidding System. Your email settings work.'], null, ''));
                flash('success', 'Email settings saved. A test email was sent to ' . $admin['email'] . ' — check your inbox (and spam folder).');
            } catch (Throwable $e) {
                flash('error', 'Email settings saved, but the test email FAILED: ' . $e->getMessage());
            }
        } else {
            flash('success', 'Email settings saved.');
        }
        // Re-deliver anything waiting in the queue with the new settings.
        DB::run("UPDATE email_queue SET status = 'queued', attempts = 0 WHERE status = 'failed'");
        redirect('admin/settings.php#email');
    }
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
<form method="post" class="card" id="email" autocomplete="off"><?= Csrf::field() ?>
  <div class="card-header"><h2>Email settings</h2><span class="muted small">Used for verification codes, bid confirmations and all notifications</span></div>
  <div class="form-group"><span class="form-label">Quick setup</span>
    <div class="btn-row">
      <button type="button" class="btn btn-outline btn-sm" data-mail-preset='{"driver":"smtp","host":"mail.<?= e(preg_replace('/^(www|bid|bidding)\./', '', (string) parse_url(base_url(), PHP_URL_HOST))) ?>","port":465,"encryption":"ssl"}'>cPanel email (SSL 465)</button>
      <button type="button" class="btn btn-outline btn-sm" data-mail-preset='{"driver":"smtp","host":"mail.<?= e(preg_replace('/^(www|bid|bidding)\./', '', (string) parse_url(base_url(), PHP_URL_HOST))) ?>","port":587,"encryption":"tls"}'>cPanel email (STARTTLS 587)</button>
      <button type="button" class="btn btn-outline btn-sm" data-mail-preset='{"driver":"smtp","host":"smtp.gmail.com","port":465,"encryption":"ssl"}'>Google Workspace / Gmail</button>
      <button type="button" class="btn btn-outline btn-sm" data-mail-preset='{"driver":"smtp","host":"smtp.office365.com","port":587,"encryption":"tls"}'>Microsoft 365</button>
    </div>
    <span class="form-help">For cPanel, first create the mailbox in cPanel → Email Accounts (e.g. bidding@yourdomain). Its "Outgoing server" is shown under Connect Devices.</span></div>
  <div class="form-row-3">
    <div class="form-group"><label class="form-label" for="mail_driver">Sending method</label>
      <select class="form-control" id="mail_driver" name="mail_driver">
        <?php foreach (['smtp' => 'SMTP (recommended)', 'mail' => 'PHP mail() — server default', 'log' => 'Log only — do not send (testing)'] as $k => $l): ?><option value="<?= $k ?>" <?= Mailer::cfg('driver') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label class="form-label" for="mail_host">SMTP host</label><input class="form-control" id="mail_host" name="mail_host" value="<?= e(Mailer::cfg('host')) ?>" placeholder="mail.yourdomain.com"></div>
    <div class="form-row">
      <div class="form-group"><label class="form-label" for="mail_port">Port</label><input class="form-control" type="number" id="mail_port" name="mail_port" value="<?= (int) Mailer::cfg('port') ?>"></div>
      <div class="form-group"><label class="form-label" for="mail_encryption">Encryption</label>
        <select class="form-control" id="mail_encryption" name="mail_encryption"><?php foreach (['ssl' => 'SSL', 'tls' => 'STARTTLS', '' => 'None'] as $k => $l): ?><option value="<?= $k ?>" <?= (string) Mailer::cfg('encryption') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    </div>
  </div>
  <div class="form-row">
    <div class="form-group"><label class="form-label" for="mail_username">SMTP username (full email address)</label><input class="form-control" id="mail_username" name="mail_username" value="<?= e(Mailer::cfg('username')) ?>" autocomplete="off" placeholder="bidding@yourdomain.com"></div>
    <div class="form-group"><label class="form-label" for="mail_password">SMTP password</label><input class="form-control" type="password" id="mail_password" name="mail_password" autocomplete="new-password" placeholder="<?= (string) Mailer::cfg('password') !== '' ? '•••••••• (saved — leave blank to keep)' : 'Enter the mailbox password' ?>">
      <?php if ((string) Mailer::cfg('password') !== ''): ?><label class="check mt-1 small"><input type="checkbox" name="mail_password_clear" value="1"> Remove saved password</label><?php endif; ?>
      <span class="form-help">Stored encrypted. Never shown again.</span></div>
  </div>
  <div class="form-row">
    <div class="form-group"><label class="form-label" for="mail_from_email">"From" email address</label><input class="form-control" type="email" id="mail_from_email" name="mail_from_email" value="<?= e(Mailer::cfg('from_email') ?: Mailer::cfg('username')) ?>" required placeholder="bidding@yourdomain.com"><span class="form-help">Use the same address as the SMTP username to avoid rejection.</span></div>
    <div class="form-group"><label class="form-label" for="mail_from_name">"From" name</label><input class="form-control" id="mail_from_name" name="mail_from_name" value="<?= e(Mailer::cfg('from_name')) ?>"></div>
  </div>
  <label class="check"><input type="checkbox" name="mail_verify_ssl" value="1" <?= Mailer::cfg('verify_ssl') ? 'checked' : '' ?>><span>Verify the mail server's SSL certificate <small class="muted">(untick only if the test fails with a certificate error — common when the host name differs from the server's certificate)</small></span></label>
  <div class="btn-row mt-2"><button class="btn btn-gold" name="action" value="save_email_test" type="submit">Save &amp; send test email</button><button class="btn btn-outline" name="action" value="save_email" type="submit">Save only</button></div>
</form>
<div class="grid grid-2">
  <div class="card"><h2>Server configuration</h2>
    <table class="kv">
      <tr><th>Site URL</th><td><?= e(base_url()) ?></td></tr>
      <tr><th>HTTPS</th><td><?= is_https() ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-red">Not active</span>' ?> · force: <?= yes_no(config('app.force_https')) ?></td></tr>
      <tr><th>Timezone</th><td><?= e(date_default_timezone_get()) ?> · DB time <?= e((string) DB::val('SELECT NOW()')) ?></td></tr>
      <tr><th>Email</th><td><?= e(Mailer::cfg('driver')) ?><?= Mailer::cfg('driver') === 'smtp' ? ' · ' . e(Mailer::cfg('host')) . ':' . (int) Mailer::cfg('port') . ' (' . e(Mailer::cfg('encryption') ?: 'none') . ')' : '' ?> · from <?= e(Mailer::fromEmail()) ?> <small class="muted">(<?= Settings::get('mail_driver', '') !== '' ? 'set in Admin → Settings' : 'from app/config.php' ?>)</small>
        <?php if (Mailer::cfg('driver') === 'log' && !is_testing()): ?><br><span class="badge badge-red">Emails are NOT being sent</span> <small>(log only)</small><?php endif; ?></td></tr>
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
