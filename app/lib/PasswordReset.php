<?php
declare(strict_types=1);

/** Secure password reset: random single-use token (hash stored), 60-minute expiry, rate limited. */
final class PasswordReset
{
    public static function request(string $type, string $email): void
    {
        $email = mb_strtolower(trim($email));
        $ip = client_ip();
        if (Throttle::tooMany('pw_reset', 'ip:' . $ip, 10, 60) || Throttle::tooMany('pw_reset', 'e:' . $email, 3, 60)) {
            return; // silently rate-limit; the UI message is identical either way
        }
        Throttle::hit('pw_reset', 'ip:' . $ip);
        Throttle::hit('pw_reset', 'e:' . $email);
        $table = $type === 'admin' ? 'admins' : 'users';
        $user = DB::one("SELECT * FROM `{$table}` WHERE email = ? AND is_active = 1", [$email]);
        Audit::log('password_reset_requested', $type, $user['id'] ?? null, null, ['email' => $email, 'exists' => (bool) $user], ['guest', null, $email]);
        if (!$user) {
            return;
        }
        $token = random_token(32);
        DB::run('UPDATE password_resets SET used_at = ? WHERE user_type = ? AND user_id = ? AND used_at IS NULL', [now(), $type, $user['id']]);
        DB::insert('password_resets', ['user_type' => $type, 'user_id' => $user['id'], 'token_hash' => secure_hash('reset:' . $token),
            'expires_at' => date('Y-m-d H:i:s', time() + 3600), 'ip' => $ip, 'created_at' => now()]);
        $link = url(($type === 'admin' ? 'admin/reset.php' : 'reset.php') . '?token=' . $token);
        $name = $user['full_name'] ?? $user['name'];
        Notifier::emailOnly($user['email'], $name, 'password_reset', 'Password reset request', [
            'We received a request to reset the password for your Cityland Bidding account.',
            'Click the button below to choose a new password. This link expires in 60 minutes and can only be used once.',
            'If you did not request this, you can ignore this email — your password will not change. Request IP: ' . $ip,
        ], $link, 'Reset my password');
    }

    public static function find(string $type, string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return DB::one('SELECT * FROM password_resets WHERE token_hash = ? AND user_type = ? AND used_at IS NULL AND expires_at > ?', [secure_hash('reset:' . $token), $type, now()]);
    }

    public static function complete(array $reset, string $password): void
    {
        $table = $reset['user_type'] === 'admin' ? 'admins' : 'users';
        DB::update($table, ['password_hash' => Security::hashPassword($password), 'updated_at' => now()] + ($table === 'admins' ? ['must_change_password' => 0] : []), 'id = ?', [$reset['user_id']]);
        DB::update('password_resets', ['used_at' => now()], 'id = ?', [$reset['id']]);
        Throttle::clear('login_fail_' . ($reset['user_type'] === 'admin' ? 'admin' : 'bidder'), 'acct:' . DB::val("SELECT email FROM `{$table}` WHERE id = ?", [$reset['user_id']]));
        Audit::log('password_reset_completed', $reset['user_type'], $reset['user_id'], null, null, [$reset['user_type'], (int) $reset['user_id'], null]);
    }
}
