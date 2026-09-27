<?php
/** Lightweight JSON endpoint for countdown re-sync (server time). Never exposes bidder identities. */
declare(strict_types=1);
define('NO_SESSION_TOUCH', true);
require __DIR__ . '/app/bootstrap.php';

$p = Bidding::property(query_int('id'));
if (!$p || !(int) $p['is_published']) {
    json_response(['ok' => false], 404);
}
$highest = (int) $p['show_highest'] ? Bidding::highest((int) $p['id']) : null;
json_response([
    'ok' => true,
    'status' => $p['status'],
    'server_time' => date('c'),
    'opens_in' => max(0, strtotime($p['opening_at']) - time()),
    'closes_in' => Bidding::secondsRemaining($p),
    'opening_label' => fmt_dt($p['opening_at'], 'M j, Y g:i:s A'),
    'closing_label' => fmt_dt($p['closing_at'], 'M j, Y g:i:s A'),
    'extensions' => (int) $p['extensions_used'],
    'highest' => $highest ? money($highest['amount']) : null,
    'bidders' => (int) $p['show_bidder_count'] ? count(Bidding::activeBids((int) $p['id'])) : null,
]);
