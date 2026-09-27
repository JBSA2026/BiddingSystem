<?php
/** Request to join a bidding event that requires pre-qualification by Cityland. */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

Csrf::requirePost();
$user = Auth::requireBidder();
$p = Bidding::property(input_int('property_id'));
if (!$p || !(int) $p['is_published'] || !in_array($p['status'], ['upcoming', 'open'], true)) {
    abort(404);
}
$back = 'property.php?ref=' . rawurlencode($p['ref_no']) . '#bid';
if (!(int) $p['require_prequalification']) {
    redirect($back);
}
$existing = DB::one('SELECT * FROM property_bidders WHERE property_id = ? AND user_id = ?', [$p['id'], $user['id']]);
if ($existing && $existing['access_status'] !== 'not_required') {
    flash('info', 'Your request has already been submitted.');
    redirect($back);
}
if ($existing) {
    DB::update('property_bidders', ['access_status' => 'pending'], 'id = ?', [$existing['id']]);
} else {
    DB::insert('property_bidders', ['property_id' => $p['id'], 'user_id' => $user['id'], 'access_status' => 'pending', 'eval_status' => 'under_review', 'joined_at' => now()]);
}
Audit::log('prequalification_requested', 'property', $p['id'], null, ['user_id' => $user['id']]);
Notifier::admins('prequalification', 'Pre-qualification request: ' . $p['ref_no'], "Bidder {$user['bidder_no']} ({$user['full_name']}) requested to join the bidding for {$p['name']} ({$p['ref_no']}).", 'admin/property_bids.php?id=' . $p['id'] . '#participants');
flash('success', 'Your request to join has been submitted. You will be notified once Cityland reviews it.');
redirect($back);
