    </div>
    <footer class="admin-footer">Server time: <span data-server-clock="<?= time() ?>"><?= e(date('M j, Y g:i:s A')) ?></span> (<?= e(date_default_timezone_get()) ?>) · v<?= e(APP_VERSION) ?></footer>
  </div>
</div>
<div class="toast-stack" data-toasts aria-live="polite"></div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
