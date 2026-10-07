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

// Clients may only download a certificate that is currently valid.
if ($client && certificate_status($cert) !== 'Valid') {
    $state = strtolower(certificate_status($cert));
    http_response_code(403);
    exit('<!doctype html><meta charset="utf-8"><title>Not available</title>'
        . '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:520px;margin:80px auto;'
        . 'padding:28px;border:1px solid #D9E2EC;border-radius:12px;background:#fff">'
        . '<h1 style="font-size:19px;margin:0 0 10px;color:#0D4F72">This certificate is ' . e($state) . '</h1>'
        . '<p style="color:#5A6472;font-size:14px;line-height:1.5">Only a valid certificate can be downloaded. '
        . 'You can still view this one, or call ' . e(setting('company_name')) . ' on '
        . e(setting('cell')) . ' to renew.</p>'
        . '<p><a href="client_portal.php" style="color:#0D4F72">Back to my certificates</a></p></div>');
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

// Any download counts as the certificate leaving the office, staff or client.
q('UPDATE certificates SET download_count = download_count + 1,
          released_at = COALESCE(released_at, now()) WHERE id = ?', [$cert['id']]);
          
audit('download_pdf', 'certificate', (int)$cert['id']);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Certificate-' . $cert['number'] . '-'
    . preg_replace('/[^A-Za-z0-9]+/', '', json_decode($cert['snapshot'], true)['vehicle']['reg_no']) . '.pdf"');
header('Content-Length: ' . filesize($path));
readfile($path);
