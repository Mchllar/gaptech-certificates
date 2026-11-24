<?php
/**
 * Print or download a tracking installation certificate.
 *
 * Goes in public/tracking_print.php  (replaces what is there)
 *
 *   tracking_print.php?id=12                   the certificate on screen
 *   tracking_print.php?id=12&print=1           and open the print dialog at once
 *   tracking_print.php?id=12&stock=plain       this one on ordinary paper
 *   tracking_print.php?id=12&stock=preprinted  onto a pre-printed blank
 *   tracking_print.php?id=12&download=1        the same thing as a PDF file
 *
 * The role checks here are the real ones. Hiding a button on the list page stops
 * nobody; this is the page that has to say no.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/tracking.php';

$user = require_staff();

// The alignment test for the pre-printed blanks: no certificate involved.
if (!empty($_GET['align'])) {
    audit('print_alignment_test', 'settings', null, [
        'offset_x' => setting('print_offset_x', '0'), 'offset_y' => setting('print_offset_y', '0')]);
    require APP_DIR . '/views/tracking_align_test.php';
    exit;
}

$id   = (int)($_GET['id'] ?? 0);
$cert = q('SELECT * FROM tracking_certificates WHERE id = ?', [$id])->fetch();
if (!$cert) {
    http_response_code(404);
    exit('No such certificate.');
}

$download = !empty($_GET['download']);
$print    = !empty($_GET['print']);

// ---------------------------------------------------------------- the paper
//
// Plain paper unless ?stock=preprinted asks for the blank. The usual paper in
// Settings decides which Print button tracking_view.php puts first; it is not
// applied here, because a preview, an accounts review or a pending certificate
// must never land on the "which blank?" step.
$stock = (string)($_GET['stock'] ?? 'plain');
if (!in_array($stock, ['plain', 'preprinted'], true)) {
    $stock = 'plain';
}

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

// ------------------------------------------------------- which blank was used
//
// Printing onto a pre-printed blank uses up a numbered piece of stationery, so
// it is a deliberate step, taken every time:
//
//  - only a VALID certificate may go onto a blank. A pending or expired one
//    can still be looked at, on plain paper, watermarked; it must not end up
//    on the real stationery.
//  - whoever prints says whether the blank's "Serial No." space is empty. If
//    it is, the system's own number is printed there, so the paper and the
//    register carry one number and nothing else needs recording. If the blank
//    already carries a number, that number is recorded against this
//    certificate - BEFORE printing, since once it is in an envelope nobody can
//    say which blank went to which customer - and can never be booked twice.
//    Nothing else is printed in that corner; the register finds the
//    certificate by the blank's number as well as its own.
$stockError = '';

if ($stock === 'preprinted' && !tracking_is_releasable($cert)) {
    http_response_code(403);
    exit('Certificate ' . e(tracking_label($cert)) . ' is '
        . e(strtolower(tracking_status($cert))) . ', so it cannot be printed onto a '
        . 'pre-printed blank. <a href="tracking_print.php?id=' . $id . '&amp;stock=plain">'
        . 'View it on plain paper</a>.');
}

if ($stock === 'preprinted' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['action'] ?? '') === 'stock_serial') {
    csrf_check();
    $hasOwn = ($_POST['blank_kind'] ?? '') === 'numbered';
    $serial = $hasOwn ? strtoupper(trim((string)($_POST['stock_serial'] ?? ''))) : '';

    if ($hasOwn && $serial === '') {
        $stockError = 'Type the number printed on the blank, or choose "It is empty".';
    } else {
        $taken = $serial === '' ? null
            : q('SELECT id, number FROM tracking_certificates
                  WHERE upper(stock_serial) = upper(?) AND id <> ?', [$serial, $id])->fetch();
        if ($taken) {
            $stockError = 'Blank ' . $serial . ' is already recorded against certificate '
                        . ($taken['number'] ?: 'draft ' . $taken['id'])
                        . '. Check the number, or that blank has been used twice.';
        } else {
            if ($serial !== (string)$cert['stock_serial']) {
                q('UPDATE tracking_certificates SET stock_serial = ? WHERE id = ?', [$serial, $id]);
                // The old value goes in the audit, so a spoiled blank is still traceable.
                audit('record_stock_serial', 'tracking_certificate', $id,
                      ['stock_serial' => $serial, 'previous' => (string)$cert['stock_serial']]);
            }
            redirect('tracking_print.php?id=' . $id . '&stock=preprinted&ready=1&print=1'
                . '&stamp=' . (!empty($_POST['stamp']) ? '1' : '0')
                . '&sig=' . (!empty($_POST['sig']) ? '1' : '0'));
        }
    }
    $cert = q('SELECT * FROM tracking_certificates WHERE id = ?', [$id])->fetch();
}

if ($stock === 'preprinted' && empty($_GET['ready'])) {
    require APP_DIR . '/views/layout.php';
    layout_top('Print onto a blank');
    layout_back('tracking_view.php?id=' . $id, 'Back to the certificate');
    $prev     = trim((string)($cert['stock_serial'] ?? ''));
    $numbered = ($_POST['blank_kind'] ?? ($prev !== '' ? 'numbered' : 'empty')) === 'numbered';
    ?>
    <h1>Print onto a pre-printed certificate</h1>
    <p class="hint" style="text-align:left">Only the entries are printed &mdash; the blank
       already has the logos, the table and the wording. Load <b>one</b> blank in the printer
       before you continue.</p>

    <?php if ($stockError !== ''): ?><p class="error"><?= e($stockError) ?></p><?php endif; ?>

    <form method="post" class="card-form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <fieldset>
        <legend>The "Serial No." in the top right corner of the blank</legend>

        <label class="inline" style="display:flex; gap:10px; align-items:flex-start; margin-bottom:12px">
          <input type="radio" name="blank_kind" value="empty" <?= $numbered ? '' : 'checked' ?>
                 style="width:16px; margin-top:3px">
          <span><b>It is empty</b> &mdash; print this certificate's number,
            <b><?= e(tracking_label($cert)) ?></b>, there.
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
                   style="text-transform:uppercase" placeholder="e.g. 01199"
                   value="<?= e((string)($_POST['stock_serial'] ?? $prev)) ?>">
          </span>
          <?php if ($prev !== ''): ?>
            <span class="field-hint">Last printed on blank <?= e($prev) ?>. If that sheet was
              spoiled, type the new blank's number.</span>
          <?php endif; ?>
        </label>
      </fieldset>
      <?php $stampOn = isset($_POST['action']) ? !empty($_POST['stamp'])
                                                 : setting('tracking_print_stamp', '1') === '1'; ?>
      <label class="inline" style="display:flex; gap:10px; align-items:center; margin:4px 0 14px">
        <input type="checkbox" name="stamp" value="1" <?= $stampOn ? 'checked' : '' ?> style="width:16px; margin:0">
        <span><b>Print the stamp</b> <span class="field-hint">&mdash; untick to stamp it by hand</span></span>
      </label>
      <?php $sigOn = isset($_POST['action']) ? !empty($_POST['sig'])
                                               : setting('tracking_print_signature', '1') === '1'; ?>
      <label class="inline" style="display:flex; gap:10px; align-items:center; margin:-6px 0 14px">
        <input type="checkbox" name="sig" value="1" <?= $sigOn ? 'checked' : '' ?> style="width:16px; margin:0">
        <span><b>Print the signature</b> <span class="field-hint">&mdash; untick to sign it by hand</span></span>
      </label>
      <button type="submit" name="action" value="stock_serial">Continue to print</button>
    </form>

    <p class="hint" style="text-align:left">Out of blanks?
       <a href="tracking_print.php?id=<?= (int)$id ?>&amp;stock=plain&amp;print=1" target="_blank">Print
       on ordinary paper</a> instead &mdash; the whole design prints with it.</p>
    <script>
      (function () {
        var input = document.getElementById('stock-serial');
        function sync() {
          var on = document.querySelector('input[name=blank_kind]:checked').value === 'numbered';
          input.disabled = !on; input.required = on;
          if (on) { input.focus(); }
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

// Read by tracking_sheet_preprinted.php: whether to print our number in the
// Serial No. space, or leave the blank's own number alone.
$GLOBALS['tracking_serial_on_blank'] = (string)($cert['stock_serial'] ?? '');

// ------------------------------------------------------------------ the sheet
//
// Read by tracking_sheet.php. A global rather than an argument, so
// render_tracking_sheet() keeps the signature its other callers already use.
$GLOBALS['tracking_print_stock'] = $stock;

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

echo $html;