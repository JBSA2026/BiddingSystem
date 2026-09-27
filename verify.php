<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

// One-click email link (works even if not signed in on this device)
$token = query('token');
if ($token !== '') {
    $uid = Verification::checkToken($token);
    if ($uid) {
        flash('success', 'Your email address has been verified. Thank you!');
    } else {
        flash('error', 'This verification link is invalid or has expired. Please sign in and request a new code.');
    }
    redirect(Auth::bidder() ? 'verify.php' : 'login.php');
}

$user = Auth::requireBidder();
$needMobile = Eligibility::mobileOtpRequired();

if (is_post()) {
    Csrf::verify();
    $action = input('action');
    if ($action === 'email_code') {
        if (Throttle::tooMany('otp_check', 'u:' . $user['id'], 10, 30)) {
            flash('error', 'Too many attempts. Please wait 30 minutes or request a new code.');
        } elseif (Verification::checkCode($user, 'email', input('code'))) {
            flash('success', 'Email address verified.');
        } else {
            Throttle::hit('otp_check', 'u:' . $user['id']);
            flash('error', 'Incorrect or expired code. Please check your email or request a new code.');
        }
    } elseif ($action === 'mobile_code' && $needMobile) {
        if (Throttle::tooMany('otp_check', 'u:' . $user['id'], 10, 30)) {
            flash('error', 'Too many attempts. Please wait 30 minutes or request a new code.');
        } elseif (Verification::checkCode($user, 'mobile', input('code'))) {
            flash('success', 'Mobile number verified.');
        } else {
            Throttle::hit('otp_check', 'u:' . $user['id']);
            flash('error', 'Incorrect or expired SMS code.');
        }
    } elseif ($action === 'resend_email') {
        if (Verification::sendEmail($user)) {
            flash('success', 'A new verification code was sent to ' . mask_email($user['email']) . '.');
        } else {
            flash('error', 'Too many code requests. Please wait an hour and try again.');
        }
    } elseif ($action === 'resend_sms' && $needMobile) {
        if (Verification::sendSms($user)) {
            flash('success', 'A verification code was sent by SMS to ' . mask_mobile($user['mobile']) . '.');
        } else {
            flash('error', 'The SMS could not be sent right now (or too many requests). Please try again later.');
        }
    }
    redirect('verify.php');
}

$user = DB::one('SELECT * FROM users WHERE id = ?', [$user['id']]);
$allDone = $user['email_verified_at'] && (!$needMobile || $user['mobile_verified_at']);

View::header('Verify Your Account');
?>
<section class="container narrow section">
  <ol class="process light mb-2"><li class="done">View Property</li><li class="done">Register</li><li class="<?= $allDone ? 'done' : 'current' ?>">Verify Account</li><li>Review Terms</li><li>Submit Bid</li></ol>
  <div class="card">
    <h1>Verify your account</h1>
    <?php if ($allDone): ?>
      <div class="alert alert-success"><strong>Verification complete.</strong> Your contact details have been verified.</div>
      <p>Next: make sure your required documents are uploaded. <?= Settings::bool('require_admin_approval', true) ? 'Cityland will review and approve your registration before you can bid.' : '' ?></p>
      <div class="btn-row"><a class="btn btn-primary" href="<?= e(url('documents.php')) ?>">My documents</a><a class="btn btn-outline" href="<?= e(url('dashboard.php')) ?>">Go to dashboard</a></div>
    <?php endif; ?>

    <h2 class="mt-2">1. Email verification <?= $user['email_verified_at'] ? status_badge('verified') : status_badge('pending') ?></h2>
    <?php if (!$user['email_verified_at']): ?>
      <p>We sent a 6-digit code and a verification link to <strong><?= e(mask_email($user['email'])) ?></strong>. Check your spam folder if you don't see it.</p>
      <form method="post" class="mb-2"><?= Csrf::field() ?><input type="hidden" name="action" value="email_code">
        <div class="form-group"><label class="form-label" for="ecode">Email verification code</label>
          <input class="form-control otp-input" id="ecode" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" required></div>
        <button class="btn btn-primary" type="submit">Verify email</button></form>
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="resend_email"><button class="btn btn-link" type="submit">Resend email code</button></form>
    <?php else: ?>
      <p class="muted">Verified on <?= e(fmt_dt($user['email_verified_at'])) ?>.</p>
    <?php endif; ?>

    <?php if ($needMobile): ?>
      <h2 class="mt-3">2. Mobile verification <?= $user['mobile_verified_at'] ? status_badge('verified') : status_badge('pending') ?></h2>
      <?php if (!$user['mobile_verified_at']): ?>
        <p>Enter the 6-digit code sent by SMS to <strong><?= e(mask_mobile($user['mobile'])) ?></strong>.</p>
        <form method="post" class="mb-2"><?= Csrf::field() ?><input type="hidden" name="action" value="mobile_code">
          <div class="form-group"><label class="form-label" for="mcode">SMS code</label>
            <input class="form-control otp-input" id="mcode" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" required></div>
          <button class="btn btn-primary" type="submit">Verify mobile</button></form>
        <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="resend_sms"><button class="btn btn-link" type="submit">Send / resend SMS code</button></form>
      <?php else: ?>
        <p class="muted">Verified on <?= e(fmt_dt($user['mobile_verified_at'])) ?>.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
<?php View::footer();
