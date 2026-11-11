<?php
/**
 * public/batch_template.php  -  the spreadsheet to fill in.
 *
 * Open with no customer and you get an empty template with the right headings.
 *
 * Open with ?client=<id> and the customer's vehicles already on file come down
 * filled in - registration, make, chassis, and the governor currently fitted -
 * so a renewal of forty lorries only needs the dates typing. That is the version
 * worth using: it cannot misspell a registration that is already in the system,
 * which is where most of the errors come from.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require_once APP_DIR . '/batches.php';   // the bulk functions - harmless if
                                         // certificates.php already pulled it in

$user = require_admin();

$clientId = (int)($_GET['client'] ?? 0);
$client   = $clientId
          ? q('SELECT * FROM clients WHERE id = ?', [$clientId])->fetch()
          : null;

$rows = [];
if ($client) {
    // one line per vehicle, with whatever is fitted to it now
    $rows = q("SELECT v.reg_no, v.make, v.chassis_no,
                      d.model AS device_model, d.serial_no, d.set_speed_kmh,
                      i.technician
                 FROM vehicles v
                 LEFT JOIN installations i
                        ON i.vehicle_id = v.id AND i.removed_on IS NULL
                 LEFT JOIN devices d ON d.id = i.device_id
                WHERE v.client_id = ?
                ORDER BY v.reg_no", [$clientId])->fetchAll();
}

$name = $client ? preg_replace('/[^A-Za-z0-9]+/', '-', trim($client['name'])) : 'fleet';
$file = 'gaptech-' . strtolower(trim($name, '-')) . '-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');

// Excel opens a CSV as the machine's default encoding unless this is here, and
// then every accented character in a customer's name arrives as rubbish.
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['Reg No', 'Make', 'Chassis No', 'Device Model', 'Serial No',
               'Set Speed', 'Technician', 'Installed On', 'Validity Months']);

if ($rows) {
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['reg_no'],
            $r['make'],
            $r['chassis_no'],
            $r['device_model'] ?? setting('default_device_model', ''),
            $r['serial_no'] ?? '',
            $r['set_speed_kmh'] ?? setting('default_set_speed', '80'),
            $r['technician'] ?? '',
            '',                                  // fitting date - filled in by hand
            '12',
        ]);
    }
} else {
    // an example line, so the shape of each column is obvious. Delete it and
    // type over it - a row with no registration is skipped, not an error.
    fputcsv($out, ['KBZ 411T', 'Isuzu FRR', 'JAANPR75L07100123',
                   setting('default_device_model', 'Mobiwire MW-02'), '8612340001',
                   setting('default_set_speed', '80'), '', date('Y-m-d'), '12']);
}

fclose($out);