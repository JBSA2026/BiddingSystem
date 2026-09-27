<?php
/**
 * Scheduled tasks. Run every minute (recommended) from cPanel → Cron Jobs:
 *   * * * * * /usr/local/bin/php /home/USER/public_html/cron.php >/dev/null 2>&1
 * or over HTTP:  https://your-site/cron.php?key=CRON_KEY
 *
 * Tasks: open/close bidding on schedule (lock rankings → Under Evaluation), closing reminders,
 * email queue delivery/retry, daily backup, housekeeping.
 */
declare(strict_types=1);

define('IN_CRON', true);
require __DIR__ . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    // HTTP mode requires the cron key (constant-time compare)
    $key = (string) ($_GET['key'] ?? '');
    if ($key === '' || !hash_equals((string) config('security.cron_key'), $key)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain');
}
@set_time_limit(300);
$lock = storage_path('logs/cron.lock');
$fh = fopen($lock, 'c');
if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
    exit("Another cron run is in progress.\n");
}
$log = static function (string $m): void {
    echo '[' . date('Y-m-d H:i:s') . "] {$m}\n";
};

// 1. Status transitions (opening / closing with ranking lock)
Bidding::syncStatuses();
$log('Status sync complete.');

// 2. Closing reminders to participants and verified-but-not-yet-bidding interested parties
$hours = (int) setting('closing_reminder_hours', '24');
if ($hours > 0) {
    $due = DB::all("SELECT * FROM properties WHERE status = 'open' AND is_published = 1 AND reminder_sent_at IS NULL AND closing_at <= ? AND closing_at > NOW()", [date('Y-m-d H:i:s', time() + $hours * 3600)]);
    foreach ($due as $p) {
        DB::update('properties', ['reminder_sent_at' => now()], 'id = ?', [$p['id']]);
        Bidding::notifyParticipants((int) $p['id'], 'closing_reminder', 'Bidding closing reminder — ' . $p['name'], [
            'This is a reminder that bidding for the property below will close soon. All times are Cityland server time.',
            Notifier::table(['Property' => $p['name'] . ' (' . $p['ref_no'] . ')', 'Closing time' => fmt_dt($p['closing_at'], 'l, M j, Y g:i A')]),
            'Bids received after the official closing time will not be accepted.',
        ], 'property.php?ref=' . rawurlencode($p['ref_no']));
        Audit::log('closing_reminder_sent', 'property', $p['id'], null, ['closing_at' => $p['closing_at']]);
        $log("Reminder sent for {$p['ref_no']}");
    }
}

// 3. Email queue
$sent = Mailer::processQueue(100);
$log("Emails sent: {$sent}");

// 4. Daily backup at/after 02:00 (once per day)
if ((int) date('G') >= 2 && setting('last_backup_date') !== date('Y-m-d')) {
    try {
        $files = Backup::create(true);
        Backup::rotate(14);
        Settings::set('last_backup_date', date('Y-m-d'));
        Audit::log('backup_created', 'backup', null, null, ['files' => array_map('basename', $files), 'scheduled' => true]);
        $log('Daily backup created.');
    } catch (Throwable $e) {
        $log('Backup failed: ' . $e->getMessage());
        Notifier::admins('suspicious_activity', 'Scheduled backup failed', 'The scheduled database backup failed: ' . $e->getMessage());
    }
}

// 5. Housekeeping
if ((int) date('i') === 0) {
    Throttle::purge(7);
    DB::run("DELETE FROM verification_codes WHERE expires_at < ?", [date('Y-m-d H:i:s', time() - 7 * 86400)]);
    DB::run("DELETE FROM password_resets WHERE expires_at < ?", [date('Y-m-d H:i:s', time() - 7 * 86400)]);
    DB::run("DELETE FROM email_queue WHERE status = 'sent' AND sent_at < ?", [date('Y-m-d H:i:s', time() - 90 * 86400)]);
    foreach (glob(storage_path('sessions') . '/sess_*') ?: [] as $s) {
        if (filemtime($s) < time() - 86400) {
            @unlink($s);
        }
    }
    $log('Housekeeping done.');
}
Settings::set('last_cron_run', date('Y-m-d H:i:s'));
flock($fh, LOCK_UN);
fclose($fh);
