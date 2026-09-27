<?php
/** Database & file backups (Super Admin). Restore is done via phpMyAdmin/CLI — see docs/BACKUP_RESTORE.md. */
declare(strict_types=1);
require __DIR__ . '/_init.php';

$admin = Auth::requireAdmin('backup.manage');
if (is_post()) {
    Csrf::verify();
    if (input('action') === 'create') {
        @set_time_limit(600);
        $files = Backup::create(!empty($_POST['include_files']));
        Audit::log('backup_created', 'backup', null, null, ['files' => array_map('basename', $files)]);
        flash('success', 'Backup created: ' . implode(', ', array_map('basename', $files)));
    }
    redirect('admin/backup.php');
}
if ($dl = query('download')) {
    $name = basename($dl);
    $path = storage_path('backups/' . $name);
    if (!preg_match('/^(db|files)-\d{8}-\d{6}\.(sql|sql\.gz|zip)$/', $name) || !is_file($path)) {
        abort(404);
    }
    Audit::log('backup_downloaded', 'backup', null, null, ['file' => $name]);
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    readfile($path);
    exit;
}
$list = Backup::list();
View::adminHeader('Backup & Restore');
?>
<div class="card"><h2>Create backup</h2>
  <p>Creates a full SQL dump of the database (including bids, audit logs, rankings and consents) and, optionally, a ZIP of all uploaded images and documents. Automatic daily backups also run via <code>cron.php</code>.</p>
  <form method="post" class="inline-actions"><?= Csrf::field() ?><input type="hidden" name="action" value="create">
    <label class="check mb-0"><input type="checkbox" name="include_files" value="1" checked> Include images &amp; documents</label>
    <button class="btn btn-primary" type="submit">Create backup now</button></form>
  <p class="small muted mt-1">Backups are stored in <code><?= e(storage_path('backups')) ?></code> (not web-accessible). Download and keep copies off-site. Backups contain personal data — store them securely.</p>
</div>
<div class="card"><h2>Available backups</h2>
  <div class="table-wrap"><table class="table"><thead><tr><th>File</th><th>Created</th><th class="num">Size</th><th></th></tr></thead><tbody>
  <?php foreach ($list as $b): ?><tr><td><?= e($b['name']) ?></td><td><?= e(date('M j, Y g:i A', $b['time'])) ?></td><td class="num"><?= e(number_format($b['size'] / 1048576, 2)) ?> MB</td><td><a class="btn btn-sm btn-outline" href="<?= e(url('admin/backup.php?download=' . rawurlencode($b['name']))) ?>">Download</a></td></tr><?php endforeach; ?>
  <?php if (!$list): ?><tr><td colspan="4" class="muted">No backups yet.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="card"><h2>Restore procedure</h2>
  <ol><li>Put the site in maintenance (e.g. rename <code>index.php</code> temporarily or restrict access in cPanel).</li>
    <li>In cPanel → phpMyAdmin, select the database, <strong>Import</strong> the <code>db-*.sql.gz</code> file (or <code>gunzip &lt; db-….sql.gz | mysql -u USER -p DBNAME</code> via SSH).</li>
    <li>Extract <code>files-*.zip</code> into the storage folder so that <code>storage/uploads/…</code> is restored.</li>
    <li>Sign in as Super Admin, open <strong>Audit Trail → Verify integrity</strong> to confirm the restored audit chain.</li></ol>
  <p class="small muted">Full details: <code>docs/BACKUP_RESTORE.md</code>.</p></div>
<?php View::adminFooter();
