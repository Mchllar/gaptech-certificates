<?php
/**
 * Administrator accounts: add people, reset a forgotten password, deactivate someone who leaves.
 * Every action is written to the audit log.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/views/layout.php';
require APP_DIR . '/accounts.php';

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
$mailOn = mail_configured();
$domain = staff_email_domain();
$form = ['name' => '', 'email' => '', 'username' => '', 'role' => 'administrator',
         'setup' => $mailOn ? 'invite' : 'password'];
$createErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $form['name']     = trim((string)($_POST['name'] ?? ''));
        $form['email']    = trim((string)($_POST['email'] ?? ''));
        $form['username'] = trim((string)($_POST['username'] ?? ''));
        $form['role']     = (string)($_POST['role'] ?? '');
        $form['setup']    = ($_POST['setup'] ?? '') === 'invite' && $mailOn ? 'invite' : 'password';
        $password         = (string)($_POST['password'] ?? '');
        $invite           = $form['setup'] === 'invite';

        if ($form['name'] === '' || $form['username'] === '') {
            $createErrors[] = 'Name and username are required.';
        } elseif (q('SELECT 1 FROM users WHERE lower(username) = lower(?)', [$form['username']])->fetch()) {
            $createErrors[] = 'That username is taken.';
        } elseif (!is_staff_email($form['email'])) {
            // Staff accounts belong to company mailboxes only. This is what stops an
            // account being made for a private address that the company cannot close.
            $createErrors[] = 'Use their @' . $domain . ' e-mail address.';
        } elseif (q('SELECT 1 FROM users WHERE lower(email) = lower(?)', [$form['email']])->fetch()) {
            $createErrors[] = 'That e-mail already has an account.';
        } elseif (!$invite && ($pwError = password_problem($password, (string)($_POST['password2'] ?? ''))) !== null) {
            $createErrors[] = $pwError;
        } elseif (!in_array($form['role'], ['administrator', 'accounts', 'management'], true)) {
            $createErrors[] = 'Invalid role.';
        } else {
            // Invited: a long random password nobody knows, until they choose their own with the code.
            $hash = password_hash($invite ? bin2hex(random_bytes(24)) : $password, PASSWORD_DEFAULT);
            $id = (int)q('INSERT INTO users (name, username, email, password_hash, role)
                          VALUES (?, ?, ?, ?, ?) RETURNING id',
                [$form['name'], $form['username'], strtolower($form['email']), $hash, $form['role']])->fetchColumn();

            // The file says every action is written to the audit log; creating an
            // account was the one that was not. Never the password, obviously -
            // not even its length.
            audit('create_user', 'user', $id, [
                'name'     => $form['name'],
                'username' => $form['username'],
                'role'     => $form['role'],
                'setup'    => $form['setup'],
                'by'       => (int)$user['id'],
            ]);

            if ($invite) {
                $newUser = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
                $why = null;
                flash(otp_send($newUser, 'setup', $why)
                    ? 'Account created. ' . $form['name'] . ' has been e-mailed a setup code at ' . $form['email'] . ' (valid 48 hours).'
                    : 'Account created, but the setup e-mail failed: ' . $why . ' Use "Email setup code" to try again.');
            } else {
                flash('Account created for ' . $form['name'] . '.');
            }
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
        // Hiding the button is not enough on its own: refuse it here too.
        if ($mailOn && is_staff_email((string)$target['email'])) {
            flash($target['name'] . ' can reset their own password with "Forgot password?" on the sign-in page, '
                . 'or use "Email setup code" to send them one.');
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

    if ($action === 'send_setup') {
        // Works for a new account and as an e-mailed reset for an existing one:
        // either way the person chooses their own password and nobody else sees it.
        $target = q('SELECT * FROM users WHERE id = ? AND active', [(int)$_POST['user_id']])->fetch();
        $why = null;
        if (!$target) {
            flash('Account not found or inactive.');
        } elseif (otp_send($target, 'setup', $why)) {
            flash('Setup code e-mailed to ' . $target['email'] . '. It works for 48 hours.');
        } else {
            flash('Not sent: ' . $why);
        }
        redirect('user_admin.php');
    }

    if ($action === 'set_email') {
        $target = q('SELECT * FROM users WHERE id = ?', [(int)$_POST['user_id']])->fetch();
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if (!$target) {
            flash('Account not found.');
        } elseif (!is_staff_email($email)) {
            flash('Not saved: use an @' . $domain . ' address.');
        } elseif (q('SELECT 1 FROM users WHERE lower(email) = ? AND id <> ?', [$email, (int)$target['id']])->fetch()) {
            flash('Not saved: ' . $email . ' already belongs to another account.');
        } else {
            q('UPDATE users SET email = ? WHERE id = ?', [$email, (int)$target['id']]);
            // Codes already sent went to the old address: they stop working.
            q('UPDATE email_codes SET used_at = now() WHERE user_id = ? AND used_at IS NULL', [(int)$target['id']]);
            audit('set_email', 'user', (int)$target['id'], ['from' => $target['email'], 'to' => $email]);
            flash('E-mail for ' . $target['name'] . ' is now ' . $email . '.');
        }
        redirect('user_admin.php');
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
  </style>
<?php require_once APP_DIR . '/views/dialog_styles.php'; ?>
<?= nu_dialog_css('#m-newuser') ?>
<style>
  #m-newuser .nu-help { margin: 5px 0 0; font-size: 12px; color: #8793A3; }
  #m-newuser .nu-help.bad { color: #C3121A; }
  #m-newuser .nu-setup { margin-bottom: 4px; }
  #m-newuser .nu-role.is-off { opacity: .55; cursor: not-allowed; }
  #m-newuser #nuPwBlock[hidden] { display: none !important; }

  /* ---------------------------------------------------------------- the staff table
     Scoped to #ua-table: the shared rules make every submit button a big solid block,
     which is what crowded the last column. Here they are small outlined actions. */
  /* This page gets more room than the 1100px default: six columns and three actions. */
  body.has-sidebar main:has(#ua-table) { max-width: 1424px; }
  /* No sideways scrolling. The wrapper is a size container, so the buttons below switch
     to icons based on the room the TABLE actually has - window size and Windows display
     scaling no longer matter. */
  .ua-wrap { container-type: inline-size; border-radius: 14px; }
  #ua-table { width: 100%; }
  #ua-table td { vertical-align: middle; padding-top: 14px; padding-bottom: 14px; }
  #ua-table .ua-name { display: block; font-weight: 600; color: #1C2430; white-space: nowrap; }
  #ua-table .ua-user { display: block; margin-top: 2px; font-size: 12.5px; color: #8793A3;
                       font-family: var(--gt-font-mono, Consolas, monospace); }
  #ua-table .ua-you { display: inline-block; margin-left: 4px; padding: 1px 7px; border-radius: 999px;
                      background: #E6F0F7; color: #0D4F72; font-size: 11px; font-weight: 700; vertical-align: 1px; }
  #ua-table .ua-email { max-width: 260px; }
  #ua-table .ua-email > span:first-child { display: block; overflow: hidden; text-overflow: ellipsis;
                                           white-space: nowrap; color: #3D4756; }
  #ua-table .ua-date { white-space: nowrap; color: #5C6775; font-variant-numeric: tabular-nums; }
  #ua-table .ua-act-h { width: 1%; }
  .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

  #ua-table .ua-role { display: inline-block; padding: 3px 10px; border-radius: 999px; white-space: nowrap;
                       font-size: 12.5px; font-weight: 600; background: #EEF2F6; color: #3D4756; }
  #ua-table .ua-role-administrator { background: #E6F0F7; color: #0D4F72; }
  #ua-table .ua-role-accounts      { background: #EDF6E3; color: #4E7A1C; }
  #ua-table .ua-role-management    { background: #F3ECFA; color: #6A3E99; }

  #ua-table .ua-status { display: inline-flex; align-items: center; gap: 7px; font-weight: 600;
                         font-size: 13.5px; white-space: nowrap; }
  #ua-table .ua-status::before { content: ""; width: 8px; height: 8px; border-radius: 50%; }
  #ua-table .ua-status.on  { color: #2E7D32; } #ua-table .ua-status.on::before  { background: #2E7D32; }
  #ua-table .ua-status.off { color: #8793A3; } #ua-table .ua-status.off::before { background: #B6C2CF; }
  #ua-table tr.is-off .ua-name, #ua-table tr.is-off .ua-email > span:first-child { color: #8793A3; }

  #ua-table .ua-actions { display: flex; justify-content: flex-end; align-items: center; gap: 8px; flex-wrap: nowrap; }
  #ua-table .ua-actions form { margin: 0; display: contents; }
  #ua-table .ua-btn {
    display: inline-flex; align-items: center; gap: 6px; margin: 0; height: 34px; padding: 0 12px;
    border: 1px solid #D9E2EC; border-radius: 9px; background: #fff; color: #0D4F72; box-shadow: none;
    font: inherit; font-size: 13px; font-weight: 600; line-height: 1; white-space: nowrap; cursor: pointer;
    text-decoration: none; transition: background-color .15s, border-color .15s, color .15s;
  }
  #ua-table .ua-btn svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2;
                          stroke-linecap: round; stroke-linejoin: round; flex: none; }
  #ua-table .ua-btn:hover { background: #F2F7FB; border-color: #BFD3E3; box-shadow: none; transform: none; }
  #ua-table .ua-btn:focus-visible { outline: 3px solid rgba(24,144,208,.35); outline-offset: 2px; }
  #ua-table .ua-btn.is-danger { color: #B42318; }
  #ua-table .ua-btn.is-danger:hover { background: #FEF3F2; border-color: #F4C7C3; }
  #ua-table .ua-btn.is-go { color: #2E7D32; }
  #ua-table .ua-btn.is-go:hover { background: #EFF8EC; border-color: #CFE7C2; }

  /* Too little room for the words: icon-only buttons. The words stay in the markup for
     screen readers (font-size 0 hides them visually only) and show again as the tooltip. */
  @container (max-width: 1140px) {
    #ua-table .ua-btn { font-size: 0; gap: 0; width: 34px; padding: 0; justify-content: center; }
    #ua-table .ua-btn svg { width: 16px; height: 16px; }
    #ua-table .ua-actions { gap: 6px; }
  }
  /* Tighter still: the username line and the added date give way, never a scrollbar. */
  @container (max-width: 820px) {
    #ua-table .ua-user { display: none; }
    #ua-table th:nth-child(5), #ua-table td:nth-child(5) { display: none; }
    #ua-table .ua-email { max-width: 200px; }
  }
  .ua-flag { display: inline-block; margin-top: 2px; padding: 1px 7px; border-radius: 999px;
             background: #FFF4E0; color: #8A5A00; font-size: 11.5px; font-weight: 600; white-space: nowrap; }
  #ua-table .ua-email .ua-flag { margin-top: 4px; }

  #m-email .gt-box { width: min(440px, calc(100vw - 32px)); }
  #m-email .em-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }
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

<div class="ua-wrap">
<table id="ua-table">
  <thead>
    <tr><th>Name</th><th>E-mail</th><th>Role</th><th>Status</th><th>Added</th><th class="ua-act-h"><span class="sr-only">Actions</span></th></tr>
  </thead>
  <tbody>
  <?php foreach ($users as $row):
      $isMe    = (int)$row['id'] === (int)$user['id'];
      $okEmail = is_staff_email((string)$row['email']); ?>
  <tr class="<?= $row['active'] ? '' : 'is-off' ?>">
    <td>
      <span class="ua-name"><?= e($row['name']) ?><?php if ($isMe): ?> <span class="ua-you">You</span><?php endif; ?></span>
      <span class="ua-user"><?= e((string)$row['username']) ?></span>
    </td>
    <td class="ua-email">
      <?php if ($row['email'] === ''): ?>
        <span class="ua-flag">No e-mail</span>
      <?php else: ?>
        <span title="<?= e($row['email']) ?>"><?= e($row['email']) ?></span>
        <?php if (!$okEmail): ?><span class="ua-flag">Not a @<?= e($domain) ?> address</span><?php endif; ?>
      <?php endif; ?>
    </td>
    <td><span class="ua-role ua-role-<?= e((string)$row['role']) ?>"><?= e(ucfirst((string)$row['role'])) ?></span></td>
    <td><span class="ua-status <?= $row['active'] ? 'on' : 'off' ?>"><?= $row['active'] ? 'Active' : 'Inactive' ?></span></td>
    <td class="ua-date"><?= e(fmt_date(substr((string)$row['created_at'], 0, 10))) ?></td>
    <td>
      <div class="ua-actions">
      <?php /* With a company address and mail working, people reset their own password with an
               e-mailed code, so a password an administrator can read is not offered. */ ?>
      <?php if (!($mailOn && $okEmail)): ?>
        <form method="post" onsubmit="return confirm('Reset this password? The current one stops working.');">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="reset">
          <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
          <button type="submit" class="ua-btn" title="Make a new password to pass on yourself">
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/></svg>Reset password</button>
        </form>
      <?php endif; ?>
      <?php if ($mailOn && $row['active'] && $okEmail): ?>
        <form method="post" onsubmit="return confirm('E-mail a setup code to <?= e(addslashes((string)$row['email'])) ?>? They will use it to choose a new password.');">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="send_setup">
          <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
          <button type="submit" class="ua-btn" title="E-mail them a code to choose a new password">
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5l8.5 6.5 8.5-6.5"/></svg>Send code</button>
        </form>
      <?php endif; ?>
        <button type="button" class="ua-btn" data-modal="#m-email" title="Change their e-mail address"
                data-uid="<?= (int)$row['id'] ?>" data-name="<?= e($row['name']) ?>"
                data-email="<?= e((string)$row['email']) ?>">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/></svg>Edit e-mail</button>
      <?php if (!$isMe): ?>
        <form method="post"<?php if ($row['active']): ?> onsubmit="return confirm('Deactivate <?= e(addslashes($row['name'])) ?>? They will not be able to sign in.');"<?php endif; ?>>
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
          <button type="submit" class="ua-btn <?= $row['active'] ? 'is-danger' : 'is-go' ?>"
                  title="<?= $row['active'] ? 'Deactivate: they can no longer sign in' : 'Activate: let them sign in again' ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v8"/><path d="M6.4 6.6a8 8 0 1 0 11.2 0"/></svg><?= $row['active'] ? 'Deactivate' : 'Activate' ?></button>
        </form>
      <?php endif; ?>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</div>

<!-- ------------------------------------------------------------------ -->
<!-- Create an account                                                  -->
<!--                                                                    -->
<!-- The form used to sit open under the table, which put a pair of     -->
<!-- password boxes permanently on a page whose job is the list above.  -->
<!-- It is now behind the New account button.                           -->
<!-- ------------------------------------------------------------------ -->
<div class="gt-modal" id="m-newuser" role="dialog" aria-modal="true" aria-labelledby="nu-title">
  <form class="gt-box" method="post" id="nuForm" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">

    <div class="nu-head">
      <div class="nu-badge" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
             stroke-linecap="round" stroke-linejoin="round">
          <circle cx="10" cy="8" r="3.6"/><path d="M3.5 20c.6-3.6 3.3-5.8 6.5-5.8 1.5 0 2.9.5 4 1.3"/>
          <path d="M18.5 14.5v6M15.5 17.5h6"/>
        </svg>
      </div>
      <div>
        <h3 id="nu-title">New staff account</h3>
        <p>Set up sign-in details for a member of staff.</p>
      </div>
      <button type="button" class="nu-x gt-close" aria-label="Close">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
    </div>

    <div class="nu-body">
      <?php foreach ($createErrors as $msg): ?>
        <div class="nu-alert" role="alert">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
               stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><path d="M12 7.5v5.5M12 16.5v.01"/></svg>
          <span><?= e($msg) ?></span>
        </div>
      <?php endforeach; ?>

      <div class="nu-section">Person</div>

      <div class="nu-field">
        <label class="nu-label" for="nuName">Full name <span class="req">*</span></label>
        <input class="nu-input" id="nuName" name="name" required value="<?= e($form['name']) ?>"
               placeholder="e.g. Jane Wanjiru" autocomplete="off">
      </div>

      <div class="nu-grid">
        <div class="nu-field">
          <label class="nu-label" for="nuUser">Username <span class="req">*</span></label>
          <input class="nu-input nu-mono" id="nuUser" name="username" required autocapitalize="none"
                 spellcheck="false" autocomplete="off" value="<?= e($form['username']) ?>"
                 placeholder="jane.wanjiru">
        </div>
        <div class="nu-field">
          <label class="nu-label" for="nuEmail">Work e-mail <span class="req">*</span></label>
          <input class="nu-input" id="nuEmail" name="email" type="email" required value="<?= e($form['email']) ?>"
                 placeholder="name@<?= e($domain) ?>" autocomplete="off" spellcheck="false"
                 pattern="[^@\s]+@<?= e(preg_quote($domain, '/')) ?>"
                 title="An @<?= e($domain) ?> address">
          <p class="nu-help" id="nuEmailHelp">Must be an @<?= e($domain) ?> address.</p>
        </div>
      </div>

      <div class="nu-gap"></div>
      <div class="nu-section">Access</div>

      <?php /*
        One control, one value: the hidden "role" input that used to sit above the
        select is gone, and the select is now three radio cards with the same name.
      */ ?>
      <div class="nu-roles" role="radiogroup" aria-label="Role">
        <?php
        $roles = [
            'administrator' => ['Administrator', 'Raises certificates and runs the system.'],
            'accounts'      => ['Accounts', 'Confirms payments and releases certificates.'],
            'management'    => ['Management', 'The dashboard only. No certificates, approvals or settings, '
                                            . 'and nothing else even by typing the address.'],
        ];
        foreach ($roles as $value => [$label, $desc]): ?>
          <label class="nu-role">
            <input type="radio" name="role" value="<?= e($value) ?>" required
                   <?= $form['role'] === $value ? 'checked' : '' ?>>
            <span class="nu-dot" aria-hidden="true"></span>
            <span><b><?= e($label) ?></b><span class="nu-desc"><?= e($desc) ?></span></span>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="nu-section">How they get in</div>

      <div class="nu-roles nu-setup" role="radiogroup" aria-label="How they get in">
        <label class="nu-role<?= $mailOn ? '' : ' is-off' ?>">
          <input type="radio" name="setup" value="invite" <?= $form['setup'] === 'invite' ? 'checked' : '' ?>
                 <?= $mailOn ? '' : 'disabled' ?>>
          <span class="nu-dot" aria-hidden="true"></span>
          <span><b>E-mail them a setup code</b><span class="nu-desc"><?= $mailOn
            ? 'Recommended. They choose their own password; nobody else ever sees it. The code works for 48 hours.'
            : 'Not available: e-mail is not set up on this server.' ?></span></span>
        </label>
        <label class="nu-role">
          <input type="radio" name="setup" value="password" <?= $form['setup'] === 'password' ? 'checked' : '' ?>>
          <span class="nu-dot" aria-hidden="true"></span>
          <span><b>Set a password now</b><span class="nu-desc">You pass it on yourself, and they change it after signing in.</span></span>
        </label>
      </div>

      <div id="nuPwBlock">
      <div class="nu-section">Sign-in password</div>

      <div class="nu-field">
        <label class="nu-label" for="pw">Password <span class="req">*</span></label>
        <div class="nu-pw">
          <input class="nu-input nu-mono" id="pw" name="password" type="text" minlength="8" maxlength="72"
                 required autocomplete="off" spellcheck="false" value="<?= e($suggested) ?>">
          <div class="nu-tools">
            <button type="button" class="nu-tool" id="nuGen" title="Generate a new password">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                   stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M20 11a8 8 0 0 0-14.3-4.9L4 8M4 4v4h4M4 13a8 8 0 0 0 14.3 4.9L20 16M20 20v-4h-4"/></svg>
              <span class="nu-tool-text">New</span>
            </button>
            <button type="button" class="nu-tool" id="nuCopy" title="Copy the password">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                   stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="8.5" y="8.5" width="12" height="12" rx="2"/><path d="M15.5 8.5V5.5a2 2 0 0 0-2-2h-8a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h3"/></svg>
              <span class="nu-tool-text">Copy</span>
            </button>
          </div>
        </div>
        <ul class="nu-rules" id="pwRules">
          <li data-rule="len">8+ characters</li>
          <li data-rule="upper">Capital letter</li>
          <li data-rule="digit">Number</li>
          <li data-rule="special">Symbol</li>
        </ul>
      </div>

      <div class="nu-field">
        <label class="nu-label" for="pw2">Repeat password <span class="req">*</span></label>
        <input class="nu-input nu-mono" id="pw2" name="password2" type="text" minlength="8" maxlength="72"
               required autocomplete="off" spellcheck="false" value="<?= e($suggested) ?>">
        <p class="nu-match" id="nuMatch" aria-live="polite"></p>
      </div>

      <div class="nu-note">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><path d="M12 11v5.5M12 7.5v.01"/></svg>
        <span>The password is shown on purpose so you can read it out or pass it on.
          Ask them to change it the first time they sign in.</span>
      </div>
      </div><!-- /#nuPwBlock -->
    </div>

    <div class="nu-foot">
      <button type="button" class="button secondary gt-close">Cancel</button>
      <button type="submit" class="button" id="nuSubmit">Create account</button>
    </div>
  </form>
</div>

<!-- Change someone's e-mail. Filled in from the row's button. -->
<div class="gt-modal" id="m-email" role="dialog" aria-modal="true" aria-labelledby="em-title">
  <form class="gt-box" method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="set_email">
    <input type="hidden" name="user_id" id="emUid" value="">
    <h3 id="em-title">Change e-mail</h3>
    <p class="gt-hint" id="emFor"></p>
    <label class="gt-f">Work e-mail
      <input type="email" name="email" id="emEmail" required spellcheck="false"
             placeholder="name@<?= e($domain) ?>"
             pattern="[^@\s]+@<?= e(preg_quote($domain, '/')) ?>" title="An @<?= e($domain) ?> address">
    </label>
    <p class="gt-hint">Only @<?= e($domain) ?> addresses. Any code already sent to the old address stops working.</p>
    <div class="em-actions">
      <button type="button" class="button secondary gt-close">Cancel</button>
      <button type="submit" class="button">Save e-mail</button>
    </div>
  </form>
</div>

<?php require_once APP_DIR . '/views/modal_base.php'; ?>

<script>
(function () {
  // Fill the e-mail dialog from the row that opened it.
  document.querySelectorAll('[data-modal="#m-email"]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('emUid').value = btn.dataset.uid;
      document.getElementById('emFor').textContent = 'For ' + btn.dataset.name + '.';
      var input = document.getElementById('emEmail');
      input.value = btn.dataset.email || '';
      setTimeout(function () { input.focus(); input.select(); }, 30);
    });
  });

  // "How they get in": the password boxes only matter when setting one now.
  var block = document.getElementById('nuPwBlock');
  var radios = document.querySelectorAll('#m-newuser input[name="setup"]');
  function sync() {
    var invite = !!document.querySelector('#m-newuser input[name="setup"][value="invite"]:checked');
    block.hidden = invite;
    block.querySelectorAll('input').forEach(function (i) {
      i.disabled = invite;          // disabled fields are neither validated nor sent
    });
    var submit = document.getElementById('nuSubmit');
    if (submit) { submit.textContent = invite ? 'Create and e-mail code' : 'Create account'; }
  }
  radios.forEach(function (r) { r.addEventListener('change', sync); });
  if (block) { sync(); }

  // Say so as soon as the address is off-domain, not only when they press Create.
  var email = document.getElementById('nuEmail'), help = document.getElementById('nuEmailHelp');
  var domain = <?= json_encode('@' . $domain) ?>;
  if (email && help) {
    email.addEventListener('input', function () {
      var v = email.value.trim().toLowerCase();
      var bad = v.indexOf('@') > -1 && v.slice(-domain.length) !== domain && v.length >= domain.length;
      help.classList.toggle('bad', bad);
      help.textContent = bad ? 'That is not an ' + domain + ' address.' : 'Must be an ' + domain + ' address.';
    });
  }
})();
</script>

<script>
(function () {
  var form = document.getElementById('nuForm');
  if (!form) { return; }
  var pw = document.getElementById('pw'), pw2 = document.getElementById('pw2');
  var list = document.getElementById('pwRules'), match = document.getElementById('nuMatch');
  var name = document.getElementById('nuName'), uname = document.getElementById('nuUser');

  var tests = {
    len: function (v) { return v.length >= 8; },
    upper: function (v) { return /[A-Z]/.test(v); },
    digit: function (v) { return /[0-9]/.test(v); },
    special: function (v) { return /[^A-Za-z0-9]/.test(v); }
  };

  function check() {
    var v = pw.value, allOk = true;
    list.querySelectorAll('li').forEach(function (li) {
      var ok = tests[li.dataset.rule](v);
      li.classList.toggle('met', ok);
      if (!ok) { allOk = false; }
    });
    pw.setCustomValidity(allOk ? '' : 'The password does not meet all the rules yet.');
    pw.classList.toggle('is-good', allOk);

    var same = pw2.value === v;
    if (!pw2.value) {
      match.textContent = ''; match.className = 'nu-match';
    } else {
      match.textContent = same ? '✓ Passwords match' : 'Passwords don’t match yet';
      match.className = 'nu-match ' + (same ? 'ok' : 'bad');
    }
    pw2.classList.toggle('is-good', !!pw2.value && same);
    pw2.classList.toggle('is-bad', !!pw2.value && !same && pw2.value.length >= v.length);
    pw2.setCustomValidity(same ? '' : 'The two passwords do not match.');
  }
  pw.addEventListener('input', check);
  pw2.addEventListener('input', check);
  check();

  // A readable password, easy to read out over the phone: Word-Word-1234.
  var WORDS = ['Lamu', 'Garissa', 'Kisumu', 'Nakuru', 'Embu', 'Meru', 'Nyeri', 'Kitui', 'Voi', 'Malindi',
               'Eldoret', 'Kericho', 'Narok', 'Isiolo', 'Wajir', 'Kilifi', 'Thika', 'Machakos', 'Busia', 'Bomet'];
  function rand(n) {
    var a = new Uint32Array(1);
    (window.crypto || window.msCrypto).getRandomValues(a);
    return a[0] % n;
  }
  document.getElementById('nuGen').addEventListener('click', function () {
    var a = WORDS[rand(WORDS.length)], b;
    do { b = WORDS[rand(WORDS.length)]; } while (b === a);
    var p = a + '-' + b + '-' + String(1000 + rand(9000));
    pw.value = p; pw2.value = p;
    check();
    pw.focus(); pw.select();
  });

  var copyBtn = document.getElementById('nuCopy');
  copyBtn.addEventListener('click', function () {
    var text = copyBtn.querySelector('.nu-tool-text');
    function done() {
      copyBtn.classList.add('done'); text.textContent = 'Copied';
      setTimeout(function () { copyBtn.classList.remove('done'); text.textContent = 'Copy'; }, 1600);
    }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(pw.value).then(done, function () { pw.select(); });
    } else {
      // Plain http on the office network has no clipboard API; the old way still works there.
      pw.select();
      try { if (document.execCommand('copy')) { done(); } } catch (e) {}
    }
  });

  // Suggest a username from the name (jane.wanjiru) until someone types one themselves.
  var userTouched = uname.value !== '';
  uname.addEventListener('input', function () { userTouched = uname.value !== ''; });
  name.addEventListener('input', function () {
    if (userTouched) { return; }
    var parts = name.value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z\s'-]/g, '').replace(/['-]/g, '').trim().split(/\s+/).filter(Boolean);
    uname.value = parts.length > 1 ? parts[0] + '.' + parts[parts.length - 1] : (parts[0] || '');
  });

  form.addEventListener('submit', function (e) {
    check();
    if (!form.checkValidity()) {
      e.preventDefault();
      var bad = form.querySelector(':invalid');
      if (bad) { bad.reportValidity(); bad.focus(); }
      return;
    }
    var btn = document.getElementById('nuSubmit');
    btn.disabled = true; btn.textContent = 'Creating…';
  });

  // A create that failed would otherwise come back with the dialog shut and the
  // reason hidden behind it, which looks like nothing happened at all.
<?php if ($createErrors): ?>
  var dlg = document.getElementById('m-newuser');
  if (dlg) { dlg.classList.add('on'); }
<?php endif; ?>
})();
</script>
<?php layout_bottom(); ?>
