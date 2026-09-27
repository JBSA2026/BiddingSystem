<?php
declare(strict_types=1);

final class View
{
    public static function header(string $title, array $opts = []): void
    {
        $pageTitle = $title;
        $bodyClass = $opts['body_class'] ?? '';
        $active = $opts['active'] ?? '';
        $bidder = Auth::bidder();
        $unread = $bidder ? Notifier::unreadCount('bidder', (int) $bidder['id']) : 0;
        require APP_DIR . '/views/header.php';
        foreach (flashes() as $f) {
            echo '<div class="container"><div class="alert alert-' . e($f['type']) . '" role="alert">' . e($f['message']) . '</div></div>';
        }
    }

    public static function footer(): void
    {
        require APP_DIR . '/views/footer.php';
    }

    public static function adminHeader(string $title, string $active = ''): void
    {
        $pageTitle = $title;
        $admin = Auth::admin();
        $unread = $admin ? Notifier::unreadCount('admin', (int) $admin['id']) : 0;
        $pendingApprovals = 0;
        if ($admin && (Rbac::can($admin['role'], 'award.approve') || Rbac::can($admin['role'], 'schedule.approve'))) {
            $pendingApprovals = (int) DB::val("SELECT (SELECT COUNT(*) FROM awards WHERE status = 'pending_approval') + (SELECT COUNT(*) FROM approval_requests WHERE status = 'pending')");
        }
        require APP_DIR . '/views/admin_header.php';
        foreach (flashes() as $f) {
            echo '<div class="alert alert-' . e($f['type']) . '" role="alert">' . e($f['message']) . '</div>';
        }
    }

    public static function adminFooter(): void
    {
        require APP_DIR . '/views/admin_footer.php';
    }

    public static function render(string $view, array $vars = []): void
    {
        extract($vars, EXTR_SKIP);
        self::header($vars['title'] ?? '');
        require APP_DIR . '/views/' . basename($view) . '.php';
        self::footer();
    }

    public static function adminRender(string $view, array $vars = []): void
    {
        extract($vars, EXTR_SKIP);
        if (Auth::admin()) {
            self::adminHeader($vars['title'] ?? '');
            require APP_DIR . '/views/' . basename($view) . '.php';
            self::adminFooter();
        } else {
            self::header($vars['title'] ?? '');
            require APP_DIR . '/views/' . basename($view) . '.php';
            self::footer();
        }
    }

    /** Render pagination links. */
    public static function pager(array $pg): string
    {
        if ($pg['pages'] <= 1) {
            return '';
        }
        $h = '<nav class="pager" aria-label="Pagination">';
        $start = max(1, $pg['page'] - 3);
        $end = min($pg['pages'], $pg['page'] + 3);
        if ($pg['page'] > 1) {
            $h .= '<a href="' . e(qs(['page' => $pg['page'] - 1])) . '">&laquo; Prev</a>';
        }
        for ($i = $start; $i <= $end; $i++) {
            $h .= $i === $pg['page'] ? '<span class="current">' . $i . '</span>' : '<a href="' . e(qs(['page' => $i])) . '">' . $i . '</a>';
        }
        if ($pg['page'] < $pg['pages']) {
            $h .= '<a href="' . e(qs(['page' => $pg['page'] + 1])) . '">Next &raquo;</a>';
        }
        return $h . '</nav>';
    }

    /** First image URL for a property (or placeholder). */
    public static function coverImage(int $propertyId): string
    {
        $id = DB::val('SELECT id FROM property_images WHERE property_id = ? ORDER BY sort_order, id LIMIT 1', [$propertyId]);
        return $id ? url('file.php?t=img&id=' . $id) : asset('img/placeholder.svg');
    }
}
