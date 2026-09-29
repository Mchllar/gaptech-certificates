<?php
/** Staff sign-in. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/views/layout.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $user = q('SELECT * FROM users WHERE lower(email) = lower(?) AND active', [$email])->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        start_session();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        unset($_SESSION['client_id']);
        audit('login', 'user', (int)$user['id']);
        redirect('index.php');
    }
    $error = 'Wrong e-mail or password.';
    usleep(400000);
}

layout_top('Sign in');
?>
<h1>Staff sign in</h1>
<?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
<form method="post" class="narrow">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <label>E-mail <input name="email" type="email" required autofocus></label>
  <label>Password <input name="password" type="password" required></label>
  <button type="submit">Sign in</button>
</form>
<p class="hint">Clients sign in at <a href="client_login.php">the client portal</a>.</p>
<?php layout_bottom(); ?>
