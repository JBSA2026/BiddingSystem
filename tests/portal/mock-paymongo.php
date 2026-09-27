<?php
// Minimal PayMongo stand-in for tests: checkout sessions + a fake "pay" page. Router script for php -S.
$store = getenv('MOCK_STORE') ?: sys_get_temp_dir() . '/mock-paymongo.json';
$db = is_file($store) ? json_decode(file_get_contents($store), true) : [];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$save = static function () use (&$db, $store) { file_put_contents($store, json_encode($db)); };
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/v1/checkout_sessions') {
    if (($_SERVER['PHP_AUTH_USER'] ?? '') !== 'sk_test_mock') { http_response_code(401); exit(json_encode(['errors' => [['detail' => 'bad key']]])); }
    $a = json_decode(file_get_contents('php://input'), true)['data']['attributes'];
    $id = 'cs_' . bin2hex(random_bytes(6));
    $db[$id] = ['attributes' => $a, 'paid' => false, 'paid_amount' => null];
    $save();
    exit(json_encode(['data' => ['id' => $id, 'attributes' => ['checkout_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/pay?cs=' . $id]]]));
}
if (preg_match('#^/v1/checkout_sessions/(cs_\w+)$#', $path, $m) && isset($db[$m[1]])) {
    $s = $db[$m[1]];
    $payments = $s['paid'] ? [['id' => 'pay_' . substr($m[1], 3), 'attributes' => ['status' => 'paid', 'amount' => $s['paid_amount'], 'livemode' => false]]] : [];
    exit(json_encode(['data' => ['id' => $m[1], 'attributes' => ['livemode' => false, 'payments' => $payments, 'metadata' => $s['attributes']['metadata']]]]));
}
if ($path === '/pay' && isset($db[$_GET['cs'] ?? ''])) {
    $cs = $_GET['cs'];
    $db[$cs]['paid'] = true;
    $db[$cs]['paid_amount'] = isset($_GET['amount']) ? (int) $_GET['amount'] : $db[$cs]['attributes']['line_items'][0]['amount'];
    $save();
    header('Location: ' . $db[$cs]['attributes']['success_url'], true, 302);
    exit;
}
http_response_code(404);
echo json_encode(['errors' => [['detail' => 'not found ' . $path]]]);
