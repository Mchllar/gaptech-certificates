<?php
/**
 * The one form staff fill in. Everything on the certificate comes from here.
 *
 * Saving creates the certificate as PENDING. It has no number and cannot be printed
 * or downloaded until accounts records the payment and approves it.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require APP_DIR . '/views/layout.php';

$user = require_admin();
$errors = [];

/** Values that never appear on the form - they always come from Settings. */
$defaults = [
    // trim() and ?: so a setting saved as blank still falls back to the value here
    'device_model' => trim((string)setting('default_model')) ?: 'INTELSPEED™',
    'set_speed_kmh' => trim((string)setting('default_speed')) ?: '80',
    'technician' => trim((string)setting('default_technician')) ?: 'GAPTECH',
    'validity_months' => trim((string)setting('default_validity_months')) ?: '12',
];

$form = [
    'client_name' => '', 'client_phone' => '', 'client_address' => '',
    'reg_no' => '', 'make' => '', 'chassis_no' => '',
    'device_model' => $defaults['device_model'], 'serial_no' => '',
    'set_speed_kmh' => $defaults['set_speed_kmh'],
    'installed_on' => date('Y-m-d'), 'technician' => $defaults['technician'],
    'type' => 'Renewal', 'issue_date' => date('Y-m-d'),
    'validity_months' => $defaults['validity_months'],
    'expiry_date' => default_expiry(date('Y-m-d'), (int)$defaults['validity_months']),

    // Ticked when the governor is being taken off another lorry and fitted to
    // this one. See the note above the serial check below: the tick does not
    // switch the check off, it closes the other certificate.
    'moved' => '', 'moved_reason' => '',

    // Ticked when this chassis is on file under a different registration
    // because the lorry was re-plated, rather than because somebody mistyped.
    'replated' => '',

    // Why this lorry's governor is being changed for a different one. Asked for
    // only when the serial typed is not the one already fitted - see the swap
    // check below.
    'swap_reason' => '',
];

/** Fields that are typed in capitals, on screen and when saved. */
$upperFields = ['client_name', 'client_address', 'reg_no', 'make', 'chassis_no',
                'device_model', 'serial_no', 'technician'];

// Prefill from an existing vehicle: certificate_new.php?reg=KDK 017N
// Matched on letters and digits only, so KDK017N finds KDK 017N.
if (!empty($_GET['reg'])) {
    $v = q("SELECT v.*, c.name AS client_name, c.phone, c.address
            FROM vehicles v JOIN clients c ON c.id = v.client_id
            WHERE regexp_replace(upper(v.reg_no), '[^A-Z0-9]', '', 'g') = ?",
           [plate_key((string)$_GET['reg'])])->fetch();
    if ($v) {
        $form['client_name'] = $v['client_name'];
        $form['client_phone'] = $v['phone'];
        $form['client_address'] = $v['address'];
        $form['reg_no'] = $v['reg_no'];
        $form['make'] = $v['make'];
        $form['chassis_no'] = $v['chassis_no'];
        $last = q('SELECT d.*, i.installed_on
                   FROM installations i JOIN devices d ON d.id = i.device_id
                   WHERE i.vehicle_id = ? ORDER BY i.installed_on DESC LIMIT 1', [$v['id']])->fetch();
        if ($last) {
            $form['serial_no'] = $last['serial_no'];
            $form['installed_on'] = $last['installed_on'];
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

    // These are not on the form, so whatever was posted is ignored.
    $form['device_model'] = $defaults['device_model'];
    $form['set_speed_kmh'] = $defaults['set_speed_kmh'];
    $form['technician'] = $defaults['technician'];
    $form['validity_months'] = $defaults['validity_months'];
    if (strtotime((string)$form['issue_date']) !== false) {
        $form['expiry_date'] = default_expiry($form['issue_date'], (int)$form['validity_months']);
    }

    // Everything on the form is required.
    $labels = [
        'client_name' => 'Customer name', 'client_phone' => 'Contact', 'client_address' => 'Address',
        'reg_no' => 'Registration number', 'make' => 'Make', 'chassis_no' => 'Chassis number',
        'serial_no' => 'Serial number', 'installed_on' => 'Date installed',
        'type' => 'Service type', 'issue_date' => 'Issue date',
    ];
    foreach ($labels as $field => $label) {
        if ($form[$field] === '') {
            $errors[] = $label . ' is required.';
        }
    }
    foreach (['issue_date' => 'Issue date', 'installed_on' => 'Date installed'] as $field => $label) {
        if ($form[$field] !== '' && strtotime((string)$form[$field]) === false) {
            $errors[] = $label . ' is not a valid date.';
        }
    }
    if (!in_array($form['type'], ['Fitting', 'Renewal'], true)) {
        $errors[] = 'Choose fitting or renewal.';
    }
    if ($form['client_phone'] !== '' && !preg_match('/^[0-9+ ]+$/', $form['client_phone'])) {
        $errors[] = 'Contact may contain only digits, spaces and +.';
    }

    // ---------------------------------------------------------------------
    // The chassis number, which is the one thing about a lorry that cannot change
    // ---------------------------------------------------------------------
    // The same chassis under two registrations is one of two things:
    //
    //   a typo       a digit keyed wrong, and a certificate is about to be
    //                issued against the wrong vehicle
    //   a re-plate   the lorry really did get a new number plate, which does
    //                happen, and is what the tick is for
    //
    // Only this one refuses to save. The registration warnings below are told
    // to the user and recorded, but never block: every one of them has a
    // legitimate version, and a check that stops real work gets worked around.
    $replated = $form['replated'] !== '';
    $other    = null;

    if (!$errors && function_exists('chassis_on_file')) {
        $other = chassis_on_file($form['chassis_no'], $form['reg_no']);

        if ($other && !$replated) {
            $errors[] = 'Chassis ' . $form['chassis_no'] . ' is already on file as '
                . $other['reg_no'] . ' (' . trim((string)$other['client_name']) . ').'
                . ' Check the number first - one digit out is the usual cause. If this'
                . ' lorry really was re-plated, tick "Same lorry, new number plate" below.';
        }
    }

    // ---------------------------------------------------------------------
    // The lorry's old governor, when it is being changed for a different one
    // ---------------------------------------------------------------------
    // A unit fails and gets replaced. Nothing in the system noticed:
    // create_certificate() looks for an installation of THIS vehicle with THIS
    // device, finds none, and creates a new row - leaving the faulty unit's row
    // open forever. The lorry ends up with two governors recorded as fitted,
    // and no trace of why the first was swapped.
    //
    // There is no tick box here, because there is nothing ambiguous to confirm:
    // a different serial on a lorry that already has one IS a replacement. Only
    // the reason is missing, so only the reason is asked for.
    $swapping = null;

    if (!$errors && function_exists('vehicle_current_unit')) {
        $fitted = vehicle_current_unit($form['reg_no']);

        if ($fitted && strtoupper(trim((string)$fitted['serial_no']))
                    !== strtoupper(trim($form['serial_no']))) {
            $swapping = $fitted;

            if (trim($form['swap_reason']) === '') {
                $errors[] = $form['reg_no'] . ' currently has governor '
                    . $fitted['serial_no'] . ', fitted '
                    . fmt_date((string)$fitted['installed_on'])
                    . '. You have entered a different one, so the old unit is coming off.'
                    . ' Say why below - if the serial is simply mistyped, correct it instead.';
            }
        }
    }

    // ---------------------------------------------------------------------
    // One sealed governor, one lorry
    // ---------------------------------------------------------------------
    // This form had NO check on the serial number. Typing one that is already
    // fitted elsewhere did not warn and did not fail: create_certificate()
    // found the device row, moved it to this vehicle, and the other lorry went
    // on holding a valid certificate for a box that is no longer in it. Two
    // lorries certified as having one governor, and nothing on any screen
    // looking wrong.
    //
    // A renewal is NOT a clash - same box, same lorry, which is what makes it a
    // renewal - so the lorry being certified is passed in and its own
    // certificates are ignored.
    //
    // "Governor moved from another vehicle" does not switch this off. It does
    // the thing that actually happened in the yard: the box came off the other
    // lorry and nobody recorded it. Ticking it records that removal, through the
    // same remove_governor_unit() with the same reason and the same audit entry,
    // so the clash is genuinely gone rather than ignored.
    $toClose = null;

    if (!$errors && function_exists('governor_serial_in_use')) {
        // Fails closed: without the removal functions the tick box does nothing
        // and the block holds. The safe direction for a control whose job is to
        // prevent a silent double-booking.
        $moving = $form['moved'] !== '' && function_exists('remove_governor_unit');

        if ($moving && trim($form['moved_reason']) === '') {
            $errors[] = 'Say why the governor is being moved. It is recorded on the other '
                . 'certificate as the reason the unit came off.';
        }

        $held = governor_serial_in_use($form['serial_no'], null, $form['reg_no']);

        if ($held && ($held['approval_status'] ?? '') === 'pending') {
            // Nothing has been issued yet, so "still fitted" would be wrong. The
            // claim is real all the same, and it cannot be closed off from here:
            // that certificate is in the accounts queue and has to be approved or
            // sent back by a person. The block holds whether or not the box is
            // ticked.
            $errors[] = 'Governor ' . $form['serial_no'] . ' is already on a certificate '
                . 'waiting for approval, for ' . $held['reg_no'] . ' (raised '
                . fmt_date(substr((string)$held['issued_at'], 0, 10)) . ').'
                . ' That one has to be approved or sent back before this unit can be '
                . 'used somewhere else.';
        } elseif ($held && $moving && trim($form['moved_reason']) !== '') {
            $toClose = $held;
        } elseif ($held) {
            $errors[] = 'Governor ' . $form['serial_no'] . ' is still recorded as fitted to '
                . $held['reg_no'] . ' (certificate ' . $held['number'] . ', cover to '
                . fmt_date($held['expiry_date']) . ').'
                . ' If it has been moved to this vehicle, tick "Governor moved from another'
                . ' vehicle" below and say why, and that certificate will be closed off.'
                . ' Otherwise check the serial - a mistyped number looks exactly like this.';
        }
    }

    if (!$errors) {
        try {
            // The other certificate comes off FIRST, before this one is created.
            //
            // Not a style choice. create_certificate() opens its own transaction,
            // so the two cannot share one. Of the two orders, this is the one
            // whose half-done state is still true: if the save then fails, the
            // governor is recorded as off the other lorry - which it is - and the
            // next attempt finds no clash. The other way round leaves this
            // certificate issued while the old one still claims the box, which is
            // precisely the state being prevented.
            $closed = '';
            if ($toClose) {
                remove_governor_unit($toClose, $user, trim($form['moved_reason']),
                                     $form['installed_on']);
                $closed = 'certificate ' . $toClose['number'] . ' (' . $toClose['reg_no'] . ')';
            }

            // What was true about this lorry BEFORE the certificate was raised.
            // Read first, because create_certificate() updates the vehicle row -
            // afterwards the old chassis is gone and there is nothing to compare.
            $was = function_exists('plate_on_file') ? plate_on_file($form['reg_no']) : null;

            $notes = [];

            // A Fitting on a lorry whose cover is still running. Usually it
            // should have been a Renewal. Not blocked - a genuine re-fitting
            // after a unit was replaced is also a Fitting - but said out loud
            // and written to the audit log, because the old certificate is
            // superseded by this one and that is not obvious from the form.
            if ($was && $form['type'] === 'Fitting' && !empty($was['expiry_date'])
                && strtotime((string)$was['expiry_date']) >= strtotime(date('Y-m-d'))) {
                $notes[] = 'Note: ' . $form['reg_no'] . ' already had cover to '
                    . fmt_date((string)$was['expiry_date'])
                    . ' (certificate ' . $was['cert_number'] . '), which this one '
                    . 'supersedes. It was raised as a Fitting, not a Renewal.';
            }

            // The chassis on file for this same lorry has been changed by this
            // form. create_certificate() overwrites it without asking, so this
            // is the only trace that a vehicle's identity was rewritten.
            $typedCh = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $form['chassis_no']));
            $knownCh = $was ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '',
                                                      (string)$was['chassis_no'])) : '';
            if ($knownCh !== '' && $typedCh !== '' && $knownCh !== $typedCh) {
                $notes[] = 'Note: the chassis on file for ' . $form['reg_no'] . ' was '
                    . $was['chassis_no'] . ' and is now ' . $form['chassis_no'] . '.';
            }

            // The old unit comes off before the new certificate is created, so
            // its installation row is closed whatever happens next. Same
            // reasoning as the governor move above: of the two half-done states,
            // "the faulty box is recorded as off" is the true one.
            if ($swapping) {
                swap_governor_unit($swapping, $user, trim($form['swap_reason']),
                                   $form['installed_on'], $form['serial_no']);
                $notes[] = 'Note: governor ' . $swapping['serial_no'] . ' came off '
                    . $form['reg_no'] . ' on ' . fmt_date($form['installed_on'])
                    . ' and ' . $form['serial_no'] . ' went on.';
            }

            $cert = create_certificate($form, $user);

            foreach ($notes as $note) {
                audit('certificate_warning', 'certificate', (int)$cert['id'], ['note' => $note]);
            }
            if ($replated && !empty($other)) {
                // Who allowed a chassis onto a second registration, and off which
                // one. No reason is asked for: unlike a removal, nothing here is
                // printed on anybody's certificate, and these four facts are the
                // whole of what a later reader needs.
                audit('chassis_replate', 'certificate', (int)$cert['id'], [
                    'chassis_no'    => $form['chassis_no'],
                    'new_reg'       => $form['reg_no'],
                    'previous_reg'  => $other['reg_no'],
                    'previous_owner'=> trim((string)$other['client_name']),
                ]);
                $notes[] = 'Note: chassis ' . $form['chassis_no'] . ' was on file as '
                    . $other['reg_no'] . ', recorded as a re-plate.';
            }

            flash('Certificate for ' . $form['reg_no'] . ' sent to accounts for approval. '
                . 'It takes its number once they confirm the payment.'
                . ($notes ? ' ' . implode(' ', $notes) : '')
                // Said out loud, because closing another customer's certificate is
                // not a side effect anybody should discover later.
                . ($closed
                    ? ' The governor was taken off ' . $closed . ', and that cover ended '
                      . fmt_date($form['installed_on']) . '.'
                    : ''));
            redirect('certificate_view.php?id=' . $cert['id']);
        } catch (Throwable $err) {
            $errors[] = 'Could not save: ' . $err->getMessage();

            // If the other certificate was already closed, say so. Otherwise the
            // next attempt succeeds, the clash is mysteriously gone, and nobody
            // knows another customer's cover ended in a save that reported failure.
            if ($closed !== '') {
                $errors[] = 'The governor had already been taken off ' . $closed
                    . ' before this failed, and that stands - it is where the unit '
                    . 'actually is that matters. Fix the problem above and save again; '
                    . 'do not tick "Governor moved from another vehicle" the second time.';
            }
        }
    }
}

/** One required text field. */
function field(string $name, string $label, string $value, array $opts = []): void
{
    $type = $opts['type'] ?? 'text';
    $caps = !empty($opts['caps']);

    // Required unless the call says otherwise - nearly every field here is.
    // A label that says "optional" is never required whatever the call passed,
    // because a field cannot be allowed to show the word and the star together
    // and then refuse to submit.
    $req = !array_key_exists('required', $opts) || $opts['required'];
    if (stripos($label, 'optional') !== false) {
        $req = false;
    }

    echo '<label class="field">';
    echo e($label) . ($req ? ' <span class="req">*</span>' : '');
    echo '<span class="field-row">';
    echo '<input name="' . e($name) . '" type="' . e($type) . '" value="' . e($value) . '"'
        . ($req ? ' required' : '')
        . ($caps ? ' class="caps"' : '')
        . (isset($opts['maxlength']) ? ' maxlength="' . (int)$opts['maxlength'] . '"' : '')
        . (isset($opts['placeholder']) ? ' placeholder="' . e($opts['placeholder']) . '"' : '')
        . (isset($opts['min']) ? ' min="' . e((string)$opts['min']) . '"' : '')
        . (isset($opts['max']) ? ' max="' . e((string)$opts['max']) . '"' : '')
        . '>';
    echo '</span>';
    if (!empty($opts['hint'])) { echo '<span class="field-hint">' . e($opts['hint']) . '</span>'; }
    echo '</label>';
}

layout_top('New certificate');
layout_back('index.php', 'Back to certificates');
?>
<h1>New Certificate</h1>
<p class="hint">All fields are required. The governor model, set speed, technician and expiry date are
filled in from Settings. The certificate goes to accounts when you save, and takes its number once
they confirm the payment.</p>

<?php foreach ($errors as $msg): ?><p class="error"><?= e($msg) ?></p><?php endforeach; ?>

<form method="post" class="card-form" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

  <fieldset>
    <legend>Customer</legend>
    <?php field('client_name', 'Customer name', $form['client_name'], ['caps' => true]); ?>
    <label class="field">Contact <span class="req">*</span>
      <span class="field-row">
        <input name="client_phone" type="tel" value="<?= e($form['client_phone']) ?>" required
               inputmode="tel" pattern="[0-9+ ]+" maxlength="20" title="Digits, spaces and + only">
      </span>
    </label>
    <?php field('client_address', 'Address', $form['client_address'], ['caps' => true]); ?>
  </fieldset>

  <fieldset>
    <legend>Vehicle</legend>
    <?php field('reg_no', 'Registration no.', $form['reg_no'], ['caps' => true]); ?>
    <?php field('make', 'Make', $form['make'], ['caps' => true]); ?>
    <?php field('chassis_no', 'Chassis no.', $form['chassis_no'], ['caps' => true]); ?>

    <?php /*
      Shown only when it is needed. A tick box that sits there on every form is
      a tick box somebody eventually ticks out of habit; this one appears when
      the chassis actually clashes, and the server has already refused by then.
    */ ?>
    <label class="field" id="replate-box-wrap" style="font-weight:400;
           <?= $other || $form['replated'] !== '' ? '' : 'display:none' ?>">
      <input type="checkbox" name="replated" value="1" id="replate-box"
             <?= $form['replated'] !== '' ? 'checked' : '' ?>>
      Same lorry, new number plate
      <span class="field-hint">Only tick this if the chassis below really does belong to
        this lorry and the registration has changed. A chassis number cannot change, so
        the same one on two plates is otherwise a typing slip.</span>
    </label>
  </fieldset>

  <fieldset>
    <legend>Speed governor</legend>
    <?php field('serial_no', 'Serial no.', $form['serial_no'], ['caps' => true]); ?>
    <?php field('installed_on', 'Date installed', $form['installed_on'], ['type' => 'date']); ?>

    <?php /*
      The way past a governor that is still on another lorry's certificate.

      Worded as what happened to the box, not as what it does to the form.
      "Allow an existing serial" invites a tick from anybody who just wants the
      error to go away; "this governor has been moved" is a claim about a lorry,
      and it is only true sometimes.
    */ ?>
    <label class="field" style="font-weight:400">
      <input type="checkbox" name="moved" value="1" id="moved-box"
             <?= $form['moved'] !== '' ? 'checked' : '' ?>>
      Governor moved from another vehicle
      <span class="field-hint">Tick this if the unit is already on a certificate for a
        different lorry because it has been taken off that one. Its certificate is closed
        off on the installation date above, and that lorry stops being covered.</span>
    </label>

    <?php /*
      Shown only when the lorry already has a different governor on it. No tick
      box: a different serial on a lorry that already has one IS a replacement,
      so there is nothing to confirm - only the reason is missing.
    */ ?>
    <div id="swap-why" style="<?= $swapping || $form['swap_reason'] !== '' ? '' : 'display:none' ?>">
      <span id="swap-note" class="field-hint" style="display:block; margin-bottom:6px"><?php
        if ($swapping) {
            echo e($form['reg_no'] . ' currently has ' . $swapping['serial_no'] . ', fitted '
                   . fmt_date((string)$swapping['installed_on'])
                   . '. It will be recorded as coming off on the installation date.');
        }
      ?></span>
      <?php field('swap_reason', 'Why the governor is being replaced',
            $form['swap_reason'],
            ['required' => false, 'maxlength' => 200,
             'placeholder' => 'Old unit faulty, replaced under warranty',
             'hint' => 'The old unit is recorded as coming off this lorry on the '
                     . 'installation date above.']); ?>
    </div>

    <div id="moved-why" style="<?= $form['moved'] !== '' ? '' : 'display:none' ?>">
      <?php field('moved_reason', 'Why it was moved', $form['moved_reason'],
            ['required' => false, 'maxlength' => 200,
             'placeholder' => 'Vehicle scrapped, governor refitted to this one',
             'hint' => 'Recorded on the other certificate as the removal reason. In six '
                     . 'months this is the only thing that tells a moved governor from a '
                     . 'mistyped serial.']); ?>
    </div>
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
  </fieldset>

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

// Contact: digits, spaces and + only, with a message when something else is typed.
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

// "Is this governor free?" - asked while the serial is being typed, so nobody
// fills in a whole form before being told the box is in another lorry.
//
// A hint only. serial_check.php answers it and the save asks the same question
// again through the same function; anything that runs only in a browser is a
// courtesy, never the rule.
(function () {
  var box = document.querySelector('[name=serial_no]');
  var reg = document.querySelector('[name=reg_no]');
  if (!box || !window.fetch) { return; }

  // The tick box lives out here because the note has to be repainted when it
  // changes: the same clash reads differently once somebody has said the unit
  // was moved.
  var moved    = document.getElementById('moved-box');
  var movedWhy = document.getElementById('moved-why');

  var note = document.createElement('span');
  note.className = 'field-hint';
  note.style.cssText = 'display:none; font-size:12.5px; line-height:1.5; margin-top:5px';
  (box.closest('.field') || box.parentNode).appendChild(note);

  var timer = null;
  var seq   = 0;                        // only the newest answer may paint

  function show(kind, html) {
    note.style.display = 'block';
    note.style.color   = kind === 'bad' ? '#C3121A' : (kind === 'warn' ? '#8A5A00' : '#4A7A18');
    note.innerHTML     = html;
  }
  function hide() { note.style.display = 'none'; note.textContent = ''; }

  function ask() {
    var value = box.value.trim();
    if (value.length < 4) { hide(); return; }

    var mine = ++seq;
    // The lorry goes too, so a RENEWAL does not report itself as a clash.
    fetch('serial_check.php?serial=' + encodeURIComponent(value)
            + '&reg=' + encodeURIComponent(reg ? reg.value.trim() : ''),
          { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || mine !== seq) { return; }        // a later keystroke already won

        if (d.state === 'pending') {
          show('bad',
            '<strong>Already on a certificate awaiting approval</strong> for '
            + d.vehicle + ' (' + (d.client || '') + '), raised ' + d.raised_on + '. '
            + '<a href="certificate_view.php?id=' + d.cert_id + '" target="_blank">Open it</a>'
            + ' &mdash; it has to be approved or sent back before this unit can be used here.');
        } else if (d.state === 'in_use') {
          // The only clash the tick box can resolve, so it is only mentioned
          // here - not on a pending one, where the server refuses either way.
          show(moved && moved.checked ? 'warn' : 'bad',
            '<strong>Still fitted to ' + d.vehicle + '</strong> (' + (d.client || '')
            + '), certificate <a href="certificate_view.php?id=' + d.cert_id
            + '" target="_blank">' + d.label + '</a>, cover to ' + d.expires + '.<br>'
            + (moved && moved.checked
                ? 'That certificate will be closed off when this one is saved, and '
                  + d.vehicle + ' stops being covered.'
                : 'If it has been moved here, tick <strong>Governor moved from another '
                  + 'vehicle</strong> below. Otherwise check the serial.'));
        } else if (d.state === 'returning') {
          show('warn',
            'Recovered unit &mdash; last fitted to <strong>' + d.vehicle + '</strong> ('
            + (d.client || '') + '), removed ' + d.removed_on
            + (d.reason ? ': ' + d.reason : '') + '. Free to fit.');
        } else if (d.state === 'free') {
          show('ok', d.seen > 0 ? 'Free to fit.' : 'New unit — not seen before.');
        } else {
          hide();                       // 'short' and 'unknown' say nothing
        }
      })
      .catch(function () { hide(); });  // the save still checks
  }

  if (moved) {
    moved.addEventListener('change', function () {
      if (movedWhy) { movedWhy.style.display = moved.checked ? '' : 'none'; }
      ask();
    });
  }

  box.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(ask, 350);       // after typing stops, not per keystroke
  });
  box.addEventListener('blur', ask);
  if (reg) { reg.addEventListener('blur', ask); }   // a renewal stops clashing
})();

// What do we already know about this lorry?
//
// The registration and the chassis go to the server TOGETHER, because the two
// answers depend on each other: a chassis is only a clash if it belongs to a
// different registration. Asking separately would let the page show a
// contradiction while the second answer was still in flight.
//
// A hint only. The save checks the chassis again through the same function.
(function () {
  var reg     = document.querySelector('[name=reg_no]');
  var chassis = document.querySelector('[name=chassis_no]');
  var type    = document.querySelector('[name=type]');
  var wrap    = document.getElementById('replate-box-wrap');
  var tick    = document.getElementById('replate-box');
  if (!reg || !chassis || !window.fetch) { return; }

  // One note per field, kept ON the field.
  //
  // This used to look the note up with input.parentNode.querySelector(), which
  // searches inside the <span class="field-row"> that wraps the input - while
  // the note is appended to the <label class="field"> ABOVE that span. So it
  // never found the note it had just made, and added another one on every
  // keystroke: seven copies of "No certificate in force", four of the chassis
  // warning, the stale ones still showing after the text stopped being true.
  //
  // Holding the reference on the element cannot drift from where the note was
  // actually put, whatever the markup around it does later.
  function noteFor(input) {
    if (!input._liveNote || !input._liveNote.isConnected) {
      var n = document.createElement('span');
      n.className = 'live-note field-hint';
      n.style.cssText = 'display:none; font-size:12.5px; line-height:1.5; margin-top:5px';
      (input.closest('.field') || input.parentNode).appendChild(n);
      input._liveNote = n;
    }
    return input._liveNote;
  }
  function paint(input, kind, html) {
    var n = noteFor(input);
    if (!html) { n.style.display = 'none'; n.innerHTML = ''; return; }
    n.style.display = 'block';
    n.style.color   = kind === 'bad' ? '#C3121A' : (kind === 'warn' ? '#8A5A00' : '#4A7A18');
    n.innerHTML     = html;
  }

  var timer = null, seq = 0;

  function ask() {
    var r = reg.value.trim(), c = chassis.value.trim();
    var serialBox = document.querySelector('[name=serial_no]');
    var s = serialBox ? serialBox.value.trim() : '';
    if (r.replace(/[^A-Za-z0-9]/g, '').length < 5) { paint(reg, '', ''); }

    var mine = ++seq;
    // The serial goes too: whether the lorry's governor is being replaced
    // depends on all three values, so they are answered in one request.
    fetch('vehicle_check.php?reg=' + encodeURIComponent(r)
            + '&chassis=' + encodeURIComponent(c)
            + '&serial=' + encodeURIComponent(s), { credentials: 'same-origin' })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (d) {
        if (!d || mine !== seq) { return; }        // a later keystroke already won

        // ---- the registration
        if (!d.plate && d.plate !== undefined) {
          paint(reg, 'ok', 'New vehicle — not certified before.');
        } else if (d.plate) {
          var who = d.plate.client ? '<strong>' + d.plate.client + '</strong>' : 'on file';
          var bits = [who];
          if (d.plate.make) { bits.push(d.plate.make); }

          if (d.plate.covered) {
            // Raising a Fitting over live cover is usually a Renewal typed in
            // the wrong box, so the dropdown is named rather than hinted at.
            var fitting = type && type.value === 'Fitting';
            paint(reg, fitting ? 'warn' : 'ok',
              bits.join(' &middot; ') + '<br>Covered to ' + d.plate.expires
              + ' by certificate <a href="certificate_view.php?id=' + d.plate.cert_id
              + '" target="_blank">' + d.plate.number + '</a>'
              + (fitting ? '. <strong>Service type is Fitting</strong> — if this is the '
                         + 'yearly renewal, change it to Renewal.' : '.'));
          } else if (d.plate.number) {
            paint(reg, 'ok', bits.join(' &middot; ') + '<br>Last cover ended '
                             + d.plate.expires + '. A renewal starts here.');
          } else {
            paint(reg, 'ok', bits.join(' &middot; ') + '<br>No certificate in force.');
          }
        }

        // ---- the governor already on this lorry
        //
        // Painted inside the reason box, not onto the serial field: that field
        // already carries the is-this-unit-free note, and two notes under one
        // input read as the page arguing with itself.
        var swapWhy  = document.getElementById('swap-why');
        var swapNote = document.getElementById('swap-note');
        var swapIn   = swapWhy ? swapWhy.querySelector('input') : null;

        if (d.fitted && d.fitted.being_replaced) {
          if (swapNote) {
            swapNote.style.color = '#8A5A00';
            swapNote.innerHTML = '<strong>' + (d.plate ? d.plate.reg_no : 'This lorry')
              + ' currently has ' + d.fitted.serial_no + '</strong>, fitted '
              + d.fitted.installed_on + '. It will be recorded as coming off on the '
              + 'installation date. If the serial above is simply mistyped, correct it '
              + 'instead of filling this in.';
          }
          if (swapWhy) { swapWhy.style.display = ''; }
        } else if (swapWhy && !(swapIn && swapIn.value.trim())) {
          swapWhy.style.display = 'none';
        }

        // ---- the chassis
        if (d.chassis) {
          paint(chassis, 'bad',
            '<strong>Already on file as ' + d.chassis.reg_no + '</strong>'
            + (d.chassis.client ? ' (' + d.chassis.client + ')' : '')
            + '. Check the number first - one digit out is the usual cause. If this '
            + 'lorry really was re-plated, tick "Same lorry, new number plate" below.');
          if (wrap) { wrap.style.display = ''; }
        } else if (d.plate && d.plate.chassis_differs) {
          paint(chassis, 'warn',
            'On file for ' + d.plate.reg_no + ' as <strong>' + d.plate.chassis_no
            + '</strong>. Saving will change it to what is typed here.');
          if (wrap && !(tick && tick.checked)) { wrap.style.display = 'none'; }
        } else {
          paint(chassis, '', '');
          if (wrap && !(tick && tick.checked)) { wrap.style.display = 'none'; }
        }
      })
      .catch(function () { /* the save still checks */ });
  }

  function later() { clearTimeout(timer); timer = setTimeout(ask, 350); }

  reg.addEventListener('input', later);
  chassis.addEventListener('input', later);
  reg.addEventListener('blur', ask);
  chassis.addEventListener('blur', ask);
  if (type) { type.addEventListener('change', ask); }   // Fitting vs Renewal changes the advice

  // The serial decides whether this is a unit replacement, so it has to re-ask.
  var serialBox = document.querySelector('[name=serial_no]');
  if (serialBox) {
    serialBox.addEventListener('input', later);
    serialBox.addEventListener('blur', ask);
  }
})();
</script>
<?php layout_bottom(); ?>