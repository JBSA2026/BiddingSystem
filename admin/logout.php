<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

Csrf::requirePost();
if ($a = Auth::admin()) {
    Audit::log('logout', 'admin', $a['id']);
}
Auth::logoutAdmin();
redirect('admin/login.php');
