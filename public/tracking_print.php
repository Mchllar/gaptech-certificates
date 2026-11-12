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
// Settings holds the usual stock; ?stock= overrides it for one print. That is
// the case worth supporting - the pre-printed blanks run out on a Friday
// afternoon and somebody still has to hand a customer a certificate.
$stock = (string)($_GET['stock'] ?? setting('tracking_print_stock', 'plain'));
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
    header('Content-Disposition: attachment; filename="installation-' . tracking_label($cert)
        . '-' . preg_replace('/[^A-Za-z0-9]/', '', (string)$cert['reg_no']) . '.pdf"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ------------------------------------------------------- which blank was used
//
// A pre-printed certificate already carries a serial of its own. It is recorded
// BEFORE the sheet goes through the printer, not after: once it is in an
// envelope nobody can say which blank went to which customer, and being able to
// say that is the only reason the blanks are numbered at all.
$stockError = '';

if ($stock === 'preprinted' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['action'] ?? '') === 'stock_serial') {
    csrf_check();
    $serial = strtoupper(trim((string)($_POST['stock_serial'] ?? '')));

    if ($serial === '') {
        $stockError = 'Type the serial printed on the blank certificate.';
    } else {
        $taken = q('SELECT id, number FROM tracking_certificates
                     WHERE upper(stock_serial) = upper(?) AND id <> ?', [$serial, $id])->fetch();
        if ($taken) {
            $stockError = 'Blank ' . $serial . ' is already recorded against certificate '
                        . ($taken['number'] ?: 'draft ' . $taken['id'])
                        . '. Check the serial, or that blank has been used twice.';
        } else {
            q('UPDATE tracking_certificates SET stock_serial = ? WHERE id = ?', [$serial, $id]);
            audit('record_stock_serial', 'tracking_certificate', $id,
                  ['stock_serial' => $serial]);
            redirect('tracking_print.php?id=' . $id . '&stock=preprinted&print=1');
        }
    }
    $cert = q('SELECT * FROM tracking_certificates WHERE id = ?', [$id])->fetch();
}

if ($stock === 'preprinted' && trim((string)($cert['stock_serial'] ?? '')) === '') {
    require APP_DIR . '/views/layout.php';
    layout_top('Which blank?');
    layout_back('tracking_view.php?id=' . $id, 'Back to the certificate');
    ?>
    <h1>Which blank certificate?</h1>
    <p class="hint" style="text-align:left">This is about to print onto a pre-printed
       certificate, which carries its own serial in the top right corner. Type that
       serial so the system records which blank this customer was given.</p>

    <?php if ($stockError !== ''): ?><p class="error"><?= e($stockError) ?></p><?php endif; ?>

    <form method="post" class="card-form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <label class="field">Serial on the blank <span class="req">*</span>
        <span class="field-row">
          <input name="stock_serial" required maxlength="20" autofocus
                 style="text-transform:uppercase" placeholder="e.g. 01199"
                 value="<?= e((string)($_POST['stock_serial'] ?? '')) ?>">
        </span>
        <span class="field-hint">Kept against this certificate and never reusable, so the
          same blank cannot be logged against two customers.</span>
      </label>
      <button type="submit" name="action" value="stock_serial">Record it and print</button>
    </form>

    <p class="hint" style="text-align:left">Out of blanks?
       <a href="tracking_print.php?id=<?= (int)$id ?>&amp;stock=plain&amp;print=1">Print this
       one on ordinary paper</a> &mdash; the whole design prints with it.</p>
    <?php
    layout_bottom();
    exit;
}

// ------------------------------------------------------------------ the sheet
//
// Read by tracking_sheet.php. A global rather than an argument, so
// render_tracking_sheet() keeps the signature its other callers already use.
$GLOBALS['tracking_print_stock'] = $stock;

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