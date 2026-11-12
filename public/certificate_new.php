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
require_once APP_DIR . '/batches.php';          // bulk certificates

$user = require_admin();
$errors = [];

$defaults = [
    // trim() and ?: so a setting saved as blank still falls back to the value here
    'device_model' => trim((string)setting('default_model')) ?: 'INTELSPEED™',
    'set_speed_kmh' => trim((string)setting('default_speed')) ?: '80',
    'technician' => trim((string)setting('default_technician')) ?: 'GAPTECH',
    'validity_months' => trim((string)setting('default_validity_months')) ?: '12',
];

// ---------------------------------------------------------------------------
// A WHOLE FLEET, on this same page
// ---------------------------------------------------------------------------
// Two small answers this page gives about itself, before anything is printed,
// and a POST branch of its own further down. None of it touches the
// single-vehicle flow - that code is exactly as it was.
// ---------------------------------------------------------------------------

// The spreadsheet to fill in. With ?client= it comes down with that customer's
// vehicles already in it, so a renewal only needs the fitting dates and nobody
// retypes a registration that is already on file.
if (isset($_GET['template'])) {
    $cid  = (int)($_GET['client'] ?? 0);
    $who  = $cid ? q('SELECT * FROM clients WHERE id = ?', [$cid])->fetch() : null;
    $rows = $who ? q("SELECT v.reg_no, v.make, v.chassis_no, d.model AS device_model,
                             d.serial_no, d.set_speed_kmh, i.technician
                        FROM vehicles v
                        LEFT JOIN installations i ON i.vehicle_id = v.id AND i.removed_on IS NULL
                        LEFT JOIN devices d ON d.id = i.device_id
                       WHERE v.client_id = ? ORDER BY v.reg_no", [$cid])->fetchAll()
                 : [];

    $name = $who ? preg_replace('/[^A-Za-z0-9]+/', '-', trim($who['name'])) : 'fleet';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="gaptech-'
         . strtolower(trim($name, '-')) . '-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");          // or Excel mangles accented names
    // Only what actually differs from one lorry to the next.
    //
    // The governor model, set speed, technician and validity are the same on
    // every certificate GAPTECH issues and already come from Settings - the
    // single form does not ask for them either. Four columns of the same value
    // repeated forty times is four columns somebody can get wrong.
    fputcsv($out, ['Reg No', 'Make', 'Chassis No', 'Serial No', 'Installed On']);
    if ($rows) {
        foreach ($rows as $r) {
            fputcsv($out, [$r['reg_no'], $r['make'], $r['chassis_no'],
                           $r['serial_no'] ?: '', '']);
        }
    } else {
        fputcsv($out, ['KBZ 411T', 'Isuzu FRR', 'JAANPR75L07100123',
                       '8612340001', date('Y-m-d')]);
    }
    fclose($out);
    exit;
}

// "How many vehicles does this customer already have?" - asked while the
// customer name is being typed, so the fleet option offers itself at the moment
// it is worth something instead of waiting to be discovered.
if (isset($_GET['fleet_count'])) {
    header('Content-Type: application/json');
    $hit = function_exists('client_match')
         ? client_match((string)$_GET['fleet_count'], (string)($_GET['phone'] ?? ''))
         : null;
    echo json_encode($hit
        ? ['id' => (int)$hit['id'], 'name' => $hit['name'],
           'phone' => (string)($hit['phone'] ?? ''),
           'address' => (string)($hit['address'] ?? ''),
           'vehicles' => (int)q('SELECT count(*) FROM vehicles WHERE client_id = ?',
                                [$hit['id']])->fetchColumn()]
        : ['id' => 0, 'vehicles' => 0, 'phone' => '', 'address' => '']);
    exit;
}


/** Values that never appear on the form - they always come from Settings. */


// Everyone already on file, for the pick-list on both customer fields. Name and
// contact together, because two customers can share a name and the contact is
// what tells them apart.
$knownClients = q('SELECT id, name, phone FROM clients ORDER BY name')->fetchAll();

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
        // Cast, because these come straight out of the database and a column
        // that was never filled in arrives as NULL. field() takes a string, so
        // one vehicle with no make recorded made this page a fatal error
        // instead of a form - and only for that vehicle, which is the kind of
        // fault that survives for months.
        $form['client_name'] = (string)$v['client_name'];
        $form['client_phone'] = (string)$v['phone'];
        $form['client_address'] = (string)$v['address'];
        $form['reg_no'] = (string)$v['reg_no'];
        $form['make'] = (string)$v['make'];
        $form['chassis_no'] = (string)$v['chassis_no'];
        $last = q('SELECT d.*, i.installed_on
                   FROM installations i JOIN devices d ON d.id = i.device_id
                   WHERE i.vehicle_id = ? ORDER BY i.installed_on DESC LIMIT 1', [$v['id']])->fetch();
        if ($last) {
            $form['serial_no'] = (string)$last['serial_no'];
            $form['installed_on'] = (string)($last['installed_on'] ?: $form['installed_on']);
        }
    }
}

// Declared out here, not inside the POST block below.
//
// $other is the same story: the chassis tick box below reads it to decide
// whether to show itself, and that happens on a fresh page load too.
//
// The form reads $swapping when the page is first opened as well, and a
// variable that only exists after a POST is a warning printed across the top of
// the form. The assignment inside the block stays: it resets the value on each
// submission, which is right.
$swapping = null;
$other    = null;

// Which half of this page was submitted. The fleet panel posts an action; the
// single-vehicle form does not, so its block below runs exactly as it always
// has and is never entered by a fleet upload.
$fleetAction = (string)($_POST['action'] ?? '');
$isFleetPost = in_array($fleetAction, ['fleet_preview', 'fleet_create'], true);
$fleet       = null;          // the preview, once there is one
$fleetErrors = [];
$fleetForm   = ['client_name' => '', 'client_phone' => '', 'client_address' => '',
                'issue_date' => date('Y-m-d'), 'label' => ''];

// The four that are the same on every certificate. Taken from Settings and
// written onto every row, exactly as the single-vehicle form does a few lines
// down - "these are not on the form, so whatever was posted is ignored".
//
// If an older spreadsheet still carries those columns they are overwritten, not
// obeyed, and the preview says so. One place decides what a GAPTECH governor
// certificate says, and it is Settings.
$fleetFixed   = [
    'device_model'    => $defaults['device_model'],
    'set_speed_kmh'   => $defaults['set_speed_kmh'],
    'technician'      => $defaults['technician'],
    'validity_months' => $defaults['validity_months'],
];
$fleetOverrode = [];

$fleetApplyDefaults = function (array $rows) use ($fleetFixed, &$fleetOverrode): array {
    foreach ($rows as $i => $row) {
        foreach ($fleetFixed as $key => $value) {
            $had = trim((string)($row[$key] ?? ''));
            if ($had !== '' && strcasecmp($had, (string)$value) !== 0) {
                $fleetOverrode[$key] = true;
            }
            $rows[$i][$key] = $value;
        }
    }
    return $rows;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isFleetPost) {
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

// ---------------------------------------------------------------------------
// The fleet panel's own POST handling
// ---------------------------------------------------------------------------
// Separate from the block above on purpose. One form per job, one handler per
// form: folding a spreadsheet upload into the single-vehicle validation would
// mean every rule up there growing an "unless this is a fleet" clause, and that
// is how the single flow quietly breaks.
if ($isFleetPost) {
    csrf_check();
    foreach ($fleetForm as $k => $v) {
        $fleetForm[$k] = trim((string)($_POST[$k] ?? $v));
    }
    if ($fleetForm['client_name'] === '') {
        $fleetErrors[] = 'Enter the customer first - it is typed once and used for every vehicle.';
    }

    if (!$fleetErrors && $fleetAction === 'fleet_preview') {
        $up = $_FILES['csv'] ?? null;
        if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $fleetErrors[] = 'Choose the filled-in spreadsheet to upload.';
        } elseif (($up['error'] ?? 0) !== UPLOAD_ERR_OK) {
            $fleetErrors[] = 'That file did not arrive in one piece (error ' . (int)$up['error']
                           . '). If it is an Excel file, save it as CSV first.';
        } else {
            try {
                $fleet = batch_preview($fleetForm,
                            $fleetApplyDefaults(batch_rows_from_csv($up['tmp_name'])),
                            $fleetForm['issue_date']);
            } catch (Throwable $e) {
                $fleetErrors[] = $e->getMessage();
            }
        }
    }

    if (!$fleetErrors && $fleetAction === 'fleet_create') {
        // Checked again from scratch. The preview on screen may be minutes old
        // and somebody else may have raised a certificate for one of these
        // lorries since - the rows posted back are a list, not evidence.
        // Applied again here, not trusted from the posted rows: what gets written
        // must come from Settings whatever arrives in the form.
        $fleet     = batch_preview($fleetForm,
                        $fleetApplyDefaults(json_decode((string)($_POST['rows'] ?? '[]'), true) ?: []),
                        $fleetForm['issue_date']);
        $confirmed = array_map('intval', (array)($_POST['confirm'] ?? []));
        $make      = [];

        foreach ($fleet['rows'] as $r) {
            if ($r['verdict'] === 'blocked') {
                continue;                       // shown, skipped, never created
            }
            $r['confirm'] = in_array((int)$r['line'], $confirmed, true);
            if (!empty($r['needs_confirm']) && !$r['confirm']) {
                $fleetErrors[] = 'Line ' . $r['line'] . ' (' . $r['reg_no']
                    . ') takes a governor off another lorry. Tick it to confirm, or remove it.';
            }
            $make[] = $r;
        }
        if (!$make && !$fleetErrors) {
            $fleetErrors[] = 'Every row in that file is blocked. Fix them and upload it again.';
        }

        if (!$fleetErrors) {
            try {
                $done = batch_create($fleetForm, $make, $user,
                                     $fleetForm['issue_date'], $fleetForm['label']);
                flash(count($done['certificates']) . ' certificates for '
                    . $fleetForm['client_name'] . ' sent to accounts for approval. '
                    . 'They take their numbers once the payment is confirmed.');
                redirect('index.php?status=pending');
            } catch (Throwable $e) {
                $fleetErrors[] = 'Could not create them: ' . $e->getMessage();
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
  .vfy-sub { font-size: 14px; color: #7A8696; line-height: 1.5; text-align: justified; }

</style>
<div class="vfy">
<h1 class="vfy-title">New Certificate</h1>
<p class="vfy-sub">All fields are required. The governor model, set speed, technician and expiry date are
filled in from Settings. The certificate goes to accounts when you save, and takes its number once
they confirm the payment.</p> </div>

<?php
/*
  One vehicle, or a whole fleet. Two panels on this page, not two pages: nobody
  decides which they are doing before they start - they begin typing a customer
  and then realise it is forty lorries. Switching is a button, not a journey,
  and the customer typed in one panel appears in the other.

  The single-vehicle form below is untouched. This sits above it.
*/
$showFleet = $isFleetPost;
?>
<datalist id="known-clients">
  <?php foreach ($knownClients as $kc): ?>
    <option value="<?= e($kc['name']) ?>"><?= e($kc['phone']) ?></option>
  <?php endforeach; ?>
</datalist>

<div style="display:flex; gap:8px; margin:0 0 6px; flex-wrap:wrap">
  <button type="button" id="tab-one" style="padding:10px 18px; border-radius:8px; font-weight:600;
          cursor:pointer; border:1px solid #D3DCE4; background:#fff; color:#0D4F72">One vehicle</button>
  <button type="button" id="tab-fleet" style="padding:10px 18px; border-radius:8px; font-weight:600;
          cursor:pointer; border:1px solid #D3DCE4; background:#fff; color:#0D4F72">Fleet</button>
</div>

<?php foreach ($errors as $msg): ?><p class="error"><?= e($msg) ?></p><?php endforeach; ?>
<?php foreach ($fleetErrors as $msg): ?><p class="error"><?= e($msg) ?></p><?php endforeach; ?>

<!-- ------------------------------------------------------------------ fleet -->
<div id="panel-fleet" style="<?= $showFleet ? '' : 'display:none' ?>">
  <p class="hint">One line per vehicle in a spreadsheet. Everything is checked and shown to
     you before anything is saved, and the whole lot goes to accounts as one payment.</p>

  <form method="post" class="card-form" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <fieldset>
      <legend>Customer</legend>
      <label class="field">Customer name <span class="req">*</span>
        <span class="field-row">
          <input name="client_name" id="fleet-client" class="caps" maxlength="120"
                 list="known-clients" autocomplete="off"
                 value="<?= e($fleetForm['client_name']) ?>">
        </span>
        <span class="field-hint" id="fleet-known"></span>
      </label>
      <label class="field">Contact
        <span class="field-row">
          <input name="client_phone" type="tel" maxlength="20" pattern="[0-9+ ]*"
                 value="<?= e($fleetForm['client_phone']) ?>">
        </span>
      </label>
      <label class="field">Address
        <span class="field-row">
          <input name="client_address" class="caps" maxlength="160"
                 value="<?= e($fleetForm['client_address']) ?>">
        </span>
      </label>
    </fieldset>

    <fieldset>
      <legend>The spreadsheet</legend>
      <p class="field-hint" style="margin-top:0">Download it, fill it in, upload it back.
         For a customer already on file it comes down with their vehicles in it, so a renewal
         only needs the fitting dates. Five columns: registration, make, chassis, serial and
         the date fitted.</p>

      <p class="field-hint" style="margin:0 0 16px">
         <?= e($defaults['device_model']) ?> &middot; <?= e($defaults['set_speed_kmh']) ?> km/h
         &middot; <?= e($defaults['technician']) ?> &middot;
         <?= e($defaults['validity_months']) ?> months &mdash; the same on every certificate,
         taken from Settings, not from the spreadsheet.</p>

      <p style="margin:0 0 16px">
        <a class="button secondary" id="fleet-template" href="certificate_new.php?template=1">Download the spreadsheet</a>
      </p>

      <label class="field">The filled-in file <span class="req">*</span>
        <span class="field-row">
          <input type="file" name="csv" accept=".csv,text/csv,application/vnd.ms-excel">
        </span>
        <span class="field-hint">From Excel: File, Save As, "CSV (Comma delimited)".</span>
      </label>

      <label class="field">Issue date <span class="req">*</span>
        <span class="field-row">
          <input type="date" name="issue_date" value="<?= e($fleetForm['issue_date']) ?>">
        </span>
        <span class="field-hint">The same for every certificate in the batch. Each expires by its own validity.</span>
      </label>

      <label class="field">Name for this batch
        <span class="field-row">
          <input name="label" maxlength="80" value="<?= e($fleetForm['label']) ?>"
                 placeholder="e.g. November intake">
        </span>
      </label>

      <button class="button" type="submit" name="action" value="fleet_preview">Check the file</button>
    </fieldset>
  </form>

  <?php if ($fleet !== null): $c = $fleet['counts']; ?>
    <?php /*
      The preview is a dialog, not a slab below the form.

      It is a decision, and a decision wants the screen: everything in it is
      about one question - do these rows go in or not - and the upload form it
      was sitting under is no longer relevant by the time it exists. Same
      dialog as the payment message, so it behaves the way the rest of the
      system does.
    */ ?>
    <button type="button" id="preview-opener" data-modal="#m-preview" style="display:none"></button>

    <div class="gt-modal" id="m-preview">
      <form class="gt-box" method="post" style="max-width:900px">
        <h3>What is in that file</h3>

        <p class="gt-hint"><?= (int)$c['new'] ?> first fittings &middot;
           <?= (int)$c['renewal'] ?> renewals &middot;
           <?= (int)$c['moved'] ?> with a governor moved from another lorry &middot;
           <?= (int)$c['blocked'] ?> that cannot be created.
           <strong>Nothing has been saved yet.</strong></p>

        <?php if ($fleetOverrode): ?>
          <p class="gt-hint" style="color:#8A5A00">
            Your file had its own
            <?= e(implode(', ', array_map(fn($k) => [
                  'device_model' => 'governor model', 'set_speed_kmh' => 'set speed',
                  'technician' => 'technician', 'validity_months' => 'validity'][$k],
                  array_keys($fleetOverrode)))) ?>.
            Those come from Settings on every certificate, so the values in the
            spreadsheet were not used &mdash; the same as on the single-vehicle form.</p>
        <?php endif; ?>

        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="rows" value="<?= e(json_encode($fleet['rows'])) ?>">
        <?php foreach ($fleetForm as $k => $v): ?>
          <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
        <?php endforeach; ?>

        <?php /* The list scrolls inside the dialog; the buttons below never move. */ ?>
        <div style="max-height:50vh; overflow:auto; margin:0 0 14px">
          <table class="list">
            <thead><tr><th>Line</th><th>Registration</th><th>Governor</th><th></th>
                       <th>What will happen</th></tr></thead>
            <tbody>
            <?php foreach ($fleet['rows'] as $r):
              $badge = ['new' => 'Fitting', 'renewal' => 'Renewal',
                        'moved' => 'Moved unit', 'blocked' => 'Cannot create'][$r['verdict']]; ?>
              <tr<?= $r['verdict'] === 'blocked' ? ' style="opacity:.6"' : '' ?>>
                <td><?= (int)$r['line'] ?></td>
                <td><strong><?= e($r['reg_no'] ?: '-') ?></strong><br>
                    <span class="field-hint"><?= e($r['make']) ?></span></td>
                <td><?= e($r['serial_no'] ?: '-') ?></td>
                <td><span class="status status-<?= e($r['verdict']) ?>"><?= e($badge) ?></span></td>
                <td><?= e(implode(' ', $r['notes'])) ?>
                  <?php if (!empty($r['needs_confirm']) && $r['verdict'] !== 'blocked'): ?>
                    <div style="display:flex; align-items:center; gap:8px; margin-top:8px">
                      <input type="checkbox" name="confirm[]" value="<?= (int)$r['line'] ?>"
                             id="cf<?= (int)$r['line'] ?>" style="width:16px; flex:0 0 16px; margin:0">
                      <label for="cf<?= (int)$r['line'] ?>" style="margin:0">Yes &mdash; it was
                        moved. Close the old fitting.</label>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="gt-foot">
          <button type="button" class="button secondary gt-close">Cancel</button>
          <button type="submit" name="action" value="fleet_create" class="button"
                  <?= $fleet['ok'] === 0 ? 'disabled' : '' ?>>
            Create <?= (int)$fleet['ok'] ?> certificate<?= $fleet['ok'] === 1 ? '' : 's' ?>
          </button>
        </div>
      </form>
    </div>
    <?php require_once APP_DIR . '/views/modal_base.php'; ?>
  <?php endif; ?>
</div>

<!-- ----------------------------------------------------------- one vehicle -->
<div id="panel-one" style="<?= $showFleet ? 'display:none' : '' ?>">
<form method="post" class="card-form" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

  <fieldset>
    <legend>Customer</legend>
    <?php /*
      The pick-list is added after the field is drawn rather than by changing
      field(): that helper is used by every input on this page, and giving it a
      "list" option for the sake of one of them would put the option on all of
      them waiting to be misused.
    */ ?>
    <?php field('client_name', 'Customer name', $form['client_name'], ['caps' => true]); ?>
    <script>(function () {
      var f = document.querySelector('#panel-one [name="client_name"]');
      if (f) { f.setAttribute('list', 'known-clients'); f.setAttribute('autocomplete', 'off'); }
    })();</script>
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
</div>

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

// The preview opens itself. Clicking the shared opener rather than touching the
// dialog directly means it opens, closes and traps focus exactly like every
// other dialog on the site, whatever modal_base.php does.
window.addEventListener('load', function () {
  var opener = document.getElementById('preview-opener');
  if (opener) { opener.click(); }
});

// --------------------------------------------------------------- the two tabs
//
// Switching copies the customer across, both ways. Nobody decides "one or many"
// before they start - they start typing and then realise - so a switch that
// makes you retype the customer is a switch nobody uses.
(function () {
  var one      = document.getElementById('panel-one');
  var fleet    = document.getElementById('panel-fleet');
  var tabOne   = document.getElementById('tab-one');
  var tabFleet = document.getElementById('tab-fleet');
  if (!one || !fleet || !tabOne || !tabFleet) { return; }

  var pairs = [['client_name', 'client_name'], ['client_phone', 'client_phone'],
               ['client_address', 'client_address']];

  function field(panel, name) { return panel.querySelector('[name="' + name + '"]'); }

  // Their details, into whichever panel is asking. Empty fields only.
  function fill(panel, d) {
    if (!d || !d.id) { return; }
    var p = field(panel, 'client_phone'), a = field(panel, 'client_address');
    var touched = false;
    if (p && p.value.trim() === '' && d.phone)   { p.value = d.phone;   touched = true; }
    if (a && a.value.trim() === '' && d.address) { a.value = d.address; touched = true; }
    if (touched) {
      // Said out loud. A field filling itself while somebody is looking away is
      // the kind of thing that gets saved without being read.
      var n = field(panel, 'client_name');
      var box = n ? (n.closest('.field') || n.parentNode) : null;
      if (box) {
        var say = box.querySelector('.picked-note');
        if (!say) {
          say = document.createElement('span');
          say.className = 'picked-note field-hint';
          say.style.cssText = 'display:block; color:#4A7A18; margin-top:5px';
          box.appendChild(say);
        }
        say.textContent = 'Contact and address filled in from the customer on file.';
      }
    }
  }

  function carry(from, to) {
    pairs.forEach(function (p) {
      var a = field(from, p[0]), b = field(to, p[1]);
      if (a && b && a.value.trim() !== '' && b.value.trim() === '') { b.value = a.value; }
    });
  }

  function paint(onFleet) {
    fleet.style.display = onFleet ? '' : 'none';
    one.style.display   = onFleet ? 'none' : '';
    [[tabOne, !onFleet], [tabFleet, onFleet]].forEach(function (t) {
      t[0].style.background  = t[1] ? '#0D4F72' : '#fff';
      t[0].style.color       = t[1] ? '#fff'    : '#0D4F72';
      t[0].style.borderColor = t[1] ? '#0D4F72' : '#D3DCE4';
    });
  }

  tabFleet.addEventListener('click', function () { carry(one, fleet); paint(true); });
  tabOne.addEventListener('click',  function () { carry(fleet, one); paint(false); });
  paint(fleet.style.display !== 'none');

  // "This customer already has 38 vehicles." Asked of the single form while the
  // name is typed, so the fleet option offers itself at the moment it is worth
  // something rather than waiting to be found.
  var name  = field(one, 'client_name');
  var phone = field(one, 'client_phone');
  var tmpl  = document.getElementById('fleet-template');
  var known = document.getElementById('fleet-known');
  if (!name || !window.fetch) { return; }

  var hint = document.createElement('p');
  hint.className = 'hint';
  hint.style.cssText = 'display:none; text-align:left; margin:0 0 16px; padding:12px 14px;'
                     + ' border-left:3px solid #8CC63F; background:#F4F9EC';
  one.parentNode.insertBefore(hint, one);

  var timer = null, seq = 0;

  function look() {
    var v = name.value.trim();
    if (v.length < 3) { hint.style.display = 'none'; return; }
    var mine = ++seq;
    fetch('certificate_new.php?fleet_count=' + encodeURIComponent(v)
            + '&phone=' + encodeURIComponent(phone ? phone.value.trim() : ''),
          { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || mine !== seq) { return; }
        if (tmpl) {
          tmpl.href = d.id ? 'certificate_new.php?template=1&client=' + d.id
                           : 'certificate_new.php?template=1';
          tmpl.textContent = d.id && d.vehicles
            ? 'Download their ' + d.vehicles + ' vehicles to fill in'
            : 'Download the spreadsheet';
        }
        if (known) {
          known.textContent = d.id ? 'On file with ' + d.vehicles + ' vehicles.' : '';
        }

        // Their contact and address, so nobody retypes what the system already
        // knows. Only into EMPTY fields: a value already on screen was put there
        // by the person at the keyboard, and overwriting it would throw away a
        // correction they are halfway through making.
        fill(one, d);
        if (d.vehicles >= 3) {
          hint.innerHTML = '<strong>' + d.name + '</strong> has <strong>' + d.vehicles
            + ' vehicles</strong> on file. <a href="#" id="go-fleet">Do them in one upload</a>'
            + ' &mdash; their registrations, chassis numbers and fitted governors come down'
            + ' already filled in.';
          hint.style.display = '';
          var go = document.getElementById('go-fleet');
          if (go) {
            go.addEventListener('click', function (ev) {
              ev.preventDefault();
              carry(one, fleet);
              paint(true);
              window.scrollTo(0, 0);
            });
          }
        } else {
          hint.style.display = 'none';       // two lorries is not a fleet
        }
      })
      .catch(function () { hint.style.display = 'none'; });
  }

  name.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(look, 400); });
  name.addEventListener('blur', look);
  if (phone) { phone.addEventListener('blur', look); }

  var fleetName = field(fleet, 'client_name');
  if (fleetName) {
    fleetName.addEventListener('blur', function () {
      if (!window.fetch) { return; }
      fetch('certificate_new.php?fleet_count=' + encodeURIComponent(fleetName.value.trim()),
            { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d || !tmpl) { return; }
          tmpl.href = d.id ? 'certificate_new.php?template=1&client=' + d.id
                           : 'certificate_new.php?template=1';
          tmpl.textContent = d.id && d.vehicles
            ? 'Download their ' + d.vehicles + ' vehicles to fill in'
            : 'Download the spreadsheet';
          if (known) { known.textContent = d.id ? 'On file with ' + d.vehicles + ' vehicles.' : ''; }
          fill(fleet, d);
        })
        .catch(function () {});
    });
  }
})();
</script>
<?php layout_bottom(); ?>