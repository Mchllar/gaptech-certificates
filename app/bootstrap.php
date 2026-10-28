<?php
/**
 * Loaded by every page: configuration, database connection, sessions and small helpers.
 */
declare(strict_types=1);

define('APP_DIR', __DIR__);
define('ROOT_DIR', dirname(__DIR__));

$config = require APP_DIR . '/config.php';

require_once APP_DIR . '/qr.php';

// ----------------------------------------------------------------- database

function db(): PDO
{
    global $config;
    static $pdo = null;
    if ($pdo === null) {
        $c = $config['db'];
        $dsn = "pgsql:host={$c['host']};port={$c['port']};dbname={$c['name']}";
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

/** Run a query with bound parameters and return the statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

// ------------------------------------------------------------------ output

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function fmt_date(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    return date('d-m-Y', strtotime($iso));
}

// ----------------------------------------------------------------- session

function start_session(): void
{
    global $config;
    if (session_status() === PHP_SESSION_NONE) {
        session_name($config['session_name']);
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

/** Call at the top of every POST handler. */
function csrf_check(): void
{
    start_session();
    $sent = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)$sent)) {
        http_response_code(400);
        exit('Your session expired. Please go back, reload the page and try again.');
    }
}

function flash(?string $message = null): ?string
{
    start_session();
    if ($message !== null) {
        $_SESSION['flash'] = $message;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

// ---------------------------------------------------------------- settings

/** Company details, signatory and the certificate counter live in the settings table. */
function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (q('SELECT key, value FROM settings')->fetchAll() as $row) {
            $cache[$row['key']] = $row['value'];
        }
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, string $value): void
{
    q('INSERT INTO settings (key, value) VALUES (?, ?)
       ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value', [$key, $value]);
}

// ------------------------------------------------------------------- audit

function audit(string $action, string $entity, ?int $entityId = null, array $details = []): void
{
    start_session();
    q('INSERT INTO audit_log (actor_type, actor_id, action, entity, entity_id, details)
       VALUES (?, ?, ?, ?, ?, ?)', [
        isset($_SESSION['user_id']) ? 'staff' : (isset($_SESSION['client_id']) ? 'client' : 'public'),
        $_SESSION['user_id'] ?? $_SESSION['client_id'] ?? null,
        $action, $entity, $entityId, json_encode($details),
    ]);
}

// -------------------------------------------------------------------- auth

function current_user(): ?array
{
    start_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $user = null;
    if ($user === null) {
        $user = q('SELECT * FROM users WHERE id = ? AND active', [$_SESSION['user_id']])->fetch() ?: null;
    }
    return $user ?: null;
}

/**
 * The pages a management account may open. Everything else is refused.
 *
 * Written as a list of what IS allowed rather than what is not, on purpose. A
 * role defined as "everything except the staff accounts page" quietly gains
 * whatever gets built next; a role defined as "these three pages" does not. When
 * management should see something new, it is added here deliberately - which is
 * the only moment anybody thinks about whether they should.
 *
 * change_password.php is not on the list because it never asks this gate: it
 * reads current_user() itself and is open to anybody signed in, which is right.
 */
function management_pages(): array
{
    return [
        'dashboard.php',
        'logout.php',
    ];
}

/**
 * Where a staff account belongs when it has not asked for anywhere in particular.
 *
 * Signing in, the front door, and being turned away from a page all have to agree
 * about this. They used to each name a page of their own, which is how management
 * ended up being sent to the certificate register and bounced out of it again -
 * two redirects that look, from the outside, like a sign-in that failed.
 */
function staff_home(array $user): string
{
    return (string)($user['role'] ?? '') === 'management' ? 'dashboard.php' : 'index.php';
}

/**
 * Stop a restricted role opening a page that is not theirs.
 *
 * Called by require_admin(), so every staff page is covered by one check - and a
 * page added next month is covered without anybody remembering to add it.
 * certificate_print.php calls this directly, because it does its own sign-in
 * handling and never reaches require_admin().
 *
 * Administrators and accounts are not restricted here, so nothing about how they
 * work changes.
 */
function enforce_role_access(array $user): void
{
    if ((string)($user['role'] ?? '') !== 'management') {
        return;
    }

    $page = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($page, management_pages(), true)) {
        return;
    }

    // Sent to their own home rather than shown an error. There is nothing useful
    // to tell somebody who typed a URL they were never given, and a page that
    // explains why it said no is a page that helps work out how to make it say
    // yes.
    $home = staff_home($user);

    // Unless home is the page being refused, which would redirect for ever. That
    // can only happen if a role's home is left off its own allowed list - a
    // mistake in this file, not something a user can cause - so it says so
    // plainly rather than hanging the browser.
    if ($home === $page) {
        http_response_code(403);
        exit('This account cannot open its own home page. '
            . 'Check management_pages() in app/bootstrap.php.');
    }

    redirect($home);
}

/**
 * The system has two kinds of account: staff (this table) and clients.
 *
 * Administrators and accounts both reach every staff page through here and are
 * told apart by what each page chooses to show. Management is the exception, and
 * is held to management_pages().
 */
function require_admin(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    enforce_role_access($user);
    return $user;
}

// Older names, kept so any page still calling them keeps working.
function require_staff(): array
{
    return require_admin();
}

function require_supervisor(): array
{
    return require_admin();
}

function current_client(): ?array
{
    start_session();
    if (empty($_SESSION['client_id'])) {
        return null;
    }
    return q('SELECT * FROM clients WHERE id = ?', [$_SESSION['client_id']])->fetch() ?: null;
}

function require_client(): array
{
    $client = current_client();
    if (!$client) {
        redirect('client_login.php');
    }
    return $client;
}