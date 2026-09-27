<?php
/**
 * TEST ENVIRONMENT ONLY — shows every email the system generated (verification codes,
 * bid confirmations, award notices…) so testers don't need real inboxes.
 * Returns 404 unless app.env = 'testing'.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

if (!is_testing()) {
    abort(404);
}
$id = query_int('id');
if ($id && query('html') === '1') {
    $m = DB::one('SELECT body_html FROM email_queue WHERE id = ?', [$id]);
    if (!$m) {
        abort(404);
    }
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox allow-popups allow-popups-to-escape-sandbox");
    header('Content-Type: text/html; charset=utf-8');
    echo $m['body_html'];
    exit;
}
$to = query('to');
$params = [];
$where = '1=1';
if ($to !== '') {
    $where = 'to_email LIKE ?';
    $params[] = '%' . $to . '%';
}
$rows = DB::all("SELECT * FROM email_queue WHERE {$where} ORDER BY id DESC LIMIT 100", $params);
View::header('Test mailbox');
?>
<section class="container section">
  <div class="page-actions"><div><h1 class="mb-0">Test mailbox</h1><span class="muted">Every email the system sends appears here (newest first). Only available in the TEST environment.</span></div>
    <form method="get" class="inline-actions"><input class="form-control sm" name="to" value="<?= e($to) ?>" placeholder="Filter by recipient e.g. bidder1"><button class="btn btn-sm btn-primary">Filter</button><a class="btn btn-sm btn-outline" href="<?= e(url('dev/mailbox.php')) ?>">Refresh</a></form></div>
  <?php if (!$rows): ?><div class="card empty">No emails yet.</div><?php endif; ?>
  <?php foreach ($rows as $m): preg_match('/\b(\d{6})\b/', (string) $m['body_text'], $code); ?>
    <details class="collapsible" <?= $m === $rows[0] ? 'open' : '' ?>>
      <summary><?= e($m['subject']) ?> <small class="muted">→ <?= e($m['to_email']) ?> · <?= e(fmt_dt($m['created_at'], 'M j g:i:s A')) ?></small>
        <?php if ($code && str_contains((string) $m['subject'], 'Verify')): ?> <span class="badge badge-gold">Code <?= e($code[1]) ?></span><?php endif; ?></summary>
      <div class="mt-1 small"><strong>Delivery:</strong> <?= e($m['status']) ?><?= $m['last_error'] ? ' — ' . e($m['last_error']) : '' ?> · <a href="<?= e(url('dev/mailbox.php?id=' . $m['id'] . '&html=1')) ?>" target="_blank" rel="noopener">View as HTML email</a></div>
      <pre class="json mt-1" style="max-width:none;max-height:none"><?= e($m['body_text']) ?></pre>
    </details>
  <?php endforeach; ?>
</section>
<?php View::footer();
