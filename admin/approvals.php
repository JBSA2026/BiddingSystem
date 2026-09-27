<?php
/** Dual-authorization queue: award approvals and schedule-change requests. */
declare(strict_types=1);
require __DIR__ . '/_init.php';

$admin = Auth::requireAdmin('dashboard.view');

if (is_post()) {
    Csrf::verify();
    Auth::requireAdmin('schedule.approve');
    $req = DB::one("SELECT * FROM approval_requests WHERE id = ? AND status = 'pending'", [input_int('request_id')]);
    try {
        if (!$req) {
            throw new RuntimeException('Request not found or already decided.');
        }
        if ((int) $req['requested_by'] === (int) $admin['id']) {
            throw new RuntimeException('Dual authorization: you cannot approve your own request.');
        }
        if (!Auth::confirmAdminPassword($admin, (string) ($_POST['confirm_password'] ?? ''))) {
            throw new RuntimeException('Password confirmation failed.');
        }
        $decision = input('decision') === 'approve' ? 'approved' : 'rejected';
        $remarks = mb_substr(input('remarks'), 0, 1000);
        if ($decision === 'approved' && $req['action_type'] === 'schedule_change') {
            $pl = json_decode($req['payload'], true);
            Bidding::applyScheduleChange((int) $req['property_id'], $pl['opening_at'], $pl['closing_at'], $req['reason'] . ($remarks ? ' | Approver: ' . $remarks : ''), (int) $admin['id']);
        }
        DB::update('approval_requests', ['status' => $decision, 'decided_by' => $admin['id'], 'decided_at' => now(), 'decision_remarks' => $remarks], 'id = ?', [$req['id']]);
        Audit::log('approval_request_' . $decision, 'approval_request', $req['id'], ['status' => 'pending'], ['status' => $decision, 'type' => $req['action_type'], 'property_id' => $req['property_id'], 'remarks' => $remarks]);
        flash('success', 'Request ' . $decision . '.');
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/approvals.php');
}

$awards = DB::all("SELECT aw.*, p.ref_no, p.name AS pname, u.full_name, u.bidder_no, b.amount, r.name AS rec_name FROM awards aw JOIN properties p ON p.id = aw.property_id JOIN users u ON u.id = aw.winning_user_id JOIN bids b ON b.id = aw.winning_bid_id JOIN admins r ON r.id = aw.recommended_by WHERE aw.status = 'pending_approval' ORDER BY aw.id");
$requests = DB::all("SELECT ar.*, p.ref_no, p.name AS pname, a.name AS req_name FROM approval_requests ar LEFT JOIN properties p ON p.id = ar.property_id JOIN admins a ON a.id = ar.requested_by WHERE ar.status = 'pending' ORDER BY ar.id");
$history = DB::all("SELECT ar.*, p.ref_no, a.name AS req_name, d.name AS dec_name FROM approval_requests ar LEFT JOIN properties p ON p.id = ar.property_id JOIN admins a ON a.id = ar.requested_by LEFT JOIN admins d ON d.id = ar.decided_by WHERE ar.status <> 'pending' ORDER BY ar.id DESC LIMIT 30");

View::adminHeader('Approvals');
?>
<div class="card"><h2>Awards pending approval</h2>
  <?php if (!$awards): ?><p class="muted">None.</p><?php else: ?>
  <div class="table-wrap"><table class="table"><thead><tr><th>Property</th><th>Recommended bidder</th><th class="num">Amount</th><th>Recommended by</th><th></th></tr></thead><tbody>
    <?php foreach ($awards as $a): ?><tr><td><?= e($a['pname']) ?><br><small class="muted"><?= e($a['ref_no']) ?></small></td><td><?= e($a['full_name']) ?><br><small class="muted"><?= e($a['bidder_no']) ?></small></td><td class="num"><?= e(money($a['amount'])) ?></td><td><?= e($a['rec_name']) ?><br><small class="muted"><?= e(fmt_dt($a['recommended_at'])) ?></small></td>
      <td><a class="btn btn-sm btn-primary" href="<?= e(url('admin/award.php?property=' . $a['property_id'])) ?>">Review</a></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>

<div class="card"><h2>Schedule change requests</h2>
  <?php if (!$requests): ?><p class="muted">None.</p><?php endif; ?>
  <?php foreach ($requests as $r): $pl = json_decode($r['payload'], true); ?>
    <div class="fieldset">
      <strong><?= e($r['pname']) ?></strong> <small class="muted"><?= e($r['ref_no']) ?></small><br>
      Closing: <?= e(fmt_dt($pl['old_closing_at'] ?? null)) ?> → <strong><?= e(fmt_dt($pl['closing_at'])) ?></strong>
      <?php if (($pl['old_opening_at'] ?? '') !== $pl['opening_at']): ?><br>Opening: <?= e(fmt_dt($pl['old_opening_at'] ?? null)) ?> → <strong><?= e(fmt_dt($pl['opening_at'])) ?></strong><?php endif; ?>
      <br><small>Requested by <?= e($r['req_name']) ?> on <?= e(fmt_dt($r['requested_at'])) ?> — Reason: <?= e($r['reason']) ?></small>
      <?php if (can('schedule.approve') && (int) $r['requested_by'] !== (int) $admin['id']): ?>
        <form method="post" class="inline-actions mt-1"><?= Csrf::field() ?><input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
          <input class="form-control sm" name="remarks" placeholder="Remarks" style="max-width:220px"><input class="form-control sm" type="password" name="confirm_password" placeholder="Your password" required style="max-width:160px" autocomplete="current-password">
          <button class="btn btn-sm btn-success" name="decision" value="approve">Approve</button><button class="btn btn-sm btn-outline" name="decision" value="reject">Reject</button></form>
      <?php elseif ((int) $r['requested_by'] === (int) $admin['id']): ?><p class="small muted mb-0">Awaiting a second authorized officer.</p><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($history): ?>
<div class="card"><h2>Recent decisions</h2><div class="table-wrap"><table class="table"><thead><tr><th>Type</th><th>Property</th><th>Requested by</th><th>Decision</th><th>Decided by</th><th>Remarks</th></tr></thead><tbody>
  <?php foreach ($history as $h): ?><tr><td><?= e(status_label($h['action_type'])) ?></td><td><?= e($h['ref_no']) ?></td><td><?= e($h['req_name']) ?></td><td><?= status_badge($h['status']) ?></td><td><?= e($h['dec_name']) ?><br><small class="muted"><?= e(fmt_dt($h['decided_at'])) ?></small></td><td><small><?= e($h['decision_remarks']) ?></small></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<?php View::adminFooter();
