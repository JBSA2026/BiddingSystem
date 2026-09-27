<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$admin = Auth::requireAdmin('dashboard.view');
$s = DB::one("SELECT COUNT(*) AS total,
    SUM(status='open') AS open_n, SUM(status='upcoming') AS upcoming_n, SUM(status='closed') AS closed_n,
    SUM(status='under_evaluation') AS eval_n, SUM(status='awarded') AS awarded_n, SUM(status='cancelled') AS cancelled_n
    FROM properties WHERE is_archived = 0");
$bidders = (int) DB::val('SELECT COUNT(*) FROM users');
$pendingBidders = (int) DB::val("SELECT COUNT(*) FROM users WHERE verification_status = 'pending' AND email_verified_at IS NOT NULL");
$totalBids = (int) DB::val("SELECT COUNT(*) FROM bids WHERE bid_type <> 'withdrawal'");
$pendingDocs = (int) DB::val("SELECT COUNT(*) FROM bidder_documents d WHERE status = 'pending' AND d.id = (SELECT MAX(id) FROM bidder_documents x WHERE x.user_id = d.user_id AND x.requirement_type_id = d.requirement_type_id)");
$openFlags = (int) DB::val('SELECT COUNT(*) FROM bidder_flags WHERE resolved_at IS NULL');
$pendingAwards = (int) DB::val("SELECT COUNT(*) FROM awards WHERE status = 'pending_approval'");
$pendingSched = (int) DB::val("SELECT COUNT(*) FROM approval_requests WHERE status = 'pending'");
$pendingPay = (int) DB::val("SELECT COUNT(*) FROM payments WHERE status = 'pending'");

$openProps = DB::all("SELECT p.*, (SELECT COUNT(DISTINCT user_id) FROM bids b WHERE b.property_id = p.id) AS bidders_n, (SELECT MAX(amount) FROM bids b WHERE b.property_id = p.id AND b.bid_type <> 'withdrawal') AS max_bid
    FROM properties p WHERE p.status IN ('open','upcoming') AND p.is_archived = 0 ORDER BY p.closing_at ASC LIMIT 10");
$recentBids = DB::all('SELECT b.*, p.ref_no, p.name AS pname, u.bidder_no, u.full_name FROM bids b JOIN properties p ON p.id = b.property_id JOIN users u ON u.id = b.user_id ORDER BY b.id DESC LIMIT 10');
$evalProps = DB::all("SELECT * FROM properties WHERE status = 'under_evaluation' AND is_archived = 0 ORDER BY closed_at ASC LIMIT 10");

View::adminHeader('Dashboard');
?>
<div class="grid grid-4 mb-3">
  <a class="stat" href="<?= e(url('admin/properties.php')) ?>"><div class="l">Total properties</div><div class="n"><?= (int) $s['total'] ?></div></a>
  <a class="stat green" href="<?= e(url('admin/properties.php?status=open')) ?>"><div class="l">Open bidding</div><div class="n"><?= (int) $s['open_n'] ?></div></a>
  <a class="stat blue" href="<?= e(url('admin/properties.php?status=upcoming')) ?>"><div class="l">Upcoming</div><div class="n"><?= (int) $s['upcoming_n'] ?></div></a>
  <a class="stat" href="<?= e(url('admin/properties.php?status=closed')) ?>"><div class="l">Closed</div><div class="n"><?= (int) $s['closed_n'] ?></div></a>
  <a class="stat gold" href="<?= e(url('admin/bidders.php')) ?>"><div class="l">Registered bidders</div><div class="n"><?= $bidders ?></div></a>
  <a class="stat blue" href="<?= e(url('admin/bids.php')) ?>"><div class="l">Total bids</div><div class="n"><?= $totalBids ?></div></a>
  <a class="stat amber" href="<?= e(url('admin/properties.php?status=under_evaluation')) ?>"><div class="l">Under evaluation</div><div class="n"><?= (int) $s['eval_n'] ?></div></a>
  <a class="stat gold" href="<?= e(url('admin/properties.php?status=awarded')) ?>"><div class="l">Awarded</div><div class="n"><?= (int) $s['awarded_n'] ?></div></a>
</div>

<?php if ($pendingBidders || $pendingDocs || $openFlags || $pendingAwards || $pendingSched || $pendingPay): ?>
<div class="card">
  <h2>Needs attention</h2>
  <div class="btn-row">
    <?php if ($pendingBidders): ?><a class="btn btn-outline btn-sm" href="<?= e(url('admin/bidders.php?status=pending')) ?>"><?= $pendingBidders ?> bidder(s) awaiting approval</a><?php endif; ?>
    <?php if ($pendingDocs): ?><a class="btn btn-outline btn-sm" href="<?= e(url('admin/bidders.php?docs=pending')) ?>"><?= $pendingDocs ?> document(s) to review</a><?php endif; ?>
    <?php if ($openFlags): ?><a class="btn btn-outline btn-sm" href="<?= e(url('admin/bidders.php?flagged=1')) ?>"><?= $openFlags ?> open flag(s)</a><?php endif; ?>
    <?php if ($pendingAwards): ?><a class="btn btn-gold btn-sm" href="<?= e(url('admin/approvals.php')) ?>"><?= $pendingAwards ?> award(s) pending approval</a><?php endif; ?>
    <?php if ($pendingSched): ?><a class="btn btn-gold btn-sm" href="<?= e(url('admin/approvals.php')) ?>"><?= $pendingSched ?> schedule change(s) pending</a><?php endif; ?>
    <?php if ($pendingPay): ?><a class="btn btn-outline btn-sm" href="<?= e(url('admin/payments.php?status=pending')) ?>"><?= $pendingPay ?> payment(s) to reconcile</a><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="grid grid-2">
  <div class="card">
    <div class="card-header"><h2>Open &amp; upcoming bidding</h2><?php if (can('properties.manage')): ?><a class="btn btn-primary btn-sm" href="<?= e(url('admin/property_edit.php')) ?>">+ Add property</a><?php endif; ?></div>
    <?php if (!$openProps): ?><p class="muted">No open or upcoming bidding events.</p><?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Property</th><th>Status</th><th>Closes</th><th class="num">Bidders</th><th class="num">Highest</th></tr></thead><tbody>
      <?php foreach ($openProps as $p): ?><tr><td><a href="<?= e(url('admin/property_bids.php?id=' . $p['id'])) ?>"><?= e($p['name']) ?></a><br><small class="muted"><?= e($p['ref_no']) ?></small></td><td><?= status_badge($p['status']) ?></td><td class="nowrap"><?= e(fmt_dt($p['closing_at'])) ?></td><td class="num"><?= (int) $p['bidders_n'] ?></td><td class="num"><?= e(money($p['max_bid'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div>
  <div class="card">
    <h2>Awaiting evaluation</h2>
    <?php if (!$evalProps): ?><p class="muted">No properties under evaluation.</p><?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Property</th><th>Closed</th><th></th></tr></thead><tbody>
      <?php foreach ($evalProps as $p): ?><tr><td><?= e($p['name']) ?><br><small class="muted"><?= e($p['ref_no']) ?></small></td><td><?= e(fmt_dt($p['closed_at'])) ?></td><td><a class="btn btn-sm btn-outline" href="<?= e(url('admin/property_bids.php?id=' . $p['id'])) ?>">Evaluate</a></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-header"><h2>Latest bid activity</h2><a class="small" href="<?= e(url('admin/bids.php')) ?>">All bids &raquo;</a></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Time (server)</th><th>Bid ref</th><th>Property</th><th>Bidder</th><th>Type</th><th class="num">Amount</th><th>GPS</th></tr></thead><tbody>
    <?php foreach ($recentBids as $b): ?><tr><td class="nowrap"><?= e(fmt_dt_precise($b['submitted_at'])) ?></td><td class="nowrap"><?= e($b['bid_ref']) ?></td><td><?= e($b['ref_no']) ?></td><td><a href="<?= e(url('admin/bidder_view.php?id=' . $b['user_id'])) ?>"><?= e($b['full_name']) ?></a><br><small class="muted"><?= e($b['bidder_no']) ?></small></td><td><?= e(status_label($b['bid_type'])) ?></td><td class="num"><?= e(money($b['amount'])) ?></td><td><?= status_badge($b['gps_status']) ?></td></tr><?php endforeach; ?>
    <?php if (!$recentBids): ?><tr><td colspan="7" class="muted">No bids yet.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<?php View::adminFooter();
