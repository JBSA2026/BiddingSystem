</main>
<footer class="site-footer">
  <div class="container footer-grid">
    <div>
      <div class="footer-brand"><span class="logo-chip"><img src="<?= e(asset('img/logo.png')) ?>" alt="" width="46" height="46"></span><strong><?= e(setting('company_name', 'Cityland')) ?></strong></div>
      <p>The official online bidding platform for Cityland condominium units, parking slots, properties and other assets — transparent, secure and fully audited.</p>
    </div>
    <div>
      <h4>Visit or call us</h4>
      <p><?= e(setting('contact_address', '')) ?><br>
      <?php if (setting('contact_phone')): ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) setting('contact_phone'))) ?>"><?= e(setting('contact_phone')) ?></a><br><?php endif; ?>
      <?php if (setting('contact_email')): ?><a href="mailto:<?= e(setting('contact_email')) ?>"><?= e(setting('contact_email')) ?></a><?php endif; ?></p>
    </div>
    <div>
      <h4>Legal &amp; privacy</h4>
      <p><a href="<?= e(url('terms.php')) ?>">Terms and Conditions</a><br>
      <a href="<?= e(url('privacy.php')) ?>">Privacy Notice</a><br>
      <a href="<?= e(url('privacy.php#dpo')) ?>">Data Protection Officer</a><br>
      <a href="<?= e(url('how-it-works.php')) ?>">How bidding works</a></p>
    </div>
  </div>
  <div class="container footer-bottom">
    <span>&copy; <?= date('Y') ?> <?= e(setting('company_name', 'Cityland')) ?>. All rights reserved.</span>
    <span>All times are Philippine Standard Time (server time).</span>
  </div>
</footer>
<div class="toast-stack" data-toasts aria-live="polite"></div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
