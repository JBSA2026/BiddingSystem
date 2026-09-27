<?php
declare(strict_types=1);

/** Pluggable SMS sender for mobile OTP. Disabled unless config sms.provider is set. */
final class Sms
{
    public static function enabled(): bool
    {
        return config('sms.provider', 'none') !== 'none' && config('sms.api_key', '') !== '';
    }

    public static function send(string $to, string $message): bool
    {
        $provider = (string) config('sms.provider', 'none');
        try {
            if ($provider === 'semaphore') {
                return self::post('https://api.semaphore.co/api/v4/messages', [
                    'apikey' => config('sms.api_key'), 'number' => ltrim($to, '+'),
                    'message' => $message, 'sendername' => config('sms.sender_name'),
                ]);
            }
            if ($provider === 'http') {
                $repl = ['{to}' => rawurlencode($to), '{message}' => rawurlencode($message),
                    '{api_key}' => rawurlencode((string) config('sms.api_key')), '{sender}' => rawurlencode((string) config('sms.sender_name'))];
                $url = strtr((string) config('sms.http_url'), $repl);
                if (strtoupper((string) config('sms.http_method', 'POST')) === 'GET') {
                    return self::request($url, null);
                }
                return self::post($url, ['to' => $to, 'message' => $message, 'apikey' => config('sms.api_key'), 'sender' => config('sms.sender_name')]);
            }
        } catch (Throwable $e) {
            error_log('SMS failed: ' . $e->getMessage());
        }
        return false;
    }

    private static function post(string $url, array $fields): bool
    {
        return self::request($url, http_build_query($fields));
    }

    private static function request(string $url, ?string $body): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }
}
