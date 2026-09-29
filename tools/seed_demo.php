<?php
/**
 * Demo data for testing. Creates sample clients, vehicles, governors and certificates
 * through the normal issuing code, so snapshots, numbering and QR links are all real.
 *
 * Put this file in the project's tools/ folder and run it from the command line:
 *
 *   php tools/seed_demo.php          add the demo data
 *   php tools/seed_demo.php remove   delete it again
 *
 * Demo clients are marked by their @demo.invalid e-mail address and demo certificates by
 * a receipt reference starting with DEMO-, which is how "remove" finds them.
 *
 * Do NOT run this once the system holds real certificates: it uses real certificate
 * numbers from the counter, and those numbers are then gone for good.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

$mode = $argv[1] ?? 'add';

// ---------------------------------------------------------------- remove

if ($mode === 'remove') {
    $pdo = db();
    $pdo->beginTransaction();
    $certs = q("DELETE FROM certificates WHERE receipt_ref LIKE 'DEMO-%' RETURNING id")->rowCount();
    q("DELETE FROM installations WHERE vehicle_id IN
         (SELECT v.id FROM vehicles v JOIN clients c ON c.id = v.client_id
          WHERE c.email LIKE '%@demo.invalid')");
    q("DELETE FROM vehicles WHERE client_id IN (SELECT id FROM clients WHERE email LIKE '%@demo.invalid')");
    q("DELETE FROM devices WHERE serial_no LIKE 'DEMO%'");
    $clients = q("DELETE FROM clients WHERE email LIKE '%@demo.invalid' RETURNING id")->rowCount();
    $pdo->commit();
    echo "Removed {$certs} demo certificates and {$clients} demo clients.\n";
    echo "Certificate numbers they used are not reused - that is deliberate.\n";
    exit;
}

// ------------------------------------------------------------------- add

$user = q("SELECT * FROM users WHERE active ORDER BY id LIMIT 1")->fetch();
if (!$user) {
    exit("Create a staff account first:  php tools/create_user.php \"Name\" you@example.com supervisor\n");
}

$real = (int)q("SELECT count(*) FROM certificates WHERE receipt_ref NOT LIKE 'DEMO-%'")->fetchColumn();
if ($real > 0) {
    echo "Warning: the database already holds {$real} certificate(s) that are not demo data.\n";
    echo "Type YES to add demo data anyway: ";
    if (trim((string)fgets(STDIN)) !== 'YES') {
        exit("Nothing added.\n");
    }
}

/**
 * Each row: client, phone, locality, reg no, make, chassis, serial, unit code,
 * days since the certificate was issued, service type.
 * The spread of issue dates gives you valid, expiring-soon and expired certificates.
 */
$rows = [
    ['Sasere Enterprises Ltd',      '0731 040 404', 'NAIROBI',  'KDK 017N', 'FUSO',       'P006041',  'DEMO231100664', 'DEMO012231100664C',  20, 'Renewal'],
    ['Riverside Haulage Ltd',       '0722 415 880', 'NAIROBI',  'KDA 442C', 'ISUZU',      'P114520',  'DEMO231100701', 'DEMO012231100701A',  45, 'Renewal'],
    ['Mombasa Road Logistics',      '0710 336 214', 'MOMBASA',  'KCX 778Q', 'HINO',       'P220913',  'DEMO231100742', 'DEMO012231100742B', 110, 'Fitting'],
    ['Nakuru Fresh Produce Ltd',    '0733 902 117', 'NAKURU',   'KDB 194X', 'MITSUBISHI', 'P305774',  'DEMO231100788', 'DEMO012231100788C', 175, 'Renewal'],
    ['Thika Cement Transporters',   '0726 550 341', 'THIKA',    'KCP 663H', 'SCANIA',     'P412008',  'DEMO231100815', 'DEMO012231100815D', 240, 'Renewal'],
    ['Kisumu Lakeside Movers',      '0708 771 902', 'KISUMU',   'KDD 205R', 'TOYOTA',     'P508117',  'DEMO231100860', 'DEMO012231100860E', 300, 'Fitting'],
    ['Eldoret Grain Carriers Ltd',  '0745 118 630', 'ELDORET',  'KBY 940M', 'FUSO',       'P611249',  'DEMO231100901', 'DEMO012231100901F', 352, 'Renewal'],
    ['Athi River Quarry Services',  '0712 664 205', 'MACHAKOS', 'KCE 087T', 'ISUZU',      'P702336',  'DEMO231100933', 'DEMO012231100933G', 358, 'Renewal'],
    ['Westlands Staff Shuttle Ltd', '0729 300 448', 'NAIROBI',  'KDF 512V', 'TOYOTA',     'P803441',  'DEMO231100975', 'DEMO012231100975H', 372, 'Renewal'],
    ['Coastal Fuel Tankers Ltd',    '0700 828 156', 'MOMBASA',  'KCJ 331B', 'HINO',       'P900558',  'DEMO231101012', 'DEMO012231101012J', 420, 'Fitting'],
];

$made = [];
foreach ($rows as $r) {
    list($name, $phone, $town, $reg, $make, $chassis, $serial, $unit, $daysAgo, $type) = $r;

    $issue = date('Y-m-d', strtotime("-{$daysAgo} days"));
    $installed = date('Y-m-d', strtotime("-" . ($daysAgo + random_int(30, 1200)) . " days"));

    $cert = create_certificate([
        'client_name' => $name,
        'client_phone' => $phone,
        'client_address' => $town,
        'reg_no' => $reg,
        'make' => $make,
        'chassis_no' => $chassis,
        'device_model' => setting('default_model', 'INTELSPEED™'),
        'serial_no' => $serial,
        'unit_code' => $unit,
        'set_speed_kmh' => setting('default_speed', '80'),
        'installed_on' => $installed,
        'technician' => setting('default_technician', 'GAPTECH'),
        'type' => $type,
        'issue_date' => $issue,
        'validity_months' => '12',
        'expiry_date' => default_expiry($issue),
        'receipt_ref' => 'DEMO-' . strtoupper(bin2hex(random_bytes(3))),
    ], $user);

    // mark the client as demo data and give the first three a portal login
    q("UPDATE clients SET email = ? WHERE id = ?",
        ['demo' . $cert['client_id'] . '@demo.invalid', $cert['client_id']]);

    $made[] = ['number' => $cert['number'], 'reg' => $reg, 'client' => $name,
               'expires' => $cert['expiry_date'], 'client_id' => $cert['client_id']];
    echo "Certificate {$cert['number']}  {$reg}  {$name}  expires {$cert['expiry_date']}\n";
}

// portal logins for the first three clients, so you can test the client side
$password = 'Demo1234';
foreach (array_slice($made, 0, 3) as $m) {
    q('UPDATE clients SET password_hash = ? WHERE id = ?',
        [password_hash($password, PASSWORD_DEFAULT), $m['client_id']]);
    echo "Portal login: demo{$m['client_id']}@demo.invalid / {$password}  ({$m['client']})\n";
}

echo "\nDone. " . count($made) . " demo certificates added.\n";
echo "You should now see valid, expiring-soon and expired ones on the dashboard.\n";
echo "Remove it all with:  php tools/seed_demo.php remove\n";
