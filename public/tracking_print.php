<?php
/**
 * Print or download a tracking installation certificate.
 *
 * Goes in public/tracking_print.php
 *
 *   tracking_print.php?id=12              the certificate on screen, ready to print
 *   tracking_print.php?id=12&download=1   the same thing as a PDF file
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

// Accounts review certificates, they do not issue them, so the PDF is not theirs
// to hand out.
if ($download && $user['role'] !== 'administrator') {
    http_response_code(403);
    exit('Downloading a certificate is for administrators. You are signed in as '
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
    $path = tracking_pdf($cert);

    if ($path === null) {
        // wkhtmltopdf is not configured on this machine. Rather than fail, show the
        // certificate and let the browser's own "Save as PDF" do the work.
        flash('PDF conversion is not set up on this machine. Use the print dialog on '
            . 'this page and choose "Save as PDF".');
        redirect('tracking_view.php?id=' . $id);
    }

    q('UPDATE tracking_certificates SET print_count = print_count + 1 WHERE id = ?', [$id]);
    audit('download_tracking', 'tracking_certificate', $id, []);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="installation-' . tracking_label($cert)
        . '-' . preg_replace('/[^A-Za-z0-9]/', '', (string)$cert['reg_no']) . '.pdf"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

audit('view_tracking_sheet', 'tracking_certificate', $id, ['status' => tracking_status($cert)]);
echo render_tracking_sheet($cert, 'screen');
