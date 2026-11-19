<?php


/**
 * Change your own password.
 *
 * Works signed in (from the menu) or signed out (from the login page), because it always
 * asks for the current password. Forgotten passwords go through forgot_password.php instead.
 *
 * Signed in, the account comes from the session and any posted username is ignored.
 * Signed out, the user types their username, the same one they sign in with.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/views/layout.php';
require APP_DIR . '/accounts.php';

$me = current_user();                       // null when signed out
$errors = [];
$done = false;
$username = $me['username'] ?? '';

// Signed in with a company address and mail working: prove it is you with an e-mailed code
// rather than the current password. Otherwise the current password, as before.
$otpMode = $me && mail_configured() && is_staff_email((string)($me['email'] ?? ''));
$codeSent = false;

/** Returns an error message, or null when the password is acceptable. */
if (!function_exists('password_problem')) {
    function password_problem(string $password, string $repeat): ?string
    {
        if (strlen($password) < 8) {
            return 'The new password must be at least 8 characters.';
        }
        if (strlen($password) > 72) {
            // bcrypt ignores everything after 72 bytes, so a longer one would be misleading
            return 'The new password must be at most 72 characters.';
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

if ($otpMode && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_code') {
    csrf_check();
    $why = null;
    if (otp_send($me, 'change', $why)) {
        $codeSent = true;
    } else {
        $errors[] = $why;
    }
} elseif ($otpMode && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $new = (string)($_POST['password'] ?? '');
    $repeat = (string)($_POST['password2'] ?? '');
    $fresh = q('SELECT * FROM users WHERE id = ? AND active', [(int)$me['id']])->fetch();

    if (($problem = password_problem($new, $repeat)) !== null) {
        $errors[] = $problem;
    } elseif ($fresh && password_verify($new, (string)$fresh['password_hash'])) {
        $errors[] = 'The new password must be different from the current one.';
    } else {
        $why = null;
        $codeId = otp_verify((int)$me['id'], 'change', (string)($_POST['code'] ?? ''), $why);
        if ($codeId === null) {
            $errors[] = $why;
            $codeSent = true;           // keep the code box in view
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), (int)$me['id']]);
            otp_consume($codeId, (int)$me['id']);
            audit('change_password', 'user', (int)$me['id'], ['via' => 'email_code']);
            $done = true;
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$me) {
        $username = trim((string)($_POST['username'] ?? ''));
    }
    $current = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['password'] ?? '');
    $repeat = (string)($_POST['password2'] ?? '');

    $user = $username === '' ? false
        : q('SELECT * FROM users WHERE lower(username) = lower(?) AND active', [$username])->fetch();

    if (!$user || !password_verify($current, $user['password_hash'])) {
        usleep(400000);
        $errors[] = $me ? 'Your current password is wrong.' : 'The username or current password is wrong.';
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

// Which field to put the cursor in: the username when it is missing, otherwise the current password.
$focusUser = !$me && $username === '';

layout_top('Change password', $me ? 'staff' : 'public');
if ($me) {
    layout_back('index.php', 'Back to SG. Certificates');
}
?>
<style>
  /* Everything is scoped to #cp. The id is deliberate: the shared form rules in content.css
     and style.css are very specific, and this page should not have to fight them one by one. */
  #cp {
    --cp-navy: #0D4F72; --cp-blue: #1890D0; --cp-green: #8CC63F; --cp-ok: #2E7D32;
    --cp-ink: #1C2430; --cp-body: #3D4756; --cp-muted: #5C6775;
    --cp-line: #E4EBF2; --cp-soft: #F6F9FC; --cp-bad: #C3121A;
    width: 100%; max-width: 460px; margin: 8px auto 32px;
    background: #fff; border: 1px solid var(--cp-line); border-radius: 16px;
    box-shadow: 0 1px 2px rgba(13,79,114,.05), 0 12px 32px rgba(13,79,114,.10);
    overflow: hidden; color: var(--cp-body); text-align: left;
  }
  body.plain #cp { margin: 0 auto; box-shadow: 0 20px 50px rgba(14,35,22,.38), 0 2px 6px rgba(1,65,40,.22); }

  #cp .cp-head { padding: 28px 28px 0; text-align: center; }
  #cp .cp-badge {
    width: 52px; height: 52px; margin: 0 auto 14px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg, #E8F4FB, #EEF7E3); color: var(--cp-navy);
  }
  #cp .cp-badge.ok { background: #E7F4E8; color: var(--cp-ok); }
  #cp h1 {
    margin: 0 0 6px; padding: 0; border: 0;
    font-size: 22px; font-weight: 700; letter-spacing: -.3px; line-height: 1.25;
    color: var(--cp-ink); text-align: center;
  }
  #cp .cp-sub { margin: 0; font-size: 14px; line-height: 1.5; color: var(--cp-muted); }
  #cp .cp-sub strong { color: var(--cp-navy); font-weight: 600; }

  #cp .cp-body { padding: 22px 28px 26px; }

  #cp .cp-alert {
    display: flex; gap: 10px; align-items: flex-start;
    margin: 0 0 18px; padding: 11px 14px; border-radius: 10px;
    background: #FDF1F1; border: 1px solid #F5D0D2; color: #93131A;
    font-size: 13.5px; line-height: 1.45; text-align: left;
  }
  #cp .cp-alert svg { flex: none; margin-top: 1px; }

  #cp .cp-form { margin: 0; padding: 0; background: none; border: 0; box-shadow: none; width: auto; max-width: none; }
  #cp .cp-field { margin: 0 0 16px; }
  #cp .cp-label {
    display: block; margin: 0 0 6px; padding: 0;
    font-size: 13px; font-weight: 600; letter-spacing: 0; text-transform: none;
    color: var(--cp-ink);
  }
  #cp .cp-input {
    display: block; width: 100%; box-sizing: border-box; margin: 0;
    padding: 11px 13px; font: inherit; font-size: 15px; line-height: 1.4;
    color: var(--cp-ink); background: var(--cp-soft);
    border: 1px solid var(--cp-line); border-radius: 10px;
    transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
  }
  #cp .pw-wrap { margin-top: 0; }   /* the show/hide eye wrapper from layout.php */
  #cp .cp-input:hover { border-color: #CBDCEA; }
  #cp .cp-input:focus {
    outline: none; background: #fff; border-color: var(--cp-blue);
    box-shadow: 0 0 0 3px rgba(24,144,208,.15);
  }
  #cp .cp-input.is-bad { border-color: #E5A3A7; }
  #cp .cp-input.is-good { border-color: #A9D18E; }

  #cp .cp-section {
    display: flex; align-items: center; gap: 10px;
    margin: 22px 0 14px; font-size: 11.5px; font-weight: 700;
    letter-spacing: .6px; text-transform: uppercase; color: var(--cp-muted);
  }
  #cp .cp-section::after { content: ""; flex: 1; height: 1px; background: var(--cp-line); }

  #cp .cp-caps {
    display: none; margin: 6px 0 0; font-size: 12.5px; color: #8A5A00;
  }
  #cp .cp-caps.on { display: block; }

  /* strength meter */
  #cp .cp-meter { display: flex; gap: 5px; margin: 10px 0 6px; }
  #cp .cp-meter span { flex: 1; height: 4px; border-radius: 2px; background: var(--cp-line); transition: background .2s ease; }
  #cp .cp-meter[data-score="1"] span:nth-child(-n+1) { background: #E5484D; }
  #cp .cp-meter[data-score="2"] span:nth-child(-n+2) { background: #F0A030; }
  #cp .cp-meter[data-score="3"] span:nth-child(-n+3) { background: var(--cp-blue); }
  #cp .cp-meter[data-score="4"] span:nth-child(-n+4) { background: var(--cp-green); }
  #cp .cp-strength { margin: 0 0 10px; font-size: 12.5px; color: var(--cp-muted); min-height: 1.2em; }
  #cp .cp-strength b { color: var(--cp-ink); font-weight: 600; }

  /* rule checklist */
  #cp .cp-rules {
    list-style: none; margin: 0; padding: 12px 14px;
    display: grid; grid-template-columns: 1fr 1fr; gap: 7px 14px;
    background: var(--cp-soft); border-radius: 10px;
    font-size: 12.5px; line-height: 1.35; color: var(--cp-muted);
  }
  @media (max-width: 420px) { #cp .cp-rules { grid-template-columns: 1fr; } }
  #cp .cp-rules li { display: flex; align-items: center; gap: 8px; padding: 0; transition: color .15s ease; }
  #cp .cp-rules li::before {
    content: ""; flex: none; width: 15px; height: 15px; box-sizing: border-box;
    border: 1.5px solid #B6C2CF; border-radius: 50%;
    transition: background .15s ease, border-color .15s ease;
  }
  #cp .cp-rules li.met { color: var(--cp-ok); }
  #cp .cp-rules li.met::before {
    border-color: var(--cp-ok);
    background: var(--cp-ok) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath d='M4 8.2l2.6 2.5L12 5.4' fill='none' stroke='%23fff' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") center/11px no-repeat;
  }

  #cp .cp-code-row { display: flex; gap: 8px; }
  #cp .cp-code { flex: 1 1 auto; min-width: 0; font-size: 20px; font-weight: 700; letter-spacing: 6px;
                 text-align: center; font-variant-numeric: tabular-nums; }
  #cp .cp-send { flex: none; padding: 0 14px; border: 1px solid #CBDCEA; border-radius: 10px; background: #fff;
                 color: var(--cp-navy); font: inherit; font-size: 13.5px; font-weight: 600; cursor: pointer; white-space: nowrap; }
  #cp .cp-send:hover { background: #F2F7FB; }
  #cp .cp-hint { margin: 6px 0 0; font-size: 12.5px; color: var(--cp-muted); }
  #cp .cp-hint strong { color: var(--cp-ink); font-weight: 600; }
  #cp .cp-match { margin: 6px 0 0; font-size: 12.5px; min-height: 1.2em; }
  #cp .cp-match.ok { color: var(--cp-ok); }
  #cp .cp-match.bad { color: var(--cp-bad); }

  #cp .cp-submit {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    width: 100%; margin: 22px 0 0; padding: 13px 18px;
    background: var(--cp-navy); color: #fff; border: 0; border-radius: 10px;
    font: inherit; font-size: 15px; font-weight: 600; cursor: pointer;
    transition: background .15s ease, box-shadow .15s ease, transform .06s ease;
  }
  #cp .cp-submit:hover { background: #0A4260; box-shadow: 0 4px 12px rgba(13,79,114,.25); }
  #cp .cp-submit:active { transform: translateY(1px); }
  #cp .cp-submit:focus-visible { outline: 3px solid rgba(24,144,208,.35); outline-offset: 2px; }
  #cp .cp-submit[disabled] { opacity: .75; cursor: progress; box-shadow: none; }
  #cp .cp-spin {
    display: none; width: 15px; height: 15px; border-radius: 50%;
    border: 2px solid rgba(255,255,255,.35); border-top-color: #fff;
    animation: cp-spin .7s linear infinite;
  }
  #cp .cp-submit[disabled] .cp-spin { display: inline-block; }
  @keyframes cp-spin { to { transform: rotate(360deg); } }

  #cp .cp-foot {
    display: flex; justify-content: center; flex-wrap: wrap; gap: 6px 18px;
    padding: 14px 28px; border-top: 1px solid var(--cp-line); background: var(--cp-soft);
    font-size: 13.5px;
  }
  #cp .cp-foot a { color: var(--cp-navy); text-decoration: none; font-weight: 500; }
  #cp .cp-foot a:hover { text-decoration: underline; text-underline-offset: 3px; }

  #cp .cp-done-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 100%; box-sizing: border-box; margin-top: 4px; padding: 13px 18px;
    background: var(--cp-navy); color: #fff; border-radius: 10px;
    font-size: 15px; font-weight: 600; text-decoration: none;
  }
  #cp .cp-done-btn:hover { background: #0A4260; }

  @media (max-width: 480px) {
    #cp .cp-head { padding: 24px 20px 0; }
    #cp .cp-body { padding: 20px 20px 22px; }
    #cp .cp-foot { padding: 14px 20px; }
  }
  @media (prefers-reduced-motion: reduce) {
    #cp *, #cp *::before { transition: none !important; animation: none !important; }
  }
</style>

<section id="cp" aria-labelledby="cp-title">

<?php if ($done): ?>
  <div class="cp-head">
    <div class="cp-badge ok" aria-hidden="true">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
           stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.2 4L19 7"/></svg>
    </div>
    <h1 id="cp-title">Password changed</h1>
    <p class="cp-sub">
      <?= $me ? 'Use your new password the next time you sign in.'
              : 'You can now sign in with your new password.' ?>
    </p>
  </div>
  <div class="cp-body">
    <a class="cp-done-btn" href="<?= $me ? 'index.php' : 'login.php' ?>">
      <?= $me ? 'Back to certificates' : 'Go to sign in' ?>
    </a>
  </div>

<?php else: ?>
  <div class="cp-head">
    <div class="cp-badge" aria-hidden="true">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
           stroke-linecap="round" stroke-linejoin="round">
        <rect x="4.5" y="10.5" width="15" height="10" rx="2.2"/>
        <path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/><circle cx="12" cy="15.5" r="1.3"/>
      </svg>
    </div>
    <h1 id="cp-title">Change your password</h1>
    <p class="cp-sub">
      <?php if ($me): ?>
        Signed in as <strong><?= e($me['username']) ?></strong>
      <?php else: ?>
        Confirm who you are, then choose a new password.
      <?php endif; ?>
    </p>
  </div>

  <div class="cp-body">
    <?php foreach ($errors as $msg): ?>
      <div class="cp-alert" role="alert">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><path d="M12 7.5v5.5M12 16.5v.01"/></svg>
        <span><?= e($msg) ?></span>
      </div>
    <?php endforeach; ?>

    <form method="post" class="cp-form" id="cpForm" novalidate>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <?php if ($me): ?>
        <?php /* Not shown, but lets password managers file the new password under the right account. */ ?>
        <input type="text" name="username" value="<?= e($me['username']) ?>" autocomplete="username"
               readonly tabindex="-1" aria-hidden="true" style="display:none">
      <?php else: ?>
        <div class="cp-field">
          <label class="cp-label" for="cpUser">Username</label>
          <input class="cp-input" id="cpUser" name="username" value="<?= e($username) ?>" required
                 autocomplete="username" autocapitalize="none" spellcheck="false"
                 <?= $focusUser ? 'autofocus' : '' ?>>
        </div>
      <?php endif; ?>

      <?php if ($otpMode): ?>
      <div class="cp-field">
        <label class="cp-label" for="cpCode">Code from your e-mail</label>
        <div class="cp-code-row">
          <input class="cp-input cp-code" id="cpCode" name="code" required inputmode="numeric"
                 autocomplete="one-time-code" pattern="\d{3}\s?\d{3}" maxlength="7" placeholder="••••••"
                 <?= $codeSent ? 'autofocus' : '' ?>>
          <button type="submit" form="cpSend" class="cp-send"><?= $codeSent ? 'Resend' : 'Email me a code' ?></button>
        </div>
        <p class="cp-hint"><?= $codeSent
            ? 'Sent to <strong>' . e(mask_email((string)$me['email'])) . '</strong>. It works for 10 minutes.'
            : 'We will send a 6-digit code to <strong>' . e(mask_email((string)$me['email'])) . '</strong>.' ?></p>
      </div>
      <?php else: ?>
      <div class="cp-field">
        <label class="cp-label" for="cpCurrent">Current password</label>
        <input class="cp-input" id="cpCurrent" name="current" type="password" required
               autocomplete="current-password" <?= $focusUser ? '' : 'autofocus' ?>>
        <p class="cp-caps" aria-live="polite">Caps Lock is on.</p>
      </div>
      <?php endif; ?>

      <div class="cp-section">New password</div>

      <div class="cp-field">
        <label class="cp-label" for="pw">New password</label>
        <input class="cp-input" id="pw" name="password" type="password" required
               minlength="8" maxlength="72" autocomplete="new-password" aria-describedby="pwRules cpStrength">
        <p class="cp-caps" aria-live="polite">Caps Lock is on.</p>
        <div class="cp-meter" id="cpMeter" data-score="0" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
        <p class="cp-strength" id="cpStrength" aria-live="polite"></p>
        <ul class="cp-rules" id="pwRules">
          <li data-rule="len">8 or more characters</li>
          <li data-rule="upper">A capital letter</li>
          <li data-rule="digit">A number</li>
          <li data-rule="special">A symbol (! @ # $ &amp; …)</li>
        </ul>
      </div>

      <div class="cp-field">
        <label class="cp-label" for="pw2">Confirm new password</label>
        <input class="cp-input" id="pw2" name="password2" type="password" required
               maxlength="72" autocomplete="new-password" aria-describedby="cpMatch">
        <p class="cp-match" id="cpMatch" aria-live="polite"></p>
      </div>

      <button type="submit" class="cp-submit" id="cpSubmit">
        <span class="cp-spin" aria-hidden="true"></span>
        <span class="cp-submit-text">Change password</span>
      </button>
    </form>
    <?php if ($otpMode): ?>
      <form method="post" id="cpSend" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="send_code">
      </form>
    <?php endif; ?>
  </div>

  <div class="cp-foot">
    <a href="forgot_password.php">Forgot your password?</a>
    <?php if (!$me): ?><a href="login.php">&larr; Back to sign in</a><?php endif; ?>
  </div>
<?php endif; ?>

</section>

<script>
(function () {
  var form = document.getElementById('cpForm');
  if (!form) { return; }
  var pw = document.getElementById('pw'), pw2 = document.getElementById('pw2');
  var current = document.getElementById('cpCurrent');
  var rules = document.getElementById('pwRules');
  var meter = document.getElementById('cpMeter'), strength = document.getElementById('cpStrength');
  var match = document.getElementById('cpMatch');
  var submit = document.getElementById('cpSubmit');

  var tests = {
    len: function (v) { return v.length >= 8; },
    upper: function (v) { return /[A-Z]/.test(v); },
    digit: function (v) { return /[0-9]/.test(v); },
    special: function (v) { return /[^A-Za-z0-9]/.test(v); }
  };
  var LABELS = ['', 'Weak', 'Fair', 'Good', 'Strong'];

  function rulesMet(v) {
    var n = 0;
    rules.querySelectorAll('li').forEach(function (li) {
      var ok = tests[li.dataset.rule](v);
      li.classList.toggle('met', ok);
      if (ok) { n++; }
    });
    return n;
  }

  function update() {
    var v = pw.value, met = rulesMet(v), all = met === 4;

    // Strength: the rules get you to "Good"; length beyond the minimum is what makes it "Strong".
    var score = v.length === 0 ? 0 : !all ? Math.min(met, 2) || 1 : v.length >= 12 ? 4 : 3;
    meter.dataset.score = score;
    strength.innerHTML = score ? 'Strength: <b>' + LABELS[score] + '</b>'
      + (score === 3 ? ' &middot; 12+ characters makes it stronger' : '') : '';

    var same = current && v.length > 0 && v === current.value;
    pw.setCustomValidity(!all ? 'The password does not meet all the rules yet.'
      : same ? 'The new password must be different from the current one.' : '');
    pw.classList.toggle('is-good', all && !same);

    if (pw2.value.length === 0) {
      match.textContent = ''; match.className = 'cp-match';
      pw2.classList.remove('is-good', 'is-bad');
    } else {
      var ok = pw2.value === v;
      match.textContent = ok ? '✓ Passwords match' : 'Passwords don’t match yet';
      match.className = 'cp-match ' + (ok ? 'ok' : 'bad');
      pw2.classList.toggle('is-good', ok);
      pw2.classList.toggle('is-bad', !ok && pw2.value.length >= v.length);
    }
    pw2.setCustomValidity(pw2.value === v ? '' : 'The two new passwords do not match.');
  }

  [pw, pw2, current].forEach(function (el) { if (el) { el.addEventListener('input', update); } });
  update();

  // Caps Lock warning, one per password field.
  form.querySelectorAll('input[type="password"]').forEach(function (input) {
    var note = input.closest('.cp-field').querySelector('.cp-caps');
    if (!note) { return; }
    function caps(e) { if (e.getModifierState) { note.classList.toggle('on', e.getModifierState('CapsLock')); } }
    input.addEventListener('keydown', caps);
    input.addEventListener('keyup', caps);
    input.addEventListener('blur', function () { note.classList.remove('on'); });
  });

  form.addEventListener('submit', function (e) {
    update();
    if (!form.checkValidity()) {
      e.preventDefault();
      var bad = form.querySelector(':invalid');
      if (bad) { bad.reportValidity(); bad.focus(); }
      return;
    }
    // Stop double submits while the server checks the password (it waits on a wrong one).
    submit.disabled = true;
    submit.querySelector('.cp-submit-text').textContent = 'Changing password…';
  });
})();
</script>
<?php layout_bottom(); ?>
