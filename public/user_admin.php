<?php
/**
 * Administrator accounts: add people, reset a forgotten password, deactivate someone who leaves.
 * Every action is written to the audit log.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/views/layout.php';

/** Returns an error message, or null when the password is acceptable. */
function password_problem(string $password, string $repeat): ?string
{
    if (strlen($password) < 8) {
        return 'The password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'The password needs at least one capital letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'The password needs at least one number.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'The password needs at least one special character.';
    }
    if ($password !== $repeat) {
        return 'The two passwords do not match.';
    }
    return null;
}

$user = require_supervisor();
$newPassword = null;          // shown once, after a reset
$newPasswordFor = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $name = trim((string)$_POST['name']);
        $email = trim((string)$_POST['email']);
        $role = (string)$_POST['role'];
        $password = (string)$_POST['password'];
          if ($name === '' || $email === '') {
            flash('Name and e-mail are required.');
          } elseif (($pwError = password_problem($password, (string)($_POST['password2'] ?? ''))) !== null) {
            flash($pwError);
          } elseif ($role !=='administrator') {
            flash('Invalid role.');
          } elseif (q('SELECT 1 FROM users WHERE lower(email) = lower(?)', [$email])->fetch()) {
            flash('That e-mail already has an account.'); 
          } else {
            $id = (int)q('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?) RETURNING id',
                [$name, $email, password_hash($password, PASSWORD_DEFAULT), $role])->fetchColumn();
            audit('create_user', 'user', $id, ['email' => $email, 'role' => $role]);
            flash('Account created for ' . $name . '.');
        }
        redirect('user_admin.php');
    }

    if ($action === 'reset') {
        $target = q('SELECT * FROM users WHERE id = ?', [(int)$_POST['user_id']])->fetch();
        if (!$target) {
            flash('Account not found.');
            redirect('user_admin.php');
        }
        // A readable one-time password: two words and four digits, e.g. Lamu-Garissa-4821
        $words = ['Lamu', 'Garissa', 'Kisumu', 'Nanyuki', 'Malindi', 'Kericho', 'Voi', 'Nyeri',
                  'Isiolo', 'Naivasha', 'Kitui', 'Meru'];
        $newPassword = $words[random_int(0, count($words) - 1)] . '-'
            . $words[random_int(0, count($words) - 1)] . '-' . random_int(1000, 9999);
        q('UPDATE users SET password_hash = ? WHERE id = ?',
            [password_hash($newPassword, PASSWORD_DEFAULT), $target['id']]);
        audit('reset_password', 'user', (int)$target['id'], ['email' => $target['email']]);
        $newPasswordFor = $target['name'];
    }

    if ($action === 'toggle') {
        $target = q('SELECT * FROM users WHERE id = ?', [(int)$_POST['user_id']])->fetch();
        if ($target && (int)$target['id'] === (int)$user['id']) {
            flash('You cannot deactivate your own account.');
        } elseif ($target) {
            $active = !$target['active'];
            if (!$active && (int)q("SELECT count(*) FROM users WHERE active AND role = 'supervisor'")
                    ->fetchColumn() <= 1 && $target['role'] === 'supervisor') {
                flash('There must always be at least one active supervisor.');
            } else {
                q('UPDATE users SET active = ? WHERE id = ?', [$active ? 't' : 'f', $target['id']]);
                audit($active ? 'activate_user' : 'deactivate_user', 'user', (int)$target['id']);
                flash($target['name'] . ' is now ' . ($active ? 'active' : 'inactive') . '.');
            }
        }
        redirect('user_admin.php');
    }
}

$users = q('SELECT * FROM users ORDER BY active DESC, name')->fetchAll();
layout_top('Staff accounts');
?>
<h1>Administrator Accounts</h1>

<?php if ($newPassword): ?>
  <div class="verify-result status-box-valid">
    <p class="verify-status">New password for <?= e((string)$newPasswordFor) ?></p>
    <p class="new-password"><?= e($newPassword) ?></p>
    <p class="hint">Write it down or pass it on now &mdash; it is not shown again and is not stored anywhere
      in readable form. Tell them to change it after signing in.</p>
  </div>
<?php endif; ?>

<table>
  <tr><th>Name</th><th>E-mail</th><th>Role</th><th>Status</th><th>Added</th><th></th></tr>
  <?php foreach ($users as $row): ?>
  <tr>
    <td><?= e($row['name']) ?><?= (int)$row['id'] === (int)$user['id'] ? ' (you)' : '' ?></td>
    <td><?= e($row['email']) ?></td>
    <td><?= e($row['role']) ?></td>
    <td class="<?= $row['active'] ? 'status-valid' : 'status-voided' ?>">
      <?= $row['active'] ? 'Active' : 'Inactive' ?></td>
    <td><?= e(fmt_date(substr((string)$row['created_at'], 0, 10))) ?></td>
    <td class="row-actions">
      <form method="post" onsubmit="return confirm('Reset this password? The current one stops working.');">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
        <button type="submit" class="link-button">Reset password</button>
      </form>
      <?php if ((int)$row['id'] !== (int)$user['id']): ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
        <button type="submit" class="link-button"><?= $row['active'] ? 'Deactivate' : 'Activate' ?></button>
      </form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>

<!--<h2>Create an Account</h2>-->
<form method="post" class="card-form">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="create">
  <fieldset>
    <legend>Create New Account</legend>
    <label class="field">Full name <span class="req">*</span>
      <span class="field-row"><input name="name" required></span></label>

    <label class="field">E-mail <span class="req">*</span>
      <span class="field-row"><input name="email" type="email" required></span></label>

        <input type="hidden" name="role" value="administrator"></span>
    <label class="field">Role <span class="req">*</span>
        <span class="field-row">
        <select name="role" required>
         <!-- <option value="staff">Staff</option>-->
          <option value="administrator">Administrator</option>
        </select>
      </span></lable>
    </label>
    <label class="field">Password <span class="req">*</span>
      <span class="field-row"><input name="password" id="pw" type="text" minlength="8" required
        value="<?= e('Kenya-' . random_int(100000, 999999)) ?>"></span></label>
    <label class="field">Repeat password <span class="req">*</span>
      <span class="field-row"><input name="password2" id="pw2" type="text" minlength="8" required
        value="<?= e('') ?>"></span></label>
    <ul class="pw-rules" id="pwRules">
      <li data-rule="len">At least 8 characters</li>
      <li data-rule="upper">One capital letter</li>
      <li data-rule="digit">One number</li>
      <li data-rule="special">One special character (! ? @ # $ % &amp; * - _ etc.)</li>
      <li data-rule="match">Both entries match</li>
    </ul>
  </fieldset>
  <button type="submit">Create account</button>
</form>

<!--Password Script-->
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

<!--<p class="hint">Administrators can reach Settings and can void and amend certificates. Staff can issue, print
and search. If every administrator is locked out, run <code>php tools/reset_password.php</code> on the server.</p>-->
<?php layout_bottom(); ?>
