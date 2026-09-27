<?php
declare(strict_types=1);

/**
 * Permanent, append-only, hash-chained audit trail.
 * Each row stores sha256(prev_hash | row content) so any edit or deletion is detectable
 * via Audit::verifyChain(). The application never updates or deletes audit rows, and the
 * optional install/triggers.sql blocks it at the database level too.
 */
final class Audit
{
    /** Resolve who is acting in the current request. */
    public static function actor(): array
    {
        if (defined('IN_ADMIN') && !empty($_SESSION['admin_id'])) {
            return ['admin', (int) $_SESSION['admin_id'], (string) ($_SESSION['admin_name'] ?? '')];
        }
        if (!empty($_SESSION['bidder_id'])) {
            return ['bidder', (int) $_SESSION['bidder_id'], (string) ($_SESSION['bidder_name'] ?? '')];
        }
        if (PHP_SAPI === 'cli' || defined('IN_CRON')) {
            return ['system', null, 'System'];
        }
        return ['guest', null, null];
    }

    public static function log(
        string $action,
        ?string $entityType = null,
        int|string|null $entityId = null,
        mixed $old = null,
        mixed $new = null,
        ?array $actorOverride = null
    ): void {
        [$type, $id, $name] = $actorOverride ?? self::actor();
        $row = [
            'actor_type'  => $type,
            'actor_id'    => $id,
            'actor_name'  => $name !== null ? mb_substr($name, 0, 190) : null,
            'action'      => mb_substr($action, 0, 80),
            'entity_type' => $entityType,
            'entity_id'   => $entityId !== null ? (int) $entityId : null,
            'old_value'   => self::encode($old),
            'new_value'   => self::encode($new),
            'ip_address'  => PHP_SAPI === 'cli' ? null : client_ip(),
            'user_agent'  => PHP_SAPI === 'cli' ? null : user_agent(),
            'created_at'  => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s.u'),
        ];

        // Serialize chain appends across concurrent requests. Inside a transaction the lock is
        // held until commit/rollback (see DB::commit) so no other writer can chain off an
        // uncommitted row.
        $inTx = DB::inTransaction();
        if (!$inTx || !self::$heldInTx) {
            if ((int) DB::val("SELECT GET_LOCK('cl_audit_chain', 15)") !== 1) {
                throw new RuntimeException('Could not obtain audit lock');
            }
            if ($inTx) {
                self::$heldInTx = true;
            }
        }
        try {
            $prev = DB::val('SELECT hash FROM audit_logs ORDER BY id DESC LIMIT 1');
            $row['prev_hash'] = $prev ?: str_repeat('0', 64);
            $row['hash'] = self::computeHash($row);
            DB::insert('audit_logs', $row);
        } finally {
            if (!$inTx) {
                DB::val("SELECT RELEASE_LOCK('cl_audit_chain')");
            }
        }
    }

    private static bool $heldInTx = false;

    /** Called by DB after commit/rollback. */
    public static function releaseTxLock(): void
    {
        if (self::$heldInTx) {
            self::$heldInTx = false;
            DB::val("SELECT RELEASE_LOCK('cl_audit_chain')");
        }
    }

    private static function encode(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_scalar($v)) {
            return (string) $v;
        }
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function computeHash(array $r): string
    {
        $parts = [
            $r['prev_hash'], $r['actor_type'], (string) $r['actor_id'], (string) $r['actor_name'], $r['action'],
            (string) $r['entity_type'], (string) $r['entity_id'], (string) $r['old_value'], (string) $r['new_value'],
            (string) $r['ip_address'], self::normTime((string) $r['created_at']),
        ];
        return hash('sha256', implode('|', $parts));
    }

    /** Normalize DATETIME(6) to a fixed format so hashes compare regardless of driver formatting. */
    public static function normTime(string $t): string
    {
        try {
            return (new DateTimeImmutable($t))->format('Y-m-d H:i:s.u');
        } catch (Throwable) {
            return $t;
        }
    }

    /** Verify the whole chain. Returns ['ok'=>bool,'checked'=>int,'broken_at'=>?int]. */
    public static function verifyChain(): array
    {
        $prev = str_repeat('0', 64);
        $checked = 0;
        $stmt = DB::run('SELECT * FROM audit_logs ORDER BY id ASC');
        while ($r = $stmt->fetch()) {
            $checked++;
            if ($r['prev_hash'] !== $prev || self::computeHash($r) !== $r['hash']) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => (int) $r['id']];
            }
            $prev = $r['hash'];
        }
        return ['ok' => true, 'checked' => $checked, 'broken_at' => null];
    }
}
