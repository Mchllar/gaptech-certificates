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
        q('UPDATE certificates SET print_count = print_count + 1,
            released_at = COALESCE(released_at, now()) WHERE id = ?', [$cert['id']]);
}

$html = render_sheet($cert, 'screen');
// Anything that is not currently valid gets stamped, so a screenshot or a printed
// preview cannot pass as a live certificate.
// Anything not currently valid is stamped across the whole sheet, so no single part
// of it (the sticker especially) can be cropped out and passed off as live.
$state = certificate_status($cert);
if ($state !== 'Valid') {
    $labels = ['Expired' => 'EXPIRED', 'Superseded' => 'REPLACED', 'Voided' => 'CANCELLED'];
    $label = $labels[$state] ?? 'NOT VALID';

    $tiles = '';
    for ($i = 0; $i < 24; $i++) {          // 6 columns x 4 rows covers all four parts
        $tiles .= '<span>' . $label . '</span>';
    }

    $stamp = '<style>
      .wm { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 99999;
            pointer-events: none; overflow: hidden;
            display: grid; grid-template-columns: repeat(6, 1fr); grid-template-rows: repeat(4, 1fr); }
      .wm span { display: flex; align-items: center; justify-content: center;
                 font: 800 30px/1 "Segoe UI", Arial, sans-serif; white-space: nowrap;
                 letter-spacing: 3px; color: rgba(200,16,46,0.8);
                 transform: rotate(-26deg);
                 -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .wm .big { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%) rotate(-26deg);
                 font-size: 120px; letter-spacing: 10px; color: rgba(200,16,46,0.8);
                 border: 9px solid rgba(200,16,46,0.8); border-radius: 18px; padding: 16px 40px; }
      .wm .note { position: absolute; bottom: 18px; left: 0; width: 100%; text-align: center;
                  transform: none; font: 600 14px "Segoe UI", Arial, sans-serif;
                  letter-spacing: 0.6px; color: rgba(200,16,46,0.8); }
    </style>
    <div class="wm" aria-hidden="true">' . $tiles
      . '<span class="big">' . $label . '</span>'
      . '<span class="note">This certificate is ' . strtolower($label)
      . ' and is not valid for use. Verify at '
      . e(parse_url($config['base_url'], PHP_URL_HOST) ?: 'the address on the certificate') . '</span>
    </div>';

    $html = str_replace('</body>', $stamp . '</body>', $html);
}
if ($print) {
    // A4 landscape, and open the browser's print dialog straight away.
    $html = str_replace('</head>',
        "<style>@page { size: A4 landscape; margin: 0; }</style>\n"
        . "<script>window.addEventListener('load', function () { window.print(); });</script>\n</head>",
        $html);
}
header('Content-Type: text/html; charset=utf-8');
echo $html;
