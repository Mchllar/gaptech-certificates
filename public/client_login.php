<?php
/** Client sign-in for the download portal. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/views/layout.php';
require APP_DIR . '/views/entry.php';

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
        // A password staff gave them works once: first stop is choosing their own.
        redirect(!empty($client['must_change_password']) ? 'client_password.php' : 'client_portal.php');
    }
    $error = 'Wrong username or password.';
    usleep(400000);
}

$typed = (string)($_POST['username'] ?? '');
$phone = trim((string)setting('cell', ''));

layout_top('Client sign in', 'public');
echo entry_styles();
?>
<div id="wl">
  <section class="wl-card" aria-labelledby="wl-title">
    <?= entry_logo() ?>

    <header class="wl-head">
      <p class="wl-eyebrow is-client"><?= entry_icon('client') ?>Client portal</p>
      <h1 id="wl-title">Sign in to your certificates</h1>
      <p>See and download the certificates for your vehicles.</p>
    </header>

    <?php if ($error !== ''): ?>
      <?= entry_alert('error', $error) ?>
    <?php elseif (!empty($_GET['timeout'])): ?>
      <?= entry_alert('info', 'You were signed out after 30 minutes without activity. Sign in again to carry on.') ?>
    <?php endif; ?>

    <form method="post" class="wl-form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <label class="wl-field">
        <span>Username</span>
        <input name="username" required autocapitalize="none" autocorrect="off" spellcheck="false"
               autocomplete="username" value="<?= e($typed) ?>"<?= $typed === '' ? ' autofocus' : '' ?>>
      </label>

      <div class="wl-field">
        <span><label for="pw">Password</label></span>
        <input id="pw" name="password" type="password" required autocomplete="current-password"<?= $typed !== '' ? ' autofocus' : '' ?>>
        <p class="wl-caps" aria-live="polite">Caps Lock is on.</p>
      </div>

      <button type="submit" class="wl-btn" data-busy="Signing in…" data-idle="Sign in">
        <span class="wl-spin" aria-hidden="true"></span><span class="wl-btn-label">Sign in</span>
      </button>
    </form>

    <p class="wl-foot">
      <span>No password yet, or forgotten it?
        <?php if ($phone !== ''):
            // The setting can hold more than one number ("0797 719 161 / 0705 624 830"): one link each.
            $links = [];
            foreach (preg_split('~\s*[/,;]\s*|\s+or\s+~i', $phone) ?: [] as $num) {
                $dial = preg_replace('/[^0-9+]/', '', $num);
                if ($dial !== '') { $links[] = '<a href="tel:' . e($dial) . '">' . e(trim($num)) . '</a>'; }
            } ?>Call the office on <?= implode(' or ', $links) ?>.
        <?php else: ?>Please call the office.<?php endif; ?>
      </span>
    </p>
  </section>

  <?= entry_back('welcome.php', 'Back to start') ?>
</div>
<?= entry_script() ?>
<?php layout_bottom(); ?>
