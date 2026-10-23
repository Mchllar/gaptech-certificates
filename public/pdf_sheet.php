<?php
/**
 * The page headless Chrome renders, and nothing else.
 *
 * Goes in public/pdf_sheet.php
 *
 * Nobody navigates here. It is opened by Chrome, on this machine, when a PDF is
 * being made - and it exists because the sheet renders correctly over HTTP and has
 * never rendered reliably as a file:// page with file:// assets.
 *
 * ---------------------------------------------------------------------------
 * Why this is safe to have in public/
 * ---------------------------------------------------------------------------
 * Three conditions, all of which must hold, none of which a stranger can satisfy:
 *
 *   1. the request comes from this machine (127.0.0.1) - checked in pdf_token_check
 *   2. the URL carries a signature made with a key that never leaves the server
 *   3. the signature expired two minutes after it was made
 *
 * So the URL is useless the moment it leaves this machine, and useless on this
 * machine two minutes later. Anything that fails a check gets 404 rather than an
 * explanation - a page that tells you why it said no is a page that helps you
 * work out how to make it say yes.
 *
 * It renders in 'screen' mode deliberately. That is the mode whose assets are
 * ordinary relative paths - exactly what the browser already loads correctly - and
 * the whole point of this file is to stop asking Chrome to do anything unusual.
 */
require __DIR__ . '/../app/bootstrap.php';

$kind        = (string)($_GET['kind'] ?? '');
$id          = (int)($_GET['id'] ?? 0);
$expires     = (int)($_GET['exp'] ?? 0);
$sig         = (string)($_GET['sig'] ?? '');
$orientation = ($_GET['orientation'] ?? '') === 'portrait' ? 'portrait' : 'landscape';

if (!in_array($kind, ['cert', 'tracking'], true) || $id <= 0
    || !pdf_token_check($kind, $id, $expires, $sig)) {
    http_response_code(404);
    exit('Not found.');
}

if ($kind === 'cert') {
    require APP_DIR . '/certificates.php';
    $cert = q('SELECT * FROM certificates WHERE id = ?', [$id])->fetch();
    if (!$cert) {
        http_response_code(404);
        exit('Not found.');
    }
    $html = render_sheet($cert, 'screen');
} else {
    require APP_DIR . '/tracking.php';
    $cert = q('SELECT * FROM tracking_certificates WHERE id = ?', [$id])->fetch();
    if (!$cert) {
        http_response_code(404);
        exit('Not found.');
    }
    $html = render_tracking_sheet($cert, 'screen');
}

// The paper. Chrome takes the orientation from the page rather than from a
// command-line switch, which is why this is here rather than in pdf.php.
$rule = '<style>@page { size: A4 ' . $orientation . '; margin: 0; }</style>';
$html = (stripos($html, '</head>') !== false)
    ? preg_replace('~</head>~i', $rule . "\n</head>", $html, 1)
    : $rule . $html;

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo $html;
