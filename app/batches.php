<?php
/**
 * app/batches.php  -  bulk certificates.
 *
 * A batch is one fleet's intake: many vehicles, one payment, one approval.
 * A certificate raised on its own is unchanged and does not belong to a batch.
 *
 * Nothing here writes anything until batch_create() is called, and batch_create()
 * is one transaction - a bad row can never leave half a batch behind.
 *
 * ---------------------------------------------------------------------------
 * TO INSTALL
 * ---------------------------------------------------------------------------
 *
 * 1. Save this file as app/batches.php
 *
 * 2. Run sql/migration_batches.sql
 *
 * 3. Add ONE line near the top of app/certificates.php, beside the clients.php one:
 *
 *        require_once __DIR__ . '/batches.php';
 *
 * 4. Worth doing, not required: in create_certificate(), replace the whole
 *    $snapshot = [ ... ]; block with
 *
 *        $snapshot = governor_snapshot($in);
 *
 *    Then one certificate and forty are printed from the same definition. Leave
 *    it and they stay two copies of the same list, which will drift the first
 *    time a field is added to the sheet.
 */
declare(strict_types=1);


// ---------------------------------------------------------------------------
// The snapshot, in one place
// ---------------------------------------------------------------------------

/**
 * Everything printed on a governor sheet, frozen.
 *
 * Both paths - one certificate or forty - build the snapshot HERE. Two copies of
 * this array would drift the first time a field is added to the sheet, and the
 * batch certificates would quietly print the old shape.
 */
function governor_snapshot(array $in): array
{
    return [
        'company' => company_details(),
        'signatory' => [
            'name' => setting('signatory_name', ''),
            'title' => setting('signatory_title', ''),
            'signature_image' => setting('signature_image', ''),
        ],
        'cert' => [
            'number' => '',          // filled in when accounts approves
            'type' => $in['type'],
            'is_renewal' => $in['type'] === 'Renewal',
            'issue_date_fmt' => fmt_date($in['issue_date']),
            'expiry_date_fmt' => fmt_date($in['expiry_date']),
            'validity_months' => (int)$in['validity_months'],
        ],
        'client' => [
            'name' => $in['client_name'],
            'phone' => $in['client_phone'],
            'address' => $in['client_address'],
        ],
        'vehicle' => [
            'reg_no' => strtoupper($in['reg_no']),
            'make' => $in['make'],
            'chassis_no' => $in['chassis_no'],
        ],
        'device' => [
            'model' => $in['device_model'],
            'serial_no' => $in['serial_no'],
            'unit_code' => $in['serial_no'],
            'set_speed_kmh' => (int)$in['set_speed_kmh'],
        ],
        'installation' => [
            'installed_on_fmt' => fmt_date($in['installed_on']),
            'technician' => $in['technician'],
        ],
    ];
}


// ---------------------------------------------------------------------------
// Reading the file
// ---------------------------------------------------------------------------

/** Letters and digits only, for comparing registrations and serials. */
function batch_key(?string $s): string
{
    return preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$s));
}

/**
 * One row per vehicle. Column order does not matter; the heading names do.
 *
 * Accepted headings (case and punctuation ignored):
 *   reg no | registration        -> reg_no          REQUIRED
 *   make                         -> make
 *   chassis no                   -> chassis_no
 *   device model | model         -> device_model
 *   serial no | serial           -> serial_no       REQUIRED
 *   set speed | speed            -> set_speed_kmh
 *   technician                   -> technician
 *   installed on | fitting date  -> installed_on
 *   validity months | validity   -> validity_months
 */
function batch_rows_from_csv(string $path): array
{
    $map = [
        'regno' => 'reg_no',            'registration' => 'reg_no',
        'registrationno' => 'reg_no',   'vehicle' => 'reg_no',
        'make' => 'make',               'model' => 'device_model',
        'chassisno' => 'chassis_no',    'chassis' => 'chassis_no',
        'devicemodel' => 'device_model',
        'serialno' => 'serial_no',      'serial' => 'serial_no',
        'setspeed' => 'set_speed_kmh',  'speed' => 'set_speed_kmh',
        'setspeedkmh' => 'set_speed_kmh',
        'technician' => 'technician',   'fitter' => 'technician',
        'installedon' => 'installed_on', 'fittingdate' => 'installed_on',
        'dateinstalled' => 'installed_on',
        'validitymonths' => 'validity_months', 'validity' => 'validity_months',
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

    // strip a UTF-8 byte order mark, which Excel adds and which otherwise
    // becomes part of the first heading
    $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$head[0]);

    $cols = [];
    foreach ($head as $i => $name) {
        $key = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$name));
        if (isset($map[$key])) {
            $cols[$i] = $map[$key];
        }
    }
    if (!in_array('reg_no', $cols, true) || !in_array('serial_no', $cols, true)) {
        fclose($fh);
        throw new RuntimeException(
            'The file needs at least a "Reg No" column and a "Serial No" column. '
            . 'Found: ' . implode(', ', array_map('strval', $head)));
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
    return $rows;
}


// ---------------------------------------------------------------------------
// The dry run
// ---------------------------------------------------------------------------

/**
 * The customer, however the caller spells it.
 *
 * The form posts client_name / client_phone / client_address; the library wants
 * name / phone / address. Getting that wrong does not fail loudly - it looks up
 * a customer called "" , finds nobody, and quietly creates a SECOND row for a
 * fleet already on file. So the mapping lives here, once, and both shapes work.
 */
function batch_client(array $in): array
{
    $c = [
        'name'    => trim((string)($in['name']    ?? $in['client_name']    ?? '')),
        'phone'   => trim((string)($in['phone']   ?? $in['client_phone']   ?? '')),
        'address' => trim((string)($in['address'] ?? $in['client_address'] ?? '')),
    ];
    if ($c['name'] === '') {
        throw new RuntimeException('The customer name is missing.');
    }
    return $c;
}


/**
 * Check every row and write nothing.
 *
 * Each row comes back with a verdict:
 *   new      - new vehicle, first certificate
 *   renewal  - the vehicle is on file already
 *   moved    - that unit is fitted to another vehicle; needs confirming
 *   blocked  - cannot be created, with the reason
 *
 * A blocked row does not stop the others. The page lets you drop it and go on.
 */
function batch_preview(array $client, array $rows, string $issueDate = ''): array
{
    $client    = batch_client($client);
    $issueDate = $issueDate !== '' ? $issueDate : date('Y-m-d');
    $seenReg = [];
    $seenSerial = [];
    $out = [];

    foreach ($rows as $row) {
        $r = $row + [
            'reg_no' => '', 'make' => '', 'chassis_no' => '', 'device_model' => '',
            'serial_no' => '', 'set_speed_kmh' => '', 'technician' => '',
            'installed_on' => '', 'validity_months' => '',
        ];
        $r['verdict'] = 'new';
        $r['notes']   = [];
        $r['needs_confirm'] = false;

        $regKey = batch_key($r['reg_no']);
        $serKey = batch_key($r['serial_no']);

        // -------------------------------------------------- cannot be created
        if ($regKey === '') {
            $r['verdict'] = 'blocked';
            $r['notes'][] = 'No registration number.';
        } elseif (isset($seenReg[$regKey])) {
            $r['verdict'] = 'blocked';
            $r['notes'][] = 'Registration repeated - also on line ' . $seenReg[$regKey] . '.';
        }
        if ($serKey === '') {
            $r['verdict'] = 'blocked';
            $r['notes'][] = 'No serial number.';
        } elseif (isset($seenSerial[$serKey])) {
            $r['verdict'] = 'blocked';
            $r['notes'][] = 'Serial repeated - also on line ' . $seenSerial[$serKey] . '.';
        }
        if ($r['installed_on'] !== '' && strtotime($r['installed_on']) === false) {
            $r['verdict'] = 'blocked';
            $r['notes'][] = 'Fitting date "' . $r['installed_on'] . '" is not a date.';
        }

        if ($r['verdict'] !== 'blocked') {
            $vehicle = vehicle_match($r['reg_no']);

            if ($vehicle) {
                $r['verdict'] = 'renewal';
                $r['notes'][] = 'On file already' . ($vehicle['make'] ? ' (' . $vehicle['make'] . ')' : '') . '.';

                $pending = q("SELECT id FROM certificates
                               WHERE vehicle_id = ? AND approval_status = 'pending'",
                             [$vehicle['id']])->fetch();
                if ($pending) {
                    $r['verdict'] = 'blocked';
                    $r['notes'][] = 'Already waiting for approval on another certificate.';
                }

                $live = q("SELECT expiry_date FROM certificates
                            WHERE vehicle_id = ? AND approval_status = 'approved'
                              AND status = 'issued' AND expiry_date >= ?
                            ORDER BY expiry_date DESC LIMIT 1",
                          [$vehicle['id'], $issueDate])->fetch();
                if ($live) {
                    $r['notes'][] = 'Current certificate runs to ' . fmt_date($live['expiry_date'])
                                  . ' - it stays live until this one is approved.';
                }

                if ($r['chassis_no'] !== '' && $vehicle['chassis_no'] !== ''
                    && batch_key($r['chassis_no']) !== batch_key($vehicle['chassis_no'])) {
                    $r['notes'][] = 'Chassis differs from the one on file ('
                                  . $vehicle['chassis_no'] . ') - it will be updated.';
                }
            }

            // the unit: is it somewhere else right now?
            $held = q("SELECT v.reg_no, i.id AS installation_id
                         FROM installations i
                         JOIN devices d  ON d.id = i.device_id
                         JOIN vehicles v ON v.id = i.vehicle_id
                        WHERE d.serial_no = ? AND i.removed_on IS NULL",
                      [$r['serial_no']])->fetch();

            if ($held && batch_key($held['reg_no']) !== $regKey) {
                $r['verdict'] = $r['verdict'] === 'blocked' ? 'blocked' : 'moved';
                $r['needs_confirm'] = true;
                $r['notes'][] = 'Unit ' . $r['serial_no'] . ' is fitted to ' . $held['reg_no']
                              . '. Confirm it was moved and that one will be closed.';
            }

            // chassis belonging to a different lorry is a data error, not a warning
            if ($r['chassis_no'] !== '') {
                $other = q("SELECT reg_no FROM vehicles
                             WHERE regexp_replace(upper(chassis_no), '[^A-Z0-9]', '', 'g') = ?
                               AND regexp_replace(upper(reg_no), '[^A-Z0-9]', '', 'g') <> ?",
                           [batch_key($r['chassis_no']), $regKey])->fetch();
                if ($other) {
                    $r['verdict'] = 'blocked';
                    $r['notes'][] = 'That chassis is recorded against ' . $other['reg_no'] . '.';
                }
            }
        }

        if ($regKey !== '' && !isset($seenReg[$regKey]))    { $seenReg[$regKey] = $r['line'] ?? '?'; }
        if ($serKey !== '' && !isset($seenSerial[$serKey])) { $seenSerial[$serKey] = $r['line'] ?? '?'; }

        $out[] = $r;
    }

    $counts = ['new' => 0, 'renewal' => 0, 'moved' => 0, 'blocked' => 0];
    foreach ($out as $r) { $counts[$r['verdict']]++; }

    return [
        'client' => client_match($client['name'], $client['phone']),
        'rows'   => $out,
        'counts' => $counts,
        'ok'     => count($out) - $counts['blocked'],
    ];
}


// ---------------------------------------------------------------------------
// Creating
// ---------------------------------------------------------------------------

/**
 * Create the batch. One transaction: all of it, or none of it.
 *
 * $rows are preview rows with verdict <> 'blocked'. A row marked 'moved' must
 * carry confirm => true, or this throws rather than quietly stealing a unit off
 * another lorry.
 *
 * Every certificate is created PENDING with no number, exactly as a single one is.
 */
function batch_create(array $client, array $rows, array $user,
                      string $issueDate = '', string $label = ''): array
{
    if (!$rows) {
        throw new RuntimeException('Nothing to create.');
    }
    $client    = batch_client($client);
    $issueDate = $issueDate !== '' ? $issueDate : date('Y-m-d');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // the client, once - which is what stops forty rows creating forty
        // slightly different spellings of the same fleet
        $found = client_match($client['name'], $client['phone']);
        if ($found) {
            q('UPDATE clients SET name = ?, phone = ?, address = ?, updated_at = now() WHERE id = ?',
              [$client['name'], $client['phone'], $client['address'], $found['id']]);
            $clientId = (int)$found['id'];
        } else {
            $clientId = (int)q('INSERT INTO clients (name, phone, address) VALUES (?, ?, ?) RETURNING id',
                [$client['name'], $client['phone'], $client['address']])->fetchColumn();
        }

        $batchId = (int)q("INSERT INTO certificate_batches (client_id, label, created_by)
                           VALUES (?, ?, ?) RETURNING id",
            [$clientId, $label !== '' ? $label : date('j M Y') . ' intake', $user['id']])
            ->fetchColumn();

        $made = [];
        foreach ($rows as $row) {
            if (($row['verdict'] ?? '') === 'blocked') {
                throw new RuntimeException('Line ' . ($row['line'] ?? '?')
                    . ' cannot be created: ' . implode(' ', $row['notes'] ?? []));
            }
            if (!empty($row['needs_confirm']) && empty($row['confirm'])) {
                throw new RuntimeException('Line ' . ($row['line'] ?? '?') . ' ('
                    . $row['reg_no'] . ') moves a unit off another vehicle and was not confirmed.');
            }

            $installedOn = $row['installed_on'] !== '' ? $row['installed_on'] : $issueDate;
            $months      = (int)($row['validity_months'] ?: 12);
            $expiry       = date('Y-m-d', strtotime($issueDate . ' +' . $months . ' months -1 day'));

            // vehicle
            $vehicle = vehicle_match($row['reg_no']);
            if ($vehicle) {
                q('UPDATE vehicles SET client_id = ?, make = ?, chassis_no = ? WHERE id = ?',
                  [$clientId, $row['make'], $row['chassis_no'], $vehicle['id']]);
                $vehicleId = (int)$vehicle['id'];
                $type = 'Renewal';
            } else {
                $vehicleId = (int)q('INSERT INTO vehicles (client_id, reg_no, make, chassis_no)
                                     VALUES (?, ?, ?, ?) RETURNING id',
                    [$clientId, plate_display($row['reg_no']), $row['make'], $row['chassis_no']])
                    ->fetchColumn();
                // 'Fitting', not 'New'. The certificates table only accepts the
                // two words the single form uses, and the database is right to
                // insist: a second vocabulary for the same thing would show up
                // on the register as a type nobody recognises.
                $type = 'Fitting';
            }

            // device
            $device = q('SELECT * FROM devices WHERE serial_no = ?', [$row['serial_no']])->fetch();
            if ($device) {
                q('UPDATE devices SET model = ?, set_speed_kmh = ? WHERE id = ?',
                  [$row['device_model'], (int)$row['set_speed_kmh'], $device['id']]);
                $deviceId = (int)$device['id'];
            } else {
                $deviceId = (int)q('INSERT INTO devices (model, serial_no, set_speed_kmh)
                                    VALUES (?, ?, ?) RETURNING id',
                    [$row['device_model'], $row['serial_no'], (int)$row['set_speed_kmh']])
                    ->fetchColumn();
            }

            // a unit moved from another vehicle: close the old fitting, with a reason
            q("UPDATE installations SET removed_on = ?,
                      removed_reason = COALESCE(NULLIF(removed_reason, ''), ?)
                 WHERE device_id = ? AND vehicle_id <> ? AND removed_on IS NULL",
              [$installedOn, 'Moved to ' . plate_display($row['reg_no']), $deviceId, $vehicleId]);

            // installation
            $installation = q('SELECT * FROM installations
                                WHERE vehicle_id = ? AND device_id = ? AND removed_on IS NULL',
                              [$vehicleId, $deviceId])->fetch();
            if ($installation) {
                $installationId = (int)$installation['id'];
            } else {
                $installationId = (int)q('INSERT INTO installations
                                          (vehicle_id, device_id, technician, installed_on)
                                          VALUES (?, ?, ?, ?) RETURNING id',
                    [$vehicleId, $deviceId, $row['technician'], $installedOn])->fetchColumn();
            }

            // the row we ended up with is the record of the fitting - read it back
            $fitted = q('SELECT installed_on, technician FROM installations WHERE id = ?',
                        [$installationId])->fetch() ?: [];

            $snapshot = governor_snapshot([
                'type' => $type,
                'issue_date' => $issueDate,
                'expiry_date' => $expiry,
                'validity_months' => $months,
                'client_name' => $client['name'],
                'client_phone' => $client['phone'],
                'client_address' => $client['address'],
                'reg_no' => plate_display($row['reg_no']),
                'make' => $row['make'],
                'chassis_no' => $row['chassis_no'],
                'device_model' => $row['device_model'],
                'serial_no' => $row['serial_no'],
                'set_speed_kmh' => (int)$row['set_speed_kmh'],
                'installed_on' => $fitted['installed_on'] ?? $installedOn,
                'technician' => $fitted['technician'] ?? $row['technician'],
            ]);

            // pending, no number, no payment reference - the batch holds that
            $certId = (int)q("INSERT INTO certificates
                  (type, client_id, vehicle_id, device_id, installation_id, issue_date, expiry_date,
                   snapshot, verify_token, issued_by, approval_status, batch_id)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?) RETURNING id", [
                $type, $clientId, $vehicleId, $deviceId, $installationId,
                $issueDate, $expiry, json_encode($snapshot),
                bin2hex(random_bytes(4)), $user['id'], $batchId,
            ])->fetchColumn();

            $made[] = ['id' => $certId, 'reg_no' => plate_display($row['reg_no']), 'type' => $type];
        }

        $pdo->commit();
    } catch (Throwable $err) {
        $pdo->rollBack();
        throw $err;
    }

    audit('create_batch', 'batch', $batchId,
          ['client' => $client['name'], 'vehicles' => count($made)]);

    return ['batch_id' => $batchId, 'certificates' => $made];
}


// ---------------------------------------------------------------------------
// Approving
// ---------------------------------------------------------------------------

/**
 * Is this payment reference already spoken for?
 *
 * Two places to look, because a single certificate holds its own reference and a
 * batch holds one for all of its own. Returns a sentence to show, or null.
 */
function payment_ref_in_use(string $ref): ?string
{
    $paid = q('SELECT p.batch_id, b.label, p.certificates
                 FROM batch_payments p
                 JOIN certificate_batches b ON b.id = p.batch_id
                WHERE upper(p.payment_ref) = upper(?)', [$ref])->fetch();
    if ($paid) {
        return 'That reference was already used on batch ' . $paid['batch_id']
             . ' (' . $paid['label'] . ', ' . $paid['certificates'] . ' certificates).';
    }

    $cert = q('SELECT id, number FROM certificates
                WHERE upper(payment_ref) = upper(?) AND batch_id IS NULL',
              [$ref])->fetch();
    if ($cert) {
        return 'That reference was already used on certificate '
             . ($cert['number'] ?: 'draft ' . $cert['id']) . '.';
    }
    return null;
}

/**
 * Approve a batch: one payment reference for the lot.
 *
 * $only, if given, is the certificate ids being approved. The rest stay pending -
 * so accounts can hold back two vehicles out of forty without blocking the other
 * thirty-eight, and the batch stays open until they are settled.
 *
 * Numbers are allocated here, in registration order, so the register reads
 * tidily instead of in whatever order the file happened to be in.
 */
function batch_approve(int $batchId, array $user, string $ref, string $note,
                       string $lpo = '', ?array $only = null): array
{
    $ref = trim($ref);
    $lpo = trim($lpo);
    // The same floor a single certificate has: say what you are releasing this
    // on. A reference, an LPO, or the payment message pasted in - but not nothing.
    if ($ref === '' && $lpo === '' && trim($note) === '') {
        throw new RuntimeException('Record the payment reference, the LPO number, or paste '
            . 'the payment message before releasing these certificates.');
    }

    $batch = q('SELECT * FROM certificate_batches WHERE id = ?', [$batchId])->fetch();
    if (!$batch) {
        throw new RuntimeException('No such batch.');
    }
    if ($batch['approval_status'] === 'approved') {
        throw new RuntimeException('That batch has already been approved.');
    }
    if ($ref !== '' && ($clash = payment_ref_in_use($ref))) {
        throw new RuntimeException($clash);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pending = q("SELECT id, vehicle_id, snapshot
                        FROM certificates
                       WHERE batch_id = ? AND approval_status = 'pending'
                       ORDER BY snapshot #>> '{vehicle,reg_no}'", [$batchId])->fetchAll();

        if ($only !== null) {
            $keep = array_map('intval', $only);
            $pending = array_values(array_filter($pending,
                        fn($c) => in_array((int)$c['id'], $keep, true)));
        }
        if (!$pending) {
            throw new RuntimeException('Nothing in that batch is waiting for approval.');
        }

        // this payment, recorded on its own. A second tranche adds a second row
        // rather than writing over the first.
        q("INSERT INTO batch_payments (batch_id, payment_ref, lpo_no, note, certificates, received_by)
           VALUES (?, ?, ?, ?, ?, ?)",
          [$batchId, $ref, $lpo, $note, count($pending), $user['id']]);

        q("UPDATE certificate_batches
              SET approved_by = ?, approved_at = now(), rejection_reason = NULL
            WHERE id = ?", [$user['id'], $batchId]);

        $vehicles = [];
        foreach ($pending as $cert) {
            $number   = next_certificate_number();
            $snapshot = is_string($cert['snapshot'])
                      ? json_decode($cert['snapshot'], true) : $cert['snapshot'];
            $snapshot['cert']['number'] = (string)$number;

            q("UPDATE certificates
                  SET number = ?, snapshot = ?, approval_status = 'approved', status = 'issued',
                      payment_ref = ?, lpo_no = ?, payment_note = ?,
                      approved_by = ?, approved_at = now(),
                      rejection_reason = NULL, pdf_path = NULL, pdf_sha256 = NULL
                WHERE id = ?",
              [$number, json_encode($snapshot), $ref, $lpo, $note, $user['id'], $cert['id']]);

            $vehicles[(int)$cert['vehicle_id']] = true;
        }

        // Retire the right one per vehicle, the same rule approve_certificate()
        // uses: the latest issued certificate is current, the others step back.
        foreach (array_keys($vehicles) as $vehicleId) {
            q("UPDATE certificates c
                  SET status = CASE WHEN c.id = cur.id THEN 'issued' ELSE 'superseded' END
                FROM (SELECT id FROM certificates
                       WHERE vehicle_id = ? AND approval_status = 'approved' AND status <> 'voided'
                       ORDER BY issue_date DESC, number DESC LIMIT 1) cur
                WHERE c.vehicle_id = ? AND c.approval_status = 'approved' AND c.status <> 'voided'",
              [$vehicleId, $vehicleId]);
        }

        // the batch closes only when nothing in it is still waiting
        $left = (int)q("SELECT count(*) FROM certificates
                         WHERE batch_id = ? AND approval_status = 'pending'",
                       [$batchId])->fetchColumn();
        if ($left === 0) {
            q("UPDATE certificate_batches SET approval_status = 'approved' WHERE id = ?", [$batchId]);
        }

        $pdo->commit();
    } catch (Throwable $err) {
        $pdo->rollBack();
        throw $err;
    }

    audit('approve_batch', 'batch', $batchId,
          ['ref' => $ref, 'lpo' => $lpo, 'approved' => count($pending), 'still_pending' => $left]);

    return ['approved' => count($pending), 'still_pending' => $left];
}

/** Reject the whole batch, with one reason. Nothing was superseded, so nothing is restored. */
function batch_reject(int $batchId, array $user, string $reason): int
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('A reason is required, so whoever raised it knows what to fix.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $n = q("UPDATE certificates
                   SET approval_status = 'rejected', rejection_reason = ?,
                       approved_by = ?, approved_at = now()
                 WHERE batch_id = ? AND approval_status = 'pending'",
               [$reason, $user['id'], $batchId])->rowCount();

        q("UPDATE certificate_batches
              SET approval_status = 'rejected', rejection_reason = ?,
                  approved_by = ?, approved_at = now()
            WHERE id = ?", [$reason, $user['id'], $batchId]);

        $pdo->commit();
    } catch (Throwable $err) {
        $pdo->rollBack();
        throw $err;
    }

    audit('reject_batch', 'batch', $batchId, ['reason' => $reason, 'certificates' => $n]);
    return $n;
}


// ---------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------

/** One batch with its certificates, for the approval screen and the register. */
function batch_get(int $batchId): ?array
{
    $batch = q('SELECT b.*, c.name AS client_name, c.phone AS client_phone,
                       u.name AS created_by_name, a.name AS approved_by_name
                  FROM certificate_batches b
                  JOIN clients c ON c.id = b.client_id
                  LEFT JOIN users u ON u.id = b.created_by
                  LEFT JOIN users a ON a.id = b.approved_by
                 WHERE b.id = ?', [$batchId])->fetch();
    if (!$batch) {
        return null;
    }
    $batch['payments'] = q('SELECT p.*, u.name AS received_by_name
                              FROM batch_payments p
                              LEFT JOIN users u ON u.id = p.received_by
                             WHERE p.batch_id = ? ORDER BY p.received_at', [$batchId])->fetchAll();
    $batch['certificates'] = q("SELECT id, number, type, approval_status, status,
                                       issue_date, expiry_date,
                                       snapshot #>> '{vehicle,reg_no}'   AS reg_no,
                                       snapshot #>> '{device,serial_no}' AS serial_no
                                  FROM certificates WHERE batch_id = ?
                                 ORDER BY reg_no", [$batchId])->fetchAll();
    return $batch;
}

/** Batches for a list screen, newest first. */
function batch_list(string $status = '', int $limit = 50): array
{
    $where = $status !== '' ? 'WHERE b.approval_status = ?' : '';
    $args  = $status !== '' ? [$status] : [];
    return q("SELECT b.*, c.name AS client_name,
                     (SELECT string_agg(NULLIF(p.payment_ref, ''), ', ' ORDER BY p.received_at)
                        FROM batch_payments p WHERE p.batch_id = b.id) AS payment_refs,
                     count(t.id)                                        AS vehicles,
                     count(t.id) FILTER (WHERE t.approval_status = 'pending')  AS pending,
                     count(t.id) FILTER (WHERE t.approval_status = 'approved') AS approved
                FROM certificate_batches b
                JOIN clients c ON c.id = b.client_id
                LEFT JOIN certificates t ON t.batch_id = b.id
                $where
               GROUP BY b.id, c.name
               ORDER BY b.created_at DESC
               LIMIT " . (int)$limit, $args)->fetchAll();
}