<?php
/** Staff sign-in. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/views/layout.php';
require APP_DIR . '/views/entry.php';
require APP_DIR . '/accounts.php';

start_session();
$token = csrf_token();          // generated here, before any output
$error = '';

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

        // Username or work e-mail - existing usernames keep working.
        $user = find_staff_login($username);

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = (int)$user['id'];
            unset($_SESSION['client_id']);
            session_regenerate_id(true);
            audit('login', 'user', (int)$user['id']);
            // index.php is the certificate register, which management may not
            // open - landing them there would bounce them straight to the
            // dashboard and look like the sign-in had failed. So they are sent
            // where they are going. Everybody else lands exactly where they did.
            redirect(($user['role'] ?? '') === 'management' ? 'reports.php' : 'index.php');
        }
        usleep(400000);
        $error = 'Wrong username, e-mail or password.';
    }
}

$typed = (string)($_POST['username'] ?? '');

layout_top('Staff sign in', 'public');
echo entry_styles();
?>
<div id="wl">
  <section class="wl-card" aria-labelledby="wl-title">
    <?= entry_logo() ?>

    <header class="wl-head">
      <p class="wl-eyebrow"><?= entry_icon('staff') ?>Staff</p>
      <h1 id="wl-title">Sign in to your account</h1>
      <p>Use your username or your @<?= e(staff_email_domain()) ?> e-mail address.</p>
    </header>

    <?php if ($error !== ''): ?>
      <?= entry_alert('error', $error) ?>
    <?php elseif (!empty($_GET['timeout'])): ?>
      <?= entry_alert('info', 'You were signed out after 30 minutes without activity. Sign in again to carry on.') ?>
    <?php endif; ?>

    <form method="post" class="wl-form">
      <input type="hidden" name="csrf" value="<?= e($token) ?>">

      <label class="wl-field">
        <span>Username or work e-mail</span>
        <input name="username" required autocapitalize="none" autocorrect="off" spellcheck="false"
               autocomplete="username" value="<?= e($typed) ?>"<?= $typed === '' ? ' autofocus' : '' ?>>
      </label>

      <div class="wl-field">
        <span><label for="pw">Password</label><a href="forgot_password.php">Forgot password?</a></span>
        <input id="pw" name="password" type="password" required autocomplete="current-password"<?= $typed !== '' ? ' autofocus' : '' ?>>
        <p class="wl-caps" aria-live="polite">Caps Lock is on.</p>
      </div>

      <button type="submit" class="wl-btn" data-busy="Signing in…" data-idle="Sign in">
        <span class="wl-spin" aria-hidden="true"></span><span class="wl-btn-label">Sign in</span>
      </button>
    </form>

    <p class="wl-foot">
      <span>Want a new password?</span>
      <a href="change_password.php">Change your password</a>
    </p>
  </section>

  <?= entry_back('welcome.php', 'Back to start') ?>
</div>
<?= entry_script() ?>
<?php layout_bottom(); ?>
