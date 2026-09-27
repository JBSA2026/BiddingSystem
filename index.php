<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$types = DB::all('SELECT id, name FROM property_types WHERE is_active = 1 ORDER BY sort_order, name');
$locations = DB::all("SELECT DISTINCT city FROM properties WHERE is_published = 1 AND is_archived = 0 AND city IS NOT NULL AND city <> '' ORDER BY city");

$q = query('q');
$typeId = query_int('type');
$city = query('city');
$status = query('status');
$minP = parse_amount(query('min'));
$maxP = parse_amount(query('max'));
$sort = query('sort', 'status');

$where = ['p.is_published = 1', 'p.is_archived = 0'];
$params = [];
if ($q !== '') {
    $where[] = '(p.ref_no LIKE ? OR p.name LIKE ? OR p.location LIKE ?)';
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    array_push($params, $like, $like, $like);
}
if ($typeId) {
    $where[] = 'p.property_type_id = ?';
    $params[] = $typeId;
}
if ($city !== '') {
    $where[] = 'p.city = ?';
    $params[] = $city;
}
$statuses = ['upcoming', 'open', 'closed', 'under_evaluation', 'awarded', 'cancelled'];
if (in_array($status, $statuses, true)) {
    $where[] = 'p.status = ?';
    $params[] = $status;
}
if ($minP !== null) {
    $where[] = 'p.starting_price >= ?';
    $params[] = $minP;
}
if ($maxP !== null) {
    $where[] = 'p.starting_price <= ?';
    $params[] = $maxP;
}
$order = match ($sort) {
    'price_asc' => 'p.starting_price ASC',
    'price_desc' => 'p.starting_price DESC',
    'closing' => 'p.closing_at ASC',
    'newest' => 'p.created_at DESC',
    default => "FIELD(p.status, 'open', 'upcoming', 'under_evaluation', 'closed', 'awarded', 'cancelled'), p.closing_at ASC",
};
$whereSql = implode(' AND ', $where);
$total = (int) DB::val("SELECT COUNT(*) FROM properties p WHERE {$whereSql}", $params);
$pg = paginate($total, 12, query_int('page', 1));
$rows = DB::all(
    "SELECT p.*, t.name AS type_name FROM properties p JOIN property_types t ON t.id = p.property_type_id
     WHERE {$whereSql} ORDER BY {$order} LIMIT {$pg['per']} OFFSET {$pg['offset']}",
    $params
);
$counts = DB::one("SELECT SUM(status='open') AS open_n, SUM(status='upcoming') AS upcoming_n FROM properties WHERE is_published = 1 AND is_archived = 0");

View::header('Properties Open for Bidding', ['active' => 'home']);
?>
<section class="hero">
  <div class="container">
    <div class="eyebrow">Cityland Online Property Bidding</div>
    <h1>Own a piece of the city, <em>one bid at a time.</em></h1>
    <p>Browse Cityland condominium units, parking slots, properties and other assets open for bidding. Register, get verified and submit your bid securely online, through a transparent and fully audited process.</p>
    <ol class="process" aria-label="Bidding process">
      <li>View Property</li><li>Register</li><li>Verify Account</li><li>Review Terms</li><li>Submit Bid</li><li>Receive Confirmation</li><li>Monitor Status</li><li>Evaluation / Award</li>
    </ol>
  </div>
</section>

<div class="container">
  <div class="filters">
    <form method="get" action="<?= e(url('')) ?>" role="search">
      <div class="f-search">
        <label class="form-label" for="f-q">Search name, location or reference no.</label>
        <input class="form-control" id="f-q" type="search" name="q" value="<?= e($q) ?>" placeholder="e.g. CL-2026-00012 or Makati">
      </div>
      <div>
        <label class="form-label" for="f-type">Property type</label>
        <select class="form-control" id="f-type" name="type">
          <option value="">All types</option>
          <?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $typeId === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label" for="f-city">Location</label>
        <select class="form-control" id="f-city" name="city">
          <option value="">All locations</option>
          <?php foreach ($locations as $l): ?><option value="<?= e($l['city']) ?>" <?= $city === $l['city'] ? 'selected' : '' ?>><?= e($l['city']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label" for="f-status">Bidding status</label>
        <select class="form-control" id="f-status" name="status">
          <option value="">All statuses</option>
          <?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label" for="f-min">Min price (₱)</label>
        <input class="form-control" id="f-min" name="min" inputmode="decimal" value="<?= e(query('min')) ?>" placeholder="0">
      </div>
      <div>
        <label class="form-label" for="f-max">Max price (₱)</label>
        <input class="form-control" id="f-max" name="max" inputmode="decimal" value="<?= e(query('max')) ?>" placeholder="Any">
      </div>
      <div class="btn-row">
        <button class="btn btn-primary" type="submit">Search</button>
        <?php if ($_GET): ?><a class="btn btn-link" href="<?= e(url('')) ?>">Reset</a><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="page-actions">
    <div><strong><?= $total ?></strong> propert<?= $total === 1 ? 'y' : 'ies' ?> found
      <?php if ($counts): ?><span class="muted"> · <?= (int) $counts['open_n'] ?> open now · <?= (int) $counts['upcoming_n'] ?> upcoming</span><?php endif; ?></div>
    <form method="get" class="inline-form">
      <?php foreach (['q', 'type', 'city', 'status', 'min', 'max'] as $k): if (query($k) !== ''): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e(query($k)) ?>"><?php endif; endforeach; ?>
      <label class="small muted" for="sort">Sort</label>
      <select id="sort" name="sort" class="form-control sm" style="width:auto;display:inline-block" data-autosubmit>
        <option value="status" <?= $sort === 'status' ? 'selected' : '' ?>>Open first</option>
        <option value="closing" <?= $sort === 'closing' ? 'selected' : '' ?>>Closing soonest</option>
        <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: low to high</option>
        <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: high to low</option>
        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
      </select>
      <noscript><button class="btn btn-sm btn-outline" type="submit">Apply</button></noscript>
    </form>
  </div>

  <?php if (!$rows): ?>
    <div class="card empty"><h3>No properties match your search</h3><p>Try removing some filters or check back soon for new bidding events.</p></div>
  <?php else: ?>
    <div class="property-grid">
      <?php foreach ($rows as $p): $link = url('property.php?ref=' . rawurlencode($p['ref_no'])); ?>
        <article class="property-card">
          <a class="thumb" href="<?= e($link) ?>">
            <img src="<?= e(View::coverImage((int) $p['id'])) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
            <?= status_badge($p['status']) ?>
          </a>
          <div class="body">
            <div class="ref"><?= e($p['ref_no']) ?> · <?= e($p['type_name']) ?></div>
            <h3><a href="<?= e($link) ?>"><?= e($p['name']) ?></a></h3>
            <div class="loc"><?= e($p['location']) ?><?= $p['floor_area'] ? ' · ' . e(rtrim(rtrim(number_format((float) $p['floor_area'], 2), '0'), '.')) . ' sqm' : '' ?></div>
            <div class="price"><small>Minimum bid</small><?= e(money($p['starting_price'])) ?></div>
            <div class="meta">
              <?php if ($p['status'] === 'open'): ?>
                <span>Closes <?= e(fmt_dt($p['closing_at'])) ?></span><span class="nowrap"><?= e(human_remaining(Bidding::secondsRemaining($p))) ?> left</span>
              <?php elseif ($p['status'] === 'upcoming'): ?>
                <span>Opens <?= e(fmt_dt($p['opening_at'])) ?></span>
              <?php else: ?>
                <span>Closed <?= e(fmt_dt($p['closed_at'] ?? $p['closing_at'])) ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div class="actions">
            <a class="btn btn-outline btn-sm" href="<?= e($link) ?>">View details</a>
            <?php if ($p['status'] === 'open'): ?><a class="btn btn-gold btn-sm" href="<?= e($link) ?>#bid">Submit Bid</a>
            <?php elseif ($p['status'] === 'upcoming'): ?><a class="btn btn-primary btn-sm" href="<?= e(url(Auth::bidder() ? 'dashboard.php' : 'register.php')) ?>"><?= Auth::bidder() ? 'Get ready' : 'Register' ?></a><?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <?= View::pager($pg) ?>
  <?php endif; ?>
</div>
<?php View::footer();
