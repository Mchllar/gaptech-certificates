<?php
/**
 * The tracking installation certificate: A4 portrait, printed in full.
 *
 * Goes in app/views/tracking_sheet.php
 *
 * This follows the printed certificate from the books - the blackletter title, the
 * dd/mm/20yy sentence, the bordered table, the signature line and the footer - so a
 * printed one and a system one look like the same document.
 *
 * Expects $d (the snapshot, as prepared by render_tracking_sheet()) and
 * $assets (a URL in screen mode, a file:// path in pdf mode).
 *
 * Measurements are in millimetres, so what the browser shows and what comes out of
 * the printer are the same size.
 *
 * NOTE on the Communications Authority mark: this file prints assets/ca_logo.* if
 * such a file exists - .png, .jpg, .svg, it does not matter - and leaves the space
 * blank if it does not. The mark belongs to the Authority, so the official artwork
 * has to be supplied rather than redrawn.
 */

/**
 * Supplied by render_tracking_sheet(), which includes this file. Declared here so
 * the editor can see where they come from - the values themselves are set by the
 * caller, and this block has no effect at run time.
 *
 * @var array  $d             the frozen snapshot: everything printed below
 * @var string $assets        where the images live: 'assets', or a file:// path for the PDF
 * @var string $trackingMode  'screen' or 'pdf'
 */

$assetDir = ROOT_DIR . '/public/assets';
$have = fn(string $f): bool => $f !== '' && is_file($assetDir . '/' . basename($f));

/**
 * Find a logo by name, whatever kind of image file it happens to be.
 * ca_logo.jpg, ca_logo.png, ca_logo.svg - all are found, so nobody has to
 * convert artwork before it can be used.
 */
$pick = function (string $base) use ($assetDir, $assets): string {
    foreach (['png', 'jpg', 'jpeg', 'webp', 'svg', 'gif', 'PNG', 'JPG', 'JPEG'] as $ext) {
        $file = $assetDir . '/' . $base . '.' . $ext;
        if (is_file($file)) {
            // On screen, the file's date goes on the address: browsers keep images a long
            // time, and without it an updated background (or logo) kept showing the old
            // one. Not for the PDF, which reads the file straight from disk every time.
            $v = (($GLOBALS['trackingMode'] ?? 'screen') === 'screen') ? '?v=' . filemtime($file) : '';
            return $assets . '/' . $base . '.' . $ext . $v;
        }
    }
    return '';
};

$caLogo   = $pick('ca_logo');
$gapLogo  = $pick('gap_logo');
$goldMark = $pick('gap_logo_gold');

// The GAP symbol as it appears on the printed stationery, and the guilloche
// background. $pick() finds either whatever file type it happens to be, and
// returns '' when it is not there - so a missing file prints a plain sheet
// rather than a broken image.
$houseMark = $pick('gap_mark') ?: $goldMark;
$security  = $pick('security_bg');

/** The signature and stamp are stored as a setting holding a path under assets/. */
$sigFile   = (string)($d['company']['signature'] ?? setting('signature_image', ''));
$stampFile = (string)($d['company']['stamp'] ?? setting('tracking_stamp_image', ''));
// Settings saves the images themselves (data:image/... URIs) so they travel inside the PDF.
// This used to accept only a file name under assets/, so a saved signature or stamp never
// appeared on a tracking certificate at all. Both forms work now.
$imgSrc    = fn(string $f): string => preg_match('~^data:image/(png|jpe?g|gif|webp);base64,~i', $f) ? $f
                                    : ($have($f) ? $assets . '/' . basename($f) : '');
$sigSrc    = $imgSrc($sigFile);
$stampSrc  = $imgSrc($stampFile);

$status    = $d['cert']['status'] ?? 'Valid';

// A certificate whose unit has been taken off is the one that most needs saying
// so on the paper: it looks completely normal otherwise, and the customer may
// still be holding it months later.
$watermark = in_array($status, ['Voided', 'Superseded', 'Expired', 'Pending approval',
                                'Rejected', 'Unit removed'], true)
    ? strtoupper($status) : '';

// The sentence reads "on __/__/20__", so the installation date is split three ways.
$inst = strtotime((string)($d['cert']['installed_on'] ?? '')) ?: time();
$instDay   = date('d', $inst);
$instMonth = date('m', $inst);
$instYear  = date('y', $inst);

/** Dates in the table are dd/mm/yyyy, as they are on the printed certificate. */
$shortDate = function ($iso) {
    $t = strtotime((string)$iso);
    return $t ? date('d/m/Y', $t) : '';
};

// One box, both units, separated by a slash - as the printed certificate reads.
// imeis is the list on anything issued since two units became possible; imei is
// the frozen string on everything older, and that still has to print.
$trkImeis = array_values(array_filter(array_map('trim',
    (array)($d['device']['imeis'] ?? [])), fn($x) => $x !== ''));
$trkLine  = $trkImeis ? implode(' / ', $trkImeis) : (string)($d['device']['imei'] ?? '');

$rows = [
    ['Vehicle Reg No.',     $d['vehicle']['reg_no']],
    ['Vehicle Make/Model',  $d['vehicle']['make_model']],
    ['Chassis No.',         $d['vehicle']['chassis_no']],
    ['Engine No.',          $d['vehicle']['engine_no']],
    ['Colour',              $d['vehicle']['colour']],
    ['Ownership Details',   $d['vehicle']['ownership']],
    ['FINANCIER',           $d['vehicle']['financier'] !== '' ? $d['vehicle']['financier'] : 'N/A'],
    // Always singular, as the printed certificate reads. Two units still print
    // on the one line, separated by a slash - the label names the row, not the
    // number of boxes in it.
    ['Track IMEI No.', $trkLine],    ['Subscription Date',   $shortDate($d['cert']['subscription_date'] ?? '')],
    ['Expiry Date',         $shortDate($d['cert']['expiry_date'] ?? '')],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Certificate of Installation <?= e($d['cert']['number']) ?> &middot; <?= e($d['vehicle']['reg_no']) ?></title>
<style>
  /* The blackletter face already in assets/, the same one the speed governor sheet uses. */
  @font-face {
    font-family: "CertBlackletter";
    src: url("<?= $assets ?>/Blackletter-Fraktur-Bold.ttf");
  }

  :root {
    --navy: #0D4F72;
    --red:  #C3121A;
    --ink:  #1A1A1A;
    --lbl:  #4A4A4A;
    --line: #333;
  }

  @page { size: A4 portrait; margin: 0; }

  html, body { margin: 0; padding: 0; background: #E9EDF1; }
  body { font-family: "Calibri", "Carlito", "Segoe UI", sans-serif; color: var(--ink); }

  .sheet {
    position: relative;
    width: 210mm; height: 297mm;
    margin: 0 auto;
    padding: 13mm 16mm 0;
    box-sizing: border-box;
    background: #fff;
    overflow: hidden;
  }
  .inner { position: relative; z-index: 2; height: 271mm; display: flex; flex-direction: column; }

  /* ---------- the security background ----------

     An <img>, not a CSS background-image, and that is deliberate: a browser
     printing a page drops background images unless it is told not to, and
     "the watermark is missing but only when printed" is a fault nobody finds
     until a customer is holding the sheet. An image prints.

     It sits at z-index 0, under everything, and the page already carries
     overflow:hidden so it is clipped to the sheet.                          */
  .security {
    position: absolute; inset: -6mm;           /* bleed past the trim */
    width: calc(100% + 12mm); height: calc(100% + 12mm);
    z-index: 0; pointer-events: none;
  }
  /* Company-name bands down the page, over the background pattern and under everything
     else. Plain text in a plain font so it stays readable in print and in the PDF. */
  .bands { position: absolute; inset: 0; z-index: 0; pointer-events: none; overflow: hidden; }
  .band {
    position: absolute; top: -2mm; height: 302mm;
    writing-mode: vertical-rl;                 /* reads top to bottom, like the old image text */
    font-family: Arial, "Liberation Sans", Helvetica, sans-serif;
    font-size: 1.85mm; font-weight: 700; letter-spacing: 0.25mm; line-height: 1;
    white-space: nowrap; overflow: hidden;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }
  .band.g { color: rgba(140, 198, 63, 0.62); }   /* GAP green */
  .band.b { color: rgba(24, 144, 208, 0.55); }   /* GAP blue */

  /* The GAP mark behind the table, as on the printed certificate. */

    /* The GAP mark behind the table. Every property it needs is here, so it
     does not matter what order the rules are in or what was replaced earlier. */
  .paper-mark {
    position: absolute;
    left: 50%;
    top: 108mm;
    transform: translateX(-50%);
    width: 115mm;
    opacity: 0.26;
    z-index: 1;
    pointer-events: none;
  }

  table td { vertical-align: middle; }

  table td { padding-top: 3.1mm; padding-bottom: 3.1mm; }

  /* ---------- serial ---------- */
  .serial { text-align: right; font-size: 4.3mm; }
  .serial b { font-weight: 700; }
  .serial .no {
    font-family: "Consolas", "DejaVu Sans Mono", monospace;
    font-size: 6mm; letter-spacing: 1.2mm; margin-left: 2mm;
  }

  /* ---------- heading ---------- */
  .head { display: flex; align-items: flex-end; gap: 8mm; margin-top: 2mm; }
  .licensed { text-align: center; }
  .licensed .cap { display: block; font-style: italic; font-size: 2.9mm; margin-bottom: 0.8mm; text-align: left; }
  .licensed img { height: 13mm; }
  /* Left blank when assets/ca_logo.png is not there, so a missing file leaves
     white space rather than printing an empty box. */
  .licensed .ca-slot { width: 42mm; height: 13mm; }
  /* The logo file does not carry the tagline, so it is set alongside it here,
     from the tagline in Settings. Change it there and it changes on every
     certificate issued afterwards. */
  .head .brand { display: flex; flex-direction: column; align-items: center; gap: 1.8mm; }
  .head .brand img { height: 16mm; }
  .head .brand .tag {
    font-size: 3mm; font-weight: 700; font-style: italic; color: var(--red);
    letter-spacing: 0.05mm; white-space: nowrap;
  }

  /* ---------- title ---------- */
  .title {
    margin: 8mm 0 0; text-align: center;
    font-family: "Old English Text MT", "CertBlackletter", "UnifrakturMaguntia", serif;
    font-size: 11mm; line-height: 1.15; color: #111; font-weight: 400; white-space: nowrap;
  }

  /* ---------- certifying sentence ---------- */
  .certify { margin: 7mm 0 0; font-size: 4.3mm; line-height: 1.75; }
  .certify .blank {
    display: inline-block; min-width: 14mm; text-align: center;
    border-bottom: 0.4mm solid var(--line); font-weight: 700; padding: 0 1.5mm;
  }
  .certify .blank.yr { min-width: 9mm; }

  /* ---------- the table ---------- */
  .tablewrap { position: relative; margin-top: 7mm; }
  table.cert { width: 100%; border-collapse: collapse; table-layout: fixed; }
  table.cert td { border: 0.35mm solid var(--line); height: 10.6mm; vertical-align: bottom; }
  table.cert td.k {
    width: 36%; padding: 0 3mm 1.6mm; vertical-align: middle;
    font-size: 4.4mm; font-weight: 700; color: var(--lbl);
  }
  /* A long owner name wraps onto a second line rather than being cut off - this is
     a certificate, so a truncated name would be worse than a taller row. */
  table.cert td.v {
    padding: 1mm 3mm 1.4mm 5mm;
    font-size: 4.1mm; line-height: 1.25; font-weight: 700; color: var(--ink);
    overflow-wrap: anywhere;
  }
  /* The stamp goes over the foot of the table, where it is put by hand. */
  /* Fills the empty right-hand part of the last two rows (Subscription and Expiry):
     that space is about 82mm x 26.5mm, so the stamp is as tall as it can be within it
     and centred across it. */
  .stamp { position: absolute; right: 9mm; bottom: 0.8mm; height: 25mm; }

  /* ---------- signing ---------- */
  /* 16mm, not 9: room for the taller signature to rise above its line without
     touching the foot of the table. */
  .sign { margin-top: 16mm; display: flex; align-items: flex-end; gap: 6mm; }
  .sign .cap {
    font-family: "Times New Roman", "Liberation Serif", serif;
    font-style: italic; font-weight: 700; font-size: 4.3mm; white-space: nowrap;
  }
  .sign .onrule {
    flex: 1; position: relative; border-bottom: 0.4mm solid var(--line);
    height: 8mm; text-align: center;
  }
  .sign .onrule span {
    position: absolute; left: 0; right: 0; bottom: 1.2mm;
    font-size: 4.6mm; font-weight: 700; letter-spacing: 0.2mm;
  }
  /* Larger and allowed to rise well above the line, the way a pen signature does.
     13mm made the signature (roughly square) a small mark that was hard to make out. */
  .sign .onrule img {
    position: absolute; left: 50%; transform: translateX(-50%); bottom: -1.5mm; height: 24mm;
    max-width: 100%; object-fit: contain;
  }
  .sign .name { flex: 1.15; }
  .sign .sigcell { flex: 0.95; }

  /* ---------- footer ---------- */
  .foot { margin-top: auto; padding-top: 5mm; padding-bottom: 10mm; }
  .foot .alt {
    display: flex; align-items: center; gap: 4mm; justify-content: center;
  }
  .foot .alt img { height: 9mm; }
  .foot .alt .lines { text-align: center; }
  .foot .alt .no-alt {
    font-family: "Times New Roman", "Liberation Serif", serif;
    font-style: italic; font-weight: 700; font-size: 3.7mm; color: var(--red);
  }
  .foot .alt .emg { font-size: 3.7mm; margin-top: 0.6mm; }
  .foot .adr { margin-top: 3mm; text-align: center; font-size: 3.4mm; line-height: 1.45; }

  /* ---------- watermark ---------- */
  .wm {
    position: absolute; left: 0; right: 0; top: 120mm;
    text-align: center; font-size: 26mm; font-weight: 700; letter-spacing: 4mm;
    color: rgba(195, 18, 26, 0.13);
    transform: rotate(-22deg); z-index: 4; pointer-events: none;
  }

  @media print {
    body { display: block !important; zoom: 1 !important; background: #fff; }
    .screen-only { display: none !important; }
    .sheet, .terms-sheet { margin: 0; box-shadow: none; }
  }

  @media screen {
    body { display: block; padding-bottom: 10mm; }
    .screen-only { max-width: 210mm; margin: 6mm auto; display: flex; justify-content: space-between;
                   align-items: center; gap: 10px; font-family: "Segoe UI", system-ui, sans-serif; }
    .screen-only .bar-btn {
      display: inline-flex; align-items: center; gap: 8px; height: 40px; padding: 0 18px;
      border-radius: 10px; font: 600 14.5px "Segoe UI", system-ui, sans-serif; text-decoration: none;
      cursor: pointer; transition: background-color .15s, border-color .15s, box-shadow .15s;
    }
    .screen-only .bar-btn svg { width: 17px; height: 17px; fill: none; stroke: currentColor; stroke-width: 2;
                                stroke-linecap: round; stroke-linejoin: round; }
    .screen-only .bar-back { background: #fff; color: #0D4F72; border: 1px solid #D9E2EC; }
    .screen-only .bar-back:hover { background: #F2F7FB; border-color: #BFD3E3; }
    .screen-only .bar-print { background: #0D4F72; color: #fff; border: 1px solid #0D4F72;
                              box-shadow: 0 4px 12px -4px rgba(13,79,114,.5); }
    .screen-only .bar-print:hover { background: #0A4260; }
    .screen-only .bar-btn:focus-visible { outline: 3px solid rgba(24,144,208,.4); outline-offset: 2px; }

    /* The back directly under the front, both centred on the same axis.
       The terms page was drifting left because only .sheet had margin auto -
       .terms-sheet was taking the browser's default and sitting hard left. */
    .sheet, .terms-sheet {
      margin: 0 auto 8mm;
      box-shadow: 0 2mm 8mm rgba(13, 79, 114, 0.18);
    }
  }
</style>
</head>
<body>

<?php if (($trackingMode ?? 'screen') === 'screen'): ?>
<div class="screen-only">
  <a class="bar-btn bar-back" href="tracking_view.php?id=<?= (int)($_GET['id'] ?? 0) ?>">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5M11 18l-6-6 6-6"/></svg>Back to the certificate</a>
  <button type="button" class="bar-btn bar-print" onclick="window.print()">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M6 14h12v7H6z"/></svg>Print this certificate</button>
</div>
<?php endif; ?>

<div class="sheet">
  <?php if ($security): ?><img class="security" src="<?= e($security) ?>" alt=""><?php endif; ?>
  <?php
    /* The company-name bands, as real page text rather than text inside the background
       image. Chrome's PDF output drew image text in another font with the letters run
       together, so it printed as an unreadable blur. Positions are the old ones from the
       image: x in its 794-unit width, which spans the sheet plus 6mm bleed each side. */
    $bands = [[36, 'g'], [43, 'g'], [168, 'b'], [175, 'b'], [300, 'g'], [307, 'g'],
              [432, 'b'], [439, 'b'], [564, 'g'], [571, 'g'], [696, 'b'], [703, 'b']];
    $bandText = str_repeat('GAPTECH SOLUTIONS LTD &middot; ', 26);
  ?>
  <div class="bands" aria-hidden="true">
    <?php foreach ($bands as [$x, $tone]): ?>
      <span class="band <?= $tone ?>" style="left: <?= round(-6 + $x * 222 / 794, 2) ?>mm"><?= $bandText ?></span>
    <?php endforeach; ?>
  </div>
  <?php if ($watermark): ?><div class="wm"><?= e($watermark) ?></div><?php endif; ?>
  <?php if ($houseMark): ?><img class="paper-mark" src="<?= e($houseMark) ?>" alt=""><?php endif; ?>
  <div class="inner">

    <div class="serial"><b>Serial No.</b><span class="no"><?= e($d['cert']['number']) ?></span></div>

    <div class="head">
      <div class="licensed">
        <span class="cap">Licensed by:</span>
        <?php if ($caLogo): ?>
          <img src="<?= e($caLogo) ?>" alt="Communications Authority of Kenya">
        <?php else: ?>
          <div class="ca-slot"></div>
        <?php endif; ?>
      </div>
      <?php if ($gapLogo): ?>
        <div class="brand">
          <img src="<?= e($gapLogo) ?>" alt="">
          <?php if (trim((string)$d['company']['tagline']) !== ''): ?>
            <span class="tag"><?= e($d['company']['tagline']) ?></span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <h1 class="title">Certificate of Installation</h1>

    <p class="certify">
      This is to certify that on <span class="blank"><?= e($instDay) ?></span> /
      <span class="blank"><?= e($instMonth) ?></span> / 20<span class="blank yr"><?= e($instYear) ?></span>
      The vehicle whose particulars appear below has been installed with a Tracking system
      and can hence be tracked:
    </p>

    <div class="tablewrap">
      <table class="cert">
        <?php foreach ($rows as [$label, $value]): ?>
          <tr>
            <td class="k"><?= e($label) ?></td>
            <td class="v"><?= e((string)$value) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <?php if ($stampSrc): ?><img class="stamp" src="<?= e($stampSrc) ?>" alt=""><?php endif; ?>
    </div>

    <div class="sign">
      <span class="cap">Signed by Name:</span>
      <span class="onrule name"><span><?= e($d['cert']['signed_by']) ?></span></span>
      <span class="cap">Signature:</span>
      <span class="onrule sigcell">
        <?php if ($sigSrc): ?><img src="<?= e($sigSrc) ?>" alt=""><?php endif; ?>
      </span>
    </div>

    <div class="foot">
      <div class="alt">
        <?php if ($goldMark): ?><img src="<?= e($goldMark) ?>" alt=""><?php endif; ?>
        <div class="lines">
          <div class="no-alt">This certificate has been issued without any alteration.</div>
          <div class="emg">For any emergency call: <?= e($d['company']['emergency']) ?></div>
        </div>
      </div>
      <div class="adr">
        <?= e($d['company']['address']) ?><br>
        E mail: <?= e($d['company']['email']) ?>, Website: <?= e($d['company']['website']) ?>
      </div>
    </div>

  </div><!-- /inner -->
</div>

<?php
  // The back of the certificate: the guarantee terms. Its own file so the
  // wording - which is a warranty GAPTECH is bound by - can be changed without
  // anybody going near the certificate layout.
  require __DIR__ . '/tracking_terms.php';
?>

</body>
</html>