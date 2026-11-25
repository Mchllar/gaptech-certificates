<?php
/**
 * Print or download a tracking installation certificate.
 *
 * Goes in public/tracking_print.php  (replaces what is there)
 *
 *   tracking_print.php?id=12                   the certificate on screen
 *   tracking_print.php?id=12&print=1           and open the print dialog at once
 *   tracking_print.php?id=12&download=1        the same thing as a PDF file
 *
 * The role checks here are the real ones. Hiding a button on the list page stops
 * nobody; this is the page that has to say no.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/tracking.php';

$user = require_staff();

$id   = (int)($_GET['id'] ?? 0);
$cert = q('SELECT * FROM tracking_certificates WHERE id = ?', [$id])->fetch();
if (!$cert) {
    http_response_code(404);
    exit('No such certificate.');
}

$download = !empty($_GET['download']);
$print    = !empty($_GET['print']);

// Printing is for administrators. Accounts may look at the sheet on screen (that
// is how they check it before releasing) but not print it.
$staffCanPrint = ($user['role'] ?? '') === 'administrator';
if ($print && !$staffCanPrint) {
    http_response_code(403);
    exit('Printing certificates is for administrators. You are signed in as ' . e((string)$user['role']) . '.');
}
$GLOBALS['tracking_can_print'] = $staffCanPrint;   // read by tracking_sheet.php: the Print button

// Always plain paper. Printing onto pre-printed tracking blanks was removed: the
// blanks cannot carry this certificate's own QR code, so they cannot be verified.
$stock = 'plain';

// Administrators and accounts may download the PDF (accounts may not print).
if ($download && !in_array($user['role'], ['administrator', 'accounts'], true)) {
    http_response_code(403);
    exit('Downloading a certificate is for administrators and accounts. You are signed in as '
        . e($user['role']) . '.');
}

// Nothing that is pending, rejected, expired, superseded or void may leave the
// building. Staff may still look at it on screen - that is how it gets checked -
// but the page is watermarked so a screenshot cannot pass for the real thing.
if ($download && !tracking_is_releasable($cert)) {
    http_response_code(403);
    exit('Certificate ' . e(tracking_label($cert)) . ' is '
        . e(strtolower(tracking_status($cert))) . ', so it cannot be downloaded.');
}

if ($download) {
    // A PDF outlives the moment, so it always carries the whole design whatever
    // paper the office is using today. Printing onto a blank is something you
    // do at a printer, not something to freeze into a file.
    $path = tracking_pdf($cert);

    if ($path === null) {
        // No PDF engine on this machine. Rather than fail, show the certificate
        // and let the browser's own "Save as PDF" do the work.
        flash('PDF conversion is not set up on this machine. Use the print dialog on '
            . 'this page and choose "Save as PDF".');
        redirect('tracking_view.php?id=' . $id);
    }

    q('UPDATE tracking_certificates SET print_count = print_count + 1 WHERE id = ?', [$id]);
    audit('download_tracking', 'tracking_certificate', $id, []);

    header('Content-Type: application/pdf');
    download_started();   // stops the page's spinner
    header('Content-Disposition: attachment; filename="installation-' . tracking_label($cert)
        . '-' . preg_replace('/[^A-Za-z0-9]/', '', (string)$cert['reg_no']) . '.pdf"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ------------------------------------------------------------------ the sheet
//
// Read by tracking_sheet.php. A global rather than an argument, so
// render_tracking_sheet() keeps the signature its other callers already use.
$GLOBALS['tracking_print_stock'] = $stock;   // always plain now

// Whether the stamp starts ticked: ?stamp=0/1 for this print, otherwise the
// office's usual choice from Settings. It can still be changed on the preview.
$GLOBALS['tracking_print_stamp'] = isset($_GET['stamp'])
    ? $_GET['stamp'] === '1'
    : setting('tracking_print_stamp', '1') === '1';

// The signature, the same way: ?sig=0/1, otherwise the usual choice.
$GLOBALS['tracking_print_signature'] = isset($_GET['sig'])
    ? $_GET['sig'] === '1'
    : setting('tracking_print_signature', '1') === '1';

if ($print) {
    q('UPDATE tracking_certificates SET print_count = print_count + 1 WHERE id = ?', [$id]);
    audit('print_tracking_sheet', 'tracking_certificate', $id, [
        'status'       => tracking_status($cert),
        'stock'        => $stock,
        'stock_serial' => (string)($cert['stock_serial'] ?? ''),
    ]);
} else {
    audit('view_tracking_sheet', 'tracking_certificate', $id,
          ['status' => tracking_status($cert), 'stock' => $stock]);
}

$html = render_tracking_sheet($cert, 'screen');

if ($print) {
    // On load rather than straight away: the background and the logos have to be
    // in before the preview is built, or the printer gets a half-drawn page.
    $html = str_replace('</head>',
        "<script>window.addEventListener('load', function () { window.print(); });"
        . "</script>\n</head>", $html);
}

if (!$staffCanPrint) {
    // The browser's own Ctrl+P gives a blank page.
    $html = str_replace('</head>', "<style>@media print { body { display: none !important; } }</style>
</head>", $html);
}

echo $html;