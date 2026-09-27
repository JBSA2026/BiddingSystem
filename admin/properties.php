<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$admin = Auth::requireAdmin('properties.view');

if (is_post()) {
    Csrf::verify();
    Auth::requireAdmin('properties.manage');
    $id = input_int('id');
    $p = Bidding::property($id);
    if (!$p) {
        abort(404);
    }
    $act = input('action');
    $map = [
        'publish' => ['is_published', 1, 'property_published'],
        'unpublish' => ['is_published', 0, 'property_unpublished'],
        'archive' => ['is_archived', 1, 'property_archived'],
        'unarchive' => ['is_archived', 0, 'property_unarchived'],
    ];
    if (isset($map[$act])) {
        [$col, $val, $auditAction] = $map[$act];
        if ($act === 'unpublish' && $p['status'] === 'open' && DB::val('SELECT 1 FROM bids WHERE property_id = ? LIMIT 1', [$id])) {
            flash('error', 'A property with bids cannot be unpublished while bidding is open. Close or cancel the bidding instead.');
        } elseif ($act === 'archive' && in_array($p['status'], ['open', 'under_evaluation'], true)) {
            flash('error', 'Open or under-evaluation properties cannot be archived.');
        } else {
            DB::update('properties', [$col => $val, 'updated_by' => $admin['id'], 'updated_at' => now()], 'id = ?', [$id]);
            Audit::log($auditAction, 'property', $id, [$col => $p[$col]], [$col => $val]);
            flash('success', 'Property ' . $p['ref_no'] . ' updated.');
        }
    }
    redirect('admin/properties.php' . (input('return') ? '?' . input('return') : ''));
}

$status = query('status');
$q = query('q');
$showArchived = query('archived') === '1';
$where = [$showArchived ? 'p.is_archived = 1' : 'p.is_archived = 0'];
$params = [];
if (in_array($status, ['upcoming', 'open', 'closed', 'under_evaluation', 'awarded', 'cancelled'], true)) {
    $where[] = 'p.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[] = '(p.ref_no LIKE ? OR p.name LIKE ? OR p.location LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
$w = implode(' AND ', $where);
$total = (int) DB::val("SELECT COUNT(*) FROM properties p WHERE {$w}", $params);
$pg = paginate($total, 25, query_int('page', 1));
$rows = DB::all("SELECT p.*, t.name AS type_name,
    (SELECT COUNT(DISTINCT user_id) FROM bids b WHERE b.property_id = p.id) AS bidders_n,
    (SELECT COUNT(*) FROM bids b WHERE b.property_id = p.id) AS bids_n
    FROM properties p JOIN property_types t ON t.id = p.property_type_id WHERE {$w}
    ORDER BY FIELD(p.status,'open','upcoming','under_evaluation','closed','awarded','cancelled'), p.closing_at DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);
$ret = http_build_query(array_filter(['status' => $status, 'q' => $q, 'archived' => $showArchived ? '1' : '']));

View::adminHeader('Properties');
?>
<div class="page-actions">
  <form class="admin-filters" method="get">
    <div class="form-group"><label class="form-label" for="q">Search</label><input class="form-control sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Ref no., name, location"></div>
    <div class="form-group"><label class="form-label" for="status">Status</label><select class="form-control sm" id="status" name="status"><option value="">All</option><?php foreach (['upcoming', 'open', 'closed', 'under_evaluation', 'awarded', 'cancelled'] as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?></select></div>
    <label class="check mb-0"><input type="checkbox" name="archived" value="1" <?= $showArchived ? 'checked' : '' ?>> Archived</label>
    <button class="btn btn-sm btn-primary" type="submit">Filter</button>
  </form>
  <?php if (can('properties.manage')): ?><a class="btn btn-gold" href="<?= e(url('admin/property_edit.php')) ?>">+ Add property</a><?php endif; ?>
</div>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Ref no.</th><th>Property</th><th>Type</th><th class="num">Starting price</th><th>Schedule</th><th>Status</th><th>Published</th><th class="num">Bidders / bids</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $p): ?>
    <tr>
      <td class="nowrap"><strong><?= e($p['ref_no']) ?></strong></td>
      <td><?= e($p['name']) ?><br><small class="muted"><?= e($p['location']) ?></small></td>
      <td><?= e($p['type_name']) ?></td>
      <td class="num"><?= e(money($p['starting_price'])) ?></td>
      <td class="nowrap"><small>Opens <?= e(fmt_dt($p['opening_at'])) ?><br>Closes <?= e(fmt_dt($p['closing_at'])) ?></small></td>
      <td><?= status_badge($p['status']) ?></td>
      <td><?= (int) $p['is_published'] ? '<span class="badge badge-green">Yes</span>' : '<span class="badge badge-grey">No</span>' ?></td>
      <td class="num"><?= (int) $p['bidders_n'] ?> / <?= (int) $p['bids_n'] ?></td>
      <td>
        <div class="inline-actions">
          <a class="btn btn-sm btn-primary" href="<?= e(url('admin/property_bids.php?id=' . $p['id'])) ?>">Bids &amp; evaluation</a>
          <?php if (can('properties.manage')): ?>
            <a class="btn btn-sm btn-outline" href="<?= e(url('admin/property_edit.php?id=' . $p['id'])) ?>">Edit</a>
            <form method="post"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="return" value="<?= e($ret) ?>">
              <?php if (!(int) $p['is_archived']): ?>
                <button class="btn btn-sm btn-outline" name="action" value="<?= (int) $p['is_published'] ? 'unpublish' : 'publish' ?>"><?= (int) $p['is_published'] ? 'Unpublish' : 'Publish' ?></button>
                <button class="btn btn-sm btn-link" name="action" value="archive">Archive</button>
              <?php else: ?>
                <button class="btn btn-sm btn-outline" name="action" value="unarchive">Restore</button>
              <?php endif; ?>
            </form>
          <?php endif; ?>
          <a class="btn btn-sm btn-link" href="<?= e(url('property.php?ref=' . rawurlencode($p['ref_no']))) ?>" target="_blank" rel="noopener">View</a>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="muted">No properties found.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?= View::pager($pg) ?>
<?php View::adminFooter();
