<?php
declare(strict_types=1);

/** Email OTP + one-click link, and SMS OTP. Only hashes are stored; codes expire and are attempt-limited. */
final class Verification
{
    private const TTL_MINUTES = 30;
    private const MAX_ATTEMPTS = 5;

    public static function sendEmail(array $user): bool
    {
        if (Throttle::tooMany('otp_send_email', 'u:' . $user['id'], 5, 60)) {
            return false;
        }
        Throttle::hit('otp_send_email', 'u:' . $user['id']);
        $code = random_code(6);
        $token = random_token(32);
        DB::run('UPDATE verification_codes SET used_at = ? WHERE user_id = ? AND channel = ? AND used_at IS NULL', [now(), $user['id'], 'email']);
        DB::insert('verification_codes', [
            'user_id' => $user['id'], 'channel' => 'email', 'code_hash' => secure_hash('email:' . $user['id'] . ':' . $code),
            'token_hash' => secure_hash('link:' . $token), 'expires_at' => date('Y-m-d H:i:s', time() + self::TTL_MINUTES * 60), 'created_at' => now(),
        ]);
        Notifier::emailOnly($user['email'], $user['full_name'], 'account_verification', 'Verify your email address', [
            'Thank you for registering with the Cityland Online Property Bidding System.',
            ['html' => '<p style="margin:0 0 6px">Your email verification code is:</p><p style="font-size:30px;font-weight:bold;letter-spacing:8px;color:#3e7d25;margin:0 0 16px">' . e($code) . '</p>'],
            'You may also click the button below. The code and link expire in ' . self::TTL_MINUTES . ' minutes. If you did not register, please ignore this email.',
        ], url('verify.php?token=' . $token), 'Verify my email');
        Audit::log('verification_email_sent', 'user', $user['id'], null, ['email' => mask_email($user['email'])]);
        return true;
    }

    public static function sendSms(array $user): bool
    {
        if (!Sms::enabled() || Throttle::tooMany('otp_send_sms', 'u:' . $user['id'], 3, 60)) {
            return false;
        }
        Throttle::hit('otp_send_sms', 'u:' . $user['id']);
        $code = random_code(6);
        DB::run('UPDATE verification_codes SET used_at = ? WHERE user_id = ? AND channel = ? AND used_at IS NULL', [now(), $user['id'], 'mobile']);
        DB::insert('verification_codes', [
            'user_id' => $user['id'], 'channel' => 'mobile', 'code_hash' => secure_hash('mobile:' . $user['id'] . ':' . $code),
            'expires_at' => date('Y-m-d H:i:s', time() + 10 * 60), 'created_at' => now(),
        ]);
        $ok = Sms::send($user['mobile'], 'Your Cityland Bidding verification code is ' . $code . '. Valid for 10 minutes. Never share this code.');
        Audit::log('verification_sms_sent', 'user', $user['id'], null, ['mobile' => mask_mobile($user['mobile']), 'delivered' => $ok]);
        return $ok;
    }

    /** Verify a typed OTP. */
    public static function checkCode(array $user, string $channel, string $code): bool
    {
        $row = DB::one('SELECT * FROM verification_codes WHERE user_id = ? AND channel = ? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1', [$user['id'], $channel, now()]);
        if (!$row || (int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            return false;
        }
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (!hash_equals($row['code_hash'], secure_hash($channel . ':' . $user['id'] . ':' . $code))) {
            DB::run('UPDATE verification_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
            Audit::log('verification_failed', 'user', $user['id'], null, ['channel' => $channel]);
            return false;
        }
        self::markVerified($user, $channel, (int) $row['id']);
        return true;
    }

    /** Verify via emailed link. Returns user id or null. */
    public static function checkToken(string $token): ?int
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = DB::one("SELECT * FROM verification_codes WHERE token_hash = ? AND channel = 'email' AND used_at IS NULL AND expires_at > ?", [secure_hash('link:' . $token), now()]);
        if (!$row) {
            return null;
        }
        $user = DB::one('SELECT * FROM users WHERE id = ?', [$row['user_id']]);
        if (!$user) {
            return null;
        }
        self::markVerified($user, 'email', (int) $row['id']);
        return (int) $user['id'];
    }

    private static function markVerified(array $user, string $channel, int $codeId): void
    {
        DB::update('verification_codes', ['used_at' => now()], 'id = ?', [$codeId]);
        $col = $channel === 'email' ? 'email_verified_at' : 'mobile_verified_at';
        DB::update('users', [$col => now()], 'id = ?', [$user['id']]);
        Audit::log($channel === 'email' ? 'email_verified' : 'mobile_verified', 'user', $user['id'], null, [$col => now()], ['bidder', (int) $user['id'], $user['full_name']]);
        $fresh = DB::one('SELECT * FROM users WHERE id = ?', [$user['id']]);
        $mobileDone = !Eligibility::mobileOtpRequired() || $fresh['mobile_verified_at'];
        if ($fresh['email_verified_at'] && $mobileDone && !DB::val("SELECT 1 FROM audit_logs WHERE action = 'registration_completed' AND entity_type = 'user' AND entity_id = ?", [$user['id']])) {
            Audit::log('registration_completed', 'user', $user['id'], null, ['bidder_no' => $fresh['bidder_no']], ['bidder', (int) $user['id'], $user['full_name']]);
            Notifier::bidder($fresh, 'registration_completed', 'Registration completed', [
                'Your email' . (Eligibility::mobileOtpRequired() ? ' and mobile number have' : ' has') . ' been verified and your registration is complete.',
                Notifier::table(['Bidder number' => $fresh['bidder_no'], 'Name' => $fresh['full_name'], 'Email' => $fresh['email']]),
                Settings::bool('require_admin_approval', true)
                    ? 'Next step: upload your required documents (if you have not yet done so). Cityland will review your registration and notify you once approved.'
                    : 'You may now upload any required documents and join bidding events.',
            ], 'dashboard.php', 'Go to my dashboard');
            Notifier::admins('new_registration', 'New bidder registration: ' . $fresh['bidder_no'], "{$fresh['full_name']}" . ($fresh['company_name'] ? " ({$fresh['company_name']})" : '') . " completed registration and verification. Review their documents and approve or reject their qualification.", 'admin/bidder_view.php?id=' . $fresh['id']);
        }
    }
}
