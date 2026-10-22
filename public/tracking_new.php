<?php
/**
 * New tracking installation certificate.
 *
 * Goes in public/tracking_new.php
 *
 * Saving creates it as PENDING. It has no serial number and cannot be printed or
 * downloaded until accounts records the payment and approves it.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/tracking.php';
require APP_DIR . '/views/layout.php';

$user = require_admin();
$errors = [];

$today = date('Y-m-d');

$form = [
    'client_name' => '', 'client_phone' => '',
    'reg_no' => '', 'make_model' => '', 'chassis_no' => '', 'engine_no' => '',
    'colour' => '', 'ownership' => '', 'financier' => '', 'imei' => '', 'imei_2' => '',
    'installed_on' => $today,
    'subscription_date' => $today,
    'term_months' => '12',
    'expiry_date' => default_expiry($today, 12),
];

/** Every certificate is signed by the same person, so this is a setting, not a field. */
$signedBy = trim((string)setting('tracking_signatory', '')) ?: 'MOSES CLAYTONE';

/** Typed and stored in capitals, because that is how the certificate reads. */
$upperFields = ['client_name', 'reg_no', 'make_model', 'chassis_no', 'engine_no',
                'colour', 'ownership', 'financier'];

// Prefill from the vehicle's last tracking certificate: tracking_new.php?reg=KDF 745B
// Matched on letters and digits only, so KDF745B finds KDF 745B.
if (!empty($_GET['reg']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $last = q("SELECT * FROM tracking_certificates
                WHERE regexp_replace(upper(reg_no), '[^A-Z0-9]', '', 'g') = ?
                ORDER BY issued_at DESC LIMIT 1", [plate_key((string)$_GET['reg'])])->fetch();
    if ($last) {
        foreach (['client_name', 'client_phone', 'reg_no', 'make_model', 'chassis_no',
                  'engine_no', 'colour', 'ownership', 'financier',
                  'installed_on'] as $key) {
            $form[$key] = (string)$last[$key];
        }
        // One box each. tracking_certificates.imei holds the PRINTED string, which
        // for two units reads "865... / 866...", so it must not be poured into a
        // single field - the rows are what the boxes come from.
        $prev = tracking_imeis((int)$last['id']);
        $form['imei']   = (string)($prev[0] ?? (string)$last['imei']);
        $form['imei_2'] = (string)($prev[1] ?? '');
    } else {
        $form['reg_no'] = mb_strtoupper(trim((string)$_GET['reg']), 'UTF-8');
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

    // The expiry is worked out, never typed: the chosen term, ending the day before
    // the anniversary. A one-year subscription bought on 23/09/2026 runs to 22/09/2027.
    $months = (int)$form['term_months'];
    if (!in_array($months, [12, 24, 36, 48, 60], true)) {
        $months = 12;
        $form['term_months'] = '12';
    }
    if (strtotime((string)$form['subscription_date']) !== false) {
        $form['expiry_date'] = default_expiry($form['subscription_date'], $months);
    }

    $labels = [
        'client_name' => 'Customer name', 'client_phone' => 'Contact',
        'reg_no' => 'Registration number', 'make_model' => 'Make / model',
        'chassis_no' => 'Chassis number', 'engine_no' => 'Engine number',
        'colour' => 'Colour', 'ownership' => 'Ownership details',
        'imei' => 'Track IMEI number', 'installed_on' => 'Date installed',
        'subscription_date' => 'Subscription date',
    ];
    foreach ($labels as $field => $label) {
        if ($form[$field] === '') {
            $errors[] = $label . ' is required.';
        }
    }
    foreach (['installed_on' => 'Date installed', 'subscription_date' => 'Subscription date'] as $f => $l) {
        if ($form[$f] !== '' && strtotime((string)$form[$f]) === false) {
            $errors[] = $l . ' is not a valid date.';
        }
    }
    if ($form['client_phone'] !== '' && !preg_match('/^[0-9+ ]+$/', $form['client_phone'])) {
        $errors[] = 'Contact may contain only digits, spaces and +.';
    }
    // The first box is a tracker IMEI: 14 to 17 digits, which covers every unit
    // GAPTECH fits as standard.
    if ($form['imei'] !== '' && !preg_match('/^[0-9]{14,17}$/', $form['imei'])) {
        $errors[] = 'An IMEI is 14 to 17 digits. Check it has been copied in full.';
    }

    // The second unit is NOT always an IMEI of that shape - it may be a serial
    // number, and it may carry letters. Checked for length and for obvious
    // rubbish only; the technician reading it off the device knows better than
    // a pattern does.
    if ($form['imei_2'] !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-\/]{3,31}$/', $form['imei_2'])) {
        $errors[] = 'The second unit\'s number looks wrong. Use 4 to 32 characters '
                  . '- digits, letters, hyphens.';
    }

    if ($form['imei'] !== '' && $form['imei'] === $form['imei_2']) {
        $errors[] = 'Both boxes have the same IMEI. Leave the second one empty if '
                  . 'there is only one unit.';
    }
    if ($form['imei'] === '' && $form['imei_2'] !== '') {
        $errors[] = 'Put the first unit in the first box.';
    }
    if ($form['installed_on'] !== '' && $form['subscription_date'] !== ''
        && strtotime($form['subscription_date']) < strtotime($form['installed_on'])) {
        $errors[] = 'The subscription cannot start before the unit was installed.';
    }

    // A vehicle with a live subscription should be renewed, not given a second one.
    if (!$errors) {
        $current = tracking_current_for_plate($form['reg_no']);
        if ($current && strtotime($current['expiry_date']) >= strtotime(date('Y-m-d'))) {
            $errors[] = $form['reg_no'] . ' already has a valid certificate ('
                . tracking_label($current) . ', expiring ' . fmt_date($current['expiry_date'])
                . '). Renew that one instead, or void it first if it was issued in error.';
        }
    }

    if (!$errors) {
        try {
            // Two boxes in, one list out. create_tracking_cert() stores the rows
            // and writes the printed "a / b" string from them.
            $cert = create_tracking_cert(
                $form + [
                    'signed_by' => $signedBy,
                    'imeis'     => [$form['imei'], $form['imei_2']],
                ], $user);
            flash('Installation certificate for ' . $form['reg_no'] . ' sent to accounts for '
                . 'approval. It takes its serial number once they confirm the payment.');
            redirect('tracking_view.php?id=' . $cert['id']);
        } catch (Throwable $err) {
            $errors[] = 'Could not save: ' . $err->getMessage();
        }
    }
}

/** One text field. $req marks it with the red asterisk and makes the browser enforce it. */
function trk_input(string $name, string $label, string $value, array $opts = []): void
{
    $req  = !array_key_exists('required', $opts) || $opts['required'];
    $type = $opts['type'] ?? 'text';
    echo '<label class="field">' . e($label);
    if ($req) { echo ' <span class="req">*</span>'; }
    echo '<span class="field-row"><input name="' . e($name) . '" type="' . e($type)
        . '" value="' . e($value) . '"'
        . ($req ? ' required' : '')
        . (!empty($opts['caps']) ? ' class="caps"' : '')
        . (isset($opts['maxlength']) ? ' maxlength="' . (int)$opts['maxlength'] . '"' : '')
        . (isset($opts['inputmode']) ? ' inputmode="' . e($opts['inputmode']) . '"' : '')
        . (isset($opts['placeholder']) ? ' placeholder="' . e($opts['placeholder']) . '"' : '')
        . '></span>';
    if (!empty($opts['hint'])) { echo '<span class="field-hint">' . e($opts['hint']) . '</span>'; }
    echo '</label>';
}

layout_top('New installation certificate');
layout_back('tracking_list.php', 'Back to installations');
?>
<h1>New Installation Certificate</h1>
<p class="hint">For a vehicle tracking unit. The expiry date is worked out from the subscription
date &mdash; twelve months, ending the day before the anniversary. The certificate goes to
accounts when you save, and takes its serial number once they confirm the payment.</p>

<?php foreach ($errors as $msg): ?><p class="error"><?= e($msg) ?></p><?php endforeach; ?>

<form method="post" class="card-form" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

  <fieldset>
    <legend>Customer</legend>
    <?php trk_input('client_name', 'Customer name', $form['client_name'], ['caps' => true]); ?>
    <?php trk_input('client_phone', 'Contact', $form['client_phone'],
          ['type' => 'tel', 'inputmode' => 'tel', 'maxlength' => 20]); ?>
    <?php trk_input('ownership', 'Ownership details', $form['ownership'],
          ['caps' => true, 'hint' => 'The registered owner, as it should read on the certificate.']); ?>
    <?php trk_input('financier', 'Financier', $form['financier'],
          ['caps' => true, 'required' => false, 'hint' => 'Leave blank if there is none; the certificate prints N/A.']); ?>
  </fieldset>

  <fieldset>
    <legend>Vehicle</legend>
    <?php trk_input('reg_no', 'Registration no.', $form['reg_no'], ['caps' => true]); ?>
    <?php trk_input('make_model', 'Make / model', $form['make_model'], ['caps' => true]); ?>
    <?php trk_input('chassis_no', 'Chassis no.', $form['chassis_no'], ['caps' => true]); ?>
    <?php trk_input('engine_no', 'Engine no.', $form['engine_no'], ['caps' => true]); ?>
    <?php trk_input('colour', 'Colour', $form['colour'], ['caps' => true]); ?>
  </fieldset>

  <fieldset>
    <legend>Tracking unit</legend>
    <?php trk_input('imei', 'Track IMEI no.', $form['imei'],
          ['inputmode' => 'numeric', 'maxlength' => 17,
           'hint' => '15 digits, from the unit or the device list.']); ?>
    <?php trk_input('imei_2', 'Second unit no. (optional)', $form['imei_2'],
          ['maxlength' => 32, 'caps' => true, 'required' => false,
           'hint' => 'Only if a second tracker is fitted. IMEI or serial, whatever is '
                   . 'on the unit. Both print in one box, separated by a slash.']); ?>
    <?php trk_input('installed_on', 'Date installed', $form['installed_on'], ['type' => 'date']); ?>
  </fieldset>

  <fieldset>
    <legend>Subscription</legend>
    <?php trk_input('subscription_date', 'Subscription date', $form['subscription_date'], ['type' => 'date']); ?>
    <label class="field">Subscription period <span class="req">*</span>
      <span class="field-row">
        <select name="term_months" id="term">
          <?php foreach ([12 => '1 year', 24 => '2 years', 36 => '3 years',
                          48 => '4 years', 60 => '5 years', 72 => '6 years'] as $m => $text): ?>
            <option value="<?= $m ?>" <?= (int)$form['term_months'] === $m ? 'selected' : '' ?>>
              <?= e($text) ?></option>
          <?php endforeach; ?>
        </select>
      </span>
    </label>
    <label class="field">Expires
      <span class="field-row">
        <input id="expiry-shown" value="<?= e(fmt_date($form['expiry_date'])) ?>" disabled>
      </span>
      <span class="field-hint">Worked out for you, from the date and the period.</span>
    </label>
    <p class="hint" style="text-align:left; grid-column:1/-1">Signed by
      <strong><?= e($signedBy) ?></strong>. Changed in Settings, not here, so every
      certificate carries the same name.</p>
  </fieldset>

  <button type="submit">Save certificate</button>
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

// Show the expiry as the subscription date moves. The server works it out again on
// save, so this is only to save a surprise.
(function () {
  var subs = document.querySelector('[name=subscription_date]');
  var term = document.getElementById('term');
  var shown = document.getElementById('expiry-shown');
  if (!subs || !term || !shown) { return; }
  var months = ['January','February','March','April','May','June',
                'July','August','September','October','November','December'];
  function recalc() {
    var parts = subs.value.split('-');
    if (parts.length !== 3) { return; }
    var d = new Date(Date.UTC(+parts[0], +parts[1] - 1, +parts[2]));
    if (isNaN(d.getTime())) { return; }
    d.setUTCMonth(d.getUTCMonth() + parseInt(term.value, 10));
    d.setUTCDate(d.getUTCDate() - 1);
    shown.value = String(d.getUTCDate()).padStart(2, '0') + ' '
                + months[d.getUTCMonth()] + ' ' + d.getUTCFullYear();
  }
  subs.addEventListener('change', recalc);
  term.addEventListener('change', recalc);
})();

// Contact: digits, spaces and + only. IMEI: digits only.
(function () {
  // imei_2 is deliberately absent: a second unit's number may carry letters, and
  // stripping them as somebody types would quietly destroy what they entered.
  var rules = [['[name=client_phone]', /[^0-9+ ]/g], ['[name=imei]', /[^0-9]/g]];
  rules.forEach(function (rule) {
    var input = document.querySelector(rule[0]);
    if (!input) { return; }
    input.addEventListener('input', function () {
      var cleaned = this.value.replace(rule[1], '');
      if (cleaned !== this.value) { this.value = cleaned; }
    });
  });
})();
</script>
<?php layout_bottom(); ?>