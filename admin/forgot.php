<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$sent = false;
$error = null;
if (is_post()) {
    Csrf::verify();
    if (!Captcha::verify()) {
        $error = 'Human verification failed.';
    } else {
        PasswordReset::request('admin', input('email'));
        $sent = true;
    }
}
View::header('Administrator password reset');
?>
<section class="container section"><div class="card auth-card">
  <h1 class="h2">Administrator password reset</h1>
  <?php if ($sent): ?><div class="alert alert-success">If the email belongs to an active administrator, a reset link has been sent.</div>
  <?php else: ?>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post"><?= Csrf::field() ?>
      <div class="form-group"><label class="form-label" for="email">Email</label><input class="form-control" type="email" id="email" name="email" required></div>
      <div class="form-group"><?= Captcha::widget() ?></div>
      <button class="btn btn-primary btn-block" type="submit">Send reset link</button></form>
  <?php endif; ?>
  <p class="text-center mt-2"><a href="<?= e(url('admin/login.php')) ?>">Back to sign in</a></p>
</div></section>
<?php View::footer();
