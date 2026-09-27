<?php
declare(strict_types=1);

/**
 * Software licensing — offline, signed license keys.
 *
 * A license key is  CLB1-<base64url(JSON payload)>.<base64url(Ed25519 signature)>
 * signed with the vendor's PRIVATE key (kept offline, see license-tools/). This file only holds the
 * PUBLIC key, so keys cannot be forged or edited (changing the domain, plan or expiry breaks the signature).
 *
 * Payload: { v, product, lid, licensee, domains[], plan, issued, expires, grace_days, max_admins, max_properties, notes }
 *
 * States:  valid → expiring (≤ 30 days left) → grace (expired, ≤ grace_days) → expired
 *          missing / invalid (bad signature, other product, wrong domain)
 * Enforcement (production only; never in the TEST environment):
 *   - expiring / grace: warning banner in the admin portal
 *   - expired / missing / invalid: admin portal locked to the License page. Public pages, existing bids,
 *     scheduled closing and all records keep working.
 *   - plan limits: active administrators and active published properties.
 */
final class License
{
    public const PRODUCT = 'cityland-bidding';
    public const PREFIX = 'CLB1-';
    public const WARN_DAYS = 30;
    public const DEFAULT_GRACE_DAYS = 7;

    /** Trusted vendor public keys (base64, Ed25519). Add a new key here when rotating; keep old ones until all licenses are reissued. */
    public const PUBLIC_KEYS = [
        '+taBcfFKa3Uk6PGZzBxSwyPa5UupdbMpVwa57ifs5X0=',
    ];

    /**
     * Vendor's online renewal portal (license-portal/, PayMongo). Admins get a "Pay online" link to it.
     * Can be overridden per site with app/config.php  'license' => ['renew_url' => 'https://…'].  '' hides the link.
     */
    public const RENEW_URL = '';

    /** Sales contact for renewals paid offline (payment link / pricing by email). Override with 'license' => ['sales_email' => …]. */
    public const SALES_EMAIL = 'sales@exigent.com.ph';

    private static ?array $cache = null;

    /** Is licensing enforced? Never in the TEST environment. */
    public static function enforced(): bool
    {
        return !is_testing();
    }

    /**
     * Decode and verify a license key string.
     * @return array{ok:bool, payload?:array, error?:string}
     */
    public static function parse(string $key): array
    {
        $key = preg_replace('/\s+/', '', $key) ?? '';
        if (!str_starts_with($key, self::PREFIX) || substr_count($key, '.') !== 1) {
            return ['ok' => false, 'error' => 'This is not a valid Cityland Bidding license key.'];
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return ['ok' => false, 'error' => 'The PHP "sodium" extension is required to verify licenses. Enable it in cPanel → Select PHP Version → Extensions.'];
        }
        [$p, $s] = explode('.', substr($key, strlen(self::PREFIX)), 2);
        $payloadJson = self::b64urlDecode($p);
        $sig = self::b64urlDecode($s);
        if ($payloadJson === null || $sig === null || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return ['ok' => false, 'error' => 'The license key is incomplete or damaged. Copy it again exactly as issued.'];
        }
        $verified = false;
        foreach (self::PUBLIC_KEYS as $pub) {
            $pk = base64_decode($pub, true);
            if ($pk !== false && strlen($pk) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && sodium_crypto_sign_verify_detached($sig, $payloadJson, $pk)) {
                $verified = true;
                break;
            }
        }
        if (!$verified) {
            return ['ok' => false, 'error' => 'The license signature is invalid. The key was altered or was not issued by the vendor.'];
        }
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload) || ($payload['product'] ?? '') !== self::PRODUCT) {
            return ['ok' => false, 'error' => 'This license is for a different product.'];
        }
        foreach (['lid', 'licensee', 'domains', 'expires'] as $req) {
            if (empty($payload[$req])) {
                return ['ok' => false, 'error' => 'The license is missing required information.'];
            }
        }
        return ['ok' => true, 'payload' => $payload];
    }

    /** The domain this installation runs on (from config app.url). */
    public static function siteHost(): string
    {
        $h = strtolower((string) parse_url(base_url(), PHP_URL_HOST));
        return preg_replace('/^www\./', '', $h) ?? $h;
    }

    public static function domainMatches(array $domains, ?string $host = null): bool
    {
        $host ??= self::siteHost();
        foreach ($domains as $d) {
            $d = preg_replace('/^www\./', '', strtolower(trim((string) $d))) ?? '';
            if ($d === '') {
                continue;
            }
            if ($d === $host || (str_starts_with($d, '*.') && (str_ends_with($host, substr($d, 1)) || $host === substr($d, 2)))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Current license status (cached per request).
     * @return array{state:string, enforced:bool, locked:bool, payload:?array, message:string, days_left:?int, grace_left:?int}
     */
    public static function status(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $key = (string) setting('license_key', '');
        $base = ['enforced' => self::enforced(), 'payload' => null, 'days_left' => null, 'grace_left' => null];
        if ($key === '') {
            return self::finish($base + ['state' => 'missing', 'message' => 'No license key has been entered.']);
        }
        $r = self::parse($key);
        if (!$r['ok']) {
            return self::finish($base + ['state' => 'invalid', 'message' => $r['error']]);
        }
        $p = $r['payload'];
        $base['payload'] = $p;
        if (!self::domainMatches((array) $p['domains'])) {
            return self::finish($base + ['state' => 'invalid', 'message' => 'This license is for ' . implode(', ', (array) $p['domains']) . ', but this site runs on ' . self::siteHost() . '.']);
        }
        $today = new DateTimeImmutable('today');
        $expires = new DateTimeImmutable((string) $p['expires']);
        $daysLeft = (int) $today->diff($expires)->format('%r%a');
        $grace = max(0, (int) ($p['grace_days'] ?? self::DEFAULT_GRACE_DAYS));
        $base['days_left'] = $daysLeft;
        if ($daysLeft < 0) {
            $graceLeft = $grace + $daysLeft;
            $base['grace_left'] = max(0, $graceLeft);
            if ($graceLeft >= 0) {
                return self::finish($base + ['state' => 'grace', 'message' => 'The license expired on ' . $expires->format('F j, Y') . '. The admin portal will be locked in ' . $graceLeft . ' day(s) unless the license is renewed.']);
            }
            return self::finish($base + ['state' => 'expired', 'message' => 'The license expired on ' . $expires->format('F j, Y') . '. Please renew it to unlock the admin portal.']);
        }
        if ($daysLeft <= self::WARN_DAYS) {
            return self::finish($base + ['state' => 'expiring', 'message' => 'The license expires in ' . $daysLeft . ' day(s), on ' . $expires->format('F j, Y') . '. Please arrange renewal.']);
        }
        return self::finish($base + ['state' => 'valid', 'message' => 'Licensed until ' . $expires->format('F j, Y') . '.']);
    }

    private static function finish(array $s): array
    {
        $s['locked'] = $s['enforced'] && in_array($s['state'], ['missing', 'invalid', 'expired'], true);
        return self::$cache = $s;
    }

    public static function adminLocked(): bool
    {
        return self::status()['locked'];
    }

    /** Validate and store a new license key. Returns error message or null on success. */
    public static function install(string $key, array $admin): ?string
    {
        $r = self::parse($key);
        if (!$r['ok']) {
            Audit::log('license_rejected', 'license', null, null, ['error' => $r['error']]);
            return $r['error'];
        }
        $p = $r['payload'];
        if (!self::domainMatches((array) $p['domains'])) {
            Audit::log('license_rejected', 'license', null, null, ['lid' => $p['lid'], 'error' => 'domain mismatch', 'site' => self::siteHost()]);
            return 'This license is for ' . implode(', ', (array) $p['domains']) . ', but this site runs on ' . self::siteHost() . '. Ask the vendor for a license for this domain.';
        }
        $old = self::status()['payload'];
        Settings::set('license_key', preg_replace('/\s+/', '', $key) ?? $key);
        self::$cache = null;
        Audit::log('license_installed', 'license', null, $old ? ['lid' => $old['lid'], 'expires' => $old['expires'], 'plan' => $old['plan'] ?? null] : null,
            ['lid' => $p['lid'], 'licensee' => $p['licensee'], 'plan' => $p['plan'] ?? null, 'expires' => $p['expires'], 'domains' => $p['domains'], 'by' => $admin['name']]);
        return null;
    }

    // ------------------------------------------------------------------ online renewal (PayMongo)
    public static function renewUrl(): string
    {
        $u = rtrim((string) (config('license.renew_url') ?: self::RENEW_URL), '/');
        return preg_match('#^https?://#i', $u) ? $u : '';
    }

    /** Link to the vendor's payment page, carrying the current key (domain-locked, so safe to share) and a way back. */
    public static function payLink(): string
    {
        $base = self::renewUrl();
        if ($base === '') {
            return '';
        }
        return $base . '/index.php?' . http_build_query([
            'key' => (string) setting('license_key', ''),
            'domain' => self::siteHost(),
            'return' => url('admin/license.php'),
        ]);
    }

    public static function salesEmail(): string
    {
        $e = (string) (config('license.sales_email') ?: self::SALES_EMAIL);
        return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
    }

    /** mailto: link asking sales for pricing and a payment link, pre-filled with this installation's license details. */
    public static function salesMailto(): string
    {
        $to = self::salesEmail();
        if ($to === '') {
            return '';
        }
        $p = self::status()['payload'];
        $subject = 'License renewal request — ' . ($p['lid'] ?? 'new license') . ' (' . self::siteHost() . ')';
        $body = "Hello,\n\nPlease send us the pricing plans and a payment link to renew / upgrade our Cityland Bidding System license.\n\n"
            . 'Licensed to: ' . ($p['licensee'] ?? '') . "\n"
            . 'License ID: ' . ($p['lid'] ?? '(none yet)') . "\n"
            . 'Current plan: ' . ($p['plan'] ?? '—') . "\n"
            . 'Expires: ' . ($p['expires'] ?? '—') . "\n"
            . 'Site domain: ' . self::siteHost() . "\n\n"
            . "Preferred plan and term: \n\nThank you.\n";
        return 'mailto:' . $to . '?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($body);
    }

    /**
     * Download the newest paid key for this license from the renewal portal and install it if it extends the license.
     * @return array{ok:bool, message:string}
     */
    public static function fetchRenewal(array $admin): array
    {
        $base = self::renewUrl();
        $p = self::status()['payload'];
        if ($base === '') {
            return ['ok' => false, 'message' => 'Online renewal is not set up for this site.'];
        }
        if (!$p) {
            return ['ok' => false, 'message' => 'No current license to look up. Paste the key you received by email instead.'];
        }
        $url = $base . '/api.php?' . http_build_query(['lid' => $p['lid'], 'domain' => self::siteHost()]);
        $raw = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false]);
            $raw = curl_exec($ch);
            curl_close($ch);
        } else {
            $raw = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 20]]));
        }
        $res = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($res)) {
            return ['ok' => false, 'message' => 'Could not reach the renewal portal. Try again later, or paste the key from your email.'];
        }
        if (empty($res['ok']) || empty($res['license_key'])) {
            return ['ok' => false, 'message' => (string) ($res['error'] ?? 'No renewed license was found yet.')];
        }
        $new = self::parse((string) $res['license_key']);
        if ($new['ok'] && ($new['payload']['lid'] ?? '') === $p['lid'] && (string) $new['payload']['expires'] <= (string) $p['expires']
            && ($new['payload']['plan'] ?? '') === ($p['plan'] ?? '')) {
            return ['ok' => false, 'message' => 'Your newest license (valid until ' . fmt_dt($p['expires'], 'F j, Y') . ') is already installed.'];
        }
        $err = self::install((string) $res['license_key'], $admin);
        return $err ? ['ok' => false, 'message' => $err] : ['ok' => true, 'message' => 'Your renewed license was downloaded and installed. Thank you!'];
    }

    // ------------------------------------------------------------------ plan limits
    /** Numeric plan limit, or null for unlimited (also unlimited when not enforced). */
    public static function limit(string $name): ?int
    {
        if (!self::enforced()) {
            return null;
        }
        $p = self::status()['payload'];
        $v = $p[$name] ?? null;
        return ($v === null || (int) $v <= 0) ? null : (int) $v;
    }

    public static function activeAdmins(): int
    {
        return (int) DB::val('SELECT COUNT(*) FROM admins WHERE is_active = 1');
    }

    /** Published, not archived and not finished (upcoming, open, closed or under evaluation). */
    public static function activeProperties(): int
    {
        return (int) DB::val("SELECT COUNT(*) FROM properties WHERE is_published = 1 AND is_archived = 0 AND status IN ('upcoming','open','closed','under_evaluation')");
    }

    /** Error message if adding one more would exceed the plan, else null. */
    public static function checkLimit(string $what): ?string
    {
        if ($what === 'admins') {
            $max = self::limit('max_admins');
            return ($max !== null && self::activeAdmins() >= $max)
                ? "Your license plan allows {$max} active administrator account(s). Deactivate an account or upgrade the license." : null;
        }
        $max = self::limit('max_properties');
        return ($max !== null && self::activeProperties() >= $max)
            ? "Your license plan allows {$max} active published propert(ies) at a time. Archive or unpublish a finished property, or upgrade the license." : null;
    }

    // ------------------------------------------------------------------ helpers
    public static function b64urlDecode(string $s): ?string
    {
        $d = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $d === false ? null : $d;
    }

    public static function b64urlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
