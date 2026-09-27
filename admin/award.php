<?php
/**
 * Controlled awarding workflow:
 *   Bidding Closed → Evaluation → Qualified Highest Bidder → Recommendation → Management Approval → Awarded
 * The system only RECOMMENDS; an authorized officer must approve (with password re-entry, and a
 * different officer than the recommender when dual authorization is enabled).
 */
declare(strict_types=1);
require __DIR__ . '/_init.php';

$admin = Auth::requireAdmin('bids.view');
$pid = query_int('property') ?: input_int('property_id');
$p = Bidding::property($pid);
if (!$p) {
    abort(404);
}
$back = 'admin/award.php?property=' . $pid;

if (is_post()) {
    Csrf::verify();
    try {
        switch (input('action')) {
            case 'recommend':
                Auth::requireAdmin('award.recommend');
                $remarks = input('remarks');
                if (mb_strlen($remarks) < 5) {
                    throw new RuntimeException('Please provide evaluation remarks supporting the recommendation.');
                }
                Bidding::recommendAward($pid, input_int('winner_id'), (array) ($_POST['backup_ids'] ?? []), $remarks, $admin);
                flash('success', 'Recommendation submitted. The award now requires approval by an authorized Approving Officer.');
                break;
            case 'approve':
                Auth::requireAdmin('award.approve');
                if (empty($_POST['confirm'])) {
                    throw new RuntimeException('Please tick the confirmation box.');
                }
                if (!Auth::confirmAdminPassword($admin, (string) ($_POST['confirm_password'] ?? ''))) {
                    Audit::log('award_approval_password_failed', 'property', $pid);
                    throw new RuntimeException('Password confirmation failed.');
                }
                $ref = mb_substr(input('approval_reference'), 0, 100);
                if ($ref === '') {
                    throw new RuntimeException('An approval reference (e.g. board resolution / memo number) is required.');
                }
                $doc = null;
                if ($files = Upload::files('supporting_doc')) {
                    $doc = Upload::store($files[0], 'awards', Upload::DOC_TYPES);
                }
                Bidding::approveAward(input_int('award_id'), $admin, $ref, input('remarks'), $doc);
                flash('success', 'Award approved. The property is now AWARDED and all participants have been notified.');
                break;
            case 'reject':
                Auth::requireAdmin('award.approve');
                $remarks = input('remarks');
                if (mb_strlen($remarks) < 5) {
                    throw new RuntimeException('Please state the reason for rejecting the recommendation.');
                }
                Bidding::rejectAward(input_int('award_id'), $admin, $remarks);
                flash('success', 'Recommendation rejected. The property remains under evaluation.');
                break;
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect($back);
}

$rows = Bidding::snapshot($pid) ?: Bidding::ranking($pid, false);
$recommended = Bidding::recommendedBidder($pid);
$pending = DB::one("SELECT aw.*, u.full_name, u.bidder_no, u.company_name, b.amount, b.bid_ref, r.name AS rec_name FROM awards aw JOIN users u ON u.id = aw.winning_user_id JOIN bids b ON b.id = aw.winning_bid_id JOIN admins r ON r.id = aw.recommended_by WHERE aw.property_id = ? AND aw.status = 'pending_approval'", [$pid]);
$approved = DB::one("SELECT aw.*, u.full_name, u.bidder_no, b.amount, b.bid_ref, ap.name AS app_name FROM awards aw JOIN users u ON u.id = aw.winning_user_id JOIN bids b ON b.id = aw.winning_bid_id LEFT JOIN admins ap ON ap.id = aw.approved_by WHERE aw.property_id = ? AND aw.status = 'approved'", [$pid]);
$qualified = array_filter($rows, static fn($r) => ($r['eval_status'] ?? '') === 'qualified');
$dual = Settings::bool('dual_auth_award', true);
$steps = ['Bidding Closed', 'Evaluation', 'Qualified Highest Bidder', 'Management Approval', 'Awarded'];
$stepIdx = match (true) {
    $p['status'] === 'awarded' => 5,
    (bool) $pending => 3,
    (bool) $qualified => 2,
    $p['status'] === 'under_evaluation' => 1,
    default => 0,
};

View::adminHeader('Award workflow — ' . $p['ref_no']);
?>
<ol class="process light mb-2"><?php foreach ($steps as $i => $s): ?><li class="<?= $i < $stepIdx ? 'done' : ($i === $stepIdx ? 'current' : '') ?>"><?= e($s) ?></li><?php endforeach; ?></ol>
<div class="card"><h2 class="mb-0"><?= e($p['name']) ?></h2><span class="muted"><?= e($p['ref_no']) ?></span> <?= status_badge($p['status']) ?> · <a href="<?= e(url('admin/property_bids.php?id=' . $pid)) ?>">Back to bids &amp; evaluation</a></div>

<?php if ($approved): ?>
  <div class="card"><h2>Awarded</h2>
    <table class="kv"><tr><th>Winning bidder</th><td><?= e($approved['full_name']) ?> (<?= e($approved['bidder_no']) ?>)</td></tr>
      <tr><th>Winning bid</th><td><?= e(money($approved['amount'])) ?> — <?= e($approved['bid_ref']) ?></td></tr>
      <tr><th>Approved by</th><td><?= e($approved['app_name']) ?> on <?= e(fmt_dt($approved['approved_at'], 'M j, Y g:i:s A')) ?></td></tr>
      <tr><th>Approval reference</th><td><?= e($approved['approval_reference']) ?></td></tr>
      <tr><th>Remarks</th><td><?= nl2br(e($approved['approval_remarks'])) ?></td></tr>
      <tr><th>Backup bidder(s)</th><td><?php $bk = json_decode((string) $approved['backup_user_ids'], true) ?: []; echo $bk ? e(implode(', ', array_column(DB::all('SELECT bidder_no FROM users WHERE id IN (' . DB::in($bk) . ')', $bk), 'bidder_no'))) : '—'; ?></td></tr>
      <tr><th>Supporting document</th><td><?= $approved['supporting_doc_path'] ? '<a href="' . e(url('file.php?t=award&id=' . $approved['id'])) . '">' . e($approved['supporting_doc_name']) . '</a>' : '—' ?></td></tr></table></div>
<?php elseif ($pending): ?>
  <div class="card"><h2>Recommendation pending approval</h2>
    <table class="kv"><tr><th>Recommended winning bidder</th><td><strong><?= e($pending['full_name']) ?></strong> (<?= e($pending['bidder_no']) ?>)<?= $pending['company_name'] ? ' — ' . e($pending['company_name']) : '' ?></td></tr>
      <tr><th>Bid</th><td><?= e(money($pending['amount'])) ?> — <?= e($pending['bid_ref']) ?></td></tr>
      <tr><th>Backup bidder(s)</th><td><?php $bk = json_decode((string) $pending['backup_user_ids'], true) ?: []; echo $bk ? e(implode(', ', array_column(DB::all('SELECT bidder_no FROM users WHERE id IN (' . DB::in($bk) . ')', $bk), 'bidder_no'))) : '—'; ?></td></tr>
      <tr><th>Recommended by</th><td><?= e($pending['rec_name']) ?> on <?= e(fmt_dt($pending['recommended_at'])) ?></td></tr>
      <tr><th>Evaluation remarks</th><td><?= nl2br(e($pending['recommend_remarks'])) ?></td></tr></table>
    <?php if (can('award.approve')): ?>
      <?php if ($dual && (int) $pending['recommended_by'] === (int) $admin['id']): ?>
        <div class="alert alert-warning mt-2">Dual authorization is enabled — a different Approving Officer must approve this award.</div>
      <?php else: ?>
        <div class="grid grid-2 mt-2">
          <form method="post" enctype="multipart/form-data" class="fieldset" data-confirm="Approve this award? The winning, backup and non-winning bidders will be notified. This is final."><?= Csrf::field() ?>
            <legend>Approve award</legend><input type="hidden" name="property_id" value="<?= $pid ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="award_id" value="<?= (int) $pending['id'] ?>">
            <div class="form-group"><label class="form-label" for="ar">Approval reference <span class="req">*</span></label><input class="form-control" id="ar" name="approval_reference" required maxlength="100" placeholder="e.g. Mgmt Memo 2026-045"></div>
            <div class="form-group"><label class="form-label" for="am">Remarks</label><textarea class="form-control" id="am" name="remarks" rows="3"></textarea></div>
            <div class="form-group"><label class="form-label" for="ad">Supporting document</label><input class="form-control" type="file" id="ad" name="supporting_doc" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"></div>
            <div class="form-group"><label class="form-label" for="apw">Confirm your password <span class="req">*</span></label><input class="form-control" type="password" id="apw" name="confirm_password" required autocomplete="current-password"></div>
            <label class="check"><input type="checkbox" name="confirm" value="1" required><span>I confirm that the recommended bidder has been evaluated (identity, documents, eligibility, payment capability, compliance) and I am authorized to approve this award.</span></label>
            <button class="btn btn-success" type="submit">Approve award</button></form>
          <form method="post" class="fieldset"><?= Csrf::field() ?><legend>Reject recommendation</legend>
            <input type="hidden" name="property_id" value="<?= $pid ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="award_id" value="<?= (int) $pending['id'] ?>">
            <div class="form-group"><label class="form-label" for="rr">Reason</label><textarea class="form-control" id="rr" name="remarks" rows="3" required></textarea></div>
            <button class="btn btn-outline" type="submit">Reject recommendation</button></form>
        </div>
      <?php endif; ?>
    <?php else: ?><p class="muted mt-2">Awaiting approval by an Approving Officer.</p><?php endif; ?>
  </div>
<?php elseif ($p['status'] === 'under_evaluation'): ?>
  <div class="card"><h2>Recommend winning bidder</h2>
    <?php if ($recommended): ?><div class="alert alert-info">Highest qualified bidder: <strong><?= e($recommended['full_name']) ?></strong> (<?= e($recommended['bidder_no']) ?>) — <?= e(money($recommended['amount'])) ?>. The system does not award automatically; you may recommend another qualified bidder with justification.</div><?php endif; ?>
    <?php if (!$qualified): ?>
      <div class="alert alert-warning">No bidders are marked Qualified yet. Evaluate bidders on the <a href="<?= e(url('admin/property_bids.php?id=' . $pid)) ?>">bids &amp; evaluation page</a> first.</div>
    <?php elseif (can('award.recommend')): ?>
      <form method="post"><?= Csrf::field() ?><input type="hidden" name="property_id" value="<?= $pid ?>"><input type="hidden" name="action" value="recommend">
        <div class="table-wrap"><table class="table"><thead><tr><th>Winner</th><th>Backup</th><th>#</th><th>Bidder</th><th class="num">Amount</th><th>Timestamp</th></tr></thead><tbody>
          <?php foreach ($qualified as $r): $uid = (int) $r['user_id']; ?>
            <tr><td><input type="radio" name="winner_id" value="<?= $uid ?>" required <?= $recommended && (int) $recommended['user_id'] === $uid ? 'checked' : '' ?>></td>
              <td><input type="checkbox" name="backup_ids[]" value="<?= $uid ?>"></td>
              <td><?= (int) ($r['rank_no'] ?? $r['rank']) ?></td><td><?= e($r['full_name']) ?><br><small class="muted"><?= e($r['bidder_no']) ?></small></td>
              <td class="num"><?= e(money($r['amount'])) ?></td><td><small><?= e(fmt_dt_precise($r['bid_time'] ?? $r['submitted_at'])) ?></small></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <div class="form-group mt-2"><label class="form-label" for="rm">Evaluation remarks / justification <span class="req">*</span></label><textarea class="form-control" id="rm" name="remarks" rows="4" required placeholder="Summarize identity verification, documents, eligibility, payment capability / deposit, compliance and other Cityland qualification requirements."></textarea></div>
        <button class="btn btn-primary" type="submit">Submit recommendation for approval</button>
      </form>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="card"><p class="muted">The award workflow is available once bidding has closed and the property is Under Evaluation. Current status: <?= status_badge($p['status']) ?></p></div>
<?php endif; ?>
<?php View::adminFooter();
