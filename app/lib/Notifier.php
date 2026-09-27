<?php
declare(strict_types=1);

/** In-app notifications + branded HTML emails for bidders and administrators. */
final class Notifier
{
    /**
     * Notify a bidder (in-app + email).
     * $paragraphs: array of plain-text lines (escaped) or ['html' => '...'] for pre-escaped html blocks.
     */
    public static function bidder(array $user, string $template, string $subject, array $paragraphs, ?string $linkPath = null, ?string $linkLabel = null, bool $inApp = true): void
    {
        $plain = implode("\n", array_map(static fn($p) => is_array($p) ? Mailer::toText($p['html']) : $p, $paragraphs));
        if ($inApp) {
            DB::insert('notifications', [
                'recipient_type' => 'bidder', 'recipient_id' => (int) $user['id'], 'title' => mb_substr($subject, 0, 190),
                'body' => mb_substr($plain, 0, 2000), 'link' => $linkPath, 'created_at' => now(),
            ]);
        }
        $html = self::layout($subject, 'Dear ' . $user['full_name'] . ',', $paragraphs, $linkPath ? url($linkPath) : null, $linkLabel ?? 'View details');
        Mailer::queue($user['email'], $user['full_name'], $subject, $html, $template);
    }

    /** Email only (e.g. verification code) without an in-app entry. */
    public static function emailOnly(string $email, string $name, string $template, string $subject, array $paragraphs, ?string $absoluteLink = null, ?string $linkLabel = null): void
    {
        Mailer::queue($email, $name, $subject, self::layout($subject, 'Dear ' . $name . ',', $paragraphs, $absoluteLink, $linkLabel ?? 'Open'), $template);
    }

    /** Notify administrators. Event decides which roles receive it. */
    public static function admins(string $event, string $subject, string $message, ?string $linkPath = null): void
    {
        $roles = match ($event) {
            'award_pending', 'schedule_approval' => ['super_admin', 'approving_officer'],
            'suspicious_activity' => ['super_admin', 'bidding_admin', 'approving_officer'],
            default => ['super_admin', 'bidding_admin'],
        };
        $admins = DB::all('SELECT id, name, email FROM admins WHERE is_active = 1 AND role IN (' . DB::in($roles) . ')', $roles);
        foreach ($admins as $a) {
            DB::insert('notifications', [
                'recipient_type' => 'admin', 'recipient_id' => (int) $a['id'], 'title' => mb_substr($subject, 0, 190),
                'body' => mb_substr($message, 0, 2000), 'link' => $linkPath, 'created_at' => now(),
            ]);
        }
        $configured = array_filter(array_map('trim', explode(',', (string) setting('admin_notification_emails', ''))), static fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
        $recipients = $configured ?: array_column($admins, 'email');
        $html = self::layout('[Admin] ' . $subject, 'Hello,', [$message], $linkPath ? url($linkPath) : null, 'Open in admin portal');
        foreach (array_unique($recipients) as $to) {
            Mailer::queue($to, null, '[Cityland Bidding Admin] ' . $subject, $html, 'admin_' . $event);
        }
    }

    /**
     * JSON feed for the notification bell (GET), and mark-as-read actions (POST: action=read_all | read, id).
     */
    public static function feedResponse(string $type, int $id): never
    {
        if (is_post()) {
            Csrf::verify();
            if (input('action') === 'read_all') {
                DB::run('UPDATE notifications SET is_read = 1 WHERE recipient_type = ? AND recipient_id = ? AND is_read = 0', [$type, $id]);
            } elseif (input('action') === 'read') {
                DB::run('UPDATE notifications SET is_read = 1 WHERE recipient_type = ? AND recipient_id = ? AND id = ?', [$type, $id, input_int('id')]);
            }
        }
        $rows = DB::all('SELECT id, title, body, link, is_read, created_at FROM notifications WHERE recipient_type = ? AND recipient_id = ? ORDER BY id DESC LIMIT 10', [$type, $id]);
        json_response([
            'ok' => true,
            'unread' => self::unreadCount($type, $id),
            'latest_id' => (int) (DB::val('SELECT MAX(id) FROM notifications WHERE recipient_type = ? AND recipient_id = ?', [$type, $id]) ?? 0),
            'items' => array_map(static fn($r) => [
                'id' => (int) $r['id'],
                'title' => $r['title'],
                'body' => mb_substr((string) $r['body'], 0, 180),
                'link' => $r['link'] ? url($r['link']) : null,
                'read' => (bool) $r['is_read'],
                'time' => fmt_dt($r['created_at'], 'M j, g:i A'),
            ], $rows),
        ]);
    }

    public static function unreadCount(string $type, int $id): int
    {
        return (int) DB::val('SELECT COUNT(*) FROM notifications WHERE recipient_type = ? AND recipient_id = ? AND is_read = 0', [$type, $id]);
    }

    public static function layout(string $title, string $greeting, array $paragraphs, ?string $link, string $linkLabel): string
    {
        $site = e(setting('site_name', 'Cityland Online Property Bidding'));
        $body = '';
        foreach ($paragraphs as $p) {
            $body .= is_array($p) ? $p['html'] : '<p style="margin:0 0 14px;line-height:1.55">' . nl2br(e($p)) . '</p>';
        }
        $btn = $link ? '<p style="margin:22px 0"><a href="' . e($link) . '" style="background:#3e7d25;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;display:inline-block;font-weight:bold">' . e($linkLabel) . '</a></p>' : '';
        $contact = e(setting('contact_email', '')) . ' · ' . e(setting('contact_phone', '')) . '<br>' . e(setting('contact_address', ''));
        return '<!doctype html><html><body style="margin:0;background:#f4f5f1;font-family:Segoe UI,Arial,sans-serif;color:#1f2937">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f1;padding:24px 0"><tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:10px;overflow:hidden;border:1px solid #e5e7eb">'
            . '<tr><td style="background:#101512;padding:18px 26px;color:#fff;border-bottom:3px solid #3e7d25"><table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background:#fff;border-radius:50%;width:44px;height:44px;text-align:center;vertical-align:middle"><img src="' . e(url('assets/img/logo.png')) . '" width="38" height="38" alt="Cityland" style="display:block;margin:3px auto"></td><td style="padding-left:12px;font-family:Georgia,serif;font-size:19px;font-weight:bold;letter-spacing:3px;color:#ffffff">' . e(mb_strtoupper(setting('company_name', 'Cityland'))) . '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:10px;letter-spacing:3px;color:#8fcf6f;font-weight:600;margin-top:3px">ONLINE PROPERTY BIDDING</div></td></tr></table></td></tr>'
            . '<tr><td style="padding:26px"><h2 style="margin:0 0 16px;font-size:21px;color:#111612;font-family:Georgia,serif">' . e($title) . '</h2>'
            . '<p style="margin:0 0 14px">' . e($greeting) . '</p>' . $body . $btn
            . '<p style="margin:24px 0 0;font-size:12px;color:#6b7280">This is an automated message from the Cityland Online Property Bidding System. Please do not reply directly to this email. For concerns contact ' . $contact . '.</p>'
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /** Standard key/value table for emails. */
    public static function table(array $rows): array
    {
        $h = '<table style="border-collapse:collapse;width:100%;margin:6px 0 16px;font-size:14px">';
        foreach ($rows as $k => $v) {
            $h .= '<tr><td style="padding:8px 10px;border:1px solid #e5e7eb;background:#f9fafb;width:40%;font-weight:600">' . e($k) . '</td><td style="padding:8px 10px;border:1px solid #e5e7eb">' . e($v) . '</td></tr>';
        }
        return ['html' => $h . '</table>'];
    }
}
