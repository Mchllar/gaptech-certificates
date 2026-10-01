<?php
/**
 * "Forgot password" for administrators: e-mails a one-hour link.
 * If e-mail is not configured, it says to ask another administrator instead.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/mailer.php';
require APP_DIR . '/views/layout.php';

$sent = false;
$problem = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim((string)($_POST['email'] ?? ''));

    // Throttle: at most 5 requests an hour for one account.
    $recent = (int)q("SELECT count(*) FROM password_resets r JOIN users u ON u.id = r.user_id
                      WHERE lower(u.email) = lower(?) AND r.created_at > now() - interval '1 hour'",
        [$email])->fetchColumn();

    $user = q('SELECT * FROM users WHERE lower(email) = lower(?) AND active', [$email])->fetch();

    if ($user && $recent < 5) {
        $token = bin2hex(random_bytes(24));
        q("INSERT INTO password_resets (user_id, token_hash, expires_at, requested_ip)
           VALUES (?, ?, now() + interval '1 hour', ?)",
            [$user['id'], hash('sha256', $token), $_SERVER['REMOTE_ADDR'] ?? '']);

        $link = rtrim($config['base_url'], '/') . '/reset_password.php?t=' . $token;
        $body = "Hello {$user['name']},\n\n"
            . "Someone asked to reset the password for your GAPTECH certificate system account.\n"
            . "Open this link within one hour to choose a new password:\n\n{$link}\n\n"
            . "If this was not you, ignore this message. Your password stays as it is.\n";
        $err = null;
        if (!mail_send($user['email'], 'Reset your certificate system password', $body, $err)) {
            $problem = $err ?? 'Could not send the e-mail.';
            audit('password_reset_email_failed', 'user', (int)$user['id'], ['error' => $problem]);
        } else {
            audit('password_reset_requested', 'user', (int)$user['id']);
        }
    }
    // Always the same message, so nobody can tell which addresses have accounts.
    $sent = true;
}

layout_top('Forgot password', 'public');
?>
<h1>Forgot your password</h1>

<?php if ($sent && $problem === ''): ?>
  <p class="flash">If that e-mail address has an account, a reset link is on its way. It works for one hour.</p>
  <p class="hint">Nothing arrived? Check the spam folder, or ask the other administrator to reset it for you
    from Staff accounts.</p>
<?php elseif ($sent): ?>
  <p class="error">The reset e-mail could not be sent: <?= e($problem) ?></p>
  <p class="hint">Ask the other administrator to reset your password from Staff accounts, or run
    <code>php tools/reset_password.php</code> on the server.</p>
<?php else: ?>
  <?php if (!mail_configured()): ?>
    <p class="error">E-mail is not set up on this server yet, so reset links cannot be sent.
      Ask the other administrator to reset your password from Staff accounts.</p>
  <?php endif; ?>
  <form method="post" class="narrow">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label>Your e-mail address <input name="email" type="email" required autofocus></label>
    <button type="submit">Send reset link</button>
  </form>
<?php endif; ?>

<p class="hint"><a href="login.php">Back to sign in</a></p>
<?php layout_bottom(); ?>
