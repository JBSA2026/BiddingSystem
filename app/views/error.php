<?php /** @var int $code @var string $message @var string $title */ ?>
<section class="container narrow section">
  <div class="card text-center">
    <div class="error-code"><?= (int) $code ?></div>
    <h1 class="h2"><?= e($title) ?></h1>
    <p><?= e($message) ?></p>
    <p><a class="btn btn-primary" href="<?= e(defined('IN_ADMIN') ? url('admin/') : url('')) ?>">Go to home page</a></p>
  </div>
</section>
