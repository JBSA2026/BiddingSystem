<?php
/**
 * Automated end-to-end test suite (≈90 checks). Run it through tests/run-e2e.sh, which creates an
 * ISOLATED database (cityland_e2e) and a temporary web server, so your manual test data is untouched.
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');
define('BASE', rtrim((string) getenv('E2E_BASE'), '/') . '/');
define('ROOT', dirname(__DIR__));
define('STORAGE', (string) getenv('E2E_STORAGE'));
$scratch = sys_get_temp_dir() . '/cityland-e2e-files';
@mkdir($scratch, 0777, true);
$fails = 0;

final class Client
{
    public string $jar;
    public string $last = '';
    public int $code = 0;
    public string $location = '';
    public function __construct(string $name) { $this->jar = sys_get_temp_dir() . "/jar_{$name}_" . getmypid(); @unlink($this->jar); }
    public function req(string $path, ?array $post = null, bool $follow = true): string
    {
        $ch = curl_init(str_starts_with($path, 'http') ? $path : BASE . ltrim($path, '/'));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_HEADER => false]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        $this->last = (string) curl_exec($ch);
        $this->code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->location = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return $this->last;
    }
    public function csrf(): string
    {
        preg_match('/name="_csrf" value="([a-f0-9]+)"/', $this->last, $m);
        return $m[1] ?? '';
    }
    public function sessionId(): string
    {
        foreach (file($this->jar) as $l) {
            if (str_contains($l, 'CLBIDSESS')) {
                return trim(substr($l, strrpos($l, "\t") + 1));
            }
        }
        return '';
    }
    public function captcha(): string
    {
        $this->req('captcha.php');
        $raw = (string) @file_get_contents(STORAGE . '/sessions/sess_' . $this->sessionId());
        preg_match('/_captcha\|s:\d+:"([A-Z0-9]+)"/', $raw, $m);
        return $m[1] ?? '';
    }
}
function check(string $label, bool $ok, string $extra = ''): void
{
    global $fails;
    echo ($ok ? "PASS " : "FAIL ") . $label . ($ok || $extra === '' ? '' : " :: {$extra}") . "\n";
    if (!$ok) {
        $fails++;
    }
}
function flashText(string $html): string
{
    preg_match_all('/class="alert alert-[a-z]+"[^>]*>(.*?)<\/div>/s', $html, $m);
    return trim(strip_tags(implode(' | ', $m[1])));
}
function db(): PDO
{
    static $pdo;
    return $pdo ??= new PDO('mysql:host=' . getenv('E2E_DB_HOST') . ';port=' . (getenv('E2E_DB_PORT') ?: '3306') . ';dbname=' . getenv('E2E_DB_NAME') . ';charset=utf8mb4', (string) getenv('E2E_DB_USER'), (string) getenv('E2E_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone='+08:00'"]);
}
function lastMailCode(string $email): string
{
    $row = db()->query("SELECT body_text FROM email_queue WHERE to_email = " . db()->quote($email) . " AND subject LIKE 'Verify%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    preg_match('/code is:\s*(\d{6})/', (string) $row, $m);
    return $m[1] ?? '';
}

// Test files
$pdf = "$scratch/id.pdf";
file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
$img = "$scratch/photo.jpg";
if (function_exists('imagecreatetruecolor')) {
    $im = imagecreatetruecolor(640, 400);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 60, 120));
    imagejpeg($im, $img);
} else { // 1x1 JPEG when GD is not installed
    file_put_contents($img, base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='));
}
$evil = "$scratch/evil.pdf";
file_put_contents($evil, "<?php echo 'pwned'; ?>");

function register(Client $c, string $name, string $email, string $mobile, string $idno, string $pdf): string
{
    $c->req('register.php');
    $csrf = $c->csrf();
    $cap = $c->captcha();
    return $c->req('register.php', [
        '_csrf' => $csrf, 'full_name' => $name, 'company_name' => '', 'address_line' => '123 Test St.', 'barangay' => 'Poblacion',
        'city' => 'Makati', 'province' => 'Metro Manila', 'postal_code' => '1200', 'email' => $email, 'mobile' => $mobile,
        'password' => 'BidderPass123', 'password_confirm' => 'BidderPass123', 'id_type' => 'PhilSys National ID', 'id_number' => $idno,
        'consent_privacy' => '1', 'consent_processing' => '1', 'consent_terms' => '1', 'captcha' => $cap, 'website' => '',
        'doc_1' => new CURLFile($pdf, 'application/pdf', 'my-id.pdf'),
    ]);
}

// ---------------------------------------------------------------- bidder A registration
$A = new Client('A');
$html = register($A, 'Juan Dela Cruz', 'juan@example.com', '0917 123 4567', 'PSN-1234-5678-9012', $pdf);
check('Bidder A registration redirects to verify', str_contains($A->location, 'verify.php'), flashText($html));
$code = lastMailCode('juan@example.com');
check('Verification email queued with code', strlen($code) === 6);
$A->req('verify.php');
$html = $A->req('verify.php', ['_csrf' => $A->csrf(), 'action' => 'email_code', 'code' => $code]);
check('Email verified with OTP', str_contains($html, 'Verification complete'), flashText($html));

// Duplicate registrations
$D = new Client('D');
$html = register($D, 'Juan Dup', 'juan@example.com', '0918 000 0000', 'X-99999', $pdf);
check('Duplicate email rejected', str_contains($html, 'already exists'), flashText($html));
$D = new Client('D2');
$html = register($D, 'Juan Dup', 'other@example.com', '09171234567', 'X-99998', $pdf);
check('Duplicate mobile rejected', str_contains($html, 'mobile number is already registered'), flashText($html));
$D = new Client('D3');
$html = register($D, 'Juan Dup', 'other2@example.com', '09190000001', 'psn 1234 5678 9012', $pdf);
check('Duplicate government ID rejected', str_contains($html, 'government ID is already registered'), flashText($html));
$D = new Client('D4');
$html = register($D, 'Evil Upload', 'evil@example.com', '09190000002', 'EV-12345', $evil);
check('Malicious file disguised as PDF rejected', str_contains($html, 'does not match its type') || str_contains($html, 'Invalid PDF'), flashText($html));
$D = new Client('D5');
$D->req('register.php');
$html = $D->req('register.php', ['_csrf' => $D->csrf(), 'full_name' => 'Bot', 'email' => 'bot@example.com', 'captcha' => 'WRONG']);
check('Wrong CAPTCHA rejected', str_contains($html, 'Human verification failed'));
$html = $D->req('register.php', ['_csrf' => 'bad', 'full_name' => 'x']);
check('Bad CSRF token rejected (419)', $D->code === 419);

// Bid button disabled before approval
$prop = db()->query("SELECT * FROM properties WHERE id = 1")->fetch();
$html = $A->req('property.php?ref=' . $prop['ref_no']);
check('Submit Bid disabled before approval', (bool) preg_match('/id="bid-submit" disabled/', $html) && str_contains($html, 'data-eligible="0"'));
$A->req('bid.php', ['_csrf' => $A->csrf(), 'property_id' => '1', 'action' => 'bid', 'amount' => '3,000,000', 'accept_terms' => '1', 'gps_status' => 'denied']);
check('Server rejects bid from unapproved bidder', str_contains($A->last, 'not yet eligible'), flashText($A->last));

// ---------------------------------------------------------------- admin login & approval
$ADM = new Client('admin');
$ADM->req('admin/login.php');
$html = $ADM->req('admin/login.php', ['_csrf' => $ADM->csrf(), 'email' => 'admin@example.com', 'password' => 'wrong']);
check('Admin wrong password rejected', str_contains($html, 'Invalid email or password'));
$ADM->req('admin/login.php');
$html = $ADM->req('admin/login.php', ['_csrf' => $ADM->csrf(), 'email' => 'admin@example.com', 'password' => 'AdminPass123']);
check('Admin login OK → dashboard', str_contains($html, 'Registered bidders'), substr(strip_tags($html), 0, 200));
foreach (['admin/properties.php', 'admin/bidders.php', 'admin/bids.php', 'admin/approvals.php', 'admin/payments.php', 'admin/reports.php', 'admin/audit.php', 'admin/requirements.php', 'admin/legal.php', 'admin/users.php', 'admin/settings.php', 'admin/backup.php', 'admin/account.php', 'admin/notifications.php', 'admin/property_edit.php', 'admin/property_edit.php?id=1', 'admin/property_bids.php?id=1', 'admin/award.php?property=1'] as $pg) {
    $ADM->req($pg);
    check("Admin page {$pg} renders", $ADM->code === 200 && !str_contains($ADM->last, 'Something went wrong') && !str_contains($ADM->last, 'Warning:') && !str_contains($ADM->last, 'Fatal'), (string) $ADM->code . ' ' . substr(strip_tags($ADM->last), 0, 300));
}
$ADM->req('admin/settings.php');
$ADM->req('admin/settings.php', ['_csrf' => $ADM->csrf(), 'action' => 'save_email_test', 'mail_driver' => 'log', 'mail_host' => 'mail.example.com', 'mail_port' => '465',
    'mail_encryption' => 'ssl', 'mail_username' => 'bidding@example.com', 'mail_password' => 'MailPass!23', 'mail_from_email' => 'bidding@example.com', 'mail_from_name' => 'Cityland Bidding', 'mail_verify_ssl' => '1']);
$encPw = (string) db()->query("SELECT svalue FROM settings WHERE skey='mail_password_enc'")->fetchColumn();
check('Admin can edit email settings (password stored encrypted)', str_contains($ADM->last, 'test email was sent') && str_starts_with($encPw, 'v1:') && !str_contains($encPw, 'MailPass'), flashText($ADM->last));
$ADM->req('admin/settings.php', ['_csrf' => $ADM->csrf(), 'action' => 'save_email', 'mail_driver' => 'smtp', 'mail_host' => 'bad host!', 'mail_port' => '465', 'mail_from_email' => 'x']);
check('Invalid email settings rejected', str_contains($ADM->last, 'valid SMTP host') && db()->query("SELECT svalue FROM settings WHERE skey='mail_driver'")->fetchColumn() === 'log', flashText($ADM->last));
$uidA = (int) db()->query("SELECT id FROM users WHERE email='juan@example.com'")->fetchColumn();
$docA = (int) db()->query("SELECT id FROM bidder_documents WHERE user_id=$uidA")->fetchColumn();
$ADM->req("admin/bidder_view.php?id=$uidA");
check('Bidder view renders', $ADM->code === 200 && str_contains($ADM->last, 'Juan Dela Cruz'));
$ADM->req("admin/bidder_view.php", ['_csrf' => $ADM->csrf(), 'id' => (string) $uidA, 'action' => 'doc', 'doc_id' => (string) $docA, 'decision' => 'approved', 'remarks' => 'Clear copy']);
$ADM->req("admin/bidder_view.php", ['_csrf' => $ADM->csrf(), 'id' => (string) $uidA, 'action' => 'qualify', 'decision' => 'approved', 'remarks' => '']);
check('Bidder A approved', db()->query("SELECT verification_status FROM users WHERE id=$uidA")->fetchColumn() === 'approved', flashText($ADM->last));

// ---------------------------------------------------------------- bidding
$html = $A->req('property.php?ref=' . $prop['ref_no']);
check('Submit Bid eligible after approval', str_contains($html, 'data-eligible="1"'), flashText($html));
$A->req('bid.php', ['_csrf' => $A->csrf(), 'property_id' => '1', 'action' => 'bid', 'amount' => '2,000,000', 'accept_terms' => '1', 'gps_status' => 'denied']);
check('Bid below minimum rejected', str_contains($A->last, 'at least the minimum'), flashText($A->last));
$A->req('property.php?ref=' . $prop['ref_no']);
$A->req('bid.php', ['_csrf' => $A->csrf(), 'property_id' => '1', 'action' => 'bid', 'amount' => '3,000,000.00', 'accept_terms' => '1',
    'gps_status' => 'granted', 'gps_lat' => '14.5547', 'gps_lng' => '121.0244', 'gps_accuracy' => '12.5', 'device_info' => 'Linux | 1920x1080 | en-PH']);
check('Bid accepted → acknowledgment', str_contains($A->location, 'acknowledgment.php') && str_contains($A->last, 'BID-'), flashText($A->last));
preg_match('/BID-\d{8}-[A-Z0-9]{6}/', $A->last, $m);
$bidRef1 = $m[0] ?? '';
check('Acknowledgment shows amount and reference', str_contains($A->last, '₱3,000,000.00') && $bidRef1 !== '');
$A->req('acknowledgment.php?ref=' . $bidRef1 . '&download=1');
check('Acknowledgment downloadable', str_contains($A->last, '<!doctype html>') && str_contains($A->last, $bidRef1));
$A->req('property.php?ref=' . $prop['ref_no']);
$A->req('bid.php', ['_csrf' => $A->csrf(), 'property_id' => '1', 'action' => 'bid', 'amount' => '3,020,000', 'accept_terms' => '1', 'gps_status' => 'denied']);
check('Revision below increment rejected (higher-only + ₱50k increment)', str_contains($A->last, 'higher than your previous bid'), flashText($A->last));
$A->req('property.php?ref=' . $prop['ref_no']);
$A->req('bid.php', ['_csrf' => $A->csrf(), 'property_id' => '1', 'action' => 'bid', 'amount' => '3,100,000', 'accept_terms' => '1', 'gps_status' => 'denied']);
check('Valid revision accepted', str_contains($A->location, 'acknowledgment.php'), flashText($A->last));
$rows = db()->query("SELECT bid_type, amount, gps_status, gps_note, gps_lat FROM bids WHERE user_id=$uidA ORDER BY id")->fetchAll();
check('Both bids retained (initial + revision)', count($rows) === 2 && $rows[0]['bid_type'] === 'initial' && $rows[1]['bid_type'] === 'revision' && $rows[0]['amount'] === '3000000.00');
check('GPS granted recorded with coordinates', $rows[0]['gps_status'] === 'granted' && (float) $rows[0]['gps_lat'] === 14.5547);
check('GPS denial recorded as "Location permission not granted."', $rows[1]['gps_note'] === 'Location permission not granted.');
$A->req('property.php?ref=' . $prop['ref_no']);
$A->req('bid.php', ['_csrf' => $A->csrf(), 'property_id' => '1', 'action' => 'bid', 'amount' => '3,500,000', 'gps_status' => 'denied']);
check('Bid without accepting terms rejected', str_contains($A->last, 'must accept the Terms'), flashText($A->last));

// Immutability at DB level
try {
    db()->exec("UPDATE bids SET amount = 1 WHERE user_id = $uidA");
    check('DB trigger blocks bid UPDATE', false);
} catch (PDOException $e) {
    check('DB trigger blocks bid UPDATE', str_contains($e->getMessage(), 'immutable'));
}
try {
    db()->exec("DELETE FROM audit_logs LIMIT 1");
    check('DB trigger blocks audit DELETE', false);
} catch (PDOException $e) {
    check('DB trigger blocks audit DELETE', str_contains($e->getMessage(), 'immutable'));
}

// ---------------------------------------------------------------- bidder B
$B = new Client('B');
register($B, 'Maria Santos', 'maria@example.com', '0928 765 4321', 'DL-N01-23-456789', $pdf);
$B->req('verify.php');
$B->req('verify.php', ['_csrf' => $B->csrf(), 'action' => 'email_code', 'code' => lastMailCode('maria@example.com')]);
$uidB = (int) db()->query("SELECT id FROM users WHERE email='maria@example.com'")->fetchColumn();
$docB = (int) db()->query("SELECT id FROM bidder_documents WHERE user_id=$uidB")->fetchColumn();
$ADM->req("admin/bidder_view.php?id=$uidB");
$ADM->req("admin/bidder_view.php", ['_csrf' => $ADM->csrf(), 'id' => (string) $uidB, 'action' => 'doc', 'doc_id' => (string) $docB, 'decision' => 'approved']);
$ADM->req("admin/bidder_view.php", ['_csrf' => $ADM->csrf(), 'id' => (string) $uidB, 'action' => 'qualify', 'decision' => 'approved']);
$B->req('property.php?ref=' . $prop['ref_no']);
$B->req('bid.php', ['_csrf' => $B->csrf(), 'property_id' => '1', 'action' => 'bid', 'amount' => '3,300,000', 'accept_terms' => '1', 'gps_status' => 'denied']);
check('Bidder B bid accepted', str_contains($B->location, 'acknowledgment.php'), flashText($B->last));
$html = $B->req('property.php?ref=' . $prop['ref_no']);
check('Bidder B sees own ranking #1 (show_ranking on)', str_contains($html, 'Your current ranking: <strong>#1</strong>'));
check('Bidder B never sees bidder A identity', !str_contains($html, 'Juan'));
$html = $A->req('dashboard.php');
check('Bidder A dashboard renders with ranking #2', str_contains($html, '#2') && str_contains($html, 'My bid history'));
$A->req("acknowledgment.php?ref=" . db()->query("SELECT bid_ref FROM bids WHERE user_id=$uidB LIMIT 1")->fetchColumn());
check("Bidder A cannot open bidder B's acknowledgment", $A->code === 404);
$A->req("file.php?t=bdoc&id=$docB");
check("Bidder A cannot download bidder B's document", $A->code === 404);

// ---------------------------------------------------------------- anti-sniping
db()->exec("UPDATE properties SET closing_at = DATE_ADD(NOW(), INTERVAL 2 MINUTE) WHERE id = 4");
$p4 = db()->query("SELECT * FROM properties WHERE id = 4")->fetch();
$A->req('property.php?ref=' . $p4['ref_no']);
$A->req('bid.php', ['_csrf' => $A->csrf(), 'property_id' => '4', 'action' => 'bid', 'amount' => '9,500,000', 'accept_terms' => '1', 'gps_status' => 'denied']);
$p4b = db()->query("SELECT * FROM properties WHERE id = 4")->fetch();
check('Anti-sniping extends closing by 5 minutes', strtotime($p4b['closing_at']) - strtotime($p4['closing_at']) === 300 && (int) $p4b['extensions_used'] === 1, $p4['closing_at'] . ' -> ' . $p4b['closing_at']);

// ---------------------------------------------------------------- admin controls
$ADM->req('admin/property_bids.php?id=1');
check('Admin ranking shows both bidders + GPS', str_contains($ADM->last, 'Juan Dela Cruz') && str_contains($ADM->last, 'Maria Santos') && str_contains($ADM->last, '14.5547'));
check('Bid chain verified', str_contains($ADM->last, 'Verified</span>'));
$ADM->req('admin/property_bids.php', ['_csrf' => $ADM->csrf(), 'id' => '1', 'action' => 'schedule', 'closing_at' => date('Y-m-d\TH:i', time() + 86400 * 10), 'reason' => 'Extended due to holiday']);
check('Schedule change goes to dual-auth approval queue', (int) db()->query("SELECT COUNT(*) FROM approval_requests WHERE status='pending'")->fetchColumn() === 1, flashText($ADM->last));
$ADM->req('admin/approvals.php');
check('Requester cannot approve own schedule change', !str_contains($ADM->last, 'name="decision" value="approve"'));

// Create approving officer
$ADM->req('admin/users.php');
$ADM->req('admin/users.php', ['_csrf' => $ADM->csrf(), 'action' => 'create', 'name' => 'Ana Approver', 'email' => 'approver@example.com', 'role' => 'approving_officer', 'password' => 'Approver123x']);
$AP = new Client('approver');
$AP->req('admin/login.php');
$AP->req('admin/login.php', ['_csrf' => $AP->csrf(), 'email' => 'approver@example.com', 'password' => 'Approver123x']);
check('New admin forced to change temp password', str_contains($AP->location, 'account.php'));
$AP->req('admin/account.php', ['_csrf' => $AP->csrf(), 'action' => 'password', 'current_password' => 'Approver123x', 'password' => 'Approver456y', 'password_confirm' => 'Approver456y']);
$AP->req('admin/properties.php');
check('Approving officer cannot manage properties (edit hidden)', !str_contains($AP->last, 'property_edit.php?id='));
$AP->req('admin/property_edit.php?id=1');
check('Approving officer denied property edit (403)', $AP->code === 403);
$AP->req('admin/users.php');
check('Approving officer denied user management (403)', $AP->code === 403);
$AP->req('admin/approvals.php');
$rid = (int) db()->query("SELECT id FROM approval_requests WHERE status='pending'")->fetchColumn();
$AP->req('admin/approvals.php', ['_csrf' => $AP->csrf(), 'request_id' => (string) $rid, 'decision' => 'approve', 'remarks' => 'OK', 'confirm_password' => 'Approver456y']);
$newClose = db()->query("SELECT closing_at FROM properties WHERE id=1")->fetchColumn();
check('Second officer approved schedule change', date('Y-m-d', strtotime($newClose)) === date('Y-m-d', time() + 86400 * 10), flashText($AP->last));

// Close bidding manually
$ADM->req('admin/property_bids.php?id=1');
$ADM->req('admin/property_bids.php', ['_csrf' => $ADM->csrf(), 'id' => '1', 'action' => 'close', 'reason' => 'Test early close', 'confirm_password' => 'AdminPass123']);
$st = db()->query("SELECT status FROM properties WHERE id=1")->fetchColumn();
check('Manual close → Under Evaluation', $st === 'under_evaluation', flashText($ADM->last));
check('Ranking snapshot locked', (int) db()->query("SELECT COUNT(*) FROM ranking_snapshots WHERE property_id=1")->fetchColumn() === 2);
$B->req('property.php?ref=' . $prop['ref_no']);
$B->req('bid.php', ['_csrf' => $B->csrf(), 'property_id' => '1', 'action' => 'bid', 'amount' => '4,000,000', 'accept_terms' => '1', 'gps_status' => 'denied']);
check('Bids rejected after close', str_contains($B->last, 'not yet eligible') || str_contains($B->last, 'not open'), flashText($B->last));

// Award workflow: can't recommend without qualified
$ADM->req('admin/award.php?property=1');
check('Award page warns no qualified bidders', str_contains($ADM->last, 'No bidders are marked Qualified'));
$ADM->req('admin/property_bids.php?id=1');
$ADM->req('admin/property_bids.php', ['_csrf' => $ADM->csrf(), 'id' => '1', 'action' => 'eval', 'user_id' => (string) $uidB, 'eval_status' => 'disqualified', 'remarks' => 'Incomplete financial docs']);
$ADM->req('admin/property_bids.php', ['_csrf' => $ADM->csrf(), 'id' => '1', 'action' => 'eval', 'user_id' => (string) $uidA, 'eval_status' => 'qualified', 'remarks' => 'All good']);
$ADM->req('admin/property_bids.php?id=1');
check('Recommendation = highest QUALIFIED bidder (A, not higher B)', (bool) preg_match('/highest <strong>qualified<\/strong> bidder is <strong>Juan Dela Cruz/', $ADM->last));
$ADM->req('admin/award.php?property=1');
$ADM->req('admin/award.php', ['_csrf' => $ADM->csrf(), 'property_id' => '1', 'action' => 'recommend', 'winner_id' => (string) $uidA, 'remarks' => 'B disqualified; A fully compliant.']);
check('Award recommended → pending (not awarded)', db()->query("SELECT status FROM properties WHERE id=1")->fetchColumn() === 'under_evaluation' && db()->query("SELECT status FROM awards WHERE property_id=1")->fetchColumn() === 'pending_approval', flashText($ADM->last));
$ADM->req('admin/award.php?property=1');
check('Recommender cannot approve own recommendation (dual auth)', str_contains($ADM->last, 'different Approving Officer'));
$AP->req('admin/award.php?property=1');
$awardId = (int) db()->query("SELECT id FROM awards WHERE property_id=1")->fetchColumn();
$AP->req('admin/award.php', ['_csrf' => $AP->csrf(), 'property_id' => '1', 'action' => 'approve', 'award_id' => (string) $awardId, 'approval_reference' => 'MEMO-2026-001', 'remarks' => 'Approved by management', 'confirm_password' => 'Approver456y', 'confirm' => '1', 'supporting_doc' => new CURLFile($pdf, 'application/pdf', 'memo.pdf')]);
check('Award approved → property AWARDED', db()->query("SELECT status FROM properties WHERE id=1")->fetchColumn() === 'awarded', flashText($AP->last));
$statuses = db()->query("SELECT user_id, eval_status FROM property_bidders WHERE property_id=1")->fetchAll(PDO::FETCH_KEY_PAIR);
check('Winner=winning, disqualified stays disqualified', $statuses[$uidA] === 'winning' && $statuses[$uidB] === 'disqualified');
check('Winning bidder email queued', (int) db()->query("SELECT COUNT(*) FROM email_queue WHERE to_email='juan@example.com' AND subject LIKE 'Congratulations%'")->fetchColumn() === 1);
$html = $A->req('dashboard.php');
check('Bidder dashboard shows Winning Bidder', str_contains($html, 'Winning Bidder'));

// Reports / audit / backup
$ADM->req('admin/reports.php?export=bids');
check('CSV bids export', str_starts_with($ADM->last, "\xEF\xBB\xBF") && str_contains($ADM->last, $bidRef1));
$ADM->req('admin/reports.php?export=properties');
$lines = array_map('str_getcsv', array_filter(explode("\n", substr($ADM->last, 3))));
check('CSV properties export columns aligned', count($lines[0]) === count($lines[1]), count($lines[0]) . ' vs ' . count($lines[1]));
$ADM->req('admin/reports.php?export=awards');
$lines = array_map('str_getcsv', array_filter(explode("\n", substr($ADM->last, 3))));
check('CSV awards export columns aligned', count($lines[0]) === count($lines[1] ?? []), count($lines[0]) . ' vs ' . count($lines[1] ?? []));
$ADM->req('admin/audit.php');
$ADM->req('admin/audit.php', ['_csrf' => $ADM->csrf()]);
check('Audit chain verifies', str_contains($ADM->last, 'Integrity verified'), flashText($ADM->last));
$ADM->req('admin/backup.php');
$ADM->req('admin/backup.php', ['_csrf' => $ADM->csrf(), 'action' => 'create', 'include_files' => '1']);
check('Backup created', count(glob(STORAGE . '/backups/db-*.sql*')) >= 1 && (!class_exists('ZipArchive') || count(glob(STORAGE . '/backups/files-*.zip')) >= 1), flashText($ADM->last));

// Property create with image upload
$ADM->req('admin/property_edit.php');
$ADM->req('admin/property_edit.php', ['_csrf' => $ADM->csrf(), 'id' => '0', 'action' => 'save', 'name' => 'Test Lot', 'property_type_id' => '6', 'location' => 'Tagaytay', 'city' => 'Tagaytay',
    'starting_price' => '1,500,000', 'min_increment' => '', 'opening_at' => date('Y-m-d\TH:i', time() + 3600), 'closing_at' => date('Y-m-d\TH:i', time() + 86400 * 3),
    'bid_mode' => 'multiple', 'gps_mode' => 'required', 'higher_only' => '1', 'require_approved_account' => '1', 'is_published' => '1', 'terms' => '<p onclick="x()">Terms <script>alert(1)</script></p>']);
$newId = (int) db()->query("SELECT MAX(id) FROM properties")->fetchColumn();
check('Property created', str_contains($ADM->location, 'property_edit.php?id=' . $newId), flashText($ADM->last));
$ADM->req('admin/property_edit.php', ['_csrf' => $ADM->csrf(), 'id' => (string) $newId, 'action' => 'upload_images', 'caption' => 'Front', 'images[]' => new CURLFile($img, 'image/jpeg', 'front.jpg')]);
$imgId = (int) db()->query("SELECT id FROM property_images WHERE property_id=$newId")->fetchColumn();
check('Image uploaded', $imgId > 0, flashText($ADM->last));
$pub = new Client('pub');
$pub->req("file.php?t=img&id=$imgId");
check('Public can view published property image', $pub->code === 200 && strlen($pub->last) > 100);
$html = $pub->req('property.php?id=' . $newId);
check('Stored XSS in terms neutralised', !str_contains($html, '<script>alert(1)') && !str_contains($html, 'onclick'));
check('GPS required disclosed on page', str_contains($html, 'Location sharing is REQUIRED'));
$html = $pub->req('?q=Tagaytay&status=upcoming');
check('Search/filter finds new property', str_contains($html, 'Test Lot'));
$html = $pub->req('?q=' . urlencode('"><script>alert(1)</script>'));
check('Reflected XSS in search escaped', !str_contains($html, '<script>alert(1)</script>'));

// Brute force
$X = new Client('x');
for ($i = 0; $i < 6; $i++) {
    $X->req('login.php');
    $X->req('login.php', ['_csrf' => $X->csrf(), 'email' => 'maria@example.com', 'password' => 'nope' . $i, 'captcha' => $i >= 3 ? $X->captcha() : '']);
}
check('Account locked after repeated failures', str_contains($X->last, 'Too many failed sign-in attempts'), flashText($X->last));

// Cron
$out = shell_exec('php ' . ROOT . '/cron.php 2>&1');
check('Cron runs', str_contains((string) $out, 'Status sync complete'), (string) $out);

$err = @file_get_contents(STORAGE . '/logs/php-error.log');
check('No PHP errors logged', !$err, (string) $err);
echo "\n" . ($fails ? "{$fails} FAILURE(S)" : 'ALL PASSED') . "\n";
exit($fails ? 1 : 0);
