<?php
/**
 * Management reports: what needs attention today at the top, then pick a period,
 * see how it went, and download it as a PDF. This page replaced the separate dashboard.
 *
 * The sums and the charts live in app/reports.php, shared with report_pdf.php, so
 * the download is always the page you are looking at.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/reports.php';
require APP_DIR . '/today.php';
require APP_DIR . '/views/layout.php';

$user = require_staff();
$P = rpt_period($_GET);
$R = rpt_build($P);
$T = today_data();
// Management reads the figures but may not open a certificate, so no Open links for them.
$canOpen = ($user['role'] ?? '') !== 'management';

// The same query string drives the PDF, so it reports exactly what is on screen.
$qs = ['p' => $P['preset'], 'm' => $P['module']];
if ($P['preset'] === 'custom') {
    $qs['from'] = $P['from']->format('Y-m-d');
    $qs['to']   = $P['to']->format('Y-m-d');
}
$pdfUrl = 'report_pdf.php?' . http_build_query($qs);

$modules = ['all' => 'Both modules', 'governor' => 'Speed governors', 'tracking' => 'Trackers'];
if (!$R['hasTracking']) { unset($modules['tracking']); }

layout_top('Reports');
?>
<style>
<?= rpt_css() ?>
<?= today_css() ?>

.rpt-head { display:flex; flex-wrap:wrap; align-items:flex-end; justify-content:space-between; gap:12px 20px; margin: 0 0 16px; }
.rpt-head h1 { margin:0 0 4px; font-size:26px; font-weight:700; letter-spacing:-.4px; color:#0D4F72; line-height:1.2;
               padding-left:12px; border-left:4px solid #8CC63F; }
.rpt-head p { margin:0; font-size:13.5px; color:#5C6775; }
.rpt-head p b { color:#1C2430; font-weight:600; }

main form.rpt-filter { display:flex; flex-wrap:wrap; align-items:flex-end; gap:10px 12px; margin:0 0 18px; padding:12px 14px;
              background:#fff; border:1px solid #E4EBF2; border-radius:14px;
              box-shadow: 0 1px 2px rgba(13,79,114,.04), 0 6px 20px rgba(13,79,114,.05); }
main form.rpt-filter label { display:flex; flex-direction:column; gap:5px; margin:0; font-size:11.5px; font-weight:700;
                    letter-spacing:.4px; text-transform:uppercase; color:#5C6775; }
main form.rpt-filter select, main form.rpt-filter input[type=date] {
  display:block; width:auto; min-width:0; margin:0; padding:8px 11px; font:inherit; font-size:14px; font-weight:400;
  letter-spacing:0; text-transform:none; color:#1C2430; background:#F6F9FC; border:1px solid #E4EBF2; border-radius:9px; }
main form.rpt-filter select:focus, main form.rpt-filter input:focus { outline:none; background:#fff; border-color:#1890D0;
  box-shadow:0 0 0 3px rgba(24,144,208,.15); }
main form.rpt-filter .rpt-dates { display:flex; gap:10px; }
main form.rpt-filter .rpt-dates[hidden] { display:none; }
main form.rpt-filter button[type=submit] { padding:9px 16px; }
main form.rpt-filter button[hidden] { display:none; }
.rpt-actions { display:flex; gap:10px; flex-wrap:wrap; }
.rpt-actions .button svg { flex:none; }
.rpt-pdf-wait { display:none; }
.rpt-actions .button.is-busy .rpt-pdf-wait { display:inline; }
.rpt-actions .button.is-busy .rpt-pdf-go { display:none; }
@media (max-width: 560px) { main form.rpt-filter label, main form.rpt-filter select { width:100%; }
  main form.rpt-filter .rpt-dates { width:100%; } main form.rpt-filter .rpt-dates label { flex:1; } }
</style>

<div class="rpt">
  <div class="rpt-head">
    <div>
      <h1>Reports</h1>
      <p>Today's position, then any period you choose, with a PDF to download.</p>
    </div>
    <div class="rpt-actions">
      <a class="button" id="rptPdf" href="<?= e($pdfUrl) ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M12 4v11"/><path d="M7.5 10.5L12 15l4.5-4.5"/><path d="M5 20h14"/></svg>
        <span class="rpt-pdf-go">Download PDF</span><span class="rpt-pdf-wait">Preparing PDF…</span>
      </a>
    </div>
  </div>

  <?= today_html($T, $canOpen) ?>

  <h2 class="rpt-h" id="period">Period report <span class="td-asat"><?= e($P['label']) ?> &middot; compared with <?= e($P['prev_label']) ?></span></h2>

  <form class="rpt-filter" method="get" id="rptFilter" action="reports.php#period">
    <label>Period
      <select name="p" id="rptPreset">
        <?php foreach (rpt_presets() as $k => $label): ?>
          <option value="<?= e($k) ?>" <?= $P['preset'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="rpt-dates" id="rptDates" <?= $P['preset'] === 'custom' ? '' : 'hidden' ?>>
      <label>From <input type="date" name="from" value="<?= e($P['from']->format('Y-m-d')) ?>"
                         max="<?= e($P['today']->format('Y-m-d')) ?>"></label>
      <label>To <input type="date" name="to" value="<?= e($P['to']->format('Y-m-d')) ?>"></label>
    </div>
    <label>Module
      <select name="m">
        <?php foreach ($modules as $k => $label): ?>
          <option value="<?= e($k) ?>" <?= $P['module'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php /* Presets and the module apply the moment they change. Only custom dates need a
             button: two dates typed by hand are not a report until both are finished. */ ?>
    <button type="submit" id="rptApply" <?= $P['preset'] === 'custom' ? '' : 'hidden' ?>>Apply dates</button>
  </form>

  <?= rpt_body($R) ?>
</div>

<script>
<?= rpt_js() ?>

(function () {
  var form = document.getElementById('rptFilter');
  var preset = document.getElementById('rptPreset');
  var dates = document.getElementById('rptDates');
  // Picking a preset is the whole decision, so it applies at once. Custom dates wait
  // for the button, because a half-typed date range is not a report anyone asked for.
  preset.addEventListener('change', function () {
    var custom = preset.value === 'custom';
    dates.hidden = !custom;
    document.getElementById('rptApply').hidden = !custom;
    dates.querySelectorAll('input').forEach(function (i) { i.disabled = !custom; });
    if (!custom) { form.submit(); }
  });
  form.querySelector('[name=m]').addEventListener('change', function () { form.submit(); });
  if (preset.value !== 'custom') {
    dates.querySelectorAll('input').forEach(function (i) { i.disabled = true; });
  }

  // The PDF takes a few seconds to render; say so, rather than leave a dead button.
  var pdf = document.getElementById('rptPdf');
  pdf.addEventListener('click', function () {
    pdf.classList.add('is-busy');
    setTimeout(function () { pdf.classList.remove('is-busy'); }, 8000);
  });
})();
</script>
<?php layout_bottom(); ?>
