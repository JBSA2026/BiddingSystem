<?php
declare(strict_types=1);

/** Database-backed rate limiting for logins, OTPs, registration and other sensitive actions. */
final class Throttle
{
    public static function hit(string $action, string $key): void
    {
        DB::insert('throttle_events', ['action' => $action, 'tkey' => mb_substr($key, 0, 190), 'created_at' => now()]);
    }

    public static function count(string $action, string $key, int $minutes): int
    {
        return (int) DB::val(
            'SELECT COUNT(*) FROM throttle_events WHERE action = ? AND tkey = ? AND created_at >= ?',
            [$action, mb_substr($key, 0, 190), date('Y-m-d H:i:s', time() - $minutes * 60)]
        );
    }

    public static function tooMany(string $action, string $key, int $max, int $minutes): bool
    {
        return self::count($action, $key, $minutes) >= $max;
    }

    public static function clear(string $action, string $key): void
    {
        DB::run('DELETE FROM throttle_events WHERE action = ? AND tkey = ?', [$action, mb_substr($key, 0, 190)]);
    }

    /** Housekeeping (called from cron). */
    public static function purge(int $days = 7): void
    {
        DB::run('DELETE FROM throttle_events WHERE created_at < ?', [date('Y-m-d H:i:s', time() - $days * 86400)]);
    }
}
