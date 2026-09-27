</main>
<footer class="site-footer">
  <div class="container footer-grid">
    <div>
      <div class="footer-brand"><?= e(setting('company_name', 'Cityland')) ?></div>
      <p class="muted-light">Official online bidding platform for Cityland condominium units, parking slots, properties and other assets.</p>
    </div>
    <div>
      <h4>Contact</h4>
      <p><?= e(setting('contact_address', '')) ?><br>
      <a href="mailto:<?= e(setting('contact_email', '')) ?>"><?= e(setting('contact_email', '')) ?></a><br>
      <?= e(setting('contact_phone', '')) ?></p>
    </div>
    <div>
      <h4>Legal &amp; Privacy</h4>
      <p><a href="<?= e(url('terms.php')) ?>">Terms and Conditions</a><br>
      <a href="<?= e(url('privacy.php')) ?>">Privacy Notice</a><br>
      <a href="<?= e(url('privacy.php#dpo')) ?>">Data Protection Officer</a></p>
    </div>
  </div>
  <div class="container footer-bottom">
    <span>&copy; <?= date('Y') ?> <?= e(setting('company_name', 'Cityland')) ?>. All rights reserved.</span>
    <span>All times are Philippine Standard Time (server time).</span>
  </div>
</footer>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
