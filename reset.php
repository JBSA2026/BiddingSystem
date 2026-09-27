<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$token = query('token') ?: input('token');
$reset = PasswordReset::find('bidder', $token);
$error = null;
if ($reset && is_post()) {
    Csrf::verify();
    $pw = (string) ($_POST['password'] ?? '');
    if ($err = Security::passwordError($pw)) {
        $error = $err;
    } elseif ($pw !== (string) ($_POST['password_confirm'] ?? '')) {
        $error = 'Passwords do not match.';
    } else {
        PasswordReset::complete($reset, $pw);
        Auth::logoutBidder();
        flash('success', 'Your password has been changed. Please sign in with your new password.');
        redirect('login.php');
    }
}
View::header('Choose a new password');
?>
<section class="container section">
  <div class="card auth-card">
    <h1 class="h2">Choose a new password</h1>
    <?php if (!$reset): ?>
      <div class="alert alert-error">This reset link is invalid, already used, or expired.</div>
      <a class="btn btn-primary" href="<?= e(url('forgot.php')) ?>">Request a new link</a>
    <?php else: ?>
      <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="form-group"><label class="form-label" for="password">New password</label><input class="form-control" type="password" id="password" name="password" required autocomplete="new-password"><span class="form-help">At least <?= (int) config('security.password_min_length', 10) ?> characters with upper- and lower-case letters and a number.</span></div>
        <div class="form-group"><label class="form-label" for="password_confirm">Confirm new password</label><input class="form-control" type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password"></div>
        <button class="btn btn-primary btn-block" type="submit">Save new password</button>
      </form>
    <?php endif; ?>
  </div>
</section>
<?php View::footer();
