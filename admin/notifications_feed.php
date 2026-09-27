<?php
/** Administrator notification bell feed (JSON). Polled by assets/js/app.js; does not extend the session idle timer. */
declare(strict_types=1);
define('NO_SESSION_TOUCH', true);
require __DIR__ . '/_init.php';

$admin = Auth::admin();
if (!$admin) {
    json_response(['ok' => false, 'error' => 'signed_out'], 401);
}
Notifier::feedResponse('admin', (int) $admin['id']);
