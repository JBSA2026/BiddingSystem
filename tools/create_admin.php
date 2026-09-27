<?php
/**
 * CLI: create or reset an administrator (e.g. if the last Super Admin is locked out).
 *   php tools/create_admin.php "Full Name" email@example.com super_admin
 * Prompts for the password (not echoed). Roles: super_admin, bidding_admin, approving_officer, auditor
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

[$script, $name, $email, $role] = array_pad($argv, 4, null);
if (!$name || !$email || !isset(Rbac::ROLES[(string) $role])) {
    fwrite(STDERR, "Usage: php tools/create_admin.php \"Full Name\" email role\nRoles: " . implode(', ', array_keys(Rbac::ROLES)) . "\n");
    exit(1);
}
fwrite(STDOUT, 'Password: ');
if (DIRECTORY_SEPARATOR === '/') {
    shell_exec('stty -echo');
}
$pw = trim((string) fgets(STDIN));
if (DIRECTORY_SEPARATOR === '/') {
    shell_exec('stty echo');
}
fwrite(STDOUT, "\n");
if ($err = Security::passwordError($pw)) {
    fwrite(STDERR, $err . "\n");
    exit(1);
}
$email = strtolower($email);
$existing = DB::one('SELECT * FROM admins WHERE email = ?', [$email]);
if ($existing) {
    DB::update('admins', ['name' => $name, 'role' => $role, 'password_hash' => Security::hashPassword($pw), 'is_active' => 1, 'updated_at' => now()], 'id = ?', [$existing['id']]);
    Audit::log('admin_reset_cli', 'admin', $existing['id'], ['role' => $existing['role']], ['role' => $role]);
    echo "Administrator {$email} updated.\n";
} else {
    $id = DB::insert('admins', ['name' => $name, 'email' => $email, 'password_hash' => Security::hashPassword($pw), 'role' => $role, 'created_at' => now()]);
    Audit::log('admin_created_cli', 'admin', $id, null, ['email' => $email, 'role' => $role]);
    echo "Administrator {$email} created.\n";
}
