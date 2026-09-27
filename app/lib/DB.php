<?php
declare(strict_types=1);

/**
 * Thin PDO wrapper. ALL queries use prepared statements with bound parameters.
 * Table/column names passed to insert()/update() must come from code, never from user input.
 */
final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                config('db.host', 'localhost'),
                (int) config('db.port', 3306),
                config('db.name'),
                config('db.charset', 'utf8mb4')
            );
            self::$pdo = new PDO($dsn, (string) config('db.user'), (string) config('db.pass'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            // Align DB clock with PHP application timezone so NOW() and PHP date() agree.
            $offset = (new DateTimeImmutable('now'))->format('P');
            self::$pdo->exec("SET time_zone = '" . $offset . "'");
            self::$pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        }
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::run($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(',', array_map(static fn($c) => '`' . $c . '`=?', array_keys($data)));
        $stmt = self::run('UPDATE `' . $table . '` SET ' . $set . ' WHERE ' . $where, array_merge(array_values($data), $whereParams));
        return $stmt->rowCount();
    }

    public static function begin(): void
    {
        self::pdo()->beginTransaction();
    }

    public static function commit(): void
    {
        self::pdo()->commit();
        Audit::releaseTxLock();
    }

    public static function rollBack(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
        Audit::releaseTxLock();
    }

    public static function inTransaction(): bool
    {
        return self::pdo()->inTransaction();
    }

    /** Server (database) time with microseconds — the authoritative bidding clock. */
    public static function serverTime(): string
    {
        return (string) self::val('SELECT NOW(6)');
    }

    /** Build "IN (?,?,?)" placeholder list. */
    public static function in(array $values): string
    {
        return implode(',', array_fill(0, max(1, count($values)), '?'));
    }
}
