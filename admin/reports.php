<?php
/** Reports and CSV (Excel-compatible, UTF-8 with BOM) exports. Every export is audited. */
declare(strict_types=1);
require __DIR__ . '/_init.php';

$admin = Auth::requireAdmin('reports.export');

$exportCsv = static function (string $name, array $header, iterable $rows): never {
    Audit::log('report_exported', 'report', null, null, ['report' => $name, 'params' => $_GET]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '-' . date('Ymd-His') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel detects UTF-8 (₱, ñ)
    fputcsv($out, $header, ',', '"', '\\');
    foreach ($rows as $r) {
        // Neutralize spreadsheet formula injection
        $r = array_map(static fn($v) => is_string($v) && $v !== '' && str_contains('=+-@', $v[0]) && !is_numeric($v) ? "'" . $v : $v, array_values($r));
        fputcsv($out, $r, ',', '"', '\\');
    }
    fclose($out);
    exit;
};

$pid = query_int('property_id');
$from = query('from');
$to = query('to');
$dateCond = static function (string $col) use ($from, $to, &$params): string {
    $c = '';
    if ($from !== '' && strtotime($from)) {
        $c .= " AND {$col} >= ?";
        $params[] = date('Y-m-d 00:00:00', strtotime($from));
    }
    if ($to !== '' && strtotime($to)) {
        $c .= " AND {$col} <= ?";
        $params[] = date('Y-m-d 23:59:59', strtotime($to));
    }
    return $c;
};

switch (query('export')) {
    case 'properties':
        $params = [];
        $rows = DB::all("SELECT p.ref_no, p.name AS pname, t.name AS tname, p.location, p.city, p.floor_area, p.starting_price, p.min_increment, p.opening_at, p.closing_at, p.original_closing_at, p.extensions_used, p.status, p.is_published, p.is_archived,
            (SELECT COUNT(DISTINCT user_id) FROM bids b WHERE b.property_id = p.id) AS c1, (SELECT COUNT(*) FROM bids b WHERE b.property_id = p.id) AS c2,
            (SELECT MAX(amount) FROM bids b WHERE b.property_id = p.id AND b.bid_type <> 'withdrawal') AS c3, p.created_at
            FROM properties p JOIN property_types t ON t.id = p.property_type_id WHERE 1=1" . $dateCond('p.created_at') . ' ORDER BY p.id', $params);
        $exportCsv('properties', ['Ref no', 'Name', 'Type', 'Location', 'City', 'Floor area', 'Starting price', 'Min increment', 'Opening', 'Closing', 'Original closing', 'Extensions', 'Status', 'Published', 'Archived', 'Bidders', 'Bid records', 'Highest bid', 'Created'], $rows);
    case 'bids':
    case 'property_bids':
        $params = [];
        $cond = $pid ? ' AND b.property_id = ?' : '';
        if ($pid) {
            $params[] = $pid;
        }
        $cond .= $dateCond('b.submitted_at');
        $rows = DB::all("SELECT b.bid_ref, p.ref_no, p.name, u.bidder_no, u.full_name, u.company_name, u.email, u.mobile, b.bid_type, b.amount, b.submitted_at,
            b.ip_address, b.device_info, b.gps_status, b.gps_lat, b.gps_lng, b.gps_accuracy, b.gps_note, b.terms_version, b.property_terms_version, b.privacy_version, b.triggered_extension, b.hash
            FROM bids b JOIN properties p ON p.id = b.property_id JOIN users u ON u.id = b.user_id WHERE 1=1{$cond} ORDER BY b.property_id, b.id", $params);
        $exportCsv($pid ? 'bids-' . DB::val('SELECT ref_no FROM properties WHERE id = ?', [$pid]) : 'bids', ['Bid ref', 'Property ref', 'Property', 'Bidder no', 'Bidder name', 'Company', 'Email', 'Mobile', 'Type', 'Amount', 'Server timestamp', 'IP', 'Device', 'GPS status', 'Latitude', 'Longitude', 'Accuracy (m)', 'GPS note', 'Terms version', 'Property terms version', 'Privacy version', 'Triggered extension', 'Integrity hash'], $rows);
    case 'rankings':
        $params = [];
        $cond = $pid ? ' WHERE rs.property_id = ?' : '';
        if ($pid) {
            $params[] = $pid;
        }
        $rows = DB::all("SELECT p.ref_no, p.name, rs.rank_no, u.bidder_no, u.full_name, u.company_name, rs.amount, rs.bid_time, b.bid_ref, pb.eval_status, pb.eval_remarks, rs.locked_at
            FROM ranking_snapshots rs JOIN properties p ON p.id = rs.property_id JOIN users u ON u.id = rs.user_id JOIN bids b ON b.id = rs.bid_id
            LEFT JOIN property_bidders pb ON pb.property_id = rs.property_id AND pb.user_id = rs.user_id{$cond} ORDER BY rs.property_id, rs.rank_no", $params);
        $exportCsv('final-rankings', ['Property ref', 'Property', 'Rank', 'Bidder no', 'Bidder', 'Company', 'Amount', 'Bid time', 'Bid ref', 'Evaluation status', 'Evaluation remarks', 'Locked at'], $rows);
    case 'bidders':
        $params = [];
        $rows = DB::all("SELECT u.bidder_no, u.full_name, u.company_name, u.email, u.mobile, u.address_line, u.barangay, u.city, u.province, u.postal_code, u.id_type, u.id_number_last4,
            u.email_verified_at, u.mobile_verified_at, u.verification_status, u.verification_remarks, u.is_flagged, u.is_blacklisted, u.blacklist_reason, u.is_active, u.registration_ip, u.created_at, u.last_login_at
            FROM users u WHERE 1=1" . $dateCond('u.created_at') . ' ORDER BY u.id', $params);
        $exportCsv('bidders', ['Bidder no', 'Full name', 'Company', 'Email', 'Mobile', 'Address', 'Barangay', 'City', 'Province', 'Postal', 'ID type', 'ID last 4', 'Email verified', 'Mobile verified', 'Qualification', 'Remarks', 'Flagged', 'Blacklisted', 'Blacklist reason', 'Active', 'Registration IP', 'Registered', 'Last login'], $rows);
    case 'awards':
        $params = [];
        $rows = DB::all("SELECT p.ref_no, p.name, aw.status, u.bidder_no, u.full_name, b.amount, b.bid_ref, aw.backup_user_ids, r.name AS rname, aw.recommended_at, aw.recommend_remarks, ap.name AS apname, aw.approved_at, aw.approval_reference, aw.approval_remarks, aw.supporting_doc_name
            FROM awards aw JOIN properties p ON p.id = aw.property_id JOIN users u ON u.id = aw.winning_user_id JOIN bids b ON b.id = aw.winning_bid_id JOIN admins r ON r.id = aw.recommended_by LEFT JOIN admins ap ON ap.id = aw.approved_by WHERE 1=1" . $dateCond('aw.recommended_at') . ' ORDER BY aw.id', $params);
        $exportCsv('awards', ['Property ref', 'Property', 'Award status', 'Winner bidder no', 'Winner', 'Winning amount', 'Bid ref', 'Backup user IDs', 'Recommended by', 'Recommended at', 'Recommendation remarks', 'Approved by', 'Approved at', 'Approval reference', 'Approval remarks', 'Supporting document'], $rows);
    case 'payments':
        $params = [];
        $rows = DB::all("SELECT pm.id, p.ref_no, u.bidder_no, u.full_name, pm.purpose, pm.amount, pm.method, pm.reference_no, pm.gateway, pm.gateway_txn_id, pm.status, pm.remarks, a.name, pm.reconciled_at, pm.created_at
            FROM payments pm JOIN properties p ON p.id = pm.property_id JOIN users u ON u.id = pm.user_id LEFT JOIN admins a ON a.id = pm.reconciled_by WHERE 1=1" . $dateCond('pm.created_at') . ' ORDER BY pm.id', $params);
        $exportCsv('payments', ['ID', 'Property ref', 'Bidder no', 'Bidder', 'Purpose', 'Amount', 'Method', 'Reference no', 'Gateway', 'Gateway txn', 'Status', 'Remarks', 'Reconciled by', 'Reconciled at', 'Submitted'], $rows);
    case 'audit':
        Auth::requireAdmin('audit.view');
        $params = [];
        $rows = DB::all("SELECT id, created_at, actor_type, actor_id, actor_name, action, entity_type, entity_id, old_value, new_value, ip_address, user_agent, prev_hash, hash FROM audit_logs WHERE 1=1" . $dateCond('created_at') . ' ORDER BY id', $params);
        $exportCsv('audit-log', ['ID', 'Timestamp', 'Actor type', 'Actor ID', 'Actor', 'Action', 'Entity', 'Entity ID', 'Previous value', 'New value', 'IP', 'User agent', 'Prev hash', 'Hash'], $rows);
    case 'consents':
        $params = [];
        $rows = DB::all("SELECT c.accepted_at, u.bidder_no, u.full_name, c.consent_type, c.version, c.context, c.granted, c.ip_address FROM consents c JOIN users u ON u.id = c.user_id WHERE 1=1" . $dateCond('c.accepted_at') . ' ORDER BY c.id', $params);
        $exportCsv('consents', ['Accepted at', 'Bidder no', 'Bidder', 'Consent type', 'Version', 'Context', 'Granted', 'IP'], $rows);
}

$props = DB::all('SELECT id, ref_no, name FROM properties ORDER BY id DESC LIMIT 300');
$summary = DB::all("SELECT p.ref_no, p.name, p.status, p.starting_price,
    (SELECT COUNT(DISTINCT user_id) FROM bids b WHERE b.property_id = p.id) AS bidders,
    (SELECT MAX(amount) FROM bids b WHERE b.property_id = p.id AND b.bid_type <> 'withdrawal') AS highest,
    (SELECT b.amount FROM awards aw JOIN bids b ON b.id = aw.winning_bid_id WHERE aw.property_id = p.id AND aw.status = 'approved' LIMIT 1) AS awarded_amount
    FROM properties p WHERE p.is_archived = 0 ORDER BY p.id DESC LIMIT 50");

View::adminHeader('Reports');
?>
<div class="card"><h2>Export reports (CSV — opens in Excel)</h2>
  <form method="get" class="admin-filters">
    <div class="form-group"><label class="form-label" for="export">Report</label><select class="form-control sm" id="export" name="export" required>
      <option value="properties">Properties summary</option><option value="bids">All bids (full detail incl. GPS, IP, terms)</option><option value="rankings">Final rankings (locked at close)</option>
      <option value="bidders">Bidders</option><option value="awards">Awards</option><option value="payments">Bid security / payments</option><option value="consents">Consent records</option>
      <?php if (can('audit.view')): ?><option value="audit">Audit log</option><?php endif; ?></select></div>
    <div class="form-group"><label class="form-label" for="property_id">Property (bids/rankings)</label><select class="form-control sm" id="property_id" name="property_id"><option value="">All</option><?php foreach ($props as $pp): ?><option value="<?= (int) $pp['id'] ?>"><?= e($pp['ref_no'] . ' — ' . mb_substr($pp['name'], 0, 40)) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label class="form-label" for="from">From</label><input class="form-control sm" type="date" id="from" name="from"></div>
    <div class="form-group"><label class="form-label" for="to">To</label><input class="form-control sm" type="date" id="to" name="to"></div>
    <button class="btn btn-primary btn-sm" type="submit">Download CSV</button>
  </form>
  <p class="small muted">Exports contain personal data. Handle according to the Cityland data privacy policy. Every export is recorded in the audit trail.</p>
</div>
<div class="card"><div class="card-header"><h2>Bidding results summary</h2><button class="btn btn-sm btn-outline" type="button" data-print>Print</button></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Property</th><th>Status</th><th class="num">Starting price</th><th class="num">Bidders</th><th class="num">Highest bid</th><th class="num">Awarded amount</th></tr></thead><tbody>
  <?php foreach ($summary as $s): ?><tr><td><?= e($s['name']) ?><br><small class="muted"><?= e($s['ref_no']) ?></small></td><td><?= status_badge($s['status']) ?></td><td class="num"><?= e(money($s['starting_price'])) ?></td><td class="num"><?= (int) $s['bidders'] ?></td><td class="num"><?= e(money($s['highest'])) ?></td><td class="num"><?= e(money($s['awarded_amount'])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
<?php View::adminFooter();
