<?php
declare(strict_types=1);

/**
 * Core bidding engine.
 *
 * Integrity guarantees:
 *  - Bids are INSERT-only. Revisions and withdrawals are new rows linked via previous_bid_id.
 *  - Timestamps come from the database server clock (NOW(6)), never the bidder's device.
 *  - Each bid is hash-chained to the previous bid on the same property (tamper-evident).
 *  - Bid validation runs inside a transaction holding a row lock on the property, so the
 *    closing-time check, anti-sniping extension and ranking are race-free.
 *  - Closing freezes a ranking snapshot and moves the property to UNDER EVALUATION.
 *  - Nothing ever auto-awards: awards require recommendation + authorized approval.
 */
final class Bidding
{
    public const GPS_DENIED_NOTE = 'Location permission not granted.';

    // ================================================================ status sync
    public static function syncStatuses(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $opened = DB::all("SELECT id, ref_no FROM properties WHERE status = 'upcoming' AND is_archived = 0 AND opening_at <= NOW() AND closing_at > NOW()");
            foreach ($opened as $p) {
                $n = DB::run("UPDATE properties SET status = 'open' WHERE id = ? AND status = 'upcoming'", [$p['id']])->rowCount();
                if ($n) {
                    Audit::log('bidding_opened', 'property', $p['id'], ['status' => 'upcoming'], ['status' => 'open'], ['system', null, 'System']);
                }
            }
            $due = DB::all("SELECT id FROM properties WHERE status IN ('open','upcoming') AND is_archived = 0 AND closing_at <= NOW()");
            foreach ($due as $p) {
                self::close((int) $p['id'], 'scheduled', ['system', null, 'System']);
            }
        } catch (PDOException $e) {
            error_log('syncStatuses: ' . $e->getMessage());
        }
    }

    // ================================================================ reading
    public static function property(int $id): ?array
    {
        return DB::one('SELECT p.*, t.name AS type_name FROM properties p JOIN property_types t ON t.id = p.property_type_id WHERE p.id = ?', [$id]);
    }

    public static function propertyByRef(string $ref): ?array
    {
        return DB::one('SELECT p.*, t.name AS type_name FROM properties p JOIN property_types t ON t.id = p.property_type_id WHERE p.ref_no = ?', [$ref]);
    }

    /** Latest non-withdrawn bid per bidder (the bidder's "current" bid). */
    public static function activeBids(int $propertyId): array
    {
        return DB::all(
            "SELECT b.* FROM bids b
             JOIN (SELECT user_id, MAX(id) AS mid FROM bids WHERE property_id = ? GROUP BY user_id) x ON x.mid = b.id
             WHERE b.bid_type <> 'withdrawal'",
            [$propertyId]
        );
    }

    /**
     * Live ranking: highest amount first; ties broken by EARLIEST server timestamp.
     * Disqualified participants are excluded when $excludeDisqualified.
     */
    public static function ranking(int $propertyId, bool $excludeDisqualified = true): array
    {
        $sql = "SELECT b.id AS bid_id, b.bid_ref, b.user_id, b.amount, b.submitted_at, b.bid_type, b.gps_status, b.gps_lat, b.gps_lng, b.gps_accuracy, b.ip_address,
                       u.full_name, u.company_name, u.email, u.mobile, u.bidder_no, u.verification_status, u.is_flagged, u.is_blacklisted,
                       pb.eval_status, pb.eval_remarks, pb.access_status
                FROM bids b
                JOIN (SELECT user_id, MAX(id) AS mid FROM bids WHERE property_id = ? GROUP BY user_id) x ON x.mid = b.id
                JOIN users u ON u.id = b.user_id
                LEFT JOIN property_bidders pb ON pb.property_id = b.property_id AND pb.user_id = b.user_id
                WHERE b.bid_type <> 'withdrawal'";
        if ($excludeDisqualified) {
            $sql .= " AND (pb.eval_status IS NULL OR pb.eval_status <> 'disqualified')";
        }
        $sql .= ' ORDER BY b.amount DESC, b.submitted_at ASC, b.id ASC';
        $rows = DB::all($sql, [$propertyId]);
        foreach ($rows as $i => &$r) {
            $r['rank'] = $i + 1;
        }
        return $rows;
    }

    /** Ranking frozen at close (if any). */
    public static function snapshot(int $propertyId): array
    {
        return DB::all(
            "SELECT rs.*, b.bid_ref, b.gps_status, b.gps_lat, b.gps_lng, b.gps_accuracy, b.ip_address,
                    u.full_name, u.company_name, u.email, u.mobile, u.bidder_no, u.verification_status, u.is_flagged, u.is_blacklisted,
                    pb.eval_status, pb.eval_remarks
             FROM ranking_snapshots rs
             JOIN bids b ON b.id = rs.bid_id
             JOIN users u ON u.id = rs.user_id
             LEFT JOIN property_bidders pb ON pb.property_id = rs.property_id AND pb.user_id = rs.user_id
             WHERE rs.property_id = ? ORDER BY rs.rank_no ASC",
            [$propertyId]
        );
    }

    /** Highest-ranked bidder whose evaluation status is "qualified" (recommendation only). */
    public static function recommendedBidder(int $propertyId): ?array
    {
        $rows = self::snapshot($propertyId) ?: self::ranking($propertyId);
        foreach ($rows as $r) {
            if (($r['eval_status'] ?? '') === 'qualified' && !(int) $r['is_blacklisted']) {
                return $r;
            }
        }
        return null;
    }

    public static function highest(int $propertyId): ?array
    {
        return self::ranking($propertyId)[0] ?? null;
    }

    public static function bidderPosition(int $propertyId, int $userId): ?int
    {
        foreach (self::ranking($propertyId) as $r) {
            if ((int) $r['user_id'] === $userId) {
                return (int) $r['rank'];
            }
        }
        return null;
    }

    /** Seconds until close by server clock (0 if closed). */
    public static function secondsRemaining(array $p): int
    {
        return max(0, strtotime($p['closing_at']) - time());
    }

    // ================================================================ submit
    /**
     * Submit a bid, a revised bid, or a withdrawal.
     * @param array{status:string,lat?:?string,lng?:?string,accuracy?:?string,captured_at?:?string} $gps
     * @return array{ok:bool,error?:string,bid?:array,extended?:bool}
     */
    public static function submit(array $user, int $propertyId, string $action, ?string $amount, array $gps, string $deviceInfo): array
    {
        $uid = (int) $user['id'];
        $property = self::property($propertyId);
        if (!$property || !(int) $property['is_published'] || (int) $property['is_archived']) {
            return ['ok' => false, 'error' => 'Property not found.'];
        }
        $elig = Eligibility::check($user, $property);
        if (!$elig['ok']) {
            $missing = array_column(array_filter($elig['items'], static fn($i) => !$i['ok']), 'label');
            return ['ok' => false, 'error' => 'You are not yet eligible to bid: ' . implode('; ', $missing) . '.'];
        }

        // GPS rules — never silently collected; the client only sends what the bidder explicitly allowed.
        $gpsStatus = in_array($gps['status'] ?? '', ['granted', 'denied', 'unavailable'], true) ? $gps['status'] : 'not_requested';
        $lat = $lng = $acc = null;
        $gpsAt = null;
        if ($gpsStatus === 'granted') {
            $lat = filter_var($gps['lat'] ?? null, FILTER_VALIDATE_FLOAT);
            $lng = filter_var($gps['lng'] ?? null, FILTER_VALIDATE_FLOAT);
            $acc = filter_var($gps['accuracy'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($lat === false || $lng === false || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                $gpsStatus = 'unavailable';
                $lat = $lng = $acc = null;
            } else {
                $gpsAt = now(); // server time of receipt; device time is not trusted
                $acc = $acc === false ? null : round((float) $acc, 2);
            }
        }
        if ($property['gps_mode'] === 'required' && $gpsStatus !== 'granted' && $action !== 'withdraw') {
            return ['ok' => false, 'error' => 'This bidding event requires location sharing. Please allow location access to submit your bid.'];
        }
        $gpsNote = match ($gpsStatus) {
            'granted' => 'Location shared with consent.',
            'denied' => self::GPS_DENIED_NOTE,
            'unavailable' => 'Location unavailable on device.',
            default => self::GPS_DENIED_NOTE,
        };

        $terms = Legal::current('terms');
        $privacy = Legal::current('privacy');

        DB::begin();
        try {
            // Lock the property row: serializes all bids for this property.
            $p = DB::one('SELECT * FROM properties WHERE id = ? FOR UPDATE', [$propertyId]);
            $serverNow = (string) DB::val('SELECT NOW(6)');
            $nowTs = strtotime(substr($serverNow, 0, 19));
            if ($p['status'] !== 'open' || $nowTs < strtotime($p['opening_at']) || $nowTs >= strtotime($p['closing_at'])) {
                DB::rollBack();
                return ['ok' => false, 'error' => 'Bidding is not open for this property. Bids and modifications are no longer accepted.'];
            }

            $prior = DB::one('SELECT * FROM bids WHERE property_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1', [$propertyId, $uid]);
            $priorActive = $prior && $prior['bid_type'] !== 'withdrawal' ? $prior : null;

            if ($action === 'withdraw') {
                if (!(int) $p['allow_withdrawal']) {
                    DB::rollBack();
                    return ['ok' => false, 'error' => 'Bid withdrawal is not allowed for this property.'];
                }
                if (!$priorActive) {
                    DB::rollBack();
                    return ['ok' => false, 'error' => 'You have no active bid to withdraw.'];
                }
                $type = 'withdrawal';
                $amt = (string) $priorActive['amount'];
            } else {
                if ($amount === null) {
                    DB::rollBack();
                    return ['ok' => false, 'error' => 'Please enter a valid bid amount (numbers only, up to 2 decimal places).'];
                }
                $amt = $amount;
                if ($p['bid_mode'] === 'single' && $prior) {
                    DB::rollBack();
                    return ['ok' => false, 'error' => 'Only one bid is allowed per bidder for this property. Your bid has already been recorded.'];
                }
                if (amount_cents($amt) < amount_cents($p['starting_price'])) {
                    DB::rollBack();
                    return ['ok' => false, 'error' => 'Your bid must be at least the minimum bid price of ' . money($p['starting_price']) . '.'];
                }
                $inc = amount_cents($p['min_increment']);
                if ($priorActive) {
                    if ((int) $p['higher_only']) {
                        $need = amount_cents($priorActive['amount']) + max($inc, 1);
                        if (amount_cents($amt) < $need) {
                            DB::rollBack();
                            return ['ok' => false, 'error' => 'A revised bid must be higher than your previous bid of ' . money($priorActive['amount'])
                                . ($inc > 0 ? ' by at least ' . money($p['min_increment']) . ' (minimum ' . money($need / 100) . ')' : '') . '.'];
                        }
                    } elseif (amount_cents($amt) === amount_cents($priorActive['amount'])) {
                        DB::rollBack();
                        return ['ok' => false, 'error' => 'Your revised bid is the same as your current bid.'];
                    }
                }
                if ((int) $p['show_highest'] && $inc > 0) {
                    $top = self::ranking($propertyId)[0] ?? null;
                    if ($top && (int) $top['user_id'] !== $uid && amount_cents($amt) < amount_cents($top['amount']) + $inc) {
                        DB::rollBack();
                        return ['ok' => false, 'error' => 'Your bid must be at least ' . money((amount_cents($top['amount']) + $inc) / 100) . ' (current highest bid plus the minimum increment).'];
                    }
                }
                $type = $prior ? 'revision' : 'initial';
            }

            // Anti-sniping automatic extension (valid bids only, not withdrawals)
            $extended = false;
            $newClosing = $p['closing_at'];
            if ($type !== 'withdrawal' && (int) $p['antisnipe_enabled'] && (int) $p['extensions_used'] < (int) $p['antisnipe_max_ext']) {
                $remaining = strtotime($p['closing_at']) - $nowTs;
                if ($remaining <= ((int) $p['antisnipe_trigger_min']) * 60) {
                    $newClosing = date('Y-m-d H:i:s', strtotime($p['closing_at']) + ((int) $p['antisnipe_extend_min']) * 60);
                    DB::update('properties', ['closing_at' => $newClosing, 'extensions_used' => (int) $p['extensions_used'] + 1], 'id = ?', [$propertyId]);
                    $extended = true;
                }
            }

            // Participation record
            DB::run(
                'INSERT INTO property_bidders (property_id, user_id, access_status, eval_status, joined_at) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE property_id = property_id',
                [$propertyId, $uid, (int) $p['require_prequalification'] ? 'approved' : 'not_required', 'under_review', now()]
            );

            $prevHash = DB::val('SELECT hash FROM bids WHERE property_id = ? ORDER BY id DESC LIMIT 1', [$propertyId]) ?: str_repeat('0', 64);
            do {
                $ref = make_reference('BID');
            } while (DB::val('SELECT 1 FROM bids WHERE bid_ref = ?', [$ref]));

            $row = [
                'bid_ref' => $ref, 'property_id' => $propertyId, 'user_id' => $uid, 'bid_type' => $type,
                'amount' => $amt, 'previous_bid_id' => $prior['id'] ?? null, 'submitted_at' => $serverNow,
                'ip_address' => client_ip(), 'user_agent' => user_agent(), 'device_info' => mb_substr($deviceInfo, 0, 500),
                'gps_status' => $gpsStatus, 'gps_lat' => $lat !== null ? (string) $lat : null, 'gps_lng' => $lng !== null ? (string) $lng : null,
                'gps_accuracy' => $acc !== null ? (string) $acc : null, 'gps_captured_at' => $gpsAt, 'gps_note' => $gpsNote,
                'terms_document_id' => $terms['id'] ?? null, 'terms_version' => $terms['version'] ?? null,
                'property_terms_version' => (int) $p['terms_version'], 'privacy_version' => $privacy['version'] ?? null,
                'triggered_extension' => $extended ? 1 : 0, 'prev_hash' => $prevHash,
            ];
            $row['hash'] = self::bidHash($row);
            $bidId = DB::insert('bids', $row);
            $row['id'] = $bidId;

            // Record acceptance of the terms in force at the moment of bidding.
            if ($terms) {
                Legal::record($uid, 'terms', $terms, $terms['version'], 'bid:' . $ref);
            }
            Legal::record($uid, 'property_terms', null, (string) $p['terms_version'], 'bid:' . $ref . ';property:' . $p['ref_no']);
            if ($gpsStatus !== 'not_requested') {
                $gdoc = Legal::current('gps_consent');
                Legal::record($uid, 'gps', $gdoc, $gdoc['version'] ?? '1.0', 'bid:' . $ref, $gpsStatus === 'granted');
            }

            $auditAction = ['initial' => 'bid_submitted', 'revision' => 'bid_revised', 'withdrawal' => 'bid_withdrawn'][$type];
            Audit::log($auditAction, 'bid', $bidId,
                $prior ? ['bid_ref' => $prior['bid_ref'], 'amount' => $prior['amount'], 'type' => $prior['bid_type']] : null,
                ['bid_ref' => $ref, 'property' => $p['ref_no'], 'amount' => $amt, 'type' => $type, 'server_time' => $serverNow, 'gps' => $gpsStatus]);
            if ($extended) {
                Audit::log('closing_extended_antisnipe', 'property', $propertyId, ['closing_at' => $p['closing_at']],
                    ['closing_at' => $newClosing, 'extension_no' => (int) $p['extensions_used'] + 1, 'trigger_bid' => $ref], ['system', null, 'Anti-sniping rule']);
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // ---- post-commit side effects (notifications, suspicious-activity checks)
        $bid = DB::one('SELECT * FROM bids WHERE id = ?', [$bidId]);
        $pLink = 'property.php?ref=' . rawurlencode($property['ref_no']);
        if ($type === 'withdrawal') {
            Notifier::bidder($user, 'bid_withdrawn', 'Bid withdrawn — ' . $property['name'], [
                'Your bid has been withdrawn as requested. This withdrawal is permanently recorded in the bid history.',
                Notifier::table(['Property' => $property['name'] . ' (' . $property['ref_no'] . ')', 'Withdrawal reference' => $ref, 'Server timestamp' => fmt_dt_precise($serverNow)]),
            ], 'dashboard.php');
            Notifier::admins('bid_withdrawn', 'Bid withdrawn: ' . $property['ref_no'], "Bidder {$user['bidder_no']} withdrew their bid on {$property['name']} ({$property['ref_no']}). Ref {$ref}.", 'admin/property_bids.php?id=' . $propertyId);
        } else {
            $isRevision = $type === 'revision';
            Notifier::bidder($user, $isRevision ? 'bid_updated' : 'bid_received', ($isRevision ? 'Revised bid received — ' : 'Bid received — ') . $property['name'], [
                $isRevision ? 'We have received your revised bid. Your previous bid(s) remain permanently recorded in the bid history.' : 'Thank you. We have received your bid.',
                Notifier::table(['Property' => $property['name'], 'Property reference' => $property['ref_no'], 'Bid amount' => money($amt), 'Bid reference' => $ref, 'Submitted (server time)' => fmt_dt_precise($serverNow)]),
                'Please keep your bid reference for your records. Ranking is for evaluation only; the award is subject to Cityland evaluation and approval.',
            ], 'acknowledgment.php?ref=' . rawurlencode($ref), 'View acknowledgment');
            Notifier::admins($isRevision ? 'revised_bid' : 'new_bid', ($isRevision ? 'Revised bid' : 'New bid') . ': ' . $property['ref_no'],
                "Bidder {$user['bidder_no']} submitted " . ($isRevision ? 'a revised' : 'a new') . " bid of " . money($amt) . " on {$property['name']} ({$property['ref_no']}). Ref {$ref}.", 'admin/property_bids.php?id=' . $propertyId);
        }
        if ($extended) {
            self::notifyParticipants($propertyId, 'schedule_change', 'Bidding extended — ' . $property['name'], [
                'A bid was received within the final ' . $property['antisnipe_trigger_min'] . ' minutes, so the automatic extension rule was applied.',
                Notifier::table(['Property' => $property['name'] . ' (' . $property['ref_no'] . ')', 'New closing time' => fmt_dt($newClosing, 'M j, Y g:i:s A')]),
            ], $pLink);
        }
        self::detectSuspicious($user, $property);
        return ['ok' => true, 'bid' => $bid, 'extended' => $extended];
    }

    public static function bidHash(array $r): string
    {
        return hash('sha256', implode('|', [
            $r['prev_hash'], $r['bid_ref'], $r['property_id'], $r['user_id'], $r['bid_type'],
            number_format((float) $r['amount'], 2, '.', ''), Audit::normTime((string) $r['submitted_at']), (string) $r['ip_address'],
            (string) $r['gps_status'], (string) ($r['gps_lat'] !== null ? number_format((float) $r['gps_lat'], 7, '.', '') : ''),
            (string) ($r['gps_lng'] !== null ? number_format((float) $r['gps_lng'], 7, '.', '') : ''), (string) $r['terms_version'],
        ]));
    }

    /** Verify the bid hash chain of a property. */
    public static function verifyChain(int $propertyId): array
    {
        $prev = str_repeat('0', 64);
        $n = 0;
        foreach (DB::all('SELECT * FROM bids WHERE property_id = ? ORDER BY id ASC', [$propertyId]) as $r) {
            $n++;
            if ($r['prev_hash'] !== $prev || self::bidHash($r) !== $r['hash']) {
                return ['ok' => false, 'checked' => $n, 'broken_at' => $r['bid_ref']];
            }
            $prev = $r['hash'];
        }
        return ['ok' => true, 'checked' => $n, 'broken_at' => null];
    }

    private static function detectSuspicious(array $user, array $property): void
    {
        $uid = (int) $user['id'];
        $pid = (int) $property['id'];
        $ip = client_ip();
        // Many bids in a short time
        $recent = (int) DB::val('SELECT COUNT(*) FROM bids WHERE user_id = ? AND submitted_at >= ?', [$uid, date('Y-m-d H:i:s', time() - 600)]);
        if ($recent >= 10) {
            self::flag($uid, 'suspicious', "High bid frequency: {$recent} bids in 10 minutes (property {$property['ref_no']}).");
        }
        // Different bidders on the same property from the same IP
        $others = DB::all('SELECT DISTINCT b.user_id, u.bidder_no FROM bids b JOIN users u ON u.id = b.user_id WHERE b.property_id = ? AND b.ip_address = ? AND b.user_id <> ?', [$pid, $ip, $uid]);
        if ($others) {
            $list = implode(', ', array_column($others, 'bidder_no'));
            self::flag($uid, 'suspicious', "Bid on {$property['ref_no']} from IP {$ip}, also used by bidder(s) {$list}.");
        }
    }

    /** Raise a flag once per identical detail string; notifies admins. */
    public static function flag(int $userId, string $type, string $details, ?int $adminId = null): void
    {
        if (DB::val('SELECT 1 FROM bidder_flags WHERE user_id = ? AND details = ? AND resolved_at IS NULL', [$userId, $details])) {
            return;
        }
        $id = DB::insert('bidder_flags', ['user_id' => $userId, 'flag_type' => $type, 'details' => mb_substr($details, 0, 1000),
            'source' => $adminId ? 'admin' : 'system', 'created_by' => $adminId, 'created_at' => now()]);
        DB::update('users', ['is_flagged' => 1], 'id = ?', [$userId]);
        Audit::log('bidder_flagged', 'user', $userId, null, ['flag_id' => $id, 'type' => $type, 'details' => $details], $adminId ? null : ['system', null, 'System']);
        if (!$adminId) {
            $no = DB::val('SELECT bidder_no FROM users WHERE id = ?', [$userId]);
            Notifier::admins('suspicious_activity', 'Suspicious activity: ' . $no, $details, 'admin/bidder_view.php?id=' . $userId);
        }
    }

    // ================================================================ closing
    /**
     * Close bidding: disable bids, lock the ranking snapshot, set UNDER EVALUATION
     * (or CLOSED if nobody bid). Idempotent.
     */
    public static function close(int $propertyId, string $reason, ?array $actor = null, ?string $remarks = null): bool
    {
        DB::begin();
        try {
            $p = DB::one('SELECT * FROM properties WHERE id = ? FOR UPDATE', [$propertyId]);
            if (!$p || !in_array($p['status'], ['open', 'upcoming'], true)) {
                DB::rollBack();
                return false;
            }
            $ranking = self::ranking($propertyId, false);
            $lockedAt = now();
            foreach ($ranking as $r) {
                DB::insert('ranking_snapshots', ['property_id' => $propertyId, 'rank_no' => $r['rank'], 'user_id' => $r['user_id'],
                    'bid_id' => $r['bid_id'], 'amount' => $r['amount'], 'bid_time' => $r['submitted_at'], 'locked_at' => $lockedAt]);
            }
            $newStatus = $ranking ? 'under_evaluation' : 'closed';
            $upd = ['status' => $newStatus, 'closed_at' => $lockedAt];
            if ($reason === 'manual' && strtotime($p['closing_at']) > time()) {
                $upd['closing_at'] = $lockedAt; // early manual close
            }
            DB::update('properties', $upd, 'id = ?', [$propertyId]);
            Audit::log('bidding_closed', 'property', $propertyId, ['status' => $p['status'], 'closing_at' => $p['closing_at']],
                ['status' => $newStatus, 'reason' => $reason, 'remarks' => $remarks, 'ranked_bidders' => count($ranking), 'locked_at' => $lockedAt], $actor);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $label = $newStatus === 'under_evaluation' ? 'is now UNDER EVALUATION' : 'closed with no bids';
        Notifier::admins('closing_bidding_event', 'Bidding closed: ' . $p['ref_no'], "Bidding for {$p['name']} ({$p['ref_no']}) closed ({$reason}) and {$label}. Ranked bidders: " . count($ranking) . '.', 'admin/property_bids.php?id=' . $propertyId);
        self::notifyParticipants($propertyId, 'evaluation_status', 'Bidding closed — ' . $p['name'], [
            'Bidding for this property has officially closed. No further bids or modifications are accepted. All bids are preserved.',
            Notifier::table(['Property' => $p['name'] . ' (' . $p['ref_no'] . ')', 'Status' => status_label($newStatus)]),
            'Cityland will now evaluate bidder identity, documents, eligibility and compliance. You will be notified of the result.',
        ], 'dashboard.php');
        return true;
    }

    public static function cancel(int $propertyId, string $reason): void
    {
        $p = self::property($propertyId);
        if (!$p || in_array($p['status'], ['awarded', 'cancelled'], true)) {
            throw new RuntimeException('This property cannot be cancelled in its current status.');
        }
        DB::update('properties', ['status' => 'cancelled', 'cancelled_reason' => $reason, 'closed_at' => $p['closed_at'] ?? now()], 'id = ?', [$propertyId]);
        DB::run("UPDATE awards SET status = 'cancelled' WHERE property_id = ? AND status = 'pending_approval'", [$propertyId]);
        Audit::log('bidding_cancelled', 'property', $propertyId, ['status' => $p['status']], ['status' => 'cancelled', 'reason' => $reason]);
        self::notifyParticipants($propertyId, 'bidding_cancellation', 'Bidding cancelled — ' . $p['name'], [
            'We regret to inform you that the bidding for the property below has been cancelled.',
            Notifier::table(['Property' => $p['name'] . ' (' . $p['ref_no'] . ')', 'Reason' => $reason]),
            'Any bid security deposit will be handled according to the bidding terms. Thank you for your interest.',
        ], 'dashboard.php');
    }

    /**
     * Change bidding schedule. After bidding starts this must go through a reasoned (and optionally
     * dual-authorized) request; every change is audited and participants are notified.
     */
    public static function applyScheduleChange(int $propertyId, string $opening, string $closing, string $reason, ?int $approvedBy = null): void
    {
        DB::begin();
        try {
            $p = DB::one('SELECT * FROM properties WHERE id = ? FOR UPDATE', [$propertyId]);
            if (!in_array($p['status'], ['upcoming', 'open'], true)) {
                throw new RuntimeException('The schedule can only be changed while bidding is upcoming or open.');
            }
            if ($p['status'] === 'open' && $opening !== $p['opening_at']) {
                $opening = $p['opening_at']; // opening time is fixed once bidding started
            }
            if (strtotime($closing) <= strtotime($opening)) {
                throw new RuntimeException('Closing time must be after opening time.');
            }
            $new = ['opening_at' => $opening, 'closing_at' => $closing];
            if ($p['status'] === 'upcoming') {
                $new['original_closing_at'] = $closing;
            }
            DB::update('properties', $new + ['updated_at' => now()], 'id = ?', [$propertyId]);
            Audit::log('schedule_changed', 'property', $propertyId, ['opening_at' => $p['opening_at'], 'closing_at' => $p['closing_at']],
                ['opening_at' => $opening, 'closing_at' => $closing, 'reason' => $reason, 'approved_by' => $approvedBy]);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        if ($p['status'] === 'open' || DB::val('SELECT 1 FROM property_bidders WHERE property_id = ? LIMIT 1', [$propertyId])) {
            self::notifyParticipants($propertyId, 'schedule_change', 'Bidding schedule changed — ' . $p['name'], [
                'The bidding schedule for the property below has been updated.',
                Notifier::table(['Property' => $p['name'] . ' (' . $p['ref_no'] . ')', 'Previous closing' => fmt_dt($p['closing_at']), 'New opening' => fmt_dt($opening), 'New closing' => fmt_dt($closing), 'Reason' => $reason]),
            ], 'property.php?ref=' . rawurlencode($p['ref_no']));
        }
    }

    // ================================================================ evaluation & award
    public static function setEvalStatus(int $propertyId, int $userId, string $status, string $remarks, array $admin): void
    {
        if (!in_array($status, ['under_review', 'qualified', 'disqualified'], true)) {
            throw new RuntimeException('Invalid status.');
        }
        $p = self::property($propertyId);
        if (!in_array($p['status'], ['under_evaluation', 'closed'], true)) {
            throw new RuntimeException('Bidders can only be evaluated after bidding closes (Under Evaluation).');
        }
        if (DB::val("SELECT 1 FROM awards WHERE property_id = ? AND status = 'pending_approval'", [$propertyId])) {
            throw new RuntimeException('An award is pending approval. Reject it first before changing evaluation statuses.');
        }
        $pb = DB::one('SELECT * FROM property_bidders WHERE property_id = ? AND user_id = ?', [$propertyId, $userId]);
        if (!$pb) {
            throw new RuntimeException('Bidder is not a participant.');
        }
        DB::update('property_bidders', ['eval_status' => $status, 'eval_remarks' => $remarks, 'evaluated_by' => $admin['id'], 'evaluated_at' => now()], 'id = ?', [$pb['id']]);
        Audit::log('bid_' . ($status === 'qualified' ? 'qualified' : ($status === 'disqualified' ? 'disqualified' : 'under_review')), 'property_bidder', $pb['id'],
            ['eval_status' => $pb['eval_status'], 'eval_remarks' => $pb['eval_remarks']], ['property' => $p['ref_no'], 'user_id' => $userId, 'eval_status' => $status, 'remarks' => $remarks]);
        $user = DB::one('SELECT * FROM users WHERE id = ?', [$userId]);
        Notifier::bidder($user, 'evaluation_status', 'Bid evaluation update — ' . $p['name'], [
            'The evaluation status of your participation has been updated.',
            Notifier::table(['Property' => $p['name'] . ' (' . $p['ref_no'] . ')', 'Evaluation status' => status_label($status)]),
            $status === 'disqualified' ? 'If you have questions about this decision, please contact Cityland.' : 'You will be notified once the evaluation is complete.',
        ], 'dashboard.php');
    }

    /** Step 1: recommend winning (+ backup) bidder. Never awards by itself. */
    public static function recommendAward(int $propertyId, int $winnerId, array $backupIds, string $remarks, array $admin): int
    {
        $p = self::property($propertyId);
        if ($p['status'] !== 'under_evaluation') {
            throw new RuntimeException('Awards can only be recommended while the property is Under Evaluation.');
        }
        if (DB::val("SELECT 1 FROM awards WHERE property_id = ? AND status = 'pending_approval'", [$propertyId])) {
            throw new RuntimeException('There is already an award pending approval for this property.');
        }
        $rows = [];
        foreach (self::snapshot($propertyId) ?: self::ranking($propertyId, false) as $r) {
            $rows[(int) $r['user_id']] = $r;
        }
        if (!isset($rows[$winnerId]) || ($rows[$winnerId]['eval_status'] ?? '') !== 'qualified') {
            throw new RuntimeException('The winning bidder must have a valid bid and be marked Qualified.');
        }
        $backupIds = array_values(array_unique(array_filter(array_map('intval', $backupIds), static fn($id) => $id !== $winnerId)));
        foreach ($backupIds as $b) {
            if (!isset($rows[$b]) || ($rows[$b]['eval_status'] ?? '') !== 'qualified') {
                throw new RuntimeException('Backup bidders must have a valid bid and be marked Qualified.');
            }
        }
        $bidId = (int) ($rows[$winnerId]['bid_id'] ?? 0);
        $id = DB::insert('awards', [
            'property_id' => $propertyId, 'winning_user_id' => $winnerId, 'winning_bid_id' => $bidId,
            'backup_user_ids' => json_encode($backupIds), 'status' => 'pending_approval',
            'recommended_by' => $admin['id'], 'recommended_at' => now(), 'recommend_remarks' => $remarks,
        ]);
        $top = array_key_first($rows);
        Audit::log('award_recommended', 'award', $id, null, ['property' => $p['ref_no'], 'winner_user_id' => $winnerId, 'bid_ref' => $rows[$winnerId]['bid_ref'],
            'amount' => $rows[$winnerId]['amount'], 'backups' => $backupIds, 'remarks' => $remarks, 'is_highest_bidder' => $top === $winnerId]);
        Notifier::admins('award_pending', 'Award pending approval: ' . $p['ref_no'], "{$admin['name']} recommended bidder {$rows[$winnerId]['bidder_no']} (" . money($rows[$winnerId]['amount']) . ") as winning bidder for {$p['name']} ({$p['ref_no']}). Approval by an authorized Approving Officer is required.", 'admin/award.php?property=' . $propertyId);
        return $id;
    }

    /** Step 2: authorized approval — only now is the property AWARDED. */
    public static function approveAward(int $awardId, array $admin, string $reference, string $remarks, ?array $doc): void
    {
        DB::begin();
        try {
            $a = DB::one("SELECT * FROM awards WHERE id = ? AND status = 'pending_approval' FOR UPDATE", [$awardId]);
            if (!$a) {
                throw new RuntimeException('Award not found or no longer pending.');
            }
            if (Settings::bool('dual_auth_award', true) && (int) $a['recommended_by'] === (int) $admin['id']) {
                throw new RuntimeException('Dual authorization is enabled: the award must be approved by a different authorized officer than the one who recommended it.');
            }
            $p = DB::one('SELECT * FROM properties WHERE id = ? FOR UPDATE', [$a['property_id']]);
            if ($p['status'] !== 'under_evaluation') {
                throw new RuntimeException('Property is not under evaluation.');
            }
            $backups = json_decode((string) $a['backup_user_ids'], true) ?: [];
            DB::update('awards', ['status' => 'approved', 'approved_by' => $admin['id'], 'approved_at' => now(), 'approval_reference' => $reference,
                'approval_remarks' => $remarks, 'supporting_doc_path' => $doc['path'] ?? null, 'supporting_doc_name' => $doc['original'] ?? null], 'id = ?', [$awardId]);
            DB::update('properties', ['status' => 'awarded', 'updated_at' => now()], 'id = ?', [$p['id']]);
            $participants = DB::all('SELECT * FROM property_bidders WHERE property_id = ?', [$p['id']]);
            foreach ($participants as $pb) {
                $uid = (int) $pb['user_id'];
                $new = $uid === (int) $a['winning_user_id'] ? 'winning' : (in_array($uid, $backups, true) ? 'backup' : ($pb['eval_status'] === 'disqualified' ? 'disqualified' : 'not_awarded'));
                if ($new !== $pb['eval_status']) {
                    DB::update('property_bidders', ['eval_status' => $new, 'evaluated_by' => $admin['id'], 'evaluated_at' => now()], 'id = ?', [$pb['id']]);
                }
            }
            Audit::log('award_approved', 'award', $awardId, ['status' => 'pending_approval', 'property_status' => 'under_evaluation'],
                ['status' => 'approved', 'property' => $p['ref_no'], 'winner_user_id' => $a['winning_user_id'], 'backups' => $backups,
                    'approval_reference' => $reference, 'remarks' => $remarks, 'approved_by' => $admin['name'], 'document' => $doc['original'] ?? null]);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        foreach (DB::all('SELECT pb.eval_status, u.* FROM property_bidders pb JOIN users u ON u.id = pb.user_id WHERE pb.property_id = ?', [$p['id']]) as $u) {
            $info = Notifier::table(['Property' => $p['name'] . ' (' . $p['ref_no'] . ')']);
            switch ($u['eval_status']) {
                case 'winning':
                    $bid = DB::one('SELECT * FROM bids WHERE id = ?', [$a['winning_bid_id']]);
                    Notifier::bidder($u, 'winning_bidder', 'Congratulations — you are the winning bidder', [
                        'We are pleased to inform you that, after evaluation and approval by Cityland management, you have been selected as the WINNING BIDDER.',
                        Notifier::table(['Property' => $p['name'] . ' (' . $p['ref_no'] . ')', 'Winning bid' => money($bid['amount']), 'Bid reference' => $bid['bid_ref'], 'Approval reference' => $reference]),
                        'A Cityland representative will contact you regarding the next steps, documentary requirements and payment schedule.',
                    ], 'dashboard.php');
                    break;
                case 'backup':
                    Notifier::bidder($u, 'backup_bidder', 'Backup bidder notification — ' . $p['name'], [
                        'After evaluation, you have been designated as a BACKUP BIDDER for the property below. Should the winning bidder fail to comply with the award conditions, Cityland may offer the property to you.',
                        $info,
                    ], 'dashboard.php');
                    break;
                case 'not_awarded':
                    Notifier::bidder($u, 'non_winning', 'Bidding result — ' . $p['name'], [
                        'Thank you for participating. After evaluation, the property below has been awarded to another bidder.',
                        $info, 'Any bid security deposit will be handled according to the bidding terms. We hope to see you in future bidding events.',
                    ], 'dashboard.php');
                    break;
            }
        }
    }

    public static function rejectAward(int $awardId, array $admin, string $remarks): void
    {
        $a = DB::one("SELECT * FROM awards WHERE id = ? AND status = 'pending_approval'", [$awardId]);
        if (!$a) {
            throw new RuntimeException('Award not found or no longer pending.');
        }
        DB::update('awards', ['status' => 'rejected', 'approved_by' => $admin['id'], 'approved_at' => now(), 'approval_remarks' => $remarks], 'id = ?', [$awardId]);
        Audit::log('award_rejected', 'award', $awardId, ['status' => 'pending_approval'], ['status' => 'rejected', 'remarks' => $remarks]);
    }

    // ================================================================ helpers
    public static function notifyParticipants(int $propertyId, string $template, string $subject, array $paragraphs, ?string $link = null): void
    {
        $users = DB::all('SELECT u.* FROM property_bidders pb JOIN users u ON u.id = pb.user_id WHERE pb.property_id = ? AND u.is_active = 1', [$propertyId]);
        foreach ($users as $u) {
            Notifier::bidder($u, $template, $subject, $paragraphs, $link);
        }
    }

    public static function nextPropertyRef(): string
    {
        do {
            $ref = 'CL-' . date('Y') . '-' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        } while (DB::val('SELECT 1 FROM properties WHERE ref_no = ?', [$ref]));
        return $ref;
    }

    /** Human-readable summary of a property's bid rules (shown to bidders BEFORE they bid). */
    public static function rulesSummary(array $p): array
    {
        $r = [];
        $r[] = $p['bid_mode'] === 'single' ? 'One (1) bid only per bidder — bids cannot be revised.' : 'Multiple / revised bids are allowed. All previous bids are permanently retained.';
        if ($p['bid_mode'] === 'multiple') {
            $r[] = (int) $p['higher_only'] ? 'Revised bids must be HIGHER than your previous bid' . ((float) $p['min_increment'] > 0 ? ' by at least ' . money($p['min_increment']) : '') . '.' : 'Revised bids may be higher or lower than your previous bid.';
        }
        $r[] = 'Minimum bid: ' . money($p['starting_price']) . '.';
        if ((float) $p['min_increment'] > 0 && (int) $p['show_highest']) {
            $r[] = 'Each bid must exceed the current highest bid by at least ' . money($p['min_increment']) . '.';
        }
        $r[] = (int) $p['allow_withdrawal'] ? 'Bid withdrawal is allowed before closing (the withdrawal is recorded).' : 'Bids cannot be withdrawn once submitted.';
        $r[] = 'Your ranking is ' . ((int) $p['show_ranking'] ? 'visible' : 'NOT visible') . ' to you; the highest bid amount is ' . ((int) $p['show_highest'] ? 'visible' : 'NOT visible')
            . '; the number of bidders is ' . ((int) $p['show_bidder_count'] ? 'visible' : 'NOT visible') . '. Bidder identities are never disclosed.';
        $r[] = $p['gps_mode'] === 'required' ? 'Location sharing is REQUIRED to submit a bid for this event.' : 'Location sharing is optional.';
        if ((int) $p['antisnipe_enabled']) {
            $r[] = sprintf('Automatic extension (anti-sniping): if a valid bid is received within the final %d minute(s), closing is extended by %d minute(s), up to %d time(s).',
                $p['antisnipe_trigger_min'], $p['antisnipe_extend_min'], $p['antisnipe_max_ext']);
        } else {
            $r[] = 'No automatic extension: bidding closes exactly at the official closing time (server time).';
        }
        if (Eligibility::depositRequired($p)) {
            $r[] = 'A ' . ((int) $p['deposit_refundable'] ? 'refundable' : 'NON-refundable') . ' bid security deposit of ' . money($p['deposit_amount']) . ' is required before bidding.';
        }
        $r[] = 'Ranking is for evaluation only. The highest bid does not automatically win; awards require Cityland evaluation and management approval.';
        return $r;
    }
}
