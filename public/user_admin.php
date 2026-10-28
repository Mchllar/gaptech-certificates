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

// What the dialog holds when the page renders. On a failed create these are what
// was typed, so nothing has to be entered twice - the old version flashed an error
// and redirected, which threw the whole form away and shut the dialog on it.
$form = ['name' => '', 'email' => '', 'username' => '', 'role' => 'administrator'];
$createErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $form['name']     = trim((string)($_POST['name'] ?? ''));
        $form['email']    = trim((string)($_POST['email'] ?? ''));
        $form['username'] = trim((string)($_POST['username'] ?? ''));
        $form['role']     = (string)($_POST['role'] ?? '');
        $password         = (string)($_POST['password'] ?? '');

        if ($form['name'] === '' || $form['username'] === '') {
            $createErrors[] = 'Name and username are required.';
        } elseif (q('SELECT 1 FROM users WHERE lower(username) = lower(?)', [$form['username']])->fetch()) {
            $createErrors[] = 'That username is taken.';
        } elseif (($pwError = password_problem($password, (string)($_POST['password2'] ?? ''))) !== null) {
            $createErrors[] = $pwError;
        } elseif (!in_array($form['role'], ['administrator', 'accounts', 'management'], true)) {
            $createErrors[] = 'Invalid role.';
        } elseif ($form['email'] !== ''
                  && q('SELECT 1 FROM users WHERE lower(email) = lower(?)', [$form['email']])->fetch()) {
            // Guarded with !== '' deliberately. Without it, the SECOND account
            // created without an e-mail collides with the first one's blank and is
            // refused - and e-mail is optional, so that is a real path.
            $createErrors[] = 'That e-mail already has an account.';
        } else {
            $id = (int)q('INSERT INTO users (name, username, email, password_hash, role)
                          VALUES (?, ?, ?, ?, ?) RETURNING id',
                [$form['name'], $form['username'], $form['email'],
                 password_hash($password, PASSWORD_DEFAULT), $form['role']])->fetchColumn();

            // The file says every action is written to the audit log; creating an
            // account was the one that was not. Never the password, obviously -
            // not even its length.
            audit('create_user', 'user', $id, [
                'name'     => $form['name'],
                'username' => $form['username'],
                'role'     => $form['role'],
                'by'       => (int)$user['id'],
            ]);

            flash('Account created for ' . $form['name'] . '.');
            redirect('user_admin.php');
        }
        // falls through and renders, with the dialog open and the values still in it
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

// A fresh suggestion each time the page loads, so the dialog opens with a usable
// password already in it.
$suggested = 'Kenya-' . random_int(100000, 999999);

layout_top('');
layout_back('index.php', 'Back to SG. Certificates');
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
    .vfy-sub { font-size: 14px; color: #7A8696; line-height: 1.5;}

    /* Heading on the left, the one button that opens the dialog on the right. */
    .vfy-head { display: flex; flex-wrap: wrap; align-items: center; gap: 12px;
                justify-content: space-between; margin: 0 0 14px; }
    .vfy-head h1.vfy-title { margin: 0; }

    /* Narrower than the certificate dialogs: it is a column of short fields, and a
       720px box would leave each one stranded in white space. */
    #m-newuser .gt-box { width: min(540px, calc(100vw - 32px)); }
    #m-newuser .gt-f select { display: block; width: 100%; margin-top: 5px;
        box-sizing: border-box; padding: 9px 11px; border: 1px solid #C9D3DC;
        border-radius: 6px; font-family: inherit; font-size: 14px; font-weight: 400; }
    #m-newuser .pw-rules { margin: 0 0 14px; }
</style>

<div class="vfy">
  <div class="vfy-head">
    <h1 class="vfy-title">Staff Accounts</h1>
    <button type="button" class="button" data-modal="#m-newuser">New account</button>
  </div>

<?php if ($newPassword): ?>
  <div class="verify-result status-box-valid">
    <p class="verify-status">New password for <?= e((string)$newPasswordFor) ?></p>
    <p class="new-password"><?= e($newPassword) ?></p>
    <p class="vfy-sub">Write it down or pass it on now &mdash; it is not shown again and is not stored anywhere
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
</div>

<!-- ------------------------------------------------------------------ -->
<!-- Create an account                                                  -->
<!--                                                                    -->
<!-- The form used to sit open under the table, which put a pair of     -->
<!-- password boxes permanently on a page whose job is the list above.  -->
<!-- It is now behind the New account button.                           -->
<!-- ------------------------------------------------------------------ -->
<div class="gt-modal" id="m-newuser">
  <form class="gt-box" method="post">
    <h3>Create an account</h3>

    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">

    <?php foreach ($createErrors as $msg): ?>
      <p class="error" style="margin:0 0 12px"><?= e($msg) ?></p>
    <?php endforeach; ?>

    <label class="gt-f">Full name <span class="req">*</span>
      <input name="name" required value="<?= e($form['name']) ?>">
    </label>

    <label class="gt-f">E-mail
      <input name="email" type="email" value="<?= e($form['email']) ?>">
    </label>

    <label class="gt-f">Username <span class="req">*</span>
      <input name="username" required autocapitalize="none" autocomplete="off"
             value="<?= e($form['username']) ?>">
    </label>

    <?php /*
      The hidden role input that sat above this select has gone. Both were called
      "role" and both were submitted, so PHP kept whichever came last - it worked,
      but by accident of ordering rather than by design. One control, one value.
    */ ?>
    <label class="gt-f">Role <span class="req">*</span>
      <select name="role" required>
        <option value="administrator" <?= $form['role'] === 'administrator' ? 'selected' : '' ?>>Administrator</option>
        <option value="accounts" <?= $form['role'] === 'accounts' ? 'selected' : '' ?>>Accounts</option>
        <option value="management" <?= $form['role'] === 'management' ? 'selected' : '' ?>>Management</option>
      </select>
      <span class="ca-note">
        <strong>Administrator</strong> &mdash; raises certificates and runs the system.
        <strong>Accounts</strong> &mdash; confirms payments and releases certificates.
        <strong>Management</strong> &mdash; the dashboard only. No certificates, no
        approvals, no settings, and no access to anything else even by typing the
        address.
      </span>
    </label>

    <label class="gt-f">Password <span class="req">*</span>
      <input name="password" id="pw" type="text" minlength="8" required
             autocomplete="off" value="<?= e($suggested) ?>">
    </label>

    <label class="gt-f">Repeat password <span class="req">*</span>
      <input name="password2" id="pw2" type="text" minlength="8" required autocomplete="off">
    </label>

    <ul class="pw-rules" id="pwRules">
      <li data-rule="len">At least 8 characters</li>
      <li data-rule="upper">One capital letter</li>
      <li data-rule="digit">One number</li>
      <li data-rule="special">One special character (! ? @ # $ % &amp; * - _ etc.)</li>
      <li data-rule="match">Both entries match</li>
    </ul>

    <p class="gt-hint">The password shows as plain text on purpose &mdash; it has to be read
      out or written down for the person it belongs to. Tell them to change it once they
      have signed in.</p>

    <div class="gt-foot">
      <button type="button" class="button secondary gt-close">Cancel</button>
      <button type="submit" class="button">Create account</button>
    </div>
  </form>
</div>

<?php require_once APP_DIR . '/views/modal_base.php'; ?>

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

  // A create that failed would otherwise come back with the dialog shut and the
  // reason hidden behind it, which looks like nothing happened at all.
<?php if ($createErrors): ?>
  var dlg = document.getElementById('m-newuser');
  if (dlg) { dlg.classList.add('on'); }
<?php endif; ?>
})();
</script>
<?php layout_bottom(); ?>