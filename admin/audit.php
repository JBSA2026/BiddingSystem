<?php
/** Searchable, read-only audit trail with hash-chain integrity verification. */
declare(strict_types=1);
require __DIR__ . '/_init.php';

$admin = Auth::requireAdmin('audit.view');
$verify = null;
if (is_post()) {
    Csrf::verify();
    $verify = Audit::verifyChain();
    Audit::log('audit_chain_verified', 'audit', null, null, $verify);
}
$q = query('q');
$action = query('action');
$actorType = query('actor_type');
$entity = query('entity');
$entityId = query_int('entity_id');
$from = query('from');
$to = query('to');
$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(actor_name LIKE ? OR old_value LIKE ? OR new_value LIKE ? OR ip_address LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($action !== '') {
    $where[] = 'action = ?';
    $params[] = $action;
}
if (in_array($actorType, ['admin', 'bidder', 'system', 'guest'], true)) {
    $where[] = 'actor_type = ?';
    $params[] = $actorType;
}
if ($entity !== '') {
    $where[] = 'entity_type = ?';
    $params[] = $entity;
}
if ($entityId) {
    $where[] = 'entity_id = ?';
    $params[] = $entityId;
}
if ($from !== '' && strtotime($from)) {
    $where[] = 'created_at >= ?';
    $params[] = date('Y-m-d 00:00:00', strtotime($from));
}
if ($to !== '' && strtotime($to)) {
    $where[] = 'created_at <= ?';
    $params[] = date('Y-m-d 23:59:59', strtotime($to));
}
$w = implode(' AND ', $where);
$total = (int) DB::val("SELECT COUNT(*) FROM audit_logs WHERE {$w}", $params);
$pg = paginate($total, 50, query_int('page', 1));
$rows = DB::all("SELECT * FROM audit_logs WHERE {$w} ORDER BY id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);
$actions = array_column(DB::all('SELECT DISTINCT action FROM audit_logs ORDER BY action'), 'action');
$entities = array_column(DB::all('SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type IS NOT NULL ORDER BY entity_type'), 'entity_type');
$pretty = static function (?string $v): string {
    if ($v === null || $v === '') {
        return '';
    }
    $j = json_decode($v, true);
    return '<pre class="json">' . e(is_array($j) ? json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $v) . '</pre>';
};

View::adminHeader('Audit Trail');
?>
<div class="card">
  <div class="card-header"><div><h2 class="mb-0">Permanent audit trail</h2><small class="muted">Append-only and hash-chained. No administrator can edit or delete audit records through the application<?= DB::val("SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'trg_audit_no_update'") ? '; database triggers also block changes' : '' ?>.</small></div>
    <form method="post"><?= Csrf::field() ?><button class="btn btn-sm btn-primary" type="submit">Verify integrity</button></form></div>
  <?php if ($verify): ?><div class="alert <?= $verify['ok'] ? 'alert-success' : 'alert-error' ?>"><?= $verify['ok'] ? 'Integrity verified: all ' . (int) $verify['checked'] . ' audit records are intact and correctly chained.' : 'INTEGRITY FAILURE at audit record #' . (int) $verify['broken_at'] . ' — records may have been altered outside the application. Escalate immediately.' ?></div><?php endif; ?>
  <form class="admin-filters" method="get">
    <div class="form-group"><label class="form-label" for="q">Search</label><input class="form-control sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Name, value, IP"></div>
    <div class="form-group"><label class="form-label" for="action">Action</label><select class="form-control sm" id="action" name="action"><option value="">All</option><?php foreach ($actions as $a): ?><option value="<?= e($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label class="form-label" for="actor_type">Actor</label><select class="form-control sm" id="actor_type" name="actor_type"><option value="">All</option><?php foreach (['admin', 'bidder', 'system', 'guest'] as $t): ?><option value="<?= $t ?>" <?= $actorType === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label class="form-label" for="entity">Record type</label><select class="form-control sm" id="entity" name="entity"><option value="">All</option><?php foreach ($entities as $en): ?><option value="<?= e($en) ?>" <?= $entity === $en ? 'selected' : '' ?>><?= e($en) ?></option><?php endforeach; ?></select></div>
    <div class="form-group" style="min-width:90px"><label class="form-label" for="entity_id">Record ID</label><input class="form-control sm" id="entity_id" name="entity_id" value="<?= $entityId ?: '' ?>" inputmode="numeric"></div>
    <div class="form-group"><label class="form-label" for="from">From</label><input class="form-control sm" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div class="form-group"><label class="form-label" for="to">To</label><input class="form-control sm" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <button class="btn btn-sm btn-primary" type="submit">Search</button>
    <a class="btn btn-sm btn-outline" href="<?= e(url('admin/reports.php?export=audit&from=' . rawurlencode($from) . '&to=' . rawurlencode($to))) ?>">Export CSV</a>
  </form>
</div>
<p class="muted small"><?= $total ?> record(s)</p>
<div class="table-wrap"><table class="table">
  <thead><tr><th>#</th><th>Date / time</th><th>Account</th><th>Action</th><th>Record</th><th>Previous value</th><th>New value</th><th>IP</th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?>
    <tr><td><?= (int) $r['id'] ?></td><td class="nowrap"><small><?= e(fmt_dt_precise($r['created_at'])) ?></small></td>
      <td><small><?= e(ucfirst($r['actor_type'])) ?><?= $r['actor_id'] ? ' #' . (int) $r['actor_id'] : '' ?><br><?= e($r['actor_name']) ?></small></td>
      <td><strong><?= e($r['action']) ?></strong></td><td><small><?= e($r['entity_type']) ?><?= $r['entity_id'] ? ' #' . (int) $r['entity_id'] : '' ?></small></td>
      <td><?= $pretty($r['old_value']) ?></td><td><?= $pretty($r['new_value']) ?></td><td><small><?= e($r['ip_address']) ?></small></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="muted">No records.</td></tr><?php endif; ?></tbody>
</table></div>
<?= View::pager($pg) ?>
<?php View::adminFooter();
