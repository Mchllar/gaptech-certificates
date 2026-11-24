<?php
/**
 * app/views/tracking_align_test.php - the alignment test for pre-printed blanks.
 *
 * Prints, in thin lines, where the system believes the blank's table, date
 * lines and signature lines are - with the printer adjustment from Settings
 * already applied, exactly as a real certificate would be. At two opposite
 * corners of the table a millimetre ruler crosses the line: 0 is where the
 * line should be.
 *
 * Print it onto a spoiled blank (or onto plain paper and hold it against a
 * blank up to the light). Where the blank's OWN line crosses each ruler is the
 * correction, read straight off: +3 on the across ruler means "move right by
 * 3". Both corners reading the same is a plain shift; different readings mean
 * the page was scaled in the print dialog.
 */
require_once __DIR__ . '/preprinted_geometry.php';

$offX = (float)setting('print_offset_x', '0');
$offY = (float)setting('print_offset_y', '0');

/**
 * A ruler with 1mm ticks from -10 to +10, its 0 at ($x, $y).
 * 'h' runs across (reads a vertical line), 'v' runs down (reads a horizontal line).
 */
$ruler = function (float $x, float $y, string $dir): string {
    $out = '';
    for ($i = -10; $i <= 10; $i++) {
        $len = $i === 0 ? 7 : ($i % 5 === 0 ? 5 : 3);
        if ($dir === 'h') {
            $out .= '<i class="tick" style="left:' . pp_mm($x + $i - 0.1) . ';top:' . pp_mm($y - $len / 2)
                  . ';width:0.2mm;height:' . pp_mm($len) . '"></i>';
            if ($i % 5 === 0) {
                $out .= '<b class="num" style="left:' . pp_mm($x + $i - 3) . ';top:' . pp_mm($y + 3.8)
                      . ';width:6mm">' . ($i > 0 ? '+' : '') . $i . '</b>';
            }
        } else {
            $out .= '<i class="tick" style="top:' . pp_mm($y + $i - 0.1) . ';left:' . pp_mm($x - $len / 2)
                  . ';height:0.2mm;width:' . pp_mm($len) . '"></i>';
            if ($i % 5 === 0) {
                $out .= '<b class="num" style="top:' . pp_mm($y + $i - 1.5) . ';left:' . pp_mm($x + 3.8)
                      . ';width:6mm;text-align:left">' . ($i > 0 ? '+' : '') . $i . '</b>';
            }
        }
    }
    // the spine
    $out .= $dir === 'h'
        ? '<i class="tick" style="left:' . pp_mm($x - 10) . ';top:' . pp_mm($y - 0.1) . ';width:20mm;height:0.2mm"></i>'
        : '<i class="tick" style="top:' . pp_mm($y - 10) . ';left:' . pp_mm($x - 0.1) . ';height:20mm;width:0.2mm"></i>';
    return $out;
};

$line = fn(float $x1, float $y1, float $x2, float $y2): string =>
    '<i class="ln" style="left:' . pp_mm($x1) . ';top:' . pp_mm($y1 - 0.1) . ';width:' . pp_mm(max($x2 - $x1, 0.2))
    . ';height:' . pp_mm(max($y2 - $y1, 0.2)) . '"></i>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Alignment test - pre-printed certificate</title>
<style>
  @page { size: A4 portrait; margin: 0; }
  html, body { margin: 0; padding: 0; }
  body { font-family: "Calibri", "Segoe UI", sans-serif; background: #E9EDF1; }
  .sheet { position: relative; width: 210mm; height: 297mm; margin: 0 auto; overflow: hidden; background: #fff; }
  .layer { position: absolute; inset: 0; }
  .ln   { position: absolute; display: block; background: #D0021B; }
  .tick { position: absolute; display: block; background: #000; }
  .num  { position: absolute; display: block; font-size: 7pt; text-align: center; line-height: 1; }
  .lbl  { position: absolute; font-size: 8pt; font-weight: 700; color: #000; line-height: 1.2; }
  .blank { position: absolute; inset: 0; width: 210mm; height: 297mm; opacity: .55; }

  @media print {
    .screen-only, .blank { display: none !important; }
    body { background: #fff; }
    .layer { transform: <?= pp_offset_css() ?>; }
  }
  @media screen {
    body { padding-bottom: 10mm; }
    .sheet { box-shadow: 0 2mm 8mm rgba(13, 79, 114, 0.18); }
    .screen-only { max-width: 210mm; margin: 6mm auto; font: 14px/1.55 "Segoe UI", system-ui, sans-serif; color: #33414F; }
    .screen-only h1 { font-size: 20px; color: #0D4F72; margin: 0 0 6px; }
    .screen-only ol { margin: 6px 0 10px 18px; padding: 0; }
    .bar-btn { display: inline-flex; align-items: center; height: 40px; padding: 0 18px; border-radius: 10px;
               font: 600 14.5px "Segoe UI", system-ui, sans-serif; cursor: pointer; text-decoration: none;
               background: #0D4F72; color: #fff; border: 1px solid #0D4F72; }
    .bar-back { background: #fff; color: #0D4F72; border-color: #D9E2EC; margin-right: 8px; }
  }
</style>
</head>
<body>

<div class="screen-only">
  <h1>Alignment test for pre-printed certificates</h1>
  <ol>
    <li>Print this page onto a <b>spoiled or used blank</b> &mdash; or onto plain paper, then hold it
        against a blank up to the light. Print at <b>A4, 100% / Actual size</b>, headers and footers off,
        exactly as you will print certificates.</li>
    <li>Red lines show where the system thinks the blank's lines are. Two rulers cross the table's
        <b>top-left</b> and <b>bottom-right</b> corners; 0 is where the line should be.</li>
    <li>On each <b>across</b> ruler, read the number where the certificate's own <b>upright</b> border
        crosses it &mdash; that is <b>Move right by</b>. On each <b>down</b> ruler, read where its
        <b>level</b> line crosses &mdash; that is <b>Move down by</b>. Minus means left / up.</li>
    <li><b>Add</b> the readings to what Settings already has
        (now <?= e((string)$offX) ?> right, <?= e((string)$offY) ?> down), save, and print this again &mdash;
        everything should read 0.</li>
  </ol>
  <p>If the two corners give <b>different</b> readings, the page is being shrunk or enlarged: check the
     scale is 100% / Actual size and the paper is A4, rather than changing the settings.</p>
  <p><a class="bar-btn bar-back" href="settings.php">Settings</a>
     <button type="button" class="bar-btn" onclick="window.print()">Print the test</button></p>
</div>

<div class="sheet">
  <?php if (is_file(ROOT_DIR . '/public/assets/tracking_blank.jpg')): ?>
    <img class="blank" src="assets/tracking_blank.jpg" alt="">
  <?php endif; ?>
  <div class="layer">
    <?php
      // the table
      foreach (PP_ROWS as $y) { echo $line(PP_TABLE_LEFT, $y, PP_VALUE_RIGHT, $y); }
      foreach ([PP_TABLE_LEFT, PP_VALUE_LEFT, PP_VALUE_RIGHT] as $x) {
          echo $line($x, PP_ROWS[0], $x + 0.2, PP_ROWS[10]);
      }
      // the date underlines and the signature lines
      foreach ([PP_DATE_DAY, PP_DATE_MON, PP_DATE_YR] as [$x1, $x2]) { echo $line($x1, PP_DATE_LINE, $x2, PP_DATE_LINE); }
      foreach ([PP_NAME, PP_SIG] as [$x1, $x2]) { echo $line($x1, PP_SIGN_LINE, $x2, PP_SIGN_LINE); }

      // rulers: across ones read the upright borders, down ones read the rules
      echo $ruler(PP_TABLE_LEFT, PP_ROWS[0] + 6, 'h');      // top-left, across
      echo $ruler(PP_TABLE_LEFT + 30, PP_ROWS[0], 'v');     // top-left, down
      echo $ruler(PP_VALUE_RIGHT, PP_ROWS[10] - 6, 'h');    // bottom-right, across
      echo $ruler(PP_VALUE_RIGHT - 40, PP_ROWS[10], 'v');   // bottom-right, down
    ?>
    <span class="lbl" style="left:<?= pp_mm(PP_TABLE_LEFT + 2) ?>; top:<?= pp_mm(PP_ROWS[0] + 12) ?>">ACROSS &rarr; Move right by</span>
    <span class="lbl" style="left:<?= pp_mm(PP_TABLE_LEFT + 36) ?>; top:<?= pp_mm(PP_ROWS[0] - 9) ?>">DOWN &darr; Move down by</span>
    <span class="lbl" style="left:<?= pp_mm(PP_VALUE_RIGHT - 52) ?>; top:<?= pp_mm(PP_ROWS[10] - 13) ?>">ACROSS &rarr; Move right by</span>
    <span class="lbl" style="left:<?= pp_mm(PP_VALUE_RIGHT - 34) ?>; top:<?= pp_mm(PP_ROWS[10] + 6) ?>">DOWN &darr; Move down by</span>
    <span class="lbl" style="left:18mm; top:6mm; font-weight:400">
      Alignment test &middot; adjustment now <?= e((string)$offX) ?> mm right, <?= e((string)$offY) ?> mm down
      &middot; printed <?= e(date('d/m/Y H:i')) ?></span>
  </div>
</div>
</body>
</html>
