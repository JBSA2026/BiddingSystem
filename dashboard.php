<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$user = Auth::requireBidder();
$uid = (int) $user['id'];
$elig = Eligibility::check($user);
$joined = DB::all(
    "SELECT p.*, t.name AS type_name, pb.eval_status, pb.access_status, pb.joined_at
     FROM property_bidders pb JOIN properties p ON p.id = pb.property_id JOIN property_types t ON t.id = p.property_type_id
     WHERE pb.user_id = ? ORDER BY FIELD(p.status,'open','upcoming','under_evaluation','closed','awarded','cancelled'), p.closing_at DESC",
    [$uid]
);
$bids = DB::all('SELECT b.*, p.name AS property_name, p.ref_no FROM bids b JOIN properties p ON p.id = b.property_id WHERE b.user_id = ? ORDER BY b.id DESC LIMIT 100', [$uid]);
$notifs = DB::all("SELECT * FROM notifications WHERE recipient_type = 'bidder' AND recipient_id = ? ORDER BY id DESC LIMIT 5", [$uid]);
$docReqs = DB::all("SELECT * FROM requirement_types WHERE is_active = 1 AND (scope = 'registration' OR id IN (SELECT pr.requirement_type_id FROM property_requirements pr JOIN property_bidders pb ON pb.property_id = pr.property_id WHERE pb.user_id = ?)) ORDER BY sort_order", [$uid]);

View::header('My Dashboard', ['active' => 'dashboard']);
?>
<section class="container section">
  <div class="page-actions">
    <div><h1 class="mb-0">Welcome, <?= e($user['full_name']) ?></h1><span class="muted">Bidder No. <strong><?= e($user['bidder_no']) ?></strong><?= $user['company_name'] ? ' · ' . e($user['company_name']) : '' ?></span></div>
    <a class="btn btn-gold" href="<?= e(url('')) ?>">Browse properties</a>
  </div>

  <div class="grid grid-4 mb-3">
    <div class="stat"><div class="l">Verification</div><div class="n" style="font-size:1rem;margin-top:6px"><?= status_badge($user['verification_status']) ?></div></div>
    <div class="stat gold"><div class="l">Properties joined</div><div class="n"><?= count($joined) ?></div></div>
    <div class="stat blue"><div class="l">Bids submitted</div><div class="n"><?= count(array_filter($bids, static fn($b) => $b['bid_type'] !== 'withdrawal')) ?></div></div>
    <div class="stat green"><div class="l">Awards</div><div class="n"><?= count(array_filter($joined, static fn($j) => $j['eval_status'] === 'winning')) ?></div></div>
  </div>

  <div class="layout-main-side">
    <div>
      <div class="card">
        <div class="card-header"><h2>Properties I joined</h2></div>
        <?php if (!$joined): ?>
          <p class="muted">You have not joined any bidding event yet. <a href="<?= e(url('')) ?>">Browse available properties</a>.</p>
        <?php else: ?>
          <div class="table-wrap"><table class="table">
            <thead><tr><th>Property</th><th>Bidding status</th><th class="num">My current bid</th><th>Ranking</th><th>Result</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($joined as $j):
                $last = DB::one('SELECT * FROM bids WHERE property_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1', [$j['id'], $uid]);
                $active = $last && $last['bid_type'] !== 'withdrawal' ? $last : null;
                $rank = ($active && (int) $j['show_ranking']) ? Bidding::bidderPosition((int) $j['id'], $uid) : null;
                $resultStatus = in_array($j['status'], ['awarded'], true) || in_array($j['eval_status'], ['winning', 'backup', 'not_awarded', 'disqualified', 'qualified'], true) ? $j['eval_status'] : ($j['status'] === 'under_evaluation' ? 'under_review' : null);
            ?>
              <tr>
                <td><a href="<?= e(url('property.php?ref=' . rawurlencode($j['ref_no']))) ?>"><strong><?= e($j['name']) ?></strong></a><br><small class="muted"><?= e($j['ref_no']) ?> · closes <?= e(fmt_dt($j['closing_at'])) ?></small>
                  <?php if ($j['access_status'] === 'pending'): ?><br><?= status_badge('pending') ?> <small>pre-qualification</small><?php elseif ($j['access_status'] === 'rejected'): ?><br><small class="muted">Pre-qualification not approved</small><?php endif; ?></td>
                <td><?= status_badge($j['status']) ?></td>
                <td class="num"><?= $active ? e(money($active['amount'])) . '<br><small class="muted">' . e($active['bid_ref']) . '</small>' : ($last ? '<small class="muted">Withdrawn</small>' : '—') ?></td>
                <td><?= $rank !== null ? '#' . (int) $rank : '<small class="muted">' . ((int) $j['show_ranking'] ? '—' : 'Not disclosed') . '</small>' ?></td>
                <td><?= $resultStatus ? status_badge($resultStatus) : '<small class="muted">Pending close</small>' ?></td>
                <td><?php if ($j['status'] === 'open'): ?><a class="btn btn-sm btn-gold" href="<?= e(url('property.php?ref=' . rawurlencode($j['ref_no']) . '#bid')) ?>">Bid</a><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="card-header"><h2>My bid history</h2><small class="muted">All submissions are permanent; earlier bids are never overwritten.</small></div>
        <?php if (!$bids): ?><p class="muted">No bids yet.</p><?php else: ?>
          <div class="table-wrap"><table class="table">
            <thead><tr><th>Bid reference</th><th>Property</th><th>Type</th><th class="num">Amount</th><th>Date / time (server)</th><th></th></tr></thead>
            <tbody><?php foreach ($bids as $b): ?>
              <tr><td class="nowrap"><?= e($b['bid_ref']) ?></td><td><?= e($b['property_name']) ?><br><small class="muted"><?= e($b['ref_no']) ?></small></td>
                <td><?= e(status_label($b['bid_type'])) ?></td>
                <td class="num"><?= e(money($b['amount'])) ?></td><td class="nowrap"><?= e(fmt_dt_precise($b['submitted_at'])) ?></td>
                <td><a class="btn btn-sm btn-outline" href="<?= e(url('acknowledgment.php?ref=' . rawurlencode($b['bid_ref']))) ?>">Receipt</a></td></tr>
            <?php endforeach; ?></tbody>
          </table></div>
        <?php endif; ?>
      </div>
    </div>

    <aside>
      <div class="card">
        <h3>Account readiness</h3>
        <ul class="checklist">
          <?php foreach ($elig['items'] as $it): ?>
            <li class="<?= $it['ok'] ? 'ok' : 'no' ?>"><span class="icon"><?= $it['ok'] ? '✓' : '✕' ?></span><span><?php if (!$it['ok'] && $it['link']): ?><a href="<?= e(url($it['link'])) ?>"><?= e($it['label']) ?></a><?php else: ?><?= e($it['label']) ?><?php endif; ?>
              <?php if (!$it['ok'] && $it['hint']): ?><span class="hint"><?= e($it['hint']) ?></span><?php endif; ?></span></li>
          <?php endforeach; ?>
        </ul>
        <?php if ($user['verification_status'] === 'rejected' && $user['verification_remarks']): ?><div class="alert alert-error small"><?= e($user['verification_remarks']) ?></div><?php endif; ?>
      </div>
      <div class="card">
        <h3>Required documents</h3>
        <ul class="doc-list">
          <?php foreach ($docReqs as $r): $st = Eligibility::docStatus($uid, (int) $r['id']); ?>
            <li><span><?= e($r['name']) ?><?= (int) $r['is_required'] || $r['scope'] === 'property' ? '' : ' <small class="muted">(optional)</small>' ?></span><?= $st ? status_badge($st) : '<span class="badge badge-grey">Not uploaded</span>' ?></li>
          <?php endforeach; ?>
        </ul>
        <a class="btn btn-outline btn-sm mt-1" href="<?= e(url('documents.php')) ?>">Manage documents</a>
      </div>
      <div class="card">
        <div class="card-header"><h3>Notifications</h3><a class="small" href="<?= e(url('notifications.php')) ?>">View all</a></div>
        <?php if (!$notifs): ?><p class="muted small">No notifications yet.</p><?php endif; ?>
        <?php foreach ($notifs as $n): ?>
          <div class="notif<?= (int) $n['is_read'] ? '' : ' unread' ?>"><div class="t"><?= e($n['title']) ?></div><div class="d"><?= e(fmt_dt($n['created_at'])) ?></div></div>
        <?php endforeach; ?>
      </div>
      <div class="card">
        <h3>My account</h3>
        <p class="small"><?= e($user['email']) ?> <?= $user['email_verified_at'] ? status_badge('verified') : status_badge('pending') ?><br>
        <?= e($user['mobile']) ?> <?= $user['mobile_verified_at'] ? status_badge('verified') : (Eligibility::mobileOtpRequired() ? status_badge('pending') : '') ?></p>
        <div class="btn-row"><a class="btn btn-sm btn-outline" href="<?= e(url('profile.php')) ?>">Profile &amp; password</a><a class="btn btn-sm btn-link" href="<?= e(url('privacy.php#rights')) ?>">My privacy rights</a></div>
      </div>
    </aside>
  </div>
</section>
<?php View::footer();
