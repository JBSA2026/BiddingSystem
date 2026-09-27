<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$steps = [
    ['View Property', 'Browse properties open for bidding. Review photos, specifications, documents, schedule and the bidding rules of each property.'],
    ['Register', 'Create a bidder account with your complete name, address, mobile number, email and government-issued ID. Accept the Privacy Notice and Terms.'],
    ['Verify Account', 'Enter the code sent to your email' . (Eligibility::mobileOtpRequired() ? ' and mobile number' : '') . '. Upload required documents. Cityland reviews and approves qualified bidders.'],
    ['Review Terms', 'Read the general Terms and Conditions and any property-specific terms and rules, including automatic extension rules where enabled.'],
    ['Submit Bid', 'Once all requirements are met, the Submit Bid button is enabled. Enter your amount, choose whether to share your location, accept the terms and confirm.'],
    ['Receive Bid Confirmation', 'You immediately receive a unique bid reference number, an on-screen acknowledgment you can print or download, and an email confirmation.'],
    ['Monitor Status', 'Track your bids, verification, documents and notifications in your dashboard. Where allowed, you can see your ranking, the highest bid or number of bidders.'],
    ['Wait for Evaluation / Award', 'At the official closing time (server time) bidding locks and the property goes Under Evaluation. Cityland evaluates bidders and management approves the award. You are notified of the result.'],
];
View::header('How It Works', ['active' => 'how']);
?>
<section class="hero"><div class="container"><h1>How online bidding works</h1><p>A simple, transparent and secure process — every step is recorded in a permanent audit trail.</p></div></section>
<section class="container section">
  <div class="grid grid-4 steps-cards">
    <?php foreach ($steps as $i => [$t, $d]): ?><div class="card"><span class="num"><?= $i + 1 ?></span><h3><?= e($t) ?></h3><p class="small mb-0"><?= e($d) ?></p></div><?php endforeach; ?>
  </div>
  <div class="grid grid-2 mt-2">
    <div class="card"><h2>Fair and transparent</h2><ul>
      <li>All schedules use Cityland's server time — not your device clock.</li>
      <li>Every bid, revision and withdrawal is permanently kept; nothing is overwritten.</li>
      <li>Bidder identities are never shown to other bidders.</li>
      <li>Rankings are for evaluation only. The highest bid does not automatically win — awards require evaluation and management approval.</li>
      <li>Any change to closing times is recorded and participants are notified.</li>
    </ul></div>
    <div class="card"><h2>Frequently asked questions</h2>
      <p><strong>Why is the Submit Bid button disabled?</strong><br>All requirements (verification, documents, approval and terms) must be complete. The property page shows a checklist of what is missing.</p>
      <p><strong>Why do you ask for my location?</strong><br>Only with your permission, to strengthen the integrity of the bid record. If you decline, the record states "Location permission not granted." Some events may require it.</p>
      <p><strong>What is automatic extension?</strong><br>For properties where it is enabled, a valid bid received in the final minutes extends the closing time, as disclosed on the property page.</p>
    </div>
  </div>
  <p class="text-center"><a class="btn btn-gold btn-lg" href="<?= e(url('register.php')) ?>">Register to bid</a></p>
</section>
<?php View::footer();
