<?php
/**
 * Everything to do with issuing a certificate, drawing the sheet and making the PDF.
 */
declare(strict_types=1);

/** Next certificate number. Locks the counter so two users can never get the same one. */
function next_certificate_number(): int
{
    $row = q("SELECT value FROM settings WHERE key = 'last_cert_number' FOR UPDATE")->fetch();
    if (!$row) {
        throw new RuntimeException("Setting 'last_cert_number' is missing. Run sql/schema.sql.");
    }
    $next = (int)$row['value'] + 1;
    q("UPDATE settings SET value = ? WHERE key = 'last_cert_number'", [(string)$next]);
    return $next;
}

/** Company details and signatory, as printed on the sheet. */
function company_details(): array
{
    return [
        'name' => setting('company_name', 'GAPTECH Solutions Ltd'),
        'name_caps' => strtoupper(setting('company_name', 'GAPTECH Solutions Ltd')),
        'tagline' => setting('tagline', 'Vehicle tracking, Fleet Management & IT Solutions'),
        'address_lines' => [setting('address_line1', ''), setting('address_line2', '')],
        'cell' => setting('cell', ''),
        'landline' => setting('landline', ''),
        'email' => setting('email', ''),
        'reg_no' => setting('reg_no', ''),
        'dealer_no' => setting('dealer_no', ''),
        'dealer_name' => setting('dealer_name', ''),
        'kebs_permit' => setting('kebs_permit', ''),
        'stamp_image' => setting('stamp_image', ''),
    ];
}

/**
 * Create a certificate from one form submission.
 *
 * Client, vehicle, device and installation are created if they are new and reused if they
 * already exist, so staff only ever fill in one form. Everything printed on the sheet is
 * copied into the snapshot, so a reprint years later shows exactly what was issued.
 */
function create_certificate(array $in, array $user): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // client (matched on phone, else name)
        $client = q('SELECT * FROM clients WHERE lower(name) = lower(?) OR (phone <> \'\' AND phone = ?)',
            [$in['client_name'], $in['client_phone']])->fetch();
        if ($client) {
            q('UPDATE clients SET name = ?, phone = ?, address = ?, updated_at = now() WHERE id = ?',
                [$in['client_name'], $in['client_phone'], $in['client_address'], $client['id']]);
            $clientId = (int)$client['id'];
        } else {
            $clientId = (int)q('INSERT INTO clients (name, phone, address) VALUES (?, ?, ?) RETURNING id',
                [$in['client_name'], $in['client_phone'], $in['client_address']])->fetchColumn();
        }

        // vehicle (registration number is unique)
        $vehicle = q('SELECT * FROM vehicles WHERE upper(reg_no) = upper(?)', [$in['reg_no']])->fetch();
        if ($vehicle) {
            q('UPDATE vehicles SET client_id = ?, make = ?, chassis_no = ? WHERE id = ?',
                [$clientId, $in['make'], $in['chassis_no'], $vehicle['id']]);
            $vehicleId = (int)$vehicle['id'];
        } else {
            $vehicleId = (int)q('INSERT INTO vehicles (client_id, reg_no, make, chassis_no)
                                 VALUES (?, upper(?), ?, ?) RETURNING id',
                [$clientId, $in['reg_no'], $in['make'], $in['chassis_no']])->fetchColumn();
        }

        // device (serial number is unique)
        $device = q('SELECT * FROM devices WHERE serial_no = ?', [$in['serial_no']])->fetch();
        if ($device) {
            q('UPDATE devices SET model = ?, set_speed_kmh = ? WHERE id = ?',
                [$in['device_model'], (int)$in['set_speed_kmh'], $device['id']]);
            $deviceId = (int)$device['id'];
        } else {
            $deviceId = (int)q('INSERT INTO devices (model, serial_no, set_speed_kmh)
                                VALUES (?, ?, ?) RETURNING id',
                [$in['device_model'], $in['serial_no'], (int)$in['set_speed_kmh']])->fetchColumn();
        }

        // installation
        $installation = q('SELECT * FROM installations WHERE vehicle_id = ? AND device_id = ? AND removed_on IS NULL',
            [$vehicleId, $deviceId])->fetch();
        if ($installation) {
            $installationId = (int)$installation['id'];
        } else {
            $installationId = (int)q('INSERT INTO installations (vehicle_id, device_id, technician, installed_on)
                                      VALUES (?, ?, ?, ?) RETURNING id',
                [$vehicleId, $deviceId, $in['technician'], $in['installed_on']])->fetchColumn();
        }

        $number = next_certificate_number();
        $token = bin2hex(random_bytes(4));
        $snapshot = [
            'company' => company_details(),
            'signatory' => [
                'name' => setting('signatory_name', ''),
                'title' => setting('signatory_title', ''),
                'signature_image' => setting('signature_image', ''),
            ],
            'cert' => [
                'number' => (string)$number,
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
                // the cards print this; the separate unit code was dropped, so it is the serial number
                'unit_code' => $in['serial_no'],
                'set_speed_kmh' => (int)$in['set_speed_kmh'],
            ],
            'installation' => [
                'installed_on_fmt' => fmt_date($in['installed_on']),
                'technician' => $in['technician'],
            ],
        ];

        $id = (int)q('INSERT INTO certificates
              (number, type, client_id, vehicle_id, device_id, installation_id, issue_date, expiry_date,
               receipt_ref, snapshot, verify_token, issued_by)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id', [
            $number, $in['type'], $clientId, $vehicleId, $deviceId, $installationId,
            $in['issue_date'], $in['expiry_date'], $in['receipt_ref'] ?? '',
            json_encode($snapshot), $token, $user['id'],
        ])->fetchColumn();

        $pdo->commit();
    } catch (Throwable $err) {
        $pdo->rollBack();
        throw $err;
    }

    audit('issue_certificate', 'certificate', $id, ['number' => $number]);
    return q('SELECT * FROM certificates WHERE id = ?', [$id])->fetch();
}

/** Public address a QR code points to. */
function verify_url(array $cert): string
{
    global $config;
    return rtrim($config['base_url'], '/') . '/verify.php?c=' . $cert['number'] . '-' . $cert['verify_token'];
}

/**
 * The certificate number in the style of the old numbering machine, drawn as an SVG image.
 */
function certificate_number_svg(string $number): string
{
    $x0 = 8; $x1 = 50; $y0 = 6; $ym = 50; $y1 = 94; $g = 5; $cell = 74;
    $seg = [
        'a' => [$x0 + $g, $y0, $x1 - $g, $y0], 'b' => [$x1, $y0 + $g, $x1, $ym - $g],
        'c' => [$x1, $ym + $g, $x1, $y1 - $g], 'd' => [$x0 + $g, $y1, $x1 - $g, $y1],
        'e' => [$x0, $ym + $g, $x0, $y1 - $g], 'f' => [$x0, $y0 + $g, $x0, $ym - $g],
        'g' => [$x0 + $g, $ym, $x1 - $g, $ym],
    ];
    $digits = [
        '0' => 'abcdef', '1' => '', '2' => 'abged', '3' => 'abgcd', '4' => 'fgbc',
        '5' => 'afgcd', '6' => 'afgedc', '7' => 'abc', '8' => 'abcdefg', '9' => 'abcdfg',
    ];
    $parts = '';
    $chars = str_split($number);
    foreach ($chars as $i => $ch) {
        $dx = $i * $cell;
        if ($ch === '1') {                       // a single upright with a small flag
            $mx = intdiv($x0 + $x1, 2) + 6 + $dx;
            $parts .= '<line x1="' . ($mx - 14) . '" y1="' . ($y0 + 12) . '" x2="' . $mx . '" y2="' . ($y0 + 1) . '"/>';
            $parts .= '<line x1="' . $mx . '" y1="' . ($y0 + 1) . '" x2="' . $mx . '" y2="' . $y1 . '"/>';
            continue;
        }
        foreach (str_split($digits[$ch] ?? '') as $s) {
            list($ax, $ay, $bx, $by) = $seg[$s];
            $parts .= '<line x1="' . ($ax + $dx) . '" y1="' . $ay . '" x2="' . ($bx + $dx) . '" y2="' . $by . '"/>';
        }
    }
    $width = count($chars) * $cell;
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' 100" width="' . $width
        . '" height="100"><g stroke="#1e1e1e" stroke-width="12" stroke-linecap="square">' . $parts . '</g></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/**
 * Render the sheet.
 *
 * $mode 'screen' produces a page for the browser (also used for browser printing);
 * $mode 'pdf' uses file:// paths and the zoom wkhtmltopdf needs.
 */
function render_sheet(array $cert, string $mode = 'screen'): string
{
    global $config;

    // These are declared global because the drawing helpers in sheet_helpers.php read them.
    global $d, $assets, $zoom, $qr, $certno_img, $logo, $logo_gold, $kebs, $sticker_bg, $microtext;

    $d = json_decode($cert['snapshot'], true);
    $sheetToCss = 1123 / 2338;                       // sheet units -> CSS pixels

    if ($mode === 'pdf') {
        $assets = 'file://' . str_replace('\\', '/', ROOT_DIR . '/public/assets');
        $zoom = round($sheetToCss * (float)$config['render_zoom'], 5);
    } else {
        $assets = 'assets';
        $zoom = round($sheetToCss, 5);
    }

    $qr = QrCode::dataUri(verify_url($cert));
    $certno_img = certificate_number_svg((string)$cert['number']);
    $logo = $assets . '/gap_logo.png';
    $logo_gold = $assets . '/gap_logo_gold.png';
    $kebs = $assets . '/kebs_mark.png';
    $sticker_bg = $assets . '/sticker_bg.png';
    $microtext = str_repeat('GAPTECHSOLUTIONSLTD', 12);

    ob_start();
    include APP_DIR . '/views/sheet.php';
    return (string)ob_get_clean();
}

/**
 * Produce the PDF and remember where it is. Returns the file path, or null when
 * wkhtmltopdf is not configured (the browser's own "Save as PDF" is then used instead).
 */
function certificate_pdf(array $cert, bool $force = false): ?string
{
    global $config;
    if (empty($config['wkhtmltopdf'])) {
        return null;
    }
    $dir = rtrim($config['storage'], '/\\') . '/certificates';
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create storage folder: ' . $dir);
    }
    $pdfPath = $dir . '/certificate-' . $cert['number'] . '.pdf';
    if (!$force && is_file($pdfPath) && !empty($cert['pdf_path'])) {
        return $pdfPath;
    }

    $htmlPath = tempnam(sys_get_temp_dir(), 'cert') . '.html';
    file_put_contents($htmlPath, render_sheet($cert, 'pdf'));

    $cmd = escapeshellarg($config['wkhtmltopdf'])
        . ' --quiet --page-size A4 --orientation Landscape'
        . ' --margin-top 0 --margin-bottom 0 --margin-left 0 --margin-right 0'
        . ' --dpi 96 --enable-local-file-access'
        . ' ' . escapeshellarg($htmlPath) . ' ' . escapeshellarg($pdfPath) . ' 2>&1';
    exec($cmd, $output, $status);
    @unlink($htmlPath);

    if ($status !== 0 || !is_file($pdfPath)) {
        throw new RuntimeException('wkhtmltopdf failed: ' . implode("\n", $output));
    }

    q('UPDATE certificates SET pdf_path = ?, pdf_sha256 = ? WHERE id = ?',
        [$pdfPath, hash_file('sha256', $pdfPath), $cert['id']]);
    return $pdfPath;
}

/** Expiry date the certificate wording implies: twelve months from issue, less a day. */
function default_expiry(string $issueDate, int $months = 12): string
{
    $date = new DateTime($issueDate);
    $date->modify("+{$months} months")->modify('-1 day');
    return $date->format('Y-m-d');
}

function certificate_status(array $cert): string
{
    if ($cert['status'] === 'voided') {
        return 'Voided';
    }
    return strtotime($cert['expiry_date']) < strtotime(date('Y-m-d')) ? 'Expired' : 'Valid';
}
