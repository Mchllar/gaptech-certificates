<?php
/** Give a client a login for the download portal. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/views/layout.php';

require_staff();
$clientId = (int)($_GET['client_id'] ?? 0);
$client = $clientId ? q('SELECT * FROM clients WHERE id = ?', [$clientId])->fetch() : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $client = q('SELECT * FROM clients WHERE id = ?', [(int)$_POST['client_id']])->fetch();
    $email = trim((string)$_POST['email']);
    $password = (string)$_POST['password'];
    if (!$client) {
        flash('Client not found.');
    } elseif (strlen($password) < 8) {
        flash('The password must be at least 8 characters.');
    } else {
        q('UPDATE clients SET email = ?, password_hash = ? WHERE id = ?',
            [$email, password_hash($password, PASSWORD_DEFAULT), $client['id']]);
        audit('set_client_access', 'client', (int)$client['id']);
        flash('Portal access set for ' . $client['name'] . '. Give them the password in person or by phone.');
        redirect('client_access.php');
    }
    redirect('client_access.php?client_id=' . (int)$_POST['client_id']);
}

$clients = q('SELECT id, name, email, password_hash IS NOT NULL AS has_login FROM clients ORDER BY name')->fetchAll();
layout_top('Client portal access');
?>
<h1>Client Portal Access</h1>
<p class="hint">Assign a client <strong style="color:green;">login credentials</strong> for the Download Portal.</p>
<form method="post" class="narrow">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <label>Client
    <select name="client_id" required>
      <option value="">Choose a client</option>
      <?php foreach ($clients as $row): ?>
        <option value="<?= (int)$row['id'] ?>" <?= $client && (int)$client['id'] === (int)$row['id'] ? 'selected' : '' ?>>
          <?= e($row['name']) ?><?= $row['has_login'] ? ' (has login)' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>E-mail <input name="email" type="email" value="<?= e($client['email'] ?? '') ?>" required></label>
  <label>Password <input name="password" type="text" required minlength="8"></label>
  <button type="submit">Save access</button>
</form>
<p class="hint" style="color:red;">(Setting a password again replaces the old one.)</p>
<?php layout_bottom(); ?>
