<?php /** @var string $pageTitle @var ?array $admin @var int $unread @var int $pendingApprovals */
$nav = [
    ['index.php', 'Dashboard', 'dashboard.view'],
    ['properties.php', 'Properties', 'properties.view'],
    ['bidders.php', 'Bidders', 'bidders.view'],
    ['bids.php', 'All Bids', 'bids.view'],
    ['approvals.php', 'Approvals', 'dashboard.view'],
    ['payments.php', 'Bid Security', 'payments.view'],
    ['reports.php', 'Reports', 'reports.export'],
    ['audit.php', 'Audit Trail', 'audit.view'],
    ['requirements.php', 'Requirements', 'settings.manage'],
    ['legal.php', 'Terms & Privacy', 'settings.manage'],
    ['users.php', 'Administrators', 'users.manage'],
    ['settings.php', 'Settings', 'settings.manage'],
    ['backup.php', 'Backup', 'backup.manage'],
];
$current = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
<title><?= e($pageTitle) ?> · Admin · <?= e(setting('company_name', 'Cityland')) ?></title>
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="admin" data-base="<?= e(base_url()) ?>">
<div class="admin-shell">
  <aside class="admin-sidebar" id="admin-sidebar">
    <a class="admin-brand" href="<?= e(url('admin/')) ?>"><img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="32" height="32"> <span><?= e(setting('company_name', 'Cityland')) ?><small>Bidding Admin</small></span></a>
    <nav aria-label="Admin">
      <?php foreach ($nav as [$file, $label, $perm]): if (!can($perm)) continue; ?>
        <a href="<?= e(url('admin/' . $file)) ?>" class="<?= $current === $file ? 'active' : '' ?>"><?= e($label) ?>
          <?php if ($file === 'approvals.php' && $pendingApprovals): ?><span class="count-pill"><?= (int) $pendingApprovals ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </aside>
  <div class="admin-main">
    <header class="admin-topbar">
      <button class="nav-toggle dark" type="button" aria-controls="admin-sidebar" aria-expanded="false" aria-label="Toggle menu"><span></span><span></span><span></span></button>
      <h1 class="admin-title"><?= e($pageTitle) ?></h1>
      <div class="admin-user">
        <a href="<?= e(url('admin/notifications.php')) ?>" class="topbar-link">Notifications<?php if ($unread): ?> <span class="count-pill"><?= (int) $unread ?></span><?php endif; ?></a>
        <a href="<?= e(url('admin/account.php')) ?>" class="topbar-link"><?= e($admin['name'] ?? '') ?> <small class="muted">(<?= e(Rbac::label($admin['role'] ?? '')) ?>)</small></a>
        <form method="post" action="<?= e(url('admin/logout.php')) ?>" class="inline-form"><?= Csrf::field() ?><button class="btn btn-sm btn-outline" type="submit">Sign out</button></form>
      </div>
    </header>
    <div class="admin-content">
