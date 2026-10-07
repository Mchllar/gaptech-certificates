<?php
/**
 * Change your own password.
 *
 * Works signed in (from the menu) or signed out (from the login page), because it always
 * asks for the current password. Forgotten passwords go through forgot_password.php instead.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/views/layout.php';

$me = current_user();                       // null when signed out
$errors = [];
$done = false;
$email = $me['email'] ?? '';

/** Returns an error message, or null when the password is acceptable. */
if (!function_exists('password_problem')) {
    function password_problem(string $password, string $repeat): ?string
    {
        if (strlen($password) < 8) {
            return 'The new password must be at least 8 characters.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return 'The new password needs at least one capital letter.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            return 'The new password needs at least one number.';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return 'The new password needs at least one special character.';
        }
        if ($password !== $repeat) {
            return 'The two new passwords do not match.';
        }
        return null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    //$email = $me ? $me['email'] : trim((string)($_POST['email'] ?? ''));
    $username = trim((string)$_POST['username']);
    $current = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['password'] ?? '');
    $repeat = (string)($_POST['password2'] ?? '');

    $user = q('SELECT * FROM users WHERE lower(username) = lower(?) AND active', [$username])->fetch();

    if (!$user || !password_verify($current, $user['password_hash'])) {
        usleep(400000);
        $errors[] = 'The username or current password is wrong.';
    } elseif ($new === $current) {
        $errors[] = 'The new password must be different from the current one.';
    } elseif (($problem = password_problem($new, $repeat)) !== null) {
        $errors[] = $problem;
    } else {
        q('UPDATE users SET password_hash = ? WHERE id = ?',
            [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        // any outstanding reset links for this account stop working
        q('UPDATE password_resets SET used_at = now() WHERE user_id = ? AND used_at IS NULL',
            [$user['id']]);
        audit('change_password', 'user', (int)$user['id']);
        $done = true;
    }
}


layout_top('Change password', $me ? 'staff' : 'public');
layout_back($me['role'] === 'accounts' ? 'approvals.php' : 'index.php', 'Back');

?>
<h1>Change your password</h1>

<?php if ($done): ?>
  <p class="flash">Your password has been changed.</p>
  <p><a class="button" href="<?= $me ? 'index.php' : 'login.php' ?>">
    <?= $me ? 'Back to certificates' : 'Go to sign in' ?></a></p>
<?php else: ?>

  <?php foreach ($errors as $msg): ?><p class="error"><?= e($msg) ?></p><?php endforeach; ?>

  <form method="post" class="narrow">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <?php if ($me): ?>
      <p class="hint">Signed in as <strong><?= e($me['email']) ?></strong>.</p>
    <?php else: ?>
      <label>E-mail <input name="email" type="email" value="<?= e($email) ?>" required autofocus></label>
    <?php endif; ?>

    <label>Current password <input name="current" type="password" required
      <?= $me ? 'autofocus' : '' ?>></label>
    <label>New password <input name="password" id="pw" type="password" minlength="8" required></label>
    <label>Repeat new password <input name="password2" id="pw2" type="password" minlength="8" required></label>

    <ul class="pw-rules" id="pwRules">
      <li data-rule="len">At least 8 characters</li>
      <li data-rule="upper">One capital letter</li>
      <li data-rule="digit">One number</li>
      <li data-rule="special">One special character (! ? @ # $ % &amp; * - _ etc.)</li>
      <li data-rule="match">Both entries match</li>
    </ul>

    <button type="submit">Change password</button>
  </form>

  <p class="hint">
    <?php if (!$me): ?><a href="login.php">Back to sign in</a> &middot; <?php endif; ?>
    <a href="forgot_password.php">Forgotten it completely?</a>
  </p>
<?php endif; ?>

<script>
(function () {
  var pw = document.getElementById('pw'), pw2 = document.getElementById('pw2');
  var list = document.getElementById('pwRules');
  if (!pw || !list) { return; }
  var tests = {
    len: function (v) { return v.length >= 8; },
    upper: function (v) { return /[A-Z]/.test(v); },
    digit: function (v) { return /[0-9]/.test(v); },
    special: function (v) { return /[^A-Za-z0-9]/.test(v); },
    match: function (v) { return v.length > 0 && v === pw2.value; }
  };
  function check() {
    var v = pw.value, allOk = true;
    list.querySelectorAll('li').forEach(function (li) {
      var ok = tests[li.dataset.rule](v);
      li.classList.toggle('met', ok);
      if (!ok) { allOk = false; }
    });
    pw.setCustomValidity(allOk ? '' : 'The password does not meet all the rules yet.');
  }
  pw.addEventListener('input', check);
  pw2.addEventListener('input', check);
  check();
})();
</script>
<?php layout_bottom(); ?>