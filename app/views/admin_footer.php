    </div>
    <footer class="admin-footer">Server time: <span data-server-clock="<?= time() ?>"><?= e(date('M j, Y g:i:s A')) ?></span> (<?= e(date_default_timezone_get()) ?>) · v<?= e(APP_VERSION) ?><?php $lp = License::status()['payload']; if ($lp): ?> · Licensed to <?= e($lp['licensee']) ?> (<?= e($lp['plan'] ?? 'Standard') ?>, until <?= e(fmt_dt($lp['expires'], 'M j, Y')) ?>)<?php endif; ?></footer>
  </div>
</div>
<div class="toast-stack" data-toasts aria-live="polite"></div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
