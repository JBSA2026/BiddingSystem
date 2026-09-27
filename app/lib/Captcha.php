<?php
declare(strict_types=1);

/**
 * Human verification: Google reCAPTCHA v2, Cloudflare Turnstile, or a built-in
 * image CAPTCHA (GD) that needs no external service. A hidden honeypot field
 * is always checked as well.
 */
final class Captcha
{
    public static function provider(): string
    {
        $p = (string) config('captcha.provider', 'builtin');
        if (in_array($p, ['recaptcha', 'turnstile'], true) && config('captcha.site_key') && config('captcha.secret_key')) {
            return $p;
        }
        return 'builtin';
    }

    /** HTML for the widget. Scripts load from Google/Cloudflare only when those providers are configured. */
    public static function widget(): string
    {
        $hp = '<div class="hp-field" aria-hidden="true"><label>Leave this empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
        switch (self::provider()) {
            case 'recaptcha':
                return $hp . '<script src="https://www.google.com/recaptcha/api.js" async defer></script><div class="g-recaptcha" data-sitekey="' . e(config('captcha.site_key')) . '"></div>';
            case 'turnstile':
                return $hp . '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><div class="cf-turnstile" data-sitekey="' . e(config('captcha.site_key')) . '"></div>';
            default:
                return $hp . '<div class="captcha-box"><img src="' . e(url('captcha.php')) . '?r=' . random_int(1000, 9999) . '" alt="Security code image" class="captcha-img" width="180" height="56">'
                    . '<button type="button" class="btn btn-link btn-sm js-captcha-refresh">New code</button></div>'
                    . '<label class="form-label" for="captcha">Type the characters shown above <span class="req">*</span></label>'
                    . '<input type="text" class="form-control" id="captcha" name="captcha" autocomplete="off" required maxlength="8" inputmode="text" autocapitalize="characters">';
        }
    }

    public static function verify(): bool
    {
        if (!empty($_POST['website'])) {
            return false; // honeypot tripped
        }
        switch (self::provider()) {
            case 'recaptcha':
                return self::remote('https://www.google.com/recaptcha/api/siteverify', (string) ($_POST['g-recaptcha-response'] ?? ''));
            case 'turnstile':
                return self::remote('https://challenges.cloudflare.com/turnstile/v0/siteverify', (string) ($_POST['cf-turnstile-response'] ?? ''));
            default:
                $expected = $_SESSION['_captcha'] ?? '';
                unset($_SESSION['_captcha']); // single use
                $given = strtoupper(preg_replace('/\s+/', '', (string) ($_POST['captcha'] ?? '')) ?? '');
                return $expected !== '' && $given !== '' && hash_equals($expected, $given);
        }
    }

    private static function remote(string $endpoint, string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_POSTFIELDS => http_build_query(['secret' => config('captcha.secret_key'), 'response' => $token, 'remoteip' => client_ip()]),
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        $data = is_string($res) ? json_decode($res, true) : null;
        return is_array($data) && !empty($data['success']);
    }

    /** Render the built-in CAPTCHA image and store the answer in session. */
    public static function image(): void
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 5; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $_SESSION['_captcha'] = $code;
        header('Cache-Control: no-store');
        if (!function_exists('imagecreatetruecolor')) {
            // GD unavailable: fall back to an SVG with noise.
            header('Content-Type: image/svg+xml');
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="180" height="56"><rect width="100%" height="100%" fill="#eef2f7"/>';
            for ($i = 0; $i < 8; $i++) {
                $svg .= sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="#9fb0c7" stroke-width="1"/>', random_int(0, 180), random_int(0, 56), random_int(0, 180), random_int(0, 56));
            }
            foreach (str_split($code) as $i => $ch) {
                $svg .= sprintf('<text x="%d" y="%d" font-family="monospace" font-size="30" font-weight="bold" fill="#0b2e59" transform="rotate(%d %d 30)">%s</text>', 16 + $i * 32, random_int(34, 44), random_int(-18, 18), 26 + $i * 32, $ch);
            }
            echo $svg . '</svg>';
            return;
        }
        $w = 180; $h = 56;
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 238, 242, 247));
        for ($i = 0; $i < 10; $i++) {
            imageline($im, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), imagecolorallocate($im, random_int(140, 200), random_int(150, 200), random_int(170, 220)));
        }
        for ($i = 0; $i < 300; $i++) {
            imagesetpixel($im, random_int(0, $w - 1), random_int(0, $h - 1), imagecolorallocate($im, random_int(100, 220), random_int(100, 220), random_int(100, 220)));
        }
        $font = 5;
        foreach (str_split($code) as $i => $ch) {
            $col = imagecolorallocate($im, random_int(0, 60), random_int(30, 80), random_int(80, 140));
            // Draw each char enlarged via a tiny temp image for legibility without TTF fonts.
            $tmp = imagecreatetruecolor(12, 16);
            imagefill($tmp, 0, 0, imagecolorallocatealpha($tmp, 0, 0, 0, 127));
            imagesavealpha($tmp, true);
            imagealphablending($tmp, false);
            imagefilledrectangle($tmp, 0, 0, 12, 16, imagecolorallocatealpha($tmp, 0, 0, 0, 127));
            imagealphablending($tmp, true);
            imagestring($tmp, $font, 1, 0, $ch, $col);
            $tmp = imagerotate($tmp, random_int(-15, 15), imagecolorallocatealpha($tmp, 0, 0, 0, 127));
            imagecopyresampled($im, $tmp, 12 + $i * 32, random_int(4, 12), 0, 0, 26, 36, imagesx($tmp), imagesy($tmp));
            imagedestroy($tmp);
        }
        header('Content-Type: image/png');
        imagepng($im);
        imagedestroy($im);
    }
}
