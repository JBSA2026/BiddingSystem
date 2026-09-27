<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$admin = Auth::requireAdmin('bidders.view');
$uid = query_int('id') ?: input_int('id');
$u = DB::one('SELECT * FROM users WHERE id = ?', [$uid]);
if (!$u) {
    abort(404);
}
$back = 'admin/bidder_view.php?id=' . $uid;

if (is_post()) {
    Csrf::verify();
    $action = input('action');
    $remarks = mb_substr(input('remarks'), 0, 500);
    try {
        switch ($action) {
            case 'qualify':
                Auth::requireAdmin('bidders.approve');
                $decision = input('decision') === 'approved' ? 'approved' : 'rejected';
                if ($decision === 'rejected' && mb_strlen($remarks) < 5) {
                    throw new RuntimeException('Please give the reason for rejection (it is sent to the bidder).');
                }
                if ($decision === 'approved' && !$u['email_verified_at']) {
                    throw new RuntimeException('The bidder has not verified their email address yet.');
                }
                DB::update('users', ['verification_status' => $decision, 'verification_remarks' => $remarks ?: null, 'verified_by' => $admin['id'], 'verified_at' => now()], 'id = ?', [$uid]);
                Audit::log($decision === 'approved' ? 'bidder_approved' : 'bidder_rejected', 'user', $uid, ['verification_status' => $u['verification_status']], ['verification_status' => $decision, 'remarks' => $remarks]);
                Notifier::bidder($u, $decision === 'approved' ? 'bidder_approved' : 'bidder_rejected', $decision === 'approved' ? 'Your bidder account has been approved' : 'Your bidder registration was not approved', $decision === 'approved'
                    ? ['Good news! Cityland has reviewed and approved your bidder registration. You may now submit bids on open properties (subject to any property-specific requirements).']
                    : ['After review, Cityland was unable to approve your bidder registration at this time.', 'Reason: ' . $remarks, 'You may update your documents and contact Cityland for assistance.'],
                    'dashboard.php');
                flash('success', 'Bidder ' . $decision . ' and notified.');
                break;
            case 'doc':
                Auth::requireAdmin('bidders.manage');
                $doc = DB::one('SELECT d.*, rt.name AS req_name FROM bidder_documents d JOIN requirement_types rt ON rt.id = d.requirement_type_id WHERE d.id = ? AND d.user_id = ?', [input_int('doc_id'), $uid]);
                if (!$doc) {
                    throw new RuntimeException('Document not found.');
                }
                $st = input('decision') === 'approved' ? 'approved' : 'rejected';
                DB::update('bidder_documents', ['status' => $st, 'remarks' => $remarks ?: null, 'reviewed_by' => $admin['id'], 'reviewed_at' => now()], 'id = ?', [$doc['id']]);
                Audit::log('bidder_document_' . $st, 'bidder_document', $doc['id'], ['status' => $doc['status']], ['status' => $st, 'requirement' => $doc['req_name'], 'remarks' => $remarks, 'user_id' => $uid]);
                if ($st === 'rejected') {
                    Notifier::bidder($u, 'document_requirement', 'Document requirement: please re-upload', [
                        'The following document could not be accepted:', Notifier::table(['Document' => $doc['req_name'], 'Reason' => $remarks ?: 'Please upload a clear, valid copy.']),
                        'Please upload a new copy from your documents page.'], 'documents.php', 'Upload document');
                }
                flash('success', 'Document ' . $st . '.');
                break;
            case 'request_doc':
                Auth::requireAdmin('bidders.manage');
                $req = DB::one('SELECT * FROM requirement_types WHERE id = ?', [input_int('requirement_id')]);
                if (!$req) {
                    throw new RuntimeException('Select a requirement.');
                }
                Audit::log('document_requested', 'user', $uid, null, ['requirement' => $req['name'], 'message' => $remarks]);
                Notifier::bidder($u, 'document_requirement', 'Document requirement: ' . $req['name'], [
                    'Cityland requests the following document to complete your qualification:',
                    Notifier::table(['Document' => $req['name'], 'Details' => $req['description'] ?: '—', 'Note from Cityland' => $remarks ?: '—']),
                ], 'documents.php', 'Upload document');
                flash('success', 'Document request sent to bidder.');
                break;
            case 'flag':
                Auth::requireAdmin('bidders.manage');
                $type = in_array(input('flag_type'), ['suspicious', 'watchlist', 'duplicate', 'manual'], true) ? input('flag_type') : 'manual';
                if (mb_strlen($remarks) < 3) {
                    throw new RuntimeException('Enter details for the flag.');
                }
                Bidding::flag($uid, $type, $remarks, (int) $admin['id']);
                flash('success', 'Bidder flagged.');
                break;
            case 'resolve_flag':
                Auth::requireAdmin('bidders.manage');
                $fl = DB::one('SELECT * FROM bidder_flags WHERE id = ? AND user_id = ? AND resolved_at IS NULL', [input_int('flag_id'), $uid]);
                if ($fl) {
                    DB::update('bidder_flags', ['resolved_by' => $admin['id'], 'resolved_at' => now(), 'resolution' => $remarks ?: 'Resolved'], 'id = ?', [$fl['id']]);
                    $open = (int) DB::val('SELECT COUNT(*) FROM bidder_flags WHERE user_id = ? AND resolved_at IS NULL', [$uid]);
                    DB::update('users', ['is_flagged' => $open > 0 ? 1 : 0], 'id = ?', [$uid]);
                    Audit::log('bidder_flag_resolved', 'user', $uid, ['flag_id' => $fl['id'], 'details' => $fl['details']], ['resolution' => $remarks]);
                    flash('success', 'Flag resolved.');
                }
                break;
            case 'blacklist':
                Auth::requireAdmin('bidders.manage');
                $on = input('value') === '1';
                if ($on && mb_strlen($remarks) < 5) {
                    throw new RuntimeException('Please enter the reason for blacklisting.');
                }
                DB::update('users', ['is_blacklisted' => $on ? 1 : 0, 'blacklist_reason' => $on ? $remarks : null], 'id = ?', [$uid]);
                if ($on) {
                    DB::insert('bidder_flags', ['user_id' => $uid, 'flag_type' => 'blacklist', 'details' => $remarks, 'source' => 'admin', 'created_by' => $admin['id'], 'created_at' => now()]);
                }
                Audit::log($on ? 'bidder_blacklisted' : 'bidder_unblacklisted', 'user', $uid, ['is_blacklisted' => $u['is_blacklisted']], ['is_blacklisted' => $on ? 1 : 0, 'reason' => $remarks]);
                flash('success', $on ? 'Bidder blacklisted. They can no longer sign in or bid.' : 'Bidder removed from blacklist.');
                break;
            case 'active':
                Auth::requireAdmin('bidders.manage');
                $on = input('value') === '1';
                DB::update('users', ['is_active' => $on ? 1 : 0], 'id = ?', [$uid]);
                Audit::log($on ? 'bidder_activated' : 'bidder_deactivated', 'user', $uid, ['is_active' => $u['is_active']], ['is_active' => $on ? 1 : 0, 'remarks' => $remarks]);
                flash('success', 'Account ' . ($on ? 'activated' : 'deactivated') . '.');
                break;
            case 'note':
                Auth::requireAdmin('notes.add');
                $note = input('note');
                if ($note !== '') {
                    $nid = DB::insert('admin_notes', ['entity_type' => 'bidder', 'entity_id' => $uid, 'admin_id' => $admin['id'], 'note' => $note, 'created_at' => now()]);
                    Audit::log('internal_note_added', 'user', $uid, null, ['note_id' => $nid]);
                    flash('success', 'Note added.');
                }
                break;
            case 'resend_verification':
                Auth::requireAdmin('bidders.manage');
                Verification::sendEmail($u);
                flash('success', 'Verification email re-sent.');
                break;
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect($back);
}

$docs = DB::all('SELECT d.*, rt.name AS req_name, a.name AS reviewer FROM bidder_documents d JOIN requirement_types rt ON rt.id = d.requirement_type_id LEFT JOIN admins a ON a.id = d.reviewed_by WHERE d.user_id = ? ORDER BY d.id DESC', [$uid]);
$flags = DB::all('SELECT f.*, a.name AS by_name, r.name AS res_name FROM bidder_flags f LEFT JOIN admins a ON a.id = f.created_by LEFT JOIN admins r ON r.id = f.resolved_by WHERE f.user_id = ? ORDER BY f.id DESC', [$uid]);
$bids = DB::all('SELECT b.*, p.ref_no, p.name AS pname FROM bids b JOIN properties p ON p.id = b.property_id WHERE b.user_id = ? ORDER BY b.id DESC', [$uid]);
$notes = DB::all("SELECT n.*, a.name FROM admin_notes n JOIN admins a ON a.id = n.admin_id WHERE n.entity_type = 'bidder' AND n.entity_id = ? ORDER BY n.id DESC", [$uid]);
$consents = DB::all('SELECT * FROM consents WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$uid]);
$logins = DB::all("SELECT action, ip_address, user_agent, created_at FROM audit_logs WHERE entity_type = 'bidder' AND entity_id = ? AND action LIKE 'login%' ORDER BY id DESC LIMIT 20", [$uid]);
$reqTypes = DB::all('SELECT * FROM requirement_types WHERE is_active = 1 ORDER BY sort_order');
$verifier = $u['verified_by'] ? DB::val('SELECT name FROM admins WHERE id = ?', [$u['verified_by']]) : null;
$related = [];
if ($u['device_hash']) {
    $related = array_merge($related, DB::all("SELECT id, bidder_no, full_name, 'same device' AS why FROM users WHERE device_hash = ? AND id <> ?", [$u['device_hash'], $uid]));
}
if ($u['registration_ip']) {
    $related = array_merge($related, DB::all("SELECT id, bidder_no, full_name, 'same registration IP' AS why FROM users WHERE registration_ip = ? AND id <> ? LIMIT 10", [$u['registration_ip'], $uid]));
}

View::adminHeader('Bidder ' . $u['bidder_no']);
?>
<div class="layout-main-side">
  <div>
    <div class="card">
      <div class="card-header"><div><h2 class="mb-0"><?= e($u['full_name']) ?></h2><span class="muted"><?= e($u['bidder_no']) ?><?= $u['company_name'] ? ' · ' . e($u['company_name']) : '' ?></span></div>
        <div><?= status_badge($u['verification_status']) ?> <?= (int) $u['is_flagged'] ? '<span class="badge badge-amber">Flagged</span>' : '' ?> <?= (int) $u['is_blacklisted'] ? '<span class="badge badge-red">Blacklisted</span>' : '' ?> <?= (int) $u['is_active'] ? '' : '<span class="badge badge-grey">Inactive</span>' ?></div></div>
      <table class="kv">
        <tr><th>Complete address</th><td><?= e($u['address_line']) ?><?= $u['barangay'] ? ', ' . e($u['barangay']) : '' ?>, <?= e($u['city']) ?>, <?= e($u['province']) ?> <?= e($u['postal_code']) ?></td></tr>
        <tr><th>Email</th><td><?= e($u['email']) ?> <?= $u['email_verified_at'] ? status_badge('verified') . ' <small class="muted">' . e(fmt_dt($u['email_verified_at'])) . '</small>' : status_badge('pending') ?></td></tr>
        <tr><th>Mobile</th><td><?= e($u['mobile']) ?> <?= $u['mobile_verified_at'] ? status_badge('verified') : (Eligibility::mobileOtpRequired() ? status_badge('pending') : '<small class="muted">SMS OTP not enabled</small>') ?></td></tr>
        <tr><th>Government ID</th><td><?= e($u['id_type']) ?> · ending <strong><?= e($u['id_number_last4']) ?></strong></td></tr>
        <tr><th>Registered</th><td><?= e(fmt_dt($u['created_at'])) ?> from IP <?= e($u['registration_ip']) ?></td></tr>
        <tr><th>Last sign-in</th><td><?= e(fmt_dt($u['last_login_at'])) ?> <?= $u['last_login_ip'] ? 'from ' . e($u['last_login_ip']) : '' ?></td></tr>
        <tr><th>Qualification decision</th><td><?= status_badge($u['verification_status']) ?> <?= $verifier ? 'by ' . e($verifier) . ' on ' . e(fmt_dt($u['verified_at'])) : '' ?><?= $u['verification_remarks'] ? '<br><small>' . e($u['verification_remarks']) . '</small>' : '' ?></td></tr>
        <?php if ((int) $u['is_blacklisted']): ?><tr><th>Blacklist reason</th><td><?= e($u['blacklist_reason']) ?></td></tr><?php endif; ?>
      </table>
    </div>

    <div class="card"><h2>Documents</h2>
      <div class="table-wrap"><table class="table"><thead><tr><th>Uploaded</th><th>Requirement</th><th>File</th><th>Status</th><th>Review</th></tr></thead><tbody>
      <?php foreach ($docs as $d): ?><tr><td class="nowrap"><small><?= e(fmt_dt($d['created_at'])) ?></small></td><td><?= e($d['req_name']) ?></td>
        <td><a href="<?= e(url('file.php?t=bdoc&id=' . $d['id'] . '&inline=1')) ?>" target="_blank" rel="noopener"><?= e($d['original_name']) ?></a><br><small class="muted"><?= e(number_format(((int) $d['file_size']) / 1024)) ?> KB</small></td>
        <td><?= status_badge($d['status']) ?><?= $d['reviewer'] ? '<br><small class="muted">' . e($d['reviewer']) . '</small>' : '' ?><?= $d['remarks'] ? '<br><small>' . e($d['remarks']) . '</small>' : '' ?></td>
        <td><?php if (can('bidders.manage')): ?><form method="post" class="inline-actions"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $uid ?>"><input type="hidden" name="action" value="doc"><input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>">
          <input class="form-control sm" name="remarks" placeholder="Remarks" style="max-width:150px"><button class="btn btn-sm btn-success" name="decision" value="approved">Approve</button><button class="btn btn-sm btn-outline" name="decision" value="rejected">Reject</button></form><?php endif; ?></td></tr><?php endforeach; ?>
      <?php if (!$docs): ?><tr><td colspan="5" class="muted">No documents uploaded.</td></tr><?php endif; ?>
      </tbody></table></div>
      <?php if (can('bidders.manage')): ?>
        <form method="post" class="inline-actions mt-2"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $uid ?>"><input type="hidden" name="action" value="request_doc">
          <select class="form-control sm" name="requirement_id" required style="max-width:240px"><option value="">Request a document…</option><?php foreach ($reqTypes as $r): ?><option value="<?= (int) $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select>
          <input class="form-control sm" name="remarks" placeholder="Message to bidder" style="max-width:260px"><button class="btn btn-sm btn-outline" type="submit">Send request</button></form>
      <?php endif; ?>
    </div>

    <div class="card"><h2>Bids</h2>
      <div class="table-wrap"><table class="table"><thead><tr><th>Time</th><th>Bid ref</th><th>Property</th><th>Type</th><th class="num">Amount</th><th>GPS</th><th>IP</th></tr></thead><tbody>
      <?php foreach ($bids as $b): ?><tr><td class="nowrap"><small><?= e(fmt_dt_precise($b['submitted_at'])) ?></small></td><td><small><?= e($b['bid_ref']) ?></small></td><td><a href="<?= e(url('admin/property_bids.php?id=' . $b['property_id'])) ?>"><?= e($b['ref_no']) ?></a></td><td><?= e(status_label($b['bid_type'])) ?></td><td class="num"><?= e(money($b['amount'])) ?></td>
        <td><small><?= $b['gps_status'] === 'granted' ? e($b['gps_lat'] . ', ' . $b['gps_lng']) . ' ±' . e((string) $b['gps_accuracy']) . 'm' : e($b['gps_note']) ?></small></td><td><small><?= e($b['ip_address']) ?></small></td></tr><?php endforeach; ?>
      <?php if (!$bids): ?><tr><td colspan="7" class="muted">No bids.</td></tr><?php endif; ?>
      </tbody></table></div></div>

    <div class="card"><h2>Consent records</h2>
      <div class="table-wrap"><table class="table"><thead><tr><th>Date / time</th><th>Type</th><th>Version</th><th>Context</th><th>Granted</th><th>IP</th></tr></thead><tbody>
      <?php foreach ($consents as $c): ?><tr><td class="nowrap"><small><?= e(fmt_dt($c['accepted_at'], 'M j, Y g:i:s A')) ?></small></td><td><?= e(status_label($c['consent_type'])) ?></td><td><?= e($c['version']) ?></td><td><small><?= e($c['context']) ?></small></td><td><?= yes_no($c['granted']) ?></td><td><small><?= e($c['ip_address']) ?></small></td></tr><?php endforeach; ?>
      </tbody></table></div></div>

    <div class="card"><h2>Sign-in history</h2>
      <div class="table-wrap"><table class="table"><thead><tr><th>Time</th><th>Event</th><th>IP</th><th>Browser</th></tr></thead><tbody>
      <?php foreach ($logins as $l): ?><tr><td class="nowrap"><small><?= e(fmt_dt($l['created_at'], 'M j, Y g:i:s A')) ?></small></td><td><?= e(status_label($l['action'])) ?></td><td><small><?= e($l['ip_address']) ?></small></td><td><small><?= e(mb_substr((string) $l['user_agent'], 0, 80)) ?></small></td></tr><?php endforeach; ?>
      <?php if (!$logins): ?><tr><td colspan="4" class="muted">No records.</td></tr><?php endif; ?>
      </tbody></table></div></div>
  </div>

  <aside>
    <?php if (can('bidders.approve')): ?>
    <div class="card"><h3>Qualification</h3>
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $uid ?>"><input type="hidden" name="action" value="qualify">
        <div class="form-group"><label class="form-label" for="qr">Remarks (sent to bidder if rejected)</label><textarea class="form-control" id="qr" name="remarks" rows="2"></textarea></div>
        <div class="btn-row"><button class="btn btn-success" name="decision" value="approved">Approve bidder</button><button class="btn btn-outline" name="decision" value="rejected">Reject</button></div></form>
    </div>
    <?php endif; ?>
    <div class="card"><h3>Flags &amp; watchlist</h3>
      <?php foreach ($flags as $f): ?>
        <div class="fieldset"><span class="badge <?= $f['resolved_at'] ? 'badge-grey' : 'badge-amber' ?>"><?= e($f['flag_type']) ?></span> <small class="muted"><?= e($f['source'] === 'system' ? 'System' : $f['by_name']) ?> · <?= e(fmt_dt($f['created_at'])) ?></small>
          <p class="small mb-1"><?= e($f['details']) ?></p>
          <?php if ($f['resolved_at']): ?><p class="small muted">Resolved by <?= e($f['res_name']) ?>: <?= e($f['resolution']) ?></p>
          <?php elseif (can('bidders.manage')): ?><form method="post" class="inline-actions mb-1"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $uid ?>"><input type="hidden" name="action" value="resolve_flag"><input type="hidden" name="flag_id" value="<?= (int) $f['id'] ?>"><input class="form-control sm" name="remarks" placeholder="Resolution"><button class="btn btn-sm btn-outline">Resolve</button></form><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (can('bidders.manage')): ?>
        <form method="post"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $uid ?>"><input type="hidden" name="action" value="flag">
          <select class="form-control sm mb-1" name="flag_type"><option value="suspicious">Suspicious</option><option value="watchlist">Watchlist</option><option value="duplicate">Possible duplicate</option><option value="manual">Other</option></select>
          <input class="form-control sm mb-1" name="remarks" placeholder="Details" required><button class="btn btn-sm btn-outline">Add flag</button></form>
      <?php endif; ?>
    </div>
    <?php if ($related): ?><div class="card"><h3>Possibly related accounts</h3><ul class="small"><?php foreach ($related as $r): ?><li><a href="<?= e(url('admin/bidder_view.php?id=' . $r['id'])) ?>"><?= e($r['bidder_no']) ?></a> <?= e($r['full_name']) ?> <span class="muted">(<?= e($r['why']) ?>)</span></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if (can('bidders.manage')): ?>
    <div class="card danger-zone"><h3>Account controls</h3>
      <form method="post" class="mb-2" data-confirm="<?= (int) $u['is_blacklisted'] ? 'Remove from blacklist?' : 'Blacklist this bidder? They will be unable to sign in or bid.' ?>"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $uid ?>"><input type="hidden" name="action" value="blacklist"><input type="hidden" name="value" value="<?= (int) $u['is_blacklisted'] ? '0' : '1' ?>">
        <?php if (!(int) $u['is_blacklisted']): ?><input class="form-control sm mb-1" name="remarks" placeholder="Reason for blacklisting" required><?php endif; ?>
        <button class="btn btn-sm <?= (int) $u['is_blacklisted'] ? 'btn-outline' : 'btn-danger' ?>"><?= (int) $u['is_blacklisted'] ? 'Remove from blacklist' : 'Blacklist bidder' ?></button></form>
      <form method="post" class="mb-2"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $uid ?>"><input type="hidden" name="action" value="active"><input type="hidden" name="value" value="<?= (int) $u['is_active'] ? '0' : '1' ?>"><button class="btn btn-sm btn-outline"><?= (int) $u['is_active'] ? 'Deactivate account' : 'Activate account' ?></button></form>
      <?php if (!$u['email_verified_at']): ?><form method="post"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $uid ?>"><input type="hidden" name="action" value="resend_verification"><button class="btn btn-sm btn-link">Resend verification email</button></form><?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="card"><h3>Internal notes</h3>
      <?php if (can('notes.add')): ?><form method="post" class="mb-2"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $uid ?>"><input type="hidden" name="action" value="note"><textarea class="form-control" name="note" rows="2" required></textarea><button class="btn btn-sm btn-primary mt-1">Add note</button></form><?php endif; ?>
      <ul class="timeline"><?php foreach ($notes as $n): ?><li><small class="muted"><?= e($n['name']) ?> · <?= e(fmt_dt($n['created_at'])) ?></small><br><?= nl2br(e($n['note'])) ?></li><?php endforeach; ?></ul>
    </div>
  </aside>
</div>
<?php View::adminFooter();
