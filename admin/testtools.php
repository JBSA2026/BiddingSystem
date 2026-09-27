<?php
/**
 * TEST ENVIRONMENT ONLY — helpers for user-acceptance testing:
 * reset test data, fast-forward bidding times, run scheduled tasks now.
 * Returns 404 unless app.env = 'testing'.
 */
declare(strict_types=1);
require __DIR__ . '/_init.php';

if (!is_testing()) {
    abort(404);
}
$admin = Auth::requireAdmin('settings.manage');

if (is_post()) {
    Csrf::verify();
    try {
        switch (input('action')) {
            case 'reset':
                if (!Auth::confirmAdminPassword($admin, (string) ($_POST['confirm_password'] ?? ''))) {
                    throw new RuntimeException('Password confirmation failed.');
                }
                TestEnv::reset();
                Auth::logoutAdmin();
                session_regenerate_id(true);
                flash('success', 'Test data has been reset. Sign in again with a test account.');
                redirect('admin/login.php');
            case 'close_in':
                $pid = input_int('property_id');
                $min = max(1, min(120, input_int('minutes', 2)));
                $p = Bidding::property($pid);
                if (!$p || $p['status'] !== 'open') {
                    throw new RuntimeException('Choose an OPEN property.');
                }
                $new = date('Y-m-d H:i:s', time() + $min * 60);
                DB::update('properties', ['closing_at' => $new], 'id = ?', [$pid]);
                Audit::log('test_time_override', 'property', $pid, ['closing_at' => $p['closing_at']], ['closing_at' => $new, 'note' => 'TEST TOOLS fast-forward']);
                flash('success', "{$p['ref_no']} now closes in {$min} minute(s) at " . fmt_dt($new, 'g:i:s A') . '.');
                break;
            case 'open_now':
                $pid = input_int('property_id');
                $p = Bidding::property($pid);
                if (!$p || $p['status'] !== 'upcoming') {
                    throw new RuntimeException('Choose an UPCOMING property.');
                }
                $new = date('Y-m-d H:i:s', time() - 1);
                DB::update('properties', ['opening_at' => $new], 'id = ?', [$pid]);
                Audit::log('test_time_override', 'property', $pid, ['opening_at' => $p['opening_at']], ['opening_at' => $new, 'note' => 'TEST TOOLS open now']);
                Bidding::syncStatuses();
                flash('success', "{$p['ref_no']} is now open for bidding.");
                break;
            case 'run_cron':
                $sent = Mailer::processQueue(200);
                flash('success', "Scheduled tasks ran: statuses synchronised, {$sent} email(s) processed.");
                break;
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/testtools.php');
}
$open = DB::all("SELECT id, ref_no, name, closing_at FROM properties WHERE status = 'open' ORDER BY closing_at");
$upcoming = DB::all("SELECT id, ref_no, name, opening_at FROM properties WHERE status = 'upcoming' ORDER BY opening_at");
View::adminHeader('Test tools');
?>
<div class="alert alert-warning"><strong>Test environment only.</strong> These tools bypass the normal controls (for example, schedule changes without dual authorization) so you can test quickly. Every use is still written to the audit trail as <code>test_time_override</code>. They do not exist on the live site.</div>
<div class="grid grid-2">
  <div class="card"><h2>Fast-forward closing</h2><p class="small muted">Make an open property close in a few minutes to test the countdown, anti-sniping extension, automatic close, ranking lock and "Under Evaluation".</p>
    <form method="post" class="inline-actions"><?= Csrf::field() ?><input type="hidden" name="action" value="close_in">
      <select class="form-control sm" name="property_id" required style="max-width:320px"><?php foreach ($open as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['ref_no'] . ' — ' . mb_substr($p['name'], 0, 45)) ?></option><?php endforeach; ?></select>
      <label class="small">close in <input class="form-control sm" type="number" name="minutes" value="3" min="1" max="120" style="width:70px"> min</label>
      <button class="btn btn-sm btn-primary">Apply</button></form></div>
  <div class="card"><h2>Open an upcoming property now</h2>
    <form method="post" class="inline-actions"><?= Csrf::field() ?><input type="hidden" name="action" value="open_now">
      <select class="form-control sm" name="property_id" required style="max-width:320px"><?php foreach ($upcoming as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['ref_no'] . ' — ' . mb_substr($p['name'], 0, 45)) ?></option><?php endforeach; ?></select>
      <button class="btn btn-sm btn-primary">Open now</button></form></div>
  <div class="card"><h2>Run scheduled tasks now</h2><p class="small muted">Normally done every minute by cron: open/close on schedule and send queued emails.</p>
    <form method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="run_cron"><button class="btn btn-sm btn-outline">Run now</button></form>
    <p class="small mt-1"><a href="<?= e(url('dev/mailbox.php')) ?>">Open the test mailbox &raquo;</a></p></div>
  <div class="card danger-zone"><h2>Reset all test data</h2><p class="small muted">Deletes everything in this TEST database (including anything you created) and reloads the standard test accounts and properties.</p>
    <form method="post" data-confirm="Delete ALL test data and reload the standard test set?"><?= Csrf::field() ?><input type="hidden" name="action" value="reset">
      <input class="form-control sm mb-1" type="password" name="confirm_password" placeholder="Your password" required autocomplete="current-password">
      <button class="btn btn-sm btn-danger">Reset test data</button></form></div>
</div>
<div class="card"><h2>Test accounts</h2>
  <div class="grid grid-2">
    <div><h3>Administrators — password <code><?= e(TestEnv::ADMIN_PASSWORD) ?></code></h3><table class="kv"><?php foreach (TestEnv::ADMINS as [$n, $em, $r]): ?><tr><th><?= e(Rbac::label($r)) ?></th><td><?= e($em) ?></td></tr><?php endforeach; ?></table></div>
    <div><h3>Bidders — password <code><?= e(TestEnv::BIDDER_PASSWORD) ?></code></h3><table class="kv"><?php foreach (TestEnv::BIDDERS as [$n, , $em, , $st]): ?><tr><th><?= e($st) ?></th><td><?= e($em) ?> (<?= e($n) ?>)</td></tr><?php endforeach; ?></table></div>
  </div></div>
<?php View::adminFooter();
