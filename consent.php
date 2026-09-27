<?php
/** Re-acceptance of updated Terms / Privacy Notice (new versions). */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$user = Auth::requireBidder();
$terms = Legal::current('terms');
$privacy = Legal::current('privacy');
$needTerms = !Legal::hasAcceptedCurrent((int) $user['id'], 'terms');
$needPrivacy = !Legal::hasAcceptedCurrent((int) $user['id'], 'privacy');

if (is_post()) {
    Csrf::verify();
    if (($needTerms && empty($_POST['accept_terms'])) || ($needPrivacy && (empty($_POST['accept_privacy']) || empty($_POST['accept_processing'])))) {
        flash('error', 'Please tick all boxes to continue.');
        redirect('consent.php');
    }
    if ($needTerms) {
        Legal::record((int) $user['id'], 'terms', $terms, $terms['version'], 'reacceptance');
    }
    if ($needPrivacy) {
        Legal::record((int) $user['id'], 'privacy', $privacy, $privacy['version'], 'reacceptance');
        Legal::record((int) $user['id'], 'data_processing', $privacy, $privacy['version'], 'reacceptance');
    }
    Audit::log('consent_accepted', 'user', $user['id'], null, ['terms' => $needTerms ? $terms['version'] : null, 'privacy' => $needPrivacy ? $privacy['version'] : null]);
    flash('success', 'Thank you. Your acceptance has been recorded.');
    redirect('dashboard.php');
}
View::header('Review Terms');
?>
<section class="container medium section">
  <h1>Review updated terms</h1>
  <?php if (!$needTerms && !$needPrivacy): ?>
    <div class="alert alert-success">You have accepted the latest Terms and Conditions (v<?= e($terms['version'] ?? '') ?>) and Privacy Notice (v<?= e($privacy['version'] ?? '') ?>).</div>
    <a class="btn btn-primary" href="<?= e(url('dashboard.php')) ?>">Back to dashboard</a>
  <?php else: ?>
    <form method="post" class="card"><?= Csrf::field() ?>
      <?php if ($needTerms): ?>
        <h2><?= e($terms['title']) ?> <small class="muted">v<?= e($terms['version']) ?></small></h2>
        <div class="terms-box prose"><?= safe_html($terms['content']) ?></div>
        <label class="check"><input type="checkbox" name="accept_terms" value="1" required><span>I have read and accept the Terms and Conditions (version <?= e($terms['version']) ?>).</span></label>
      <?php endif; ?>
      <?php if ($needPrivacy): ?>
        <h2 class="mt-2"><?= e($privacy['title']) ?> <small class="muted">v<?= e($privacy['version']) ?></small></h2>
        <div class="terms-box prose"><?= safe_html($privacy['content']) ?></div>
        <label class="check"><input type="checkbox" name="accept_privacy" value="1" required><span>I have read and understood the Privacy Notice (version <?= e($privacy['version']) ?>).</span></label>
        <label class="check"><input type="checkbox" name="accept_processing" value="1" required><span>I consent to the processing of my personal information for the purposes stated in the Privacy Notice, in accordance with the Data Privacy Act of 2012.</span></label>
      <?php endif; ?>
      <button class="btn btn-primary" type="submit">Accept and continue</button>
    </form>
  <?php endif; ?>
</section>
<?php View::footer();
