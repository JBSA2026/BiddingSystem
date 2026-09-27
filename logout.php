<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

Csrf::requirePost();
if ($u = Auth::bidder()) {
    Audit::log('logout', 'user', $u['id']);
}
Auth::logoutBidder();
flash('success', 'You have been signed out.');
redirect('');
