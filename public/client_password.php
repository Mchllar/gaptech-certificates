<?php
/**
 * Client portal: change your password. No e-mail involved, by design - clients'
 * addresses are not kept.
 *
 *   - After signing in with a password staff set (must_change_password), the client
 *     must choose their own before anything else opens. They have just proved the
 *     temporary password, so it is not asked for again.
 *   - Any other time, the current password proves who they are.
 *
 * Forgotten passwords: the client calls the office, staff confirm who is calling and
 * set a temporary password in Client Portal Access, and this page takes it from there.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/accounts.php';
require APP_DIR . '/views/layout.php';
require APP_DIR . '/views/entry.php';

$client = require_client();
$forced = !empty($client['must_change_password']);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $current = (string)($_POST['current'] ?? '');
    $new     = (string)($_POST['password'] ?? '');
    $repeat  = (string)($_POST['password2'] ?? '');

    if (!$forced && !password_verify($current, (string)$client['password_hash'])) {
        usleep(400000);
        $errors[] = 'Your current password is wrong.';
    } elseif (password_verify($new, (string)$client['password_hash'])) {
        $errors[] = $forced ? 'Choose a new password, not the one the office gave you.'
                            : 'The new password must be different from the current one.';
    } elseif (($problem = account_password_problem($new, $repeat)) !== null) {
        $errors[] = $problem;
    } else {
        q('UPDATE clients SET password_hash = ?, must_change_password = false WHERE id = ?',
            [password_hash($new, PASSWORD_DEFAULT), (int)$client['id']]);
        start_session();
        session_regenerate_id(true);
        audit('client_change_password', 'client', (int)$client['id'], ['first_time' => $forced]);
        flash($forced ? 'Your password is set. Welcome to the portal.' : 'Your password has been changed.');
        redirect('client_portal.php');
    }
}

layout_top('Change password', $forced ? 'public' : 'client');
echo entry_styles();
?>
<style>
  body.has-sidebar #wl { max-width: 470px; margin: 8px auto 32px; }
  #wl .wl-rules { list-style: none; margin: 10px 0 0; padding: 10px 12px; border-radius: 10px; background: #F6F9FC;
                  display: grid; grid-template-columns: 1fr 1fr; gap: 6px 12px; font-size: 12.5px; color: #5C6775; }
  #wl .wl-rules li { display: flex; align-items: center; gap: 7px; }
  #wl .wl-rules li::before { content: ""; width: 13px; height: 13px; border-radius: 50%; border: 1.5px solid #B6C2CF; flex: none; }
  #wl .wl-rules li.met { color: #2E7D32; }
  #wl .wl-rules li.met::before { border-color: #2E7D32; background: #2E7D32; box-shadow: inset 0 0 0 2px #fff; }
  #wl .wl-match { margin: 6px 0 0; min-height: 1.2em; font-size: 12.5px; }
  #wl .wl-match.ok { color: #2E7D32; } #wl .wl-match.bad { color: #C3121A; }
  @media (max-width: 400px) { #wl .wl-rules { grid-template-columns: 1fr; } }
</style>

<div id="wl">
  <section class="wl-card" aria-labelledby="wl-title">
    <?php if ($forced): ?><?= entry_logo() ?><?php endif; ?>

    <header class="wl-head">
      <p class="wl-eyebrow is-client"><?= entry_icon('client') ?>Client portal</p>
      <h1 id="wl-title"><?= $forced ? 'Choose your own password' : 'Change your password' ?></h1>
      <p><?= $forced
        ? 'The password from the office works only once. Pick one that only you know.'
        : 'Signed in as ' . e((string)$client['username']) . '.' ?></p>
    </header>

    <?php foreach ($errors as $m): ?><?= entry_alert('error', $m) ?><?php endforeach; ?>

    <form method="post" class="wl-form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <?php /* lets password managers file the new password under the right account */ ?>
      <input type="text" name="username" value="<?= e((string)$client['username']) ?>" autocomplete="username"
             readonly tabindex="-1" aria-hidden="true" style="display:none">

      <?php if (!$forced): ?>
      <div class="wl-field">
        <span><label for="cur">Current password</label></span>
        <input id="cur" name="current" type="password" required autocomplete="current-password" autofocus>
        <p class="wl-caps" aria-live="polite">Caps Lock is on.</p>
      </div>
      <?php endif; ?>

      <div class="wl-field">
        <span><label for="pw">New password</label></span>
        <input id="pw" name="password" type="password" required minlength="8" maxlength="72"
               autocomplete="new-password"<?= $forced ? ' autofocus' : '' ?>>
        <p class="wl-caps" aria-live="polite">Caps Lock is on.</p>
        <ul class="wl-rules" id="pwRules">
          <li data-rule="len">8 or more characters</li>
          <li data-rule="upper">A capital letter</li>
          <li data-rule="digit">A number</li>
          <li data-rule="special">A symbol (! @ # $ …)</li>
        </ul>
      </div>

      <div class="wl-field">
        <span><label for="pw2">Repeat the new password</label></span>
        <input id="pw2" name="password2" type="password" required maxlength="72" autocomplete="new-password">
        <p class="wl-match" id="pwMatch" aria-live="polite"></p>
      </div>

      <button type="submit" class="wl-btn" data-busy="Saving…" data-idle="Save password">
        <span class="wl-spin" aria-hidden="true"></span><span class="wl-btn-label">Save password</span>
      </button>
    </form>

    <p class="wl-foot"><span>Forgotten your password? Call the office and they will give you a one-time password.</span></p>
  </section>

  <?= $forced ? entry_back('logout.php', 'Sign out') : entry_back('client_portal.php', 'Back to my certificates') ?>
</div>

<script>
(function () {
  var pw = document.getElementById('pw'), pw2 = document.getElementById('pw2');
  var rules = document.getElementById('pwRules'), match = document.getElementById('pwMatch');
  var tests = {
    len: function (v) { return v.length >= 8; }, upper: function (v) { return /[A-Z]/.test(v); },
    digit: function (v) { return /[0-9]/.test(v); }, special: function (v) { return /[^A-Za-z0-9]/.test(v); }
  };
  function update() {
    var v = pw.value, all = true;
    rules.querySelectorAll('li').forEach(function (li) {
      var ok = tests[li.dataset.rule](v); li.classList.toggle('met', ok); if (!ok) { all = false; }
    });
    pw.setCustomValidity(all ? '' : 'The password does not meet all the rules yet.');
    var same = pw2.value === v;
    match.textContent = !pw2.value ? '' : same ? '✓ Passwords match' : 'Passwords don’t match yet';
    match.className = 'wl-match' + (!pw2.value ? '' : same ? ' ok' : ' bad');
    pw2.setCustomValidity(same ? '' : 'The two passwords do not match.');
  }
  pw.addEventListener('input', update); pw2.addEventListener('input', update); update();
})();
</script>
<?= entry_script() ?>
<?php layout_bottom(); ?>
