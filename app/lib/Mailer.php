<?php
declare(strict_types=1);

/**
 * Dependency-free SMTP client (SSL/465 or STARTTLS/587, AUTH LOGIN/PLAIN) compatible with
 * cPanel mail accounts and third-party SMTP relays. Emails are queued in `email_queue`
 * and delivered at the end of the request; failures are retried by cron.php.
 */
final class Mailer
{
    private static bool $shutdownRegistered = false;

    /** Queue an email for delivery. */
    public static function queue(string $toEmail, ?string $toName, string $subject, string $html, ?string $template = null): void
    {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        DB::insert('email_queue', [
            'to_email' => $toEmail, 'to_name' => $toName, 'subject' => mb_substr($subject, 0, 255),
            'body_html' => $html, 'body_text' => self::toText($html), 'template' => $template,
            'status' => 'queued', 'created_at' => now(),
        ]);
        if (!self::$shutdownRegistered && PHP_SAPI !== 'cli') {
            self::$shutdownRegistered = true;
            register_shutdown_function(static function (): void {
                if (function_exists('fastcgi_finish_request')) {
                    @session_write_close();
                    fastcgi_finish_request();
                }
                try {
                    self::processQueue(20);
                } catch (Throwable $e) {
                    error_log('Mail queue: ' . $e->getMessage());
                }
            });
        }
    }

    /** Deliver queued emails. Returns number sent. */
    public static function processQueue(int $limit = 50): int
    {
        $rows = DB::all("SELECT * FROM email_queue WHERE status = 'queued' OR (status = 'failed' AND attempts < 5) ORDER BY id ASC LIMIT " . (int) $limit);
        $sent = 0;
        foreach ($rows as $r) {
            // Claim the row so concurrent workers don't double-send.
            $claimed = DB::run("UPDATE email_queue SET attempts = attempts + 1 WHERE id = ? AND attempts = ? AND status <> 'sent'", [$r['id'], $r['attempts']])->rowCount();
            if (!$claimed) {
                continue;
            }
            try {
                self::send($r['to_email'], (string) $r['to_name'], $r['subject'], $r['body_html'], (string) $r['body_text']);
                DB::update('email_queue', ['status' => 'sent', 'sent_at' => now(), 'last_error' => null], 'id = ?', [$r['id']]);
                $sent++;
            } catch (Throwable $e) {
                DB::update('email_queue', ['status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 500)], 'id = ?', [$r['id']]);
            }
        }
        return $sent;
    }

    public static function send(string $to, string $toName, string $subject, string $html, string $text = ''): void
    {
        $driver = (string) config('mail.driver', 'smtp');
        $fromEmail = (string) config('mail.from_email');
        $fromName = (string) config('mail.from_name', 'Cityland');
        $boundary = 'b' . bin2hex(random_bytes(12));
        $text = $text !== '' ? $text : self::toText($html);
        $headers = [
            'Date: ' . date('r'),
            'From: ' . self::encodeHeader($fromName) . ' <' . $fromEmail . '>',
            'To: ' . ($toName !== '' ? self::encodeHeader($toName) . ' ' : '') . '<' . $to . '>',
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (parse_url(base_url(), PHP_URL_HOST) ?: 'localhost') . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: CitylandBidding/' . APP_VERSION,
        ];
        $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . "--{$boundary}--\r\n";

        if ($driver === 'log') {
            file_put_contents(storage_path('logs/mail.log'), "==== " . date('c') . " TO: {$to}\nSubject: {$subject}\n\n{$text}\n\n", FILE_APPEND | LOCK_EX);
            return;
        }
        if ($driver === 'mail') {
            $hdr = array_filter($headers, static fn($h) => !str_starts_with($h, 'To:') && !str_starts_with($h, 'Subject:'));
            if (!mail($to, self::encodeHeader($subject), $body, implode("\r\n", $hdr), '-f' . $fromEmail)) {
                throw new RuntimeException('PHP mail() failed');
            }
            return;
        }
        self::smtp($to, implode("\r\n", $headers) . "\r\n\r\n" . $body);
    }

    private static function smtp(string $to, string $data): void
    {
        $host = (string) config('mail.host');
        $port = (int) config('mail.port', 465);
        $enc = strtolower((string) config('mail.encryption', 'ssl'));
        $timeout = (int) config('mail.timeout', 15);
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($fp, $timeout);
        try {
            self::expect($fp, [220]);
            $ehloHost = parse_url(base_url(), PHP_URL_HOST) ?: 'localhost';
            self::cmd($fp, 'EHLO ' . $ehloHost, [250]);
            if ($enc === 'tls') {
                self::cmd($fp, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new RuntimeException('STARTTLS negotiation failed');
                }
                self::cmd($fp, 'EHLO ' . $ehloHost, [250]);
            }
            $user = (string) config('mail.username');
            if ($user !== '') {
                self::cmd($fp, 'AUTH LOGIN', [334]);
                self::cmd($fp, base64_encode($user), [334]);
                self::cmd($fp, base64_encode((string) config('mail.password')), [235]);
            }
            self::cmd($fp, 'MAIL FROM:<' . config('mail.from_email') . '>', [250]);
            self::cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::cmd($fp, 'DATA', [354]);
            // Dot-stuffing per RFC 5321
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $data));
            self::cmd($fp, $data . "\r\n.", [250]);
            self::cmd($fp, 'QUIT', [221]);
        } finally {
            fclose($fp);
        }
    }

    private static function cmd($fp, string $line, array $ok): string
    {
        fwrite($fp, $line . "\r\n");
        return self::expect($fp, $ok);
    }

    private static function expect($fp, array $ok): string
    {
        $resp = '';
        while (($line = fgets($fp, 515)) !== false) {
            $resp .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($resp, 0, 3);
        if (!in_array($code, $ok, true)) {
            throw new RuntimeException('SMTP error: ' . trim($resp));
        }
        return $resp;
    }

    private static function encodeHeader(string $s): string
    {
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    public static function toText(string $html): string
    {
        $t = preg_replace('/<\/t[dh]>\s*<t[dh][^>]*>/i', ': ', $html) ?? $html;
        $t = preg_replace(['/<br\s*\/?>/i', '/<\/(p|div|h\d|li|tr)>/i'], "\n", $t) ?? $t;
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", $t) ?? $t);
    }
}
