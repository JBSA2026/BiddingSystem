<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$token = query('token') ?: input('token');
$reset = PasswordReset::find('admin', $token);
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
        Auth::logoutAdmin();
        flash('success', 'Password changed. Please sign in.');
        redirect('admin/login.php');
    }
}
View::header('Choose a new password');
?>
<section class="container section"><div class="card auth-card">
  <h1 class="h2">Choose a new password</h1>
  <?php if (!$reset): ?><div class="alert alert-error">This reset link is invalid or expired.</div>
  <?php else: ?>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
      <div class="form-group"><label class="form-label" for="password">New password</label><input class="form-control" type="password" id="password" name="password" required autocomplete="new-password"></div>
      <div class="form-group"><label class="form-label" for="password_confirm">Confirm</label><input class="form-control" type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password"></div>
      <button class="btn btn-primary btn-block" type="submit">Save</button></form>
  <?php endif; ?>
</div></section>
<?php View::footer();
