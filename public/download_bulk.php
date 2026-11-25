<?php
/**
 * public/download_bulk.php - download several certificates at once, as one .zip.
 *
 * Posted from the tick boxes on the two registers:
 *
 *   kind=cert      governor certificates   (index.php)
 *   kind=tracking  tracking installations  (tracking_list.php)
 *
 * The rules are the same ones the single downloads apply - this page is the one
 * that has to say no, whatever the list chose to show:
 *
 *   governor  any staff member; the certificate must be approved and numbered
 *   tracking  administrators and accounts; the certificate must be valid
 *
 * Anything selected that fails those rules is left out, and the reason is
 * listed in a note inside the zip, so nobody thinks a certificate went missing.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require APP_DIR . '/tracking.php';
require APP_DIR . '/zip.php';

$user = require_staff();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect('index.php');
}
csrf_check();

$kind = (string)($_POST['kind'] ?? '') === 'tracking' ? 'tracking' : 'cert';
$ids  = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));

// Back to the page the request came from, filters and all. Only a plain page
// name of our own is accepted, so this cannot be turned into an open redirect.
$back = (string)($_POST['back'] ?? '');
if (!preg_match('/^(index|tracking_list)\.php(\?[^\s]*)?$/', $back)) {
    $back = $kind === 'tracking' ? 'tracking_list.php' : 'index.php';
}

// One full page of the register at most. Each new PDF is a browser render, and
// a few hundred in one request would run past any sensible time limit.
const BULK_MAX = 100;

if (!$ids) {
    flash('Tick at least one certificate to download.');
    redirect($back);
}
if (count($ids) > BULK_MAX) {
    flash('Download at most ' . BULK_MAX . ' certificates at a time.');
    redirect($back);
}
if ($kind === 'tracking' && !in_array($user['role'], ['administrator', 'accounts'], true)) {
    http_response_code(403);
    exit('Downloading a certificate is for administrators and accounts. You are signed in as '
        . e($user['role']) . '.');
}
if (pdf_engine() === null) {
    flash('PDF conversion is not set up on this machine, so certificates cannot be '
        . 'downloaded in bulk. Open them one at a time and use "Save as PDF".');
    redirect($back);
}

// The PDFs can take a while to render. Let go of the session so the rest of the
// system stays usable in other tabs meanwhile, and lift the time limit.
session_write_close();
set_time_limit(0);

$in    = implode(',', array_fill(0, count($ids), '?'));
$files = [];
$skips = [];

if ($kind === 'cert') {
    $rows = q("SELECT * FROM certificates WHERE id IN ($in) ORDER BY number", $ids)->fetchAll();
    foreach ($rows as $cert) {
        $snap = json_decode((string)$cert['snapshot'], true) ?: [];
        $reg  = (string)($snap['vehicle']['reg_no'] ?? '');
        $who  = certificate_label($cert) . ($reg !== '' ? ' (' . $reg . ')' : '');

        if ($cert['approval_status'] !== 'approved' || $cert['number'] === null) {
            $skips[] = $who . ' - ' . strtolower(certificate_status($cert)) . ', not released yet';
            continue;
        }
        try {
            $path = certificate_pdf($cert);
        } catch (Throwable $err) {
            $skips[] = $who . ' - could not create the PDF: ' . $err->getMessage();
            continue;
        }
        $files['Certificate-' . $cert['number'] . '-'
            . preg_replace('/[^A-Za-z0-9]+/', '', $reg) . '.pdf'] = $path;

        // A download is the certificate leaving the office, same as download.php.
        q('UPDATE certificates SET download_count = download_count + 1,
                  released_at = COALESCE(released_at, now()) WHERE id = ?', [$cert['id']]);
        audit('download_pdf', 'certificate', (int)$cert['id'], ['bulk' => true]);
    }
} else {
    $rows = q("SELECT * FROM tracking_certificates WHERE id IN ($in) ORDER BY number", $ids)->fetchAll();
    foreach ($rows as $cert) {
        $who = tracking_label($cert) . ' (' . $cert['reg_no'] . ')';

        if (!tracking_is_releasable($cert)) {
            $skips[] = $who . ' - ' . strtolower(tracking_status($cert)) . ', so it cannot be downloaded';
            continue;
        }
        try {
            $path = tracking_pdf($cert);
        } catch (Throwable $err) {
            $skips[] = $who . ' - could not create the PDF: ' . $err->getMessage();
            continue;
        }
        $files['installation-' . tracking_label($cert) . '-'
            . preg_replace('/[^A-Za-z0-9]/', '', (string)$cert['reg_no']) . '.pdf'] = $path;

        q('UPDATE tracking_certificates SET print_count = print_count + 1 WHERE id = ?', [$cert['id']]);
        audit('download_tracking', 'tracking_certificate', (int)$cert['id'], ['bulk' => true]);
    }
}

if (!$files) {
    // flash() reopens the session released above.
    flash('None of the selected certificates could be downloaded. '
        . implode('; ', array_slice($skips, 0, 5)) . (count($skips) > 5 ? '; ...' : ''));
    redirect($back);
}

if ($skips) {
    $note = sys_get_temp_dir() . '/bulk-skipped-' . bin2hex(random_bytes(6)) . '.txt';
    file_put_contents($note, "These were selected but are not in this download:\r\n\r\n"
        . implode("\r\n", $skips) . "\r\n");
    $files['NOT-INCLUDED.txt'] = $note;
}

zip_send($files, ($kind === 'tracking' ? 'installations' : 'certificates')
    . '-' . date('Y-m-d-His') . '.zip');

if (isset($note)) {
    @unlink($note);
}
exit;
