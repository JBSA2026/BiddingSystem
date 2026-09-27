<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

if (Auth::admin()) {
    redirect('admin/');
}
$error = null;
$pending2fa = !empty($_SESSION['admin_id']) && empty($_SESSION['admin_2fa_ok']);

if (is_post()) {
    Csrf::verify();
    if (input('step') === '2fa' && $pending2fa) {
        $a = DB::one('SELECT * FROM admins WHERE id = ? AND is_active = 1', [$_SESSION['admin_id']]);
        $key = 'a:' . ($a['id'] ?? 0);
        if (!$a || Throttle::tooMany('admin_2fa', $key, 5, 15)) {
            Auth::logoutAdmin();
            $error = 'Too many invalid codes. Please sign in again after 15 minutes.';
            $pending2fa = false;
        } elseif (Totp::verify((string) $a['totp_secret'], input('code'))) {
            Throttle::clear('admin_2fa', $key);
            Auth::completeAdminLogin($a);
            Audit::log('login_2fa_success', 'admin', $a['id'], null, null, ['admin', (int) $a['id'], $a['name']]);
            $to = $_SESSION['_admin_intended'] ?? null;
            unset($_SESSION['_admin_intended']);
            if (is_string($to) && str_starts_with($to, '/') && !str_starts_with($to, '//')) {
                header('Location: ' . $to, true, 303);
                exit;
            }
            redirect('admin/');
        } else {
            Throttle::hit('admin_2fa', $key);
            Audit::log('login_2fa_failed', 'admin', $a['id'], null, null, ['guest', null, $a['email']]);
            $error = 'Invalid authentication code.';
        }
    } elseif (input('step') === 'cancel') {
        Auth::logoutAdmin();
        redirect('admin/login.php');
    } else {
        if (Throttle::count('login_fail_admin', 'ip:' . client_ip(), 15) >= 3 && !Captcha::verify()) {
            $error = 'Please complete the human verification.';
        } else {
            $res = Auth::attemptAdmin(input('email'), (string) ($_POST['password'] ?? ''));
            if ($res['ok']) {
                Auth::loginAdminPending($res['user']);
                if (!empty($_SESSION['admin_2fa_ok'])) {
                    $to = $_SESSION['_admin_intended'] ?? null;
                    unset($_SESSION['_admin_intended']);
                    if (is_string($to) && str_starts_with($to, '/') && !str_starts_with($to, '//')) {
                        header('Location: ' . $to, true, 303);
                        exit;
                    }
                    redirect('admin/');
                }
                redirect('admin/login.php');
            }
            $error = $res['error'];
        }
    }
}
$showCaptcha = !$pending2fa && Throttle::count('login_fail_admin', 'ip:' . client_ip(), 15) >= 3;
View::header('Administrator sign in');
?>
<section class="container section">
  <div class="card auth-card">
    <h1 class="h2">Cityland administrator portal</h1>
    <p class="muted small">Authorized personnel only. All activity is logged.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($pending2fa): ?>
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="step" value="2fa">
        <div class="form-group"><label class="form-label" for="code">6-digit code from your authenticator app</label>
          <input class="form-control otp-input" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus></div>
        <button class="btn btn-primary btn-block" type="submit">Verify</button>
      </form>
      <form method="post" class="mt-1 text-center"><?= Csrf::field() ?><input type="hidden" name="step" value="cancel"><button class="btn btn-link" type="submit">Cancel</button></form>
    <?php else: ?>
      <form method="post"><?= Csrf::field() ?>
        <div class="form-group"><label class="form-label" for="email">Email</label><input class="form-control" type="email" id="email" name="email" required autocomplete="username" autofocus></div>
        <div class="form-group"><label class="form-label" for="password">Password</label><input class="form-control" type="password" id="password" name="password" required autocomplete="current-password"></div>
        <?php if ($showCaptcha): ?><div class="form-group"><?= Captcha::widget() ?></div><?php endif; ?>
        <button class="btn btn-primary btn-block" type="submit">Sign in</button>
      </form>
      <p class="text-center mt-2"><a href="<?= e(url('admin/forgot.php')) ?>">Forgot password?</a></p>
    <?php endif; ?>
  </div>
</section>
<?php View::footer();
