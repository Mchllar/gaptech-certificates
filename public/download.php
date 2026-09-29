<?php
/** Send the PDF. Staff may download any certificate, a client only their own. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';

$cert = q('SELECT * FROM certificates WHERE id = ?', [(int)($_GET['id'] ?? 0)])->fetch();
if (!$cert) {
    http_response_code(404);
    exit('Certificate not found.');
}

$user = current_user();
$client = current_client();
if (!$user && (!$client || (int)$client['id'] !== (int)$cert['client_id'])) {
    redirect('client_login.php');
}

try {
    $path = certificate_pdf($cert);
} catch (Throwable $err) {
    http_response_code(500);
    exit('Could not create the PDF: ' . e($err->getMessage()));
}

if ($path === null) {
    // No wkhtmltopdf configured: use the browser's own print-to-PDF instead.
    redirect('sheet.php?id=' . (int)$cert['id'] . '&print=1');
}

if ($client) {
    q('UPDATE certificates SET download_count = download_count + 1 WHERE id = ?', [$cert['id']]);
}
audit('download_pdf', 'certificate', (int)$cert['id']);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Certificate-' . $cert['number'] . '-'
    . preg_replace('/[^A-Za-z0-9]+/', '', json_decode($cert['snapshot'], true)['vehicle']['reg_no']) . '.pdf"');
header('Content-Length: ' . filesize($path));
readfile($path);
