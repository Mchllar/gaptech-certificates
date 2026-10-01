<?php
/**
 * Last-resort password reset, run on the server itself.
 * Use this when everyone, including the supervisor, is locked out.
 *
 *   php tools/reset_password.php                      list the accounts
 *   php tools/reset_password.php you@gaptech.com      reset that account's password
 */
require __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line on the server.\n");
}

$email = $argv[1] ?? '';

if ($email === '') {
    echo "Accounts:\n";
    foreach (q('SELECT email, name, role, active FROM users ORDER BY name')->fetchAll() as $u) {
        printf("  %-38s %-12s %s\n", $u['email'], $u['role'], $u['active'] ? 'active' : 'inactive');
    }
    echo "\nReset one with:  php tools/reset_password.php email@example.com\n";
    exit;
}

$user = q('SELECT * FROM users WHERE lower(email) = lower(?)', [$email])->fetch();
if (!$user) {
    exit("No account with that e-mail.\n");
}

echo "New password for {$user['name']} ({$user['email']}): ";
$password = trim((string)fgets(STDIN));
if (strlen($password) < 8) {
    exit("The password must be at least 8 characters. Nothing changed.\n");
}

q('UPDATE users SET password_hash = ?, active = true WHERE id = ?',
    [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
q("INSERT INTO audit_log (actor_type, actor_id, action, entity, entity_id, details)
   VALUES ('console', NULL, 'reset_password', 'user', ?, ?)",
    [$user['id'], json_encode(['email' => $user['email'], 'via' => 'command line'])]);

echo "Done. {$user['email']} can sign in with the new password.\n";
