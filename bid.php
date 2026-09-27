<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

Csrf::requirePost();
$user = Auth::requireBidder();
$pid = input_int('property_id');
$property = Bidding::property($pid);
if (!$property) {
    abort(404);
}
$back = 'property.php?ref=' . rawurlencode($property['ref_no']) . '#bid';
$action = input('action') === 'withdraw' ? 'withdraw' : 'bid';

if (Throttle::tooMany('bid_submit', 'u:' . $user['id'], 20, 5)) {
    flash('error', 'Too many bid submissions in a short period. Please wait a few minutes.');
    redirect($back);
}
Throttle::hit('bid_submit', 'u:' . $user['id']);

if ($action === 'bid' && empty($_POST['accept_terms'])) {
    flash('error', 'You must accept the Terms and Conditions and bidding rules to submit a bid.');
    redirect($back);
}

$amount = $action === 'bid' ? parse_amount(input('amount')) : null;
$gps = [
    'status' => input('gps_status'),
    'lat' => input('gps_lat') ?: null,
    'lng' => input('gps_lng') ?: null,
    'accuracy' => input('gps_accuracy') ?: null,
];
$result = Bidding::submit($user, $pid, $action, $amount, $gps, input('device_info'));

if (!$result['ok']) {
    Audit::log('bid_rejected', 'property', $pid, null, ['reason' => $result['error'], 'amount' => input('amount'), 'action' => $action]);
    flash('error', $result['error']);
    redirect($back);
}
if ($action === 'withdraw') {
    flash('success', 'Your bid has been withdrawn. Reference: ' . $result['bid']['bid_ref']);
    redirect($back);
}
if (!empty($result['extended'])) {
    flash('info', 'Your bid was received within the final minutes; the closing time has been automatically extended per the bidding rules.');
}
redirect('acknowledgment.php?ref=' . rawurlencode($result['bid']['bid_ref']) . '&new=1');
