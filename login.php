<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

if (Auth::bidder()) {
    redirect('dashboard.php');
}
$error = null;
if (is_post()) {
    Csrf::verify();
    $email = input('email');
    $needCaptcha = Throttle::count('login_fail_bidder', 'ip:' . client_ip(), 15) >= 3;
    if ($needCaptcha && !Captcha::verify()) {
        $error = 'Please complete the human verification.';
    } else {
        $res = Auth::attemptBidder($email, (string) ($_POST['password'] ?? ''));
        if ($res['ok']) {
            Auth::loginBidder($res['user']);
            $intended = $_SESSION['_intended'] ?? null;
            unset($_SESSION['_intended']);
            if (!$res['user']['email_verified_at']) {
                redirect('verify.php');
            }
            // Only allow local redirects
            if (is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//')) {
                header('Location: ' . $intended, true, 303);
                exit;
            }
            redirect('dashboard.php');
        }
        $error = $res['error'];
    }
    keep_old(['email' => $email]);
}
$showCaptcha = Throttle::count('login_fail_bidder', 'ip:' . client_ip(), 15) >= 3;

View::header('Sign in', ['active' => 'login']);
?>
<section class="container section">
  <div class="card auth-card">
    <h1 class="h2">Bidder sign in</h1>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" novalidate>
      <?= Csrf::field() ?>
      <div class="form-group"><label class="form-label" for="email">Email address</label><input class="form-control" type="email" id="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="username" autofocus></div>
      <div class="form-group"><label class="form-label" for="password">Password</label><input class="form-control" type="password" id="password" name="password" required autocomplete="current-password"></div>
      <?php if ($showCaptcha): ?><div class="form-group"><?= Captcha::widget() ?></div><?php endif; ?>
      <button class="btn btn-primary btn-block" type="submit">Sign in</button>
    </form>
    <p class="text-center mt-2"><a href="<?= e(url('forgot.php')) ?>">Forgot your password?</a></p>
    <div class="divider-text">New to Cityland online bidding?</div>
    <a class="btn btn-gold btn-block" href="<?= e(url('register.php')) ?>">Register as a bidder</a>
  </div>
</section>
<?php clear_old(); View::footer();
