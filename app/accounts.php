<?php
/**
 * Staff accounts by e-mail: the company domain rule and one-time codes.
 *
 * Why there is no public "create account": every staff account is made by an
 * administrator in Staff Accounts. The system then e-mails the person a code
 * to choose their own password. Nobody outside can add themselves, and codes
 * are only ever sent to addresses on the company domain, so even a guessed
 * username cannot pull a code to a private mailbox.
 *
 * Codes: 6 digits, stored only as an HMAC (never readable in the database),
 * one live code per account and purpose, 5 wrong tries and it is dead,
 * 10 minutes for password resets/changes, 48 hours for a new account's setup,
 * and no more than 5 codes an hour per account.
 */
declare(strict_types=1);

require_once APP_DIR . '/mailer.php';

const OTP_MAX_TRIES    = 5;
const OTP_MAX_PER_HOUR = 5;
const OTP_TTL = ['reset' => 600, 'change' => 600, 'setup' => 172800];

/** The one domain staff addresses must be on, e.g. gaptechsolutions.com. */
function staff_email_domain(): string
{
    return strtolower(trim((string)env('STAFF_EMAIL_DOMAIN', 'gaptechsolutions.com'), " @\t"));
}

/** True for a well-formed address on the company domain (and nothing else). */
function is_staff_email(string $email): bool
{
    $email = trim($email);
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }
    $at = strrpos($email, '@');
    return strtolower(substr($email, $at + 1)) === staff_email_domain();
}

/** m.guya@gaptechsolutions.com -> m•••a@gaptechsolutions.com, for "we sent it to ..." lines. */
function mask_email(string $email): string
{
    $at = strrpos($email, '@');
    if ($at === false) {
        return '';
    }
    $local = substr($email, 0, $at);
    $shown = strlen($local) <= 2 ? substr($local, 0, 1) . '•'
           : substr($local, 0, 1) . '•••' . substr($local, -1);
    return $shown . substr($email, $at);
}

/** Find an active staff account by username or e-mail. */
function find_staff_login(string $login): ?array
{
    $login = trim($login);
    if ($login === '') {
        return null;
    }
    $row = q("SELECT * FROM users
               WHERE active AND (lower(username) = lower(?) OR (email <> '' AND lower(email) = lower(?)))
               ORDER BY (lower(username) = lower(?)) DESC LIMIT 1",
        [$login, $login, $login])->fetch();
    return $row ?: null;
}

/** Secret for hashing codes. Set APP_SECRET in .env; falls back to something machine-specific. */
function app_secret(): string
{
    $s = (string)env('APP_SECRET', '');
    if ($s !== '') {
        return $s;
    }
    global $config;
    return hash('sha256', 'gaptech|' . ($config['db']['pass'] ?? '') . '|' . __DIR__);
}

function otp_hash(int $userId, string $purpose, string $code): string
{
    return hash_hmac('sha256', $userId . '|' . $purpose . '|' . $code, app_secret());
}

/** Same rules as everywhere else in the system. Returns a message, or null when fine. */
function account_password_problem(string $password, string $repeat): ?string
{
    if (strlen($password) < 8)  { return 'The new password must be at least 8 characters.'; }
    if (strlen($password) > 72) { return 'The new password must be at most 72 characters.'; }
    if (!preg_match('/[A-Z]/', $password))        { return 'The new password needs at least one capital letter.'; }
    if (!preg_match('/[0-9]/', $password))        { return 'The new password needs at least one number.'; }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) { return 'The new password needs at least one special character.'; }
    if ($password !== $repeat)                    { return 'The two new passwords do not match.'; }
    return null;
}

/**
 * Create a code for $user and e-mail it. Returns true when it was sent.
 * On false, $error says why in words fit for the person on the screen.
 */
function otp_send(array $user, string $purpose, ?string &$error = null): bool
{
    $error = null;
    if (!isset(OTP_TTL[$purpose])) {
        throw new InvalidArgumentException('Unknown code purpose: ' . $purpose);
    }
    $email = trim((string)($user['email'] ?? ''));
    if (!is_staff_email($email)) {
        $error = 'Codes are only sent to @' . staff_email_domain() . ' addresses, and this account does not have one.';
        return false;
    }
    if (!mail_configured()) {
        $error = 'E-mail is not set up on this server.';
        return false;
    }
    $recent = (int)q("SELECT count(*) FROM email_codes WHERE user_id = ? AND created_at > now() - interval '1 hour'",
        [(int)$user['id']])->fetchColumn();
    if ($recent >= OTP_MAX_PER_HOUR) {
        $error = 'Too many codes have been requested for this account. Wait an hour and try again.';
        return false;
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $ttl  = OTP_TTL[$purpose];

    // One live code per account and purpose: asking again kills the previous one.
    q("UPDATE email_codes SET used_at = now() WHERE user_id = ? AND purpose = ? AND used_at IS NULL",
        [(int)$user['id'], $purpose]);
    $id = (int)q("INSERT INTO email_codes (user_id, purpose, code_hash, expires_at, requested_ip)
                  VALUES (?, ?, ?, now() + make_interval(secs => ?), ?) RETURNING id",
        [(int)$user['id'], $purpose, otp_hash((int)$user['id'], $purpose, $code), $ttl,
         (string)($_SERVER['REMOTE_ADDR'] ?? '')])->fetchColumn();

    [$subject, $body] = otp_message($user, $purpose, $code);
    $mailError = null;
    if (!mail_send($email, $subject, $body, $mailError)) {
        q('DELETE FROM email_codes WHERE id = ?', [$id]);
        audit('otp_email_failed', 'user', (int)$user['id'], ['purpose' => $purpose, 'error' => $mailError]);
        $error = 'The e-mail could not be sent. Try again in a few minutes, or ask an administrator.';
        return false;
    }
    audit('otp_sent', 'user', (int)$user['id'], ['purpose' => $purpose]);
    return true;
}

/**
 * Check a typed code. Returns the code's id when right (call otp_consume() once
 * the password is saved), or null with $error set when not.
 */
function otp_verify(int $userId, string $purpose, string $typed, ?string &$error = null): ?int
{
    $typed = preg_replace('/\D/', '', $typed);
    $row = $userId > 0 ? q("SELECT * FROM email_codes
                             WHERE user_id = ? AND purpose = ? AND used_at IS NULL
                             ORDER BY id DESC LIMIT 1", [$userId, $purpose])->fetch() : false;

    if (!$row || strtotime((string)$row['expires_at']) < time()) {
        usleep(300000);
        $error = 'That code has expired or is not valid. Ask for a new one.';
        return null;
    }
    if ((int)$row['attempts'] >= OTP_MAX_TRIES) {
        $error = 'Too many wrong tries. Ask for a new code.';
        return null;
    }
    if (strlen($typed) !== 6 || !hash_equals((string)$row['code_hash'], otp_hash($userId, $purpose, $typed))) {
        q('UPDATE email_codes SET attempts = attempts + 1 WHERE id = ?', [(int)$row['id']]);
        $left = OTP_MAX_TRIES - (int)$row['attempts'] - 1;
        usleep(300000);
        $error = $left > 0
            ? 'That code is not right. ' . $left . ($left === 1 ? ' try' : ' tries') . ' left.'
            : 'Too many wrong tries. Ask for a new code.';
        return null;
    }
    return (int)$row['id'];
}

/** The code has done its job: it, and any other live code for this account, stop working. */
function otp_consume(int $codeId, int $userId): void
{
    q('UPDATE email_codes SET used_at = now() WHERE (id = ? OR user_id = ?) AND used_at IS NULL', [$codeId, $userId]);
    q('UPDATE password_resets SET used_at = now() WHERE user_id = ? AND used_at IS NULL', [$userId]);
}

/** Subject and plain-text body for each kind of code. */
function otp_message(array $user, string $purpose, string $code): array
{
    global $config;
    $base  = rtrim((string)($config['base_url'] ?? ''), '/');
    $name  = trim((string)($user['name'] ?? '')) ?: 'there';
    $shown = substr($code, 0, 3) . ' ' . substr($code, 3);
    $sig   = "\n--\nGAPTECH Solutions Ltd - Certificates System\n"
           . "This is an automatic message. Never share this code: GAPTECH staff will never ask you for it.\n";

    if ($purpose === 'setup') {
        $link = $base . '/forgot_password.php?setup=1&u=' . rawurlencode((string)$user['email']);
        return [
            'Set up your GAPTECH certificates account',
            "Hello {$name},\n\n"
            . "An account has been created for you on the GAPTECH certificates system.\n\n"
            . "Your username: {$user['username']}\n"
            . "Your setup code: {$shown}\n\n"
            . "Open this page, enter the code and choose your password:\n{$link}\n\n"
            . "The code works for 48 hours. If it has expired, go to the sign-in page,\n"
            . "click \"Forgot password?\" and enter your work e-mail to get a new code:\n"
            . $base . "/forgot_password.php\n\n"
            . "If you were not expecting this, ignore this e-mail and tell the office.\n" . $sig,
        ];
    }
    $what = $purpose === 'change' ? 'change your password' : 'reset your password';
    return [
        "{$shown} is your GAPTECH verification code",
        "Hello {$name},\n\n"
        . "Use this code to {$what} on the GAPTECH certificates system:\n\n"
        . "    {$shown}\n\n"
        . "It works for 10 minutes. If you did not ask for it, ignore this e-mail;\n"
        . "your password stays as it is.\n" . $sig,
    ];
}
