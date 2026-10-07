<?php
/**
 * Loaded by every page: configuration, database connection, sessions and small helpers.
 */
declare(strict_types=1);

define('APP_DIR', __DIR__);
define('ROOT_DIR', dirname(__DIR__));

$config = require APP_DIR . '/config.php';

// Never show a raw PHP error to a user. Log the detail, show a plain page with a
// reference the administrator can look up.
ini_set('display_errors', '0');
error_reporting(E_ALL);

function show_failure(string $detail): void
{
    global $config;
    $ref = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $dir = rtrim($config['storage'], '/\\') . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    @file_put_contents($dir . '/errors.log',
        date('Y-m-d H:i:s') . "  [{$ref}]  " . ($_SERVER['REQUEST_URI'] ?? 'cli') . "\n"
        . $detail . "\n\n", FILE_APPEND | LOCK_EX);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title>'
       . '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:520px;margin:80px auto;'
       . 'padding:28px;border:1px solid #D9E2EC;border-radius:12px;background:#fff">'
       . '<h1 style="font-size:19px;margin:0 0 10px;color:#0D4F72">Something went wrong</h1>'
       . '<p style="color:#5A6472;font-size:14px;line-height:1.5">The page could not be completed. '
       . 'Please try again. If it keeps happening, tell the administrator and quote reference '
       . '<strong>' . $ref . '</strong>.</p>'
       . '<p><a href="welcome.php" style="color:#0D4F72">Back to the start</a></p></div>';
    exit;
}

set_exception_handler(function (Throwable $e) {
    show_failure($e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        show_failure($err['message'] . "\n" . $err['file'] . ':' . $err['line']);
    }
});

// Work out the address from the request when base_url is left empty, so the system
// follows whatever host was used: localhost, 192.168.x.x, or a real domain later.
if (empty($config['base_url'])) {
    if (PHP_SAPI === 'cli') {
        $config['base_url'] = 'http://localhost/gaptech-certificates/public';
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        $config['base_url'] = $scheme . '://' . $host . $dir;
    }
}

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

function fmt_date(?string $date): string
{
    if (!$date) {
        return '';
    }
    $t = strtotime($date);
    return $t ? date('d F Y', $t) : (string)$date;
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
    if (session_status() !== PHP_SESSION_ACTIVE) {
        http_response_code(500);
        exit('The session could not be started on the server. Check that nothing is printed before bootstrap.php loads.');
    }
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
 * The system has two kinds of account: administrators (this table) and clients.
 * Every administrator has the same powers, so one check covers every staff page.
 */
/** An administrator: issues certificates, manages clients and settings. */
function require_admin(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    if ($user['role'] !== 'administrator') {
        http_response_code(403);
        exit('This page is for administrators. You are signed in as ' . e($user['role']) . '.');
    }
    return $user;
}

/** Accounts: records payments and approves certificates. */
function require_accounts(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    if ($user['role'] !== 'accounts') {
        http_response_code(403);
        exit('This page is for accounts. You are signed in as ' . e($user['role']) . '.');
    }
    return $user;
}

/** Any signed-in member of staff, whichever role. */
function require_staff(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    return $user;
}

// Older name, kept so any page still calling it keeps working.
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
start_session();

