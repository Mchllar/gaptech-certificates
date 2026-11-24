<?php
/**
 * The management report as a PDF, for the period on screen in reports.php.
 *
 * Built from the same rpt_build() and rpt_body() as the page, then handed to the
 * Chrome renderer the certificates already use (app/pdf.php). Charts are SVG, so
 * they come out sharp at any zoom and print cleanly.
 *
 * ?html=1 shows the print layout in the browser instead - useful for checking a
 * layout, and the fallback offered if this machine cannot produce a PDF.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/reports.php';

$user = require_staff();
$P = rpt_period($_GET);
$R = rpt_build($P);

$asHtml = isset($_GET['html']);

/** A file:// URL for a local asset; pdf_render_html() inlines these before Chrome sees them. */
function rpt_file_url(string $path): string
{
    $real = realpath($path);
    return $real ? 'file:///' . ltrim(str_replace('\\', '/', $real), '/') : '';
}

$assets  = __DIR__ . '/assets';
// The full company logo (name and tagline), web-sized: the 10,000px original would bloat every PDF.
$logo    = $asHtml ? 'assets/logo_wide.png' : rpt_file_url($assets . '/logo_wide.png');
$font    = $asHtml ? 'assets/fonts/InterVariable.ttf' : rpt_file_url($assets . '/fonts/InterVariable.ttf');
$company = (string)setting('company_name', 'GAPTECH Solutions Ltd');
$modules = ['all' => 'Speed governors and trackers', 'governor' => 'Speed governors', 'tracking' => 'Trackers'];
$generated = (new DateTimeImmutable('now'))->format('j M Y, H:i');

ob_start();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Management report <?= e($P['label']) ?></title>
<style>
@font-face { font-family: "InterReport"; src: url("<?= $font ?>") format("truetype"); font-weight: 100 900; }
<?= rpt_css() ?>

* { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
html, body { margin: 0; padding: 0; background: #fff; }
body { font-family: "InterReport", "Segoe UI", Arial, sans-serif; font-size: 11px; color: #3D4756; }

.doc-head { display: flex; align-items: center; justify-content: space-between; gap: 20px;
            padding: 0 0 14px; margin: 0 0 16px; border-bottom: 2px solid #0D4F72; }
.doc-head img { height: 50px; width: auto; }
.doc-title { text-align: right; }
.doc-title h1 { margin: 0; font-size: 20px; font-weight: 700; color: #0D4F72; letter-spacing: -.3px; }
.doc-title p { margin: 2px 0 0; font-size: 11px; color: #5C6775; }
.doc-title p b { color: #1C2430; }
.doc-meta { display: flex; justify-content: space-between; gap: 16px; margin: -6px 0 14px; font-size: 9.5px; color: #8793A3; }

/* A4 is narrower than a screen: fixed columns, flatter cards, nothing split over a page. */
.rpt-card, .rpt-kpi { box-shadow: none; border-radius: 10px; padding: 12px 14px; }
.rpt-card, .rpt-kpi, .rpt-grid, .rpt-findings { break-inside: avoid; page-break-inside: avoid; }
.rpt h2.rpt-h { margin: 18px 0 8px; font-size: 10.5px; break-after: avoid; page-break-after: avoid; }
.rpt-card h3 { font-size: 12.5px; }
.rpt-cap { font-size: 9.5px; margin-bottom: 8px; }
.rpt-findings li { font-size: 11px; margin-bottom: 4px; }
.rpt-kpis { grid-template-columns: repeat(3, 1fr); gap: 8px; }
.rpt-kpi-row b { font-size: 21px; }
.rpt-kpi-label { font-size: 10px; } .rpt-kpi-foot { font-size: 9px; } .rpt-delta { font-size: 9.5px; }
.rpt-grid { gap: 10px; margin-bottom: 10px; }
.rpt-grid.two { grid-template-columns: 1fr 1fr; }
.rpt-grid.three { grid-template-columns: repeat(3, 1fr); }
.rpt-ring { width: 130px; height: 130px; }
.rpt-key li { font-size: 10px; padding: 4px 0; grid-template-columns: 9px 1fr auto 30px; gap: 6px; }
.rpt-legend { font-size: 10px; }
.rpt-bar-row { font-size: 10.5px; grid-template-columns: 34% 1fr 64px; }
.rpt .rpt-table th { font-size: 9.5px; padding: 6px 8px; }
.rpt .rpt-table td { font-size: 10.5px; padding: 6px 8px; }
.rpt-keep { margin-bottom: 10px; }
.rpt-note { font-size: 9px; }
.doc-foot { margin-top: 18px; padding-top: 8px; border-top: 1px solid #E4EBF2; font-size: 9px; color: #8793A3;
            display: flex; justify-content: space-between; }
<?php if ($asHtml): ?>
body { max-width: 794px; margin: 24px auto; padding: 0 16px; }
.doc-print { position: fixed; top: 12px; right: 12px; padding: 9px 14px; border: 0; border-radius: 8px;
             background: #0D4F72; color: #fff; font: 600 13px "Segoe UI", sans-serif; cursor: pointer; }
@media print { .doc-print { display: none; } body { margin: 0; max-width: none; padding: 0; } }
<?php endif; ?>
</style>
</head>
<body>
<?php /* After </head>, so it outranks the margin-less @page rule pdf_render_html() adds there. */ ?>
<style>@page { size: A4 portrait; margin: 14mm 13mm 14mm; }</style>
<?php if ($asHtml): ?><button class="doc-print" onclick="window.print()">Print / Save as PDF</button><?php endif; ?>

<div class="rpt">
  <header class="doc-head">
    <img src="<?= e($logo) ?>" alt="<?= e($company) ?>">
    <div class="doc-title">
      <h1>Management Report</h1>
      <p><b><?= e($P['label']) ?></b> &middot; <?= e($modules[$P['module']] ?? '') ?></p>
    </div>
  </header>
  <div class="doc-meta">
    <span>Compared with the previous period, <?= e($P['prev_label']) ?>.
      Approved certificates only; volumes, not revenue (amounts are not recorded).</span>
    <span>Generated <?= e($generated) ?> by <?= e((string)($user['name'] ?? '')) ?></span>
  </div>

  <?= rpt_body($R, true) ?>

  <footer class="doc-foot">
    <span><?= e($company) ?> &middot; Confidential: for management use</span>
    <span><?= e($P['label']) ?></span>
  </footer>
</div>
</body>
</html>
<?php
$html = (string)ob_get_clean();

if ($asHtml) {
    echo $html;
    exit;
}

$name = 'GAPTECH-report_' . $P['from']->format('Y-m-d') . '_to_' . $P['to']->format('Y-m-d')
      . ($P['module'] !== 'all' ? '_' . $P['module'] : '') . '.pdf';
$dir  = rtrim((string)($config['storage'] ?? sys_get_temp_dir()), '/\\') . DIRECTORY_SEPARATOR . 'reports';
$path = $dir . DIRECTORY_SEPARATOR . 'report-' . bin2hex(random_bytes(6)) . '.pdf';

try {
    pdf_render_html($html, $path, 'portrait');
} catch (Throwable $err) {
    error_log('[report_pdf] ' . $err->getMessage());
    // No PDF engine on this machine, or it failed: offer the print layout instead of a blank error.
    $qs = $_GET; $qs['html'] = 1;
    require APP_DIR . '/views/layout.php';
    layout_top('Report');
    layout_back('reports.php?' . http_build_query(array_diff_key($_GET, ['html' => 1])), 'Back to reports');
    ?>
    <p class="error">The PDF could not be created on this server.</p>
    <p class="hint">You can still open the print layout and use your browser's <b>Save as PDF</b>.</p>
    <p><a class="button" href="report_pdf.php?<?= e(http_build_query($qs)) ?>" target="_blank" rel="noopener">Open print layout</a></p>
    <?php
    layout_bottom();
    exit;
}

audit('report_pdf', 'report', null, ['from' => $P['from']->format('Y-m-d'), 'to' => $P['to']->format('Y-m-d'), 'module' => $P['module']]);

header('Content-Type: application/pdf');
download_started();   // stops the page's spinner
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
readfile($path);
@unlink($path);
