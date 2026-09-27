<?php
declare(strict_types=1);

/** RFC 6238 time-based one-time passwords for optional administrator 2FA (Google Authenticator, Authy, MS Authenticator). */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $length = 32): string
    {
        $s = '';
        for ($i = 0; $i < $length; $i++) {
            $s .= self::ALPHABET[random_int(0, 31)];
        }
        return $s;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
    }

    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return false;
        }
        $t = intdiv(time(), 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::at($secret, $t + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function at(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        $bin = pack('N*', 0) . pack('N*', $counter);
        $h = hash_hmac('sha1', $bin, $key, true);
        $o = ord($h[19]) & 0xf;
        $n = ((ord($h[$o]) & 0x7f) << 24) | ((ord($h[$o + 1]) & 0xff) << 16) | ((ord($h[$o + 2]) & 0xff) << 8) | (ord($h[$o + 3]) & 0xff);
        return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $s): string
    {
        $s = strtoupper(rtrim($s, '='));
        $bits = '';
        foreach (str_split($s) as $c) {
            $p = strpos(self::ALPHABET, $c);
            if ($p === false) {
                continue;
            }
            $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
