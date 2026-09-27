<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$ref = query('ref');
$p = $ref !== '' ? Bidding::propertyByRef($ref) : (query_int('id') ? Bidding::property(query_int('id')) : null);
$isAdminPreview = (bool) Auth::admin();
if (!$p || ((!(int) $p['is_published'] || (int) $p['is_archived']) && !$isAdminPreview)) {
    abort(404, 'This property is not available.');
}
$pid = (int) $p['id'];
$images = DB::all('SELECT * FROM property_images WHERE property_id = ? ORDER BY sort_order, id', [$pid]);
$docs = DB::all('SELECT * FROM property_documents WHERE property_id = ? AND is_public = 1 ORDER BY id', [$pid]);
$reqs = DB::all('SELECT rt.* FROM property_requirements pr JOIN requirement_types rt ON rt.id = pr.requirement_type_id WHERE pr.property_id = ? AND rt.is_active = 1 ORDER BY rt.sort_order', [$pid]);
$user = Auth::bidder();
$elig = Eligibility::check($user, $p);
$rules = Bidding::rulesSummary($p);
$terms = Legal::current('terms');
$gpsDoc = Legal::current('gps_consent');
$shareUrl = url('property.php?ref=' . rawurlencode($p['ref_no']));

$myBids = [];
$myActive = null;
$position = null;
$participation = null;
if ($user) {
    $myBids = DB::all('SELECT * FROM bids WHERE property_id = ? AND user_id = ? ORDER BY id DESC', [$pid, $user['id']]);
    $myActive = ($myBids && $myBids[0]['bid_type'] !== 'withdrawal') ? $myBids[0] : null;
    if ($myActive && (int) $p['show_ranking']) {
        $position = Bidding::bidderPosition($pid, (int) $user['id']);
    }
    $participation = DB::one('SELECT * FROM property_bidders WHERE property_id = ? AND user_id = ?', [$pid, $user['id']]);
}
$highest = (int) $p['show_highest'] ? Bidding::highest($pid) : null;
$bidderCount = (int) $p['show_bidder_count'] ? count(Bidding::activeBids($pid)) : null;
$isOpen = $p['status'] === 'open' && Bidding::secondsRemaining($p) > 0;
$canRevise = $p['bid_mode'] === 'multiple' || !$myBids;
$suggest = $myActive && (int) $p['higher_only']
    ? (amount_cents($myActive['amount']) + max(amount_cents($p['min_increment']), 1)) / 100
    : (float) $p['starting_price'];
if ($highest && (int) $p['show_highest'] && (float) $p['min_increment'] > 0 && (!$myActive || (int) $highest['user_id'] !== (int) $user['id'])) {
    $suggest = max($suggest, (amount_cents($highest['amount']) + amount_cents($p['min_increment'])) / 100);
}

View::header($p['name']);
?>
<div class="container">
  <nav class="breadcrumbs no-print"><a href="<?= e(url('')) ?>">Properties</a> / <?= e($p['type_name']) ?> / <?= e($p['ref_no']) ?></nav>
  <?php if ($isAdminPreview && (!(int) $p['is_published'] || (int) $p['is_archived'])): ?>
    <div class="alert alert-warning">Admin preview — this property is <?= (int) $p['is_archived'] ? 'archived' : 'not published' ?> and is not visible to the public.</div>
  <?php endif; ?>
  <div class="detail-title">
    <div>
      <div class="muted small">Reference No. <strong><?= e($p['ref_no']) ?></strong> · <?= e($p['type_name']) ?></div>
      <h1><?= e($p['name']) ?></h1>
      <div class="muted"><?= e($p['location']) ?></div>
    </div>
    <div class="btn-row no-print">
      <?= status_badge($p['status']) ?>
      <button type="button" class="btn btn-outline btn-sm" data-print>Print details</button>
    </div>
  </div>

  <div class="layout-main-side mt-2">
    <div>
      <?php if ($images): ?>
        <div class="gallery" data-gallery aria-roledescription="carousel" aria-label="Property photos">
          <?php foreach ($images as $i => $img): ?>
            <div class="slide<?= $i === 0 ? ' active' : '' ?>"><img src="<?= e(url('file.php?t=img&id=' . $img['id'])) ?>" alt="<?= e($img['caption'] ?: $p['name'] . ' photo ' . ($i + 1)) ?>" <?= $i > 0 ? 'loading="lazy"' : '' ?>>
              <?php if ($img['caption']): ?><div class="caption"><?= e($img['caption']) ?></div><?php endif; ?></div>
          <?php endforeach; ?>
          <button class="nav-btn prev" type="button" aria-label="Previous photo">&#8249;</button>
          <button class="nav-btn next" type="button" aria-label="Next photo">&#8250;</button>
          <span class="counter">1 / <?= count($images) ?></span>
        </div>
        <?php if (count($images) > 1): ?>
          <div class="thumbs no-print"><?php foreach ($images as $i => $img): ?><button type="button" class="<?= $i === 0 ? 'active' : '' ?>" aria-label="Show photo <?= $i + 1 ?>"><img src="<?= e(url('file.php?t=img&id=' . $img['id'])) ?>" alt="" loading="lazy"></button><?php endforeach; ?></div>
        <?php endif; ?>
      <?php else: ?>
        <div class="gallery"><div class="slide active"><img src="<?= e(asset('img/placeholder.svg')) ?>" alt="No photo available"></div></div>
      <?php endif; ?>

      <div class="card mt-2">
        <h2>Property Details</h2>
        <table class="kv">
          <tr><th>Property / unit name</th><td><?= e($p['name']) ?></td></tr>
          <tr><th>Reference number</th><td><strong><?= e($p['ref_no']) ?></strong></td></tr>
          <tr><th>Property type</th><td><?= e($p['type_name']) ?></td></tr>
          <tr><th>Location</th><td><?= e($p['location']) ?><?= $p['city'] ? ', ' . e($p['city']) : '' ?></td></tr>
          <tr><th>Floor area</th><td><?= $p['floor_area'] ? e(number_format((float) $p['floor_area'], 2)) . ' sqm' : '—' ?></td></tr>
          <tr><th>Starting / minimum bid</th><td><strong><?= e(money($p['starting_price'])) ?></strong></td></tr>
          <tr><th>Minimum bid increment</th><td><?= (float) $p['min_increment'] > 0 ? e(money($p['min_increment'])) : 'Not applicable' ?></td></tr>
          <tr><th>Bid opening</th><td><?= e(fmt_dt($p['opening_at'], 'l, M j, Y g:i A')) ?></td></tr>
          <tr><th>Bid closing</th><td><?= e(fmt_dt($p['closing_at'], 'l, M j, Y g:i A')) ?><?php if ($p['closing_at'] !== $p['original_closing_at']): ?><br><small class="muted">Originally <?= e(fmt_dt($p['original_closing_at'])) ?><?= (int) $p['extensions_used'] ? ' · extended ' . (int) $p['extensions_used'] . '× by anti-sniping rule' : '' ?></small><?php endif; ?></td></tr>
          <tr><th>Bidding status</th><td><?= status_badge($p['status']) ?></td></tr>
          <?php if (Eligibility::depositRequired($p)): ?><tr><th>Bid security deposit</th><td><?= e(money($p['deposit_amount'])) ?> (<?= (int) $p['deposit_refundable'] ? 'refundable' : 'non-refundable' ?>)</td></tr><?php endif; ?>
        </table>
        <?php if ($p['description']): ?><h3 class="mt-3">Description</h3><div class="prose"><?= nl2p($p['description']) ?></div><?php endif; ?>
        <?php if ($p['specifications']): ?><h3 class="mt-2">Specifications</h3><div class="prose"><?= nl2p($p['specifications']) ?></div><?php endif; ?>
      </div>

      <?php if ($docs): ?>
      <div class="card">
        <h2>Supporting Documents</h2>
        <ul class="doc-list">
          <?php foreach ($docs as $d): ?>
            <li><span><?= e($d['title']) ?> <small class="muted">(<?= e(strtoupper(pathinfo((string) $d['original_name'], PATHINFO_EXTENSION))) ?>, <?= e(number_format(((int) $d['file_size']) / 1024, 0)) ?> KB)</small></span>
              <a class="btn btn-outline btn-sm" href="<?= e(url('file.php?t=pdoc&id=' . $d['id'])) ?>">Download</a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <div class="card">
        <h2>Bidding Rules for this Property</h2>
        <p class="muted small">Please read these rules before bidding. They are shown to all participants before they bid.</p>
        <ul class="rules-list"><?php foreach ($rules as $r): ?><li><?= e($r) ?></li><?php endforeach; ?></ul>
        <?php if ($reqs): ?>
          <h3 class="mt-2">Required documents for this property</h3>
          <ul class="rules-list"><?php foreach ($reqs as $r): ?><li><strong><?= e($r['name']) ?></strong><?= $r['description'] ? ' — ' . e($r['description']) : '' ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </div>

      <div class="card">
        <h2>Terms and Conditions</h2>
        <?php if ($p['terms']): ?>
          <h3>Property-specific terms <small class="muted">(version <?= (int) $p['terms_version'] ?>)</small></h3>
          <div class="terms-box prose"><?= safe_html($p['terms']) ?></div>
        <?php endif; ?>
        <p>General bidding terms: <a href="<?= e(url('terms.php')) ?>" target="_blank" rel="noopener"><?= e($terms['title'] ?? 'Terms and Conditions') ?> (version <?= e($terms['version'] ?? '1.0') ?>)</a> · <a href="<?= e(url('privacy.php')) ?>" target="_blank" rel="noopener">Privacy Notice</a></p>
      </div>

      <div class="card">
        <h2>Contact Information</h2>
        <div class="prose"><?= $p['contact_info'] ? nl2p($p['contact_info']) : '<p>' . e(setting('contact_email')) . '<br>' . e(setting('contact_phone')) . '</p>' ?></div>
      </div>
    </div>

    <aside class="sticky-side" id="bid">
      <div class="card">
        <div class="price-box"><div class="label">Starting / minimum bid</div><div class="value"><?= e(money($p['starting_price'])) ?></div></div>
        <?php if ($p['status'] === 'open'): ?>
          <div class="countdown" data-countdown data-mode="closing" data-status="open" data-property-id="<?= $pid ?>" data-remaining="<?= Bidding::secondsRemaining($p) ?>">
            <div class="cd-label">Bidding closes in</div><div class="cd-value"><?= e(human_remaining(Bidding::secondsRemaining($p))) ?></div>
            <div class="cd-sub">Official closing: <?= e(fmt_dt($p['closing_at'], 'M j, Y g:i:s A')) ?> (server time)</div>
          </div>
        <?php elseif ($p['status'] === 'upcoming'): ?>
          <div class="countdown upcoming" data-countdown data-mode="opening" data-status="upcoming" data-property-id="<?= $pid ?>" data-remaining="<?= max(0, strtotime($p['opening_at']) - time()) ?>">
            <div class="cd-label">Bidding opens in</div><div class="cd-value"><?= e(human_remaining(max(0, strtotime($p['opening_at']) - time()))) ?></div>
            <div class="cd-sub">Opens <?= e(fmt_dt($p['opening_at'])) ?></div>
          </div>
        <?php else: ?>
          <div class="countdown ended"><div class="cd-label">Bidding status</div><div class="cd-value" style="font-size:1.3rem"><?= e(status_label($p['status'])) ?></div>
            <div class="cd-sub"><?= $p['status'] === 'cancelled' ? 'This bidding event was cancelled.' : 'No further bids are accepted. All records are preserved.' ?></div></div>
        <?php endif; ?>

        <?php if ($highest !== null || $bidderCount !== null): ?>
          <table class="kv mb-2">
            <?php if ((int) $p['show_highest']): ?><tr><th>Current highest bid</th><td data-live-highest><strong><?= $highest ? e(money($highest['amount'])) : 'No bids yet' ?></strong></td></tr><?php endif; ?>
            <?php if ($bidderCount !== null): ?><tr><th>Number of bidders</th><td data-live-count><?= (int) $bidderCount ?></td></tr><?php endif; ?>
          </table>
        <?php endif; ?>

        <?php if ($myActive): ?>
          <div class="alert alert-info">
            <strong>Your current bid: <?= e(money($myActive['amount'])) ?></strong><br>
            <small>Ref <?= e($myActive['bid_ref']) ?> · <?= e(fmt_dt_precise($myActive['submitted_at'])) ?></small>
            <?php if ($position !== null): ?><br><small>Your current ranking: <strong>#<?= (int) $position ?></strong> (for evaluation only)</small><?php endif; ?>
            <br><a href="<?= e(url('acknowledgment.php?ref=' . rawurlencode($myActive['bid_ref']))) ?>" class="small">View acknowledgment</a>
          </div>
        <?php elseif ($myBids && $myBids[0]['bid_type'] === 'withdrawal'): ?>
          <div class="alert alert-warning">You withdrew your bid (ref <?= e($myBids[0]['bid_ref']) ?>).</div>
        <?php endif; ?>
        <?php if ($participation && in_array($participation['eval_status'], ['winning', 'backup', 'not_awarded', 'disqualified', 'qualified'], true)): ?>
          <p>Your result: <?= status_badge($participation['eval_status']) ?></p>
        <?php endif; ?>

        <?php if ($isOpen || $p['status'] === 'upcoming'): ?>
          <h3 class="mt-2">Bidding requirements</h3>
          <ul class="checklist">
            <?php foreach ($elig['items'] as $it): if ($it['key'] === 'open' && $p['status'] === 'upcoming') continue; ?>
              <li class="<?= $it['ok'] ? 'ok' : 'no' ?>"><span class="icon"><?= $it['ok'] ? '✓' : '✕' ?></span>
                <span><?php if (!$it['ok'] && $it['link']): ?><a href="<?= e(url($it['link'])) ?>"><?= e($it['label']) ?></a><?php else: ?><?= e($it['label']) ?><?php endif; ?>
                <?php if (!$it['ok'] && $it['hint']): ?><span class="hint"><?= e($it['hint']) ?></span><?php endif; ?></span></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if ($user && (int) $p['require_prequalification'] && in_array($p['status'], ['upcoming', 'open'], true) && (!$participation || $participation['access_status'] === 'not_required')): ?>
          <form method="post" action="<?= e(url('join.php')) ?>" id="join" class="mb-2"><?= Csrf::field() ?><input type="hidden" name="property_id" value="<?= $pid ?>">
            <button class="btn btn-primary btn-block" type="submit">Request to join this bidding event</button></form>
        <?php endif; ?>

        <?php if (!$user): ?>
          <a class="btn btn-gold btn-lg btn-block" href="<?= e(url('register.php')) ?>">Register to Bid</a>
          <p class="text-center small mt-1">Already registered? <a href="<?= e(url('login.php')) ?>">Sign in</a></p>
        <?php elseif ($isOpen && $canRevise): ?>
          <form method="post" action="<?= e(url('bid.php')) ?>" id="bid-form" data-gps-required="<?= $p['gps_mode'] === 'required' ? '1' : '0' ?>" data-eligible="<?= $elig['ok'] ? '1' : '0' ?>"
                data-confirm="Please confirm: submit a bid of {amount} for <?= e($p['name']) ?> (<?= e($p['ref_no']) ?>)? Your bid is binding and will be permanently recorded.">
            <?= Csrf::field() ?>
            <input type="hidden" name="property_id" value="<?= $pid ?>">
            <input type="hidden" name="action" value="bid">
            <input type="hidden" name="gps_status" value="">
            <input type="hidden" name="gps_lat" value=""><input type="hidden" name="gps_lng" value=""><input type="hidden" name="gps_accuracy" value="">
            <input type="hidden" name="device_info" value="">
            <div class="form-group">
              <label class="form-label" for="amount"><?= $myActive ? 'Revised bid amount' : 'Your bid amount' ?> <span class="req">*</span></label>
              <div class="input-prefix"><span>₱</span><input class="form-control" id="amount" name="amount" inputmode="decimal" autocomplete="off" required placeholder="<?= e(number_format($suggest, 2)) ?>" <?= $elig['ok'] ? '' : 'disabled' ?>></div>
              <span class="form-help">Minimum acceptable: <?= e(money($suggest)) ?><?= (float) $p['min_increment'] > 0 ? ' · Increment: ' . e(money($p['min_increment'])) : '' ?></span>
            </div>

            <div class="gps-panel">
              <strong>Location consent <?= $p['gps_mode'] === 'required' ? '(required for this event)' : '(optional)' ?></strong>
              <div class="small mt-1"><?= safe_html($gpsDoc['content'] ?? '') ?></div>
              <div class="btn-row mt-1">
                <button type="button" class="btn btn-sm btn-primary" id="gps-allow" <?= $elig['ok'] ? '' : 'disabled' ?>>Share my location</button>
                <?php if ($p['gps_mode'] !== 'required'): ?><button type="button" class="btn btn-sm btn-outline" id="gps-decline" <?= $elig['ok'] ? '' : 'disabled' ?>>Don't share</button><?php endif; ?>
              </div>
              <div id="gps-status" class="gps-status" aria-live="polite">Please choose an option. Location is never collected without your action.</div>
            </div>

            <label class="check"><input type="checkbox" id="accept-terms" name="accept_terms" value="1" required <?= $elig['ok'] ? '' : 'disabled' ?>>
              <span>I have read and accept the <a href="<?= e(url('terms.php')) ?>" target="_blank" rel="noopener">Terms and Conditions (v<?= e($terms['version'] ?? '1.0') ?>)</a><?= $p['terms'] ? ', the property-specific terms (v' . (int) $p['terms_version'] . ')' : '' ?> and the bidding rules above. I understand my bid is binding and will be permanently recorded.</span></label>

            <button type="submit" class="btn btn-gold btn-lg btn-block" id="bid-submit" disabled><?= $myActive ? 'Submit Revised Bid' : 'Submit Bid' ?></button>
            <?php if (!$elig['ok']): ?><p class="small muted mt-1 text-center">The Submit Bid button is enabled once all requirements above are completed.</p><?php endif; ?>
          </form>
          <?php if ($myActive && (int) $p['allow_withdrawal']): ?>
            <form method="post" action="<?= e(url('bid.php')) ?>" class="mt-2" data-confirm="Withdraw your bid of <?= e(money($myActive['amount'])) ?>? The withdrawal will be permanently recorded.">
              <?= Csrf::field() ?><input type="hidden" name="property_id" value="<?= $pid ?>"><input type="hidden" name="action" value="withdraw"><input type="hidden" name="gps_status" value="not_requested">
              <button class="btn btn-outline btn-block btn-sm" type="submit">Withdraw my bid</button>
            </form>
          <?php endif; ?>
        <?php elseif ($isOpen && !$canRevise): ?>
          <div class="alert alert-info">This property allows only one bid per bidder. Your bid has been recorded.</div>
        <?php elseif ($p['status'] === 'upcoming'): ?>
          <button class="btn btn-gold btn-lg btn-block" disabled>Submit Bid (opens <?= e(fmt_dt($p['opening_at'], 'M j, g:i A')) ?>)</button>
        <?php endif; ?>
      </div>

      <div class="card text-center no-print">
        <h3>Share this property</h3>
        <div class="qr-box" data-qr="<?= e($shareUrl) ?>" data-size="150" aria-label="QR code linking to this property"></div>
        <p class="small muted mt-1">Scan to open this bidding page</p>
        <a class="btn btn-outline btn-sm" href="<?= e(url('qr.php?ref=' . rawurlencode($p['ref_no']))) ?>" target="_blank" rel="noopener">Printable QR code</a>
      </div>
      <div class="print-only"><p>Bidding page: <?= e($shareUrl) ?></p><p>Printed <?= e(date('M j, Y g:i A')) ?> (server time)</p></div>
    </aside>
  </div>
</div>
<script src="<?= e(asset('js/vendor/qrcode.js')) ?>"></script>
<?php View::footer();
