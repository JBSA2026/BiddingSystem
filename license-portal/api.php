<?php
/**
 * Client sites call this from Admin → License → "Check for my renewed license":
 *   api.php?lid=<License ID>&domain=<site domain>
 * Returns the newest issued key for that license and domain. Keys are signed and locked to the domain,
 * so they are useless anywhere else.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
$lid = (string) ($_GET['lid'] ?? '');
$domain = preg_replace('/^www\./', '', strtolower(trim((string) ($_GET['domain'] ?? '')))) ?? '';
if (!preg_match('/^[A-Za-z0-9-]{3,40}$/', $lid) || $domain === '') {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Missing license ID or domain.']));
}
$st = db()->prepare("SELECT * FROM orders WHERE lid = ? AND status IN ('paid','issued') ORDER BY id DESC LIMIT 5");
$st->execute([$lid]);
$pendingIssue = false;
foreach ($st->fetchAll() as $o) {
    $domains = (array) json_decode($o['domains'], true);
    $match = false;
    foreach ($domains as $d) {
        if ($d === $domain || (str_starts_with($d, '*.') && (str_ends_with($domain, substr($d, 1)) || $domain === substr($d, 2)))) {
            $match = true;
        }
    }
    if (!$match) {
        continue;
    }
    if ($o['status'] === 'issued') {
        exit(json_encode(['ok' => true, 'license_key' => $o['license_key'], 'expires' => $o['new_expires'], 'plan' => $o['plan']]));
    }
    $pendingIssue = true;
}
exit(json_encode(['ok' => false, 'error' => $pendingIssue
    ? 'Your payment was received and your new key is being prepared. You will get it by email within one business day.'
    : 'No paid renewal was found for this license yet.']));
