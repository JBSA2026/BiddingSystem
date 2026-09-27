<?php
/** Printable QR code linking directly to a property's bidding page. */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$p = Bidding::propertyByRef(query('ref'));
if (!$p || (!(int) $p['is_published'] && !Auth::admin())) {
    abort(404);
}
$link = url('property.php?ref=' . rawurlencode($p['ref_no']));
View::header('QR Code — ' . $p['ref_no']);
?>
<section class="container narrow section text-center">
  <div class="card">
    <img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="56" height="56">
    <h1 class="h2 mt-1"><?= e($p['name']) ?></h1>
    <p class="muted">Reference No. <strong><?= e($p['ref_no']) ?></strong> · <?= e($p['type_name']) ?> · <?= e($p['location']) ?></p>
    <div class="qr-box" data-qr="<?= e($link) ?>" data-size="300"></div>
    <p class="mt-2"><strong>Scan to view and bid online</strong><br><small class="muted"><?= e($link) ?></small></p>
    <p>Minimum bid: <strong><?= e(money($p['starting_price'])) ?></strong> · Bidding closes <?= e(fmt_dt($p['closing_at'])) ?></p>
    <button class="btn btn-primary" type="button" data-print>Print</button>
  </div>
</section>
<script src="<?= e(asset('js/vendor/qrcode.js')) ?>"></script>
<?php View::footer();
