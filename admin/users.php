<?php
/** Administrator accounts and role assignment (Super Admin only). */
declare(strict_types=1);
require __DIR__ . '/_init.php';

$admin = Auth::requireAdmin('users.manage');
if (is_post()) {
    Csrf::verify();
    $action = input('action');
    try {
        if ($action === 'create') {
            $email = mb_strtolower(input('email'));
            $role = input('role');
            $name = mb_substr(input('name'), 0, 150);
            $pw = (string) ($_POST['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || !isset(Rbac::ROLES[$role])) {
                throw new RuntimeException('Name, valid email and role are required.');
            }
            if ($err = Security::passwordError($pw)) {
                throw new RuntimeException($err);
            }
            if (DB::val('SELECT 1 FROM admins WHERE email = ?', [$email])) {
                throw new RuntimeException('An administrator with this email already exists.');
            }
            $id = DB::insert('admins', ['name' => $name, 'email' => $email, 'password_hash' => Security::hashPassword($pw), 'role' => $role,
                'must_change_password' => 1, 'created_by' => $admin['id'], 'created_at' => now()]);
            Audit::log('admin_created', 'admin', $id, null, ['name' => $name, 'email' => $email, 'role' => $role]);
            flash('success', 'Administrator created. They must change the temporary password at first sign-in.');
        } elseif ($action === 'update') {
            $id = input_int('admin_id');
            $t = DB::one('SELECT * FROM admins WHERE id = ?', [$id]);
            if (!$t) {
                throw new RuntimeException('Not found.');
            }
            $role = input('role');
            $active = empty($_POST['is_active']) ? 0 : 1;
            if ((int) $t['id'] === (int) $admin['id'] && ($role !== 'super_admin' || !$active)) {
                throw new RuntimeException('You cannot remove your own Super Admin role or deactivate yourself.');
            }
            if (!isset(Rbac::ROLES[$role])) {
                throw new RuntimeException('Invalid role.');
            }
            $new = ['role' => $role, 'is_active' => $active, 'updated_at' => now()];
            if (!empty($_POST['reset_2fa'])) {
                $new += ['totp_enabled' => 0, 'totp_secret' => null];
            }
            DB::update('admins', $new, 'id = ?', [$id]);
            Audit::log('admin_updated', 'admin', $id, ['role' => $t['role'], 'is_active' => $t['is_active'], 'totp_enabled' => $t['totp_enabled']], $new);
            flash('success', 'Administrator updated.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/users.php');
}
$rows = DB::all('SELECT * FROM admins ORDER BY is_active DESC, role, name');
View::adminHeader('Administrators');
?>
<div class="card"><h2>Roles</h2>
  <ul class="small"><li><strong>Super Admin</strong> — full system access, settings, administrators, backups.</li>
    <li><strong>Bidding Administrator</strong> — manage properties, bidders, bidding processes, evaluation and award recommendations.</li>
    <li><strong>Approving Officer</strong> — approve bidder qualifications, final awards and schedule changes (dual authorization).</li>
    <li><strong>Auditor / Viewer</strong> — read-only access to records, reports and audit logs.</li></ul></div>
<div class="table-wrap mb-3"><table class="table"><thead><tr><th>Name</th><th>Email</th><th>Role / status</th><th>2FA</th><th>Last sign-in</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
  <tr><td><?= e($r['name']) ?></td><td><?= e($r['email']) ?></td>
    <td><form method="post" class="inline-actions"><?= Csrf::field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="admin_id" value="<?= (int) $r['id'] ?>">
      <select class="form-control sm" name="role" style="width:auto"><?php foreach (Rbac::ROLES as $k => $label): ?><option value="<?= $k ?>" <?= $r['role'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
      <label class="check mb-0"><input type="checkbox" name="is_active" value="1" <?= (int) $r['is_active'] ? 'checked' : '' ?>> Active</label>
      <?php if ((int) $r['totp_enabled']): ?><label class="check mb-0"><input type="checkbox" name="reset_2fa" value="1"> Reset 2FA</label><?php endif; ?>
      <button class="btn btn-sm btn-primary">Save</button></form></td>
    <td><?= (int) $r['totp_enabled'] ? '<span class="badge badge-green">On</span>' : '<span class="badge badge-grey">Off</span>' ?></td>
    <td><small><?= e(fmt_dt($r['last_login_at'])) ?><br><?= e($r['last_login_ip']) ?></small></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<div class="card"><h2>Add administrator</h2>
  <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="create">
    <div class="form-row"><div class="form-group"><label class="form-label" for="n">Full name</label><input class="form-control" id="n" name="name" required></div><div class="form-group"><label class="form-label" for="em">Email</label><input class="form-control" type="email" id="em" name="email" required></div></div>
    <div class="form-row"><div class="form-group"><label class="form-label" for="ro">Role</label><select class="form-control" id="ro" name="role"><?php foreach (Rbac::ROLES as $k => $label): ?><option value="<?= $k ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label class="form-label" for="pw">Temporary password</label><input class="form-control" type="text" id="pw" name="password" required autocomplete="off" value="<?= e(substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 12) . 'a1A') ?>"><span class="form-help">Share securely; the user must change it at first sign-in.</span></div></div>
    <button class="btn btn-gold" type="submit">Create administrator</button></form></div>
<?php View::adminFooter();
