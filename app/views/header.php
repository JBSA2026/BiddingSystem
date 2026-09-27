<?php /** @var string $pageTitle @var string $bodyClass @var string $active @var ?array $bidder @var int $unread */ ?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#101512">
<meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
<title><?= e($pageTitle ? $pageTitle . ' · ' : '') ?><?= e(setting('site_name', 'Cityland Online Property Bidding')) ?></title>
<?php if (is_testing()): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<meta name="description" content="Official Cityland online bidding for condominium units, parking slots and other properties.">
<link rel="icon" href="<?= e(asset('img/favicon-64.png')) ?>" type="image/png">
<link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
<link rel="preload" href="<?= e(url('assets/fonts/inter-var.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="<?= e($bodyClass) ?>" data-base="<?= e(base_url()) ?>"<?= $bidder ? ' data-feed="' . e(url('notifications_feed.php')) . '" data-notif-page="' . e(url('notifications.php')) . '"' : '' ?>>
<a class="skip-link" href="#main">Skip to content</a>
<?php if (is_testing()): ?><div class="test-banner" role="note"><strong>TEST ENVIRONMENT</strong> — for testing only. Bids here are not real. <a href="<?= e(url('dev/mailbox.php')) ?>">Open test mailbox</a> (verification codes &amp; emails)</div><?php endif; ?>
<header class="site-header">
  <div class="container header-inner">
    <a class="brand" href="<?= e(url('')) ?>" aria-label="<?= e(setting('company_name', 'Cityland')) ?> Online Property Bidding — home">
      <span class="logo-chip"><img src="<?= e(asset('img/logo.png')) ?>" alt="" width="46" height="46"></span>
      <span class="brand-text"><strong><?= e(setting('company_name', 'Cityland')) ?></strong><small>Online Property Bidding</small></span>
    </a>
    <nav id="site-nav" class="site-nav" aria-label="Main">
      <a href="<?= e(url('')) ?>" class="<?= $active === 'home' ? 'active' : '' ?>">Properties</a>
      <a href="<?= e(url('how-it-works.php')) ?>" class="<?= $active === 'how' ? 'active' : '' ?>">How It Works</a>
      <?php if ($bidder): ?>
        <a href="<?= e(url('dashboard.php')) ?>" class="<?= $active === 'dashboard' ? 'active' : '' ?>">My Dashboard</a>
        <form method="post" action="<?= e(url('logout.php')) ?>" class="inline-form"><?= Csrf::field() ?><button type="submit" class="btn btn-outline-light btn-sm">Sign out</button></form>
      <?php else: ?>
        <a href="<?= e(url('login.php')) ?>" class="<?= $active === 'login' ? 'active' : '' ?>">Sign in</a>
        <a href="<?= e(url('register.php')) ?>" class="btn btn-gold btn-sm">Register to Bid</a>
      <?php endif; ?>
    </nav>
    <div class="header-actions">
      <button class="icon-btn" type="button" data-theme-toggle aria-label="Switch between light and dark mode" title="Light / dark mode"><span class="theme-icon-light"><?= icon('moon') ?></span><span class="theme-icon-dark"><?= icon('sun') ?></span></button>
      <?php if ($bidder): require APP_DIR . '/views/bell.php'; endif; ?>
      <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav" aria-label="Toggle menu"><span></span><span></span><span></span></button>
    </div>
  </div>
</header>
<main id="main">
