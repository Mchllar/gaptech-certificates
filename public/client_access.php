<?php
/** Give a client a login for the download portal. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/views/layout.php';

require_admin();

$clientId = (int)($_GET['client_id'] ?? 0);
$client   = $clientId ? q('SELECT * FROM clients WHERE id = ?', [$clientId])->fetch() : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $postedId = (int)($_POST['client_id'] ?? 0);
    $client   = q('SELECT * FROM clients WHERE id = ?', [$postedId])->fetch();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!$client) {
        flash('Client not found.');
    } elseif ($username === '') {
        flash('A username is required.');
    } elseif (q('SELECT 1 FROM clients WHERE lower(username) = lower(?) AND id <> ?',
                [$username, $client['id']])->fetch()) {
        flash('That username is already used by another client.');
    } elseif (strlen($password) < 8) {
        flash('The password must be at least 8 characters.');
    } else {
        // One-time: the client must replace it with their own at the next sign-in, so the
        // password staff read out never stays in use. Client e-mails are not collected.
        q('UPDATE clients SET username = ?, password_hash = ?, must_change_password = true WHERE id = ?',
            [$username, password_hash($password, PASSWORD_DEFAULT), $client['id']]);
        audit('set_client_access', 'client', (int)$client['id'], ['username' => $username, 'one_time' => true]);
        flash('Login saved for ' . $client['name'] . '. Give them the username and password by phone or in person. '
            . 'The password works once: they choose their own when they sign in.');
    }
    redirect('client_access.php?client_id=' . $postedId);
}

$clients = q('SELECT id, name, username, must_change_password, password_hash IS NOT NULL AS has_login
              FROM clients ORDER BY name')->fetchAll();

/** A readable suggestion, e.g. SASERE ENTERPRISES LTD -> sasere */
$suggestedUser = '';
if ($client) {
    $suggestedUser = $client['username'] ?: strtolower(preg_replace('/[^a-z0-9]+/i', '',
        strtok((string)$client['name'], ' ')));
}
$suggestedPass = 'Gap@' . random_int(100000, 999999);

// The credentials table is paged on its own, so its parameters are prefixed with l.
$withLogin = array_values(array_filter($clients, fn($r) => !empty($r['has_login'])));

$lper = (int)($_GET['lper'] ?? 10);
if (!in_array($lper, [10, 25, 50, 100], true)) {
    $lper = 10;
}
$ltotal    = count($withLogin);
$lpages    = max(1, (int)ceil($ltotal / $lper));
$lpage     = min(max(1, (int)($_GET['lpage'] ?? 1)), $lpages);
$loffset   = ($lpage - 1) * $lper;
$loginRows = array_slice($withLogin, $loffset, $lper);

/** A link into the credentials table, keeping whatever else is in the URL. */
function login_link(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    return 'client_access.php?' . http_build_query(array_filter($params,
        fn($v) => $v !== '' && $v !== null)) . '#logins';
}

// A client in the URL means one was picked, or a save has just come back with a message.
// Only reopen when a save came back with something to fix.
$openCreds = !empty($_GET['open']);

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

</style>
<div class="vfy">
<h1 class="vfy-title">SG. Client's Portal Access</h1>
<p class="vfy-sub">Assign or change the username and password clients use to download their own SG. certificates.</p>

<!-- <h2 id="logins">Clients with credentials</h2> -->

<table>
  <tr><th>Index</th><th>Client</th><th>Username</th><th>Password</th><th></th></tr>
  <?php foreach ($loginRows as $i => $row): ?>
    <tr>
      <td><?= $loffset + $i + 1 ?></td>
      <td><?= e($row['name']) ?></td>
      <td><?= e($row['username']) ?></td>
      <td><?= !empty($row['must_change_password']) ? 'Waiting for first sign-in' : 'Set by client' ?></td>
      <td><button type="button" class="link-button creds-edit"
                  data-client-id="<?= (int)$row['id'] ?>">Change</button></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$loginRows): ?>
    <tr><td colspan="5">No client has portal credentials yet.</td></tr>
  <?php endif; ?>
</table>

<div class="pager">
  <form method="get" class="per-page" action="client_access.php">
    <?php foreach ($_GET as $k => $v): ?>
      <?php if ($k !== 'lper' && $k !== 'lpage'): ?>
        <input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>">
      <?php endif; ?>
    <?php endforeach; ?>
    <label>Rows per page
      <select name="lper" onchange="this.form.submit()">
        <?php foreach ([10, 25, 50, 100] as $n): ?>
          <option value="<?= $n ?>" <?= $lper === $n ? 'selected' : '' ?>><?= $n ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>

  <span class="pager-info">
    <?= $ltotal ? ($loffset + 1) . '&ndash;' . min($loffset + $lper, $ltotal) : 0 ?> of <?= $ltotal ?>
  </span>

  <span class="pager-buttons">
    <?php if ($lpage > 1): ?>
      <a class="button secondary" href="<?= e(login_link(['lpage' => 1])) ?>">&laquo; First</a>
      <a class="button secondary" href="<?= e(login_link(['lpage' => $lpage - 1])) ?>">Previous</a>
    <?php endif; ?>
    <span class="pager-page">Page <?= $lpage ?> of <?= $lpages ?></span>
    <?php if ($lpage < $lpages): ?>
      <a class="button secondary" href="<?= e(login_link(['lpage' => $lpage + 1])) ?>">Next</a>
      <a class="button secondary" href="<?= e(login_link(['lpage' => $lpages])) ?>">Last &raquo;</a>
    <?php endif; ?>
  </span>
</div>


<div class="actions">
  <button type="button" class="button" data-modal="#m-creds" id="creds-open">Assign Credentials</button>
</div>
</div>

<?php require_once APP_DIR . '/views/dialog_styles.php'; ?>
<?= nu_dialog_css('#m-creds') ?>
<style>
  #m-creds .gt-box { width: min(520px, calc(100vw - 32px)); }
</style>

<!-- ------------------------------------------------------------------ -->
<!-- Client portal login. Same dialog as New staff account.             -->
<!-- ------------------------------------------------------------------ -->
<div class="gt-modal" id="m-creds" role="dialog" aria-modal="true" aria-labelledby="cr-title">
  <form class="gt-box" method="post" id="crForm" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="nu-head">
      <div class="nu-badge" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
             stroke-linecap="round" stroke-linejoin="round">
          <circle cx="8" cy="15" r="4"/><path d="M10.8 12.2L20 3M17 6l3 3M14.5 8.5l2 2"/>
        </svg>
      </div>
      <div>
        <h3 id="cr-title">Client portal login</h3>
        <p>A username and one-time password for a client to download their certificates.</p>
      </div>
      <button type="button" class="nu-x gt-close" aria-label="Close">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
    </div>

    <div class="nu-body">
      <div class="nu-section">Client</div>

      <div class="nu-field">
        <label class="nu-label" for="crClient">Client <span class="req">*</span></label>
        <select class="nu-input" id="crClient" name="client_id" required>
          <option value="">Choose a client</option>
          <?php foreach ($clients as $row):
              $suggest = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string)strtok((string)$row['name'], ' '))); ?>
            <option value="<?= (int)$row['id'] ?>"
                    data-username="<?= e((string)($row['username'] ?? '')) ?>" data-suggest="<?= e($suggest) ?>"
                    <?= $client && (int)$client['id'] === (int)$row['id'] ? 'selected' : '' ?>>
              <?= e($row['name']) ?><?= $row['has_login'] ? ' (' . e($row['username']) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="nu-help" id="crState" aria-live="polite"></p>
      </div>

      <div class="nu-section">Sign-in</div>

      <div class="nu-field">
        <label class="nu-label" for="crUser">Username <span class="req">*</span></label>
        <input class="nu-input nu-mono" id="crUser" name="username" required autocapitalize="none"
               spellcheck="false" autocomplete="off" value="<?= e($suggestedUser) ?>" placeholder="e.g. sasere">
      </div>

      <div class="nu-field">
        <label class="nu-label" for="crPw">One-time password <span class="req">*</span></label>
        <div class="nu-pw">
          <input class="nu-input nu-mono" id="crPw" name="password" type="text" required minlength="8" maxlength="72"
                 autocomplete="off" spellcheck="false" value="<?= e($suggestedPass) ?>">
          <div class="nu-tools">
            <button type="button" class="nu-tool" id="crGen" title="Generate a new password">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                   stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M20 11a8 8 0 0 0-14.3-4.9L4 8M4 4v4h4M4 13a8 8 0 0 0 14.3 4.9L20 16M20 20v-4h-4"/></svg>
              <span class="nu-tool-text">New</span>
            </button>
            <button type="button" class="nu-tool" id="crCopy" title="Copy the password">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                   stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="8.5" y="8.5" width="12" height="12" rx="2"/><path d="M15.5 8.5V5.5a2 2 0 0 0-2-2h-8a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h3"/></svg>
              <span class="nu-tool-text">Copy</span>
            </button>
          </div>
        </div>
      </div>

      <div class="nu-note">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><path d="M12 11v5.5M12 7.5v.01"/></svg>
        <span>Give it to the client by phone or in person. It works for <b>one sign-in</b>: they then
          choose their own. Setting it again replaces the old one, which is also how you help a client
          who has forgotten theirs.</span>
      </div>
    </div>

    <div class="nu-foot">
      <button type="button" class="button secondary gt-close">Cancel</button>
      <button type="submit" class="button" id="crSubmit">Save login</button>
    </div>
  </form>
</div>

<?php require_once APP_DIR . '/views/modal_base.php'; ?>

<script>
(function () {
  var dlg = document.getElementById('m-creds');
  if (!dlg) { return; }
  var form = document.getElementById('crForm');
  var sel = document.getElementById('crClient'), user = document.getElementById('crUser');
  var pw = document.getElementById('crPw'), state = document.getElementById('crState');

  // Picking a client fills in their username (or a suggestion) and says what saving will do.
  function fillFromClient() {
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) { state.textContent = ''; return; }
    user.value = opt.dataset.username || opt.dataset.suggest || '';
    state.textContent = opt.dataset.username
      ? 'Already has a login. Saving gives them a new one-time password.'
      : 'No login yet.';
  }
  sel.addEventListener('change', fillFromClient);

  // "Change" on a row opens the dialog with that client chosen.
  document.querySelectorAll('.creds-edit').forEach(function (btn) {
    btn.addEventListener('click', function () {
      sel.value = btn.dataset.clientId;
      fillFromClient();
      dlg.classList.add('on');
      setTimeout(function () { pw.focus(); pw.select(); }, 30);
    });
  });
  document.getElementById('creds-open').addEventListener('click', function () {
    setTimeout(function () { sel.focus(); }, 30);
  });

  // A readable password, easy to read out over the phone: Word-Word-1234.
  var WORDS = ['Lamu', 'Garissa', 'Kisumu', 'Nakuru', 'Embu', 'Meru', 'Nyeri', 'Kitui', 'Voi', 'Malindi',
               'Eldoret', 'Kericho', 'Narok', 'Isiolo', 'Wajir', 'Kilifi', 'Thika', 'Machakos', 'Busia', 'Bomet'];
  function rand(n) { var a = new Uint32Array(1); window.crypto.getRandomValues(a); return a[0] % n; }
  document.getElementById('crGen').addEventListener('click', function () {
    var a = WORDS[rand(WORDS.length)], b;
    do { b = WORDS[rand(WORDS.length)]; } while (b === a);
    pw.value = a + '-' + b + '-' + String(1000 + rand(9000));
    pw.focus(); pw.select();
  });

  var copyBtn = document.getElementById('crCopy');
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

  form.addEventListener('submit', function (e) {
    if (!form.checkValidity()) {
      e.preventDefault();
      var bad = form.querySelector(':invalid');
      if (bad) { bad.reportValidity(); bad.focus(); }
      return;
    }
    var btn = document.getElementById('crSubmit');
    btn.disabled = true; btn.textContent = 'Saving…';
  });

  fillFromClient();
<?php if ($openCreds): ?>
  dlg.classList.add('on');
<?php endif; ?>
})();
</script>
<?php layout_bottom(); ?>
