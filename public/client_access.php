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
    $email    = trim((string)($_POST['email'] ?? ''));
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
        q('UPDATE clients SET username = ?, email = ?, password_hash = ? WHERE id = ?',
            [$username, $email, password_hash($password, PASSWORD_DEFAULT), $client['id']]);
        audit('set_client_access', 'client', (int)$client['id'], ['username' => $username]);
        flash('Login saved for ' . $client['name'] . '. Give the password to them by phone or in person.');
    }
    redirect('client_access.php?client_id=' . $postedId);
}

$clients = q('SELECT id, name, username, email, password_hash IS NOT NULL AS has_login
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

layout_top('Client portal access');
layout_back('index.php', 'Back to certificates');
?>
<h1 class="page-title">SG. Client's Portal Access</h1>
<p class="hint">Assign or change the username and password clients use to download their own SG. certificates.</p>

<!-- <h2 id="logins">Clients with credentials</h2> -->

<table>
  <tr><th>Index</th><th>Client</th><th>Username</th><th>E-mail</th><th></th></tr>
  <?php foreach ($loginRows as $i => $row): ?>
    <tr>
      <td><?= $loffset + $i + 1 ?></td>
      <td><?= e($row['name']) ?></td>
      <td><?= e($row['username']) ?></td>
      <td><?= e($row['email']) ?></td>
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
  <button type="button" class="button" id="creds-open">Assign Credentials</button>
</div>

<div id="creds-modal" class="modal" <?= $openCreds ? '' : 'hidden' ?>>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="creds-title">
    <div class="modal-head">
      <h2 id="creds-title" style="color:white;">Assign Credentials</h2>
      <button type="button" class="modal-close" aria-label="Close">&times;</button>
    </div>

    <div class="modal-body">
      <form method="post" class="pay-edit">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <label>Client <span class="req">*</span>
          <select name="client_id" required>
            <option value="">Choose a client</option>
            <?php foreach ($clients as $row): ?>
              <option value="<?= (int)$row['id'] ?>"
                      <?= $client && (int)$client['id'] === (int)$row['id'] ? 'selected' : '' ?>>
                <?= e($row['name']) ?><?= $row['has_login'] ? ' (' . e($row['username']) . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>Username <span class="req">*</span>
          <input name="username" required autocapitalize="none" value="<?= e($suggestedUser) ?>">
        </label>

        <label>E-mail (optional)
          <input name="email" type="email" value="<?= e($client['email'] ?? '') ?>">
        </label>

        <label>Password <span class="req">*</span>
          <input name="password" type="text" required minlength="8" value="<?= e($suggestedPass) ?>">
        </label>

        <p class="warn-note">Setting a password again replaces the old one.
          Give it to the client by phone or in person, never by e-mail.</p>

        <div class="pay-edit-actions">
          <button type="submit">Save credentials</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(
  
  function () {
    var modal = document.getElementById('creds-modal');
    var sel  = modal.querySelector('select[name="client_id"]');
    var user = modal.querySelector('input[name="username"]');
    var mail = modal.querySelector('input[name="email"]');

  function fillFromClient() {
    var opt = sel ? sel.options[sel.selectedIndex] : null;
    if (!opt || !opt.value) { return; }
    user.value = opt.dataset.username || opt.dataset.suggest || '';
    mail.value = opt.dataset.email || '';
  }

  if (!modal) { return; }

  function show() {
    modal.removeAttribute('hidden');
    document.body.style.overflow = 'hidden';
    var first = modal.querySelector('select, input');
    if (first) { first.focus(); }
  }
  function shut() {
    modal.setAttribute('hidden', '');
    document.body.style.overflow = '';
  }

  var opener = document.getElementById('creds-open');
  if (opener) { opener.addEventListener('click', show); }

    if (sel) { sel.addEventListener('change', fillFromClient); }

    document.querySelectorAll('.creds-edit').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (sel) { sel.value = btn.dataset.clientId; fillFromClient(); }
      show();
    });
  });

  modal.addEventListener('click', function (ev) {
    if (ev.target === modal || ev.target.classList.contains('modal-close')) { shut(); }
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && !modal.hasAttribute('hidden')) { shut(); }
  });

  // The page can load with the dialog already open, so lock the scroll then too.
  if (!modal.hasAttribute('hidden')) { document.body.style.overflow = 'hidden'; }
})();
</script>
<?php layout_bottom(); ?>
