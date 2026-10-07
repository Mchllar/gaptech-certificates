<?php
/** Choose a new password from the link in the reset e-mail. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/views/layout.php';

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$row = $token === '' ? null : q("SELECT r.*, u.name, u.email FROM password_resets r
        JOIN users u ON u.id = r.user_id
        WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > now() AND u.active",
    [hash('sha256', $token)])->fetch();

$done = false;
$errors = [];

if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');
    if (strlen($password) < 8) {
        $errors[] = 'The password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        q('UPDATE users SET password_hash = ? WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $row['user_id']]);
        q('UPDATE password_resets SET used_at = now() WHERE id = ?', [$row['id']]);
        // any other outstanding links for this account stop working
        q('UPDATE password_resets SET used_at = now()
           WHERE user_id = ? AND used_at IS NULL', [$row['user_id']]);
        $pdo->commit();
        audit('password_reset_completed', 'user', (int)$row['user_id']);
        $done = true;
    }
}

layout_top('Choose a new password', 'public');
?>
<h1>Choose a new password</h1>

<?php if ($done): ?>
  <p class="flash">Your password has been changed. You can sign in with it now.</p>
  <p><a class="button" href="login.php">Go to sign in</a></p>
<?php elseif (!$row): ?>
  <p class="error">This link is not valid. It may have expired, been used already, or been typed
    incompletely. Request a new one.</p>
  <p><a class="button" href="forgot_password.php">Request a new link</a></p>
<?php else: ?>
  <p class="hint">Setting a new password for <strong><?= e($row['email']) ?></strong>.</p>
  <?php foreach ($errors as $msg): ?><p class="error"><?= e($msg) ?></p><?php endforeach; ?>
  <form method="post" class="narrow">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="t" value="<?= e($token) ?>">
    <label>New password <input name="password" type="password" minlength="8" required autofocus></label>
    <label>Repeat it <input name="confirm" type="password" minlength="8" required></label>
    <button type="submit">Save new password</button>
  </form>
<?php endif; ?>
<?php layout_bottom(); ?>