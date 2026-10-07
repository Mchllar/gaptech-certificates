<?php
/** Give a client a login for the download portal. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/views/layout.php';

require_admin();
$clientId = (int)($_GET['client_id'] ?? 0);
$client = $clientId ? q('SELECT * FROM clients WHERE id = ?', [$clientId])->fetch() : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $postedId = (int)($_POST['client_id'] ?? 0);
    $client = q('SELECT * FROM clients WHERE id = ?', [$postedId])->fetch();
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
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

layout_top('Client portal access',);
layout_back('index.php', 'Back to certificates');
?>
<h1 style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; font-size: 1.75rem; font-weight: 700; color: #0f172a; margin: 0 0 1rem 0; padding-left: 0.875rem; border-left: 4px solid #3b82f6; letter-spacing: -0.025em; line-height: 1.25;">Client Portal Access</h1>
<p class="hint">Assign a client <strong style="color:green;">login credentials</strong> for the Download Portal.</p>

<form method="post" class="narrow" style="width:auto;">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <label>Client
    <select name="client_id" required onchange="location.href='client_access.php?client_id=' + this.value">
      <option value="">Choose a client</option>
      <?php foreach ($clients as $row): ?>
        <option value="<?= (int)$row['id'] ?>" <?= $client && (int)$client['id'] === (int)$row['id'] ? 'selected' : '' ?>>
          <?= e($row['name']) ?><?= $row['has_login'] ? ' (' . e($row['username']) . ')' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Username <input name="username" required autocapitalize="none"
      value="<?= e($suggestedUser) ?>"></label>
  <label>E-mail (optional) <input name="email" type="email" value="<?= e($client['email'] ?? '') ?>"></label>
  <label>Password <input name="password" type="text" required minlength="8"
      value="<?= e($suggestedPass) ?>"></label>
  <button type="submit">Save Credentials</button>
</form>

<p class="hint" style="color:red;">(Setting a password again replaces the old one.)</p>

<h2>Clients With Credentials</h2>
<table>
  <tr><th>Client</th><th>Username</th><th>E-mail</th><th></th></tr>
  <?php foreach ($clients as $row): ?>
    <?php if (!$row['has_login']) { continue; } ?>
    <tr>
      <td><?= e($row['name']) ?></td>
      <td><?= e($row['username']) ?></td>
      <td><?= e($row['email']) ?></td>
      <td><a href="client_access.php?client_id=<?= (int)$row['id'] ?>">Change</a></td>
    </tr>
  <?php endforeach; ?>
</table>
<?php layout_bottom(); ?>