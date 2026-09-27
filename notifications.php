<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$user = Auth::requireBidder();
if (is_post()) {
    Csrf::verify();
    DB::run("UPDATE notifications SET is_read = 1 WHERE recipient_type = 'bidder' AND recipient_id = ?", [$user['id']]);
    redirect('notifications.php');
}
$total = (int) DB::val("SELECT COUNT(*) FROM notifications WHERE recipient_type = 'bidder' AND recipient_id = ?", [$user['id']]);
$pg = paginate($total, 25, query_int('page', 1));
$rows = DB::all("SELECT * FROM notifications WHERE recipient_type = 'bidder' AND recipient_id = ? ORDER BY id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", [$user['id']]);
View::header('Notifications', ['active' => 'notifications']);
?>
<section class="container narrow section">
  <div class="page-actions"><h1 class="mb-0">Notifications</h1>
    <form method="post"><?= Csrf::field() ?><button class="btn btn-outline btn-sm" type="submit">Mark all as read</button></form></div>
  <div class="card">
    <?php if (!$rows): ?><p class="muted">No notifications yet.</p><?php endif; ?>
    <?php foreach ($rows as $n): ?>
      <div class="notif<?= (int) $n['is_read'] ? '' : ' unread' ?>">
        <div class="t"><?= e($n['title']) ?></div>
        <div class="d"><?= e(fmt_dt($n['created_at'])) ?></div>
        <div class="small mt-1"><?= nl2br(e($n['body'])) ?></div>
        <?php if ($n['link']): ?><a class="small" href="<?= e(url($n['link'])) ?>">Open &raquo;</a><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?= View::pager($pg) ?>
  </div>
</section>
<?php View::footer();
