<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

if (Auth::bidder()) {
    redirect('dashboard.php');
}
$regReqs = DB::all("SELECT * FROM requirement_types WHERE scope = 'registration' AND is_active = 1 ORDER BY sort_order");
$privacy = Legal::current('privacy');
$terms = Legal::current('terms');
$idTypes = ['Philippine Passport', 'Driver\'s License', 'PhilSys National ID', 'UMID', 'SSS ID', 'PRC ID', 'Postal ID', 'Voter\'s ID', 'TIN ID', 'Senior Citizen ID', 'Foreign Passport', 'Other'];
$errors = [];

if (is_post()) {
    Csrf::verify();
    keep_old($_POST);
    $ip = client_ip();
    $d = [
        'full_name' => mb_substr(input('full_name'), 0, 150), 'company_name' => mb_substr(input('company_name'), 0, 190),
        'address_line' => mb_substr(input('address_line'), 0, 255), 'barangay' => mb_substr(input('barangay'), 0, 120),
        'city' => mb_substr(input('city'), 0, 120), 'province' => mb_substr(input('province'), 0, 120), 'postal_code' => mb_substr(input('postal_code'), 0, 10),
        'email' => mb_strtolower(input('email')), 'mobile_raw' => input('mobile'), 'id_type' => input('id_type'), 'id_number' => input('id_number'),
    ];
    $password = (string) ($_POST['password'] ?? '');

    if (Throttle::tooMany('register', 'ip:' . $ip, 5, 60)) {
        $errors[] = 'Too many registration attempts from your network. Please try again later.';
    }
    if (!Captcha::verify()) {
        $errors[] = 'Human verification failed. Please try the security check again.';
    }
    if (mb_strlen($d['full_name']) < 3 || !preg_match('/^[\p{L}\p{M} .,\'-]+$/u', $d['full_name'])) {
        $errors[] = 'Please enter your full legal name (letters only).';
    }
    foreach (['address_line' => 'Street address', 'city' => 'City / Municipality', 'province' => 'Province'] as $k => $label) {
        if ($d[$k] === '') {
            $errors[] = "{$label} is required.";
        }
    }
    if ($d['postal_code'] !== '' && !preg_match('/^\d{4}$/', $d['postal_code'])) {
        $errors[] = 'Postal code must be 4 digits.';
    }
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($d['email']) > 190) {
        $errors[] = 'Please enter a valid email address.';
    }
    $mobile = normalize_mobile($d['mobile_raw']);
    if (!$mobile) {
        $errors[] = 'Please enter a valid mobile number (e.g. 0917 123 4567).';
    }
    if ($pwErr = Security::passwordError($password)) {
        $errors[] = $pwErr;
    } elseif ($password !== (string) ($_POST['password_confirm'] ?? '')) {
        $errors[] = 'Passwords do not match.';
    }
    if (!in_array($d['id_type'], $idTypes, true)) {
        $errors[] = 'Please select the type of government-issued ID.';
    }
    $idNorm = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $d['id_number']) ?? '');
    if (strlen($idNorm) < 4 || strlen($idNorm) > 30) {
        $errors[] = 'Please enter your government ID number.';
    }
    if (empty($_POST['consent_privacy']) || empty($_POST['consent_processing']) || empty($_POST['consent_terms'])) {
        $errors[] = 'You must read and accept the Privacy Notice, Data Privacy Consent, and Terms and Conditions to register.';
    }
    // Required document uploads
    $uploads = [];
    foreach ($regReqs as $r) {
        $f = Upload::files('doc_' . $r['id']);
        if ($f) {
            $uploads[(int) $r['id']] = $f[0];
        } elseif ((int) $r['is_required']) {
            $errors[] = 'Please upload: ' . $r['name'] . '.';
        }
    }

    // ---- duplicate-account detection
    $idHash = $idNorm !== '' ? secure_hash('govid:' . $idNorm) : null;
    if (!$errors) {
        if (DB::val('SELECT 1 FROM users WHERE email = ?', [$d['email']])) {
            $errors[] = 'An account with this email address already exists. Please sign in or reset your password.';
        }
        if (DB::val('SELECT 1 FROM users WHERE mobile = ?', [$mobile])) {
            $errors[] = 'This mobile number is already registered to another account. Each bidder may only have one account.';
        }
        if ($idHash && ($dupId = DB::val('SELECT id FROM users WHERE id_number_hash = ?', [$idHash]))) {
            $errors[] = 'This government ID is already registered to another account. Each bidder may only have one account. Please contact Cityland if you believe this is an error.';
            Bidding::flag((int) $dupId, 'duplicate', 'Another registration was attempted with the same government ID number from IP ' . $ip . ' (email ' . $d['email'] . ').');
        }
    }

    // Validate uploads before creating account
    $stored = [];
    if (!$errors) {
        foreach ($uploads as $rid => $file) {
            try {
                $stored[$rid] = Upload::store($file, 'bidders', Upload::BIDDER_DOC_TYPES);
            } catch (RuntimeException $e) {
                $errors[] = 'Upload problem: ' . $e->getMessage();
            }
        }
    }

    if (!$errors) {
        Throttle::hit('register', 'ip:' . $ip);
        $device = Security::deviceHash();
        DB::begin();
        try {
            do {
                $bidderNo = make_reference('BDR');
            } while (DB::val('SELECT 1 FROM users WHERE bidder_no = ?', [$bidderNo]));
            $uid = DB::insert('users', [
                'bidder_no' => $bidderNo, 'full_name' => $d['full_name'], 'company_name' => $d['company_name'] ?: null,
                'address_line' => $d['address_line'], 'barangay' => $d['barangay'] ?: null, 'city' => $d['city'], 'province' => $d['province'],
                'postal_code' => $d['postal_code'] ?: null, 'email' => $d['email'], 'mobile' => $mobile,
                'password_hash' => Security::hashPassword($password), 'id_type' => $d['id_type'], 'id_number_hash' => $idHash,
                'id_number_last4' => substr($idNorm, -4), 'registration_ip' => $ip, 'device_hash' => $device, 'created_at' => now(),
            ]);
            foreach ($stored as $rid => $s) {
                DB::insert('bidder_documents', ['user_id' => $uid, 'requirement_type_id' => $rid, 'file_path' => $s['path'], 'original_name' => $s['original'],
                    'mime' => $s['mime'], 'file_size' => $s['size'], 'status' => 'pending', 'created_at' => now()]);
            }
            Legal::record($uid, 'privacy', $privacy, $privacy['version'] ?? '1.0', 'registration');
            Legal::record($uid, 'data_processing', $privacy, $privacy['version'] ?? '1.0', 'registration');
            Legal::record($uid, 'terms', $terms, $terms['version'] ?? '1.0', 'registration');
            Audit::log('account_registered', 'user', $uid, null, [
                'bidder_no' => $bidderNo, 'name' => $d['full_name'], 'company' => $d['company_name'], 'email' => $d['email'], 'mobile' => $mobile,
                'id_type' => $d['id_type'], 'documents' => count($stored), 'privacy_version' => $privacy['version'] ?? null, 'terms_version' => $terms['version'] ?? null,
            ], ['bidder', $uid, $d['full_name']]);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // Soft duplicate signals → flag for admin review (registration still allowed)
        $normName = mb_strtolower(preg_replace('/\s+/', ' ', $d['full_name']) ?? '');
        $sameName = DB::all('SELECT id, bidder_no FROM users WHERE id <> ? AND LOWER(full_name) = ? AND LOWER(city) = ?', [$uid, $normName, mb_strtolower($d['city'])]);
        if ($sameName) {
            Bidding::flag($uid, 'duplicate', 'Possible duplicate: same name and city as ' . implode(', ', array_column($sameName, 'bidder_no')) . '.');
        }
        $sameDevice = DB::all('SELECT bidder_no FROM users WHERE id <> ? AND device_hash = ?', [$uid, $device]);
        if ($sameDevice) {
            Bidding::flag($uid, 'duplicate', 'Registered from the same browser/device as ' . implode(', ', array_column($sameDevice, 'bidder_no')) . '.');
        }
        $threshold = (int) setting('dup_ip_threshold', '3');
        $sameIp = (int) DB::val('SELECT COUNT(*) FROM users WHERE registration_ip = ? AND created_at >= ?', [$ip, date('Y-m-d H:i:s', time() - 30 * 86400)]);
        if ($threshold > 0 && $sameIp >= $threshold) {
            Bidding::flag($uid, 'suspicious', "{$sameIp} accounts registered from IP {$ip} in the last 30 days.");
        }

        $user = DB::one('SELECT * FROM users WHERE id = ?', [$uid]);
        Auth::loginBidder($user);
        Verification::sendEmail($user);
        if (Eligibility::mobileOtpRequired()) {
            Verification::sendSms($user);
        }
        clear_old();
        flash('success', 'Registration received! We sent a 6-digit verification code to ' . mask_email($user['email']) . '.');
        redirect('verify.php');
    }
}

View::header('Register as a Bidder', ['active' => 'register']);
?>
<section class="container medium section">
  <ol class="process light mb-2"><li class="done">View Property</li><li class="current">Register</li><li>Verify Account</li><li>Review Terms</li><li>Submit Bid</li></ol>
  <div class="card">
    <h1>Bidder Registration</h1>
    <p class="muted">Create your account to participate in Cityland property bidding. Fields marked <span class="req">*</span> are required. Your information is protected under the Data Privacy Act of 2012.</p>
    <?php if ($errors): ?><div class="alert alert-error"><strong>Please correct the following:</strong><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <form method="post" enctype="multipart/form-data" novalidate>
      <?= Csrf::field() ?>
      <fieldset class="fieldset"><legend>Personal / Company Information</legend>
        <div class="form-row">
          <div class="form-group"><label class="form-label" for="full_name">Full name (as shown on ID) <span class="req">*</span></label><input class="form-control" id="full_name" name="full_name" value="<?= e(old('full_name')) ?>" required autocomplete="name" maxlength="150"></div>
          <div class="form-group"><label class="form-label" for="company_name">Company name <small class="muted">(if bidding for a company)</small></label><input class="form-control" id="company_name" name="company_name" value="<?= e(old('company_name')) ?>" autocomplete="organization" maxlength="190"></div>
        </div>
        <div class="form-group"><label class="form-label" for="address_line">House/Unit No., Street, Subdivision <span class="req">*</span></label><input class="form-control" id="address_line" name="address_line" value="<?= e(old('address_line')) ?>" required autocomplete="street-address" maxlength="255"></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label" for="barangay">Barangay</label><input class="form-control" id="barangay" name="barangay" value="<?= e(old('barangay')) ?>" maxlength="120"></div>
          <div class="form-group"><label class="form-label" for="city">City / Municipality <span class="req">*</span></label><input class="form-control" id="city" name="city" value="<?= e(old('city')) ?>" required autocomplete="address-level2" maxlength="120"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label" for="province">Province / Region <span class="req">*</span></label><input class="form-control" id="province" name="province" value="<?= e(old('province')) ?>" required autocomplete="address-level1" maxlength="120"></div>
          <div class="form-group"><label class="form-label" for="postal_code">Postal code</label><input class="form-control" id="postal_code" name="postal_code" value="<?= e(old('postal_code')) ?>" inputmode="numeric" maxlength="4" autocomplete="postal-code"></div>
        </div>
      </fieldset>

      <fieldset class="fieldset"><legend>Contact &amp; Sign-in</legend>
        <div class="form-row">
          <div class="form-group"><label class="form-label" for="email">Email address <span class="req">*</span></label><input class="form-control" type="email" id="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email" maxlength="190"><span class="form-help">A verification code will be sent here.</span></div>
          <div class="form-group"><label class="form-label" for="mobile">Mobile number <span class="req">*</span></label><input class="form-control" type="tel" id="mobile" name="mobile" value="<?= e(old('mobile')) ?>" required autocomplete="tel" placeholder="0917 123 4567" maxlength="20"><?php if (Eligibility::mobileOtpRequired()): ?><span class="form-help">An SMS code will be sent here.</span><?php endif; ?></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label" for="password">Password <span class="req">*</span></label><input class="form-control" type="password" id="password" name="password" required autocomplete="new-password" minlength="<?= (int) config('security.password_min_length', 10) ?>"><span class="form-help">At least <?= (int) config('security.password_min_length', 10) ?> characters with upper- and lower-case letters and a number.</span></div>
          <div class="form-group"><label class="form-label" for="password_confirm">Confirm password <span class="req">*</span></label><input class="form-control" type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password"></div>
        </div>
      </fieldset>

      <fieldset class="fieldset"><legend>Identity Verification</legend>
        <div class="form-row">
          <div class="form-group"><label class="form-label" for="id_type">Government-issued ID type <span class="req">*</span></label>
            <select class="form-control" id="id_type" name="id_type" required><option value="">Select…</option><?php foreach ($idTypes as $t): ?><option <?= old('id_type') === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label class="form-label" for="id_number">ID number <span class="req">*</span></label><input class="form-control" id="id_number" name="id_number" value="<?= e(old('id_number')) ?>" required autocomplete="off" maxlength="40"><span class="form-help">Stored securely as a one-way hash (only the last 4 characters are visible to Cityland staff) to prevent duplicate accounts.</span></div>
        </div>
        <?php foreach ($regReqs as $r): ?>
          <div class="form-group"><label class="form-label" for="doc_<?= (int) $r['id'] ?>"><?= e($r['name']) ?> <?= (int) $r['is_required'] ? '<span class="req">*</span>' : '<small class="muted">(optional)</small>' ?></label>
            <input class="form-control" type="file" id="doc_<?= (int) $r['id'] ?>" name="doc_<?= (int) $r['id'] ?>" accept=".pdf,.jpg,.jpeg,.png" <?= (int) $r['is_required'] ? 'required' : '' ?>>
            <span class="form-help"><?= e($r['description']) ?> PDF, JPG or PNG, max <?= (int) config('app.max_upload_mb', 10) ?> MB.</span></div>
        <?php endforeach; ?>
      </fieldset>

      <fieldset class="fieldset"><legend>Data Privacy Consent</legend>
        <div class="terms-box prose"><?= safe_html($privacy['content'] ?? '') ?></div>
        <label class="check"><input type="checkbox" name="consent_privacy" value="1" <?= old('consent_privacy') ? 'checked' : '' ?> required><span>I have read and understood the <a href="<?= e(url('privacy.php')) ?>" target="_blank" rel="noopener">Privacy Notice</a> (version <?= e($privacy['version'] ?? '1.0') ?>). <span class="req">*</span></span></label>
        <label class="check"><input type="checkbox" name="consent_processing" value="1" <?= old('consent_processing') ? 'checked' : '' ?> required><span>I freely give my consent to Cityland to collect, use, store and process my personal information, including my government ID and qualification documents, for the purposes stated in the Privacy Notice, in accordance with Republic Act No. 10173 (Data Privacy Act of 2012). <span class="req">*</span></span></label>
        <label class="check"><input type="checkbox" name="consent_terms" value="1" <?= old('consent_terms') ? 'checked' : '' ?> required><span>I have read and accept the <a href="<?= e(url('terms.php')) ?>" target="_blank" rel="noopener">Bidding Terms and Conditions</a> (version <?= e($terms['version'] ?? '1.0') ?>). <span class="req">*</span></span></label>
      </fieldset>

      <fieldset class="fieldset"><legend>Human Verification</legend>
        <div class="form-group"><?= Captcha::widget() ?></div>
      </fieldset>

      <button class="btn btn-gold btn-lg btn-block" type="submit">Create my bidder account</button>
      <p class="text-center mt-2">Already have an account? <a href="<?= e(url('login.php')) ?>">Sign in</a></p>
    </form>
  </div>
</section>
<?php View::footer();
