<?php
/** Bid security / reservation deposit reconciliation (manual; gateway-ready). */
declare(strict_types=1);
require __DIR__ . '/_init.php';

$admin = Auth::requireAdmin('payments.view');
if (is_post()) {
    Csrf::verify();
    Auth::requireAdmin('payments.reconcile');
    $pay = DB::one('SELECT * FROM payments WHERE id = ?', [input_int('payment_id')]);
    $st = input('status');
    if ($pay && in_array($st, ['verified', 'rejected', 'refunded', 'forfeited'], true)) {
        DB::update('payments', ['status' => $st, 'remarks' => mb_substr(input('remarks'), 0, 500) ?: null, 'reconciled_by' => $admin['id'], 'reconciled_at' => now()], 'id = ?', [$pay['id']]);
        Audit::log('payment_' . $st, 'payment', $pay['id'], ['status' => $pay['status']], ['status' => $st, 'remarks' => input('remarks'), 'reference_no' => $pay['reference_no'], 'amount' => $pay['amount']]);
        $u = DB::one('SELECT * FROM users WHERE id = ?', [$pay['user_id']]);
        $p = Bidding::property((int) $pay['property_id']);
        Notifier::bidder($u, 'payment_' . $st, 'Bid security ' . status_label($st) . ' — ' . $p['name'], [
            'The status of your bid security payment has been updated.',
            Notifier::table(['Property' => $p['name'] . ' (' . $p['ref_no'] . ')', 'Reference no.' => $pay['reference_no'], 'Amount' => money($pay['amount']), 'Status' => status_label($st), 'Remarks' => input('remarks') ?: '—']),
        ], 'payment.php?property=' . $p['id']);
        flash('success', 'Payment marked ' . status_label($st) . '.');
    }
    redirect('admin/payments.php' . qs());
}
$status = query('status');
$where = '1=1';
$params = [];
if (in_array($status, ['pending', 'verified', 'rejected', 'refunded', 'forfeited'], true)) {
    $where = 'pm.status = ?';
    $params[] = $status;
}
$rows = DB::all("SELECT pm.*, p.ref_no, u.full_name, u.bidder_no, a.name AS rec_name FROM payments pm JOIN properties p ON p.id = pm.property_id JOIN users u ON u.id = pm.user_id LEFT JOIN admins a ON a.id = pm.reconciled_by WHERE {$where} ORDER BY pm.id DESC LIMIT 300", $params);

View::adminHeader('Bid Security / Payments');
?>
<?php if (!Settings::bool('bid_security_enabled')): ?><div class="alert alert-info">The bid security feature is currently <strong>disabled</strong> in Settings. Records below are kept for reconciliation. Automatic payment processing is not active; gateway: <strong><?= e(setting('payment_gateway', 'none')) ?></strong>.</div><?php endif; ?>
<form class="admin-filters" method="get"><div class="form-group"><label class="form-label" for="status">Status</label><select class="form-control sm" id="status" name="status" data-autosubmit><option value="">All</option><?php foreach (['pending', 'verified', 'rejected', 'refunded', 'forfeited'] as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?></select></div>
  <?php if (can('reports.export')): ?><a class="btn btn-sm btn-outline" href="<?= e(url('admin/reports.php?export=payments')) ?>">Export CSV</a><?php endif; ?></form>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Submitted</th><th>Bidder</th><th>Property</th><th>Method</th><th>Reference</th><th class="num">Amount</th><th>Proof</th><th>Status</th><th>Reconcile</th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?>
    <tr><td class="nowrap"><small><?= e(fmt_dt($r['created_at'])) ?></small></td><td><?= e($r['full_name']) ?><br><small class="muted"><?= e($r['bidder_no']) ?></small></td><td><?= e($r['ref_no']) ?></td><td><?= e(status_label($r['method'])) ?></td><td><?= e($r['reference_no']) ?></td><td class="num"><?= e(money($r['amount'])) ?></td>
      <td><?= $r['proof_path'] ? '<a href="' . e(url('file.php?t=proof&id=' . $r['id'])) . '">View</a>' : '—' ?></td>
      <td><?= status_badge($r['status']) ?><?= $r['rec_name'] ? '<br><small class="muted">' . e($r['rec_name']) . ' · ' . e(fmt_dt($r['reconciled_at'])) . '</small>' : '' ?><?= $r['remarks'] ? '<br><small>' . e($r['remarks']) . '</small>' : '' ?></td>
      <td><?php if (can('payments.reconcile')): ?><form method="post" class="inline-actions"><?= Csrf::field() ?><input type="hidden" name="payment_id" value="<?= (int) $r['id'] ?>">
        <select class="form-control sm" name="status"><?php foreach (['verified', 'rejected', 'refunded', 'forfeited'] as $s): ?><option value="<?= $s ?>"><?= e(status_label($s)) ?></option><?php endforeach; ?></select>
        <input class="form-control sm" name="remarks" placeholder="Remarks / bank ref" style="max-width:150px"><button class="btn btn-sm btn-primary">Save</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="muted">No payment records.</td></tr><?php endif; ?></tbody>
</table></div>
<?php View::adminFooter();
