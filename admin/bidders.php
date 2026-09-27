<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$admin = Auth::requireAdmin('bidders.view');
$q = query('q');
$status = query('status');
$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(u.full_name LIKE ? OR u.company_name LIKE ? OR u.email LIKE ? OR u.mobile LIKE ? OR u.bidder_no LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
    $where[] = 'u.verification_status = ?';
    $params[] = $status;
}
if (query('flagged') === '1') {
    $where[] = 'EXISTS (SELECT 1 FROM bidder_flags f WHERE f.user_id = u.id AND f.resolved_at IS NULL)';
}
if (query('blacklisted') === '1') {
    $where[] = 'u.is_blacklisted = 1';
}
if (query('docs') === 'pending') {
    $where[] = "EXISTS (SELECT 1 FROM bidder_documents d WHERE d.user_id = u.id AND d.status = 'pending' AND d.id = (SELECT MAX(x.id) FROM bidder_documents x WHERE x.user_id = d.user_id AND x.requirement_type_id = d.requirement_type_id))";
}
if (query('unverified') === '1') {
    $where[] = 'u.email_verified_at IS NULL';
}
$w = implode(' AND ', $where);
$total = (int) DB::val("SELECT COUNT(*) FROM users u WHERE {$w}", $params);
$pg = paginate($total, 30, query_int('page', 1));
$rows = DB::all("SELECT u.*, (SELECT COUNT(*) FROM bids b WHERE b.user_id = u.id) AS bids_n,
    (SELECT COUNT(*) FROM bidder_flags f WHERE f.user_id = u.id AND f.resolved_at IS NULL) AS flags_n
    FROM users u WHERE {$w} ORDER BY u.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);

View::adminHeader('Bidders');
?>
<form class="admin-filters" method="get">
  <div class="form-group"><label class="form-label" for="q">Search</label><input class="form-control sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Name, company, email, mobile, bidder no."></div>
  <div class="form-group"><label class="form-label" for="status">Qualification</label><select class="form-control sm" id="status" name="status"><option value="">All</option><?php foreach (['pending', 'approved', 'rejected'] as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?></select></div>
  <label class="check mb-0"><input type="checkbox" name="docs" value="pending" <?= query('docs') === 'pending' ? 'checked' : '' ?>> Docs to review</label>
  <label class="check mb-0"><input type="checkbox" name="flagged" value="1" <?= query('flagged') === '1' ? 'checked' : '' ?>> Flagged / watchlist</label>
  <label class="check mb-0"><input type="checkbox" name="blacklisted" value="1" <?= query('blacklisted') === '1' ? 'checked' : '' ?>> Blacklisted</label>
  <label class="check mb-0"><input type="checkbox" name="unverified" value="1" <?= query('unverified') === '1' ? 'checked' : '' ?>> Email unverified</label>
  <button class="btn btn-sm btn-primary" type="submit">Filter</button>
  <?php if (can('reports.export')): ?><a class="btn btn-sm btn-outline" href="<?= e(url('admin/reports.php?export=bidders')) ?>">Export CSV</a><?php endif; ?>
</form>
<p class="muted small"><?= $total ?> bidder(s)</p>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Bidder</th><th>Contact</th><th>Email / mobile verified</th><th>Qualification</th><th class="num">Bids</th><th>Flags</th><th>Registered</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $u): ?>
    <tr>
      <td><strong><?= e($u['full_name']) ?></strong><?= $u['company_name'] ? '<br><small>' . e($u['company_name']) . '</small>' : '' ?><br><small class="muted"><?= e($u['bidder_no']) ?></small></td>
      <td><small><?= e($u['email']) ?><br><?= e($u['mobile']) ?><br><?= e($u['city']) ?>, <?= e($u['province']) ?></small></td>
      <td><?= $u['email_verified_at'] ? status_badge('verified') : status_badge('pending') ?> <?= $u['mobile_verified_at'] ? status_badge('verified') : '<span class="badge badge-grey">Mobile —</span>' ?></td>
      <td><?= status_badge($u['verification_status']) ?></td>
      <td class="num"><?= (int) $u['bids_n'] ?></td>
      <td><?= (int) $u['flags_n'] ? '<span class="badge badge-amber">' . (int) $u['flags_n'] . ' open</span>' : '' ?><?= (int) $u['is_blacklisted'] ? ' <span class="badge badge-red">Blacklisted</span>' : '' ?></td>
      <td class="nowrap"><small><?= e(fmt_dt($u['created_at'])) ?></small></td>
      <td><a class="btn btn-sm btn-primary" href="<?= e(url('admin/bidder_view.php?id=' . $u['id'])) ?>">Review</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="muted">No bidders found.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?= View::pager($pg) ?>
<?php View::adminFooter();
