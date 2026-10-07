<?php
/**
 * Session probe. Put this in public/ and open it in the browser:
 *   http://localhost/gaptech-certificates/public/sess_probe.php
 *
 * Reload it two or three times. It reports whether the session survives between
 * requests, which is what the CSRF check depends on. Delete the file afterwards.
 *
 * It deliberately does NOT include bootstrap.php on the first run, so it can tell
 * you whether the problem is PHP's session handling or something in the app.
 */

$useApp = isset($_GET['app']);

ob_start();                      // so we can report headers_sent honestly below

if ($useApp) {
    require __DIR__ . '/../app/bootstrap.php';
    start_session();
} else {
    session_name('gaptech_certs');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

$started   = session_status() === PHP_SESSION_ACTIVE;
$id        = session_id();
$cookieIn  = $_COOKIE[session_name()] ?? null;
$previous  = $_SESSION['probe'] ?? null;
$counter   = (int)($_SESSION['count'] ?? 0) + 1;

if ($started) {
    $_SESSION['probe'] = $_SESSION['probe'] ?? bin2hex(random_bytes(4));
    $_SESSION['count'] = $counter;
}

$file = null; $line = null;
$sent = headers_sent($file, $line);

$savePath = ini_get('session.save_path') ?: sys_get_temp_dir();
$sessFile = rtrim($savePath, '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $id;

function yn(bool $ok): string {
    return $ok
        ? '<b style="color:#1a7a3c">yes</b>'
        : '<b style="color:#C3121A">no</b>';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Session probe</title>
<style>
  body { font-family: "Segoe UI", Arial, sans-serif; max-width: 720px; margin: 40px auto; padding: 0 18px; color: #16202B; }
  h1 { font-size: 20px; }
  table { border-collapse: collapse; width: 100%; margin-bottom: 18px; }
  th, td { text-align: left; padding: 7px 9px; border-bottom: 1px solid #D9E2EC; font-size: 14px; vertical-align: top; }
  th { width: 240px; background: #F2F6FA; }
  code { background: #EEF2F7; padding: 1px 5px; border-radius: 4px; font-size: 13px; }
  .verdict { padding: 12px 14px; border-radius: 8px; margin-bottom: 20px; font-size: 15px; }
  .good { background: #E9F6EC; border: 1px solid #B7DCC0; }
  .bad  { background: #FDECEC; border: 1px solid #F0B6B6; }
</style>
</head>
<body>
<h1>Session probe <?= $useApp ? '(through the app\'s bootstrap.php)' : '(plain PHP, app not loaded)' ?></h1>

<?php if ($counter > 1 && $previous !== null): ?>
  <p class="verdict good">The session is working. The value written on your first visit came back
     (<code><?= htmlspecialchars((string)$previous) ?></code>, visit number <?= $counter ?>).
     <?= $useApp ? 'Sessions are fine inside the app, so the CSRF failure is elsewhere — tell me and we will look at login.php itself.' : 'Now try <a href="?app=1">the same test through the app</a>.' ?></p>
<?php elseif ($counter > 1): ?>
  <p class="verdict bad">The session is NOT surviving. Each reload starts a fresh one, so every form will fail.
     Look at the rows below: a missing cookie, or "output already sent", is the cause.</p>
<?php else: ?>
  <p class="verdict">First visit — a value has just been stored. <b>Reload this page</b> to see whether it comes back.</p>
<?php endif; ?>

<table>
  <tr><th>Session started</th><td><?= yn($started) ?></td></tr>
  <tr><th>Session name</th><td><code><?= htmlspecialchars(session_name()) ?></code></td></tr>
  <tr><th>Session id</th><td><code><?= htmlspecialchars($id ?: '(none)') ?></code></td></tr>
  <tr><th>Cookie sent by the browser</th>
      <td><?= yn($cookieIn !== null) ?>
          <?= $cookieIn !== null ? '<code>' . htmlspecialchars($cookieIn) . '</code>' : ' &mdash; on a first visit this is normal; on a reload it means the cookie was never stored' ?></td></tr>
  <tr><th>Visit number</th><td><?= $counter ?></td></tr>
  <tr><th>Value stored last time</th><td><?= $previous === null ? '<i>nothing came back</i>' : '<code>' . htmlspecialchars((string)$previous) . '</code>' ?></td></tr>
  <tr><th>Output already sent?</th>
      <td><?= $sent ? '<b style="color:#C3121A">yes</b>, from ' . htmlspecialchars((string)$file) . ' line ' . (int)$line : '<b style="color:#1a7a3c">no</b>' ?></td></tr>
  <tr><th>output_buffering</th><td><code><?= htmlspecialchars((string)ini_get('output_buffering')) ?></code></td></tr>
  <tr><th>session.save_path</th><td><code><?= htmlspecialchars($savePath) ?></code> &mdash; writable: <?= yn(is_writable($savePath)) ?></td></tr>
  <tr><th>Session file on disk</th><td><?= yn(is_file($sessFile)) ?> <code><?= htmlspecialchars($sessFile) ?></code></td></tr>
  <tr><th>session.use_strict_mode</th><td><code><?= htmlspecialchars((string)ini_get('session.use_strict_mode')) ?></code></td></tr>
  <tr><th>session.cookie_secure</th><td><code><?= htmlspecialchars((string)ini_get('session.cookie_secure')) ?></code> <?= ini_get('session.cookie_secure') ? '&mdash; this breaks sessions over plain http' : '' ?></td></tr>
  <tr><th>session.cookie_samesite</th><td><code><?= htmlspecialchars((string)ini_get('session.cookie_samesite')) ?></code></td></tr>
  <tr><th>Address you are using</th><td><code><?= htmlspecialchars(($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '')) ?></code></td></tr>
  <tr><th>php.ini in use</th><td><code><?= htmlspecialchars(php_ini_loaded_file() ?: 'none') ?></code></td></tr>
</table>

<p style="font-size:14px;color:#5A6472">
  Test both: <a href="sess_probe.php">plain PHP</a> &middot; <a href="sess_probe.php?app=1">through the app</a>.
  Reload each one twice before reading the result.
</p>
</body>
</html>
