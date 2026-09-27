<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$admin = Auth::requireAdmin();
if (is_post()) {
    Csrf::verify();
    DB::run("UPDATE notifications SET is_read = 1 WHERE recipient_type = 'admin' AND recipient_id = ?", [$admin['id']]);
    redirect('admin/notifications.php');
}
$total = (int) DB::val("SELECT COUNT(*) FROM notifications WHERE recipient_type = 'admin' AND recipient_id = ?", [$admin['id']]);
$pg = paginate($total, 30, query_int('page', 1));
$rows = DB::all("SELECT * FROM notifications WHERE recipient_type = 'admin' AND recipient_id = ? ORDER BY id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", [$admin['id']]);
View::adminHeader('Notifications');
?>
<div class="page-actions"><span class="muted"><?= $total ?> notification(s)</span><form method="post"><?= Csrf::field() ?><button class="btn btn-sm btn-outline">Mark all as read</button></form></div>
<div class="card">
  <?php foreach ($rows as $n): ?><div class="notif<?= (int) $n['is_read'] ? '' : ' unread' ?>"><div class="t"><?= e($n['title']) ?></div><div class="d"><?= e(fmt_dt($n['created_at'])) ?></div><div class="small"><?= nl2br(e($n['body'])) ?></div><?php if ($n['link']): ?><a class="small" href="<?= e(url($n['link'])) ?>">Open &raquo;</a><?php endif; ?></div><?php endforeach; ?>
  <?php if (!$rows): ?><p class="muted">No notifications.</p><?php endif; ?>
  <?= View::pager($pg) ?>
</div>
<?php View::adminFooter();
