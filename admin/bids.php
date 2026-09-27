<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$admin = Auth::requireAdmin('bids.view');
$q = query('q');
$type = query('type');
$pid = query_int('property');
$sort = query('sort', 'newest');
$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(b.bid_ref LIKE ? OR p.ref_no LIKE ? OR u.full_name LIKE ? OR u.bidder_no LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if (in_array($type, ['initial', 'revision', 'withdrawal'], true)) {
    $where[] = 'b.bid_type = ?';
    $params[] = $type;
}
if ($pid) {
    $where[] = 'b.property_id = ?';
    $params[] = $pid;
}
if (query('gps') !== '' && in_array(query('gps'), ['granted', 'denied', 'unavailable', 'not_requested'], true)) {
    $where[] = 'b.gps_status = ?';
    $params[] = query('gps');
}
$order = match ($sort) {
    'amount_desc' => 'b.amount DESC, b.submitted_at ASC',
    'amount_asc' => 'b.amount ASC',
    'oldest' => 'b.id ASC',
    default => 'b.id DESC',
};
$w = implode(' AND ', $where);
$total = (int) DB::val("SELECT COUNT(*) FROM bids b JOIN properties p ON p.id = b.property_id JOIN users u ON u.id = b.user_id WHERE {$w}", $params);
$pg = paginate($total, 50, query_int('page', 1));
$rows = DB::all("SELECT b.*, p.ref_no, p.name AS pname, u.full_name, u.bidder_no, u.verification_status FROM bids b JOIN properties p ON p.id = b.property_id JOIN users u ON u.id = b.user_id WHERE {$w} ORDER BY {$order} LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);
$props = DB::all('SELECT id, ref_no FROM properties ORDER BY id DESC LIMIT 300');

View::adminHeader('All Bids');
?>
<form class="admin-filters" method="get">
  <div class="form-group"><label class="form-label" for="q">Search</label><input class="form-control sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Bid ref, property ref, bidder"></div>
  <div class="form-group"><label class="form-label" for="property">Property</label><select class="form-control sm" id="property" name="property"><option value="">All</option><?php foreach ($props as $pp): ?><option value="<?= (int) $pp['id'] ?>" <?= $pid === (int) $pp['id'] ? 'selected' : '' ?>><?= e($pp['ref_no']) ?></option><?php endforeach; ?></select></div>
  <div class="form-group"><label class="form-label" for="type">Type</label><select class="form-control sm" id="type" name="type"><option value="">All</option><?php foreach (['initial', 'revision', 'withdrawal'] as $t): ?><option value="<?= $t ?>" <?= $type === $t ? 'selected' : '' ?>><?= e(status_label($t)) ?></option><?php endforeach; ?></select></div>
  <div class="form-group"><label class="form-label" for="gps">GPS</label><select class="form-control sm" id="gps" name="gps"><option value="">All</option><?php foreach (['granted', 'denied', 'unavailable', 'not_requested'] as $t): ?><option value="<?= $t ?>" <?= query('gps') === $t ? 'selected' : '' ?>><?= e(status_label($t)) ?></option><?php endforeach; ?></select></div>
  <div class="form-group"><label class="form-label" for="sort">Sort</label><select class="form-control sm" id="sort" name="sort"><option value="newest">Newest first</option><option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option><option value="amount_desc" <?= $sort === 'amount_desc' ? 'selected' : '' ?>>Highest to lowest</option><option value="amount_asc" <?= $sort === 'amount_asc' ? 'selected' : '' ?>>Lowest to highest</option></select></div>
  <button class="btn btn-sm btn-primary" type="submit">Filter</button>
  <?php if (can('reports.export')): ?><a class="btn btn-sm btn-outline" href="<?= e(url('admin/reports.php?export=bids' . ($pid ? '&property_id=' . $pid : ''))) ?>">Export CSV</a><?php endif; ?>
</form>
<p class="muted small"><?= $total ?> bid record(s). Records are immutable.</p>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Time (server)</th><th>Bid ref</th><th>Property</th><th>Bidder</th><th>Type</th><th class="num">Amount</th><th>GPS</th><th>IP</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $b): ?>
    <tr><td class="nowrap"><small><?= e(fmt_dt_precise($b['submitted_at'])) ?></small></td><td class="nowrap"><small><?= e($b['bid_ref']) ?></small></td>
      <td><a href="<?= e(url('admin/property_bids.php?id=' . $b['property_id'])) ?>"><?= e($b['ref_no']) ?></a><br><small class="muted"><?= e($b['pname']) ?></small></td>
      <td><a href="<?= e(url('admin/bidder_view.php?id=' . $b['user_id'])) ?>"><?= e($b['full_name']) ?></a><br><small class="muted"><?= e($b['bidder_no']) ?></small> <?= status_badge($b['verification_status']) ?></td>
      <td><?= e(status_label($b['bid_type'])) ?></td><td class="num"><?= e(money($b['amount'])) ?></td>
      <td><small><?= $b['gps_status'] === 'granted' ? e($b['gps_lat'] . ', ' . $b['gps_lng']) . '<br>±' . e((string) $b['gps_accuracy']) . 'm' : e($b['gps_note'] ?: status_label($b['gps_status'])) ?></small></td>
      <td><small><?= e($b['ip_address']) ?></small></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="muted">No bids found.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?= View::pager($pg) ?>
<?php View::adminFooter();
