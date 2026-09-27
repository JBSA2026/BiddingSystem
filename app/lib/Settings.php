<?php
declare(strict_types=1);

final class Settings
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (DB::all('SELECT skey, svalue FROM settings') as $r) {
                self::$cache[$r['skey']] = $r['svalue'];
            }
        }
        return self::$cache;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::all();
        return array_key_exists($key, $all) && $all[$key] !== null ? (string) $all[$key] : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        return $v === null ? $default : $v === '1';
    }

    public static function set(string $key, string $value): void
    {
        DB::run(
            'INSERT INTO settings (skey, svalue, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_at = VALUES(updated_at)',
            [$key, $value, now()]
        );
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }
}
