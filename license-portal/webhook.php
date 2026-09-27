<?php
/**
 * PayMongo webhook — register  <portal_url>/webhook.php  for the event  checkout_session.payment.paid.
 * The signature is verified, then the payment is re-confirmed with PayMongo's API before a key is issued.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$body = (string) file_get_contents('php://input');
$sig = (string) ($_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '');
if (!webhook_signature_ok($sig, $body, (string) cfg('paymongo.webhook_secret'))) {
    log_line('webhook: bad signature');
    http_response_code(401);
    exit('invalid signature');
}
$event = json_decode($body, true);
$type = (string) ($event['data']['attributes']['type'] ?? '');
$resource = $event['data']['attributes']['data'] ?? [];
if ($type !== 'checkout_session.payment.paid') {
    http_response_code(200);
    exit('ignored');
}
$token = (string) ($resource['attributes']['metadata']['order_token'] ?? '');
$order = $token !== '' ? order_by_token($token) : null;
if (!$order || $order['checkout_id'] !== ($resource['id'] ?? null)) {
    log_line('webhook: unknown order for checkout ' . ($resource['id'] ?? '?'));
    http_response_code(200);
    exit('unknown order');
}
try {
    $order = fulfil($order);
    http_response_code(200);
    echo 'ok ' . $order['status'];
} catch (Throwable $e) {
    log_line("webhook: order {$order['id']}: " . $e->getMessage());
    http_response_code(500); // PayMongo retries
    echo 'retry';
}
