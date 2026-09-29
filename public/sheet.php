<?php
/** The certificate sheet itself, for screen and for printing. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';

$cert = q('SELECT * FROM certificates WHERE id = ?', [(int)($_GET['id'] ?? 0)])->fetch();
if (!$cert) {
    http_response_code(404);
    exit('Certificate not found.');
}

// Staff may open any certificate; a client only their own.
$user = current_user();
$client = current_client();
if (!$user && (!$client || (int)$client['id'] !== (int)$cert['client_id'])) {
    redirect('login.php');
}

$print = !empty($_GET['print']);
if ($print) {
    q('UPDATE certificates SET print_count = print_count + 1 WHERE id = ?', [$cert['id']]);
    audit('print_sheet', 'certificate', (int)$cert['id']);
}

$html = render_sheet($cert, 'screen');
if ($print) {
    // A4 landscape, and open the browser's print dialog straight away.
    $html = str_replace('</head>',
        "<style>@page { size: A4 landscape; margin: 0; }</style>\n"
        . "<script>window.addEventListener('load', function () { window.print(); });</script>\n</head>",
        $html);
}
header('Content-Type: text/html; charset=utf-8');
echo $html;
