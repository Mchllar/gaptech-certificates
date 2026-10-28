<?php
/**
 * Empty the database for testing, keeping the logins and the settings.
 *
 * Put this file in the project's tools/ folder and run it from Command Prompt:
 *
 *   C:\xampp\php\php.exe tools\reset_data.php
 *       show what is there, delete nothing
 *
 *   C:\xampp\php\php.exe tools\reset_data.php wipe
 *       delete it, after you type WIPE
 *
 *   C:\xampp\php\php.exe tools\reset_data.php wipe 13600
 *       delete it and set the governor counter back, so the next certificate
 *       issued is 13601
 *
 *   C:\xampp\php\php.exe tools\reset_data.php wipe 13600 1189
 *       and set the tracking counter back too, so the next one is 01190
 *
 * WHAT IT NEVER TOUCHES:
 *   users      the administrator and accounts logins, and their passwords
 *   settings   company details, signatory, defaults, financier agreements
 *
 * Client portal logins live on the clients table and ARE deleted with it, so
 * after a wipe you will need to set up client access again.
 *
 * DO NOT RUN THIS ON A DATABASE THAT HOLDS REAL CERTIFICATES. A certificate that
 * has been printed and handed to a driver exists outside this system, and the
 * verification page is the only thing that can confirm it. Deleting the row makes
 * that certificate unverifiable, and there is no undo.
 */
require __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

$mode     = $argv[1] ?? 'show';
$counter  = isset($argv[2]) ? (int)$argv[2] : null;
$trkCount = isset($argv[3]) ? (int)$argv[3] : null;

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
//
// tracking_imeis is first because it points at tracking_certificates. It would
// cascade on its own, but listing it means the count above tells you how many
// unit records are about to go, rather than quietly taking them with something
// else.
//
// renewal_orders and renewal_payments were dropped when the orders module went.
// They stay on the list so this still works on a database predating that change;
// on yours they print as absent.
$plan = [
    'renewal_payments'      => 'payments against orders (removed module)',
    'certificates'          => 'speed governor certificates',
    'renewal_orders'        => 'renewal orders (removed module)',
    'tracking_imeis'        => 'tracker unit numbers',
    'tracking_certificates' => 'tracking installation certificates',
    'installations'         => 'installations',
    'devices'               => 'devices',
    'vehicles'              => 'vehicles',
    'clients'               => 'clients, and their portal logins',
    'audit_log'             => 'the audit trail',
];

echo "\n";
printf("%-24s %s\n", 'TABLE', 'ROWS');
echo str_repeat('-', 60), "\n";

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

echo str_repeat('-', 60), "\n";
printf("%-24s %-8d %s\n", 'users', $count('users'), 'NOT TOUCHED');
printf("%-24s %-8d %s\n", 'settings', $count('settings'), 'NOT TOUCHED');

// ---------------------------------------------------------------------------
// The generated PDFs.
//
// They are not in the database, so a wipe used to leave them behind: a folder of
// certificate-13764.pdf files for certificates that no longer exist. They are
// regenerated on demand, so nothing is lost by clearing them - and leaving them
// is actively confusing once the counter has been set back, because the folder
// then holds a file named after a number about to be issued again.
// ---------------------------------------------------------------------------
$storage = rtrim((string)($config['storage'] ?? ''), '/\\');
$pdfDirs = $storage !== ''
    ? [$storage . DIRECTORY_SEPARATOR . 'certificates',
       $storage . DIRECTORY_SEPARATOR . 'tracking']
    : [];

$pdfFiles = [];
foreach ($pdfDirs as $dir) {
    foreach ((array)glob($dir . DIRECTORY_SEPARATOR . '*.pdf') as $f) {
        $pdfFiles[] = $f;
    }
}
printf("%-24s %-8d %s\n", 'generated PDFs', count($pdfFiles), 'deleted by a wipe');
echo "\n";

if ($mode !== 'wipe') {
    echo "Nothing deleted. To delete all of the above:\n";
    echo "  php tools\\reset_data.php wipe\n\n";
    exit;
}

if ($total === 0 && !$pdfFiles) {
    echo "Already empty. Nothing to do.\n\n";
    exit;
}

// A certificate that has been printed and handed to a driver exists outside this
// system. Say so, with the number, before anyone types anything.
if ($count('certificates') > 0) {
    $live = q("SELECT count(*) FROM certificates
               WHERE approval_status = 'approved' AND status <> 'voided'
                 AND expiry_date >= current_date")->fetchColumn();
    if ((int)$live > 0) {
        echo "WARNING: {$live} of these certificates are approved and still valid today.\n";
        echo "If any have been printed and handed over, deleting them makes those\n";
        echo "certificates impossible to verify. There is no undo.\n\n";
    }
}

echo "Type WIPE to delete everything listed above: ";
if (trim((string)fgets(STDIN)) !== 'WIPE') {
    exit("\nNothing deleted.\n\n");
}

q('BEGIN');

try {
    $deleted = [];
    foreach ($plan as $table => $what) {
        if (!$have($table)) {
            continue;
        }
        $deleted[$table] = q('DELETE FROM ' . $table)->rowCount();
    }

    // Sequences, so ids start from 1 again rather than from wherever the last
    // test left off. Harmless where a table has no serial id.
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

    // The printed numbers are deliberately separate, and by default LEFT ALONE:
    // reusing a number that has already been printed is worse than a gap in the
    // sequence. Each is set only when you pass it.
    //
    // They used to be entangled - passing a governor number silently forced the
    // tracking counter to 1189 as well, whatever you had asked for.
    if ($counter !== null) {
        q("INSERT INTO settings (key, value) VALUES ('last_certificate_number', ?)
           ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value", [(string)$counter]);
    }
    if ($trkCount !== null) {
        q("INSERT INTO settings (key, value) VALUES ('last_tracking_number', ?)
           ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value", [(string)$trkCount]);
    }

    q('COMMIT');
} catch (Throwable $err) {
    q('ROLLBACK');
    exit("\nNothing was deleted - the wipe failed and was rolled back:\n  "
       . $err->getMessage() . "\n\n");
}

// Files last, and outside the transaction: a rollback cannot put a deleted file
// back, so nothing leaves the disk until the rows are definitely gone.
$filesGone = 0;
foreach ($pdfFiles as $f) {
    if (@unlink($f)) {
        $filesGone++;
    }
}

// Leftovers from the PDF renderer. Not data, but they accumulate, and a stray
// failed-sheet.html is confusing to find after a clean run.
if ($storage !== '') {
    @unlink($storage . DIRECTORY_SEPARATOR . 'failed-sheet.html');
    @unlink($storage . DIRECTORY_SEPARATOR . 'certificates' . DIRECTORY_SEPARATOR . '_pdf_test.pdf');
}

echo "\nDeleted:\n";
foreach ($deleted as $table => $n) {
    printf("  %-24s %d\n", $table, $n);
}
printf("  %-24s %d\n", 'generated PDFs', $filesGone);

echo "\nUsers and settings were not touched.\n";

$show = function (string $key, string $label, ?int $set) {
    $now = (string)q('SELECT value FROM settings WHERE key = ?', [$key])->fetchColumn();
    if ($set !== null) {
        echo "  {$label} set back to {$set}; the next one issued will be " . ($set + 1) . ".\n";
    } else {
        echo "  {$label} left at " . ($now === '' ? '(unset)' : $now) . " and carries on from there.\n";
    }
};

echo "\nNumbering:\n";
$show('last_certificate_number', 'Governor', $counter);
$show('last_tracking_number', 'Tracking', $trkCount);

if ($counter === null && $trkCount === null) {
    echo "\nA gap is safer than reissuing a number that has already been printed,\n";
    echo "which is why they are left alone unless you ask. To set them back:\n";
    echo "  php tools\\reset_data.php wipe 13600 1189\n";
}

echo "\nClient portal logins went with the clients. Set up client access again\n";
echo "before testing the portal, or run: php tools\\seed_demo.php add\n\n";