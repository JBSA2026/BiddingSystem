<?php
/** Administrator's own account: password change and optional TOTP two-factor authentication. */
declare(strict_types=1);
require __DIR__ . '/_init.php';

$admin = Auth::requireAdmin();
$errors = [];
if (is_post()) {
    Csrf::verify();
    $action = input('action');
    if (!Auth::confirmAdminPassword($admin, (string) ($_POST['current_password'] ?? ''))) {
        $errors[] = 'Current password is incorrect.';
    } elseif ($action === 'password') {
        $pw = (string) ($_POST['password'] ?? '');
        if ($err = Security::passwordError($pw)) {
            $errors[] = $err;
        } elseif ($pw !== (string) ($_POST['password_confirm'] ?? '')) {
            $errors[] = 'Passwords do not match.';
        } elseif (password_verify($pw, $admin['password_hash'])) {
            $errors[] = 'Choose a password different from the current one.';
        } else {
            DB::update('admins', ['password_hash' => Security::hashPassword($pw), 'must_change_password' => 0, 'updated_at' => now()], 'id = ?', [$admin['id']]);
            Audit::log('password_changed', 'admin', $admin['id']);
            session_regenerate_id(true);
            flash('success', 'Password changed.');
            redirect('admin/account.php');
        }
    } elseif ($action === 'enable_2fa') {
        $secret = $_SESSION['pending_totp'] ?? '';
        if ($secret === '' || !Totp::verify($secret, input('code'))) {
            $errors[] = 'The code is incorrect. Check that your phone time is correct and try again.';
        } else {
            DB::update('admins', ['totp_secret' => $secret, 'totp_enabled' => 1, 'updated_at' => now()], 'id = ?', [$admin['id']]);
            unset($_SESSION['pending_totp']);
            Audit::log('2fa_enabled', 'admin', $admin['id']);
            flash('success', 'Two-factor authentication is now enabled.');
            redirect('admin/account.php');
        }
    } elseif ($action === 'disable_2fa') {
        if (Settings::bool('admin_2fa_required')) {
            $errors[] = '2FA is required by policy and cannot be disabled.';
        } elseif (!Totp::verify((string) $admin['totp_secret'], input('code'))) {
            $errors[] = 'Invalid authentication code.';
        } else {
            DB::update('admins', ['totp_secret' => null, 'totp_enabled' => 0], 'id = ?', [$admin['id']]);
            Audit::log('2fa_disabled', 'admin', $admin['id']);
            flash('success', 'Two-factor authentication disabled.');
            redirect('admin/account.php');
        }
    }
}
$admin = DB::one('SELECT * FROM admins WHERE id = ?', [$admin['id']]);
if (!(int) $admin['totp_enabled'] && empty($_SESSION['pending_totp'])) {
    $_SESSION['pending_totp'] = Totp::generateSecret();
}
$uri = !(int) $admin['totp_enabled'] ? Totp::uri($_SESSION['pending_totp'], $admin['email'], setting('company_name', 'Cityland') . ' Bidding') : '';
View::adminHeader('My account');
?>
<?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="grid grid-2">
  <div class="card"><h2>Change password</h2>
    <?php if ((int) $admin['must_change_password']): ?><div class="alert alert-warning">You are using a temporary password. Please set a new one.</div><?php endif; ?>
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="password">
      <div class="form-group"><label class="form-label" for="c1">Current password</label><input class="form-control" type="password" id="c1" name="current_password" required autocomplete="current-password"></div>
      <div class="form-group"><label class="form-label" for="p1">New password</label><input class="form-control" type="password" id="p1" name="password" required autocomplete="new-password"><span class="form-help">Min <?= (int) config('security.password_min_length', 10) ?> chars, upper/lower case and a number.</span></div>
      <div class="form-group"><label class="form-label" for="p2">Confirm</label><input class="form-control" type="password" id="p2" name="password_confirm" required autocomplete="new-password"></div>
      <button class="btn btn-primary">Change password</button></form></div>
  <div class="card"><h2>Two-factor authentication</h2>
    <?php if ((int) $admin['totp_enabled']): ?>
      <div class="alert alert-success">2FA is <strong>enabled</strong> on your account.</div>
      <?php if (!Settings::bool('admin_2fa_required')): ?>
        <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="disable_2fa">
          <div class="form-group"><label class="form-label" for="c2">Current password</label><input class="form-control" type="password" id="c2" name="current_password" required></div>
          <div class="form-group"><label class="form-label" for="d2">Authenticator code</label><input class="form-control" id="d2" name="code" inputmode="numeric" maxlength="6" required></div>
          <button class="btn btn-outline">Disable 2FA</button></form>
      <?php endif; ?>
    <?php else: ?>
      <?php if (Settings::bool('admin_2fa_required')): ?><div class="alert alert-warning">Your organization requires 2FA. Set it up now to continue.</div><?php endif; ?>
      <ol class="small"><li>Install Google Authenticator, Microsoft Authenticator or Authy.</li><li>Scan this QR code (or enter the key manually).</li><li>Enter the 6-digit code to confirm.</li></ol>
      <div class="qr-box" data-qr="<?= e($uri) ?>" data-size="200"></div>
      <p class="small">Manual key: <code><?= e(chunk_split($_SESSION['pending_totp'], 4, ' ')) ?></code></p>
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="enable_2fa">
        <div class="form-group"><label class="form-label" for="c3">Current password</label><input class="form-control" type="password" id="c3" name="current_password" required></div>
        <div class="form-group"><label class="form-label" for="e2">6-digit code</label><input class="form-control otp-input" id="e2" name="code" inputmode="numeric" maxlength="6" required></div>
        <button class="btn btn-primary">Enable 2FA</button></form>
    <?php endif; ?>
  </div>
</div>
<script src="<?= e(asset('js/vendor/qrcode.js')) ?>"></script>
<?php View::adminFooter();
