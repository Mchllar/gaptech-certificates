<?php
/** Administrator sign-in. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/views/layout.php';

start_session();
$token = csrf_token();          // generated here, before any output
$error = '';

// Which card they came from. This only changes the wording - what someone can
// actually do comes from the role on their account, checked on every page.
$as = ($_GET['as'] ?? '') === 'accounts' ? 'accounts' : 'administrator';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sent = (string)($_POST['csrf'] ?? '');  
    
    if ($sent === '') {
    // The form arrived with no token at all - that is a page fault, not the user's.
    $error = 'The sign-in form did not send its security token. Reload the page and try again.';
    } elseif (!hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        $error = 'Your session expired while the page was open. Please sign in again.';
        $_SESSION['csrf'] = $token = bin2hex(random_bytes(16));
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $user = q('SELECT * FROM users WHERE lower(username) = lower(?) AND active', 
        [$username])->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = (int)$user['id'];
            unset($_SESSION['client_id']);
            session_regenerate_id(true);
            audit('login', 'user', (int)$user['id']);
            // each role has its own home page
            redirect($user['role'] === 'accounts' ? 'approvals.php' : 'index.php');
        }
        usleep(400000);
        $error = 'Wrong username or password.';
    }
}

layout_top('Sign in');
?>
<?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
    <h1 style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; font-size: 1.75rem; font-weight: 700; color: #0f172a; margin: 0 0 1rem 0; padding-left: 0.875rem; border-left: 4px solid #3b82f6; letter-spacing: -0.025em; line-height: 1.25;"><?= $as === 'accounts' ? 'Accounts' : 'Administrator' ?> Module</h1>
    <p class="hint"style="color: darkgray; margin: 4px 0 10px 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">Sign In to Continue</p>

    <form method="post" class="narrow">
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <label>Username 
            <input name="username" required autofocus autocapitalize="none" autocomplete="username"
            value="<?= e($_POST['username'] ?? '') ?>">
        </label>
        <label>Password 
            <input name="password" type="password" required autocomplete="current-password">
        </label>
        <button type="submit">Sign in</button>
    </form>
    <p class="hint"><a href="change_password.php">Change your password</a> &middot;
    <a href="forgot_password.php">Forgot your password?</a></p>
    <p class="hint"><a href="welcome.php">&larr; Back</a></p>
<?php layout_bottom(); ?>