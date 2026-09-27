<?php
/** Configurable bidder requirements / qualification documents and property types. */
declare(strict_types=1);
require __DIR__ . '/_init.php';

$admin = Auth::requireAdmin('settings.manage');
if (is_post()) {
    Csrf::verify();
    $action = input('action');
    if ($action === 'save_req') {
        $rid = input_int('req_id');
        $d = ['name' => mb_substr(input('name'), 0, 150), 'description' => mb_substr(input('description'), 0, 500) ?: null,
            'scope' => input('scope') === 'property' ? 'property' : 'registration', 'is_required' => empty($_POST['is_required']) ? 0 : 1,
            'is_active' => empty($_POST['is_active']) ? 0 : 1, 'sort_order' => input_int('sort_order')];
        if ($d['name'] === '') {
            flash('error', 'Name is required.');
        } elseif ($rid) {
            $old = DB::one('SELECT * FROM requirement_types WHERE id = ?', [$rid]);
            DB::update('requirement_types', $d, 'id = ?', [$rid]);
            Audit::log('requirement_updated', 'requirement_type', $rid, $old, $d);
            flash('success', 'Requirement updated.');
        } else {
            $rid = DB::insert('requirement_types', $d + ['created_at' => now()]);
            Audit::log('requirement_created', 'requirement_type', $rid, null, $d);
            flash('success', 'Requirement added.');
        }
    } elseif ($action === 'save_type') {
        $tid = input_int('type_id');
        $d = ['name' => mb_substr(input('name'), 0, 100), 'is_active' => empty($_POST['is_active']) ? 0 : 1, 'sort_order' => input_int('sort_order')];
        if ($d['name'] !== '') {
            try {
                if ($tid) {
                    DB::update('property_types', $d, 'id = ?', [$tid]);
                } else {
                    $tid = DB::insert('property_types', $d);
                }
                Audit::log('property_type_saved', 'property_type', $tid, null, $d);
                flash('success', 'Property type saved.');
            } catch (PDOException) {
                flash('error', 'A property type with that name already exists.');
            }
        }
    }
    redirect('admin/requirements.php');
}
$reqs = DB::all('SELECT * FROM requirement_types ORDER BY scope DESC, sort_order, id');
$types = DB::all('SELECT * FROM property_types ORDER BY sort_order, name');
View::adminHeader('Requirements & Property Types');
?>
<div class="card"><h2>Bidder requirements / qualification documents</h2>
  <p class="small muted"><strong>Registration</strong> requirements apply to all bidders (collected at sign-up when active; "required" ones must be approved before bidding). <strong>Property</strong> requirements apply only to properties where they are ticked.</p>
  <div class="table-wrap"><table class="table"><thead><tr><th>Order</th><th>Name &amp; description</th><th>Scope</th><th>Required</th><th>Active</th><th></th></tr></thead><tbody>
  <?php foreach ($reqs as $r): ?>
    <tr><td colspan="6"><form method="post" class="inline-actions"><?= Csrf::field() ?><input type="hidden" name="action" value="save_req"><input type="hidden" name="req_id" value="<?= (int) $r['id'] ?>">
      <input class="form-control sm" type="number" name="sort_order" value="<?= (int) $r['sort_order'] ?>" style="width:64px">
      <input class="form-control sm" name="name" value="<?= e($r['name']) ?>" style="max-width:220px" required>
      <input class="form-control sm" name="description" value="<?= e($r['description']) ?>" style="max-width:360px">
      <select class="form-control sm" name="scope" style="width:auto"><option value="registration" <?= $r['scope'] === 'registration' ? 'selected' : '' ?>>Registration</option><option value="property" <?= $r['scope'] === 'property' ? 'selected' : '' ?>>Property</option></select>
      <label class="check mb-0"><input type="checkbox" name="is_required" value="1" <?= (int) $r['is_required'] ? 'checked' : '' ?>> Required</label>
      <label class="check mb-0"><input type="checkbox" name="is_active" value="1" <?= (int) $r['is_active'] ? 'checked' : '' ?>> Active</label>
      <button class="btn btn-sm btn-primary">Save</button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <h3 class="mt-2">Add requirement</h3>
  <form method="post" class="inline-actions"><?= Csrf::field() ?><input type="hidden" name="action" value="save_req">
    <input class="form-control sm" type="number" name="sort_order" value="10" style="width:64px"><input class="form-control sm" name="name" placeholder="Name" required style="max-width:220px">
    <input class="form-control sm" name="description" placeholder="Description / instructions" style="max-width:360px">
    <select class="form-control sm" name="scope" style="width:auto"><option value="registration">Registration</option><option value="property">Property</option></select>
    <label class="check mb-0"><input type="checkbox" name="is_required" value="1" checked> Required</label><label class="check mb-0"><input type="checkbox" name="is_active" value="1" checked> Active</label>
    <button class="btn btn-sm btn-gold">Add</button></form>
</div>
<div class="card"><h2>Property types</h2>
  <?php foreach ($types as $t): ?><form method="post" class="inline-actions mb-1"><?= Csrf::field() ?><input type="hidden" name="action" value="save_type"><input type="hidden" name="type_id" value="<?= (int) $t['id'] ?>">
    <input class="form-control sm" type="number" name="sort_order" value="<?= (int) $t['sort_order'] ?>" style="width:64px"><input class="form-control sm" name="name" value="<?= e($t['name']) ?>" style="max-width:240px"><label class="check mb-0"><input type="checkbox" name="is_active" value="1" <?= (int) $t['is_active'] ? 'checked' : '' ?>> Active</label><button class="btn btn-sm btn-primary">Save</button></form><?php endforeach; ?>
  <form method="post" class="inline-actions mt-2"><?= Csrf::field() ?><input type="hidden" name="action" value="save_type"><input class="form-control sm" type="number" name="sort_order" value="10" style="width:64px"><input class="form-control sm" name="name" placeholder="New property type" required style="max-width:240px"><input type="hidden" name="is_active" value="1"><button class="btn btn-sm btn-gold">Add type</button></form>
</div>
<?php View::adminFooter();
