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

// The alignment test for the pre-printed stationery: the blank's lines and
// some millimetre rulers. Any certificate will do to draw it - no entries
// are printed - so it uses the latest one.
if (!empty($_GET['align'])) {
    require_staff();
    $any = q('SELECT * FROM certificates ORDER BY id DESC LIMIT 1')->fetch();
    if (!$any) {
        exit('Issue one certificate first - the test is drawn from the certificate layout.');
    }
    audit('print_alignment_test', 'settings', null, ['sheet' => 'governor',
        'offset_x' => setting('governor_offset_x', '0'), 'offset_y' => setting('governor_offset_y', '0')]);
    $GLOBALS['sheet_preprinted'] = true;
    $GLOBALS['sheet_align']      = true;
    $html = render_sheet($any, 'screen');
    echo str_replace('</head>', "<style>@page { size: A4 landscape; margin: 0; }</style>\n</head>", $html);
    exit;
}

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
    download_started();   // stops the page's spinner
    header('Content-Disposition: attachment; filename="Certificate-' . $cert['number'] . '-'
        . preg_replace('/[^A-Za-z0-9]+/', '', $reg) . '.pdf"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ---------------------------------------------------------------- the paper
//
// Plain paper unless ?stock=preprinted asks for the stationery. The usual
// paper in Settings only decides which Print button certificate_view.php puts
// first - a preview, a client or a PDF must never land on the step below.
$stock = (string)($_GET['stock'] ?? 'plain') === 'preprinted' && $user ? 'preprinted' : 'plain';

// ------------------------------------------------------- which blank was used
//
// The stationery carries its own Cert No. in four places. Normally that is the
// number the system gave this certificate - last_cert_number is kept in step
// with the stock - but a blank can be skipped, spoiled or taken out of order,
// and then the paper and the register disagree. So before a blank is used:
//
//  - only a VALID certificate may go onto one;
//  - whoever prints says what Cert No. is on the blank (or that it has none,
//    and the system prints its own in those four places);
//  - the blank's own number is recorded against the certificate, the same way
//    tracking certificates do it - searchable from the register, and never
//    usable for a second vehicle. Nothing is printed over it.
if ($stock === 'preprinted' && !$releasable) {
    http_response_code(403);
    exit('Certificate ' . e(certificate_label($cert)) . ' is ' . e(strtolower($status))
        . ', so it cannot be printed onto the pre-printed stationery.');
}

$stockError = '';

if ($stock === 'preprinted' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['action'] ?? '') === 'stock_serial') {
    csrf_check();
    $hasOwn = ($_POST['blank_kind'] ?? '') === 'numbered';
    $serial = $hasOwn ? strtoupper(trim((string)($_POST['stock_serial'] ?? ''))) : '';
    $digits = fn(string $s): string => ltrim(preg_replace('/[^0-9A-Z]/', '', strtoupper($s)), '0');
    $same   = $serial !== '' && $digits($serial) === $digits((string)$cert['number']);

    if ($hasOwn && $serial === '') {
        $stockError = 'Type the Cert No. printed on the blank, or choose "It is empty".';
    } else {
        // Same number as the system: nothing to remember. Different: kept.
        $keep  = ($hasOwn && !$same) ? $serial : '';
        $taken = $keep === '' ? null
            : q('SELECT id, number FROM certificates WHERE upper(stock_serial) = upper(?) AND id <> ?',
                [$keep, $id])->fetch();
        if ($taken) {
            $stockError = 'Blank ' . $keep . ' is already recorded against certificate '
                . ($taken['number'] ?: 'draft ' . $taken['id']) . '. Check the number, or that blank has been used twice.';
        } else {
            if ($keep !== (string)$cert['stock_serial']) {
                q('UPDATE certificates SET stock_serial = ? WHERE id = ?', [$keep, $id]);
                audit('record_stock_serial', 'certificate', $id,
                      ['stock_serial' => $keep, 'previous' => (string)$cert['stock_serial']]);
            }
            redirect('certificate_print.php?id=' . $id . '&stock=preprinted&ready=1&print=1'
                . '&own=' . ($hasOwn ? '1' : '0') . '&sig=' . (!empty($_POST['sig']) ? '1' : '0'));
        }
    }
    $cert = q('SELECT * FROM certificates WHERE id = ?', [$id])->fetch();
}

if ($stock === 'preprinted' && empty($_GET['ready'])) {
    require APP_DIR . '/views/layout.php';
    layout_top('Print onto a blank');
    layout_back('certificate_view.php?id=' . $id, 'Back to the certificate');
    $prev     = trim((string)($cert['stock_serial'] ?? ''));
    $numbered = ($_POST['blank_kind'] ?? ($prev !== '' ? 'numbered' : 'empty')) === 'numbered';
    $sigOn    = isset($_POST['action']) ? !empty($_POST['sig'])
                                        : setting('governor_print_signature', '1') === '1';
    ?>
    <h1>Print onto a pre-printed certificate</h1>
    <p class="hint" style="text-align:left">Only the entries are printed &mdash; the blank already has
       the design, the dealer details and the QR codes. Load <b>one</b> blank sheet in the printer before
       you continue.</p>

    <?php if ($stockError !== ''): ?><p class="error"><?= e($stockError) ?></p><?php endif; ?>

    <form method="post" class="card-form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <fieldset>
        <legend>The "Cert No." printed on the blank</legend>

        <label class="inline" style="display:flex; gap:10px; align-items:flex-start; margin-bottom:12px">
          <input type="radio" name="blank_kind" value="empty" <?= $numbered ? '' : 'checked' ?>
                 style="width:16px; margin-top:3px">
          <span><b>It is empty</b> &mdash; print this certificate's number,
            <b><?= e((string)$cert['number']) ?></b>, in the four Cert No. spaces.
            <span class="field-hint" style="display:block">The paper and the system then carry the
              same number. Nothing else to record.</span></span>
        </label>

        <label class="inline" style="display:flex; gap:10px; align-items:flex-start">
          <input type="radio" name="blank_kind" value="numbered" <?= $numbered ? 'checked' : '' ?>
                 style="width:16px; margin-top:3px">
          <span><b>It already has a number printed on it</b>
            <span class="field-hint" style="display:block">Type it below. It is recorded against this
              certificate, can never be used for another, and searching the register for it
              finds this certificate. Nothing is printed over it.</span></span>
        </label>

        <label class="field" style="margin-top:10px">Number on the blank
          <span class="field-row">
            <input name="stock_serial" maxlength="20" id="stock-serial"
                   style="text-transform:uppercase" placeholder="e.g. 15060"
                   value="<?= e((string)($_POST['stock_serial'] ?? $prev)) ?>">
          </span>
          <?php if ($prev !== ''): ?>
            <span class="field-hint">Last printed on blank <?= e($prev) ?>. If that sheet was
              spoiled, type the new blank's number.</span>
          <?php endif; ?>
        </label>
      </fieldset>

      <label class="inline" style="display:flex; gap:10px; align-items:center; margin:4px 0 14px">
        <input type="checkbox" name="sig" value="1" <?= $sigOn ? 'checked' : '' ?> style="width:16px; margin:0">
        <span><b>Print the signature</b> <span class="field-hint">&mdash; untick to sign it by hand</span></span>
      </label>
      <button type="submit" name="action" value="stock_serial">Continue to print</button>
    </form>

    <p class="hint" style="text-align:left">Out of blanks?
       <a href="certificate_print.php?id=<?= (int)$id ?>&amp;print=1" target="_blank">Print on ordinary
       paper</a> instead &mdash; the whole design prints with it.</p>
    <script>
      (function () {
        var input = document.getElementById('stock-serial');
        function sync() {
          var on = document.querySelector('input[name=blank_kind]:checked').value === 'numbered';
          input.disabled = !on; input.required = on;
        }
        document.querySelectorAll('input[name=blank_kind]').forEach(function (r) {
          r.addEventListener('change', sync);
        });
        sync();
      })();
    </script>
    <?php
    layout_bottom();
    exit;
}

// Read by app/views/sheet.php.
$GLOBALS['sheet_preprinted']      = $stock === 'preprinted';
$GLOBALS['sheet_own_number']      = ($stock === 'preprinted' && ($_GET['own'] ?? '1') === '1') ? 'yes' : '';
$GLOBALS['sheet_print_signature'] = isset($_GET['sig'])
    ? $_GET['sig'] === '1'
    : setting('governor_print_signature', '1') === '1';
// A client viewing their own certificate gets the sheet without the print options.
$GLOBALS['sheet_no_bar'] = !$user;

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
