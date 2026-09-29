<?php
/**
 * Create the first staff account (run once from the command line):
 *   php tools/create_user.php "Michelle" michelle@gaptechsolutions.com supervisor
 */
require __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
$name = $argv[1] ?? '';
$email = $argv[2] ?? '';
$role = $argv[3] ?? 'staff';
if ($name === '' || $email === '' || !in_array($role, ['staff', 'supervisor'], true)) {
    exit("Usage: php tools/create_user.php \"Full Name\" email@example.com [staff|supervisor]\n");
}
echo 'Password: ';
$password = trim((string)fgets(STDIN));
if (strlen($password) < 8) {
    exit("The password must be at least 8 characters.\n");
}
q('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)
   ON CONFLICT (email) DO UPDATE SET name = EXCLUDED.name, password_hash = EXCLUDED.password_hash,
                                     role = EXCLUDED.role, active = true',
    [$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
echo "Account ready for {$email} ({$role}).\n";
