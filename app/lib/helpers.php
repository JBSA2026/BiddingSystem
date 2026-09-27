<?php
declare(strict_types=1);

/** Read a config value using dot notation, e.g. config('db.host'). */
function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['__config'] ?? [];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

/** Read a database-backed setting (admin-configurable). */
function setting(string $key, ?string $default = null): ?string
{
    return Settings::get($key, $default);
}

/** HTML-escape output. Use for EVERY untrusted value printed into HTML. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(): string
{
    return rtrim((string) config('app.url', ''), '/');
}

function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = APP_ROOT . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? substr((string) filemtime($file), -6) : APP_VERSION;
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function storage_path(string $path = ''): string
{
    $base = rtrim((string) config('app.storage_path', APP_ROOT . '/storage'), '/');
    return $base . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function redirect(string $path): never
{
    $target = preg_match('#^https?://#i', $path) ? $path : url($path);
    header('Location: ' . $target, true, 303);
    exit;
}

function abort(int $code, string $message = ''): never
{
    http_response_code($code);
    $titles = [400 => 'Bad Request', 403 => 'Access Denied', 404 => 'Not Found', 405 => 'Method Not Allowed', 419 => 'Session Expired', 429 => 'Too Many Requests'];
    $title = $titles[$code] ?? 'Error';
    $message = $message ?: ($code === 404 ? 'The page or record you requested could not be found.' : 'You are not allowed to perform this action.');
    if (!defined('IN_ADMIN')) {
        View::render('error', ['title' => $title, 'code' => $code, 'message' => $message]);
    } else {
        View::adminRender('error', ['title' => $title, 'code' => $code, 'message' => $message]);
    }
    exit;
}

/** Current server time string in application timezone. */
function now(): string
{
    return date('Y-m-d H:i:s');
}

function money(mixed $amount): string
{
    if ($amount === null || $amount === '') {
        return '—';
    }
    return '₱' . number_format((float) $amount, 2);
}

function fmt_dt(?string $dt, string $format = 'M j, Y g:i A'): string
{
    if (!$dt) {
        return '—';
    }
    $ts = strtotime($dt);
    return $ts ? date($format, $ts) : '—';
}

function fmt_dt_precise(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    try {
        $d = new DateTimeImmutable($dt);
        return $d->format('M j, Y g:i:s.v A');
    } catch (Throwable) {
        return e($dt);
    }
}

function client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $trusted = (array) config('security.trusted_proxies', []);
    if ($trusted && in_array($remote, $trusted, true)) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', (string) $_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
    }
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
}

function user_agent(): string
{
    return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    $trusted = (array) config('security.trusted_proxies', []);
    return $trusted && in_array($_SERVER['REMOTE_ADDR'] ?? '', $trusted, true)
        && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Trimmed string input from POST. */
function input(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** Trimmed string input from GET. */
function query(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function query_int(string $key, int $default = 0): int
{
    $v = $_GET[$key] ?? null;
    return (is_string($v) && ctype_digit($v)) ? (int) $v : $default;
}

function input_int(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? null;
    return (is_string($v) && ctype_digit($v)) ? (int) $v : $default;
}

/** Parse a money amount like "5,500,000.00" into a normalized decimal string, or null if invalid. */
function parse_amount(string $raw): ?string
{
    $clean = str_replace([',', '₱', ' ', 'PHP', 'php'], '', $raw);
    if (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', $clean)) {
        return null;
    }
    return number_format((float) $clean, 2, '.', '');
}

/** Compare decimal strings safely (no float rounding surprises for 2dp amounts). */
function amount_cents(string|float|int|null $v): int
{
    return (int) round(((float) $v) * 100);
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

/** Remember form input for re-display after validation errors. */
function old(string $key, string $default = ''): string
{
    return (string) ($_SESSION['_old'][$key] ?? $default);
}

function keep_old(array $data): void
{
    unset($data['password'], $data['password_confirm'], $data['_csrf'], $data['current_password']);
    $_SESSION['_old'] = $data;
}

function clear_old(): void
{
    unset($_SESSION['_old']);
}

function random_code(int $digits = 6): string
{
    return str_pad((string) random_int(0, (10 ** $digits) - 1), $digits, '0', STR_PAD_LEFT);
}

function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

/** Keyed hash with the application secret (for OTPs, tokens, ID numbers). */
function secure_hash(string $value): string
{
    return hash_hmac('sha256', $value, (string) config('app.secret_key'));
}

/** Readable unique reference: PREFIX-YYYYMMDD-XXXXXX (no ambiguous chars). */
function make_reference(string $prefix): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 6; $i++) {
        $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $prefix . '-' . date('Ymd') . '-' . $s;
}

function normalize_mobile(string $raw): ?string
{
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if (str_starts_with($d, '63') && strlen($d) === 12) {
        return '+' . $d;
    }
    if (str_starts_with($d, '09') && strlen($d) === 11) {
        return '+63' . substr($d, 1);
    }
    if (str_starts_with($d, '9') && strlen($d) === 10) {
        return '+63' . $d;
    }
    // Allow international numbers in E.164 form (8–15 digits) when entered with +
    if (str_starts_with(trim($raw), '+') && strlen($d) >= 8 && strlen($d) <= 15) {
        return '+' . $d;
    }
    return null;
}

function mask_email(string $email): string
{
    [$u, $d] = array_pad(explode('@', $email, 2), 2, '');
    return mb_substr($u, 0, 2) . str_repeat('•', max(1, mb_strlen($u) - 2)) . '@' . $d;
}

function mask_mobile(string $m): string
{
    return substr($m, 0, 4) . str_repeat('•', max(0, strlen($m) - 7)) . substr($m, -3);
}

function status_label(string $status): string
{
    return [
        'upcoming' => 'Upcoming', 'open' => 'Open', 'closed' => 'Closed',
        'under_evaluation' => 'Under Evaluation', 'awarded' => 'Awarded', 'cancelled' => 'Cancelled',
        'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected',
        'under_review' => 'Under Review', 'qualified' => 'Qualified', 'disqualified' => 'Disqualified',
        'winning' => 'Winning Bidder', 'backup' => 'Backup Bidder', 'not_awarded' => 'Not Awarded',
        'not_required' => 'Not Required', 'verified' => 'Verified', 'refunded' => 'Refunded', 'forfeited' => 'Forfeited',
        'pending_approval' => 'Pending Approval', 'initial' => 'Initial Bid', 'revision' => 'Revised Bid', 'withdrawal' => 'Withdrawal',
        'granted' => 'Granted', 'denied' => 'Denied', 'unavailable' => 'Unavailable', 'not_requested' => 'Not Requested',
    ][$status] ?? ucwords(str_replace('_', ' ', $status));
}

function status_badge(string $status): string
{
    $cls = match ($status) {
        'open', 'approved', 'qualified', 'verified', 'winning', 'granted' => 'badge-green',
        'upcoming', 'pending', 'under_review', 'pending_approval' => 'badge-blue',
        'under_evaluation', 'backup' => 'badge-amber',
        'awarded' => 'badge-gold',
        'cancelled', 'rejected', 'disqualified', 'denied', 'forfeited' => 'badge-red',
        default => 'badge-grey',
    };
    return '<span class="badge ' . $cls . '">' . e(status_label($status)) . '</span>';
}

function yes_no(mixed $v): string
{
    return $v ? 'Yes' : 'No';
}

/** Human "time left" from seconds. */
function human_remaining(int $seconds): string
{
    if ($seconds <= 0) {
        return '00:00';
    }
    $d = intdiv($seconds, 86400);
    $h = intdiv($seconds % 86400, 3600);
    $m = intdiv($seconds % 3600, 60);
    return ($d > 0 ? $d . 'd ' : '') . sprintf('%02d:%02d', $h, $m);
}

function paginate(int $total, int $perPage, int $page): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    return ['page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $perPage, 'per' => $perPage, 'total' => $total];
}

/** Build a query string preserving current GET params, overriding given ones. */
function qs(array $override = []): string
{
    $params = array_merge($_GET, $override);
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
    return $params ? '?' . http_build_query($params) : '';
}

function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Sanitize a limited subset of HTML for admin-authored rich text (terms, descriptions). */
function safe_html(?string $html): string
{
    $html = (string) $html;
    if ($html === '') {
        return '';
    }
    $allowed = '<p><br><b><strong><i><em><u><ul><ol><li><h3><h4><h5><blockquote><table><thead><tbody><tr><th><td><hr><small>';
    $clean = strip_tags($html, $allowed);
    // Strip all attributes from allowed tags (removes on*, style, href=javascript:, etc.)
    $clean = preg_replace('/<(\/?)([a-z0-9]+)\b[^>]*>/i', '<$1$2>', $clean) ?? '';
    return $clean;
}

/** Plain text to HTML paragraphs (escaped). */
function nl2p(?string $text): string
{
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }
    $parts = preg_split('/\n{2,}/', str_replace("\r", '', $text)) ?: [];
    return implode('', array_map(static fn($p) => '<p>' . nl2br(e($p)) . '</p>', $parts));
}

/** Does the signed-in administrator have a permission? */
function can(string $permission): bool
{
    $a = Auth::admin();
    return $a !== null && Rbac::can($a['role'], $permission);
}
