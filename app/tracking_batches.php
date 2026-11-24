<?php
/**
 * Fleet installations for trackers - the tracking twin of app/batches.php.
 *
 * A fleet is one customer's vehicles raised together and released on one payment.
 * Every row goes through the same rules as the single installation form, and nothing
 * is written until trk_batch_create(), which is one transaction: a bad row can never
 * leave half a fleet behind.
 *
 * Needs app/tracking.php (loaded first by the pages that use this) and
 * sql/migration_tracking_batches.sql.
 */
declare(strict_types=1);


// ---------------------------------------------------------------------------
// The spreadsheet
// ---------------------------------------------------------------------------

/** Column headings the fleet spreadsheet uses, in order. */
const TRK_BATCH_COLUMNS = ['Reg No', 'Make / Model', 'Chassis No', 'Engine No', 'Colour',
                           'Ownership', 'Financier', 'IMEI', 'Second Unit', 'Installed On'];

/**
 * One row per vehicle. Column order does not matter; the heading names do, and case,
 * spaces and punctuation in them are ignored. "Reg No" and "IMEI" are required.
 */
function trk_batch_rows_from_csv(string $path): array
{
    $map = [
        'regno' => 'reg_no', 'registration' => 'reg_no', 'registrationno' => 'reg_no',
        'vehicle' => 'reg_no', 'vehicleregno' => 'reg_no',
        'makemodel' => 'make_model', 'vehiclemakemodel' => 'make_model',
        // Separate MAKE and MODEL columns (government fleet sheets) are joined into one.
        'make' => 'make', 'model' => 'model',
        // Government (GK) fleet sheets: the GK plate is the registration that prints; the
        // civilian plate and the SIM card are kept on the record; CUSTOMER and USER make the
        // ownership. NO (a row count) is simply not read.
        'gkplate' => 'gk_plate', 'gk' => 'gk_plate', 'gkno' => 'gk_plate',
        'civilianplate' => 'civilian_plate', 'civilplate' => 'civilian_plate',
        'civilianregno' => 'civilian_plate',
        'customer' => 'customer', 'user' => 'vehicle_user',
        'simcard' => 'sim_card', 'sim' => 'sim_card', 'simno' => 'sim_card',
        'simcardno' => 'sim_card', 'simnumber' => 'sim_card',
        'chassisno' => 'chassis_no', 'chassis' => 'chassis_no',
        'engineno' => 'engine_no', 'engine' => 'engine_no',
        'colour' => 'colour', 'color' => 'colour',
        'ownership' => 'ownership', 'owner' => 'ownership', 'ownershipdetails' => 'ownership',
        'financier' => 'financier', 'bank' => 'financier',
        'imei' => 'imei', 'imeino' => 'imei', 'trackimei' => 'imei', 'trackimeino' => 'imei',
        'imei1' => 'imei',
        'secondunit' => 'imei_2', 'imei2' => 'imei_2', 'secondimei' => 'imei_2',
        'secondunitno' => 'imei_2',
        'installedon' => 'installed_on', 'dateinstalled' => 'installed_on',
        'fittingdate' => 'installed_on', 'installed' => 'installed_on',
    ];

    $fh = fopen($path, 'r');
    if (!$fh) {
        throw new RuntimeException('Could not open the uploaded file.');
    }
    $head = fgetcsv($fh);
    if (!$head) {
        fclose($fh);
        throw new RuntimeException('That file is empty.');
    }
    // Excel puts a byte-order mark in front of the first heading.
    $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$head[0]);

    $cols = [];
    foreach ($head as $i => $name) {
        $key = strtolower((string)preg_replace('/[^a-z0-9]/i', '', (string)$name));
        if (isset($map[$key]) && !in_array($map[$key], $cols, true)) {
            $cols[$i] = $map[$key];
        }
    }
    $hasPlate = (bool)array_intersect(['reg_no', 'gk_plate', 'civilian_plate'], $cols);
    if (!$hasPlate || !in_array('imei', $cols, true)) {
        fclose($fh);
        throw new RuntimeException('The file needs a registration column ("Reg No", or "GK Plate" / "Civilian Plate") and an "IMEI" column. '
            . 'Found: ' . implode(', ', array_map('strval', $head)) . '. Download the spreadsheet '
            . 'from this page and fill that in.');
    }

    $rows = [];
    $line = 1;
    while (($data = fgetcsv($fh)) !== false) {
        $line++;
        if (count(array_filter($data, fn($v) => trim((string)$v) !== '')) === 0) {
            continue;                                   // blank line
        }
        $row = ['line' => $line];
        foreach ($cols as $i => $field) {
            $row[$field] = trim((string)($data[$i] ?? ''));
        }
        $rows[] = $row;
    }
    fclose($fh);

    if (!$rows) {
        throw new RuntimeException('That file has headings but no vehicles.');
    }
    if (count($rows) > 500) {
        throw new RuntimeException('That file has ' . count($rows) . ' vehicles. Upload it in parts '
            . 'of 500 or fewer, so a mistake is never more than 500 rows to fix.');
    }
    return $rows;
}


// ---------------------------------------------------------------------------
// The dry run
// ---------------------------------------------------------------------------

/**
 * Check every row and write nothing.
 *
 * $fleet: client_name, client_phone, subscription_date, term_months.
 *
 * Each row comes back with a verdict:
 *   new      - first installation on this vehicle
 *   renewal  - the vehicle has had a tracker certificate before
 *   moved    - a unit is still recorded on another vehicle; needs confirming, with a reason
 *   blocked  - cannot be created, with the reason
 *
 * A blocked row does not stop the others: it is shown, skipped, and never created.
 */
function trk_batch_preview(array $fleet, array $rows): array
{
    $client = trim((string)($fleet['client_name'] ?? ''));
    $start  = (string)($fleet['subscription_date'] ?? date('Y-m-d'));
    $months = (int)($fleet['term_months'] ?? 12);
    if (!isset(TRACKING_TERMS[$months])) { $months = 12; }
    $today  = date('Y-m-d');

    $seenReg = [];
    $seenImei = [];
    $out = [];

    foreach ($rows as $row) {
        $r = $row + ['reg_no' => '', 'make_model' => '', 'chassis_no' => '', 'engine_no' => '',
                     'colour' => '', 'ownership' => '', 'financier' => '', 'imei' => '',
                     'imei_2' => '', 'installed_on' => '', 'make' => '', 'model' => '',
                     'gk_plate' => '', 'civilian_plate' => '', 'customer' => '',
                     'vehicle_user' => '', 'sim_card' => ''];

        // Government sheets: the GK plate prints as the registration (the civilian plate if a
        // row has none); MAKE and MODEL become one "make / model"; CUSTOMER and USER become
        // the ownership, e.g. "MINISTRY OF HEALTH - KITUI SUB-COUNTY".
        $gk  = mb_strtoupper(trim((string)$r['gk_plate']), 'UTF-8');
        $civ = mb_strtoupper(trim((string)$r['civilian_plate']), 'UTF-8');
        if ($gk !== '') {
            if (trim((string)$r['reg_no']) !== '' && $civ === '' && plate_key($r['reg_no']) !== plate_key($gk)) {
                $civ = (string)$r['reg_no'];     // a sheet with both "Reg No" and "GK Plate"
            }
            $r['reg_no'] = $gk;
        } elseif (trim((string)$r['reg_no']) === '') {
            $r['reg_no'] = $civ;
            $civ = '';                            // it is the registration now, not a second plate
        }
        $r['civilian_plate'] = $civ !== '' ? plate_display($civ) : '';
        if (trim((string)$r['make_model']) === '') {
            $make  = trim((string)$r['make']);
            $model = trim((string)$r['model']);
            // "TOYOTA" + "TOYOTA HILUX" should not become "TOYOTA TOYOTA HILUX".
            $r['make_model'] = ($make !== '' && stripos($model, $make) === 0) ? $model : trim($make . ' ' . $model);
        }
        if (trim((string)$r['ownership']) === '') {
            $r['ownership'] = implode(' - ', array_filter([trim((string)$r['customer']), trim((string)$r['vehicle_user'])],
                                                   fn($v) => $v !== ''));
        }
        $r['sim_card'] = trim((string)$r['sim_card']);

        // Same shape the single form stores: capitals, plate spaced, ownership defaulting
        // to the customer, the fitting date defaulting to the subscription date.
        foreach (['make_model', 'chassis_no', 'engine_no', 'colour', 'ownership', 'financier'] as $k) {
            $r[$k] = mb_strtoupper(trim((string)$r[$k]), 'UTF-8');
        }
        $r['reg_no']  = plate_display((string)$r['reg_no']);
        $r['financier'] = trk_financier_canonical($r['financier']);   // the name it will print
        $r['imei']    = trim((string)$r['imei']);
        $r['imei_2']  = trim((string)$r['imei_2']);
        if ($r['ownership'] === '') { $r['ownership'] = mb_strtoupper($client, 'UTF-8'); }
        if ($r['installed_on'] === '') { $r['installed_on'] = $start; }
        elseif (($t = strtotime($r['installed_on'])) !== false) { $r['installed_on'] = date('Y-m-d', $t); }

        $r['verdict'] = 'new';
        $r['notes'] = [];
        $r['needs_confirm'] = [];     // slot number => vehicle the unit is still on
        $r['renewed_from'] = null;
        $block = function (string $why) use (&$r) { $r['verdict'] = 'blocked'; $r['notes'][] = $why; };

        $regKey = plate_key($r['reg_no']);

        // ------------------------------------------------ what the single form refuses
        if ($regKey === '') {
            $block('No registration number.');
        } elseif (isset($seenReg[$regKey])) {
            $block('Registration repeated - also on line ' . $seenReg[$regKey] . '.');
        }

        $missing = [];
        foreach (['make_model' => 'make/model', 'chassis_no' => 'chassis', 'engine_no' => 'engine number',
                  'colour' => 'colour'] as $k => $label) {
            if ($r[$k] === '') { $missing[] = $label; }
        }
        if ($missing) {
            $block('Missing ' . implode(', ', $missing) . '.');
        }

        // Excel shows a long number as 8.61234E+14 and saves it that way - the digits are gone.
        foreach ([1 => 'imei', 2 => 'imei_2'] as $n => $k) {
            if (preg_match('/^[0-9.]+E\+?[0-9]+$/i', $r[$k])) {
                $block('Excel has turned the ' . ($n === 1 ? 'IMEI' : 'second unit') . ' into "'
                    . $r[$k] . '" and lost its digits. Format that column as Text, retype it and save again.');
                $r[$k] = '';
            }
        }
        if (preg_match('/^[0-9.]+E\+?[0-9]+$/i', $r['sim_card'])) {
            $block('Excel has turned the SIM card number into "' . $r['sim_card'] . '" and lost its digits. '
                . 'Format that column as Text, retype it and save again.');
        }
        if ($r['imei'] === '' && $r['verdict'] !== 'blocked') {
            $block('No IMEI.');
        } elseif ($r['imei'] !== '' && !preg_match('/^[0-9]{10,17}$/', $r['imei'])) {
            $block('IMEI "' . $r['imei'] . '" should be 10 to 17 digits.');
        }
        if ($r['imei_2'] !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-\/]{3,31}$/', $r['imei_2'])) {
            $block('Second unit "' . $r['imei_2'] . '" looks wrong: use 4 to 32 letters, digits or hyphens.');
        }
        if ($r['imei'] !== '' && $r['imei'] === $r['imei_2']) {
            $block('The same number is in both IMEI columns.');
        }
        foreach ([$r['imei'], $r['imei_2']] as $unit) {
            if ($unit !== '' && isset($seenImei[$unit])) {
                $block('Unit ' . $unit . ' is also on line ' . $seenImei[$unit] . '.');
            }
        }

        if (strtotime($r['installed_on']) === false) {
            $block('Installed On "' . $r['installed_on'] . '" is not a date.');
        } elseif ($r['installed_on'] > $today) {
            $block('Installed On ' . fmt_date($r['installed_on']) . ' is in the future.');
        }

        // ------------------------------------------------ against what is on file
        $rowStart = $start;
        if ($r['verdict'] !== 'blocked') {
            $pending = q("SELECT id FROM tracking_certificates
                           WHERE regexp_replace(upper(reg_no), '[^A-Z0-9]', '', 'g') = ?
                             AND approval_status = 'pending' LIMIT 1", [$regKey])->fetch();
            if ($pending) {
                $block('Already waiting for approval on another certificate (TRK-'
                    . str_pad((string)$pending['id'], 5, '0', STR_PAD_LEFT) . ').');
            }
        }

        if ($r['verdict'] !== 'blocked') {
            $current = tracking_current_for_plate($r['reg_no']);
            if ($current) {
                $r['verdict'] = 'renewal';
                $r['renewed_from'] = (int)$current['id'];
                if ((string)$current['expiry_date'] >= $today) {
                    // Picks up the day after the current cover ends, exactly like a single
                    // renewal, so the customer keeps every day already paid for.
                    // renewal_start_date() is in app/certificates.php, which tracking pages do
                    // not load; the fallback is the same rule (current expiry still ahead here).
                    $rowStart = function_exists('renewal_start_date')
                        ? renewal_start_date((string)$current['expiry_date'])
                        : date('Y-m-d', strtotime((string)$current['expiry_date'] . ' +1 day'));
                    $r['notes'][] = 'Renews ' . tracking_label($current) . ', which runs to '
                        . fmt_date((string)$current['expiry_date']) . '; the new one starts the day after.';
                } else {
                    $r['notes'][] = 'Had ' . tracking_label($current) . ', lapsed '
                        . fmt_date((string)$current['expiry_date']) . '.';
                }
            } elseif (!trk_is_first_installation($r['reg_no'])) {
                $r['verdict'] = 'renewal';
            }
        }

        if ($r['verdict'] !== 'blocked' && $r['installed_on'] > $rowStart) {
            $block('Installed On ' . fmt_date($r['installed_on'])
                . ' is after the subscription starts (' . fmt_date($rowStart) . ').');
        }

        // ------------------------------------------------ the units themselves
        if ($r['verdict'] !== 'blocked') {
            foreach ([1 => $r['imei'], 2 => $r['imei_2']] as $n => $unit) {
                $held = $unit !== '' ? tracking_imei_in_use($unit) : null;
                if (!$held || plate_key((string)$held['reg_no']) === $regKey) {
                    continue;                           // free, or already on this vehicle
                }
                if (($held['approval_status'] ?? '') === 'pending') {
                    $block('Unit ' . $unit . ' is on a certificate waiting for approval for '
                        . $held['reg_no'] . '. That one has to be approved or sent back first.');
                } else {
                    $r['verdict'] = 'moved';
                    $r['needs_confirm'][$n] = ['id' => (int)$held['id'], 'reg_no' => (string)$held['reg_no'],
                                               'label' => tracking_label($held), 'unit' => $unit];
                    $r['notes'][] = 'Unit ' . $unit . ' is still recorded on ' . $held['reg_no']
                        . ' (' . tracking_label($held) . '). Confirm it was moved and that one will be closed.';
                }
            }
        }

        // ------------------------------------------------ the dates it will get
        $bonus = ($r['verdict'] !== 'blocked' && trk_is_first_installation($r['reg_no']))
               ? trk_financier_bonus($r['financier']) : 0;
        $r['subscription_date'] = $rowStart;
        $r['bonus_months'] = $bonus;
        $r['expiry_date'] = default_expiry($rowStart, $months + $bonus);
        if ($bonus > 0) {
            $r['notes'][] = '+' . $bonus . ' months under the ' . $r['financier'] . ' agreement.';
        }

        if ($regKey !== '' && !isset($seenReg[$regKey])) { $seenReg[$regKey] = $r['line'] ?? '?'; }
        foreach ([$r['imei'], $r['imei_2']] as $unit) {
            if ($unit !== '' && !isset($seenImei[$unit])) { $seenImei[$unit] = $r['line'] ?? '?'; }
        }
        $out[] = $r;
    }

    $counts = ['new' => 0, 'renewal' => 0, 'moved' => 0, 'blocked' => 0];
    foreach ($out as $r) { $counts[$r['verdict']]++; }

    return ['rows' => $out, 'counts' => $counts, 'ok' => count($out) - $counts['blocked']];
}


// ---------------------------------------------------------------------------
// Creating
// ---------------------------------------------------------------------------

/**
 * Create the fleet: one transaction, all of it or none of it.
 *
 * $rows are preview rows that are not blocked. A row with units on another vehicle must
 * carry moved_reason (a reason per row), or this refuses rather than quietly take a unit
 * off another customer's vehicle. Every certificate is created PENDING, with no serial,
 * exactly as a single installation is.
 */
function trk_batch_create(array $fleet, array $rows, array $user, string $label = ''): array
{
    if (!$rows) {
        throw new RuntimeException('Nothing to create.');
    }
    $client   = mb_strtoupper(trim((string)($fleet['client_name'] ?? '')), 'UTF-8');
    $phone    = trim((string)($fleet['client_phone'] ?? ''));
    $signedBy = trim((string)setting('tracking_signatory', '')) ?: 'MOSES CLAYTONE';
    if ($client === '') {
        throw new RuntimeException('The customer name is missing.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $batchId = (int)q("INSERT INTO tracking_batches (client_name, client_phone, label, created_by)
                           VALUES (?, ?, ?, ?) RETURNING id",
            [$client, $phone, $label !== '' ? $label : date('j M Y') . ' fleet', $user['id']])->fetchColumn();

        $made = [];
        foreach ($rows as $row) {
            if (($row['verdict'] ?? '') === 'blocked') {
                throw new RuntimeException('Line ' . ($row['line'] ?? '?') . ' cannot be created: '
                    . implode(' ', $row['notes'] ?? []));
            }

            // Units coming off other vehicles: close those certificates first, with the
            // reason, through the same function the removal screen uses.
            foreach ((array)($row['needs_confirm'] ?? []) as $held) {
                $why = trim((string)($row['moved_reason'] ?? ''));
                if ($why === '') {
                    throw new RuntimeException('Line ' . ($row['line'] ?? '?') . ' (' . $row['reg_no']
                        . ') takes unit ' . $held['unit'] . ' off ' . $held['reg_no']
                        . ' - say why, so that certificate records it.');
                }
                $other = q('SELECT * FROM tracking_certificates WHERE id = ?', [(int)$held['id']])->fetch();
                if ($other && ($other['status'] ?? '') === 'issued') {
                    remove_tracking_unit($other, $user, $why, (string)$row['installed_on']);
                }
            }

            $cert = create_tracking_cert([
                'client_name'       => $client,
                'client_phone'      => $phone,
                'reg_no'            => $row['reg_no'],
                'make_model'        => $row['make_model'],
                'chassis_no'        => $row['chassis_no'],
                'engine_no'         => $row['engine_no'],
                'colour'            => $row['colour'],
                'ownership'         => $row['ownership'],
                'financier'         => $row['financier'],
                'imeis'             => [$row['imei'], $row['imei_2']],
                'installed_on'      => $row['installed_on'],
                'subscription_date' => $row['subscription_date'],
                'expiry_date'       => $row['expiry_date'],
                'signed_by'         => $signedBy,
                'civilian_plate'    => (string)($row['civilian_plate'] ?? ''),
                'sim_card'          => (string)($row['sim_card'] ?? ''),
            ], $user, !empty($row['renewed_from']) ? (int)$row['renewed_from'] : null);

            q('UPDATE tracking_certificates SET batch_id = ? WHERE id = ?', [$batchId, (int)$cert['id']]);

            if (!empty($row['bonus_months'])) {
                audit('financier_bonus', 'tracking_certificate', (int)$cert['id'], [
                    'financier' => $row['financier'], 'bonus' => (int)$row['bonus_months'],
                    'expiry' => $row['expiry_date'], 'batch' => $batchId,
                ]);
            }
            $made[] = ['id' => (int)$cert['id'], 'reg_no' => $row['reg_no']];
        }

        $pdo->commit();
    } catch (Throwable $err) {
        $pdo->rollBack();
        throw $err;
    }

    audit('create_tracking_batch', 'tracking_batch', $batchId,
          ['client' => $client, 'vehicles' => count($made)]);

    return ['batch_id' => $batchId, 'certificates' => $made];
}


// ---------------------------------------------------------------------------
// Releasing
// ---------------------------------------------------------------------------

/** Is this payment reference already spoken for, anywhere in the tracking module? */
function trk_payment_ref_in_use(string $ref, ?int $exceptCertId = null): ?string
{
    $ref = strtoupper(trim($ref));
    if ($ref === '') {
        return null;
    }
    $paid = q('SELECT p.batch_id, b.client_name, p.certificates
                 FROM tracking_batch_payments p JOIN tracking_batches b ON b.id = p.batch_id
                WHERE upper(p.payment_ref) = ?', [$ref])->fetch();
    if ($paid) {
        return 'That reference was already used to release ' . $paid['certificates']
             . ' tracker certificates for ' . $paid['client_name'] . ' (fleet ' . $paid['batch_id'] . ').';
    }
    $cert = q('SELECT * FROM tracking_certificates
                WHERE upper(payment_ref) = ? AND batch_id IS NULL
                  AND (?::int IS NULL OR id <> ?::int) LIMIT 1',
              [$ref, $exceptCertId, $exceptCertId])->fetch();
    if ($cert) {
        return 'That reference was already used on certificate ' . tracking_label($cert) . '.';
    }
    return null;
}

/**
 * Release fleet certificates on one payment.
 *
 * $only, when given, is the certificate ids being released; the rest stay pending, so two
 * unpaid vehicles never hold up the others, and the fleet stays open until they are settled.
 * Serial numbers are allocated here, in registration order, so the register reads tidily.
 */
function trk_batch_approve(int $batchId, array $user, string $ref, string $note, ?array $only = null): array
{
    $ref = strtoupper(trim($ref));
    if ($ref === '') {
        throw new RuntimeException('A payment reference is required.');
    }
    $batch = q('SELECT * FROM tracking_batches WHERE id = ?', [$batchId])->fetch();
    if (!$batch) {
        throw new RuntimeException('No such fleet.');
    }
    if ($clash = trk_payment_ref_in_use($ref)) {
        throw new RuntimeException($clash);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pending = q("SELECT * FROM tracking_certificates
                       WHERE batch_id = ? AND approval_status = 'pending'
                       ORDER BY regexp_replace(upper(reg_no), '[^A-Z0-9]', '', 'g')", [$batchId])->fetchAll();
        if ($only !== null) {
            $keep = array_map('intval', $only);
            $pending = array_values(array_filter($pending, fn($c) => in_array((int)$c['id'], $keep, true)));
        }
        if (!$pending) {
            throw new RuntimeException('Nothing ticked is waiting for approval.');
        }

        q('INSERT INTO tracking_batch_payments (batch_id, payment_ref, note, certificates, received_by)
           VALUES (?, ?, ?, ?, ?)', [$batchId, $ref, $note, count($pending), $user['id']]);

        foreach ($pending as $cert) {
            $number = next_tracking_number();
            $snapshot = json_decode((string)$cert['snapshot'], true) ?: [];
            $snapshot['cert']['number'] = str_pad((string)$number, 5, '0', STR_PAD_LEFT);

            q("UPDATE tracking_certificates
                  SET number = ?, snapshot = ?, approval_status = 'approved', status = 'issued',
                      payment_ref = ?, payment_note = ?, approved_by = ?, approved_at = now(),
                      rejection_reason = NULL, pdf_path = NULL, pdf_sha256 = NULL
                WHERE id = ?", [$number, json_encode($snapshot), $ref, $note, $user['id'], $cert['id']]);

            // The same rule a single approval uses: this vehicle's latest approved
            // certificate is current, the others step back.
            $key = plate_key((string)$cert['reg_no']);
            q("UPDATE tracking_certificates c
                  SET status = CASE WHEN c.id = cur.id THEN 'issued' ELSE 'superseded' END
                FROM (SELECT id FROM tracking_certificates
                       WHERE regexp_replace(upper(reg_no), '[^A-Z0-9]', '', 'g') = ?
                         AND approval_status = 'approved' AND status NOT IN ('voided', 'removed')
                       ORDER BY expiry_date DESC, number DESC LIMIT 1) cur
                WHERE regexp_replace(upper(c.reg_no), '[^A-Z0-9]', '', 'g') = ?
                  AND c.approval_status = 'approved' AND c.status NOT IN ('voided', 'removed')",
              [$key, $key]);
        }

        $left = (int)q("SELECT count(*) FROM tracking_certificates
                         WHERE batch_id = ? AND approval_status = 'pending'", [$batchId])->fetchColumn();
        q("UPDATE tracking_batches SET approved_by = ?, approved_at = now(),
                  approval_status = CASE WHEN ? = 0 THEN 'approved' ELSE approval_status END
            WHERE id = ?", [$user['id'], $left, $batchId]);

        $pdo->commit();
    } catch (Throwable $err) {
        $pdo->rollBack();
        throw $err;
    }

    audit('approve_tracking_batch', 'tracking_batch', $batchId,
          ['ref' => $ref, 'approved' => count($pending), 'still_pending' => $left]);

    return ['approved' => count($pending), 'still_pending' => $left];
}


// ---------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------

/** One fleet with its certificates and payments. */
function trk_batch_get(int $batchId): ?array
{
    $batch = q('SELECT b.*, u.name AS created_by_name FROM tracking_batches b
                  LEFT JOIN users u ON u.id = b.created_by WHERE b.id = ?', [$batchId])->fetch();
    if (!$batch) {
        return null;
    }
    // Everything the vehicle pop-up on the certificate page shows, for every vehicle.
    $batch['certificates'] = q("SELECT id, number, reg_no, imei, approval_status, status,
                                       subscription_date, expiry_date, renewed_from, installed_on,
                                       client_name, client_phone, make_model, chassis_no, engine_no,
                                       colour, ownership, financier, civilian_plate, sim_card
                                  FROM tracking_certificates WHERE batch_id = ?
                                 ORDER BY regexp_replace(upper(reg_no), '[^A-Z0-9]', '', 'g')",
                               [$batchId])->fetchAll();
    $batch['payments'] = q('SELECT p.*, u.name AS received_by_name FROM tracking_batch_payments p
                              LEFT JOIN users u ON u.id = p.received_by
                             WHERE p.batch_id = ? ORDER BY p.received_at', [$batchId])->fetchAll();
    return $batch;
}



// ---------------------------------------------------------------------------
// Correcting a whole fleet at once
// ---------------------------------------------------------------------------

/** The details that are usually the same across a fleet, and so can be set for all of it. */
const TRK_FLEET_EDITABLE = ['client_name', 'client_phone', 'ownership', 'financier',
                            'installed_on', 'subscription_date', 'expiry_date'];

/**
 * Set the same value for chosen details on every certificate in a fleet, with one reason.
 *
 * $set is field => new value, only for the fields being changed (anything else in a
 * certificate stays as it is). Each certificate goes through the same checks as editing
 * it alone, all inside ONE transaction: if any vehicle would be invalid, nothing is saved
 * and the message names the vehicle. Voided, superseded and rejected certificates are
 * skipped, as is any already holding the new values. Every changed certificate gets its
 * own entry in its history, marked as a fleet edit.
 *
 * Returns ['changed' => n, 'unchanged' => n, 'skipped' => n].
 */
function trk_amend_fleet(int $batchId, array $set, array $user, string $reason): array
{
    $set = array_intersect_key($set, array_flip(TRK_FLEET_EDITABLE));
    if (!$set) {
        throw new RuntimeException('Tick at least one detail to change for the whole fleet.');
    }
    if (mb_strlen(trim($reason)) < 5) {
        throw new RuntimeException('Say why these details are being changed (a few words at least). '
            . 'It is kept with every certificate.');
    }
    $certs = q('SELECT * FROM tracking_certificates WHERE batch_id = ? ORDER BY id', [$batchId])->fetchAll();
    if (!$certs) {
        throw new RuntimeException('No such fleet.');
    }

    $out = ['changed' => 0, 'unchanged' => 0, 'skipped' => 0];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($certs as $cert) {
            if (in_array((string)$cert['status'], ['voided', 'superseded'], true)
                || ($cert['approval_status'] ?? '') === 'rejected') {
                $out['skipped']++;
                continue;
            }
            try {
                trk_amend($cert, $set + trk_current_values($cert), $user, $reason, false, ['fleet' => $batchId]);
                $out['changed']++;
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'Nothing was changed.') {
                    $out['unchanged']++;
                    continue;
                }
                throw new RuntimeException($cert['reg_no'] . ': ' . $e->getMessage()
                    . ' Nothing in the fleet was changed.');
            }
        }
        if ($out['changed'] === 0) {
            throw new RuntimeException('Every vehicle in the fleet already has those details. Nothing was changed.');
        }
        audit('amend_tracking_batch', 'tracking_batch', $batchId,
              ['reason' => trim($reason), 'set' => $set] + $out);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $out;
}
