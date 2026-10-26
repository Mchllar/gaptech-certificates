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

    // One name, kept in both places. client_name is still what the lists, the
    // dashboard and the renewal dialog read, and the column is NOT NULL, so it is
    // filled from the owner rather than dropped - no migration, and nothing
    // downstream has to learn a new field.
    $form['client_name'] = $form['ownership'];

    // The expiry is worked out, never typed: the chosen term, ending the day before
    // the anniversary. A one-year subscription bought on 23/09/2026 runs to 22/09/2027.
    $months = (int)$form['term_months'];
    if (!in_array($months, [12, 24, 36, 48, 60], true)) {
        $months = 12;
        $form['term_months'] = '12';
    }
    // The financier's agreement, if there is one, and only on a first installation.
    // Worked out here rather than trusted from the form: the expiry is printed on
    // the certificate and frozen in its snapshot, so it is not a number the browser
    // gets to decide.
    $bonusMonths = trk_is_first_installation($form['reg_no'])
        ? trk_financier_bonus($form['financier'])
        : 0;

    if (strtotime((string)$form['subscription_date']) !== false) {
        $form['expiry_date'] = default_expiry($form['subscription_date'], $months + $bonusMonths);
    }

    $labels = [
        // No 'client_name' here: it has no box of its own any more, and a required
        // field with nowhere to type it is an error nobody can clear.
        'client_phone' => 'Contact',
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

    // A tracker cannot be in two vehicles at once.
    //
    // GAPTECH refits recovered units, so the same IMEI appearing on a later
    // certificate is normal and expected. What is not normal is it appearing on a
    // later certificate while an EARLIER one still says the unit is in service:
    // that is either the wrong IMEI typed, or a removal nobody recorded. Both end
    // with two vehicles counted as covered by one box, which is almost impossible
    // to spot afterwards.
    //
    // So it blocks rather than warns, and says exactly which certificate to go and
    // close off first.
    if (!$errors && function_exists('tracking_imei_in_use')) {
        foreach ([$form['imei'], $form['imei_2']] as $slot) {
            $held = $slot !== '' ? tracking_imei_in_use($slot) : null;
            if ($held && ($held['approval_status'] ?? '') === 'pending') {
                // Nothing has been issued yet, so "still fitted" would be wrong.
                // The claim is real all the same: approve both and two vehicles
                // end up holding one box.
                $errors[] = 'Unit ' . $slot . ' is already on a certificate waiting for '
                    . 'approval, for ' . $held['reg_no'] . ' (raised '
                    . fmt_date(substr((string)$held['issued_at'], 0, 10)) . ').'
                    . ' That one has to be approved or sent back before this unit can be '
                    . 'used somewhere else.';
            } elseif ($held) {
                $errors[] = 'Unit ' . $slot . ' is still recorded as fitted to '
                    . $held['reg_no'] . ' (certificate ' . tracking_label($held) . ').'
                    . ' Record its removal on that certificate first, then raise this one.';
            }
        }
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
            // Why this certificate runs longer than the term that was chosen. The
            // expiry itself is in the snapshot; this is the reason behind it, so
            // nobody reading the record in a year has to guess.
            if ($bonusMonths > 0) {
                audit('financier_bonus', 'tracking_certificate', (int)$cert['id'], [
                    'financier' => $form['financier'],
                    'term'      => $months,
                    'bonus'     => $bonusMonths,
                    'expiry'    => $form['expiry_date'],
                ]);
            }

            flash('Installation certificate for ' . $form['reg_no'] . ' sent to accounts for '
                . 'approval'
                . ($bonusMonths > 0
                    ? ', with ' . $bonusMonths . ' extra months under the '
                      . $form['financier'] . ' agreement (expires '
                      . fmt_date($form['expiry_date']) . ')'
                    : '')
                . '. It takes its serial number once they confirm the payment.');
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
        // A datalist is a dropdown you can also type straight past: the suggestions
        // are offered, never enforced. autocomplete="off" sits on the form to stop
        // the browser filling in names and phone numbers, but it also suppresses
        // the datalist popup in some browsers - so a field that has a list says on.
        . (isset($opts['list']) ? ' list="' . e($opts['list']) . '" autocomplete="on"' : '')
        . '></span>';
    if (!empty($opts['hint'])) { echo '<span class="field-hint">' . e($opts['hint']) . '</span>'; }
    echo '</label>';
}

/**
 * Extra months a financier's agreement adds to a FIRST installation.
 *
 * KCB and GAPTECH have an arrangement that gives KCB-financed vehicles three
 * months on top. It lives in the `financier_bonus` setting rather than in this
 * file, written as NAME:MONTHS, comma separated:
 *
 *     KENYA COMMERCIAL BANK:3, KCB:3
 *
 * Both spellings are listed because either may be typed. Commercial agreements
 * end, and when this one does it is a line in Settings, not a code change and a
 * deployment - clear the setting and new certificates go back to the plain term.
 *
 * Matching ignores case, punctuation and spacing, so "Kenya Commercial Bank",
 * "KENYA COMMERCIAL BANK" and "K.C.B." all find their entry.
 */
function trk_financier_bonus(string $financier): int
{
    $key = static fn(string $s): string =>
        preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim($s)));

    $want = $key($financier);
    if ($want === '') {
        return 0;
    }

    foreach (explode(',', (string)setting('financier_bonus', 'KENYA COMMERCIAL BANK:3, KCB:3')) as $pair) {
        $bits = explode(':', $pair);
        if (count($bits) !== 2) {
            continue;
        }
        if ($key($bits[0]) === $want) {
            return max(0, min(24, (int)trim($bits[1])));   // a sane ceiling, not a free hand
        }
    }

    return 0;
}

/**
 * Has this vehicle ever had a tracking certificate before?
 *
 * The agreement is a sign-up benefit, so it applies to a first installation only.
 * A rejected certificate does not count as a previous one - it was never issued -
 * but a voided or expired one does: the vehicle was signed up, whatever happened
 * afterwards.
 *
 * Checked on the server at save time, not taken from the form, because this
 * decides a date that gets printed.
 */
function trk_is_first_installation(string $reg): bool
{
    if (trim($reg) === '') {
        return true;
    }
    $seen = q("SELECT 1 FROM tracking_certificates
                WHERE regexp_replace(upper(reg_no), '[^A-Z0-9]', '', 'g') = ?
                  AND approval_status <> 'rejected'
                LIMIT 1", [plate_key($reg)])->fetchColumn();

    return $seen === false;
}

/**
 * The financiers to suggest.
 *
 * Two sources, so the list looks after itself:
 *
 *   - the regulars, from the tracking_financiers setting, so they can be changed
 *     in Settings rather than in this file;
 *   - every financier already used on a certificate, commonest first, so a bank
 *     typed once in January is waiting in the dropdown in February.
 *
 * Suggestions only. Anything can still be typed, and the field can still be left
 * blank - the certificate prints N/A.
 */
function trk_financiers(): array
{
    $regulars = array_filter(array_map(
        static fn($s) => mb_strtoupper(trim($s)),
        explode(',', (string)setting('tracking_financiers', 'HFCB BANK, KENYA COMMERCIAL BANK'))));

    $used = [];
    try {
        $used = q("SELECT financier
                     FROM tracking_certificates
                    WHERE trim(financier) <> ''
                    GROUP BY financier
                    ORDER BY count(*) DESC, financier")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $ignored) {
        // A dropdown is a convenience. If the query fails the field still works.
    }

    $seen = [];
    foreach (array_merge($regulars, array_map('mb_strtoupper', $used)) as $name) {
        $name = trim((string)$name);
        if ($name !== '') { $seen[$name] = true; }
    }

    return array_keys($seen);
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
    <?php /*
      There used to be a "Customer name" box above this one. It asked for the same
      name twice: the certificate prints Ownership Details and never a client name,
      so whatever was typed as the customer appeared nowhere on the paper - and the
      two could disagree, which on a certificate is worse than either being wrong.
      Ownership is now the one name, and it fills the customer field behind the
      scenes so the lists, the dashboard and the renewal dialog still read the same.
    */ ?>
    <?php trk_input('ownership', 'Ownership details', $form['ownership'],
          ['caps' => true,
           'hint' => 'The registered owner, exactly as it should read on the certificate. '
                   . 'This is the name the lists and the dashboard use too.']); ?>
    <?php trk_input('client_phone', 'Contact', $form['client_phone'],
          ['type' => 'tel', 'inputmode' => 'tel', 'maxlength' => 20]); ?>
    <?php trk_input('financier', 'Financier', $form['financier'],
          ['caps' => true, 'required' => false, 'list' => 'financier-list',
           'hint' => 'Pick one or type another. Leave blank if there is none; the certificate prints N/A.']); ?>
    <datalist id="financier-list">
      <?php foreach (trk_financiers() as $bank): ?>
        <option value="<?= e($bank) ?>"></option>
      <?php endforeach; ?>
    </datalist>
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
          ['maxlength' => 32, 'caps' => true,
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
                          48 => '4 years', 60 => '5 years'] as $m => $text): ?>
            <option value="<?= $m ?>" <?= (int)$form['term_months'] === $m ? 'selected' : '' ?>>
              <?= e($text) ?></option>
          <?php endforeach; ?>
        </select>
      </span>
    </label>
    <?php
      // What the page should show before anything is submitted. Recomputed on the
      // server on save, so this is a preview, not the decision.
      $viewFirst = trk_is_first_installation($form['reg_no']);
      $viewBonus = $viewFirst ? trk_financier_bonus($form['financier']) : 0;
    ?>
    <label class="field">Expires
      <span class="field-row">
        <input id="expiry-shown" value="<?= e(fmt_date($form['expiry_date'])) ?>" disabled>
      </span>
      <span class="field-hint">Worked out for you, from the date and the period.</span>
    </label>

    <p class="hint bonus-note" style="text-align:left; grid-column:1/-1; margin:0;
       color:#4A7A18; <?= $viewBonus > 0 ? '' : 'display:none' ?>">
      Includes <strong><span class="bonus-months"><?= (int)$viewBonus ?></span> extra
      months</strong> under the financier&rsquo;s agreement with GAPTECH. First
      installation only &mdash; a renewal runs for the term you choose.
    </p>
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
  var fin   = document.querySelector('[name=financier]');
  var note  = document.querySelector('.bonus-note');
  var count = document.querySelector('.bonus-months');
  if (!subs || !term || !shown) { return; }

  // Which financiers add months, and how many. Written out by the server from the
  // same setting the server uses on save, so the two cannot drift apart.
  var bonuses = <?= json_encode(array_reduce(
        array_filter(explode(',', (string)setting('financier_bonus', 'KENYA COMMERCIAL BANK:3, KCB:3'))),
        static function (array $carry, string $pair): array {
            $bits = explode(':', $pair);
            if (count($bits) === 2) {
                $carry[preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim($bits[0])))] = (int)trim($bits[1]);
            }
            return $carry;
        }, []), JSON_UNESCAPED_SLASHES) ?>;

  // Only a first installation earns the extra months, and only the server can say
  // whether this is one. It decides again on save; this just keeps the preview
  // honest rather than promising a date that will not be issued.
  var firstInstall = <?= $viewFirst ? 'true' : 'false' ?>;

  var months = ['January','February','March','April','May','June',
                'July','August','September','October','November','December'];

  function bonusNow() {
    if (!firstInstall || !fin) { return 0; }
    var key = (fin.value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    return bonuses[key] || 0;
  }

  function recalc() {
    var parts = subs.value.split('-');
    if (parts.length !== 3) { return; }
    var d = new Date(Date.UTC(+parts[0], +parts[1] - 1, +parts[2]));
    if (isNaN(d.getTime())) { return; }

    var extra = bonusNow();
    d.setUTCMonth(d.getUTCMonth() + parseInt(term.value, 10) + extra);
    d.setUTCDate(d.getUTCDate() - 1);
    shown.value = String(d.getUTCDate()).padStart(2, '0') + ' '
                + months[d.getUTCMonth()] + ' ' + d.getUTCFullYear();

    if (note) {
      note.style.display = extra > 0 ? '' : 'none';
      if (count) { count.textContent = String(extra); }
    }
  }

  subs.addEventListener('change', recalc);
  term.addEventListener('change', recalc);
  if (fin) {
    fin.addEventListener('input', recalc);
    fin.addEventListener('change', recalc);
  }
})();

// ---------------------------------------------------------------------------
// Is this unit free? Asked while the IMEI is still being typed.
//
// The save checks the same thing again, through the same function. This is here
// so nobody fills in a whole form before being told the unit is still in another
// vehicle - it is not what decides whether the certificate may be raised.
// ---------------------------------------------------------------------------
(function () {
  var boxes = [document.querySelector('[name=imei]'), document.querySelector('[name=imei_2]')]
                .filter(Boolean);
  if (!boxes.length || !window.fetch) { return; }

  boxes.forEach(function (box) {
    var note = document.createElement('span');
    note.className = 'imei-note';
    note.style.cssText = 'display:none; font-size:12.5px; line-height:1.5; margin-top:5px';
    box.closest('.field').appendChild(note);

    var timer = null;
    var seq   = 0;            // only the newest answer may paint

    function show(kind, html) {
      note.style.display = 'block';
      note.style.color   = kind === 'bad' ? '#C3121A' : (kind === 'warn' ? '#8A5A00' : '#4A7A18');
      note.innerHTML     = html;
    }
    function hide() { note.style.display = 'none'; note.textContent = ''; }

    function ask() {
      var value = box.value.trim();
      if (value.length < 10) { hide(); return; }

      // The other box, checked here rather than on the server: two units with the
      // same number is a typo, not a clash with another vehicle.
      var other = boxes.filter(function (b) { return b !== box; })[0];
      if (other && other.value.trim() === value) {
        show('bad', 'Both boxes have the same number. The second unit has its own.');
        return;
      }

      var mine = ++seq;
      fetch('imei_check.php?imei=' + encodeURIComponent(value), { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d || mine !== seq) { return; }      // a later keystroke already won

          if (d.state === 'pending') {
            show('bad',
              '<strong>Already on a certificate awaiting approval</strong> for '
              + d.vehicle + ' (' + (d.client || '') + '), raised ' + d.raised_on + '. '
              + '<a href="tracking_view.php?id=' + d.cert_id + '" target="_blank">Open it</a>'
              + ' &mdash; it has to be approved or sent back before this unit can be used here.');
          } else if (d.state === 'in_use') {
            show('bad',
              '<strong>Still fitted to ' + d.vehicle + '</strong> ('
              + (d.client || '') + '), certificate '
              + '<a href="tracking_view.php?id=' + d.cert_id + '" target="_blank">'
              + d.label + '</a>, cover to ' + d.expires + '.<br>'
              + 'Record its removal on that certificate first.');
          } else if (d.state === 'returning') {
            show('warn',
              'Recovered unit &mdash; last fitted to <strong>' + d.vehicle + '</strong> ('
              + (d.client || '') + '), removed ' + d.removed_on
              + (d.reason ? ': ' + d.reason : '') + '. Free to fit.');
          } else if (d.state === 'free') {
            show('ok', d.seen > 0 ? 'Free to fit.' : 'New unit — not seen before.');
          } else {
            hide();
          }
        })
        .catch(function () { hide(); });          // the save still checks
    }

    box.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(ask, 350);              // after typing stops, not per keystroke
    });
    box.addEventListener('blur', ask);
  });
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