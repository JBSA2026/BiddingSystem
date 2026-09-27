<?php
declare(strict_types=1);

/**
 * Determines whether a bidder may submit a bid on a property.
 * The same checks drive the on-screen checklist (Submit Bid stays disabled) and the
 * server-side enforcement in Bidding::submit().
 */
final class Eligibility
{
    /**
     * @return array{ok:bool, items:array<int,array{key:string,label:string,ok:bool,link:?string,hint:?string}>}
     */
    public static function check(?array $user, ?array $property = null): array
    {
        $items = [];
        $add = static function (string $key, string $label, bool $ok, ?string $link = null, ?string $hint = null) use (&$items): void {
            $items[] = compact('key', 'label', 'ok', 'link', 'hint');
        };

        if (!$user) {
            $add('account', 'Create an account and sign in', false, 'register.php');
            return ['ok' => false, 'items' => $items];
        }
        $uid = (int) $user['id'];

        $add('email', 'Email address verified', (bool) $user['email_verified_at'], 'verify.php');
        if (self::mobileOtpRequired()) {
            $add('mobile', 'Mobile number verified (SMS OTP)', (bool) $user['mobile_verified_at'], 'verify.php');
        }
        $profileOk = $user['full_name'] && $user['address_line'] && $user['city'] && $user['province'] && $user['mobile'] && $user['email'];
        $add('profile', 'Profile information complete', (bool) $profileOk, 'profile.php');

        // Registration-level required documents
        $docsNeedApproval = Settings::bool('require_docs_approved', true);
        $regReqs = DB::all("SELECT * FROM requirement_types WHERE scope = 'registration' AND is_required = 1 AND is_active = 1 ORDER BY sort_order");
        $docsOk = true;
        foreach ($regReqs as $r) {
            $st = self::docStatus($uid, (int) $r['id']);
            if ($st === null || $st === 'rejected' || ($docsNeedApproval && $st !== 'approved')) {
                $docsOk = false;
            }
        }
        if ($regReqs) {
            $add('documents', 'Required documents submitted' . ($docsNeedApproval ? ' and approved' : ''), $docsOk, 'documents.php',
                $docsNeedApproval ? 'Documents are reviewed by Cityland. This may take 1–2 business days.' : null);
        }

        $needApproval = Settings::bool('require_admin_approval', true) || ($property && (int) $property['require_approved_account']);
        if ($needApproval) {
            $add('approval', 'Bidder account approved by Cityland', $user['verification_status'] === 'approved', 'dashboard.php',
                $user['verification_status'] === 'rejected' ? 'Your registration was not approved. Please see your notifications or contact Cityland.' : 'Pending review by Cityland.');
        }

        $add('terms', 'Latest Terms & Conditions and Privacy Notice accepted',
            Legal::hasAcceptedCurrent($uid, 'terms') && Legal::hasAcceptedCurrent($uid, 'privacy'), 'consent.php');

        if ((int) $user['is_blacklisted']) {
            $add('blacklist', 'Account in good standing', false, null, 'This account is not permitted to bid. Please contact Cityland.');
        }

        if ($property) {
            $pid = (int) $property['id'];
            $propReqs = DB::all('SELECT rt.* FROM property_requirements pr JOIN requirement_types rt ON rt.id = pr.requirement_type_id WHERE pr.property_id = ? AND rt.is_active = 1 ORDER BY rt.sort_order', [$pid]);
            foreach ($propReqs as $r) {
                $st = self::docStatus($uid, (int) $r['id']);
                $ok = $st !== null && $st !== 'rejected' && (!$docsNeedApproval || $st === 'approved');
                $add('pdoc_' . $r['id'], 'Property requirement: ' . $r['name'], $ok, 'documents.php', $st === 'pending' ? 'Submitted — awaiting review.' : null);
            }
            if ((int) $property['require_prequalification']) {
                $access = DB::val('SELECT access_status FROM property_bidders WHERE property_id = ? AND user_id = ?', [$pid, $uid]);
                $add('prequal', 'Pre-qualified by Cityland for this property', $access === 'approved', 'property.php?ref=' . rawurlencode($property['ref_no']) . '#join',
                    $access === 'pending' ? 'Your request to join is awaiting approval.' : ($access === 'rejected' ? 'Your request to join was not approved.' : 'Request to join this bidding event.'));
            }
            if (self::depositRequired($property)) {
                $paid = (bool) DB::val("SELECT 1 FROM payments WHERE user_id = ? AND property_id = ? AND purpose = 'bid_security' AND status = 'verified' LIMIT 1", [$uid, $pid]);
                $add('deposit', 'Bid security deposit of ' . money($property['deposit_amount']) . ' verified', $paid, 'payment.php?property=' . $pid);
            }
            $add('open', 'Bidding is open for this property', $property['status'] === 'open' && strtotime($property['closing_at']) > time() && strtotime($property['opening_at']) <= time());
        }

        $ok = true;
        foreach ($items as $i) {
            $ok = $ok && $i['ok'];
        }
        return ['ok' => $ok, 'items' => $items];
    }

    public static function mobileOtpRequired(): bool
    {
        return Settings::bool('require_mobile_otp') && Sms::enabled();
    }

    public static function depositRequired(array $property): bool
    {
        return Settings::bool('bid_security_enabled') && (int) $property['deposit_required'] && (float) $property['deposit_amount'] > 0;
    }

    /** Latest document status for a requirement, or null if never uploaded. */
    public static function docStatus(int $userId, int $reqId): ?string
    {
        $s = DB::val('SELECT status FROM bidder_documents WHERE user_id = ? AND requirement_type_id = ? ORDER BY id DESC LIMIT 1', [$userId, $reqId]);
        return $s === null ? null : (string) $s;
    }
}
