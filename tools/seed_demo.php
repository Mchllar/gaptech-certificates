<?php
/**
 * Demo data for the speed governor certificates.
 *
 * Put this file in the project's tools/ folder and run it from the command line:
 *
 *   php tools/seed_demo.php              add the demo data
 *   php tools/seed_demo.php add 5        add it, attached to existing client id 5
 *   php tools/seed_demo.php remove       delete it again
 *
 * Everything goes through the normal issuing code - create_certificate(),
 * approve_certificate(), reject, void - so snapshots, numbering, superseding and
 * the approval trail are real rather than rows pushed into the table.
 *
 * IT NEVER TOUCHES THE users TABLE. Staff logins and passwords are left alone.
 * Client portal credentials are left alone too: pass an existing client's id and
 * the demo certificates are attached to them, so you can sign in as that client
 * and see the portal side without creating a new login.
 *
 * Demo certificates are marked by a verify_token beginning "demo-", and that is
 * how "remove" finds them.
 *
 * Do NOT run this once the system holds real certificates: approving a demo one
 * takes a real number from the counter, and that number is then gone for good.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

$mode     = $argv[1] ?? 'add';
$clientId = isset($argv[2]) ? (int)$argv[2] : 0;

// ---------------------------------------------------------------- remove

if ($mode === 'remove') {
    $pdo = db();
    $pdo->beginTransaction();

    $certs = q("DELETE FROM certificates WHERE verify_token LIKE 'demo-%'")->rowCount();

    // Only tidy up vehicles and devices that no certificate still points at, so
    // nothing real is taken with them.
    q("DELETE FROM installations i
        WHERE NOT EXISTS (SELECT 1 FROM certificates c WHERE c.installation_id = i.id)
          AND EXISTS (SELECT 1 FROM vehicles v
                       WHERE v.id = i.vehicle_id AND v.reg_no LIKE 'KDA 1%')");
    q("DELETE FROM vehicles v
        WHERE v.reg_no LIKE 'KDA 1%'
          AND NOT EXISTS (SELECT 1 FROM certificates c WHERE c.vehicle_id = v.id)");
    q("DELETE FROM devices d
        WHERE d.serial_no LIKE 'DEMO%'
          AND NOT EXISTS (SELECT 1 FROM certificates c WHERE c.device_id = d.id)");

    $clients = q("DELETE FROM clients cl
                   WHERE cl.email LIKE '%@demo.invalid'
                     AND NOT EXISTS (SELECT 1 FROM certificates c
                                      WHERE c.client_id = cl.id)")->rowCount();

    $pdo->commit();

    echo "Removed {$certs} demo certificates and {$clients} demo clients.\n";
    echo "Users, passwords and client portal logins were not touched.\n";
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
    exit("There is no accounts user, so nothing could be approved and every demo\n"
       . "certificate would sit as Pending approval. Set one with:\n"
       . "  UPDATE users SET role = 'accounts' WHERE username = '...';\n");
}

$attachTo = null;
if ($clientId > 0) {
    $attachTo = q('SELECT * FROM clients WHERE id = ?', [$clientId])->fetch();
    if (!$attachTo) {
        exit("There is no client with id {$clientId}.\n");
    }
    echo "Attaching the demo certificates to client {$clientId}: {$attachTo['name']}\n\n";
}

// Every client that exists before this run. Anything created from here on is the
// seed's own and may be removed again; these are left alone whatever happens.
$keepClients = array_map('intval',
    q('SELECT id FROM clients')->fetchAll(PDO::FETCH_COLUMN));

$real = (int)q("SELECT count(*) FROM certificates WHERE verify_token NOT LIKE 'demo-%'")->fetchColumn();
if ($real > 0) {
    echo "Warning: the database already holds {$real} certificate(s) that are not demo\n";
    echo "data. Approving demo ones will take real certificate numbers.\n";
    echo "Type YES to add demo data anyway: ";
    if (trim((string)fgets(STDIN)) !== 'YES') {
        exit("Nothing added.\n");
    }
}

// ---------------------------------------------------------------- helpers

/** An M-Pesa confirmation in the usual Safaricom wording. */
function demo_mpesa(string $ref, string $amount, string $when): string
{
    return $ref . ' Confirmed. Ksh' . $amount . ' sent to GAPTECH SOLUTIONS LTD'
        . ' for account GAP on ' . $when . '. New M-PESA balance is Ksh'
        . number_format(random_int(1200, 48000), 2) . '. Transaction cost, Ksh0.00.';
}

function demo_ref(): string
{
    return 'T' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 9));
}

$d = fn(string $modify) => date('Y-m-d', strtotime($modify));

/** Raise a certificate, mark it as demo data, and pin it to the right client. */
function demo_raise(array $in, array $admin, ?array $attachTo, array $keepClients): array
{
    $cert = create_certificate($in, $admin);
    $id   = (int)$cert['id'];

    q("UPDATE certificates SET verify_token = 'demo-' || verify_token WHERE id = ?", [$id]);

    if ($attachTo) {
        // create_certificate() derives the client from the name and phone. When
        // these are meant to belong to one existing client, say so rather than
        // hoping the lookup lands on the right row.
        q('UPDATE certificates SET client_id = ? WHERE id = ?', [(int)$attachTo['id'], $id]);
    } else {
        // create_certificate() made the client row, so it carries no marker and
        // "remove" would leave it behind. Stamp it - but only if it did not exist
        // before this run, so a real client is never relabelled.
        $cid = (int)$cert['client_id'];
        if (!in_array($cid, $keepClients, true)) {
            q("UPDATE clients
                  SET email = lower(regexp_replace(name, '[^A-Za-z0-9]+', '.', 'g'))
                              || '@demo.invalid'
                WHERE id = ? AND coalesce(email, '') = ''", [$cid]);
        }
    }

    return q('SELECT * FROM certificates WHERE id = ?', [$id])->fetch();
}

/** Approve one, with a plausible payment. */
function demo_approve(array $cert, array $accounts, string $when): array
{
    $ref = demo_ref();
    approve_certificate($cert, $accounts, $ref,
        demo_mpesa($ref, number_format(random_int(9, 25) * 500, 2), $when));
    return q('SELECT * FROM certificates WHERE id = ?', [$cert['id']])->fetch();
}

// ------------------------------------------------------------------- data
//
// Eleven vehicles covering every state the list can filter on, including two
// certificates paid for early that have not started yet.

$fleet = [
    ['KDA 101A', 'ISUZU FRR',          'FRR90-7733412',     'MWANGI HAULIERS LTD',        '0722 118 400'],
    ['KDA 102B', 'TOYOTA HIACE',       'KDH206-0254871',    'UMOJA SACCO SOCIETY LTD',    '0733 904 118'],
    ['KDA 103C', 'MITSUBISHI CANTER',  'FE85-0442119',      'MASHARIKI DISTRIBUTORS',     '0711 230 904'],
    ['KDA 104D', 'ISUZU NQR',          'NQR71-3310997',     'GRACE WANJIKU MWANGI',       '0720 553 801'],
    ['KDA 105E', 'SCANIA P410',        'XLER6X20005332118', 'NORTHERN CORRIDOR LOGISTICS','0729 441 077'],
    ['KDA 106F', 'TOYOTA PROBOX',      'NCP51-0119087',     'PETER NJOROGE KAMAU',        '0702 889 341'],
    ['KDA 107G', 'NISSAN CARAVAN',     'VWE25-0090233',     'SAMUEL OTIENO ODHIAMBO',     '0745 118 220'],
    ['KDA 108H', 'TOYOTA LAND CRUISER','HZJ79-0033615',     'TSAVO SAFARI OUTFITTERS',    '0712 664 205'],
    ['KDA 109J', 'TOYOTA HILUX',       'GUN125-3391004',    'DAVID KIPROTICH SANG',       '0738 902 551'],
    ['KDA 110K', 'TOYOTA NOAH',        'ZRR70-0338112',     'LAKESIDE TOURS AND TRAVEL',  '0722 456 789'],
    ['KDA 111L', 'SUBARU FORESTER',    'SH5-0229114',       'BRIAN OMONDI WERE',          '0702 118 004'],
];

$serial = 231100000;

/** Build the $in array create_certificate() expects. */
$make_in = function (array $v, string $issue, string $type) use (&$serial, $attachTo): array {
    $serial++;
    return [
        'client_name'     => $attachTo ? $attachTo['name'] : $v[3],
        'client_phone'    => $attachTo ? ($attachTo['phone'] ?: $v[4]) : $v[4],
        'client_address'  => $attachTo ? ($attachTo['address'] ?? 'NAIROBI') : 'NAIROBI',
        'reg_no'          => $v[0],
        'make'            => $v[1],
        'chassis_no'      => $v[2],
        'device_model'    => trim((string)setting('default_model')) ?: 'INTELSPEED™',
        'serial_no'       => 'DEMO' . $serial,
        'set_speed_kmh'   => trim((string)setting('default_speed')) ?: '80',
        'installed_on'    => $issue,
        'technician'      => trim((string)setting('default_technician')) ?: 'GAPTECH',
        'type'            => $type,
        'issue_date'      => $issue,
        'validity_months' => '12',
        'expiry_date'     => date('Y-m-d', strtotime($issue . ' +1 year -1 day')),
    ];
};

/** One approved certificate running from $issue. */
$approved = function (array $v, string $issue, string $type)
    use ($make_in, $admin, $accounts, $attachTo, $keepClients) {
    $cert = demo_raise($make_in($v, $issue, $type), $admin, $attachTo, $keepClients);
    return demo_approve($cert, $accounts, date('j/n/y', strtotime($issue)) . ' at 10:42 AM');
};

$made = [];
$note = function (string $k) use (&$made) { $made[$k] = ($made[$k] ?? 0) + 1; };

// 1-2  Valid, well inside their year.
$approved($fleet[0], $d('-5 months'),  'Renewal'); $note('valid');
$approved($fleet[1], $d('-2 months'),  'Fitting'); $note('valid');

// 3  Valid, but expiring in 12 days: shows in "expiring within 30 days".
$approved($fleet[2], $d('-1 year +12 days'), 'Renewal'); $note('valid');

// 4-5  SCHEDULED. The vehicle is covered now, and a renewal has already been paid
//      for and approved. It starts the day after the current cover ends, so it is
//      not downloadable yet.
$scheduledPairs = [];
foreach ([[3, '-1 year +26 days'], [4, '-1 year +40 days']] as [$i, $when]) {
    $live = $approved($fleet[$i], $d($when), 'Renewal');
    $note('valid');

    $start = date('Y-m-d', strtotime($live['expiry_date'] . ' +1 day'));
    $in    = $make_in($fleet[$i], $start, 'Renewal');
    $next  = demo_raise($in, $admin, $attachTo, $keepClients);
    $next  = demo_approve($next, $accounts, date('j/n/y') . ' at 9:15 AM');
    $note('scheduled');

    $scheduledPairs[] = [(int)$live['id'], (int)$next['id'], $fleet[$i][0]];
}

// 6  Expired three weeks ago.
$approved($fleet[5], $d('-1 year -21 days'), 'Renewal'); $note('expired');

// 7  Replaced: an old certificate and the live renewal that took over.
$old = $approved($fleet[6], $d('-2 years'), 'Fitting');
$approved($fleet[6], $d('-11 months'), 'Renewal');
$note('replaced'); $note('valid');

// 8  Voided: the vehicle was sold and the governor removed.
$void = $approved($fleet[7], $d('-4 months'), 'Renewal');
q("UPDATE certificates SET status = 'voided', void_reason = ? WHERE id = ?",
    ['Vehicle sold, governor removed on ' . fmt_date($d('-2 weeks')) . '.', $void['id']]);
audit('void_certificate', 'certificate', (int)$void['id'], ['reason' => 'demo data']);
$note('voided');

// 9  Pending approval, raised yesterday.
demo_raise($make_in($fleet[8], $d('-1 day'), 'Fitting'), $admin, $attachTo, $keepClients);
$note('pending');

// 10 Pending, with a payment the administrator already recorded. This is the one
//    that makes accounts see the "not verified by accounts" warning.
$withPay = demo_raise($make_in($fleet[9], $d('-2 days'), 'Renewal'), $admin, $attachTo, $keepClients);
$ref = demo_ref();
q('UPDATE certificates SET payment_ref = ?, payment_note = ? WHERE id = ?',
    [$ref, demo_mpesa($ref, '14,000.00', date('j/n/y', strtotime($d('-2 days'))) . ' at 3:18 PM'),
     $withPay['id']]);
$note('pending');

// 11 Rejected: no payment ever arrived.
$rej = demo_raise($make_in($fleet[10], $d('-9 days'), 'Fitting'), $admin, $attachTo, $keepClients);
reject_certificate($rej, $accounts,
    'No payment received against this registration. Resubmit with the M-Pesa message.');
$note('rejected');

// ------------------------------------------------- did superseding behave?
//
// Approving a certificate that starts in the future must NOT retire the one the
// client is using today. If it did, this catches it, puts it back, and says so -
// because it means create_certificate() or approve_certificate() is still
// superseding without looking at the dates.

$broken = [];
foreach ($scheduledPairs as [$liveId, $nextId, $reg]) {
    $live = q('SELECT status FROM certificates WHERE id = ?', [$liveId])->fetchColumn();
    if ($live !== 'issued') {
        q("UPDATE certificates SET status = 'issued' WHERE id = ?", [$liveId]);
        $broken[] = $reg;
    }
}

// ---------------------------------------------------------------- report

$last = (int)q("SELECT value FROM settings WHERE key = 'last_certificate_number'")->fetchColumn();

echo "Demo certificates added.\n\n";
foreach (['valid' => 'Valid', 'scheduled' => 'Scheduled', 'expired' => 'Expired',
          'replaced' => 'Replaced', 'voided' => 'Voided', 'pending' => 'Pending',
          'rejected' => 'Rejected'] as $k => $label) {
    if (!empty($made[$k])) {
        printf("  %-11s %d\n", $label, $made[$k]);
    }
}

if ($broken) {
    echo "\n  !! A certificate paid for early retired the one still in use, on: "
       . implode(', ', $broken) . "\n";
    echo "     I have put those back to issued, but the cause is still there:\n";
    echo "     the supersede step is running without checking issue_date. Until it\n";
    echo "     is fixed, a client who renews early loses the certificate they hold.\n";
} else {
    echo "\n  Superseding behaved: the certificates paid for early did not retire\n";
    echo "  the ones still in use.\n";
}

echo "\nOpen index.php to see them. Remove again with:\n";
echo "  php tools/seed_demo.php remove\n";