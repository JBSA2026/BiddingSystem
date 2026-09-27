<?php
declare(strict_types=1);

/** Authentication for bidders and administrators, with rate limiting and lockout. */
final class Auth
{
    // ------------------------------------------------------------------ bidders
    public static function bidder(): ?array
    {
        static $cache = null;
        $id = (int) ($_SESSION['bidder_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        if ($cache === null || (int) $cache['id'] !== $id) {
            $cache = DB::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$id]);
            if (!$cache) {
                unset($_SESSION['bidder_id'], $_SESSION['bidder_name']);
                return null;
            }
        }
        return $cache;
    }

    public static function requireBidder(): array
    {
        $u = self::bidder();
        if (!$u) {
            $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? null;
            flash('info', 'Please sign in or register to continue.');
            redirect('login.php');
        }
        return $u;
    }

    /**
     * @return array{ok:bool,error?:string,user?:array}
     */
    public static function attemptBidder(string $email, string $password): array
    {
        return self::attempt('bidder', 'users', $email, $password);
    }

    public static function loginBidder(array $user): void
    {
        Session::regenerate();
        $_SESSION['bidder_id'] = (int) $user['id'];
        $_SESSION['bidder_name'] = $user['full_name'];
        DB::update('users', ['last_login_at' => now(), 'last_login_ip' => client_ip()], 'id = ?', [$user['id']]);
    }

    public static function logoutBidder(): void
    {
        unset($_SESSION['bidder_id'], $_SESSION['bidder_name']);
        Session::regenerate();
    }

    // ------------------------------------------------------------------ admins
    public static function admin(): ?array
    {
        static $cache = null;
        $id = (int) ($_SESSION['admin_id'] ?? 0);
        if ($id <= 0 || empty($_SESSION['admin_2fa_ok'])) {
            return null;
        }
        if ($cache === null || (int) $cache['id'] !== $id) {
            $cache = DB::one('SELECT * FROM admins WHERE id = ? AND is_active = 1', [$id]);
            if (!$cache) {
                unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_2fa_ok']);
                return null;
            }
        }
        return $cache;
    }

    public static function requireAdmin(?string $permission = null): array
    {
        $a = self::admin();
        if (!$a) {
            $_SESSION['_admin_intended'] = $_SERVER['REQUEST_URI'] ?? null;
            redirect('admin/login.php');
        }
        if ($a['must_change_password'] && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'account.php') {
            flash('warning', 'Please change your temporary password before continuing.');
            redirect('admin/account.php');
        }
        if (Settings::bool('admin_2fa_required') && !$a['totp_enabled'] && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'account.php') {
            flash('warning', 'Two-factor authentication is required for all administrators. Please enable it now.');
            redirect('admin/account.php');
        }
        // Licensing: when the license is missing, invalid or past its grace period, only the License page
        // (and own account / sign-out) remain available. Public pages and records are unaffected.
        if (License::adminLocked() && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['license.php', 'account.php', 'logout.php'], true)) {
            flash('error', License::status()['message'] . ' The admin portal is locked until a valid license is entered.');
            redirect('admin/license.php');
        }
        if ($permission !== null && !Rbac::can($a['role'], $permission)) {
            Audit::log('access_denied', 'permission', null, null, ['permission' => $permission, 'page' => $_SERVER['SCRIPT_NAME'] ?? '']);
            abort(403, 'Your role does not have permission for this action.');
        }
        return $a;
    }

    public static function attemptAdmin(string $email, string $password): array
    {
        return self::attempt('admin', 'admins', $email, $password);
    }

    /** First factor passed. If 2FA is enabled the session is "pending" until the TOTP is verified. */
    public static function loginAdminPending(array $admin): void
    {
        Session::regenerate();
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_name'] = $admin['name'];
        $_SESSION['admin_2fa_ok'] = !$admin['totp_enabled'];
        if (!$admin['totp_enabled']) {
            self::completeAdminLogin($admin);
        }
    }

    public static function completeAdminLogin(array $admin): void
    {
        $_SESSION['admin_2fa_ok'] = true;
        session_regenerate_id(true);
        DB::update('admins', ['last_login_at' => now(), 'last_login_ip' => client_ip()], 'id = ?', [$admin['id']]);
    }

    public static function logoutAdmin(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_2fa_ok']);
        Session::regenerate();
    }

    /** Re-authentication for critical actions (award approval, deadline change...). */
    public static function confirmAdminPassword(array $admin, string $password): bool
    {
        return password_verify($password, (string) DB::val('SELECT password_hash FROM admins WHERE id = ?', [$admin['id']]));
    }

    // ------------------------------------------------------------------ shared
    private static function attempt(string $type, string $table, string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));
        $ip = client_ip();
        $window = (int) config('security.lockout_minutes', 15);
        $maxAcct = (int) config('security.max_login_attempts', 5);
        $maxIp = (int) config('security.max_ip_attempts', 20);
        $actor = [$type === 'admin' ? 'admin' : 'bidder', null, $email];

        if (Throttle::tooMany("login_fail_{$type}", 'acct:' . $email, $maxAcct, $window)
            || Throttle::tooMany("login_fail_{$type}", 'ip:' . $ip, $maxIp, $window)) {
            Audit::log('login_blocked', $type, null, null, ['email' => $email, 'reason' => 'rate_limited'], ['guest', null, $email]);
            return ['ok' => false, 'error' => "Too many failed sign-in attempts. Please wait {$window} minutes and try again."];
        }

        $user = DB::one("SELECT * FROM `{$table}` WHERE email = ?", [$email]);
        // Always run password_verify to keep response time uniform (user enumeration defence).
        $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        $valid = password_verify($password, $hash) && $user;

        if (!$valid || !(int) $user['is_active'] || ($type === 'bidder' && (int) $user['is_blacklisted'])) {
            Throttle::hit("login_fail_{$type}", 'acct:' . $email);
            Throttle::hit("login_fail_{$type}", 'ip:' . $ip);
            $reason = !$valid ? 'invalid_credentials' : 'inactive_or_blocked';
            Audit::log('login_failed', $type, $user['id'] ?? null, null, ['email' => $email, 'reason' => $reason], ['guest', null, $email]);
            if (Throttle::count("login_fail_{$type}", 'acct:' . $email, $window) >= $maxAcct && $user) {
                Notifier::admins('suspicious_activity', 'Repeated failed sign-in attempts', "Account {$email} ({$type}) was temporarily locked after repeated failed sign-in attempts from IP {$ip}.");
            }
            if ($valid && $type === 'bidder' && (int) $user['is_blacklisted']) {
                return ['ok' => false, 'error' => 'This account is not permitted to access the bidding system. Please contact Cityland.'];
            }
            return ['ok' => false, 'error' => 'Invalid email or password.'];
        }

        Throttle::clear("login_fail_{$type}", 'acct:' . $email);
        if (password_needs_rehash($user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
            DB::update($table, ['password_hash' => Security::hashPassword($password)], 'id = ?', [$user['id']]);
        }
        Audit::log('login_success', $type, $user['id'], null, ['email' => $email], [$actor[0], (int) $user['id'], $user['full_name'] ?? $user['name']]);
        return ['ok' => true, 'user' => $user];
    }
}
