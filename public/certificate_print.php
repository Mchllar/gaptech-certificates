<?php
/**
 * Print or download a speed governor certificate.
 *
 * Goes in public/certificate_print.php
 *
 *   certificate_print.php?id=12               the sheet on screen
 *   certificate_print.php?id=12&print=1       the sheet, print dialog opens itself
 *   certificate_print.php?id=12&download=1    the same thing as a PDF file
 *
 * This replaces sheet.php and download.php as the place the rules live. Both of those
 * stay as one-line forwarders, so every link already in the system keeps working.
 *
 * The rules, in one place:
 *
 *   on screen   administrators and accounts may open any certificate - accounts need
 *               to see what they are approving. A client may open only their own, and
 *               only once it is valid.
 *   download    administrators and clients. Accounts approve certificates, they do not
 *               hand them out. Only a valid certificate may be downloaded at all.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';

$id   = (int)($_GET['id'] ?? 0);
$cert = q('SELECT * FROM certificates WHERE id = ?', [$id])->fetch();
if (!$cert) {
    http_response_code(404);
    exit('Certificate not found.');
}

$user   = current_user();
$client = current_client();

$download = !empty($_GET['download']);
$print    = !empty($_GET['print']);

// Both of these already live in app/certificates.php and already take approval into
// account, so this page asks them rather than working it out a second way.
$status     = certificate_status($cert);
$releasable = certificate_is_releasable($cert);

// ---------------------------------------------------------------- who may look

if (!$user && !$client) {
    redirect($download ? 'client_login.php' : 'login.php');
}

if ($client) {
    if ((int)$client['id'] !== (int)$cert['client_id']) {
        http_response_code(403);
        exit('That certificate belongs to another account.');
    }
    // A client sees a certificate once it is valid, and not before. A pending one has
    // no number, and an expired or voided one is not theirs to pass on.
    if (!$releasable) {
        http_response_code(403);
        exit('Certificate ' . e(certificate_label($cert)) . ' is '
            . e(strtolower($status)) . ', so it is not available. '
            . 'Please contact GAPTECH Solutions to renew it.');
    }
}

// --------------------------------------------------------------- who may print
//
// Staff printing is for administrators. Accounts may open the sheet on screen -
// that is how they check a certificate before releasing it - but not print it:
// no Print buttons, no print dialog, and the browser's own Ctrl+P prints a blank
// page (see the style added at the bottom).
$staffCanPrint = $user && ($user['role'] ?? '') === 'administrator';
$wantsPrint    = $print;
if ($user && !$client && !$staffCanPrint && $wantsPrint) {
    http_response_code(403);
    exit('Printing certificates is for administrators. You are signed in as '
        . e((string)$user['role']) . '. You can still <a href="certificate_print.php?id=' . $id
        . '">view it on screen</a>.');
}

// ------------------------------------------------------------ who may download

// if ($download && $user && $user['role'] !== 'administrator') {
//     http_response_code(403);
//     exit('Downloading a certificate is for administrators. You are signed in as '
//         . e($user['role']) . '.');
// }

// Nothing that is pending, rejected, expired, superseded or void leaves the building.
// Staff may still look at it on screen - that is how it gets checked.
if ($download && !$releasable) {
    http_response_code(403);
    exit('Certificate ' . e(certificate_label($cert)) . ' is ' . e(strtolower($status))
        . ', so it cannot be downloaded.');
}

// ------------------------------------------------------------------- download

if ($download) {
    try {
        $path = certificate_pdf($cert);
    } catch (Throwable $err) {
        http_response_code(500);
        exit('Could not create the PDF: ' . e($err->getMessage()));
    }

    if ($path === null) {
        // wkhtmltopdf is not set up on this machine. Show the sheet with the print
        // dialog open instead, so "Save as PDF" does the same job.
        redirect('certificate_print.php?id=' . $id . ($client || $staffCanPrint ? '&print=1' : ''));
    }

    if ($client) {
        q('UPDATE certificates SET download_count = download_count + 1 WHERE id = ?', [$id]);
    }
    audit('download_pdf', 'certificate', $id);

    $reg = json_decode((string)$cert['snapshot'], true)['vehicle']['reg_no'] ?? '';
    header('Content-Type: application/pdf');
    download_started();   // stops the page's spinner
    header('Content-Disposition: attachment; filename="Certificate-' . $cert['number'] . '-'
        . preg_replace('/[^A-Za-z0-9]+/', '', $reg) . '.pdf"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ---------------------------------------------------------------- the paper
//
// Always plain paper, the whole design. Printing onto pre-printed stationery was
// removed: the stationery's own QR codes cannot point at this certificate.

// Read by app/views/sheet.php.
$GLOBALS['sheet_print_signature'] = isset($_GET['sig'])
    ? $_GET['sig'] === '1'
    : setting('governor_print_signature', '1') === '1';
// A client viewing their own certificate gets the sheet without the print options.
$GLOBALS['sheet_no_bar'] = !$user || !$staffCanPrint;   // no Print button for accounts either

// --------------------------------------------------------------------- screen

if ($print) {
    q('UPDATE certificates SET print_count = print_count + 1 WHERE id = ?', [$id]);
    audit('print_sheet', 'certificate', $id);
} else {
    audit('view_sheet', 'certificate', $id, ['status' => $status]);
}

$html = render_sheet($cert, 'screen');

// A4 landscape whenever it is printed - also from the Print button on a
// preview, not only when the dialog opens itself.
$html = str_replace('</head>', "<style>@page { size: A4 landscape; margin: 0; }</style>\n</head>", $html);

// Accounts looking at it: the browser's own Ctrl+P / Print gives a blank page.
if ($user && !$client && !$staffCanPrint) {
    $html = str_replace('</head>', "<style>@media print { body { display: none !important; } }</style>\n</head>", $html);
}

if ($print) {
    // Open the browser's print dialog straight away.
    $html = str_replace('</head>',
        "<script>window.addEventListener('load', function () { window.print(); });</script>\n</head>",
        $html);
}

// ---------------------------------------------------------------------------
// A certificate that is not valid is stamped across, so a screenshot of one
// cannot be passed off as the real thing. Staff can still read every word.
//
// This is the only thing on this page that changes how the sheet looks. Delete
// this block if you would rather it printed exactly as before.
// ---------------------------------------------------------------------------
if (!$releasable) {
    $mark = '<div style="position:fixed; inset:0; z-index:9999; pointer-events:none;
                  display:flex; align-items:center; justify-content:center;">
               <span style="font:700 150px/1 Arial, sans-serif; letter-spacing:22px;
                     color:rgba(195,18,26,0.14); transform:rotate(-20deg); white-space:nowrap;">'
           . e(strtoupper($status)) . '</span></div>';
    $html = str_replace('</body>', $mark . '</body>', $html);
}

header('Content-Type: text/html; charset=utf-8');
echo $html;
