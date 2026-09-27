<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$user = Auth::requireBidder();
$uid = (int) $user['id'];
$errors = [];

if (is_post()) {
    Csrf::verify();
    $action = input('action');
    if (!password_verify((string) ($_POST['current_password'] ?? ''), $user['password_hash'])) {
        $errors[] = 'Your current password is incorrect.';
    } elseif ($action === 'contact') {
        $new = [
            'company_name' => mb_substr(input('company_name'), 0, 190) ?: null, 'address_line' => mb_substr(input('address_line'), 0, 255),
            'barangay' => mb_substr(input('barangay'), 0, 120) ?: null, 'city' => mb_substr(input('city'), 0, 120),
            'province' => mb_substr(input('province'), 0, 120), 'postal_code' => mb_substr(input('postal_code'), 0, 10) ?: null,
        ];
        $mobile = normalize_mobile(input('mobile'));
        if (!$new['address_line'] || !$new['city'] || !$new['province']) {
            $errors[] = 'Address, city and province are required.';
        }
        if (!$mobile) {
            $errors[] = 'Please enter a valid mobile number.';
        } elseif ($mobile !== $user['mobile'] && DB::val('SELECT 1 FROM users WHERE mobile = ? AND id <> ?', [$mobile, $uid])) {
            $errors[] = 'This mobile number is registered to another account.';
        }
        if (!$errors) {
            if ($mobile !== $user['mobile']) {
                $new['mobile'] = $mobile;
                $new['mobile_verified_at'] = null;
            }
            $old = array_intersect_key($user, $new);
            DB::update('users', $new + ['updated_at' => now()], 'id = ?', [$uid]);
            Audit::log('profile_updated', 'user', $uid, $old, $new);
            flash('success', 'Your profile has been updated.' . (isset($new['mobile']) && Eligibility::mobileOtpRequired() ? ' Please verify your new mobile number.' : ''));
            redirect(isset($new['mobile']) && Eligibility::mobileOtpRequired() ? 'verify.php' : 'profile.php');
        }
    } elseif ($action === 'password') {
        $pw = (string) ($_POST['password'] ?? '');
        if ($err = Security::passwordError($pw)) {
            $errors[] = $err;
        } elseif ($pw !== (string) ($_POST['password_confirm'] ?? '')) {
            $errors[] = 'New passwords do not match.';
        } else {
            DB::update('users', ['password_hash' => Security::hashPassword($pw), 'updated_at' => now()], 'id = ?', [$uid]);
            Audit::log('password_changed', 'user', $uid);
            Session::regenerate();
            $_SESSION['bidder_id'] = $uid;
            $_SESSION['bidder_name'] = $user['full_name'];
            flash('success', 'Your password has been changed.');
            redirect('profile.php');
        }
    }
}
$consents = DB::all('SELECT * FROM consents WHERE user_id = ? ORDER BY id DESC LIMIT 30', [$uid]);

View::header('My Profile', ['active' => 'dashboard']);
?>
<section class="container medium section">
  <h1>My profile</h1>
  <?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <div class="grid grid-2">
    <div class="card">
      <h2>Account details</h2>
      <table class="kv">
        <tr><th>Bidder number</th><td><?= e($user['bidder_no']) ?></td></tr>
        <tr><th>Full name</th><td><?= e($user['full_name']) ?></td></tr>
        <tr><th>Email</th><td><?= e($user['email']) ?></td></tr>
        <tr><th>Government ID</th><td><?= e($user['id_type']) ?> ending in <?= e($user['id_number_last4']) ?></td></tr>
        <tr><th>Registered</th><td><?= e(fmt_dt($user['created_at'])) ?></td></tr>
      </table>
      <p class="small muted mt-1">To change your legal name, email or ID details, please contact Cityland — changes require re-verification.</p>
    </div>
    <div class="card">
      <h2>Change password</h2>
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="password">
        <div class="form-group"><label class="form-label" for="cp1">Current password</label><input class="form-control" type="password" id="cp1" name="current_password" required autocomplete="current-password"></div>
        <div class="form-group"><label class="form-label" for="np">New password</label><input class="form-control" type="password" id="np" name="password" required autocomplete="new-password"></div>
        <div class="form-group"><label class="form-label" for="np2">Confirm new password</label><input class="form-control" type="password" id="np2" name="password_confirm" required autocomplete="new-password"></div>
        <button class="btn btn-primary" type="submit">Update password</button>
      </form>
    </div>
  </div>
  <div class="card">
    <h2>Contact details</h2>
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="contact">
      <div class="form-row">
        <div class="form-group"><label class="form-label" for="company_name">Company name</label><input class="form-control" id="company_name" name="company_name" value="<?= e($user['company_name']) ?>"></div>
        <div class="form-group"><label class="form-label" for="mobile">Mobile number</label><input class="form-control" id="mobile" name="mobile" value="<?= e($user['mobile']) ?>" required></div>
      </div>
      <div class="form-group"><label class="form-label" for="address_line">Street address</label><input class="form-control" id="address_line" name="address_line" value="<?= e($user['address_line']) ?>" required></div>
      <div class="form-row-3">
        <div class="form-group"><label class="form-label" for="barangay">Barangay</label><input class="form-control" id="barangay" name="barangay" value="<?= e($user['barangay']) ?>"></div>
        <div class="form-group"><label class="form-label" for="city">City</label><input class="form-control" id="city" name="city" value="<?= e($user['city']) ?>" required></div>
        <div class="form-group"><label class="form-label" for="province">Province</label><input class="form-control" id="province" name="province" value="<?= e($user['province']) ?>" required></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label" for="postal_code">Postal code</label><input class="form-control" id="postal_code" name="postal_code" value="<?= e($user['postal_code']) ?>"></div>
        <div class="form-group"><label class="form-label" for="cp2">Current password (to confirm)</label><input class="form-control" type="password" id="cp2" name="current_password" required autocomplete="current-password"></div>
      </div>
      <button class="btn btn-primary" type="submit">Save contact details</button>
    </form>
  </div>
  <div class="card">
    <h2>My consent records</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th>Date / time</th><th>Consent</th><th>Version</th><th>Context</th><th>Given</th></tr></thead><tbody>
      <?php foreach ($consents as $c): ?><tr><td class="nowrap"><?= e(fmt_dt($c['accepted_at'], 'M j, Y g:i:s A')) ?></td><td><?= e(status_label($c['consent_type'])) ?></td><td><?= e($c['version']) ?></td><td><small><?= e($c['context']) ?></small></td><td><?= yes_no($c['granted']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
</section>
<?php View::footer();
