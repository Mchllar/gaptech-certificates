<?php
/**
 * app/views/tracking_sheet_preprinted.php
 *
 * The tracking certificate for the PRE-PRINTED blanks in store: the entries and
 * nothing else. The blank already carries the logos, title, table, labels and
 * footer, so printing any of that again would double it up.
 *
 * Positions come from preprinted_geometry.php (measured off a scan of the
 * blank) and are fixed, not flowed, so a long name can never push the rows
 * below it out of their boxes. Settings holds what differs from office to
 * office: the size of the lettering, whether the stamp is printed, and the
 * printer's own drift.
 *
 * On screen the scan of the blank sits behind the entries, so whoever is
 * printing can see where everything will land before a blank is used. It is
 * never printed.
 *
 * Expects what render_tracking_sheet() sets: $d, $assets, and
 * $GLOBALS['tracking_serial_on_blank'] - the blank's own pre-printed number, or
 * '' when its Serial No. space is empty and the system's number goes there.
 * $GLOBALS['tracking_print_stamp'] (bool) says whether the stamp starts ticked.
 *
 * @var array  $d
 * @var string $assets
 */
require_once __DIR__ . '/preprinted_geometry.php';

$shortDate = function ($iso) {
    $t = strtotime((string)$iso);
    return $t ? date('d/m/Y', $t) : '';
};

$inst = strtotime((string)($d['cert']['installed_on'] ?? '')) ?: time();

$imeis = array_values(array_filter(array_map('trim',
    (array)($d['device']['imeis'] ?? [])), fn($x) => $x !== ''));
$imeiLine = $imeis ? implode(' / ', $imeis) : (string)($d['device']['imei'] ?? '');

// In the blank's row order: Reg, Make/Model, Chassis, Engine, Colour,
// Ownership, Financier, IMEI, Subscription, Expiry.
$values = [
    $d['vehicle']['reg_no'] ?? '',
    $d['vehicle']['make_model'] ?? '',
    $d['vehicle']['chassis_no'] ?? '',
    $d['vehicle']['engine_no'] ?? '',
    $d['vehicle']['colour'] ?? '',
    $d['vehicle']['ownership'] ?? '',
    ($d['vehicle']['financier'] ?? '') !== '' ? $d['vehicle']['financier'] : 'N/A',
    $imeiLine,
    $shortDate($d['cert']['subscription_date'] ?? ''),
    $shortDate($d['cert']['expiry_date'] ?? ''),
];

$number    = (string)($d['cert']['number'] ?? '');
$ownSerial = trim((string)($GLOBALS['tracking_serial_on_blank'] ?? ''));
$withStamp = (bool)($GLOBALS['tracking_print_stamp'] ?? true);
$withSig   = (bool)($GLOBALS['tracking_print_signature'] ?? true);

// Signature and stamp, the same images the full certificate uses.
$assetDir = ROOT_DIR . '/public/assets';
$imgSrc = function (string $f) use ($assetDir, $assets): string {
    if (preg_match('~^data:image/(png|jpe?g|gif|webp);base64,~i', $f)) { return $f; }
    return ($f !== '' && is_file($assetDir . '/' . basename($f))) ? $assets . '/' . basename($f) : '';
};
$sigSrc   = $imgSrc((string)($d['company']['signature'] ?? setting('signature_image', '')));
$stampSrc = $imgSrc((string)($d['company']['stamp'] ?? setting('tracking_stamp_image', '')));

$blank = is_file($assetDir . '/tracking_blank.jpg')
    ? $assets . '/tracking_blank.jpg?v=' . filemtime($assetDir . '/tracking_blank.jpg') : '';

$offX = (float)setting('print_offset_x', '0');
$offY = (float)setting('print_offset_y', '0');

// The size of the lettering, in points, as Word counts them - so "11" here
// matches 11 in the Word template the office used before.
$pt = (float)setting('preprinted_font_pt', '13');
$pt = $pt >= 7 && $pt <= 18 ? $pt : 13;

/** A long entry steps down a size or two rather than spilling out of its box. */
$fit = function (string $v) use ($pt): string {
    $n = mb_strlen($v);
    $size = $n > 70 ? $pt * 0.72 : ($n > 42 ? $pt * 0.85 : $pt);
    return round($size, 2) . 'pt';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Pre-printed certificate <?= e($number) ?> &middot; <?= e((string)($d['vehicle']['reg_no'] ?? '')) ?></title>
<style>
  @page { size: A4 portrait; margin: 0; }
  html, body { margin: 0; padding: 0; }
  body { font-family: Arial, "Liberation Sans", Helvetica, sans-serif; color: #000; background: #E9EDF1; }

  .sheet { position: relative; width: 210mm; height: 297mm; margin: 0 auto; overflow: hidden; background: #fff; }
  .blank { position: absolute; inset: 0; width: 210mm; height: 297mm; z-index: 0; }
  .entries { position: absolute; inset: 0; z-index: 1; }

  .at { position: absolute; white-space: nowrap; font-weight: 700; line-height: 1; -webkit-text-stroke: 0.2px #000; }

  /* Sit on a ruled line: the box ends on the rule, the text sits just above it. */
  .on-line { display: flex; align-items: flex-end; justify-content: center; height: 8mm; padding-bottom: 0.9mm; box-sizing: border-box; }

  /* Bottom-aligned, so a one-line entry sits on the same baseline as the label
     printed beside it on the blank (about 2.8mm above the row's lower rule). A
     long entry that wraps grows upwards and stays inside the box. */
  .cell {
    position: absolute; display: flex; align-items: flex-end;
    padding: 0 3mm 1.2mm 9mm; box-sizing: border-box;
    font-weight: 700; line-height: 1.15; overflow-wrap: anywhere; overflow: hidden;
    -webkit-text-stroke: 0.2px #000;   /* a touch heavier than plain bold, like the Word print */
  }

  .serial { font-family: "Consolas", "DejaVu Sans Mono", monospace; font-size: <?= round($pt * 1.3, 1) ?>pt; letter-spacing: 0.8mm; }
  .date   { font-size: <?= $pt ?>pt; padding-bottom: 0; }   /* digits sit right on the underline */
  .name   { font-size: <?= $pt ?>pt; letter-spacing: 0.15mm; }
  .sig img   { position: absolute; left: 50%; transform: translateX(-50%); bottom: -9mm; height: 29mm; max-width: 60mm; object-fit: contain; }
  .stamp  { position: absolute; height: 24mm; }
  body.no-stamp .stamp { display: none; }
  body.no-sig .sig img { display: none; }

  @media print {
    body { background: #fff; }
    .screen-only, .blank { display: none !important; }
    .sheet { margin: 0; }
    /* The printer's own drift, measured once with the alignment test and saved in
       Settings. Only on paper: on screen the entries must line up with the scan. */
    .entries { transform: <?= pp_offset_css() ?>; }
  }

  @media screen {
    body { padding-bottom: 10mm; }
    .sheet { box-shadow: 0 2mm 8mm rgba(13, 79, 114, 0.18); }
    body.hide-blank .blank { display: none; }
    .screen-only { max-width: 210mm; margin: 6mm auto; font-family: "Segoe UI", system-ui, sans-serif; }
    .bar { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
    .bar-btn {
      display: inline-flex; align-items: center; gap: 8px; height: 40px; padding: 0 18px;
      border-radius: 10px; font: 600 14.5px "Segoe UI", system-ui, sans-serif; text-decoration: none; cursor: pointer;
    }
    .bar-back  { background: #fff; color: #0D4F72; border: 1px solid #D9E2EC; }
    .bar-print { background: #0D4F72; color: #fff; border: 1px solid #0D4F72; }
    .bar-opts { display: flex; gap: 16px; flex-wrap: wrap; }
    .bar label { font-size: 14px; color: #33414F; display: inline-flex; gap: 6px; align-items: center; cursor: pointer; }
    .note {
      margin-top: 10px; padding: 10px 14px; border-radius: 10px; font-size: 13.5px; line-height: 1.5;
      background: #FFF8E6; border: 1px solid #F0D9A0; color: #5A4500;
    }
  }
</style>
</head>
<body class="<?= trim(($withStamp ? '' : 'no-stamp') . ' ' . ($withSig ? '' : 'no-sig')) ?>">

<div class="screen-only">
  <div class="bar">
    <a class="bar-btn bar-back" href="tracking_view.php?id=<?= (int)($_GET['id'] ?? 0) ?>">&larr; Back to the certificate</a>
    <span class="bar-opts">
      <?php if ($stampSrc): ?>
        <label><input type="checkbox" id="opt-stamp" <?= $withStamp ? 'checked' : '' ?>> Print the stamp</label>
      <?php endif; ?>
      <?php if ($sigSrc): ?>
        <label><input type="checkbox" id="opt-sig" <?= $withSig ? 'checked' : '' ?>> Print the signature</label>
      <?php endif; ?>
      <label><input type="checkbox" id="opt-blank" checked> Show the blank behind (screen only)</label>
    </span>
    <button type="button" class="bar-btn bar-print" onclick="window.print()">Print onto the blank</button>
  </div>
  <div class="note">
    <b>Before printing:</b> load ONE blank certificate, printed side to be printed on.
    In the print dialog choose <b>A4</b>, scale <b>100% / Actual size</b> (not "Fit to page"),
    margins <b>None</b> or <b>Default</b>, and turn <b>Headers and footers</b> off.
    Only the entries print &mdash; the blank shown here is a guide.
    <?php if ($offX || $offY): ?>
      This printer's adjustment (<?= e((string)$offX) ?> mm across, <?= e((string)$offY) ?> mm down) is applied.
    <?php else: ?>
      Not landing on the boxes? Print the <a href="tracking_print.php?align=1" target="_blank">alignment test</a> once.
    <?php endif; ?>
  </div>
</div>

<div class="sheet">
  <?php if ($blank): ?><img class="blank" src="<?= e($blank) ?>" alt=""><?php endif; ?>

  <div class="entries">

    <?php if ($ownSerial === ''): ?>
      <?php /* The blank's Serial No. space is empty: the system's number goes in it,
               so the paper and the register carry the same number. */ ?>
      <div class="at serial" style="left:<?= pp_mm(PP_SERIAL_X) ?>; top:<?= pp_mm(PP_SERIAL_Y - 4.6) ?>"><?= e($number) ?></div>
    <?php endif; ?>
    <?php /* When the blank has its own number, nothing is printed near it: that
             number is recorded against the certificate, and the register finds
             the certificate by it. */ ?>

    <?php foreach ([[PP_DATE_DAY, date('d', $inst)], [PP_DATE_MON, date('m', $inst)],
                    [PP_DATE_YR, date('y', $inst)]] as [[$x1, $x2], $v]): ?>
      <div class="at on-line date" style="left:<?= pp_mm($x1) ?>; width:<?= pp_mm($x2 - $x1) ?>;
           top:<?= pp_mm(PP_DATE_LINE - 8 + 1) ?>"><?= e($v) ?></div>
    <?php endforeach; ?>

    <?php foreach ($values as $i => $v): $v = (string)$v; ?>
      <div class="cell" style="left:<?= pp_mm(PP_VALUE_LEFT) ?>; width:<?= pp_mm(PP_VALUE_RIGHT - PP_VALUE_LEFT) ?>;
           top:<?= pp_mm(PP_ROWS[$i] + 0.4) ?>; height:<?= pp_mm(PP_ROWS[$i + 1] - PP_ROWS[$i] - 0.8) ?>;
           font-size:<?= $fit($v) ?>"><?= e($v) ?></div>
    <?php endforeach; ?>

    <?php if ($stampSrc): ?>
      <img class="stamp" src="<?= e($stampSrc) ?>" alt=""
           style="right:<?= pp_mm(210 - PP_VALUE_RIGHT + 9) ?>; top:<?= pp_mm(PP_ROWS[10] - 0.8 - 24) ?>">
    <?php endif; ?>

    <div class="at on-line name" style="left:<?= pp_mm(PP_NAME[0]) ?>; width:<?= pp_mm(PP_NAME[1] - PP_NAME[0]) ?>;
         top:<?= pp_mm(PP_SIGN_LINE - 8) ?>"><?= e((string)($d['cert']['signed_by'] ?? '')) ?></div>

    <div class="at sig" style="left:<?= pp_mm(PP_SIG[0]) ?>; width:<?= pp_mm(PP_SIG[1] - PP_SIG[0]) ?>;
         top:<?= pp_mm(PP_SIGN_LINE - 8) ?>; height:8mm">
      <?php if ($sigSrc): ?><img src="<?= e($sigSrc) ?>" alt=""><?php endif; ?>
    </div>

  </div>
</div>

<script>
  (function () {
    function wire(id, fn) { var b = document.getElementById(id); if (b) { b.addEventListener('change', function () { fn(b.checked); }); } }
    wire('opt-blank', function (on) { document.body.classList.toggle('hide-blank', !on); });
    wire('opt-stamp', function (on) { document.body.classList.toggle('no-stamp', !on); });
    wire('opt-sig',   function (on) { document.body.classList.toggle('no-sig', !on); });
  })();
</script>
</body>
</html>
