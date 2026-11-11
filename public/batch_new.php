<?php
/**
 * public/batch_new.php  -  one fleet, many vehicles, one upload.
 *
 * Three steps on one page:
 *   1. who the customer is, and download a spreadsheet to fill in
 *   2. upload it and READ THE PREVIEW - nothing is saved at this point
 *   3. create the batch, all pending, waiting for accounts
 *
 * Nothing is written to the database until step 3 is submitted.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require_once APP_DIR . '/batches.php';          // the bulk functions
require_once APP_DIR . '/views/mode_switch.php'; // one vehicle / a whole fleet
require APP_DIR . '/views/layout.php';

$user   = require_admin();
$errors = [];
$notes  = [];

$form = [
    'client_name'    => '',
    'client_phone'   => '',
    'client_address' => '',
    'issue_date'     => date('Y-m-d'),
    'label'          => '',
];
foreach ($form as $k => $v) {
    $form[$k] = trim((string)($_POST[$k] ?? $_GET[$k] ?? $v));
}

$preview = null;
$action  = (string)($_POST['action'] ?? '');

// Which customers already exist, so the spreadsheet can come down pre-filled.
$clients = q('SELECT id, name, phone FROM clients ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ($form['client_name'] === '') {
        $errors[] = 'Enter the customer first - it is typed once here and used for every vehicle.';
    }

    // ---------------------------------------------------------- 2. the preview
    if (!$errors && $action === 'preview') {
        $up = $_FILES['csv'] ?? null;
        if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Choose the filled-in spreadsheet to upload.';
        } elseif (($up['error'] ?? 0) !== UPLOAD_ERR_OK) {
            $errors[] = 'That file did not arrive in one piece (error ' . (int)$up['error']
                      . '). Try again, or save it as CSV first if it is an Excel file.';
        } else {
            try {
                $rows    = batch_rows_from_csv($up['tmp_name']);
                $preview = batch_preview($form, $rows, $form['issue_date']);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }

    // ---------------------------------------------------------- 3. create it
    if (!$errors && $action === 'create') {
        $rows = json_decode((string)($_POST['rows'] ?? '[]'), true) ?: [];

        // Checked again from scratch. The preview could be minutes old and
        // somebody else may have raised a certificate for one of these lorries
        // in the meantime - the file on screen is not evidence of anything.
        $preview = batch_preview($form, $rows, $form['issue_date']);

        $confirmed = array_map('intval', (array)($_POST['confirm'] ?? []));
        $create    = [];
        foreach ($preview['rows'] as $r) {
            if ($r['verdict'] === 'blocked') {
                continue;                       // shown, skipped, never created
            }
            $r['confirm'] = in_array((int)$r['line'], $confirmed, true);
            if (!empty($r['needs_confirm']) && !$r['confirm']) {
                $errors[] = 'Line ' . $r['line'] . ' (' . $r['reg_no']
                    . ') takes a governor off another lorry. Tick it to confirm, or remove it.';
            }
            $create[] = $r;
        }

        if (!$create && !$errors) {
            $errors[] = 'Every row in that file is blocked. Fix them and upload it again.';
        }

        if (!$errors) {
            try {
                $made = batch_create($form, $create, $user, $form['issue_date'], $form['label']);
                header('Location: batch_view.php?id=' . $made['batch_id'] . '&created=1');
                exit;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

layout_top('New certificate');
layout_back('index.php', 'Back to certificates');
?>
<h1>New certificate</h1>
<?php mode_switch('bulk', $form); ?>

<p class="hint">Type the customer once, fill in one line per vehicle, and upload it.
Everything is checked and shown to you before anything is saved. The batch then goes to
accounts as a single payment.</p>

<?php foreach ($errors as $msg): ?><p class="error"><?= e($msg) ?></p><?php endforeach; ?>
<?php foreach ($notes  as $msg): ?><p class="hint"><?= e($msg) ?></p><?php endforeach; ?>

<form method="post" class="card-form" enctype="multipart/form-data" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

  <fieldset>
    <legend>1. Customer</legend>
    <p class="field-hint" style="margin-top:0">Typed once, here. That is what stops forty rows
       creating four spellings of the same company.</p>

    <label class="field">Customer name <span class="req">*</span>
      <span class="field-row">
        <input name="client_name" list="known-clients" class="caps" required maxlength="120"
               value="<?= e($form['client_name']) ?>">
      </span>
      <datalist id="known-clients">
        <?php foreach ($clients as $c): ?>
          <option value="<?= e($c['name']) ?>"><?= e($c['phone']) ?></option>
        <?php endforeach; ?>
      </datalist>
      <span class="field-hint">Start typing - existing customers will offer themselves.</span>
    </label>

    <label class="field">Contact
      <span class="field-row">
        <input name="client_phone" type="tel" maxlength="20" pattern="[0-9+ ]*"
               value="<?= e($form['client_phone']) ?>">
      </span>
    </label>

    <label class="field">Address
      <span class="field-row">
        <input name="client_address" class="caps" maxlength="160"
               value="<?= e($form['client_address']) ?>">
      </span>
    </label>
  </fieldset>

  <fieldset>
    <legend>2. The spreadsheet</legend>

    <p class="field-hint" style="margin-top:0">
      Download it, fill it in, upload it back. Choosing an existing customer brings their
      vehicles down already filled in, so a renewal only needs the fitting dates.
    </p>

    <p style="margin:0 0 16px">
      <a class="button secondary" id="get-template" href="batch_template.php">Download the spreadsheet</a>
    </p>

    <label class="field">The filled-in file <span class="req">*</span>
      <span class="field-row">
        <input type="file" name="csv" accept=".csv,text/csv,application/vnd.ms-excel">
      </span>
      <span class="field-hint">Save it as CSV from Excel &mdash; File, Save As, "CSV (Comma delimited)".</span>
    </label>

    <label class="field">Issue date <span class="req">*</span>
      <span class="field-row">
        <input type="date" name="issue_date" required value="<?= e($form['issue_date']) ?>">
      </span>
      <span class="field-hint">The same issue date for every certificate in the batch.
        Each one expires by its own validity.</span>
    </label>

    <label class="field">Name for this batch
      <span class="field-row">
        <input name="label" maxlength="80" value="<?= e($form['label']) ?>"
               placeholder="e.g. November intake, 38 lorries">
      </span>
    </label>

    <button class="button" type="submit" name="action" value="preview">Check the file</button>
  </fieldset>
</form>

<?php if ($preview !== null): $c = $preview['counts']; ?>

<h2 style="margin-top:32px">What is in that file</h2>
<p class="hint">
  <?= (int)$c['new'] ?> first fittings &middot;
  <?= (int)$c['renewal'] ?> renewals &middot;
  <?= (int)$c['moved'] ?> with a governor moved from another lorry &middot;
  <?= (int)$c['blocked'] ?> that cannot be created.
  Nothing has been saved yet.
</p>

<form method="post" class="card-form">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="rows" value="<?= e(json_encode($preview['rows'])) ?>">
  <?php foreach (['client_name','client_phone','client_address','issue_date','label'] as $k): ?>
    <input type="hidden" name="<?= e($k) ?>" value="<?= e($form[$k]) ?>">
  <?php endforeach; ?>

  <table class="list">
    <thead>
      <tr><th>Line</th><th>Registration</th><th>Governor</th><th></th><th>What will happen</th></tr>
    </thead>
    <tbody>
    <?php foreach ($preview['rows'] as $r):
      $badge = ['new' => 'Fitting', 'renewal' => 'Renewal',
                'moved' => 'Moved unit', 'blocked' => 'Cannot create'][$r['verdict']]; ?>
      <tr class="row-<?= e($r['verdict']) ?>"<?= $r['verdict'] === 'blocked' ? ' style="opacity:.6"' : '' ?>>
        <td><?= (int)$r['line'] ?></td>
        <td><strong><?= e($r['reg_no'] ?: '-') ?></strong><br>
            <span class="field-hint"><?= e($r['make']) ?></span></td>
        <td><?= e($r['serial_no'] ?: '-') ?></td>
        <td><span class="status status-<?= e($r['verdict']) ?>"><?= e($badge) ?></span></td>
        <td>
          <?= e(implode(' ', $r['notes'])) ?>
          <?php if (!empty($r['needs_confirm']) && $r['verdict'] !== 'blocked'): ?>
            <div style="display:flex; align-items:center; gap:8px; margin-top:8px">
              <input type="checkbox" name="confirm[]" value="<?= (int)$r['line'] ?>"
                     id="cf<?= (int)$r['line'] ?>" style="width:16px; flex:0 0 16px; margin:0">
              <label for="cf<?= (int)$r['line'] ?>" style="margin:0">
                Yes &mdash; it was moved. Close the old fitting.</label>
            </div>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($c['blocked']): ?>
    <p class="hint">The <?= (int)$c['blocked'] ?> blocked
       <?= $c['blocked'] === 1 ? 'row is' : 'rows are' ?> skipped, not created. Fix
       <?= $c['blocked'] === 1 ? 'it' : 'them' ?> in the spreadsheet and upload it again
       if <?= $c['blocked'] === 1 ? 'it belongs' : 'they belong' ?> in this batch.</p>
  <?php endif; ?>

  <p style="margin-top:20px">
    <button class="button" type="submit" name="action" value="create">
      Create <?= (int)$preview['ok'] ?> certificate<?= $preview['ok'] === 1 ? '' : 's' ?>
    </button>
  </p>
</form>

<?php endif; ?>

<script>
// The template link carries whichever customer is typed in, so their vehicles
// come down already filled in. Falls back to the empty template.
(function () {
  var name = document.querySelector('input[name="client_name"]');
  var link = document.getElementById('get-template');
  var ids  = <?= json_encode(array_column($clients, 'id', 'name')) ?>;
  if (!name || !link) { return; }

  function point() {
    var id = ids[name.value] || ids[name.value.trim()];
    link.href = id ? 'batch_template.php?client=' + id : 'batch_template.php';
    link.textContent = id ? 'Download their vehicles to fill in' : 'Download the spreadsheet';
  }
  name.addEventListener('input', point);
  name.addEventListener('change', point);
  point();
})();
</script>
<?php layout_bottom(); ?>