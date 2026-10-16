<?php
/**
 * Demo data for the tracking installation module.
 *
 * Creates sample installations through the normal issuing code - create, approve,
 * reject, renew, void - so snapshots, serial numbers, superseding and the approval
 * trail are all real rather than rows pushed straight into the table.
 *
 * Put this file in the project's tools/ folder and run it from the command line:
 *
 *   php tools/seed_tracking.php          add the demo data
 *   php tools/seed_tracking.php remove   delete it again
 *
 * Demo rows are marked by a verify_token beginning "demo-", and that is how "remove"
 * finds them. Nothing else in the system is touched.
 *
 * Do NOT run this once the system holds real installation certificates: approving a
 * demo one takes a real serial number from the counter. "remove" puts the counter back
 * only when no tracking certificates are left at all.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/tracking.php';

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

$mode = $argv[1] ?? 'add';

// ---------------------------------------------------------------- remove

if ($mode === 'remove') {
    $pdo = db();
    $pdo->beginTransaction();
    $gone = q("DELETE FROM tracking_certificates WHERE verify_token LIKE 'demo-%'")->rowCount();
    $left = (int)q('SELECT count(*) FROM tracking_certificates')->fetchColumn();
    if ($left === 0) {
        q("UPDATE settings SET value = '1189' WHERE key = 'last_tracking_number'");
    }
    $pdo->commit();

    echo "Removed {$gone} demo installation certificates.\n";
    echo $left === 0
        ? "No tracking certificates left, so the serial counter is back at 1189.\n"
        : "{$left} real certificate(s) remain, so the serial counter is left alone.\n";
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

$real = (int)q("SELECT count(*) FROM tracking_certificates
                 WHERE verify_token NOT LIKE 'demo-%'")->fetchColumn();
if ($real > 0) {
    echo "Warning: the database already holds {$real} installation certificate(s) that are\n";
    echo "not demo data. Approving demo ones will take real serial numbers.\n";
    echo "Type YES to add demo data anyway: ";
    if (trim((string)fgets(STDIN)) !== 'YES') {
        exit("Nothing added.\n");
    }
}

// ---------------------------------------------------------------- helpers

/** An M-Pesa confirmation in the usual Safaricom wording. */
function trk_mpesa(string $ref, string $amount, string $when): string
{
    return $ref . ' Confirmed. Ksh' . $amount . ' sent to GAP TECH SOLUTIONS LTD'
        . ' for account TRACKING on ' . $when . '. New M-PESA balance is Ksh'
        . number_format(random_int(1200, 48000), 2) . '. Transaction cost, Ksh0.00.';
}

/** Mark a row as demo data, and backdate when it was raised. */
function trk_mark(array $cert, string $raisedOn): void
{
    q("UPDATE tracking_certificates
          SET verify_token = 'demo-' || verify_token, issued_at = ?::timestamptz
        WHERE id = ?", [$raisedOn . ' 09:30:00+03', $cert['id']]);
}

/** Raise one, exactly as the form does. */
function trk_raise(array $in, array $admin, string $raisedOn): array
{
    $cert = create_tracking_cert($in, $admin);
    trk_mark($cert, $raisedOn);
    return q('SELECT * FROM tracking_certificates WHERE id = ?', [$cert['id']])->fetch();
}

$today = new DateTimeImmutable('today');
$d = fn(string $modify) => $today->modify($modify)->format('Y-m-d');

$made = ['valid' => 0, 'expired' => 0, 'pending' => 0, 'rejected' => 0,
         'superseded' => 0, 'voided' => 0];

// ---------------------------------------------------------------- the data
//
// Twelve vehicles covering every state the list page can filter on, plus one
// four-year subscription and two due to expire inside the next thirty days.

$book = [
    // reg,       make/model,            chassis,            engine,        colour,  owner,                         financier,            imei
    ['KDZ 055G',  'TOYOTA SUCCEED',      'NCP165-0067141',   '1NZ-8991811', 'WHITE', 'FRANCIS JANE MAINA',          'HFCB BANK',          '865784051731951'],
    ['KCY 812M',  'ISUZU FRR',           'FRR90-7733412',    '4HK1-2290113','WHITE', 'RIFT VALLEY HAULIERS LIMITED','KCB BANK KENYA',     '862901447102388'],
    ['KDA 447T',  'TOYOTA PROBOX',       'NCP51-0119087',    '1NZ-4410236', 'SILVER','PETER NJOROGE KAMAU',         '',                   '867310229984512'],
    ['KBZ 309Q',  'MITSUBISHI CANTER',   'FE85-0442119',     '4M50-1180774','BLUE',  'MASHARIKI DISTRIBUTORS LTD',  'ABSA BANK KENYA',    '861552038871204'],
    ['KDK 017N',  'NISSAN CARAVAN',      'VWE25-0090233',    'QR25-7741092','WHITE', 'SAMUEL OTIENO ODHIAMBO',      '',                   '869004117320556'],
    ['KCN 663R',  'TOYOTA HIACE',        'KDH206-0254871',   '1KD-5520418', 'WHITE', 'UMOJA SACCO SOCIETY LIMITED', 'COOPERATIVE BANK',   '864220915588310'],
    ['KDL 229B',  'ISUZU NQR',           'NQR71-3310997',    '4HG1-7719023','WHITE', 'GRACE WANJIKU MWANGI',        'NCBA BANK KENYA',    '868117443029765'],
    ['KBX 774V',  'TOYOTA LAND CRUISER', 'HZJ79-0033615',    '1HZ-0992144', 'BEIGE', 'TSAVO SAFARI OUTFITTERS LTD', '',                   '863448120077491'],
    ['KDM 581J',  'SCANIA P410',         'XLER6X20005332118','DC13-1148906','RED',   'NORTHERN CORRIDOR LOGISTICS','STANBIC BANK KENYA', '866793015540228'],
    ['KCF 138H',  'TOYOTA HILUX',        'GUN125-3391004',   '2GD-8810337', 'GREY',  'DAVID KIPROTICH SANG',        '',                   '860225978113644'],
];

// 1-3  Valid, approved months ago, running normally.
foreach ([[0, '-7 months', 12], [1, '-4 months', 12], [2, '-2 months', 12]] as [$i, $when, $term]) {
    [$reg, $model, $chassis, $engine, $colour, $owner, $fin, $imei] = $book[$i];
    $start = $d($when);
    $cert = trk_raise([
        'client_name' => $owner, 'client_phone' => '07' . random_int(10, 99) . ' ' . random_int(100000, 999999),
        'reg_no' => $reg, 'make_model' => $model, 'chassis_no' => $chassis, 'engine_no' => $engine,
        'colour' => $colour, 'ownership' => $owner, 'financier' => $fin, 'imei' => $imei,
        'installed_on' => $start, 'subscription_date' => $start,
        'expiry_date' => default_expiry($start, $term),
        'signed_by' => trim((string)setting('tracking_signatory', '')) ?: 'MOSES CLAYTONE',
    ], $admin, $start);

    if ($accounts) {
        $ref = 'T' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 9));
        approve_tracking_cert($cert, $accounts, $ref,
            trk_mpesa($ref, number_format(random_int(9, 25) * 500, 2),
                      date('j/n/y', strtotime($start)) . ' at 10:4' . random_int(0, 9) . ' AM'));
        $made['valid']++;
    } else {
        $made['pending']++;
    }
}

// 4  A four-year subscription, like certificate 01182.
[$reg, $model, $chassis, $engine, $colour, $owner, $fin, $imei] = $book[3];
$start = $d('-3 weeks');
$cert = trk_raise([
    'client_name' => $owner, 'client_phone' => '0722 118 400',
    'reg_no' => $reg, 'make_model' => $model, 'chassis_no' => $chassis, 'engine_no' => $engine,
    'colour' => $colour, 'ownership' => $owner, 'financier' => $fin, 'imei' => $imei,
    'installed_on' => $start, 'subscription_date' => $start,
    'expiry_date' => default_expiry($start, 48),
    'signed_by' => trim((string)setting('tracking_signatory', '')) ?: 'MOSES CLAYTONE',
], $admin, $start);
if ($accounts) {
    approve_tracking_cert($cert, $accounts, 'TKG4YR77Q1',
        trk_mpesa('TKG4YR77Q1', '48,000.00', date('j/n/y', strtotime($start)) . ' at 2:05 PM'));
    $made['valid']++;
} else {
    $made['pending']++;
}

// 5-6  Expiring inside the next thirty days, so the list page has something to show.
foreach ([[4, '-11 months -3 weeks'], [5, '-11 months -1 week']] as [$i, $when]) {
    [$reg, $model, $chassis, $engine, $colour, $owner, $fin, $imei] = $book[$i];
    $start = $d($when);
    $cert = trk_raise([
        'client_name' => $owner, 'client_phone' => '07' . random_int(10, 99) . ' ' . random_int(100000, 999999),
        'reg_no' => $reg, 'make_model' => $model, 'chassis_no' => $chassis, 'engine_no' => $engine,
        'colour' => $colour, 'ownership' => $owner, 'financier' => $fin, 'imei' => $imei,
        'installed_on' => $start, 'subscription_date' => $start,
        'expiry_date' => default_expiry($start, 12),
        'signed_by' => trim((string)setting('tracking_signatory', '')) ?: 'MOSES CLAYTONE',
    ], $admin, $start);
    if ($accounts) {
        $ref = 'T' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 9));
        approve_tracking_cert($cert, $accounts, $ref,
            trk_mpesa($ref, '12,000.00', date('j/n/y', strtotime($start)) . ' at 11:20 AM'));
        $made['valid']++;
    } else {
        $made['pending']++;
    }
}

// 7  Expired: ran out two months ago and nobody renewed.
[$reg, $model, $chassis, $engine, $colour, $owner, $fin, $imei] = $book[6];
$start = $d('-14 months');
$cert = trk_raise([
    'client_name' => $owner, 'client_phone' => '0733 904 118',
    'reg_no' => $reg, 'make_model' => $model, 'chassis_no' => $chassis, 'engine_no' => $engine,
    'colour' => $colour, 'ownership' => $owner, 'financier' => $fin, 'imei' => $imei,
    'installed_on' => $start, 'subscription_date' => $start,
    'expiry_date' => default_expiry($start, 12),
    'signed_by' => trim((string)setting('tracking_signatory', '')) ?: 'MOSES CLAYTONE',
], $admin, $start);
if ($accounts) {
    approve_tracking_cert($cert, $accounts, 'TKEXP4410B',
        trk_mpesa('TKEXP4410B', '12,000.00', date('j/n/y', strtotime($start)) . ' at 9:12 AM'));
    $made['expired']++;
} else {
    $made['pending']++;
}

// 8  Superseded: renewed last month, so the old one stepped aside for the new one.
[$reg, $model, $chassis, $engine, $colour, $owner, $fin, $imei] = $book[7];
$start = $d('-13 months');
$old = trk_raise([
    'client_name' => $owner, 'client_phone' => '0720 553 801',
    'reg_no' => $reg, 'make_model' => $model, 'chassis_no' => $chassis, 'engine_no' => $engine,
    'colour' => $colour, 'ownership' => $owner, 'financier' => $fin, 'imei' => $imei,
    'installed_on' => $start, 'subscription_date' => $start,
    'expiry_date' => default_expiry($start, 12),
    'signed_by' => trim((string)setting('tracking_signatory', '')) ?: 'MOSES CLAYTONE',
], $admin, $start);
if ($accounts) {
    approve_tracking_cert($old, $accounts, 'TKOLD2291V',
        trk_mpesa('TKOLD2291V', '12,000.00', date('j/n/y', strtotime($start)) . ' at 4:40 PM'));

    // The renewal goes through the same path the Renew button uses.
    $old = q('SELECT * FROM tracking_certificates WHERE id = ?', [$old['id']])->fetch();
    $new = renew_tracking_cert($old, $admin,
        trk_mpesa('TKNEW8830L', '12,000.00', date('j/n/y', strtotime($d('-1 month'))) . ' at 8:55 AM'), 12);
    trk_mark($new, $d('-1 month'));
    $new = q('SELECT * FROM tracking_certificates WHERE id = ?', [$new['id']])->fetch();
    approve_tracking_cert($new, $accounts, 'TKNEW8830L', (string)$new['payment_note']);

    $made['superseded']++;
    $made['valid']++;
} else {
    $made['pending']++;
}

// 9  Voided: the unit came out when the vehicle was sold.
[$reg, $model, $chassis, $engine, $colour, $owner, $fin, $imei] = $book[8];
$start = $d('-5 months');
$cert = trk_raise([
    'client_name' => $owner, 'client_phone' => '0711 230 904',
    'reg_no' => $reg, 'make_model' => $model, 'chassis_no' => $chassis, 'engine_no' => $engine,
    'colour' => $colour, 'ownership' => $owner, 'financier' => $fin, 'imei' => $imei,
    'installed_on' => $start, 'subscription_date' => $start,
    'expiry_date' => default_expiry($start, 12),
    'signed_by' => trim((string)setting('tracking_signatory', '')) ?: 'MOSES CLAYTONE',
], $admin, $start);
if ($accounts) {
    approve_tracking_cert($cert, $accounts, 'TKVOID5517C',
        trk_mpesa('TKVOID5517C', '12,000.00', date('j/n/y', strtotime($start)) . ' at 1:30 PM'));
    $cert = q('SELECT * FROM tracking_certificates WHERE id = ?', [$cert['id']])->fetch();
    void_tracking_cert($cert, $admin, 'Vehicle sold, unit removed on ' . fmt_date($d('-2 weeks')) . '.');
    $made['voided']++;
} else {
    $made['pending']++;
}

// 10  Pending: raised yesterday, sitting in the accounts queue.
[$reg, $model, $chassis, $engine, $colour, $owner, $fin, $imei] = $book[9];
$start = $d('-1 day');
trk_raise([
    'client_name' => $owner, 'client_phone' => '0729 441 077',
    'reg_no' => $reg, 'make_model' => $model, 'chassis_no' => $chassis, 'engine_no' => $engine,
    'colour' => $colour, 'ownership' => $owner, 'financier' => $fin, 'imei' => $imei,
    'installed_on' => $start, 'subscription_date' => $start,
    'expiry_date' => default_expiry($start, 12),
    'signed_by' => trim((string)setting('tracking_signatory', '')) ?: 'MOSES CLAYTONE',
], $admin, $start);
$made['pending']++;

// 11  Pending with a payment already recorded by the administrator - the case that
//     makes accounts see the "not verified by accounts" warning.
$start = $d('-2 days');
$cert = trk_raise([
    'client_name' => 'LAKESIDE TOURS AND TRAVEL LTD', 'client_phone' => '0745 118 220',
    'reg_no' => 'KDP 904F', 'make_model' => 'TOYOTA NOAH', 'chassis_no' => 'ZRR70-0338112',
    'engine_no' => '3ZR-1120884', 'colour' => 'PEARL WHITE',
    'ownership' => 'LAKESIDE TOURS AND TRAVEL LTD', 'financier' => 'EQUITY BANK KENYA',
    'imei' => '865003778112049',
    'installed_on' => $start, 'subscription_date' => $start,
    'expiry_date' => default_expiry($start, 24),
    'signed_by' => trim((string)setting('tracking_signatory', '')) ?: 'MOSES CLAYTONE',
], $admin, $start);
q('UPDATE tracking_certificates SET payment_ref = ?, payment_note = ? WHERE id = ?',
    ['TKADM1182P', trk_mpesa('TKADM1182P', '22,000.00', date('j/n/y', strtotime($start)) . ' at 3:18 PM'),
     $cert['id']]);
$made['pending']++;

// 12  Rejected: no payment ever arrived.
$start = $d('-9 days');
$cert = trk_raise([
    'client_name' => 'BRIAN OMONDI WERE', 'client_phone' => '0702 889 341',
    'reg_no' => 'KCS 220L', 'make_model' => 'SUBARU FORESTER', 'chassis_no' => 'SH5-0229114',
    'engine_no' => 'EJ20-9930118', 'colour' => 'GREEN',
    'ownership' => 'BRIAN OMONDI WERE', 'financier' => '',
    'imei' => '862118004471203',
    'installed_on' => $start, 'subscription_date' => $start,
    'expiry_date' => default_expiry($start, 12),
    'signed_by' => trim((string)setting('tracking_signatory', '')) ?: 'MOSES CLAYTONE',
], $admin, $start);
if ($accounts) {
    reject_tracking_cert($cert, $accounts,
        'No payment received against this registration. Resubmit with the M-Pesa message.');
    $made['rejected']++;
} else {
    $made['pending']++;
}

// ---------------------------------------------------------------- report

$last = (int)q("SELECT value FROM settings WHERE key = 'last_tracking_number'")->fetchColumn();

echo "Demo installation certificates added.\n\n";
foreach ($made as $state => $count) {
    if ($count > 0) {
        printf("  %-11s %d\n", $state, $count);
    }
}
echo "\nSerial numbers now run to " . str_pad((string)$last, 5, '0', STR_PAD_LEFT) . ".\n";
echo "Open tracking_list.php to see them. Remove again with:\n";
echo "  php tools/seed_tracking.php remove\n";
