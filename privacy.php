<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$doc = Legal::current('privacy');
$gps = Legal::current('gps_consent');
$years = (int) setting('data_retention_years', '10');
View::header('Privacy Notice');
?>
<section class="container medium section">
  <div class="card">
    <h1><?= e($doc['title'] ?? 'Privacy Notice') ?></h1>
    <p class="muted">Version <?= e($doc['version'] ?? '') ?> · effective <?= e(fmt_dt($doc['created_at'] ?? null, 'F j, Y')) ?></p>
    <div class="prose"><?= safe_html($doc['content'] ?? '') ?></div>
  </div>
  <div class="card">
    <h2>Location (GPS) consent</h2>
    <div class="prose"><?= safe_html($gps['content'] ?? '') ?></div>
  </div>
  <div class="card" id="retention">
    <h2>Data retention policy</h2>
    <ul>
      <li>Bid records, ranking snapshots, award decisions, consent records and audit logs are retained for <strong><?= $years ?> years</strong> after the close of the relevant bidding event, to meet legal, audit, tax and dispute-resolution requirements.</li>
      <li>Registration data and uploaded documents of bidders who never participated in a bidding event are retained for up to 3 years from last activity, then securely deleted or anonymized upon request or periodic review.</li>
      <li>Location data is only captured at the moment of bid submission with your permission and is retained as part of that bid record.</li>
      <li>Backups are encrypted/access-controlled and rotated; deleted data ages out of backups according to the backup rotation schedule.</li>
    </ul>
  </div>
  <div class="card" id="rights">
    <h2>Your rights as a data subject</h2>
    <p>Under the Data Privacy Act of 2012 (RA 10173), you have the right to be informed, to access, to object, to erasure or blocking, to rectification, to data portability, to damages, and to lodge a complaint with the National Privacy Commission (<a href="https://privacy.gov.ph" target="_blank" rel="noopener">privacy.gov.ph</a>). Some records (e.g. submitted bids and audit logs) cannot be erased while a legal retention obligation applies, but may be blocked from further processing.</p>
  </div>
  <div class="card" id="dpo">
    <h2>Privacy concerns — Data Protection Officer</h2>
    <table class="kv">
      <tr><th>Name</th><td><?= e(setting('dpo_name', 'Data Protection Officer')) ?></td></tr>
      <tr><th>Email</th><td><a href="mailto:<?= e(setting('dpo_email', '')) ?>"><?= e(setting('dpo_email', '')) ?></a></td></tr>
      <tr><th>Phone</th><td><?= e(setting('dpo_phone', '')) ?></td></tr>
      <tr><th>Address</th><td><?= e(setting('contact_address', '')) ?></td></tr>
    </table>
  </div>
</section>
<?php View::footer();
