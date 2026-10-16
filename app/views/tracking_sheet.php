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
 * NOTE on the Communications Authority mark: this file prints assets/ca_logo.png if
 * that file exists and leaves the space empty if it does not. The mark belongs to the
 * Authority, so the official artwork has to be supplied rather than redrawn.
 */

$assetDir = ROOT_DIR . '/public/assets';
$have = fn(string $f): bool => $f !== '' && is_file($assetDir . '/' . basename($f));

$caLogo   = $have('ca_logo.png')      ? $assets . '/ca_logo.png'      : '';
$gapLogo  = $have('gap_logo.png')     ? $assets . '/gap_logo.png'     : '';
$goldMark = $have('gap_logo_gold.png') ? $assets . '/gap_logo_gold.png' : '';

/** The signature and stamp are stored as a setting holding a path under assets/. */
$sigFile   = (string)($d['company']['signature'] ?? setting('signature_image', ''));
$stampFile = (string)($d['company']['stamp'] ?? setting('stamp_image', ''));
$sigSrc    = $have($sigFile)   ? $assets . '/' . basename($sigFile)   : '';
$stampSrc  = $have($stampFile) ? $assets . '/' . basename($stampFile) : '';

$status    = $d['cert']['status'] ?? 'Valid';
$watermark = in_array($status, ['Voided', 'Superseded', 'Expired', 'Pending approval', 'Rejected'], true)
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

$rows = [
    ['Vehicle Reg No.',     $d['vehicle']['reg_no']],
    ['Vehicle Make/Model',  $d['vehicle']['make_model']],
    ['Chassis No.',         $d['vehicle']['chassis_no']],
    ['Engine No.',          $d['vehicle']['engine_no']],
    ['Colour',              $d['vehicle']['colour']],
    ['Ownership Details',   $d['vehicle']['ownership']],
    ['FINANCIER',           $d['vehicle']['financier'] !== '' ? $d['vehicle']['financier'] : 'N/A'],
    ['Track IMEI No.',      $d['device']['imei']],
    ['Subscription Date',   $shortDate($d['cert']['subscription_date'] ?? '')],
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

  /* The faint house mark the printed stationery carries behind the table. */
  .paper-mark {
    position: absolute; left: 50%; top: 115mm; transform: translateX(-50%);
    width: 80mm; opacity: 0.07; z-index: 1; pointer-events: none;
  }

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
  .stamp { position: absolute; right: 2mm; bottom: 1.5mm; height: 17mm; }

  /* ---------- signing ---------- */
  .sign { margin-top: 9mm; display: flex; align-items: flex-end; gap: 6mm; }
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
  .sign .onrule img {
    position: absolute; left: 50%; transform: translateX(-50%); bottom: 0.5mm; height: 13mm;
  }
  .sign .name { flex: 1.15; }
  .sign .sigcell { flex: 0.95; }

  /* ---------- footer ---------- */
  .foot { margin-top: auto; padding-bottom: 10mm; }
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

  /* On screen only: a grey surround and a print button. Neither is printed. */
  .screen-only { max-width: 210mm; margin: 6mm auto; text-align: right; }
  .screen-only button, .screen-only a {
    font: inherit; font-size: 14px; padding: 8px 16px; border-radius: 6px; cursor: pointer;
    border: 1px solid var(--navy); background: var(--navy); color: #fff; text-decoration: none;
  }
  .screen-only a.secondary { background: #fff; color: var(--navy); }
  @media print {
    body { background: #fff; }
    .screen-only { display: none !important; }
    .sheet { margin: 0; box-shadow: none; }
  }
  @media screen {
    .sheet { box-shadow: 0 2mm 8mm rgba(13, 79, 114, 0.18); }
  }
</style>
</head>
<body>

<?php if (($trackingMode ?? 'screen') === 'screen'): ?>
<div class="screen-only">
  <a class="secondary" href="tracking_view.php?id=<?= (int)($_GET['id'] ?? 0) ?>">Back</a>
  <button onclick="window.print()">Print this certificate</button>
</div>
<?php endif; ?>

<div class="sheet">
  <?php if ($watermark): ?><div class="wm"><?= e($watermark) ?></div><?php endif; ?>
  <?php if ($goldMark): ?><img class="paper-mark" src="<?= e($goldMark) ?>" alt=""><?php endif; ?>

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

</body>
</html>
