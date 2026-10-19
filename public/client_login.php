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
        <h1 class="vfy-title">Client Module</h1>
        <p class="vfy-sub">Sign In to Continue</p>

        <form method="post" class="narrow">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <label>Username <input name="username" required autofocus autocapitalize="none" autocomplete="username"></label>
            <label>Password <input name="password" type="password" required></label>
            <button type="submit">Sign in</button>
        </form>
        <p class="hint">Need to check someone else's certificate? Use the <a href="verify.php">verification page</a>.</p>
        <p class="hint"><a href="welcome.php">&larr; Back</a></p>
    </div>
<?php layout_bottom(); ?>