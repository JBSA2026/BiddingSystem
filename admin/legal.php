<?php
/** Terms & Conditions, Privacy Notice and GPS consent — versioned, never overwritten. */
declare(strict_types=1);
require __DIR__ . '/_init.php';

$admin = Auth::requireAdmin('settings.manage');
$types = ['terms' => 'Terms and Conditions', 'privacy' => 'Privacy Notice', 'gps_consent' => 'GPS / Location Consent'];
$type = array_key_exists(query('type'), $types) ? query('type') : 'terms';

if (is_post()) {
    Csrf::verify();
    $type = array_key_exists(input('type'), $types) ? input('type') : 'terms';
    $version = preg_replace('/[^0-9A-Za-z.\-]/', '', input('version')) ?? '';
    $title = mb_substr(input('title'), 0, 190);
    $content = (string) ($_POST['content'] ?? '');
    if ($version === '' || $title === '' || trim($content) === '') {
        flash('error', 'Version, title and content are required.');
    } elseif (DB::val('SELECT 1 FROM legal_documents WHERE doc_type = ? AND version = ?', [$type, $version])) {
        flash('error', 'That version number already exists. Versions are permanent — use a new number.');
    } else {
        Legal::publish($type, $version, $title, safe_html($content), (int) $admin['id']);
        flash('success', $types[$type] . ' version ' . $version . ' published. ' . ($type !== 'gps_consent' ? 'Bidders will be asked to accept it before their next bid.' : ''));
    }
    redirect('admin/legal.php?type=' . $type);
}
$current = Legal::current($type);
$history = DB::all('SELECT l.*, a.name AS by_name, (SELECT COUNT(*) FROM consents c WHERE c.legal_document_id = l.id) AS acceptances FROM legal_documents l LEFT JOIN admins a ON a.id = l.published_by WHERE l.doc_type = ? ORDER BY l.id DESC', [$type]);
$suggest = $current ? (preg_match('/^(\d+)\.(\d+)$/', $current['version'], $m) ? $m[1] . '.' . ((int) $m[2] + 1) : $current['version'] . '-1') : '1.0';
View::adminHeader('Terms & Privacy');
?>
<div class="tabs"><?php foreach ($types as $k => $label): ?><a href="<?= e(url('admin/legal.php?type=' . $k)) ?>" class="<?= $type === $k ? 'active' : '' ?>"><?= e($label) ?></a><?php endforeach; ?></div>
<div class="card"><h2>Publish new version of <?= e($types[$type]) ?></h2>
  <p class="small muted">Publishing creates a new version; previous versions are kept permanently with their acceptance records. Allowed HTML: p, br, strong, em, u, ul, ol, li, h3–h5, table, blockquote.</p>
  <form method="post"><?= Csrf::field() ?><input type="hidden" name="type" value="<?= e($type) ?>">
    <div class="form-row"><div class="form-group"><label class="form-label" for="version">New version</label><input class="form-control" id="version" name="version" value="<?= e($suggest) ?>" required maxlength="20"></div>
      <div class="form-group"><label class="form-label" for="title">Title</label><input class="form-control" id="title" name="title" value="<?= e($current['title'] ?? $types[$type]) ?>" required></div></div>
    <div class="form-group"><label class="form-label" for="content">Content</label><textarea class="form-control" id="content" name="content" rows="18" required><?= e($current['content'] ?? '') ?></textarea></div>
    <button class="btn btn-primary" type="submit">Publish new version</button></form>
</div>
<div class="card"><h2>Version history</h2>
  <div class="table-wrap"><table class="table"><thead><tr><th>Version</th><th>Title</th><th>Published</th><th>By</th><th class="num">Acceptances</th><th>Current</th></tr></thead><tbody>
  <?php foreach ($history as $h): ?><tr><td><?= e($h['version']) ?></td><td><details><summary><?= e($h['title']) ?></summary><div class="prose small mt-1"><?= safe_html($h['content']) ?></div></details></td><td><?= e(fmt_dt($h['created_at'])) ?></td><td><?= e($h['by_name'] ?? 'Installer') ?></td><td class="num"><?= (int) $h['acceptances'] ?></td><td><?= (int) $h['is_current'] ? '<span class="badge badge-green">Current</span>' : '' ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
<?php View::adminFooter();
