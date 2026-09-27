<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$sent = false;
$error = null;
if (is_post()) {
    Csrf::verify();
    if (!Captcha::verify()) {
        $error = 'Human verification failed. Please try again.';
    } else {
        PasswordReset::request('bidder', input('email'));
        $sent = true;
    }
}
View::header('Forgot password');
?>
<section class="container section">
  <div class="card auth-card">
    <h1 class="h2">Reset your password</h1>
    <?php if ($sent): ?>
      <div class="alert alert-success">If an account exists for that email address, we have sent a password reset link. Please check your inbox (and spam folder). The link expires in 60 minutes.</div>
      <a class="btn btn-primary btn-block" href="<?= e(url('login.php')) ?>">Back to sign in</a>
    <?php else: ?>
      <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
      <p class="muted">Enter the email address you registered with and we'll send you a secure reset link.</p>
      <form method="post"><?= Csrf::field() ?>
        <div class="form-group"><label class="form-label" for="email">Email address</label><input class="form-control" type="email" id="email" name="email" required autocomplete="email"></div>
        <div class="form-group"><?= Captcha::widget() ?></div>
        <button class="btn btn-primary btn-block" type="submit">Send reset link</button>
      </form>
    <?php endif; ?>
  </div>
</section>
<?php View::footer();
