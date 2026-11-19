<?php
/**
 * Reset a forgotten password, or set up a new account, with a code sent by e-mail.
 *
 *   Step 1  Username or work e-mail  ->  a 6-digit code is e-mailed (company domain only).
 *   Step 2  Code + new password      ->  saved; every other code and reset link dies.
 *
 * New accounts arrive with ?setup=1&u=<email> from the invitation e-mail and start at step 2.
 *
 * The page never says whether an account exists: step 1 always moves on to step 2 with
 * the same wording, and a code for an account that does not exist is simply "not valid".
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/accounts.php';
require APP_DIR . '/views/layout.php';
require APP_DIR . '/views/entry.php';

start_session();

/** The flow in progress: which account (0 = no match), what for, and where the code went. */
$flow = $_SESSION['otp_flow'] ?? null;
if (!is_array($flow) || (int)($flow['at'] ?? 0) < time() - 3 * 3600) {
    $flow = null;
}

$errors = [];
$notice = '';
$done   = false;

// Arriving from an invitation e-mail: go straight to entering the code.
if (isset($_GET['setup']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $u = find_staff_login((string)($_GET['u'] ?? ''));
    $flow = ['uid' => $u ? (int)$u['id'] : 0, 'purpose' => 'setup',
             'at' => time()];
    $_SESSION['otp_flow'] = $flow;
}
if (isset($_GET['restart'])) {
    unset($_SESSION['otp_flow']);
    redirect('forgot_password.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'request' || ($action === 'resend' && $flow)) {
        if ($action === 'request') {
            $login = trim((string)($_POST['login'] ?? ''));
            $u = find_staff_login($login);
            $flow = ['uid' => $u ? (int)$u['id'] : 0, 'purpose' => 'reset',
                     'at' => time()];
        } else {
            $u = $flow['uid'] ? q('SELECT * FROM users WHERE id = ? AND active', [$flow['uid']])->fetch() : null;
        }

        $why = null;
        if ($u && otp_send($u, $flow['purpose'], $why)) {
            $notice = 'sent';
        } elseif ($u && $why !== null && !str_starts_with($why, 'Codes are only sent')) {
            // Real trouble the person can act on (mail down, too many requests).
            // "No company address" stays hidden, so it cannot be used to probe accounts.
            $errors[] = $why;
        } else {
            usleep(random_int(250000, 600000));      // same feel as a real send
            $notice = 'sent';
        }
        $flow['at'] = time();
        $_SESSION['otp_flow'] = $flow;
    }

    if ($action === 'verify' && $flow) {
        $code = (string)($_POST['code'] ?? '');
        $new  = (string)($_POST['password'] ?? '');
        $rep  = (string)($_POST['password2'] ?? '');

        if (($problem = account_password_problem($new, $rep)) !== null) {
            $errors[] = $problem;
        } else {
            $why = null;
            $codeId = otp_verify((int)$flow['uid'], (string)$flow['purpose'], $code, $why);
            if ($codeId === null) {
                $errors[] = $why;
            } else {
                $pdo = db();
                $pdo->beginTransaction();
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $flow['uid']]);
                otp_consume($codeId, (int)$flow['uid']);
                $pdo->commit();
                audit($flow['purpose'] === 'setup' ? 'account_setup_completed' : 'password_reset_completed',
                      'user', (int)$flow['uid'], ['via' => 'email_code']);
                $doneFor = $flow['purpose'];
                unset($_SESSION['otp_flow']);
                $flow = null;
                $done = true;
            }
        }
    }
}

$isSetup = ($flow['purpose'] ?? '') === 'setup' || (($doneFor ?? '') === 'setup');
$step = $done ? 'done' : ($flow ? 'code' : 'ask');
$mailOn = mail_configured();

layout_top($isSetup ? 'Set up your account' : 'Reset password', 'public');
echo entry_styles();
?>
<style>
  #wl .wl-code input { font-size: 24px; font-weight: 700; letter-spacing: 10px; text-align: center;
                       font-variant-numeric: tabular-nums; padding: 12px 10px; }
  #wl .wl-code input::placeholder { letter-spacing: 10px; color: #C9D3DE; }
  #wl .wl-rules { list-style: none; margin: 10px 0 0; padding: 10px 12px; border-radius: 10px; background: #F6F9FC;
                  display: grid; grid-template-columns: 1fr 1fr; gap: 6px 12px; font-size: 12.5px; color: #5C6775; }
  #wl .wl-rules li { display: flex; align-items: center; gap: 7px; }
  #wl .wl-rules li::before { content: ""; width: 13px; height: 13px; border-radius: 50%; border: 1.5px solid #B6C2CF; flex: none; }
  #wl .wl-rules li.met { color: #2E7D32; }
  #wl .wl-rules li.met::before { border-color: #2E7D32; background: #2E7D32; box-shadow: inset 0 0 0 2px #fff; }
  #wl .wl-match { margin: 6px 0 0; min-height: 1.2em; font-size: 12.5px; }
  #wl .wl-match.ok { color: #2E7D32; } #wl .wl-match.bad { color: #C3121A; }
  #wl .wl-resend { display: flex; justify-content: center; gap: 6px; flex-wrap: wrap; margin: 14px 0 0;
                   font-size: 13px; color: #5C6775; }
  #wl .wl-resend form { margin: 0; display: inline; }
  #wl .wl-resend button { all: unset; cursor: pointer; color: #0D4F72; font-weight: 600;
                          border-bottom: 1.5px solid rgba(13,79,114,.25); }
  #wl .wl-resend button:hover { border-bottom-color: #0D4F72; }
  #wl .wl-resend button:focus-visible { outline: 3px solid rgba(27,154,214,.45); outline-offset: 3px; }
  #wl .wl-done { width: 56px; height: 56px; margin: 0 auto 12px; border-radius: 50%; display: grid; place-items: center;
                 background: #E7F4E8; color: #2E7D32; }
  #wl .wl-done svg { width: 28px; height: 28px; }
  @media (max-width: 400px) { #wl .wl-rules { grid-template-columns: 1fr; } }
</style>

<div id="wl">
  <section class="wl-card" aria-labelledby="wl-title">
    <?= entry_logo() ?>

<?php if ($step === 'done'): ?>
    <header class="wl-head">
      <div class="wl-done"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.2 4L19 7"/></svg></div>
      <h1 id="wl-title"><?= $isSetup ? 'Your account is ready' : 'Password changed' ?></h1>
      <p>Sign in with your username or work e-mail and your new password.</p>
    </header>
    <a class="wl-btn" href="login.php">Go to sign in</a>

<?php elseif ($step === 'ask'): ?>
    <header class="wl-head">
      <p class="wl-eyebrow"><?= entry_icon('shield') ?>Staff</p>
      <h1 id="wl-title">Reset your password</h1>
      <p>We'll send a 6-digit code to your email.</p>
    </header>

    <?php foreach ($errors as $m): ?><?= entry_alert('error', $m) ?><?php endforeach; ?>
    <?php if (!$mailOn): ?>
      <?= entry_alert('error', 'E-mail is not set up on this server yet, so codes cannot be sent. Ask an administrator to reset your password from Staff Accounts.') ?>
    <?php endif; ?>

    <form method="post" class="wl-form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="request">
      <label class="wl-field">
        <span>Username or work e-mail</span>
        <input name="login" required autofocus autocapitalize="none" autocorrect="off" spellcheck="false"
               autocomplete="username" placeholder="name@<?= e(staff_email_domain()) ?>">
      </label>
      <button type="submit" class="wl-btn" data-busy="Sending code…" data-idle="Email me a code"<?= $mailOn ? '' : ' disabled' ?>>
        <span class="wl-spin" aria-hidden="true"></span><span class="wl-btn-label">Email me a code</span>
      </button>
    </form>

    <p class="wl-foot"><span>No @<?= e(staff_email_domain()) ?> address on your account? Ask an administrator to add it in Staff Accounts.</span></p>

<?php else: /* code */ ?>
    <header class="wl-head">
      <p class="wl-eyebrow"><?= entry_icon('shield') ?><?= $isSetup ? 'New account' : 'Staff' ?></p>
      <h1 id="wl-title"><?= $isSetup ? 'Set up your account' : 'Enter your code' ?></h1>
      <p>
        <?php if ($isSetup): ?>
          Enter the setup code from your invitation e-mail, then choose your password.
        <?php else: /* never the address itself: that would confirm the account exists */ ?>
          If an account matches, a 6-digit code is on its way to your email. It works for 10 minutes.
        <?php endif; ?>
      </p>
    </header>

    <?php foreach ($errors as $m): ?><?= entry_alert('error', $m) ?><?php endforeach; ?>
    <?php if ($notice === 'sent' && !$errors && $isSetup): ?>
      <?= entry_alert('info', 'A new setup code has been sent. Only the newest code works.') ?>
    <?php endif; ?>

    <form method="post" class="wl-form" id="otpForm">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="verify">

      <label class="wl-field wl-code">
        <span><?= $isSetup ? 'Setup code' : 'Verification code' ?></span>
        <input name="code" required inputmode="numeric" autocomplete="one-time-code" pattern="\d{3}\s?\d{3}"
               maxlength="7" placeholder="••••••" autofocus>
      </label>

      <div class="wl-field">
        <span><label for="pw"><?= $isSetup ? 'Choose a password' : 'New password' ?></label></span>
        <input id="pw" name="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password">
        <p class="wl-caps" aria-live="polite">Caps Lock is on.</p>
        <ul class="wl-rules" id="pwRules">
          <li data-rule="len">8 or more characters</li>
          <li data-rule="upper">A capital letter</li>
          <li data-rule="digit">A number</li>
          <li data-rule="special">A symbol (! @ # $ …)</li>
        </ul>
      </div>

      <div class="wl-field">
        <span><label for="pw2">Repeat the password</label></span>
        <input id="pw2" name="password2" type="password" required maxlength="72" autocomplete="new-password">
        <p class="wl-match" id="pwMatch" aria-live="polite"></p>
      </div>

      <button type="submit" class="wl-btn" data-busy="Saving…" data-idle="Save password">
        <span class="wl-spin" aria-hidden="true"></span><span class="wl-btn-label">Save password</span>
      </button>
    </form>

    <div class="wl-resend">
      <span>No code?</span>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="resend">
        <button type="submit">Send a new one</button>
      </form>
      <span class="wl-sep">&middot;</span>
      <a class="wl-link" href="forgot_password.php?restart=1">Use a different account</a>
    </div>

    <p class="wl-foot"><span>Check your spam folder. Codes come from <?= e((string)($config['smtp']['from'] ?? 'the office')) ?>.</span></p>
<?php endif; ?>
  </section>

  <?= entry_back('login.php', 'Back to sign in') ?>
</div>

<script>
(function () {
  var pw = document.getElementById('pw'), pw2 = document.getElementById('pw2');
  if (!pw) { return; }
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
