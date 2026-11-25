<?php 
/**
 * Set by render_sheet() in app/certificates.php, which declares them global and
 * then includes this file. Declared here too so the contract is visible and the
 * editor stops reporting them as undefined.
 */
global $d, $assets, $zoom, $qr, $certno_img, $logo, $logo_gold, $kebs, $sticker_bg, $microtext;

require_once __DIR__ . '/sheet_helpers.php';
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Certificate <?= e($d['cert']['number']) ?> - <?= e($d['vehicle']['reg_no']) ?></title>
<style>
  /* All coordinates are in "sheet units": the original A4 landscape sheet scanned at 200 dpi
     (2338 x 1653). The body zoom converts them to the PDF page. */
  @font-face { font-family: "CertBlackletter"; src: url("<?= $assets ?>/Blackletter-Fraktur-Bold.ttf"); }
  html, body { margin: 0; padding: 0; }
  body { zoom: <?= $zoom ?>; }
  .sheet { position: relative; width: 2338px; height: 1645px; overflow: hidden; background: #fff; color: #222; }
  .t { position: absolute; white-space: nowrap; line-height: 1; }
  .ln { position: absolute; height: 0; border-top: 2.5px solid #333; }
  .c   { font-family: "Calibri", "Carlito", sans-serif; }
  .cb  { font-family: "Calibri", "Carlito", sans-serif; font-weight: bold; }
  .cbi { font-family: "Calibri", "Carlito", sans-serif; font-weight: bold; font-style: italic; }
  .a   { font-family: "Arial", "Liberation Sans", sans-serif; }
  .ab  { font-family: "Arial", "Liberation Sans", sans-serif; font-weight: bold; }
  .abi { font-family: "Arial", "Liberation Sans", sans-serif; font-weight: bold; font-style: italic; }
  .ai  { font-family: "Arial", "Liberation Sans", sans-serif; font-style: italic; }
  .tbi { font-family: "Times New Roman", "Liberation Serif", serif; font-weight: bold; font-style: italic; }
  .red { color: #E8323C; }
  .blue { color: #2B62B0; }
  .fr  { font-family: "Old English Text MT", "CertBlackletter", serif; color: #F26B6B;
         -webkit-text-stroke: 1.6px #C62830; }
  /* The company-name bands. #EFEFEF was all but white on white and could not be read;
     this blue-grey reads clearly yet stays well behind the printed details. */
  .micro { position: absolute; overflow: hidden; color: #C3D0DC; font-family: "Arial", "Liberation Sans", sans-serif;
           font-weight: bold; font-size: 17px; line-height: 22px; white-space: nowrap; letter-spacing: 1px; }
  .cut-v { position: absolute; width: 0; border-left: 2px dashed #B9B9B9; }
  .cut-h { position: absolute; height: 0; border-top: 2px dashed #B9B9B9; }
  .box { position: absolute; border: 3px solid #E8584A; }
  .chk { display: inline-block; width: 26px; height: 26px; border: 2.5px solid #333; vertical-align: -3px;
         margin: 0 10px 0 5px; position: relative; }
  .tick { position: absolute; left: 1px; top: -10px; font-family: "DejaVu Sans", sans-serif; font-size: 34px; color: #111; }
  .sigph { position: absolute; border: 2px dashed #B5B5B5; color: #9A9A9A; text-align: center;
           font-family: "Carlito", sans-serif; font-style: italic; font-size: 17px; background: rgba(255,255,255,0.55); }
  .img { position: absolute; }
  .emb { text-shadow: 1px 1px 0 rgba(255,255,255,0.7), -1px -1px 0 rgba(0,0,0,0.18); }
  .rot { -webkit-transform: rotate(-90deg); -webkit-transform-origin: left top; }
  .dup { -webkit-transform: rotate(-56deg); -webkit-transform-origin: left top; color: #DADADA; letter-spacing: 5px; }

  /* ---- signature on/off (screen prints only; a PDF always has it) ---- */
  body.no-sig .sig-img { display: none !important; }
  /* Bolder signature. Only a transparent PNG: the ink is taken to solid black and
     shadowed onto itself a hair each way, which thickens every stroke. (A JPEG has a
     white background that would go black with it, so it is left as it is.) */
  img.sig-img[src^="data:image/png"], img.sig-img[src$=".png"] {
    filter: brightness(0) drop-shadow(0.9px 0 0 #000) drop-shadow(-0.9px 0 0 #000)
            drop-shadow(0 0.9px 0 #000) drop-shadow(0 -0.9px 0 #000); }

  /* ---- onto the PRE-PRINTED stationery ----------------------------------
     The blank already carries everything that never changes, so only the
     entries print: the helpers tag them "entry" (values, Cert No., signatures)
     and everything else is hidden - hidden, not removed, so not one entry
     moves from the position measured off the stationery. The dealer number is
     printed on the blank too, so it is marked not-entry. */
  .sheet.pp * { visibility: hidden; }
  .sheet.pp .entry, .sheet.pp .entry *, .sheet.pp .tick { visibility: visible; }
  .sheet.pp .not-entry, .sheet.pp .not-entry * { visibility: hidden; }
  .sheet.pp.own-no .certno { visibility: hidden; }     /* the blank has its own Cert No. */
  .pp-ruler, .pp-ruler * { visibility: visible !important; }
  .sheet.pp.align .ln, .sheet.pp.align .box { visibility: visible; border-color: #D0021B; }
  .sheet.pp.align .entry, .sheet.pp.align .tick { visibility: hidden; }

  @media screen {
    /* The design, faded, behind the entries - a guide that never prints. */
    .sheet.pp.ghost * { visibility: visible; }
    .sheet.pp.ghost > *:not(.entry):not(.pp-ruler) { opacity: .25; }
    .sheet.pp.ghost.own-no .certno { opacity: .25; }
    .sheet.pp.align.ghost .entry { visibility: hidden; }
  }
  @media print {
    /* The printer's own drift from Settings, in sheet units (7.872 per mm). */
    .sheet.pp { transform: translate(<?= round((float)setting('governor_offset_x', '0') * 2338 / 297, 2) ?>px,
                                     <?= round((float)setting('governor_offset_y', '0') * 2338 / 297, 2) ?>px); }
    .screen-only { display: none !important; }
  }
</style>
</head>
<?php
  // Set by certificate_print.php for this one print; render_sheet()'s other
  // callers (the PDF) leave them unset and get the full design, signed.
  $ppMode   = !empty($GLOBALS['sheet_preprinted']);
  $ppAlign  = !empty($GLOBALS['sheet_align']);
  $ppOwnNo  = trim((string)($GLOBALS['sheet_own_number'] ?? '')) !== '';
  $onScreen = strpos((string)$assets, 'file://') !== 0;
  $withSig  = !$onScreen || ($GLOBALS['sheet_print_signature'] ?? true);
  $sheetCls = trim('sheet' . ($ppMode ? ' pp ghost' : '') . ($ppAlign ? ' align' : '')
                   . ($ppOwnNo ? ' own-no' : ''));
?>
<body class="<?= $withSig ? '' : 'no-sig' ?>">
<?php if ($onScreen && empty($GLOBALS['sheet_no_bar'])): ?>
<?php /* The body is zoomed down to fit the sheet on the page; zooming this bar
         back up keeps it at its normal size. */ ?>
<div class="screen-only" style="zoom:<?= round(1 / $zoom, 4) ?>; max-width:1123px; margin:14px 0;
     display:flex; gap:16px; align-items:center; flex-wrap:wrap; font:14px 'Segoe UI', system-ui, sans-serif; color:#33414F">
  <?php if (!$ppAlign): ?>
    <label style="display:inline-flex; gap:6px; align-items:center; cursor:pointer">
      <input type="checkbox" <?= $withSig ? 'checked' : '' ?>
             onchange="document.body.classList.toggle('no-sig', !this.checked)"> Print the signature</label>
  <?php endif; ?>
  <?php if ($ppMode): ?>
    <label style="display:inline-flex; gap:6px; align-items:center; cursor:pointer">
      <input type="checkbox" checked
             onchange="document.querySelector('.sheet').classList.toggle('ghost', this.checked)">
      Show the design behind (screen only)</label>
    <span style="flex-basis:100%; padding:9px 13px; border-radius:9px; background:#FFF8E6; border:1px solid #F0D9A0; color:#5A4500">
      <?php if ($ppAlign): ?>
        <b>Alignment test:</b> print onto a spoiled blank at <b>A4 landscape, 100% / Actual size</b>.
        Red lines show where the system thinks the blank's lines are. Where the blank's own line crosses each
        ruler is the correction in mm: <b>across</b> rulers &rarr; "Move right by", <b>down</b> rulers &rarr;
        "Move down by". Add it to Settings (now <?= e(setting('governor_offset_x', '0')) ?> right,
        <?= e(setting('governor_offset_y', '0')) ?> down). Different readings at the two ends mean the page is
        being scaled &mdash; fix the print dialog, not the settings.
      <?php else: ?>
        <b>Before printing:</b> load ONE blank certificate sheet. Choose <b>A4 landscape</b>, scale
        <b>100% / Actual size</b>, headers and footers off. Only the entries print &mdash; the faded design
        is a guide. Off the lines? Print the <a href="certificate_print.php?align=1" target="_blank">alignment test</a> once.
      <?php endif; ?>
    </span>
  <?php endif; ?>
  <button type="button" onclick="window.print()" style="height:38px; padding:0 18px; border-radius:9px; border:0;
          background:#0D4F72; color:#fff; font:600 14px 'Segoe UI', system-ui, sans-serif; cursor:pointer">
    <?= $ppAlign ? 'Print the test' : ($ppMode ? 'Print onto the blank' : 'Print') ?></button>
</div>
<?php endif; ?>
 
 
 
 
 
 
 
 
 
 
 
 
<div class="<?= $sheetCls ?>">
 
  <!-- security microtext bands (printed background) -->
  <div class="micro" style="left:0; top:122px; width:1160px; height:66px;"><?= $microtext ?><br><?= $microtext ?><br><?= $microtext ?></div>
  <div class="micro" style="left:150px; top:1398px; width:1010px; height:66px;"><?= $microtext ?><br><?= $microtext ?><br><?= $microtext ?></div>
  <div class="micro" style="left:1880px; top:150px; width:458px; height:66px;"><?= $microtext ?><br><?= $microtext ?><br><?= $microtext ?></div>
 
  <!-- cut lines -->
  <div class="cut-v" style="left:1165px; top:0; height:1645px;"></div>
  <div class="cut-h" style="left:1166px; top:842px; width:1172px;"></div>
  <div class="cut-v" style="left:1550px; top:843px; height:802px;"></div>
 
  <!-- ================= COMPLIANCE CERTIFICATE (left half) ================= -->
  <?= logo_lockup(52, 20, 194, 168, 101, 21.6, 130, 19.4) ?>
  <?= address(652, 1140, 36, 24.6, 29.6) ?>
  <div class="t c" style="left:48px; top:<?= 212 - 14.4 ?>px; font-size:27.7px;"><span class="cb">REG NO:</span> <?= e($d['company']['reg_no']) ?></div>
 
  <?= tc(180, 985, 268, 54.3, 'c', 'Speed Governor & Recorder', 'color:#333; text-shadow:0.5px 0 0 #333, -0.5px 0 0 #333;') ?>
  <?= tc(180, 985, 330, 60, 'fr', 'Compliance Certificate') ?>
  <?php /* Blank when there is no verify address to point at: an <img> with an
           empty src prints a broken-image icon on the certificate. */ ?>
  <?php if ($qr !== ''): ?>
  <img class="img" src="<?= $qr ?>" style="left:975px; top:240px; width:84px; height:84px;">
  <?php endif; ?>
 
  <?= t(40, 385, 33, 'cb', 'THIS IS TO CERTIFY THAT') ?>
  <?= t(780, 396, 32, 'cb', 'Cert No.') ?>
  <?= certno(903, 398, 26) ?>
 
  <?= t(40, 452, 33, 'c', 'Customer Name:') ?>
  <?= ln(268, 1068, 470) ?><?= val(268, 1068, 470, 33, 'ab', 'AB', $d['client']['name']) ?>
 
  <?= t(40, 522, 33, 'c', 'Contact:') ?>
  <?= ln(158, 578, 540) ?><?= val(158, 578, 540, 33, 'ab', 'AB', $d['client']['phone']) ?>
  <?= t(583, 526, 33, 'c', 'Address:') ?>
  <?= ln(705, 1068, 544) ?><?= val(705, 1068, 544, 33, 'ab', 'AB', $d['client']['address']) ?>
 
  <?= t(40, 595, 33, 'c', 'This is to certify that vehicle Reg No.') ?>
  <?= ln(530, 1068, 612) ?><?= val(530, 1068, 612, 33, 'ab', 'AB', $d['vehicle']['reg_no']) ?>
 
  <?= t(40, 662, 33, 'c', 'Make') ?>
  <?= ln(118, 525, 680) ?><?= val(118, 525, 680, 33, 'ab', 'AB', $d['vehicle']['make']) ?>
  <?= t(530, 667, 33, 'c', 'Chassis No.') ?>
  <?= ln(690, 1068, 686) ?><?= val(690, 1068, 686, 33, 'ab', 'AB', $d['vehicle']['chassis_no']) ?>
 
  <div class="t c" style="left:38px; top:<?= 738 - 17 ?>px; font-size:33px;">is fitted with an approved Speed Governor Model <span class="cb"><?= e($d['device']['model']) ?></span></div>
 
  <?= t(38, 806, 33, 'c', 'Serial No. / Unit Code') ?>
  <?= ln(335, 695, 822) ?><?= val(335, 695, 822, 33, 'ab', 'AB', $d['device']['serial_no']) ?>
  <?= t(700, 812, 33, 'c', 'on Date:') ?>
  <?= ln(822, 1068, 828) ?><?= val(822, 1068, 828, 33, 'ab', 'AB', $d['cert']['issue_date_fmt']) ?>
 
  <?= t(38, 875, 33, 'c', 'EXPIRY DATE:') ?>
  <?= ln(222, 700, 892) ?><?= val(222, 700, 892, 33, 'ab', 'AB', $d['cert']['expiry_date_fmt']) ?>
 
  <div class="t cb" style="left:38px; top:<?= 940 - 17 ?>px; font-size:33.1px;">The Speed Governor is calibrated and sealed not to exceed
    <span class="c red" style="font-size:55.3px; font-weight:normal; vertical-align:-3px;"><?= e($d['device']['set_speed_kmh']) ?>km/hr</span></div>
 
  <?= tc(100, 1015, 995, 27.5, 'tbi red', 'This Certificate is valid for ' . $d['cert']['validity_months'] . ' months from date of Issuing') ?>
  <?= t(62, 1035, 27.6, 'tbi red', 'It is an offence to tamper with the Digital Speed Limiter and it will nullify all warranty.') ?>
  <?= t(95, 1085, 33.1, 'cb', 'WARRANTY VALID ONLY IF THE GOVERNOR SEAL REMAINS INTACT') ?>
 
  <?= t(40, 1150, 27.5, 'cb', 'Signed with stamp by Supplier/Dealer/Agent') ?>
  <div class="box" style="left:45px; top:1185px; width:1024px; height:207px;"></div>
  <?= t(55, 1205, 24.6, 'cb', 'FITTING CENTER DETAILS') ?>
  <?= t(55, 1250, 25, 'c', 'DEALER:') ?>
  <?= ln(142, 475, 1262) ?><?= val(142, 475, 1262, 27, 'cb not-entry', 'CB', $d['company']['dealer_no'], 4) ?>
  <div class="t a" style="left:480px; top:<?= 1250 - 15 ?>px; font-size:29px;">FITTING<span class="chk"><?php if (!$d['cert']['is_renewal']): ?><span class="tick">&#10003;</span><?php endif; ?></span>RENEWAL<span class="chk"><?php if ($d['cert']['is_renewal']): ?><span class="tick">&#10003;</span><?php endif; ?></span>(Service Type)</div>
  <div class="t c" style="left:55px; top:<?= 1305 - 16 ?>px; font-size:24.5px;">Dealer Name: <span class="cb" style="font-size:32.8px;"><?= e($d['company']['dealer_name']) ?></span></div>
  <?= t(55, 1365, 24.7, 'c', 'Signature:') ?>
  <?= ln(160, 540, 1377) ?>
  <?= signature(230, 1330, 240, 1390) ?>
  <?= t(552, 1365, 24.7, 'c', 'Company seal (here)') ?>
  <?= ln(770, 1058, 1377) ?>
  <?php if (!empty($d['company']['stamp_image'])): ?><img class="img" src="<?= e($d['company']['stamp_image']) ?>" style="left:830px; top:1262px; height:125px; opacity:0.85;"><?php endif; ?>
 
  <img class="img" src="<?= $kebs ?>" style="left:58px; top:1397px; width:96px;">
  <?= t(42, 1507, 26.9, 'cb', $d['company']['kebs_permit']) ?>
  <img class="img" src="<?= $logo_gold ?>" style="left:914px; top:1408px; width:138px;">
  <?= t(102, 1560, 31.4, 'abi', 'This certificate is issued without any alterations whatsoever', 'color:#EE5A3C;') ?>
 
  <!-- ================= WARRANTY CARD (top right) ================= -->
  <?= logo_lockup(1180, 36, 122, 1255, 88, 14.3, 108, 13.1) ?>
  <?= address(1592, 1990, 34, 23.3, 22) ?>
  <?= t(2000, 67, 26.3, 'cb', 'Cert No.') ?>
  <?= certno(2102, 66, 24) ?>
  <div class="t cb" style="left:1992px; top:113px; width:303px; height:45px; line-height:47px; text-align:center; background:#E53935; color:#fff; font-size:38.5px;">WARRANTY CARD</div>
 
  <div class="t cb" style="left:1188px; top:<?= 162 - 13 ?>px; font-size:25.5px; line-height:34px;">
    &bull; Warrant cover for <?= e($d['device']['model']) ?> is Strictly for the gadget unit only.<br>
    &bull; <?= e($d['company']['name_caps']) ?> is not liable for any damages that may result from malfunctioning due<br>
    &nbsp;&nbsp;to attempted tamper, short circuit (with intent or otherwise), and/or attempted or actual installation by<br>
    &nbsp;&nbsp;unauthorized technicians, or any other related cause.<br>
    &bull; Warranty period runs for 12 months since installation date.
  </div>
  <?= t(1205, 332, 25, 'cb', 'Disclaimer:-') ?>
  <?= t(1205, 370, 24.9, 'c', 'The limited warranty does !apply to any product or part thereof damaged by:') ?>
  <div class="t c" style="left:1215px; top:<?= 400 - 13 ?>px; font-size:24.9px; line-height:33.3px;">
    <b>a)</b> Alteration or disassembly;<br><b>b)</b> Accident or abuse;<br>
    <b>c)</b> Repair or service provided by an unauthorized repair facility;<br>
    <b>d)</b> Operation contrary to instructions pertaining to this product.
  </div>
  <?= t(1185, 532, 27, 'cb', 'Details:', 'text-decoration:underline;') ?>
 
  <?= t(1185, 574, 24.9, 'c', 'Vehicle Reg. No.') ?><?= ln(1352, 1722, 584) ?><?= val(1352, 1722, 584, 28, 'a', 'A', $d['vehicle']['reg_no'], 8) ?>
  <?= t(1185, 618, 24.9, 'c', 'Gadget Serial Number:') ?><?= ln(1418, 1722, 628) ?><?= val(1418, 1722, 628, 28, 'a', 'A', $d['device']['serial_no'], 8) ?>
  <?= t(1185, 662, 24.9, 'c', 'Phone Contacts:') ?><?= ln(1352, 1722, 672) ?><?= val(1352, 1722, 672, 28, 'a', 'A', $d['client']['phone'], 8) ?>
  <?= t(1185, 706, 24.9, 'c', 'Date Installed:') ?><?= ln(1335, 1722, 716) ?><?= val(1335, 1722, 716, 28, 'a', 'A', $d['installation']['installed_on_fmt'], 8) ?>
 
  <?= t(1728, 578, 24.9, 'c', 'Chassis No.') ?><?= ln(1850, 2228, 590) ?><?= val(1850, 2228, 590, 28, 'a', 'A', $d['vehicle']['chassis_no'], 8) ?>
  <?= t(1728, 623, 24.9, 'c', 'Client Name:') ?><?= ln(1863, 2228, 634) ?><?= val(1863, 2228, 634, 28, 'a', 'A', $d['client']['name'], 8) ?>
  <?= t(1728, 667, 24.9, 'c', 'Locality:') ?><?= ln(1815, 2228, 678) ?><?= val(1815, 2228, 678, 28, 'a', 'A', $d['client']['address'], 8) ?>
  <?= t(1728, 712, 24.9, 'c', 'Acting Agent/Technician:') ?><?= ln(1985, 2228, 722) ?><?= val(1985, 2228, 722, 28, 'a', 'A', $d['installation']['technician'], 8) ?>
 
  <?= t(1185, 747, 24.3, 'c', 'SIGNED') ?>
  <?= ln(1375, 1612, 775) ?>
  <?= signature(1385, 722, 220, 784) ?>
  <?= tc(1375, 1612, 797, 27.2, 'cbi', 'COMPANY', 'color:#333;') ?>
  <?= ln(1865, 2102, 780) ?>
  <?= tc(1865, 2102, 803, 27.2, 'cbi', 'CLIENT/REP.', 'color:#333;') ?>
 
  <!-- ================= OFFICE COPY CARD (bottom middle) ================= -->
  <?= logo_lockup(1180, 866, 110, 1248, 917, 14.3, 933, 11.6) ?>
  <?= address(1172, 1520, 951, 17.4, 17.6, 'center') ?>
  <?= t(1170, 1057, 18.2, 'ab', 'SPEED GOVERNOR: ' . $d['device']['model'], 'letter-spacing:0.6px;') ?>
  <div class="t ab" style="left:1262px; top:1452px; font-size:52px;"><div class="dup">DUPLICATE</div></div>
  <?= t(1170, 1097, 28.3, 'ab', 'Cert No.') ?>
  <?= certno(1300, 1097, 24) ?>
  <?php foreach ([['Vehicle Reg', $d['vehicle']['reg_no'], 1167], ['Chassis No', $d['vehicle']['chassis_no'], 1242],
                              ['Serial No', $d['device']['unit_code'], 1317], ['Issue Date', $d['cert']['issue_date_fmt'], 1392],
                              ['Exp Date', $d['cert']['expiry_date_fmt'], 1467]] as [$label, $value, $cy]): ?>
    <?= t(1170, $cy, 22.3, 'ab', $label) ?><?= ln(1300, 1518, $cy + 12) ?><?= val(1300, 1518, $cy + 12, 32.7, 'ab', 'AB', $value, 4) ?>
  <?php endforeach; ?>
  <?= t(1170, 1540, 22.3, 'ab', 'Signature') ?><?= ln(1300, 1518, 1552) ?>
  <?= signature(1310, 1490, 200, 1585) ?>
 
  <!-- ================= VEHICLE STICKER (bottom right) ================= -->
  <img class="img" src="<?= $sticker_bg ?>" style="left:1568px; top:872px; width:737px; height:726px;">
  <?= logo_lockup(1605, 920, 100, 1664, 965, 11.8, 980, 9.9) ?>
  <?= address(1905, 2200, 913, 19, 22.5) ?>
  <img class="img" src="<?= $kebs ?>" style="left:2200px; top:936px; width:60px;">
  <div class="t cb" style="left:2259px; top:1030px; font-size:17px;"><div class="rot"><?= e($d['company']['kebs_permit']) ?></div></div>
  <?= t(1613, 1052, 21.3, 'ab', 'SPEED GOVERNOR: ' . $d['device']['model'], 'letter-spacing:0.6px;') ?>
  <?php if ($qr !== ''): ?>
  <img class="img" src="<?= $qr ?>" style="left:2045px; top:1036px; width:72px; height:72px;">
  <?php endif; ?>
  <img class="img" src="<?= $logo_gold ?>" style="left:2130px; top:1084px; width:134px;">
  <?= t(1613, 1103, 28.3, 'ab emb', 'Cert No.') ?>
  <?= certno(1753, 1103, 24) ?>
  <?php foreach ([['Vehicle Reg', $d['vehicle']['reg_no'], 1168], ['Chassis No', $d['vehicle']['chassis_no'], 1242],
                              ['Serial No', $d['device']['unit_code'], 1317], ['Issue Date', $d['cert']['issue_date_fmt'], 1392],
                              ['Exp Date', $d['cert']['expiry_date_fmt'], 1467]] as [$label, $value, $cy]): ?>
    <?= t(1613, $cy, 27.6, 'ab emb', $label, 'color:#3a3a3a;') ?><?= ln(1770, 2222, $cy + 14) ?><?= val(1770, 2222, $cy + 14, 33, 'ab', 'AB', $value, 4) ?>
  <?php endforeach; ?>
  <?= t(1613, 1540, 27.6, 'ab emb', 'Signature', 'color:#3a3a3a;') ?><?= ln(1770, 2222, 1554) ?>
  <?= signature(1880, 1490, 230, 1572) ?>
 
  <?php if ($ppAlign):
    // Millimetre rulers for the alignment test, in sheet units (7.872 per mm).
    // 0 is where the blank's line should cross; read the blank's own line off it.
    $u = 2338 / 297;
    $rul = function (float $x, float $y, string $dir, string $label) use ($u): string {
        $o = '<div class="pp-ruler">';
        for ($i = -10; $i <= 10; $i++) {
            $len = ($i === 0 ? 7 : ($i % 5 === 0 ? 5 : 3)) * $u;
            if ($dir === 'h') {
                $o .= '<i style="position:absolute;background:#000;left:' . round($x + $i * $u - 1) . 'px;top:' . round($y - $len / 2)
                    . 'px;width:2px;height:' . round($len) . 'px"></i>';
                if ($i % 5 === 0) {
                    $o .= '<b style="position:absolute;font:600 15px Arial;left:' . round($x + $i * $u - 20) . 'px;top:' . round($y + 4 * $u)
                        . 'px;width:40px;text-align:center">' . ($i > 0 ? '+' : '') . $i . '</b>';
                }
            } else {
                $o .= '<i style="position:absolute;background:#000;top:' . round($y + $i * $u - 1) . 'px;left:' . round($x - $len / 2)
                    . 'px;height:2px;width:' . round($len) . 'px"></i>';
                if ($i % 5 === 0) {
                    $o .= '<b style="position:absolute;font:600 15px Arial;top:' . round($y + $i * $u - 8) . 'px;left:' . round($x + 4 * $u)
                        . 'px">' . ($i > 0 ? '+' : '') . $i . '</b>';
                }
            }
        }
        $o .= $dir === 'h'
            ? '<i style="position:absolute;background:#000;left:' . round($x - 10 * $u) . 'px;top:' . round($y - 1) . 'px;width:' . round(20 * $u) . 'px;height:2px"></i>'
            : '<i style="position:absolute;background:#000;top:' . round($y - 10 * $u) . 'px;left:' . round($x - 1) . 'px;height:' . round(20 * $u) . 'px;width:2px"></i>';
        $lx = $dir === 'h' ? $x - 10 * $u : $x + 9 * $u;
        $ly = $dir === 'h' ? $y - 8 * $u : $y - 2;
        return $o . '<b style="position:absolute;font:700 17px Arial;white-space:nowrap;left:' . round($lx) . 'px;top:' . round($ly) . 'px">'
             . $label . '</b></div>';
    };
    echo $rul(1069, 1290, 'h', 'ACROSS &rarr; Move right by');    // the red box's right edge
    echo $rul(640, 470, 'v', 'DOWN &darr; Move down by');         // the Customer Name line
    echo $rul(1992, 135, 'h', 'ACROSS &rarr; Move right by');     // the WARRANTY CARD block's left edge
    echo $rul(2000, 1554, 'v', 'DOWN &darr; Move down by');       // the sticker's Signature line
  endif; ?>
</div>
</body>
</html>
 