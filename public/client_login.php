<?php
/** Client sign-in for the download portal. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/views/layout.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $client = q('SELECT * FROM clients WHERE lower(username) = lower(?) AND active', [$username])->fetch();

    if ($client && password_verify($password, $client['password_hash'])) {
        start_session();
        session_regenerate_id(true);
        $_SESSION['client_id'] = (int)$client['id'];
        unset($_SESSION['user_id']);
        audit('login', 'client', (int)$client['id']);
        redirect('client_portal.php');
    }
    $error = 'Wrong e-mail or password. If you have not been given a password or have forgotten your password, please call the office.';
    usleep(400000);
}

layout_top('Client sign in', 'public');
?>
<?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
    <h1 style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; font-size: 1.75rem; font-weight: 700; color: #0f172a; margin: 0 0 1rem 0; padding-left: 0.875rem; border-left: 4px solid #3b82f6; letter-spacing: -0.025em; line-height: 1.25;">Client Module</h1>
    <p class="hint"style="color: darkgray; margin: 4px 0 10px 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">Sign In to Continue</p>

    <form method="post" class="narrow">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>Username <input name="username" required autofocus autocapitalize="none" autocomplete="username"></label>
        <label>Password <input name="password" type="password" required></label>
        <button type="submit">Sign in</button>
    </form>
    <p class="hint">Need to check someone else's certificate? Use the <a href="verify.php">verification page</a>.</p>
    <p class="hint"><a href="welcome.php">&larr; Back</a></p>
<?php layout_bottom(); ?>