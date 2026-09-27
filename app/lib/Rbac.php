<?php
declare(strict_types=1);

/** Role-Based Access Control for Cityland administrators. */
final class Rbac
{
    public const ROLES = [
        'super_admin'       => 'Super Admin',
        'bidding_admin'     => 'Bidding Administrator',
        'approving_officer' => 'Approving Officer',
        'auditor'           => 'Auditor / Viewer',
    ];

    /**
     * Permission matrix. '*' = everything.
     *  - *.view           read-only access
     *  - properties.manage  add/edit/publish/unpublish/archive/upload/configure rules
     *  - bidding.control  close / cancel bidding, request schedule changes
     *  - bidders.manage   flag, notes, blacklist, review documents
     *  - bidders.approve  approve/reject bidder qualification
     *  - evaluation.manage  mark bidders qualified/disqualified/under review
     *  - award.recommend  recommend winning & backup bidder
     *  - award.approve    approve final award (Approving Officer)
     *  - schedule.approve approve schedule change requests (dual authorization)
     *  - payments.reconcile  verify/reject bid security payments
     *  - reports.export   CSV/Excel exports
     *  - audit.view       audit logs and integrity checks
     *  - settings.manage  system settings, terms, requirements
     *  - users.manage     administrator accounts
     *  - backup.manage    database / file backups
     */
    private const MATRIX = [
        'super_admin' => ['*'],
        'bidding_admin' => [
            'dashboard.view', 'properties.view', 'properties.manage', 'bidding.control', 'bidders.view',
            'bidders.manage', 'bidders.approve', 'bids.view', 'evaluation.manage', 'award.recommend',
            'payments.view', 'payments.reconcile', 'reports.export', 'audit.view', 'notes.add',
        ],
        'approving_officer' => [
            'dashboard.view', 'properties.view', 'bidders.view', 'bidders.approve', 'bids.view',
            'evaluation.manage', 'award.recommend', 'award.approve', 'schedule.approve', 'payments.view',
            'reports.export', 'audit.view', 'notes.add',
        ],
        'auditor' => [
            'dashboard.view', 'properties.view', 'bidders.view', 'bids.view', 'payments.view',
            'reports.export', 'audit.view',
        ],
    ];

    public static function can(string $role, string $permission): bool
    {
        $perms = self::MATRIX[$role] ?? [];
        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    public static function label(string $role): string
    {
        return self::ROLES[$role] ?? $role;
    }
}

