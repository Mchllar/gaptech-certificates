<?php
/**
 * Check the SMTP settings in app/config.php by sending one test message.
 *
 *   php tools/test_mail.php you@example.com
 *
 * Put this in the project's tools/ folder. Delete it once mail is working.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/mailer.php';

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

$to = $argv[1] ?? '';
if ($to === '') {
    exit("Usage: php tools/test_mail.php you@example.com\n");
}

$s = $config['smtp'] ?? [];
echo "Settings in app/config.php\n";
printf("  host      : %s\n", $s['host'] ?: '(empty - nothing will send)');
printf("  port      : %s\n", $s['port'] ?? '');
printf("  security  : %s\n", $s['security'] ?? '');
printf("  username  : %s\n", $s['username'] ?: '(none - no authentication)');
printf("  password  : %s\n", !empty($s['password']) ? str_repeat('*', 8) : '(none)');
printf("  from      : %s\n\n", $s['from'] ?: '(empty)');

if (!mail_configured()) {
    exit("Fill in at least 'host' and 'from', then run this again.\n");
}

// Can we even reach the server? A blocked port is the most common problem.
$port = (int)($s['port'] ?? 587);
echo "Opening a connection to {$s['host']}:{$port} ... ";
$probe = @fsockopen(($s['security'] === 'ssl' ? 'ssl://' : '') . $s['host'], $port, $errno, $errstr, 10);
if (!$probe) {
    echo "FAILED\n  {$errstr} ({$errno})\n\n";
    echo "Usually this means the port is blocked by the firewall or the office internet,\n";
    echo "or the host name is wrong. Try port 465 with security 'ssl' as an alternative.\n";
    exit(1);
}
fclose($probe);
echo "reached\n\n";

echo "Sending a test message to {$to} ... ";
$error = null;
$body = "This is a test from the GAPTECH certificate system.\n\n"
    . "If you are reading it, password reset e-mails will work.\n"
    . "Sent " . date('d-m-Y H:i') . " from " . gethostname() . ".\n";

if (mail_send($to, 'Certificate system test message', $body, $error)) {
    echo "sent\n\nCheck the inbox, and the spam folder if it is not there.\n";
} else {
    echo "FAILED\n  {$error}\n\n";
    echo "Common causes:\n";
    echo "  535 / authentication failed : wrong username or password. For Gmail and Google\n";
    echo "      Workspace you must use an App Password, not the normal account password.\n";
    echo "  5.7.x / not allowed to send as : the 'from' address must match the account signing in.\n";
    echo "  TLS errors : try port 465 with security 'ssl', or 587 with 'tls'.\n";
    exit(1);
}
