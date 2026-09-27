<?php
declare(strict_types=1);

/**
 * TEST ENVIRONMENT ONLY — builds a realistic, repeatable data set for user-acceptance testing:
 * one account per admin role, bidders in every state, and properties in every bidding status
 * (with photos, documents and bids). Refuses to run unless config app.env = 'testing'.
 */
final class TestEnv
{
    public const ADMIN_PASSWORD = 'Admin@12345';
    public const BIDDER_PASSWORD = 'Bidder@12345';

    public const ADMINS = [
        ['Sofia Super (Test)', 'superadmin@test.local', 'super_admin'],
        ['Ben Bidding (Test)', 'biddingadmin@test.local', 'bidding_admin'],
        ['Alma Approver (Test)', 'approver@test.local', 'approving_officer'],
        ['Andy Auditor (Test)', 'auditor@test.local', 'auditor'],
    ];

    /** key => [name, company, email, mobile, state] */
    public const BIDDERS = [
        'b1' => ['Juan Dela Cruz', null, 'bidder1@test.local', '+639170000001', 'approved'],
        'b2' => ['Maria Santos', 'Santos Realty Holdings Inc.', 'bidder2@test.local', '+639170000002', 'approved'],
        'b3' => ['Jose Reyes', null, 'bidder3@test.local', '+639170000003', 'approved'],
        'pending' => ['Ana Pending', null, 'pending@test.local', '+639170000004', 'pending'],
        'unverified' => ['Carlo Unverified', null, 'unverified@test.local', '+639170000005', 'unverified'],
        'blacklisted' => ['Bong Blacklisted', null, 'blacklisted@test.local', '+639170000006', 'blacklisted'],
    ];

    public static function guard(): void
    {
        if (!is_testing()) {
            throw new RuntimeException('Refused: this command only runs when app.env is "testing". It must never run on the live site.');
        }
    }

    // ------------------------------------------------------------------ schema
    public static function hasSchema(): bool
    {
        return (bool) DB::val("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bids'");
    }

    public static function installSchema(): string
    {
        self::guard();
        foreach (self::splitSql((string) file_get_contents(APP_ROOT . '/install/schema.sql')) as $stmt) {
            DB::pdo()->exec($stmt);
        }
        try {
            foreach (self::splitSql((string) file_get_contents(APP_ROOT . '/install/triggers.sql')) as $stmt) {
                DB::pdo()->exec($stmt);
            }
            return 'Schema and immutability triggers installed.';
        } catch (PDOException $e) {
            return 'Schema installed; triggers skipped (' . $e->getMessage() . ').';
        }
    }

    /** Drop every table and remove uploaded files. */
    public static function wipe(): void
    {
        self::guard();
        $pdo = DB::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $t) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $uploads = storage_path('uploads');
        if (is_dir($uploads)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                if ($f->getFilename() === '.gitkeep') {
                    continue;
                }
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
        }
    }

    public static function reset(): array
    {
        self::guard();
        self::wipe();
        $log = [self::installSchema()];
        return array_merge($log, self::seed());
    }

    // ------------------------------------------------------------------ seed
    public static function seed(): array
    {
        self::guard();
        $log = [];
        $now = now();

        foreach (['company_name' => 'Cityland', 'site_name' => 'Cityland Online Property Bidding (TEST)', 'contact_email' => 'bidding@test.local',
            'contact_address' => 'Cityland Pasong Tamo Tower, Estacion St., Makati City, 1230 Metro Manila', 'contact_phone' => '(02) 8843 2704', 'dpo_phone' => '(02) 8843 2704',
            'bid_security_enabled' => '1', 'require_admin_approval' => '1', 'require_docs_approved' => '1', 'dual_auth_award' => '1', 'dual_auth_schedule' => '1',
            'payment_instructions' => "TEST ONLY — do not send real money.\nBank: Sample Bank, Account name: Cityland Development Corp. (TEST), Account no. 0000-0000-00\nGCash/Maya: 0917 000 0000 (TEST)"] as $k => $v) {
            Settings::set($k, $v);
        }

        // ---- administrators (one per role)
        $admins = [];
        foreach (self::ADMINS as [$name, $email, $role]) {
            $admins[$role] = DB::insert('admins', ['name' => $name, 'email' => $email, 'password_hash' => Security::hashPassword(self::ADMIN_PASSWORD),
                'role' => $role, 'created_at' => $now]);
        }
        $log[] = count($admins) . ' administrator accounts (password ' . self::ADMIN_PASSWORD . ')';

        // ---- bidders
        $terms = Legal::current('terms');
        $privacy = Legal::current('privacy');
        $users = [];
        $i = 10;
        foreach (self::BIDDERS as $key => [$name, $company, $email, $mobile, $state]) {
            $i++;
            $uid = DB::insert('users', [
                'bidder_no' => make_reference('BDR'), 'full_name' => $name, 'company_name' => $company,
                'address_line' => $i . ' Sample Street, Test Village', 'barangay' => 'Poblacion', 'city' => 'Makati', 'province' => 'Metro Manila', 'postal_code' => '1210',
                'email' => $email, 'mobile' => $mobile, 'password_hash' => Security::hashPassword(self::BIDDER_PASSWORD),
                'id_type' => 'PhilSys National ID', 'id_number_hash' => secure_hash('govid:TEST' . $i), 'id_number_last4' => 'T0' . $i,
                'email_verified_at' => $state === 'unverified' ? null : $now, 'verification_status' => in_array($state, ['approved', 'blacklisted'], true) ? 'approved' : 'pending',
                'verified_by' => in_array($state, ['approved', 'blacklisted'], true) ? $admins['bidding_admin'] : null, 'verified_at' => in_array($state, ['approved', 'blacklisted'], true) ? $now : null,
                'is_blacklisted' => $state === 'blacklisted' ? 1 : 0, 'blacklist_reason' => $state === 'blacklisted' ? 'TEST: submitted falsified documents in a previous bidding event' : null,
                'registration_ip' => '203.0.113.' . $i, 'created_at' => $now,
            ]);
            $users[$key] = $uid;
            if ($state !== 'unverified') {
                $doc = self::storeFile(self::pdf('Government ID — ' . $name . ' (TEST DOCUMENT)'), 'bidders', 'pdf');
                DB::insert('bidder_documents', ['user_id' => $uid, 'requirement_type_id' => 1, 'file_path' => $doc, 'original_name' => 'government-id.pdf',
                    'mime' => 'application/pdf', 'file_size' => filesize(storage_path($doc)), 'status' => $state === 'pending' ? 'pending' : 'approved',
                    'reviewed_by' => $state === 'pending' ? null : $admins['bidding_admin'], 'reviewed_at' => $state === 'pending' ? null : $now, 'created_at' => $now]);
            }
            foreach ([['privacy', $privacy], ['data_processing', $privacy], ['terms', $terms]] as [$type, $d]) {
                DB::insert('consents', ['user_id' => $uid, 'consent_type' => $type, 'legal_document_id' => $d['id'], 'version' => $d['version'],
                    'context' => 'registration', 'granted' => 1, 'ip_address' => '203.0.113.' . $i, 'user_agent' => 'Test data', 'accepted_at' => $now]);
            }
            if ($state === 'blacklisted') {
                DB::insert('bidder_flags', ['user_id' => $uid, 'flag_type' => 'blacklist', 'details' => 'TEST: blacklisted example account', 'source' => 'admin', 'created_by' => $admins['bidding_admin'], 'created_at' => $now]);
            }
        }
        DB::insert('bidder_flags', ['user_id' => $users['b3'], 'flag_type' => 'watchlist', 'details' => 'TEST: watchlist example — verify source of funds before award', 'source' => 'admin', 'created_by' => $admins['bidding_admin'], 'created_at' => $now]);
        DB::update('users', ['is_flagged' => 1], 'id = ?', [$users['b3']]);
        $log[] = count($users) . ' bidder accounts (password ' . self::BIDDER_PASSWORD . ')';

        // ---- properties
        $types = array_column(DB::all('SELECT id, name FROM property_types'), 'id', 'name');
        $mk = static function (array $d) use ($types, $admins, $now): int {
            $d += ['city' => 'Makati', 'min_increment' => 50000, 'status' => 'open', 'is_published' => 1, 'bid_mode' => 'multiple', 'higher_only' => 1,
                'contact_info' => "Cityland Bidding Team (TEST)\nCityland Pasong Tamo Tower, Estacion St., Makati City, 1230 Metro Manila\n(02) 8843 2704 · bidding@test.local",
                'terms' => '<p>TEST TERMS. The property is sold on an "as-is, where-is" basis. The winning bidder shall pay 10% of the bid price within five (5) banking days from receipt of the notice of award, and the balance per the approved payment schedule.</p>',
                'created_by' => $admins['bidding_admin'], 'created_at' => $now];
            $d['property_type_id'] = $types[$d['type']];
            unset($d['type']);
            $d['ref_no'] = Bidding::nextPropertyRef();
            $d['original_closing_at'] = $d['closing_at'];
            $d['description'] ??= "TEST LISTING — for user acceptance testing only.\n\nWell-maintained unit in a Cityland development, offered for bidding on an as-is, where-is basis. Viewing by appointment with the Cityland bidding team.";
            $id = DB::insert('properties', $d);
            Audit::log('property_created', 'property', $id, null, ['test_data' => true, 'name' => $d['name']], ['admin', $admins['bidding_admin'], 'Ben Bidding (Test)']);
            return $id;
        };
        $at = static fn(string $rel): string => date('Y-m-d H:i:00', strtotime($rel));

        $p = [];
        $p['open'] = $mk(['name' => 'TEST — 1BR Unit 1508, Cityland Pioneer Tower', 'type' => 'Condominium Unit', 'location' => 'Pioneer St. cor. Reliance St., Mandaluyong City', 'city' => 'Mandaluyong',
            'floor_area' => 36.5, 'specifications' => "1 bedroom, 1 toilet & bath\n15th floor, city view\nWith balcony", 'starting_price' => 3000000, 'opening_at' => $at('-1 day'), 'closing_at' => $at('+3 days'),
            'show_ranking' => 1, 'show_bidder_count' => 1]);
        $p['closing_soon'] = $mk(['name' => 'TEST — Studio 0912, Cityland Shaw Tower (closes in ~20 min, auto-extension ON)', 'type' => 'Condominium Unit', 'location' => 'Shaw Blvd., Mandaluyong City', 'city' => 'Mandaluyong',
            'floor_area' => 24.0, 'specifications' => "Studio unit, 9th floor\nFully tiled", 'starting_price' => 2400000, 'min_increment' => 25000, 'opening_at' => $at('-2 days'), 'closing_at' => $at('+20 minutes'),
            'show_highest' => 1, 'show_bidder_count' => 1, 'antisnipe_enabled' => 1, 'antisnipe_trigger_min' => 5, 'antisnipe_extend_min' => 5, 'antisnipe_max_ext' => 3]);
        $p['gps'] = $mk(['name' => 'TEST — Parking Slot B2-117, Cityland Makati Executive (1 bid only, GPS required)', 'type' => 'Parking Slot', 'location' => 'Makati Ave., Makati City',
            'floor_area' => 12.5, 'starting_price' => 850000, 'min_increment' => 0, 'opening_at' => $at('-3 hours'), 'closing_at' => $at('+2 days'),
            'bid_mode' => 'single', 'higher_only' => 0, 'gps_mode' => 'required']);
        $p['upcoming'] = $mk(['name' => 'TEST — Office Unit 11F-A, Cityland Condo Tower (pre-qualification + deposit)', 'type' => 'Office Unit', 'location' => 'H.V. dela Costa St., Salcedo Village, Makati City',
            'floor_area' => 72.0, 'specifications' => "Bare office unit\n11th floor, 2 parking slots available separately", 'starting_price' => 9800000, 'min_increment' => 100000,
            'opening_at' => $at('+1 hour'), 'closing_at' => $at('+5 days'), 'status' => 'upcoming', 'require_prequalification' => 1, 'allow_withdrawal' => 1,
            'deposit_required' => 1, 'deposit_amount' => 100000, 'deposit_refundable' => 1]);
        DB::insert('property_requirements', ['property_id' => $p['upcoming'], 'requirement_type_id' => 4]);
        $p['evaluation'] = $mk(['name' => 'TEST — 2BR Unit 2203, Cityland Grand Emerald Tower (under evaluation)', 'type' => 'Condominium Unit', 'location' => 'Emerald Ave., Ortigas Center, Pasig City', 'city' => 'Pasig',
            'floor_area' => 54.0, 'starting_price' => 4000000, 'opening_at' => $at('-5 days'), 'closing_at' => $at('+1 day'), 'show_ranking' => 1]);
        $p['awarded'] = $mk(['name' => 'TEST — Commercial Unit G-05, Cityland Herrera Tower (awarded)', 'type' => 'Commercial Unit', 'location' => 'V.A. Rufino St., Legaspi Village, Makati City',
            'floor_area' => 45.0, 'starting_price' => 6500000, 'opening_at' => $at('-10 days'), 'closing_at' => $at('+1 day')]);
        $p['cancelled'] = $mk(['name' => 'TEST — Lot 14, Block 3, Tagaytay Highlands Extension (cancelled)', 'type' => 'Lot', 'location' => 'Tagaytay City, Cavite', 'city' => 'Tagaytay',
            'floor_area' => 300, 'starting_price' => 5200000, 'opening_at' => $at('+2 days'), 'closing_at' => $at('+9 days'), 'status' => 'upcoming']);
        $p['draft'] = $mk(['name' => 'TEST — Parking Slot P3-020 (unpublished draft)', 'type' => 'Parking Slot', 'location' => 'Cityland Pioneer Tower, Mandaluyong City', 'city' => 'Mandaluyong',
            'starting_price' => 780000, 'min_increment' => 0, 'opening_at' => $at('+3 days'), 'closing_at' => $at('+10 days'), 'status' => 'upcoming', 'is_published' => 0]);

        // photos + documents
        $palette = [[24, 52, 20], [62, 125, 37], [34, 40, 36], [44, 95, 24], [70, 78, 72], [30, 70, 45], [52, 60, 54], [40, 88, 30]];
        $n = 0;
        foreach ($p as $key => $pid) {
            $name = (string) DB::val('SELECT name FROM properties WHERE id = ?', [$pid]);
            foreach (['Building exterior', 'Living area', 'Floor plan'] as $k => $caption) {
                $path = self::storeFile(self::image($name, $caption, $palette[($n + $k) % count($palette)]), 'properties', 'jpg');
                DB::insert('property_images', ['property_id' => $pid, 'file_path' => $path, 'original_name' => strtolower(str_replace(' ', '-', $caption)) . '.jpg', 'caption' => $caption, 'sort_order' => $k, 'created_at' => $now]);
            }
            $doc = self::storeFile(self::pdf('Property information sheet — ' . $name), 'property_docs', 'pdf');
            DB::insert('property_documents', ['property_id' => $pid, 'title' => 'Property information sheet (sample)', 'file_path' => $doc, 'original_name' => 'property-information-sheet.pdf',
                'mime' => 'application/pdf', 'file_size' => filesize(storage_path($doc)), 'is_public' => 1, 'created_by' => $admins['bidding_admin'], 'created_at' => $now]);
            $n++;
        }

        // ---- bids (submitted through the real bidding engine)
        $bid = static function (string $userKey, int $pid, string $amount, bool $gps = false) use ($users): void {
            $u = DB::one('SELECT * FROM users WHERE id = ?', [$users[$userKey]]);
            $_SERVER['REMOTE_ADDR'] = '198.51.100.' . (int) $u['id'];
            $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (TEST DATA)';
            Audit::$forceActor = ['bidder', (int) $u['id'], $u['full_name']];
            try {
                $r = Bidding::submit($u, $pid, 'bid', $amount, $gps
                    ? ['status' => 'granted', 'lat' => (string) (14.55 + mt_rand(0, 300) / 10000), 'lng' => (string) (121.02 + mt_rand(0, 300) / 10000), 'accuracy' => '15']
                    : ['status' => 'denied'], 'Test data | 1920x1080 | en-PH | Asia/Manila');
                if (!$r['ok']) {
                    throw new RuntimeException('Seed bid failed: ' . $r['error']);
                }
            } finally {
                Audit::$forceActor = null;
            }
        };
        $bid('b1', $p['open'], '3000000.00', true);
        $bid('b2', $p['open'], '3150000.00', true);
        $bid('b1', $p['open'], '3200000.00');
        $bid('b2', $p['closing_soon'], '2450000.00', true);
        foreach ([['b1', '4100000.00'], ['b2', '4350000.00'], ['b3', '4200000.00']] as [$u, $a]) {
            $bid($u, $p['evaluation'], $a, true);
        }
        foreach ([['b1', '6900000.00'], ['b3', '6800000.00'], ['b2', '6650000.00']] as [$u, $a]) {
            $bid($u, $p['awarded'], $a, true);
        }
        $system = ['system', null, 'System (test data)'];
        Bidding::close($p['evaluation'], 'manual', $system, 'TEST DATA: closed to demonstrate evaluation');
        Bidding::close($p['awarded'], 'manual', $system, 'TEST DATA: closed to demonstrate award');

        // ---- awarded example: evaluation → recommendation → approval (two different officers)
        $bAdmin = DB::one('SELECT * FROM admins WHERE id = ?', [$admins['bidding_admin']]);
        $appr = DB::one('SELECT * FROM admins WHERE id = ?', [$admins['approving_officer']]);
        Audit::$forceActor = ['admin', (int) $bAdmin['id'], $bAdmin['name']];
        Bidding::setEvalStatus($p['awarded'], $users['b1'], 'disqualified', 'TEST: failed to submit proof of financial capacity', $bAdmin);
        Bidding::setEvalStatus($p['awarded'], $users['b3'], 'qualified', 'TEST: identity, documents and funds verified', $bAdmin);
        Bidding::setEvalStatus($p['awarded'], $users['b2'], 'qualified', 'TEST: corporate documents complete', $bAdmin);
        $awardId = Bidding::recommendAward($p['awarded'], $users['b3'], [$users['b2']], 'TEST: highest bidder disqualified; recommending highest qualified bidder with backup.', $bAdmin);
        Audit::$forceActor = ['admin', (int) $appr['id'], $appr['name']];
        $memo = self::storeFile(self::pdf('Management approval memo (TEST)'), 'awards', 'pdf');
        Bidding::approveAward($awardId, $appr, 'TEST-MEMO-2026-001', 'TEST: approved per management committee', ['path' => $memo, 'original' => 'approval-memo.pdf']);
        Audit::$forceActor = ['admin', (int) $bAdmin['id'], $bAdmin['name']];
        Bidding::cancel($p['cancelled'], 'TEST: title documents under review; bidding postponed');
        Audit::$forceActor = null;

        // ---- pre-qualification request + pending deposit on the upcoming property
        DB::insert('property_bidders', ['property_id' => $p['upcoming'], 'user_id' => $users['b1'], 'access_status' => 'pending', 'eval_status' => 'under_review', 'joined_at' => $now]);
        DB::insert('payments', ['user_id' => $users['b1'], 'property_id' => $p['upcoming'], 'purpose' => 'bid_security', 'amount' => 100000, 'method' => 'gcash',
            'reference_no' => 'TEST-GCASH-0001', 'gateway' => 'manual', 'status' => 'pending', 'created_at' => $now]);

        $log[] = count($p) . ' properties (open, closing soon, GPS-required, upcoming, under evaluation, awarded, cancelled, unpublished) with photos, documents and bids';
        Audit::log('test_data_loaded', 'system', null, null, ['properties' => count($p), 'bidders' => count($users)], ['system', null, 'Test environment']);
        return $log;
    }

    // ------------------------------------------------------------------ helpers
    private static function storeFile(string $bytes, string $subdir, string $ext): string
    {
        $rel = 'uploads/' . $subdir . '/' . date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $abs = storage_path($rel);
        if (!is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0750, true);
        }
        file_put_contents($abs, $bytes);
        return $rel;
    }

    /** Generated "photo" so the gallery can be tested without real images. */
    private static function image(string $title, string $caption, array $rgb): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return (string) file_get_contents(APP_ROOT . '/assets/img/placeholder.svg');
        }
        $w = 1200;
        $h = 750;
        $im = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y++) {
            $f = $y / $h;
            imageline($im, 0, $y, $w, $y, imagecolorallocate($im, (int) ($rgb[0] + (200 - $rgb[0]) * $f * 0.5), (int) ($rgb[1] + (200 - $rgb[1]) * $f * 0.5), (int) ($rgb[2] + (220 - $rgb[2]) * $f * 0.5)));
        }
        $gold = imagecolorallocate($im, 143, 207, 111);
        $dark = imagecolorallocatealpha($im, 16, 21, 18, 30);
        $win = imagecolorallocate($im, 245, 222, 140);
        foreach ([[120, 330, 260], [300, 170, 220], [480, 260, 200], [700, 120, 240], [960, 300, 150]] as [$x, $top, $bw]) {
            imagefilledrectangle($im, $x, $top, $x + $bw, 640, $dark);
            for ($wy = $top + 20; $wy < 620; $wy += 38) {
                for ($wx = $x + 16; $wx < $x + $bw - 20; $wx += 34) {
                    if (mt_rand(0, 3) > 0) {
                        imagefilledrectangle($im, $wx, $wy, $wx + 16, $wy + 20, $win);
                    }
                }
            }
        }
        imagefilledrectangle($im, 0, 640, $w, $h, imagecolorallocate($im, 16, 21, 18));
        imagefilledrectangle($im, 40, 668, 110, 672, $gold);
        $white = imagecolorallocate($im, 255, 255, 255);
        imagestring($im, 5, 40, 684, mb_substr(preg_replace('/[^\x20-\x7E]/', '-', $title) ?? '', 0, 120), $white);
        imagestring($im, 4, 40, 708, $caption . '  |  SAMPLE IMAGE FOR TESTING', $gold);
        ob_start();
        imagejpeg($im, null, 82);
        imagedestroy($im);
        return (string) ob_get_clean();
    }

    /** Minimal valid one-page PDF. */
    private static function pdf(string $text): string
    {
        $text = preg_replace('/[^\x20-\x7E]/', '-', $text) ?? '';
        $esc = static fn(string $s): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
        $stream = "BT /F1 18 Tf 60 760 Td (" . $esc('CITYLAND - TEST DOCUMENT') . ") Tj ET\nBT /F1 12 Tf 60 730 Td (" . $esc($text) . ") Tj ET\nBT /F1 10 Tf 60 700 Td (" . $esc('Generated ' . date('Y-m-d H:i') . ' for user acceptance testing. Not a real document.') . ") Tj ET";
        $objs = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $i => $o) {
            $offsets[] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $out .= sprintf("%010d 00000 n \n", $off);
        }
        return $out . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    /** Quote-, comment- and DELIMITER-aware SQL splitter. */
    public static function splitSql(string $sql): array
    {
        $stmts = [];
        $buf = '';
        $delim = ';';
        $len = strlen($sql);
        $inStr = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            if (!$inStr && $c === '-' && substr($sql, $i, 3) === '-- ') {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl;
                continue;
            }
            if (!$inStr && ($i === 0 || $sql[$i - 1] === "\n") && strncasecmp(substr($sql, $i, 10), 'DELIMITER ', 10) === 0) {
                $nl = strpos($sql, "\n", $i);
                $delim = trim(substr($sql, $i + 10, ($nl === false ? $len : $nl) - $i - 10));
                $i = $nl === false ? $len : $nl;
                continue;
            }
            if ($c === "'") {
                if ($inStr && ($sql[$i + 1] ?? '') === "'") {
                    $buf .= "''";
                    $i++;
                    continue;
                }
                $inStr = !$inStr;
            }
            if (!$inStr && substr($sql, $i, strlen($delim)) === $delim) {
                if (trim($buf) !== '') {
                    $stmts[] = trim($buf);
                }
                $buf = '';
                $i += strlen($delim) - 1;
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $stmts[] = trim($buf);
        }
        return $stmts;
    }
}
