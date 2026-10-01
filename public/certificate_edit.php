<?php
/**
 * Correct a certificate that has not left the office yet.
 *
 * Rules:
 *  - supervisors only
 *  - only while the certificate is valid and has never been printed or downloaded
 *  - the previous values are written to the audit log before anything changes
 *
 * Once a certificate has been printed or downloaded, someone may be holding a copy of it,
 * so it must be voided and reissued instead. That is offered here rather than an edit.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require APP_DIR . '/views/layout.php';

$user = require_supervisor();
$cert = q('SELECT * FROM certificates WHERE id = ?', [(int)($_GET['id'] ?? 0)])->fetch();
if (!$cert) {
    http_response_code(404);
    exit('Certificate not found.');
}

$snapshot = json_decode($cert['snapshot'], true);
$status = certificate_status($cert);
$released = !empty($cert['released_at']);
$editable = ($status === 'Valid' && !$released);

$errors = [];
$form = [
    'client_name' => $snapshot['client']['name'],
    'client_phone' => $snapshot['client']['phone'],
    'client_address' => $snapshot['client']['address'],
    'reg_no' => $snapshot['vehicle']['reg_no'],
    'make' => $snapshot['vehicle']['make'],
    'chassis_no' => $snapshot['vehicle']['chassis_no'],
    'device_model' => $snapshot['device']['model'],
    'serial_no' => $snapshot['device']['serial_no'],
    'set_speed_kmh' => (string)$snapshot['device']['set_speed_kmh'],
    'installed_on' => $cert['installation_id'] ? (string)q('SELECT installed_on FROM installations WHERE id = ?',
        [$cert['installation_id']])->fetchColumn() : date('Y-m-d'),
    'technician' => $snapshot['installation']['technician'],
    'type' => $cert['type'],
    'issue_date' => $cert['issue_date'],
    'expiry_date' => $cert['expiry_date'],
    'receipt_ref' => $cert['receipt_ref'],
];
$upperFields = ['client_name', 'client_address', 'reg_no', 'make', 'chassis_no',
                'device_model', 'serial_no', 'technician', 'receipt_ref'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$editable) {
        http_response_code(403);
        exit('This certificate can no longer be amended.');
    }
    foreach ($form as $key => $default) {
        $value = trim((string)($_POST[$key] ?? $default));
        if (in_array($key, $upperFields, true)) {
            $value = mb_strtoupper($value, 'UTF-8');
        }
        $form[$key] = $value;
    }

    $labels = [
        'client_name' => 'Customer name', 'client_phone' => 'Contact', 'client_address' => 'Address',
        'reg_no' => 'Registration number', 'make' => 'Make', 'chassis_no' => 'Chassis number',
        'device_model' => 'Governor model', 'serial_no' => 'Serial number', 'set_speed_kmh' => 'Set speed',
        'installed_on' => 'Date installed', 'technician' => 'Technician', 'type' => 'Service type',
        'issue_date' => 'Issue date', 'expiry_date' => 'Expiry date',
    ];
    foreach ($labels as $field => $label) {
        if ($form[$field] === '') {
            $errors[] = $label . ' is required.';
        }
    }
    if (!in_array($form['type'], ['Fitting', 'Renewal'], true)) {
        $errors[] = 'Choose fitting or renewal.';
    }
    if ($form['client_phone'] !== '' && !preg_match('/^[0-9+ ]+$/', $form['client_phone'])) {
        $errors[] = 'Contact may contain only digits, spaces and +.';
    }
    if ($form['receipt_ref'] !== '') {
        $used = q('SELECT number FROM certificates WHERE receipt_ref = ? AND id <> ?',
            [$form['receipt_ref'], $cert['id']])->fetch();
        if ($used) {
            $errors[] = 'That receipt reference is already on certificate ' . $used['number'] . '.';
        }
    }
    $reason = trim((string)($_POST['reason'] ?? ''));
    if ($reason === '') {
        $errors[] = 'Give a reason for the correction.';
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // keep the company and signatory exactly as the certificate was issued
            $new = $snapshot;
            $new['client'] = ['name' => $form['client_name'], 'phone' => $form['client_phone'],
                              'address' => $form['client_address']];
            $new['vehicle'] = ['reg_no' => $form['reg_no'], 'make' => $form['make'],
                               'chassis_no' => $form['chassis_no']];
            $new['device'] = ['model' => $form['device_model'], 'serial_no' => $form['serial_no'],
                              'unit_code' => $form['serial_no'], 'set_speed_kmh' => (int)$form['set_speed_kmh']];
            $new['installation'] = ['installed_on_fmt' => fmt_date($form['installed_on']),
                                    'technician' => $form['technician']];
            $new['cert']['type'] = $form['type'];
            $new['cert']['is_renewal'] = ($form['type'] === 'Renewal');
            $new['cert']['issue_date_fmt'] = fmt_date($form['issue_date']);
            $new['cert']['expiry_date_fmt'] = fmt_date($form['expiry_date']);

            // the old values, in full, before anything is touched
            audit('amend_certificate', 'certificate', (int)$cert['id'], [
                'number' => $cert['number'],
                'reason' => $reason,
                'before' => $snapshot,
                'before_dates' => ['type' => $cert['type'], 'issue_date' => $cert['issue_date'],
                                   'expiry_date' => $cert['expiry_date'], 'receipt_ref' => $cert['receipt_ref']],
                'after' => $new,
            ]);

            q('UPDATE certificates SET type = ?, issue_date = ?, expiry_date = ?, receipt_ref = ?,
                      snapshot = ?, pdf_path = NULL, pdf_sha256 = NULL WHERE id = ?', [
                $form['type'], $form['issue_date'], $form['expiry_date'], $form['receipt_ref'],
                json_encode($new), $cert['id'],
            ]);

            // keep the underlying records in step, so the next renewal is prefilled correctly
            q('UPDATE clients SET name = ?, phone = ?, address = ?, updated_at = now() WHERE id = ?',
                [$form['client_name'], $form['client_phone'], $form['client_address'], $cert['client_id']]);
            q('UPDATE vehicles SET reg_no = upper(?), make = ?, chassis_no = ? WHERE id = ?',
                [$form['reg_no'], $form['make'], $form['chassis_no'], $cert['vehicle_id']]);
            q('UPDATE devices SET model = ?, serial_no = ?, set_speed_kmh = ? WHERE id = ?',
                [$form['device_model'], $form['serial_no'], (int)$form['set_speed_kmh'], $cert['device_id']]);
            q('UPDATE installations SET technician = ?, installed_on = ? WHERE id = ?',
                [$form['technician'], $form['installed_on'], $cert['installation_id']]);

            $pdo->commit();
        } catch (Throwable $err) {
            $pdo->rollBack();
            $errors[] = 'Could not save: ' . $err->getMessage();
        }

        if (!$errors) {
            // any PDF made earlier is now out of date
            $old = rtrim($config['storage'], '/\\') . '/certificates/certificate-' . $cert['number'] . '.pdf';
            if (is_file($old)) {
                @unlink($old);
            }
            flash('Certificate ' . $cert['number'] . ' corrected. The change is in the audit log.');
            redirect('certificate_view.php?id=' . $cert['id']);
        }
    }
}

/** One required field. */
function edit_field(string $name, string $label, string $value, array $opts = []): void
{
    $type = $opts['type'] ?? 'text';
    $caps = !empty($opts['caps']);
    echo '<label class="field">' . e($label) . ' <span class="req">*</span><span class="field-row">';
    echo '<input name="' . e($name) . '" type="' . e($type) . '" value="' . e($value) . '" required'
        . ($caps ? ' class="caps"' : '')
        . (isset($opts['min']) ? ' min="' . e((string)$opts['min']) . '"' : '')
        . (isset($opts['max']) ? ' max="' . e((string)$opts['max']) . '"' : '')
        . '>';
    echo '</span></label>';
}

layout_top('Edit certificate ' . $cert['number']);
?>
<h1>EDIT CERTIFICATE - <?= e($cert['number']) ?></h1>
<p class="hint" style="color: blue;"><?= e($snapshot['client']['name']) ?> &middot; <?= e($snapshot['vehicle']['reg_no']) ?></p>
<?php if (!$editable): ?>
  <p class="error">
    <?php if ($status !== 'Valid'): ?>
      <span style="color: green;">
      This certificate is <?= e(strtolower($status)) ?>, so it cannot be amended.
    <?php else: ?>
      <span style="color: red;">
        This certificate was released on <?= e(fmt_date(substr((string)$cert['released_at'], 0, 10))) ?>
        (printed <?= (int)$cert['print_count'] ?>, downloaded <?= (int)$cert['download_count'] ?>), so a copy
        may be in someone's hands. Amending it would leave that copy saying something different from the record.
      </span>
    <?php endif; ?>
  </p>
  <p style="color: blue;">Void it and issue a corrected one instead:</p>
  <div class="actions">
    <a class="button" href="certificate_view.php?id=<?= (int)$cert['id'] ?>">Back to certificate</a>
    <a class="button" href="certificate_new.php?reg=<?= urlencode($snapshot['vehicle']['reg_no']) ?>">Issue a replacement</a>
  </div>
<?php else: ?>

<p class="hint" style="color: green;">Corrections keep the same certificate number. The old values are written to the audit log
with your name and reason. This is only possible because the certificate has not been printed or
downloaded yet.</p>

<?php foreach ($errors as $msg): ?><p class="error"><?= e($msg) ?></p><?php endforeach; ?>

<form method="post" class="card-form" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

  <fieldset>
    <legend>Customer</legend>
    <?php edit_field('client_name', 'Customer name', $form['client_name'], ['caps' => true]); ?>
    <?php edit_field('client_phone', 'Contact', $form['client_phone'], ['type' => 'tel']); ?>
    <?php edit_field('client_address', 'Address', $form['client_address'], ['caps' => true]); ?>
  </fieldset>

  <fieldset>
    <legend>Vehicle</legend>
    <?php edit_field('reg_no', 'Registration no.', $form['reg_no'], ['caps' => true]); ?>
    <?php edit_field('make', 'Make', $form['make'], ['caps' => true]); ?>
    <?php edit_field('chassis_no', 'Chassis no.', $form['chassis_no'], ['caps' => true]); ?>
  </fieldset>

  <fieldset>
    <legend>Speed governor</legend>
    <?php edit_field('device_model', 'Model', $form['device_model'], ['caps' => true]); ?>
    <?php edit_field('serial_no', 'Serial no.', $form['serial_no'], ['caps' => true]); ?>
    <?php edit_field('set_speed_kmh', 'Set speed (km/h)', $form['set_speed_kmh'],
        ['type' => 'number', 'min' => 1, 'max' => 200]); ?>
    <?php edit_field('installed_on', 'Date installed', $form['installed_on'], ['type' => 'date']); ?>
    <?php edit_field('technician', 'Acting agent / technician', $form['technician'], ['caps' => true]); ?>
  </fieldset>

  <fieldset>
    <legend>Certificate</legend>
    <label class="field">Service type <span class="req">*</span>
      <span class="field-row">
        <select name="type" required>
          <option value="Renewal" <?= $form['type'] === 'Renewal' ? 'selected' : '' ?>>Renewal</option>
          <option value="Fitting" <?= $form['type'] === 'Fitting' ? 'selected' : '' ?>>Fitting</option>
        </select>
      </span>
    </label>
    <?php edit_field('issue_date', 'Issue date', $form['issue_date'], ['type' => 'date']); ?>
    <?php edit_field('expiry_date', 'Expiry date', $form['expiry_date'], ['type' => 'date']); ?>
    <label class="field">Receipt / payment reference
      <span class="field-row">
        <input name="receipt_ref" class="caps" value="<?= e($form['receipt_ref']) ?>">
      </span>
    </label>
  </fieldset>

  <fieldset>
    <legend>Reason</legend>
    <label class="field" style="width:66%">Reason for the correction <span class="req">*</span>
      <span class="field-row">
        <input name="reason" value="<?= e($_POST['reason'] ?? '') ?>" required
               placeholder="e.g. chassis number mistyped at data entry">
      </span>
    </label>
  </fieldset>

  <button type="submit">Save correction</button>
  <a class="button secondary" href="certificate_view.php?id=<?= (int)$cert['id'] ?>">Cancel</a>
</form>

<script>
document.querySelectorAll('input.caps').forEach(function (input) {
  input.addEventListener('input', function () {
    var start = this.selectionStart, end = this.selectionEnd;
    this.value = this.value.toUpperCase();
    if (this.setSelectionRange) { this.setSelectionRange(start, end); }
  });
});
document.querySelector('[name=client_phone]').addEventListener('input', function () {
  this.value = this.value.replace(/[^0-9+ ]/g, '');
});
</script>
<?php endif; ?>
<?php layout_bottom(); ?>
