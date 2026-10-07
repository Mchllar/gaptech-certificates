<?php
/**
 * Demo data for testing. Creates sample clients, vehicles, governors and certificates
 * through the normal issuing code, so snapshots, numbering, approvals and QR links are real.
 *
 * Put this file in the project's tools/ folder and run it from the command line:
 *
 *   php tools/seed_demo.php          add the demo data
 *   php tools/seed_demo.php remove   delete it again
 *
 * Demo clients are marked by their @demo.invalid e-mail address, and that is how
 * "remove" finds everything attached to them.
 *
 * Do NOT run this once the system holds real certificates: approving a demo certificate
 * takes a real number from the counter, and that number is then gone for good.
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
    $certs = q("DELETE FROM certificates WHERE client_id IN
                  (SELECT id FROM clients WHERE email LIKE '%@demo.invalid')
                RETURNING id")->rowCount();
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

$admin = q("SELECT * FROM users WHERE role = 'administrator' AND active ORDER BY id LIMIT 1")->fetch();
if (!$admin) {
    exit("Create an administrator account first, then run this again.\n");
}

$accounts = q("SELECT * FROM users WHERE role = 'accounts' AND active ORDER BY id LIMIT 1")->fetch();
if (!$accounts) {
    echo "There is no accounts user, so nothing can be approved and every demo\n";
    echo "certificate will sit as Pending approval. Set one with:\n";
    echo "  UPDATE users SET role = 'accounts' WHERE username = '...';\n";
    echo "Continue anyway? Type YES: ";
    if (trim((string)fgets(STDIN)) !== 'YES') {
        exit("Nothing added.\n");
    }
}

$real = (int)q("SELECT count(*) FROM certificates c
                  LEFT JOIN clients cl ON cl.id = c.client_id
                 WHERE coalesce(cl.email, '') NOT LIKE '%@demo.invalid'")->fetchColumn();
if ($real > 0) {
    echo "Warning: the database already holds {$real} certificate(s) that are not demo data.\n";
    echo "Type YES to add demo data anyway: ";
    if (trim((string)fgets(STDIN)) !== 'YES') {
        exit("Nothing added.\n");
    }
}

/** An M-Pesa confirmation in the usual Safaricom wording. */
function demo_mpesa(string $ref, string $amount, string $when): string
{
    return $ref . ' Confirmed. Ksh' . $amount . ' sent to GAPTECH SOLUTIONS LTD'
        . ' for account GAP on ' . $when . '. New M-PESA balance is Ksh'
        . number_format(random_int(1200, 48000), 2) . '. Transaction cost, Ksh0.00.';
}

/**
 * Each row: client, phone, locality, reg no, make, chassis, serial,
 * days since issue, service type, outcome.
 *
 * outcome:  approve    paid for and live
 *           pending    issued, waiting on accounts
 *           reject     accounts found no payment
 *           void       approved, then cancelled
 *           supersede  approved, then renewed - the first one retires
 *
 * The spread of issue dates gives valid, expiring-soon and expired certificates.
 */
$rows = [
    ['SASERE ENTERPRISES LTD',      '0731 040 404', 'NAIROBI',  'KDK 017N', 'FUSO',       'P006041', 'DEMO231100664',  20, 'Renewal',  'approve'],
    ['RIVERSIDE HAULAGE LTD',       '0722 415 880', 'NAIROBI',  'KDA 442C', 'ISUZU',      'P114520', 'DEMO231100701',  45, 'Renewal',  'approve'],
    ['MOMBASA ROAD LOGISTICS',      '0710 336 214', 'MOMBASA',  'KCX 778Q', 'HINO',       'P220913', 'DEMO231100742', 110, 'Fitting',  'approve'],
    ['NAKURU FRESH PRODUCE LTD',    '0733 902 117', 'NAKURU',   'KDB 194X', 'MITSUBISHI', 'P305774', 'DEMO231100788', 175, 'Renewal',  'approve'],
    ['THIKA CEMENT TRANSPORTERS',   '0726 550 341', 'THIKA',    'KCP 663H', 'SCANIA',     'P412008', 'DEMO231100815', 240, 'Renewal',  'void'],
    ['KISUMU LAKESIDE MOVERS',      '0708 771 902', 'KISUMU',   'KDD 205R', 'TOYOTA',     'P508117', 'DEMO231100860', 300, 'Fitting',  'approve'],
    ['ELDORET GRAIN CARRIERS LTD',  '0745 118 630', 'ELDORET',  'KBY 940M', 'FUSO',       'P611249', 'DEMO231100901', 352, 'Renewal',  'approve'],
    ['ATHI RIVER QUARRY SERVICES',  '0712 664 205', 'MACHAKOS', 'KCE 087T', 'ISUZU',      'P702336', 'DEMO231100933', 358, 'Renewal',  'supersede'],
    ['WESTLANDS STAFF SHUTTLE LTD', '0729 300 448', 'NAIROBI',  'KDF 512V', 'TOYOTA',     'P803441', 'DEMO231100975', 372, 'Renewal',  'approve'],
    ['COASTAL FUEL TANKERS LTD',    '0700 828 156', 'MOMBASA',  'KCJ 331B', 'HINO',       'P900558', 'DEMO231101012', 420, 'Fitting',  'approve'],
    ['NDOVU MOVERS LTD',            '0714 502 388', 'NAIROBI',  'KDG 186C', 'SCANIA',     'P911664', 'DEMO231101055',   3, 'Fitting',  'pending'],
    ['SUMMIT HARDWARE SUPPLIES',    '0738 902 551', 'NAIROBI',  'KDF 745B', 'ISUZU',      'P922771', 'DEMO231101088',   1, 'Renewal',  'pending'],
    ['BAHARI TOURS AND TRAVEL',     '0701 884 293', 'MOMBASA',  'KCM 613V', 'TOYOTA',     'P933887', 'DEMO231101120',   5, 'Fitting',  'reject'],
];

/** Issue one certificate from a row and return the fresh database record. */
function demo_issue(array $in, array $admin): array
{
    $cert = create_certificate($in, $admin);
    $id = is_array($cert) ? (int)$cert['id'] : (int)$cert;
    return q('SELECT * FROM certificates WHERE id = ?', [$id])->fetch();
}

$made = [];
$refSeed = 0;

foreach ($rows as $r) {
    list($name, $phone, $town, $reg, $make, $chassis, $serial, $daysAgo, $type, $outcome) = $r;

    $issue     = date('Y-m-d', strtotime("-{$daysAgo} days"));
    $installed = date('Y-m-d', strtotime("-" . ($daysAgo + random_int(30, 1200)) . " days"));

    $in = [
        'client_name'    => $name,
        'client_phone'   => $phone,
        'client_address' => $town,
        'reg_no'         => $reg,
        'make'           => $make,
        'chassis_no'     => $chassis,
        'device_model'   => trim((string)setting('default_model')) ?: 'INTELSPEED™',
        'serial_no'      => $serial,
        'set_speed_kmh'  => trim((string)setting('default_speed')) ?: '80',
        'installed_on'   => $installed,
        'technician'     => trim((string)setting('default_technician')) ?: 'GAPTECH',
        'type'           => $type,
        'issue_date'     => $issue,
        'validity_months' => '12',
        'expiry_date'    => default_expiry($issue),
    ];

    $cert = demo_issue($in, $admin);

    // Mark the client as demo data straight away, so a later failure still leaves
    // everything removable.
    q("UPDATE clients SET email = ? WHERE id = ?",
        ['demo' . $cert['client_id'] . '@demo.invalid', $cert['client_id']]);

    $label = 'pending';

    if ($outcome === 'pending' || !$accounts) {
        echo "Pending      {$reg}  {$name}\n";
    } elseif ($outcome === 'reject') {
        reject_certificate($cert, $accounts, 'No payment traced against this registration.');
        echo "Rejected     {$reg}  {$name}\n";
    } else {
        $ref  = 'T' . strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ0123456789'), 0, 9));
        $note = demo_mpesa($ref, $type === 'Fitting' ? '3,500' : '2,800',
                           date('j/n/y g:i A', strtotime($issue . ' +9 hours')));
        $cert = approve_certificate($cert, $accounts, $ref, $note);
        $label = $cert['number'];
        echo "Certificate {$label}  {$reg}  {$name}  expires {$cert['expiry_date']}\n";

        if ($outcome === 'void') {
            q("UPDATE certificates SET status = 'voided' WHERE id = ?", [$cert['id']]);
            echo "             ...then voided\n";
        }

        if ($outcome === 'supersede') {
            // A renewal issued a fortnight ago. Approving it retires the one above.
            $in2 = $in;
            $in2['type']        = 'Renewal';
            $in2['issue_date']  = date('Y-m-d', strtotime('-14 days'));
            $in2['expiry_date'] = default_expiry($in2['issue_date']);

            $cert2 = demo_issue($in2, $admin);
            $ref2  = 'T' . strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ0123456789'), 0, 9));
            $cert2 = approve_certificate($cert2, $accounts, $ref2,
                        demo_mpesa($ref2, '2,800', date('j/n/y g:i A', strtotime('-14 days +10 hours'))));
            echo "Certificate {$cert2['number']}  {$reg}  renewal - the earlier one is now superseded\n";
        }
    }

    $made[] = ['id' => $cert['id'], 'number' => $cert['number'], 'reg' => $reg,
               'client' => $name, 'client_id' => $cert['client_id'], 'outcome' => $outcome];
}

// Portal logins for the first three clients, so the client side can be tested.
$password = 'Demo@1234';
foreach (array_slice($made, 0, 3) as $m) {
    q('UPDATE clients SET password_hash = ? WHERE id = ?',
        [password_hash($password, PASSWORD_DEFAULT), $m['client_id']]);
    echo "Portal login: demo{$m['client_id']}@demo.invalid / {$password}  ({$m['client']})\n";
}

// Mark three approved ones as already printed, so the "cannot amend after release"
// rule can be seen. Pending ones are skipped - they cannot have been released.
$printed = 0;
foreach ($made as $m) {
    if ($printed >= 3 || $m['number'] === null) {
        continue;
    }
    q("UPDATE certificates SET print_count = 1, released_at = issued_at WHERE id = ?", [$m['id']]);
    $printed++;
}

// One corrected payment, so the "edited" tag and the audit trail have an example.
$fix = q("SELECT c.* FROM certificates c JOIN clients cl ON cl.id = c.client_id
           WHERE cl.email LIKE '%@demo.invalid' AND c.approval_status = 'approved'
           ORDER BY c.id DESC LIMIT 1")->fetch();
if ($fix && $accounts) {
    q("UPDATE certificates SET payment_edited_by = ?, payment_edited_at = now() WHERE id = ?",
        [$accounts['id'], $fix['id']]);
    audit('edit_payment_ref', 'certificate', (int)$fix['id'], [
        'from_ref' => 'TXX0000000',
        'to_ref'   => $fix['payment_ref'],
        'reason'   => 'Reference was mistyped at approval (demo data).',
    ]);
    echo "Marked certificate {$fix['number']} as having had its payment reference corrected.\n";
}

echo "\nDone. " . count($made) . " demo certificates added.\n";
echo "The dashboard should now show valid, expiring-soon, expired, pending,\n";
echo "rejected, superseded and voided certificates, and the payments register\n";
echo "should have approvals with M-Pesa messages against them.\n";
echo "Remove it all with:  php tools/seed_demo.php remove\n";
