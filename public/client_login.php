<?php
/** Client sign-in for the download portal. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/views/layout.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $client = q('SELECT * FROM clients WHERE lower(email) = lower(?) AND password_hash IS NOT NULL',
        [$email])->fetch();
    if ($client && password_verify($password, $client['password_hash'])) {
        start_session();
        session_regenerate_id(true);
        $_SESSION['client_id'] = (int)$client['id'];
        unset($_SESSION['user_id']);
        audit('login', 'client', (int)$client['id']);
        redirect('client_portal.php');
    }
    $error = 'Wrong e-mail or password. If you have not been given a password, please call the office.';
    usleep(400000);
}

layout_top('Client sign in', 'public');
?>
<h1>Client Speed Govenor Portal</h1>
<?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
<form method="post" class="narrow">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <label>E-mail <input name="email" type="email" required autofocus></label>
  <label>Password <input name="password" type="password" required></label>
  <button type="submit">Sign in</button>
</form>
<p class="hint">Need to check someone else's certificate? Use the <a href="verify.php">verification page</a>.</p>
<?php layout_bottom(); ?>
