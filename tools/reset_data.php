<?php
/**
 * Wipe every certificate, order and client out of the database.
 *
 * Put this file in the project's tools/ folder and run it from the command line:
 *
 *   php tools/reset_data.php                 show what is there, delete nothing
 *   php tools/reset_data.php wipe            delete it, after you type WIPE
 *   php tools/reset_data.php wipe 13600      delete it and set the certificate
 *                                            counter back to 13600
 *
 * This is NOT seed_demo.php remove. That one deletes only what the seed made,
 * matched on a verify_token beginning "demo-". Anything you created through the
 * application yourself - a renewal you raised in the portal, an order you paid
 * for while testing - carries no such marker, so "remove" cannot see it and
 * never will. This deletes everything regardless of where it came from.
 *
 * WHAT IT NEVER TOUCHES:
 *   users      staff logins and passwords
 *   settings   the company details, paybill, bank, defaults
 *
 * Client portal logins live on the clients table and ARE deleted with it, so
 * after a wipe you will need to set up client access again.
 *
 * DO NOT RUN THIS ON A DATABASE THAT HOLDS REAL CERTIFICATES. Certificates that
 * have been printed and handed to drivers exist outside this system, and the
 * verification page is the only thing that can confirm them. Deleting the rows
 * makes every one of those certificates unverifiable.
 */
require __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

$mode    = $argv[1] ?? 'show';
$counter = isset($argv[2]) ? (int)$argv[2] : null;

/** Tables that may or may not exist, depending on which migrations have run. */
$have = function (string $table): bool {
    return (bool)q('SELECT to_regclass(?)', ['public.' . $table])->fetchColumn();
};

$count = function (string $table) use ($have): int {
    return $have($table)
        ? (int)q('SELECT count(*) FROM ' . $table)->fetchColumn()
        : -1;
};

// The order matters: children before parents, or the foreign keys refuse.
// certificates point at renewal_orders, so certificates go first.
$plan = [
    'renewal_payments'      => 'payments against orders',
    'certificates'          => 'speed governor certificates',
    'renewal_orders'        => 'renewal orders',
    'tracking_certificates' => 'tracking installation certificates',
    'installations'         => 'installations',
    'devices'               => 'devices',
    'vehicles'              => 'vehicles',
    'clients'               => 'clients, and their portal logins',
    'audit_log'             => 'the audit trail',
];

echo "\n";
printf("%-24s %s\n", 'TABLE', 'ROWS');
echo str_repeat('-', 56), "\n";

$total = 0;
foreach ($plan as $table => $what) {
    $n = $count($table);
    if ($n < 0) {
        printf("%-24s %-8s %s\n", $table, '-', '(not in this database)');
        continue;
    }
    $total += $n;
    printf("%-24s %-8d %s\n", $table, $n, $what);
}

$users = $count('users');
echo str_repeat('-', 56), "\n";
printf("%-24s %-8d %s\n", 'users', $users, 'NOT TOUCHED');
printf("%-24s %-8s %s\n", 'settings', $count('settings'), 'NOT TOUCHED');
echo "\n";

if ($mode !== 'wipe') {
    echo "Nothing deleted. To delete all of the above:\n";
    echo "  php tools/reset_data.php wipe\n\n";
    exit;
}

if ($total === 0) {
    echo "Already empty. Nothing to do.\n\n";
    exit;
}

// A certificate that has been printed and handed to a driver exists outside this
// system. Deleting its row makes it unverifiable, and no amount of re-seeding
// brings it back - so say so, with the number, before anyone types anything.
if ($count('certificates') > 0) {
    $live = q("SELECT count(*) FROM certificates
               WHERE approval_status = 'approved' AND status <> 'voided'
                 AND expiry_date >= current_date")->fetchColumn();
    if ((int)$live > 0) {
        echo "WARNING: {$live} of these certificates are approved and still valid today.\n";
        echo "If any of them have been printed and handed over, deleting them makes\n";
        echo "those certificates impossible to verify. There is no undo.\n\n";
    }
}

echo "Type WIPE to delete everything listed above: ";
if (trim((string)fgets(STDIN)) !== 'WIPE') {
    exit("\nNothing deleted.\n\n");
}

// Driven through q() rather than a PDO handle, because this file requires only
// bootstrap.php and db() does not always live there.
q('BEGIN');

try {
    $deleted = [];
    foreach ($plan as $table => $what) {
        if (!$have($table)) {
            continue;
        }
        $deleted[$table] = q('DELETE FROM ' . $table)->rowCount();
    }

    // Sequences, so the next run starts from 1 rather than from wherever the
    // last test left off. Harmless if a table has no serial id.
    foreach (array_keys($plan) as $table) {
        if (!$have($table)) {
            continue;
        }
        try {
            q("SELECT setval(pg_get_serial_sequence(?, 'id'), 1, false)", [$table]);
        } catch (Throwable $ignore) {
            // no serial id on that table
        }
    }

    // The certificate counter is deliberately separate: by default it is LEFT
    // ALONE, because reusing a number that has already been printed is worse
    // than a gap in the sequence. Pass a number to set it back on purpose.
    if ($counter !== null) {
        q("INSERT INTO settings (key, value) VALUES ('last_certificate_number', ?)
           ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value", [(string)$counter]);
        if ($have('tracking_certificates')) {
            q("UPDATE settings SET value = '1189' WHERE key = 'last_tracking_number'");
        }
    }

    q('COMMIT');
} catch (Throwable $err) {
    q('ROLLBACK');
    exit("\nNothing was deleted - the wipe failed and was rolled back:\n  "
       . $err->getMessage() . "\n\n");
}

echo "\nDeleted:\n";
foreach ($deleted as $table => $n) {
    printf("  %-24s %d\n", $table, $n);
}

echo "\nUsers and settings were not touched.\n";

if ($counter !== null) {
    echo "Certificate numbering set back to {$counter}; the next one issued will be "
       . ($counter + 1) . ".\n";
} else {
    $last = (string)q("SELECT value FROM settings WHERE key = 'last_certificate_number'")
                ->fetchColumn();
    echo "Certificate numbering left at {$last}. Numbering carries on from there,\n";
    echo "which is deliberate - a gap is safer than reissuing a number that has\n";
    echo "already been printed. To set it back:\n";
    echo "  php tools/reset_data.php wipe 13600\n";
}

echo "\nClient portal logins went with the clients. Set up client access again\n";
echo "before testing the portal, or run: php tools/seed_demo.php add\n\n";
