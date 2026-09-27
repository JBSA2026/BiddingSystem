<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$admin = Auth::requireAdmin('bids.view');
$id = query_int('id') ?: input_int('id');
$p = Bidding::property($id);
if (!$p) {
    abort(404);
}
$back = 'admin/property_bids.php?id=' . $id;

if (is_post()) {
    Csrf::verify();
    $action = input('action');
    try {
        switch ($action) {
            case 'close':
                Auth::requireAdmin('bidding.control');
                if (!Auth::confirmAdminPassword($admin, (string) ($_POST['confirm_password'] ?? ''))) {
                    throw new RuntimeException('Password confirmation failed.');
                }
                $reason = input('reason');
                if (mb_strlen($reason) < 5) {
                    throw new RuntimeException('Please state the reason for closing bidding early.');
                }
                Bidding::close($id, 'manual', null, $reason) || throw new RuntimeException('Bidding is not open.');
                flash('success', 'Bidding closed. Rankings are locked and the property is now under evaluation.');
                break;
            case 'cancel':
                Auth::requireAdmin('bidding.control');
                if (!Auth::confirmAdminPassword($admin, (string) ($_POST['confirm_password'] ?? ''))) {
                    throw new RuntimeException('Password confirmation failed.');
                }
                $reason = input('reason');
                if (mb_strlen($reason) < 5) {
                    throw new RuntimeException('Please state the reason for cancellation.');
                }
                Bidding::cancel($id, $reason);
                flash('success', 'Bidding cancelled and participants notified.');
                break;
            case 'schedule':
                Auth::requireAdmin('bidding.control');
                $reason = input('reason');
                $open = input('opening_at') !== '' ? date('Y-m-d H:i:00', strtotime(input('opening_at'))) : $p['opening_at'];
                $close = input('closing_at') !== '' ? date('Y-m-d H:i:00', strtotime(input('closing_at'))) : '';
                if (mb_strlen($reason) < 10) {
                    throw new RuntimeException('A reason (at least 10 characters) is required for any schedule change.');
                }
                if (!$close || strtotime($close) <= time()) {
                    throw new RuntimeException('The new closing time must be in the future. To end bidding now, use "Close bidding".');
                }
                if (Settings::bool('dual_auth_schedule', true)) {
                    if (DB::val("SELECT 1 FROM approval_requests WHERE property_id = ? AND action_type = 'schedule_change' AND status = 'pending'", [$id])) {
                        throw new RuntimeException('A schedule change request is already pending for this property.');
                    }
                    $rid = DB::insert('approval_requests', ['action_type' => 'schedule_change', 'property_id' => $id,
                        'payload' => json_encode(['opening_at' => $open, 'closing_at' => $close, 'old_opening_at' => $p['opening_at'], 'old_closing_at' => $p['closing_at']]),
                        'reason' => $reason, 'requested_by' => $admin['id'], 'requested_at' => now()]);
                    Audit::log('schedule_change_requested', 'property', $id, ['opening_at' => $p['opening_at'], 'closing_at' => $p['closing_at']], ['opening_at' => $open, 'closing_at' => $close, 'reason' => $reason, 'request_id' => $rid]);
                    Notifier::admins('schedule_approval', 'Schedule change needs approval: ' . $p['ref_no'], "{$admin['name']} requested to change the bidding schedule of {$p['name']} to close on " . fmt_dt($close) . ". Reason: {$reason}", 'admin/approvals.php');
                    flash('success', 'Schedule change request submitted. A second authorized officer must approve it (dual authorization).');
                } else {
                    Bidding::applyScheduleChange($id, $open, $close, $reason, (int) $admin['id']);
                    flash('success', 'Schedule updated, audited and participants notified.');
                }
                break;
            case 'eval':
                Auth::requireAdmin('evaluation.manage');
                Bidding::setEvalStatus($id, input_int('user_id'), input('eval_status'), mb_substr(input('remarks'), 0, 1000), $admin);
                flash('success', 'Evaluation status updated.');
                break;
            case 'access':
                Auth::requireAdmin('bidders.approve');
                $uid = input_int('user_id');
                $st = input('decision') === 'approved' ? 'approved' : 'rejected';
                $pb = DB::one('SELECT * FROM property_bidders WHERE property_id = ? AND user_id = ?', [$id, $uid]);
                if (!$pb) {
                    throw new RuntimeException('Request not found.');
                }
                DB::update('property_bidders', ['access_status' => $st], 'id = ?', [$pb['id']]);
                Audit::log('prequalification_' . $st, 'property_bidder', $pb['id'], ['access_status' => $pb['access_status']], ['access_status' => $st, 'property' => $p['ref_no'], 'user_id' => $uid]);
                $u = DB::one('SELECT * FROM users WHERE id = ?', [$uid]);
                Notifier::bidder($u, $st === 'approved' ? 'bidder_approved' : 'bidder_rejected', 'Pre-qualification ' . ($st === 'approved' ? 'approved' : 'not approved') . ' — ' . $p['name'], [
                    $st === 'approved' ? 'You have been pre-qualified to participate in the bidding for the property below.' : 'Your request to participate in the bidding for the property below was not approved.',
                    Notifier::table(['Property' => $p['name'] . ' (' . $p['ref_no'] . ')']),
                ], 'property.php?ref=' . rawurlencode($p['ref_no']));
                flash('success', 'Pre-qualification ' . $st . '.');
                break;
            case 'note':
                Auth::requireAdmin('notes.add');
                $note = input('note');
                if ($note !== '') {
                    $nid = DB::insert('admin_notes', ['entity_type' => 'property', 'entity_id' => $id, 'admin_id' => $admin['id'], 'note' => $note, 'created_at' => now()]);
                    Audit::log('internal_note_added', 'property', $id, null, ['note_id' => $nid]);
                    flash('success', 'Note added.');
                }
                break;
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect($back . (input('anchor') ? '#' . input('anchor') : ''));
}

$p = Bidding::property($id);
$isLocked = in_array($p['status'], ['closed', 'under_evaluation', 'awarded', 'cancelled'], true);
$snapshot = Bidding::snapshot($id);
$ranking = $snapshot ?: Bidding::ranking($id, false);
$recommended = $p['status'] === 'under_evaluation' ? Bidding::recommendedBidder($id) : null;
$sort = query('sort', 'time');
$historyOrder = $sort === 'amount' ? 'b.amount DESC, b.submitted_at ASC' : 'b.id DESC';
$history = DB::all("SELECT b.*, u.full_name, u.bidder_no FROM bids b JOIN users u ON u.id = b.user_id WHERE b.property_id = ? ORDER BY {$historyOrder}", [$id]);
$requests = DB::all("SELECT pb.*, u.full_name, u.bidder_no, u.company_name, u.verification_status FROM property_bidders pb JOIN users u ON u.id = pb.user_id WHERE pb.property_id = ? AND pb.access_status IN ('pending','approved','rejected') ORDER BY pb.joined_at", [$id]);
$notes = DB::all("SELECT n.*, a.name FROM admin_notes n JOIN admins a ON a.id = n.admin_id WHERE n.entity_type = 'property' AND n.entity_id = ? ORDER BY n.id DESC", [$id]);
$awards = DB::all('SELECT aw.*, u.full_name, u.bidder_no, r.name AS rec_name, ap.name AS app_name FROM awards aw JOIN users u ON u.id = aw.winning_user_id JOIN admins r ON r.id = aw.recommended_by LEFT JOIN admins ap ON ap.id = aw.approved_by WHERE aw.property_id = ? ORDER BY aw.id DESC', [$id]);
$chain = Bidding::verifyChain($id);
$pendingSched = DB::one("SELECT * FROM approval_requests WHERE property_id = ? AND action_type = 'schedule_change' AND status = 'pending'", [$id]);
$docsNeedApproval = Settings::bool('require_docs_approved', true);
$propReqIds = array_map('intval', array_column(DB::all('SELECT requirement_type_id FROM property_requirements WHERE property_id = ?', [$id]), 'requirement_type_id'));
$regReqIds = array_map('intval', array_column(DB::all("SELECT id FROM requirement_types WHERE scope='registration' AND is_required=1 AND is_active=1"), 'id'));
$docSummary = static function (int $uid) use ($propReqIds, $regReqIds): string {
    $all = array_unique(array_merge($regReqIds, $propReqIds));
    if (!$all) {
        return 'none required';
    }
    $ok = 0;
    foreach ($all as $rid) {
        if (Eligibility::docStatus($uid, $rid) === 'approved') {
            $ok++;
        }
    }
    return $ok . '/' . count($all) . ' approved';
};
$mapLink = static fn($lat, $lng) => 'https://www.openstreetmap.org/?mlat=' . rawurlencode((string) $lat) . '&mlon=' . rawurlencode((string) $lng) . '#map=17/' . rawurlencode((string) $lat) . '/' . rawurlencode((string) $lng);

View::adminHeader($p['ref_no'] . ' — Bids & Evaluation');
?>
<div class="card">
  <div class="card-header">
    <div><h2 class="mb-0"><?= e($p['name']) ?></h2><span class="muted"><?= e($p['ref_no']) ?> · <?= e($p['type_name']) ?> · <?= e($p['location']) ?></span></div>
    <div class="btn-row"><?= status_badge($p['status']) ?>
      <?php if (can('properties.manage')): ?><a class="btn btn-sm btn-outline" href="<?= e(url('admin/property_edit.php?id=' . $id)) ?>">Edit property</a><?php endif; ?>
      <?php if (can('reports.export')): ?><a class="btn btn-sm btn-outline" href="<?= e(url('admin/reports.php?export=property_bids&property_id=' . $id)) ?>">Export bids (CSV)</a><?php endif; ?>
      <button class="btn btn-sm btn-outline" type="button" data-print>Print</button></div>
  </div>
  <div class="grid grid-4">
    <div><div class="muted small">Starting price</div><strong><?= e(money($p['starting_price'])) ?></strong><br><small class="muted">Increment <?= e(money($p['min_increment'])) ?></small></div>
    <div><div class="muted small">Opening</div><strong><?= e(fmt_dt($p['opening_at'])) ?></strong></div>
    <div><div class="muted small">Closing</div><strong><?= e(fmt_dt($p['closing_at'], 'M j, Y g:i:s A')) ?></strong><?php if ($p['closing_at'] !== $p['original_closing_at']): ?><br><small class="muted">Original: <?= e(fmt_dt($p['original_closing_at'])) ?> · <?= (int) $p['extensions_used'] ?> auto-extension(s)</small><?php endif; ?></div>
    <div><div class="muted small">Highest bid</div><strong><?= $ranking ? e(money($ranking[0]['amount'])) : '—' ?></strong><br><small class="muted"><?= count($ranking) ?> active bidder(s) · <?= count($history) ?> bid record(s)</small></div>
  </div>
  <p class="small mt-2 mb-0">Bid integrity chain: <?= $chain['ok'] ? '<span class="badge badge-green">Verified</span> ' . (int) $chain['checked'] . ' record(s) intact' : '<span class="badge badge-red">Broken</span> at ' . e((string) $chain['broken_at']) . ' — investigate immediately' ?></p>
</div>

<div class="card">
  <div class="card-header"><h2><?= $snapshot ? 'Final ranking (locked at close)' : 'Live ranking' ?></h2>
    <small class="muted"><?= $snapshot ? 'Locked ' . e(fmt_dt($snapshot[0]['locked_at'], 'M j, Y g:i:s A')) . ' — cannot be modified.' : 'Highest amount first; ties go to the earliest server timestamp.' ?> Ranking is for evaluation only and does not award the property.</small></div>
  <?php if ($recommended): ?><div class="alert alert-info">System recommendation (not an award): highest <strong>qualified</strong> bidder is <strong><?= e($recommended['full_name']) ?></strong> (<?= e($recommended['bidder_no']) ?>) at <strong><?= e(money($recommended['amount'])) ?></strong>, rank #<?= (int) ($recommended['rank_no'] ?? $recommended['rank']) ?>.
    <?php if (can('award.recommend')): ?> <a href="<?= e(url('admin/award.php?property=' . $id)) ?>">Proceed to award workflow &raquo;</a><?php endif; ?></div>
  <?php elseif ($p['status'] === 'under_evaluation'): ?><div class="alert alert-warning">No bidder has been marked <strong>Qualified</strong> yet. Review each bidder's identity, documents, eligibility, payment capability and compliance, then set their evaluation status.</div><?php endif; ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>#</th><th>Bidder</th><th class="num">Bid amount</th><th>Timestamp (server)</th><th>Bid ref</th><th>Verification</th><th>Documents</th><th>GPS / IP</th><th>Evaluation</th></tr></thead>
    <tbody>
    <?php foreach ($ranking as $r): $rank = (int) ($r['rank_no'] ?? $r['rank']); $time = $r['bid_time'] ?? $r['submitted_at']; ?>
      <tr class="<?= $rank === 1 ? 'highlight' : '' ?><?= ($r['eval_status'] ?? '') === 'disqualified' ? ' muted-row' : '' ?>">
        <td><strong><?= $rank ?></strong></td>
        <td><a href="<?= e(url('admin/bidder_view.php?id=' . $r['user_id'])) ?>"><strong><?= e($r['full_name']) ?></strong></a><?= $r['company_name'] ? '<br><small>' . e($r['company_name']) . '</small>' : '' ?><br><small class="muted"><?= e($r['bidder_no']) ?> · <?= e($r['email']) ?> · <?= e($r['mobile']) ?></small>
          <?= (int) $r['is_flagged'] ? '<br><span class="badge badge-amber">Flagged</span>' : '' ?><?= (int) $r['is_blacklisted'] ? ' <span class="badge badge-red">Blacklisted</span>' : '' ?></td>
        <td class="num"><strong><?= e(money($r['amount'])) ?></strong></td>
        <td class="nowrap"><small><?= e(fmt_dt_precise($time)) ?></small></td>
        <td class="nowrap"><small><?= e($r['bid_ref']) ?></small></td>
        <td><?= status_badge($r['verification_status']) ?></td>
        <td><small><?= e($docSummary((int) $r['user_id'])) ?></small></td>
        <td><small><?= $r['gps_status'] === 'granted' ? '<a href="' . e($mapLink($r['gps_lat'], $r['gps_lng'])) . '" target="_blank" rel="noopener noreferrer">' . e($r['gps_lat'] . ', ' . $r['gps_lng']) . '</a> ±' . e((string) $r['gps_accuracy']) . 'm' : e(status_label($r['gps_status'])) ?><br><?= e($r['ip_address']) ?></small></td>
        <td>
          <?= status_badge($r['eval_status'] ?? 'under_review') ?>
          <?php if ($r['eval_remarks']): ?><br><small><?= e($r['eval_remarks']) ?></small><?php endif; ?>
          <?php if (can('evaluation.manage') && in_array($p['status'], ['under_evaluation', 'closed'], true)): ?>
            <details class="mt-1"><summary class="small">Update</summary>
              <form method="post" class="mt-1"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="eval"><input type="hidden" name="user_id" value="<?= (int) $r['user_id'] ?>">
                <select class="form-control sm" name="eval_status"><?php foreach (['under_review', 'qualified', 'disqualified'] as $s): ?><option value="<?= $s ?>" <?= ($r['eval_status'] ?? '') === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?></select>
                <input class="form-control sm mt-1" name="remarks" placeholder="Remarks (identity, documents, eligibility, payment capability, compliance…)" maxlength="1000">
                <button class="btn btn-sm btn-primary mt-1" type="submit">Save</button></form></details>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$ranking): ?><tr><td colspan="9" class="muted">No bids.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php if ($awards): ?>
<div class="card"><h2>Award records</h2>
  <div class="table-wrap"><table class="table"><thead><tr><th>Status</th><th>Winning bidder</th><th>Recommended</th><th>Approval</th><th>Reference / remarks</th><th>Document</th></tr></thead><tbody>
  <?php foreach ($awards as $a): ?><tr><td><?= status_badge($a['status']) ?></td><td><?= e($a['full_name']) ?><br><small class="muted"><?= e($a['bidder_no']) ?></small></td>
    <td><small><?= e($a['rec_name']) ?><br><?= e(fmt_dt($a['recommended_at'])) ?></small></td><td><small><?= e($a['app_name'] ?? '—') ?><br><?= e(fmt_dt($a['approved_at'])) ?></small></td>
    <td><small><?= e($a['approval_reference'] ?? '') ?><br><?= e($a['approval_remarks'] ?? '') ?></small></td>
    <td><?= $a['supporting_doc_path'] ? '<a href="' . e(url('file.php?t=award&id=' . $a['id'])) . '">' . e($a['supporting_doc_name']) . '</a>' : '—' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if (can('award.recommend') || can('award.approve')): ?><a class="btn btn-sm btn-outline mt-1" href="<?= e(url('admin/award.php?property=' . $id)) ?>">Open award workflow</a><?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><h2>Complete bid history</h2>
    <div class="btn-row"><a class="btn btn-sm <?= $sort !== 'amount' ? 'btn-primary' : 'btn-outline' ?>" href="<?= e(url($back . '&sort=time')) ?>">Newest first</a><a class="btn btn-sm <?= $sort === 'amount' ? 'btn-primary' : 'btn-outline' ?>" href="<?= e(url($back . '&sort=amount')) ?>">Highest to lowest</a></div></div>
  <p class="small muted">Every submission is kept permanently — revisions and withdrawals are separate records. Nothing is overwritten.</p>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Time (server)</th><th>Bid ref</th><th>Bidder</th><th>Type</th><th class="num">Amount</th><th>GPS</th><th>IP / device</th><th>Terms</th></tr></thead>
    <tbody><?php foreach ($history as $b): ?>
      <tr><td class="nowrap"><small><?= e(fmt_dt_precise($b['submitted_at'])) ?></small></td><td class="nowrap"><small><?= e($b['bid_ref']) ?></small><?= (int) $b['triggered_extension'] ? '<br><span class="badge badge-amber">Extended close</span>' : '' ?></td>
        <td><?= e($b['full_name']) ?><br><small class="muted"><?= e($b['bidder_no']) ?></small></td><td><?= e(status_label($b['bid_type'])) ?></td><td class="num"><?= e(money($b['amount'])) ?></td>
        <td><small><?= $b['gps_status'] === 'granted' ? '<a href="' . e($mapLink($b['gps_lat'], $b['gps_lng'])) . '" target="_blank" rel="noopener noreferrer">' . e($b['gps_lat'] . ', ' . $b['gps_lng']) . '</a><br>±' . e((string) $b['gps_accuracy']) . ' m @ ' . e(fmt_dt($b['gps_captured_at'], 'g:i:s A')) : e($b['gps_note'] ?: status_label($b['gps_status'])) ?></small></td>
        <td><small><?= e($b['ip_address']) ?><br><span class="muted" title="<?= e($b['user_agent']) ?>"><?= e(mb_substr((string) $b['device_info'], 0, 60)) ?></span></small></td>
        <td><small>T&amp;C v<?= e($b['terms_version']) ?> / P v<?= (int) $b['property_terms_version'] ?></small></td></tr>
    <?php endforeach; ?>
    <?php if (!$history): ?><tr><td colspan="8" class="muted">No bids recorded.</td></tr><?php endif; ?></tbody>
  </table></div>
</div>

<?php if ((int) $p['require_prequalification'] || $requests): ?>
<div class="card" id="participants"><h2>Pre-qualification requests</h2>
  <div class="table-wrap"><table class="table"><thead><tr><th>Bidder</th><th>Account</th><th>Requested</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($requests as $r): ?><tr><td><a href="<?= e(url('admin/bidder_view.php?id=' . $r['user_id'])) ?>"><?= e($r['full_name']) ?></a><br><small class="muted"><?= e($r['bidder_no']) ?></small></td><td><?= status_badge($r['verification_status']) ?></td><td><?= e(fmt_dt($r['joined_at'])) ?></td><td><?= status_badge($r['access_status']) ?></td>
    <td><?php if (can('bidders.approve') && in_array($p['status'], ['upcoming', 'open'], true)): ?><form method="post" class="inline-actions"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="access"><input type="hidden" name="anchor" value="participants"><input type="hidden" name="user_id" value="<?= (int) $r['user_id'] ?>">
      <?php if ($r['access_status'] !== 'approved'): ?><button class="btn btn-sm btn-success" name="decision" value="approved">Approve</button><?php endif; ?>
      <?php if ($r['access_status'] !== 'rejected'): ?><button class="btn btn-sm btn-outline" name="decision" value="rejected">Reject</button><?php endif; ?></form><?php endif; ?></td></tr><?php endforeach; ?>
  <?php if (!$requests): ?><tr><td colspan="5" class="muted">No requests.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php endif; ?>

<div class="grid grid-2">
<?php if (can('bidding.control') && in_array($p['status'], ['upcoming', 'open'], true)): ?>
  <div class="card" id="schedule"><h2>Change schedule</h2>
    <?php if ($pendingSched): $pl = json_decode($pendingSched['payload'], true); ?>
      <div class="alert alert-warning">A change to close on <strong><?= e(fmt_dt($pl['closing_at'])) ?></strong> is awaiting approval (requested <?= e(fmt_dt($pendingSched['requested_at'])) ?>).</div>
    <?php else: ?>
      <p class="small muted">Schedule changes are never silent: a reason is required, the change is recorded in the audit trail<?= Settings::bool('dual_auth_schedule', true) ? ', <strong>a second authorized officer must approve it</strong>,' : '' ?> and all participants are notified.</p>
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="schedule"><input type="hidden" name="anchor" value="schedule">
        <?php if ($p['status'] === 'upcoming'): ?><div class="form-group"><label class="form-label" for="so">New opening</label><input class="form-control" type="datetime-local" id="so" name="opening_at" value="<?= e(date('Y-m-d\TH:i', strtotime($p['opening_at']))) ?>"></div><?php endif; ?>
        <div class="form-group"><label class="form-label" for="sc">New closing</label><input class="form-control" type="datetime-local" id="sc" name="closing_at" value="<?= e(date('Y-m-d\TH:i', strtotime($p['closing_at']))) ?>" required></div>
        <div class="form-group"><label class="form-label" for="sr">Reason (required)</label><textarea class="form-control" id="sr" name="reason" required minlength="10" rows="3"></textarea></div>
        <button class="btn btn-primary" type="submit"><?= Settings::bool('dual_auth_schedule', true) ? 'Submit for approval' : 'Apply change' ?></button></form>
    <?php endif; ?>
  </div>
  <div class="card danger-zone"><h2>Close or cancel bidding</h2>
    <?php if ($p['status'] === 'open'): ?>
    <form method="post" class="mb-3" data-confirm="Close bidding NOW? New bids will be disabled and rankings locked."><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="close">
      <div class="form-group"><label class="form-label" for="cr">Reason for early close</label><input class="form-control" id="cr" name="reason" required minlength="5"></div>
      <div class="form-group"><label class="form-label" for="cp">Confirm your password</label><input class="form-control" type="password" id="cp" name="confirm_password" required autocomplete="current-password"></div>
      <button class="btn btn-primary" type="submit">Close bidding now</button></form>
    <?php endif; ?>
    <form method="post" data-confirm="Cancel this bidding event? All participants will be notified. This cannot be undone."><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="cancel">
      <div class="form-group"><label class="form-label" for="xr">Reason for cancellation</label><input class="form-control" id="xr" name="reason" required minlength="5"></div>
      <div class="form-group"><label class="form-label" for="xp">Confirm your password</label><input class="form-control" type="password" id="xp" name="confirm_password" required autocomplete="current-password"></div>
      <button class="btn btn-danger" type="submit">Cancel bidding</button></form>
  </div>
<?php elseif (can('bidding.control') && $p['status'] === 'under_evaluation'): ?>
  <div class="card danger-zone"><h2>Cancel bidding</h2>
    <form method="post" data-confirm="Cancel this bidding event? All participants will be notified."><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="cancel">
      <div class="form-group"><label class="form-label" for="xr">Reason</label><input class="form-control" id="xr" name="reason" required minlength="5"></div>
      <div class="form-group"><label class="form-label" for="xp">Confirm your password</label><input class="form-control" type="password" id="xp" name="confirm_password" required></div>
      <button class="btn btn-danger" type="submit">Cancel bidding</button></form></div>
<?php endif; ?>
  <div class="card"><h2>Internal notes</h2>
    <?php if (can('notes.add')): ?><form method="post" class="mb-2"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="note"><textarea class="form-control" name="note" rows="2" required placeholder="Visible to administrators only. Notes cannot be edited or deleted."></textarea><button class="btn btn-sm btn-primary mt-1" type="submit">Add note</button></form><?php endif; ?>
    <ul class="timeline"><?php foreach ($notes as $n): ?><li><small class="muted"><?= e($n['name']) ?> · <?= e(fmt_dt($n['created_at'])) ?></small><br><?= nl2br(e($n['note'])) ?></li><?php endforeach; ?></ul>
    <?php if (!$notes): ?><p class="muted small">No notes.</p><?php endif; ?>
  </div>
</div>
<?php View::adminFooter();
