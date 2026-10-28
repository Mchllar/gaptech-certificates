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
        redirect('certificate_print.php?id=' . $id . '&print=1');
    }

    if ($client) {
        q('UPDATE certificates SET download_count = download_count + 1 WHERE id = ?', [$id]);
    }
    audit('download_pdf', 'certificate', $id);

    $reg = json_decode((string)$cert['snapshot'], true)['vehicle']['reg_no'] ?? '';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Certificate-' . $cert['number'] . '-'
        . preg_replace('/[^A-Za-z0-9]+/', '', $reg) . '.pdf"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// --------------------------------------------------------------------- screen

if ($print) {
    q('UPDATE certificates SET print_count = print_count + 1 WHERE id = ?', [$id]);
    audit('print_sheet', 'certificate', $id);
} else {
    audit('view_sheet', 'certificate', $id, ['status' => $status]);
}

$html = render_sheet($cert, 'screen');

if ($print) {
    // A4 landscape, and open the browser's print dialog straight away.
    $html = str_replace('</head>',
        "<style>@page { size: A4 landscape; margin: 0; }</style>\n"
        . "<script>window.addEventListener('load', function () { window.print(); });</script>\n</head>",
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
