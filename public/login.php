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
<style>
    .vfy-title { margin: 0 0 6px; font-size: 22px; font-weight: 700; letter-spacing: -.4px; color: #1C2430; line-height: 1.25; }
        /* ---- a little wider once there is room ---- */
    .vfy h1.vfy-title {
        margin: 0 0 6px;
        font-size: 22px; font-weight: 700; letter-spacing: -.4px;
        line-height: 1.25; color: #0D4F72;
        padding-left: 12px; border-left: 4px solid #8CC63F;
    }
    @media (min-width: 520px) { .vfy h1.vfy-title { font-size: 26px; } }
    .vfy-sub { font-size: 14px; color: #7A8696; line-height: 1.5; text-align: center; }
</style>


<?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
    <?php if (!empty($_GET['timeout'])): ?>
        <p class="hint">You were signed out after 30 minutes of inactivity.</p>
    <?php endif; ?>

    <div class="vfy">
    <h1 class="vfy-title"><?= $as === 'accounts' ? 'Accounts' : 'Administrator' ?> Module</h1>
    <p class="vfy-sub">Sign In to Continue</p>
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
    </div>
<?php layout_bottom(); ?>