<?php
/** The one form staff fill in. Everything on the certificate comes from here. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require APP_DIR . '/views/layout.php';

$user = require_staff();
$errors = [];

/** Values prefilled from Settings. Staff can type over them. */
$defaults = [
    // trim() and ?: so a setting saved as blank still falls back to the value here
    'device_model' => trim((string)setting('default_model')) ?: 'INTELSPEED™',
    'set_speed_kmh' => trim((string)setting('default_speed')) ?: '80',
    'technician' => trim((string)setting('default_technician')) ?: 'GAPTECH',
];

$form = [
    'client_name' => '', 'client_phone' => '', 'client_address' => '',
    'reg_no' => '', 'make' => '', 'chassis_no' => '',
    'device_model' => $defaults['device_model'], 'serial_no' => '', 
    'set_speed_kmh' => $defaults['set_speed_kmh'],
    'installed_on' => date('Y-m-d'), 'technician' => $defaults['technician'],
    'type' => 'Renewal', 'issue_date' => date('Y-m-d'), 'validity_months' => '12',
    'expiry_date' => 'Expiry date', 'receipt_ref' => '',
];

/** Fields that are typed in capitals, on screen and when saved. */
$upperFields = ['client_name', 'client_address', 'reg_no', 'make', 'chassis_no',
                'device_model', 'serial_no', 'technician', 'receipt_ref'];

// Prefill from an existing vehicle: certificate_new.php?reg=KDK 017N
if (!empty($_GET['reg'])) {
    $v = q('SELECT v.*, c.name AS client_name, c.phone, c.address
            FROM vehicles v JOIN clients c ON c.id = v.client_id
            WHERE upper(v.reg_no) = upper(?)', [$_GET['reg']])->fetch();
    if ($v) {
        $form['client_name'] = $v['client_name'];
        $form['client_phone'] = $v['phone'];
        $form['client_address'] = $v['address'];
        $form['reg_no'] = $v['reg_no'];
        $form['make'] = $v['make'];
        $form['chassis_no'] = $v['chassis_no'];
        $last = q('SELECT d.*, i.technician, i.installed_on
                   FROM installations i JOIN devices d ON d.id = i.device_id
                   WHERE i.vehicle_id = ? ORDER BY i.installed_on DESC LIMIT 1', [$v['id']])->fetch();
        if ($last) {
            $form['device_model'] = $last['model'];
            $form['serial_no'] = $last['serial_no'];
            $form['set_speed_kmh'] = $last['set_speed_kmh'];
            $form['installed_on'] = $last['installed_on'];
            $form['technician'] = $last['technician'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($form as $key => $default) {
        $value = trim((string)($_POST[$key] ?? $default));
        if (in_array($key, $upperFields, true)) {
            $value = mb_strtoupper($value, 'UTF-8');
        }
        $form[$key] = $value;
    }
    if ($form['expiry_date'] === '' && $form['issue_date'] !== '') {
        $form['expiry_date'] = default_expiry($form['issue_date'], (int)$form['validity_months']);
    }

    // Every field is required except 'receipt_ref' => 'Receipt reference',

    $labels = [
        'client_name' => 'Customer name', 'client_phone' => 'Contact', 'client_address' => 'Address',
        'reg_no' => 'Registration number', 'make' => 'Make', 'chassis_no' => 'Chassis number',
        'device_model' => 'Governor model', 'serial_no' => 'Serial number', 
        'set_speed_kmh' => 'Set speed', 'installed_on' => 'Date installed', 'technician' => 'Technician',
        'type' => 'Service type', 'issue_date' => 'Issue date', 'validity_months' => 'Validity',
        'expiry_date' => 'Expiry date', 
    ];
    foreach ($labels as $field => $label) {
        if ($form[$field] === '') {
            $errors[] = $label . ' is required.';
        }
    }
    if (!in_array($form['type'], ['Fitting', 'Renewal'], true)) {
        $errors[] = 'Choose fitting or renewal.';
    }
    if ($form['receipt_ref'] !== '') {
        $used = q('SELECT number FROM certificates WHERE receipt_ref = ?', [$form['receipt_ref']])->fetch();
        if ($used) {
            $errors[] = 'That receipt reference was already used on certificate ' . $used['number'] . '.';
        }
    }

    if (!$errors) {
        try {
            $cert = create_certificate($form, $user);
            flash('Certificate ' . $cert['number'] . ' issued.');
            redirect('certificate_view.php?id=' . $cert['id']);
        } catch (Throwable $err) {
            $errors[] = 'Could not save: ' . $err->getMessage();
        }
    }
}

/** One required text field. */
function field(string $name, string $label, string $value, array $opts = []): void
{
    $type = $opts['type'] ?? 'text';
    $caps = !empty($opts['caps']);
    echo '<label class="field">';
    echo e($label) . ' <span class="req">*</span>';
    echo '<span class="field-row">';
    echo '<input name="' . e($name) . '" type="' . e($type) . '" value="' . e($value) . '" required'
        . ($caps ? ' class="caps"' : '')
        . (isset($opts['min']) ? ' min="' . e((string)$opts['min']) . '"' : '')
        . (isset($opts['max']) ? ' max="' . e((string)$opts['max']) . '"' : '')
        . '>';
    echo '</span></label>';
}

layout_top('New certificate');
?>
<h1>New certificate</h1>
<p class="hint">All fields are required except the receipt reference. The certificate number is allocated automatically when you save.
Some values are filled in from Settings &mdash; type over them if this job is different.</p>

<?php foreach ($errors as $msg): ?><p class="error"><?= e($msg) ?></p><?php endforeach; ?>

<form method="post" class="card-form" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

  <fieldset>
    <legend>Customer</legend>
    <?php field('client_name', 'Customer name', $form['client_name'], ['caps' => true]); ?>
    <label class="field">Contact <span class="req">*</span>
      <span class="field-row">
        <input name="client_phone" type="tel" value="<?= e($form['client_phone']) ?>" required
               inputmode="tel" pattern="[0-9+ ]+" maxlength="20"
               title="Digits, spaces and + only">
      </span>
    </label>
    <?php field('client_address', 'Address', $form['client_address'], ['caps' => true]); ?>
  </fieldset>

  <fieldset>
    <legend>Vehicle</legend>
    <?php field('reg_no', 'Registration no.', $form['reg_no'], ['caps' => true]); ?>
    <?php field('make', 'Make', $form['make'], ['caps' => true]); ?>
    <?php field('chassis_no', 'Chassis no.', $form['chassis_no'], ['caps' => true]); ?>
  </fieldset>

  <fieldset>
    <legend>Speed governor</legend>
    <?php field('device_model', 'Model', $form['device_model'], ['caps' => true]); ?>
    <?php field('serial_no', 'Serial no.', $form['serial_no'], ['caps' => true]); ?>
    <?php field('set_speed_kmh', 'Set speed (km/h)', $form['set_speed_kmh'],
        ['type' => 'number', 'min' => 1, 'max' => 200]); ?>
    <?php field('installed_on', 'Date installed', $form['installed_on'], ['type' => 'date']); ?>
    <label class="field">Acting agent / technician
      <span class="field-static"><?= e($form['technician']) ?></span>
      <input type="hidden" name="technician" value="<?= e($form['technician']) ?>">
    </label>
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
    <?php field('issue_date', 'Issue date', $form['issue_date'], ['type' => 'date']); ?>
    <?php field('validity_months', 'Valid for (months)', $form['validity_months'],
        ['type' => 'number', 'min' => 1, 'max' => 60]); ?>
    <?php field('expiry_date', 'Expiry date', $form['expiry_date'], ['type' => 'date']); ?>
    <?php echo '<label class="field">Receipt / payment reference'
        . '<span class="field-row"><input name="receipt_ref" class="caps" value="'
        . e($form['receipt_ref']) . '"></span></label>'; 
        ?>
        <br>
  <button type="submit">Save Certificate</button>
</form>

<script>
// Type in capitals: the value itself is stored in capitals, not only shown that way.
document.querySelectorAll('input.caps').forEach(function (input) {
  input.addEventListener('input', function () {
    var start = this.selectionStart, end = this.selectionEnd;
    this.value = this.value.toUpperCase();
    if (this.setSelectionRange) { this.setSelectionRange(start, end); }
  });
});

// Keep the expiry date in step with the issue date: twelve months, less a day.
function recalcExpiry() {
  var issue = document.querySelector('[name=issue_date]').value;
  var months = parseInt(document.querySelector('[name=validity_months]').value || '12', 10);
  if (!issue) { return; }
  var d = new Date(issue + 'T00:00:00');
  d.setMonth(d.getMonth() + months);
  d.setDate(d.getDate() - 1);
  document.querySelector('[name=expiry_date]').value = d.toISOString().slice(0, 10);
}
document.querySelector('[name=issue_date]').addEventListener('change', recalcExpiry);
document.querySelector('[name=validity_months]').addEventListener('change', recalcExpiry);

// Enforce allowed characters for client phone input: digits, spaces, and + only.
(function () {
  var phone = document.querySelector('[name=client_phone]');
  if (!phone) { return; }

  var note = document.createElement('span');
  note.className = 'field-note';
  note.textContent = 'Only digits, spaces and + are allowed.';
  phone.parentNode.parentNode.appendChild(note);

  var hideTimer;
  phone.addEventListener('input', function () {
    var cleaned = this.value.replace(/[^0-9+ ]/g, '');
    if (cleaned !== this.value) {
      this.value = cleaned;
      note.classList.add('show');
      this.classList.add('input-bad');
      clearTimeout(hideTimer);
      hideTimer = setTimeout(function () {
        note.classList.remove('show');
        phone.classList.remove('input-bad');
      }, 2500);
    }
  });
})();
</script>
<?php layout_bottom(); ?>
