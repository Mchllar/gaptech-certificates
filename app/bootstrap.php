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
 * The system has two kinds of account: administrators (this table) and clients.
 * Every administrator has the same powers, so one check covers every staff page.
 */
function require_admin(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
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
