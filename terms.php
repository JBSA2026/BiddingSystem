<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$doc = Legal::current('terms');
$versions = DB::all("SELECT id, version, title, created_at, is_current FROM legal_documents WHERE doc_type = 'terms' ORDER BY id DESC");
$show = query_int('v') ? DB::one("SELECT * FROM legal_documents WHERE doc_type = 'terms' AND id = ?", [query_int('v')]) : $doc;
View::header('Terms and Conditions');
?>
<section class="container medium section">
  <div class="card">
    <h1><?= e($show['title'] ?? 'Terms and Conditions') ?></h1>
    <p class="muted">Version <?= e($show['version'] ?? '') ?> · effective <?= e(fmt_dt($show['created_at'] ?? null, 'F j, Y')) ?><?= ($show && !(int) $show['is_current']) ? ' · <strong>superseded</strong>' : '' ?></p>
    <div class="prose"><?= safe_html($show['content'] ?? '') ?></div>
    <p class="small muted mt-2">Each property may have additional property-specific terms, shown on its bidding page. The version you accept is recorded with every bid.</p>
  </div>
  <?php if (count($versions) > 1): ?>
    <div class="card"><h2>Version history</h2><ul><?php foreach ($versions as $v): ?><li><a href="<?= e(url('terms.php?v=' . $v['id'])) ?>">Version <?= e($v['version']) ?></a> — <?= e(fmt_dt($v['created_at'], 'M j, Y')) ?><?= (int) $v['is_current'] ? ' (current)' : '' ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
</section>
<?php View::footer();
