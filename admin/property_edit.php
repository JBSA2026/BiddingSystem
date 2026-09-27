<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$admin = Auth::requireAdmin('properties.manage');
$id = query_int('id') ?: input_int('id');
$p = $id ? Bidding::property($id) : null;
if ($id && !$p) {
    abort(404);
}
$types = DB::all('SELECT * FROM property_types WHERE is_active = 1 ORDER BY sort_order, name');
$reqTypes = DB::all("SELECT * FROM requirement_types WHERE is_active = 1 ORDER BY scope DESC, sort_order");
$hasBids = $p && (bool) DB::val('SELECT 1 FROM bids WHERE property_id = ? LIMIT 1', [$id]);
$started = $p && $p['status'] !== 'upcoming';
$finished = $p && in_array($p['status'], ['closed', 'under_evaluation', 'awarded', 'cancelled'], true);
$lockRules = $hasBids || $started;       // pricing, bid rules & terms fixed once bidding starts
$lockSchedule = $started;                // schedule changes after start go through the audited change request
$selectedReqs = $p ? array_map('intval', array_column(DB::all('SELECT requirement_type_id FROM property_requirements WHERE property_id = ?', [$id]), 'requirement_type_id')) : [];
$errors = [];

$toDt = static fn(string $v): ?string => ($v !== '' && ($t = strtotime($v)) !== false) ? date('Y-m-d H:i:00', $t) : null;
$chk = static fn(string $k): int => empty($_POST[$k]) ? 0 : 1;

if (is_post()) {
    Csrf::verify();
    $action = input('action', 'save');

    // ---- image / document management
    if ($p && $action === 'upload_images') {
        $n = 0;
        foreach (Upload::files('images') as $f) {
            try {
                $s = Upload::store($f, 'properties', Upload::IMAGE_TYPES);
                $imgId = DB::insert('property_images', ['property_id' => $id, 'file_path' => $s['path'], 'original_name' => $s['original'],
                    'caption' => mb_substr(input('caption'), 0, 255) ?: null, 'sort_order' => (int) DB::val('SELECT COALESCE(MAX(sort_order),0)+1 FROM property_images WHERE property_id = ?', [$id]), 'created_at' => now()]);
                Audit::log('property_image_uploaded', 'property', $id, null, ['image_id' => $imgId, 'file' => $s['original']]);
                $n++;
            } catch (RuntimeException $e) {
                flash('error', $f['name'] . ': ' . $e->getMessage());
            }
        }
        if ($n) {
            flash('success', "{$n} image(s) uploaded.");
        }
        redirect('admin/property_edit.php?id=' . $id . '#media');
    }
    if ($p && $action === 'delete_image') {
        $img = DB::one('SELECT * FROM property_images WHERE id = ? AND property_id = ?', [input_int('image_id'), $id]);
        if ($img) {
            DB::run('DELETE FROM property_images WHERE id = ?', [$img['id']]);
            if ($abs = Upload::absolute($img['file_path'])) {
                @unlink($abs);
            }
            Audit::log('property_image_deleted', 'property', $id, ['image_id' => $img['id'], 'file' => $img['original_name']], null);
            flash('success', 'Image removed.');
        }
        redirect('admin/property_edit.php?id=' . $id . '#media');
    }
    if ($p && $action === 'image_order') {
        foreach ((array) ($_POST['order'] ?? []) as $imgId => $ord) {
            DB::update('property_images', ['sort_order' => (int) $ord, 'caption' => mb_substr((string) ($_POST['captions'][$imgId] ?? ''), 0, 255) ?: null], 'id = ? AND property_id = ?', [(int) $imgId, $id]);
        }
        Audit::log('property_images_reordered', 'property', $id);
        flash('success', 'Image order and captions saved.');
        redirect('admin/property_edit.php?id=' . $id . '#media');
    }
    if ($p && $action === 'upload_doc') {
        $files = Upload::files('document');
        $title = mb_substr(input('doc_title'), 0, 190);
        if (!$files || $title === '') {
            flash('error', 'Please provide a document title and file.');
        } else {
            try {
                $s = Upload::store($files[0], 'property_docs', Upload::DOC_TYPES);
                $docId = DB::insert('property_documents', ['property_id' => $id, 'title' => $title, 'file_path' => $s['path'], 'original_name' => $s['original'],
                    'mime' => $s['mime'], 'file_size' => $s['size'], 'is_public' => $chk('doc_public'), 'created_by' => $admin['id'], 'created_at' => now()]);
                Audit::log('property_document_uploaded', 'property', $id, null, ['document_id' => $docId, 'title' => $title, 'file' => $s['original'], 'public' => $chk('doc_public')]);
                flash('success', 'Document uploaded.');
            } catch (RuntimeException $e) {
                flash('error', $e->getMessage());
            }
        }
        redirect('admin/property_edit.php?id=' . $id . '#media');
    }
    if ($p && $action === 'delete_doc') {
        $doc = DB::one('SELECT * FROM property_documents WHERE id = ? AND property_id = ?', [input_int('doc_id'), $id]);
        if ($doc) {
            DB::run('DELETE FROM property_documents WHERE id = ?', [$doc['id']]);
            if ($abs = Upload::absolute($doc['file_path'])) {
                @unlink($abs);
            }
            Audit::log('property_document_deleted', 'property', $id, ['document_id' => $doc['id'], 'title' => $doc['title']], null);
            flash('success', 'Document removed.');
        }
        redirect('admin/property_edit.php?id=' . $id . '#media');
    }

    // ---- main save
    $d = [
        'name' => mb_substr(input('name'), 0, 190),
        'property_type_id' => input_int('property_type_id'),
        'location' => mb_substr(input('location'), 0, 255),
        'city' => mb_substr(input('city'), 0, 120) ?: null,
        'description' => input('description') ?: null,
        'floor_area' => input('floor_area') !== '' ? (is_numeric(str_replace(',', '', input('floor_area'))) ? str_replace(',', '', input('floor_area')) : null) : null,
        'specifications' => input('specifications') ?: null,
        'contact_info' => input('contact_info') ?: null,
    ];
    if ($d['name'] === '' || $d['location'] === '' || !$d['property_type_id']) {
        $errors[] = 'Name, property type and location are required.';
    }
    if (!$lockRules) {
        $start = parse_amount(input('starting_price'));
        $inc = input('min_increment') === '' ? '0.00' : parse_amount(input('min_increment'));
        if ($start === null || (float) $start <= 0) {
            $errors[] = 'Enter a valid starting / minimum bid price.';
        }
        if ($inc === null) {
            $errors[] = 'Enter a valid minimum increment (or leave blank).';
        }
        $dep = input('deposit_amount') === '' ? null : parse_amount(input('deposit_amount'));
        $d += [
            'starting_price' => $start, 'min_increment' => $inc,
            'bid_mode' => input('bid_mode') === 'single' ? 'single' : 'multiple',
            'higher_only' => $chk('higher_only'), 'allow_withdrawal' => $chk('allow_withdrawal'),
            'show_ranking' => $chk('show_ranking'), 'show_highest' => $chk('show_highest'), 'show_bidder_count' => $chk('show_bidder_count'),
            'gps_mode' => input('gps_mode') === 'required' ? 'required' : 'optional',
            'require_approved_account' => $chk('require_approved_account'), 'require_prequalification' => $chk('require_prequalification'),
            'antisnipe_enabled' => $chk('antisnipe_enabled'),
            'antisnipe_trigger_min' => max(1, min(120, input_int('antisnipe_trigger_min', 5))),
            'antisnipe_extend_min' => max(1, min(120, input_int('antisnipe_extend_min', 5))),
            'antisnipe_max_ext' => max(0, min(100, input_int('antisnipe_max_ext', 3))),
            'deposit_required' => $chk('deposit_required'), 'deposit_amount' => $dep, 'deposit_refundable' => $chk('deposit_refundable'),
        ];
        if ($d['deposit_required'] && (!$dep || (float) $dep <= 0)) {
            $errors[] = 'Enter the bid security deposit amount, or untick the deposit requirement.';
        }
        $newTerms = input('terms');
        if (!$p || trim((string) $p['terms']) !== trim($newTerms)) {
            $d['terms'] = $newTerms !== '' ? $newTerms : null;
            if ($p) {
                $d['terms_version'] = (int) $p['terms_version'] + 1;
            }
        }
    }
    if (!$lockSchedule) {
        $open = $toDt(input('opening_at'));
        $close = $toDt(input('closing_at'));
        if (!$open || !$close) {
            $errors[] = 'Opening and closing date/time are required.';
        } elseif (strtotime($close) <= strtotime($open)) {
            $errors[] = 'Closing time must be after the opening time.';
        } elseif (strtotime($close) <= time()) {
            $errors[] = 'Closing time must be in the future.';
        } else {
            $d['opening_at'] = $open;
            $d['closing_at'] = $close;
            $d['original_closing_at'] = $close;
        }
    }
    $refIn = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', input('ref_no')) ?? '');
    if (!$hasBids) {
        if ($refIn === '') {
            $refIn = $p['ref_no'] ?? Bidding::nextPropertyRef();
        }
        if (DB::val('SELECT 1 FROM properties WHERE ref_no = ? AND id <> ?', [$refIn, $id ?: 0])) {
            $errors[] = 'Reference number already used by another property.';
        }
        $d['ref_no'] = $refIn;
    }

    if (!$p && $chk('is_published') && ($limitErr = License::checkLimit('properties'))) {
        $errors[] = $limitErr . ' Untick "Publish immediately" to save it as a draft.';
    }
    if (!$errors) {
        $reqIds = array_values(array_intersect(array_map('intval', (array) ($_POST['requirements'] ?? [])), array_map('intval', array_column($reqTypes, 'id'))));
        DB::begin();
        try {
            if (!$p) {
                $d += ['status' => 'upcoming', 'is_published' => $chk('is_published'), 'created_by' => $admin['id'], 'created_at' => now()];
                $id = DB::insert('properties', $d);
                Audit::log('property_created', 'property', $id, null, $d);
            } else {
                $old = array_intersect_key($p, $d);
                $changed = [];
                foreach ($d as $k => $v) {
                    if ((string) ($old[$k] ?? '') !== (string) ($v ?? '')) {
                        $changed[$k] = $v;
                    }
                }
                if ($changed) {
                    DB::update('properties', $changed + ['updated_by' => $admin['id'], 'updated_at' => now()], 'id = ?', [$id]);
                    $oldChanged = array_intersect_key($old, $changed);
                    Audit::log('property_modified', 'property', $id, $oldChanged, $changed);
                    if (isset($changed['starting_price'])) {
                        Audit::log('starting_price_changed', 'property', $id, ['starting_price' => $p['starting_price']], ['starting_price' => $changed['starting_price']]);
                    }
                    if (isset($changed['opening_at']) || isset($changed['closing_at'])) {
                        Audit::log('schedule_changed', 'property', $id, ['opening_at' => $p['opening_at'], 'closing_at' => $p['closing_at']],
                            ['opening_at' => $d['opening_at'] ?? $p['opening_at'], 'closing_at' => $d['closing_at'] ?? $p['closing_at'], 'reason' => 'Edited before bidding opened']);
                    }
                }
            }
            if (!$lockRules) {
                $oldReqs = $selectedReqs;
                DB::run('DELETE FROM property_requirements WHERE property_id = ?', [$id]);
                foreach ($reqIds as $rid) {
                    DB::insert('property_requirements', ['property_id' => $id, 'requirement_type_id' => $rid]);
                }
                sort($oldReqs);
                sort($reqIds);
                if ($p && $oldReqs !== $reqIds) {
                    Audit::log('property_requirements_changed', 'property', $id, $oldReqs, $reqIds);
                }
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        if ($p && isset($changed['closing_at']) && DB::val('SELECT 1 FROM property_bidders WHERE property_id = ? LIMIT 1', [$id])) {
            Bidding::notifyParticipants($id, 'schedule_change', 'Bidding schedule changed — ' . $d['name'], [
                'The bidding schedule has been updated before bidding opened.',
                Notifier::table(['Property' => $d['name'], 'Opening' => fmt_dt($d['opening_at']), 'Closing' => fmt_dt($d['closing_at'])]),
            ], 'property.php?ref=' . rawurlencode($d['ref_no'] ?? $p['ref_no']));
        }
        flash('success', $p ? 'Property saved.' : 'Property created. Now add photos and documents below.');
        redirect('admin/property_edit.php?id=' . $id);
    }
}

$v = static function (string $k, $default = '') use ($p) {
    if (is_post() && isset($_POST[$k])) {
        return is_string($_POST[$k]) ? $_POST[$k] : $default;
    }
    return $p[$k] ?? $default;
};
$flag = static function (string $k, int $default = 0) use ($p): bool {
    if (is_post()) {
        return !empty($_POST[$k]);
    }
    return $p ? (bool) (int) $p[$k] : (bool) $default;
};
$dtVal = static fn(?string $s): string => $s ? date('Y-m-d\TH:i', strtotime($s)) : '';
$images = $p ? DB::all('SELECT * FROM property_images WHERE property_id = ? ORDER BY sort_order, id', [$id]) : [];
$docs = $p ? DB::all('SELECT * FROM property_documents WHERE property_id = ? ORDER BY id', [$id]) : [];
$dis = static fn(bool $locked): string => $locked ? 'disabled' : '';

View::adminHeader($p ? 'Edit property ' . $p['ref_no'] : 'Add property');
?>
<?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($p): ?>
  <div class="page-actions"><div><?= status_badge($p['status']) ?> <?= (int) $p['is_published'] ? '<span class="badge badge-green">Published</span>' : '<span class="badge badge-grey">Not published</span>' ?></div>
    <div class="btn-row"><a class="btn btn-sm btn-outline" href="<?= e(url('admin/property_bids.php?id=' . $id)) ?>">Bids &amp; evaluation</a><a class="btn btn-sm btn-outline" href="<?= e(url('property.php?ref=' . rawurlencode($p['ref_no']))) ?>" target="_blank" rel="noopener">Preview</a><a class="btn btn-sm btn-outline" href="<?= e(url('qr.php?ref=' . rawurlencode($p['ref_no']))) ?>" target="_blank" rel="noopener">QR code</a></div></div>
  <?php if ($lockRules): ?><div class="alert alert-info">Bidding has started<?= $hasBids ? ' and bids exist' : '' ?>: pricing, bid rules, requirements and property terms are locked to protect fairness. <?= $lockSchedule && !$finished ? 'Schedule changes must be made through <a href="' . e(url('admin/property_bids.php?id=' . $id . '#schedule')) . '">Change schedule</a> (reason required, audited' . (Settings::bool('dual_auth_schedule', true) ? ', dual authorization' : '') . ').' : '' ?></div><?php endif; ?>
<?php endif; ?>

<form method="post" class="card">
  <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $id ?>"><input type="hidden" name="action" value="save">
  <h2>Property information</h2>
  <div class="form-row">
    <div class="form-group"><label class="form-label" for="name">Property / unit name <span class="req">*</span></label><input class="form-control" id="name" name="name" value="<?= e($v('name')) ?>" required maxlength="190"></div>
    <div class="form-group"><label class="form-label" for="ref_no">Unique property reference no.</label><input class="form-control" id="ref_no" name="ref_no" value="<?= e($v('ref_no')) ?>" maxlength="40" placeholder="Auto-generated if blank" <?= $dis($hasBids) ?>><span class="form-help">Letters, numbers and dashes. Locked once bids exist.</span></div>
  </div>
  <div class="form-row-3">
    <div class="form-group"><label class="form-label" for="property_type_id">Property type <span class="req">*</span></label><select class="form-control" id="property_type_id" name="property_type_id" required><option value="">Select…</option><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $v('property_type_id') === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label class="form-label" for="city">City (for location filter)</label><input class="form-control" id="city" name="city" value="<?= e($v('city')) ?>" maxlength="120"></div>
    <div class="form-group"><label class="form-label" for="floor_area">Floor area (sqm)</label><input class="form-control" id="floor_area" name="floor_area" value="<?= e($v('floor_area')) ?>" inputmode="decimal"></div>
  </div>
  <div class="form-group"><label class="form-label" for="location">Location / address <span class="req">*</span></label><input class="form-control" id="location" name="location" value="<?= e($v('location')) ?>" required maxlength="255"></div>
  <div class="form-group"><label class="form-label" for="description">Description</label><textarea class="form-control" id="description" name="description" rows="5"><?= e($v('description')) ?></textarea></div>
  <div class="form-row">
    <div class="form-group"><label class="form-label" for="specifications">Specifications</label><textarea class="form-control" id="specifications" name="specifications" rows="4" placeholder="e.g. 1 bedroom, 1 toilet & bath, balcony, floor 12, unit 1205"><?= e($v('specifications')) ?></textarea></div>
    <div class="form-group"><label class="form-label" for="contact_info">Contact information</label><textarea class="form-control" id="contact_info" name="contact_info" rows="4" placeholder="Name, phone, email of the bidding officer"><?= e($v('contact_info')) ?></textarea></div>
  </div>

  <h2 class="mt-2">Pricing &amp; schedule</h2>
  <div class="form-row">
    <div class="form-group"><label class="form-label" for="starting_price">Starting / minimum bid price (₱) <span class="req">*</span></label><input class="form-control" id="starting_price" name="starting_price" value="<?= e($v('starting_price')) ?>" inputmode="decimal" <?= $dis($lockRules) ?> required></div>
    <div class="form-group"><label class="form-label" for="min_increment">Minimum bid increment (₱)</label><input class="form-control" id="min_increment" name="min_increment" value="<?= e($v('min_increment', '0.00')) ?>" inputmode="decimal" <?= $dis($lockRules) ?>><span class="form-help">0 = not applicable.</span></div>
  </div>
  <div class="form-row">
    <div class="form-group"><label class="form-label" for="opening_at">Bid opening date/time (server time, <?= e(date('T')) ?>) <span class="req">*</span></label><input class="form-control" type="datetime-local" id="opening_at" name="opening_at" value="<?= e(is_post() ? input('opening_at') : $dtVal($p['opening_at'] ?? null)) ?>" <?= $dis($lockSchedule) ?> required></div>
    <div class="form-group"><label class="form-label" for="closing_at">Bid closing date/time <span class="req">*</span></label><input class="form-control" type="datetime-local" id="closing_at" name="closing_at" value="<?= e(is_post() ? input('closing_at') : $dtVal($p['closing_at'] ?? null)) ?>" <?= $dis($lockSchedule) ?> required></div>
  </div>

  <h2 class="mt-2">Bid rules</h2>
  <fieldset class="fieldset" <?= $dis($lockRules) ?>><legend>Bidding format</legend>
    <div class="form-row">
      <div class="form-group"><label class="form-label" for="bid_mode">Number of bids</label><select class="form-control" id="bid_mode" name="bid_mode"><option value="multiple" <?= $v('bid_mode', 'multiple') === 'multiple' ? 'selected' : '' ?>>Multiple / revised bids allowed</option><option value="single" <?= $v('bid_mode') === 'single' ? 'selected' : '' ?>>One bid only</option></select></div>
      <div class="form-group"><label class="form-label" for="gps_mode">GPS / location</label><select class="form-control" id="gps_mode" name="gps_mode"><option value="optional" <?= $v('gps_mode', 'optional') === 'optional' ? 'selected' : '' ?>>Optional (bidder chooses)</option><option value="required" <?= $v('gps_mode') === 'required' ? 'selected' : '' ?>>Required for this bidding event</option></select></div>
    </div>
    <label class="check"><input type="checkbox" name="higher_only" value="1" <?= $flag('higher_only', 1) ? 'checked' : '' ?>><span>Higher bids only (revisions must exceed the bidder's previous bid by at least the increment)</span></label>
    <label class="check"><input type="checkbox" name="allow_withdrawal" value="1" <?= $flag('allow_withdrawal') ? 'checked' : '' ?>><span>Bid withdrawal allowed before closing</span></label>
  </fieldset>
  <fieldset class="fieldset" <?= $dis($lockRules) ?>><legend>Visibility to bidders (identities are never shown)</legend>
    <label class="check"><input type="checkbox" name="show_ranking" value="1" <?= $flag('show_ranking') ? 'checked' : '' ?>><span>Bidders can see their own current ranking</span></label>
    <label class="check"><input type="checkbox" name="show_highest" value="1" <?= $flag('show_highest') ? 'checked' : '' ?>><span>Highest bid amount visible (open bidding; increment then applies over the current highest bid)</span></label>
    <label class="check"><input type="checkbox" name="show_bidder_count" value="1" <?= $flag('show_bidder_count') ? 'checked' : '' ?>><span>Number of competing bidders visible</span></label>
  </fieldset>
  <fieldset class="fieldset" <?= $dis($lockRules) ?>><legend>Anti-sniping / automatic extension</legend>
    <label class="check"><input type="checkbox" name="antisnipe_enabled" value="1" <?= $flag('antisnipe_enabled') ? 'checked' : '' ?> data-toggle-target="antisnipe-opts"><span>Enable automatic extension (disclosed to bidders on the property page)</span></label>
    <div class="form-row-3" id="antisnipe-opts">
      <div class="form-group"><label class="form-label" for="ast">Trigger period (final minutes)</label><input class="form-control" type="number" min="1" max="120" id="ast" name="antisnipe_trigger_min" value="<?= e($v('antisnipe_trigger_min', '5')) ?>"></div>
      <div class="form-group"><label class="form-label" for="ase">Extension duration (minutes)</label><input class="form-control" type="number" min="1" max="120" id="ase" name="antisnipe_extend_min" value="<?= e($v('antisnipe_extend_min', '5')) ?>"></div>
      <div class="form-group"><label class="form-label" for="asm">Maximum number of extensions</label><input class="form-control" type="number" min="0" max="100" id="asm" name="antisnipe_max_ext" value="<?= e($v('antisnipe_max_ext', '3')) ?>"></div>
    </div>
  </fieldset>
  <fieldset class="fieldset" <?= $dis($lockRules) ?>><legend>Bidder qualification requirements</legend>
    <label class="check"><input type="checkbox" name="require_approved_account" value="1" <?= $flag('require_approved_account', 1) ? 'checked' : '' ?>><span>Bidder account must be approved by Cityland</span></label>
    <label class="check"><input type="checkbox" name="require_prequalification" value="1" <?= $flag('require_prequalification') ? 'checked' : '' ?>><span>Bidders must request to join and be pre-qualified for this property</span></label>
    <div class="form-group"><span class="form-label">Required documents for this property</span>
      <?php foreach ($reqTypes as $r): ?><label class="check"><input type="checkbox" name="requirements[]" value="<?= (int) $r['id'] ?>" <?= in_array((int) $r['id'], is_post() ? array_map('intval', (array) ($_POST['requirements'] ?? [])) : $selectedReqs, true) ? 'checked' : '' ?>><span><?= e($r['name']) ?> <small class="muted">(<?= e($r['scope']) ?>)</small></span></label><?php endforeach; ?>
      <span class="form-help">Manage the list under <a href="<?= e(url('admin/requirements.php')) ?>">Requirements</a>.</span></div>
  </fieldset>
  <fieldset class="fieldset" <?= $dis($lockRules) ?>><legend>Bid security / reservation deposit <?= Settings::bool('bid_security_enabled') ? '' : '<small class="muted">(feature disabled in Settings — stored for future use)</small>' ?></legend>
    <label class="check"><input type="checkbox" name="deposit_required" value="1" <?= $flag('deposit_required') ? 'checked' : '' ?>><span>Require a bid security deposit before bidding</span></label>
    <div class="form-row">
      <div class="form-group"><label class="form-label" for="deposit_amount">Deposit amount (₱)</label><input class="form-control" id="deposit_amount" name="deposit_amount" value="<?= e($v('deposit_amount')) ?>" inputmode="decimal"></div>
      <div class="form-group"><label class="check mt-3"><input type="checkbox" name="deposit_refundable" value="1" <?= $flag('deposit_refundable', 1) ? 'checked' : '' ?>><span>Refundable</span></label></div>
    </div>
  </fieldset>

  <h2 class="mt-2">Property-specific terms and conditions</h2>
  <div class="form-group"><label class="form-label" for="terms">Terms (basic HTML allowed: p, ul, li, strong, h3…) <?= $p ? '<small class="muted">current version ' . (int) $p['terms_version'] . '</small>' : '' ?></label>
    <textarea class="form-control" id="terms" name="terms" rows="8" <?= $dis($lockRules) ?>><?= e($v('terms')) ?></textarea><span class="form-help">Editing the terms creates a new version number; bids record the version accepted.</span></div>

  <?php if (!$p): ?><label class="check"><input type="checkbox" name="is_published" value="1"><span>Publish immediately (visible on the public website)</span></label><?php endif; ?>
  <div class="btn-row mt-2"><button class="btn btn-primary btn-lg" type="submit"><?= $p ? 'Save changes' : 'Create property' ?></button><a class="btn btn-link" href="<?= e(url('admin/properties.php')) ?>">Cancel</a></div>
</form>

<?php if ($p): ?>
<div class="card" id="media">
  <h2>Photos</h2>
  <?php if ($images): ?>
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="image_order">
      <div class="img-admin-grid">
        <?php foreach ($images as $img): ?>
          <figure><img src="<?= e(url('file.php?t=img&id=' . $img['id'])) ?>" alt="">
            <figcaption><input class="form-control sm" name="captions[<?= (int) $img['id'] ?>]" value="<?= e($img['caption']) ?>" placeholder="Caption"></figcaption>
            <figcaption><label class="small">Order <input class="form-control sm" style="width:60px;display:inline-block" type="number" name="order[<?= (int) $img['id'] ?>]" value="<?= (int) $img['sort_order'] ?>"></label>
              <button class="btn btn-sm btn-link" type="submit" form="del-img-<?= (int) $img['id'] ?>">Delete</button></figcaption>
          </figure>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-sm btn-outline mt-1" type="submit">Save order &amp; captions</button>
    </form>
    <?php foreach ($images as $img): ?><form method="post" id="del-img-<?= (int) $img['id'] ?>" data-confirm="Delete this image?"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="delete_image"><input type="hidden" name="image_id" value="<?= (int) $img['id'] ?>"></form><?php endforeach; ?>
  <?php else: ?><p class="muted">No photos yet.</p><?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="mt-2 inline-actions"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="upload_images">
    <input class="form-control sm" type="file" name="images[]" accept=".jpg,.jpeg,.png,.webp" multiple required style="max-width:300px">
    <input class="form-control sm" name="caption" placeholder="Caption (optional)" style="max-width:220px">
    <button class="btn btn-sm btn-primary" type="submit">Upload photos</button></form>
  <p class="form-help">JPG, PNG or WEBP. Images are re-encoded and metadata (including camera GPS) is stripped.</p>

  <h2 class="mt-3">Supporting documents</h2>
  <?php if ($docs): ?><ul class="doc-list"><?php foreach ($docs as $d): ?>
    <li><span><a href="<?= e(url('file.php?t=pdoc&id=' . $d['id'])) ?>"><?= e($d['title']) ?></a> <small class="muted"><?= e($d['original_name']) ?> · <?= (int) $d['is_public'] ? 'public' : 'internal only' ?></small></span>
      <form method="post" data-confirm="Delete this document?"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="delete_doc"><input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>"><button class="btn btn-sm btn-link" type="submit">Delete</button></form></li>
  <?php endforeach; ?></ul><?php else: ?><p class="muted">No documents yet.</p><?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="mt-2 inline-actions"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="upload_doc">
    <input class="form-control sm" name="doc_title" placeholder="Document title (e.g. Floor plan)" required style="max-width:240px">
    <input class="form-control sm" type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" required style="max-width:260px">
    <label class="check mb-0"><input type="checkbox" name="doc_public" value="1" checked> Public download</label>
    <button class="btn btn-sm btn-primary" type="submit">Upload document</button></form>
</div>
<?php endif; ?>
<?php View::adminFooter();
