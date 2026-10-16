<?php
/**
 * Administrator dashboard.
 *
 * Charts are drawn as plain SVG from the data, so there is no charting library to
 * install, nothing to load from the internet, and it prints the same as it displays.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require APP_DIR . '/views/layout.php';

$user = require_admin();

/* ---------------------------------------------------------------- the data */

$counts = q("SELECT
    count(*) AS total,
    count(*) FILTER (WHERE approval_status = 'approved' AND status = 'issued'
                     AND expiry_date >= current_date) AS valid,
    count(*) FILTER (WHERE approval_status = 'approved' AND status = 'issued'
                     AND expiry_date BETWEEN current_date AND current_date + 30) AS soon,
    count(*) FILTER (WHERE approval_status = 'approved' AND status = 'issued'
                     AND expiry_date < current_date) AS expired,
    count(*) FILTER (WHERE approval_status = 'pending')  AS pending,
    count(*) FILTER (WHERE approval_status = 'rejected') AS rejected,
    count(*) FILTER (WHERE status = 'superseded') AS superseded,
    count(*) FILTER (WHERE status = 'voided')     AS voided
  FROM certificates")->fetch();

$fleet = q("SELECT
    (SELECT count(*) FROM vehicles) AS vehicles,
    (SELECT count(*) FROM clients)  AS clients,
    (SELECT count(*) FROM clients WHERE password_hash IS NOT NULL) AS with_login")->fetch();

// certificates issued each month for the last twelve
$monthly = q("SELECT to_char(d.m, 'Mon') AS label,
                     to_char(d.m, 'Mon YYYY') AS full_label,
                     count(c.id) AS n
                FROM generate_series(date_trunc('month', current_date) - interval '11 months',
                                     date_trunc('month', current_date),
                                     interval '1 month') AS d(m)
                LEFT JOIN certificates c
                       ON date_trunc('month', c.issue_date) = d.m
                      AND c.approval_status = 'approved'
               GROUP BY d.m ORDER BY d.m")->fetchAll();

$types = q("SELECT type, count(*) AS n FROM certificates
             WHERE approval_status = 'approved'
               AND issue_date >= current_date - interval '12 months'
             GROUP BY type ORDER BY n DESC")->fetchAll();

$topClients = q("SELECT cl.name, count(*) AS n
                   FROM certificates c JOIN clients cl ON cl.id = c.client_id
                  WHERE c.approval_status = 'approved'
                  GROUP BY cl.name ORDER BY n DESC, cl.name LIMIT 7")->fetchAll();

$approvals = q("SELECT
    count(*) FILTER (WHERE approved_at >= date_trunc('month', current_date)) AS this_month,
    count(*) FILTER (WHERE approved_at >= date_trunc('month', current_date) - interval '1 month'
                      AND approved_at <  date_trunc('month', current_date)) AS last_month,
    avg(extract(epoch FROM (approved_at - issued_at)) / 86400.0)
        FILTER (WHERE approved_at IS NOT NULL AND issued_at IS NOT NULL) AS avg_days
  FROM certificates WHERE approval_status = 'approved'")->fetch();

$expiring = q("SELECT c.id, c.number, c.expiry_date, v.reg_no, cl.name AS client_name
                 FROM certificates c
                 JOIN vehicles v ON v.id = c.vehicle_id
                 JOIN clients cl ON cl.id = c.client_id
                WHERE c.approval_status = 'approved' AND c.status = 'issued'
                  AND c.expiry_date BETWEEN current_date AND current_date + 30
                ORDER BY c.expiry_date LIMIT 8")->fetchAll();

/* ------------------------------------------------------------ the drawings */

/**
 * A ring chart. $slices is a list of [label, value, colour].
 * Drawn with stroked arcs, so the thickness is one number rather than two paths.
 */
function gt_donut(array $slices, int $size = 200, float $thick = 28.0): string
{
    $total = 0.0;
    foreach ($slices as $s) {
        $total += (float)$s[1];
    }

    $c = $size / 2.0;
    $r = ($size - $thick) / 2.0;
    $svg = '<svg class="dash-donut" viewBox="0 0 ' . $size . ' ' . $size . '" role="img">';

    if ($total <= 0) {
        return $svg . '<circle cx="' . $c . '" cy="' . $c . '" r="' . $r . '" fill="none"'
             . ' stroke="#E4EBF2" stroke-width="' . $thick . '"/></svg>';
    }

    $angle = -M_PI / 2;                       // start at twelve o'clock
    foreach ($slices as $s) {
        $value = (float)$s[1];
        if ($value <= 0) {
            continue;
        }
        $sweep = ($value / $total) * 2 * M_PI;

        // a slice that is the whole circle has no arc to draw, so use a circle
        if ($value >= $total - 1e-9) {
            $svg .= '<circle cx="' . $c . '" cy="' . $c . '" r="' . round($r, 2) . '" fill="none"'
                  . ' stroke="' . $s[2] . '" stroke-width="' . $thick . '"/>';
            break;
        }

        $x1 = $c + $r * cos($angle);
        $y1 = $c + $r * sin($angle);
        $x2 = $c + $r * cos($angle + $sweep);
        $y2 = $c + $r * sin($angle + $sweep);
        $large = $sweep > M_PI ? 1 : 0;

        $svg .= '<path d="M ' . round($x1, 2) . ' ' . round($y1, 2)
              . ' A ' . round($r, 2) . ' ' . round($r, 2) . ' 0 ' . $large . ' 1 '
              . round($x2, 2) . ' ' . round($y2, 2) . '"'
              . ' fill="none" stroke="' . $s[2] . '" stroke-width="' . $thick . '"/>';

        $angle += $sweep;
    }

    return $svg . '</svg>';
}

/** Vertical bars for a twelve month series. */
function gt_bars(array $rows): string
{
    $w = 640; $h = 220; $padL = 34; $padB = 30; $padT = 14;
    $max = 1;
    foreach ($rows as $r) {
        $max = max($max, (int)$r['n']);
    }
    $step = ($w - $padL - 10) / max(count($rows), 1);
    $bw = min(38.0, $step * 0.62);

    $svg = '<svg class="dash-bars" viewBox="0 0 ' . $w . ' ' . $h . '" role="img">';

    // four guide lines, labelled
    for ($i = 0; $i <= 4; $i++) {
        $v = (int)round($max * $i / 4);
        $y = $h - $padB - ($h - $padB - $padT) * ($i / 4);
        $svg .= '<line x1="' . $padL . '" y1="' . round($y, 1) . '" x2="' . ($w - 6) . '" y2="' . round($y, 1)
              . '" stroke="#EDF2F7" stroke-width="1"/>'
              . '<text x="' . ($padL - 8) . '" y="' . round($y + 4, 1) . '" text-anchor="end"'
              . ' font-size="10" fill="#A8B4C2">' . $v . '</text>';
    }

    foreach ($rows as $i => $r) {
        $n = (int)$r['n'];
        $bh = ($h - $padB - $padT) * ($n / $max);
        $x = $padL + $step * $i + ($step - $bw) / 2;
        $y = $h - $padB - $bh;

        $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bw, 1)
              . '" height="' . round(max($bh, 0), 1) . '" rx="4" fill="url(#dashBar)">'
              . '<title>' . e($r['full_label']) . ': ' . $n . '</title></rect>';

        if ($n > 0) {
            $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . round($y - 5, 1) . '"'
                  . ' text-anchor="middle" font-size="10" font-weight="700" fill="#5A6472">' . $n . '</text>';
        }

        $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($h - 10) . '" text-anchor="middle"'
              . ' font-size="10" fill="#8793A3">' . e($r['label']) . '</text>';
    }

    return $svg . '<defs><linearGradient id="dashBar" x1="0" y1="0" x2="0" y2="1">'
         . '<stop offset="0%" stop-color="#1890D0"/><stop offset="100%" stop-color="#0D4F72"/>'
         . '</linearGradient></defs></svg>';
}

$mix = [
    ['Valid',       (int)$counts['valid'],      '#2E9E4F'],
    ['Expired',     (int)$counts['expired'],    '#E0912F'],
    ['Pending',     (int)$counts['pending'],    '#1890D0'],
    ['Superseded',  (int)$counts['superseded'], '#A3B0BE'],
    ['Rejected',    (int)$counts['rejected'],   '#C3121A'],
    ['Voided',      (int)$counts['voided'],     '#5B6472'],
];

$typeColours = ['#0D4F72', '#8CC63F', '#1890D0', '#E0912F'];

layout_top('Dashboard');
?>
<style>
.dash-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
              gap: 14px; margin: 0 0 22px; }
.dash-tile { background: #fff; border: 1px solid #E4EBF2; border-radius: 14px;
             padding: 16px 18px; text-decoration: none; display: block;
             box-shadow: 0 1px 2px rgba(13,79,114,.04), 0 6px 20px rgba(13,79,114,.06);
             border-left: 3px solid #E4EBF2; transition: border-color .15s ease, background .15s ease; }
.dash-tile:hover { background: #F8FBFE; }
.dash-tile b { display: block; font-size: 30px; font-weight: 700; line-height: 1.05;
               color: #0D4F72; font-variant-numeric: tabular-nums; }
.dash-tile span { display: block; margin-top: 3px; font-size: 12px; color: #8793A3; }
.dash-ok   { border-left-color: #2E9E4F; } .dash-ok b   { color: #1E7B34; }
.dash-warn { border-left-color: #E0912F; } .dash-warn b { color: #B06A00; }
.dash-bad  { border-left-color: #C3121A; } .dash-bad b  { color: #C3121A; }
.dash-info { border-left-color: #1890D0; } .dash-info b { color: #0D4F72; }

.dash-grid { display: grid; gap: 16px; grid-template-columns: 1fr; margin: 0 0 16px; }
@media (min-width: 900px) { .dash-grid.two { grid-template-columns: 340px 1fr; } }

.dash-panel { background: #fff; border: 1px solid #E4EBF2; border-radius: 14px; padding: 18px 20px;
              box-shadow: 0 1px 2px rgba(13,79,114,.04), 0 6px 20px rgba(13,79,114,.06); }
.dash-panel h3 { margin: 0 0 4px; font-size: 13px; font-weight: 700; letter-spacing: .4px;
                 text-transform: uppercase; color: #8793A3; }
.dash-panel .dash-cap { margin: 0 0 16px; font-size: 12.5px; color: #A8B4C2; }

.dash-donut-wrap { position: relative; width: 200px; margin: 0 auto 14px; }
.dash-donut { display: block; width: 200px; height: 200px; }
.dash-centre { position: absolute; inset: 0; display: flex; flex-direction: column;
               align-items: center; justify-content: center; pointer-events: none; }
.dash-centre b { font-size: 30px; font-weight: 700; color: #1C2430; line-height: 1; }
.dash-centre span { font-size: 11px; color: #A8B4C2; text-transform: uppercase; letter-spacing: .6px; }

.dash-key { list-style: none; margin: 0; padding: 0; }
.dash-key li { display: flex; align-items: center; gap: 9px; padding: 5px 0; font-size: 13px; }
.dash-key i { width: 10px; height: 10px; border-radius: 3px; flex: 0 0 10px; }
.dash-key em { margin-left: auto; font-style: normal; font-weight: 700; color: #1C2430;
               font-variant-numeric: tabular-nums; }

.dash-bars { display: block; width: 100%; height: auto; }

.dash-hbar { display: grid; grid-template-columns: 1fr; gap: 9px; margin: 0; }
.dash-hbar div { font-size: 13px; }
.dash-hbar .dash-hbar-top { display: flex; justify-content: space-between; gap: 10px;
                            margin-bottom: 4px; color: #4A5568; }
.dash-hbar .dash-hbar-top strong { color: #1C2430; font-variant-numeric: tabular-nums; }
.dash-track { height: 8px; border-radius: 999px; background: #EDF2F7; overflow: hidden; }
.dash-fill { height: 100%; border-radius: 999px;
             background: linear-gradient(90deg, #1890D0, #0D4F72); }

.dash-mini { display: flex; gap: 22px; flex-wrap: wrap; margin: 2px 0 0; }
.dash-mini div b { display: block; font-size: 22px; font-weight: 700; color: #0D4F72;
                   font-variant-numeric: tabular-nums; }
.dash-mini div span { font-size: 11.5px; color: #8793A3; }
</style>

<h1>Speed Governor Dashboard</h1>
<p class="hint">Everything below counts approved speed govenor certificates unless it says otherwise.</p>

<div class="dash-tiles">
  <a class="dash-tile dash-ok" href="index.php?status=valid">
    <b><?= (int)$counts['valid'] ?></b><span>Valid right now</span></a>
  <a class="dash-tile dash-warn" href="index.php?status=valid">
    <b><?= (int)$counts['soon'] ?></b><span>Expiring within 30 days</span></a>
  <a class="dash-tile dash-bad" href="index.php?status=expired">
    <b><?= (int)$counts['expired'] ?></b><span>Already expired</span></a>
  <a class="dash-tile dash-info" href="index.php?status=pending">
    <b><?= (int)$counts['pending'] ?></b><span>Waiting for accounts</span></a>
</div>

<div class="dash-grid two">
  <div class="dash-panel">
    <h3>Certificate mix</h3>
    <p class="dash-cap">Every certificate ever issued.</p>

    <div class="dash-donut-wrap">
      <?= gt_donut($mix) ?>
      <div class="dash-centre">
        <b><?= (int)$counts['total'] ?></b>
        <span>total</span>
      </div>
    </div>

    <ul class="dash-key">
      <?php foreach ($mix as $s): ?>
        <?php if ((int)$s[1] === 0) { continue; } ?>
        <li><i style="background:<?= e($s[2]) ?>"></i><?= e($s[0]) ?><em><?= (int)$s[1] ?></em></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="dash-panel">
    <h3>Issued by month</h3>
    <p class="dash-cap">The last twelve months, by issue date.</p>
    <?= gt_bars($monthly) ?>
  </div>
</div>

<div class="dash-grid two">
  <div class="dash-panel">
    <h3>Fitting or renewal</h3>
    <p class="dash-cap">Last twelve months.</p>

    <?php
    $typeSlices = [];
    foreach ($types as $i => $t) {
        $typeSlices[] = [$t['type'], (int)$t['n'], $typeColours[$i % count($typeColours)]];
    }
    $typeTotal = array_sum(array_column($types, 'n'));
    ?>

    <div class="dash-donut-wrap">
      <?= gt_donut($typeSlices) ?>
      <div class="dash-centre">
        <b><?= (int)$typeTotal ?></b>
        <span>issued</span>
      </div>
    </div>

    <ul class="dash-key">
      <?php foreach ($typeSlices as $s): ?>
        <li><i style="background:<?= e($s[2]) ?>"></i><?= e($s[0]) ?><em><?= (int)$s[1] ?></em></li>
      <?php endforeach; ?>
      <?php if (!$typeSlices): ?><li>Nothing issued in the last year.</li><?php endif; ?>
    </ul>
  </div>

  <div class="dash-panel">
    <h3>Busiest customers</h3>
    <p class="dash-cap">By certificates issued, all time.</p>

    <?php $topMax = $topClients ? max(array_map(fn($r) => (int)$r['n'], $topClients)) : 1; ?>
    <div class="dash-hbar">
      <?php foreach ($topClients as $row): ?>
        <div>
          <div class="dash-hbar-top">
            <span><?= e($row['name']) ?></span><strong><?= (int)$row['n'] ?></strong>
          </div>
          <div class="dash-track">
            <div class="dash-fill" style="width:<?= round((int)$row['n'] / max($topMax, 1) * 100, 1) ?>%"></div>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$topClients): ?><div>No certificates yet.</div><?php endif; ?>
    </div>
  </div>
</div>

<div class="dash-panel" style="margin-bottom:16px">
  <h3>Accounts and fleet</h3>
  <p class="dash-cap">How approvals are moving, and how much of the fleet is on the books.</p>
  <div class="dash-mini">
    <div><b><?= (int)$approvals['this_month'] ?></b><span>Approved this month</span></div>
    <div><b><?= (int)$approvals['last_month'] ?></b><span>Approved last month</span></div>
    <div><b><?= $approvals['avg_days'] !== null ? number_format((float)$approvals['avg_days'], 1) : '&mdash;' ?></b>
         <span>Average days to approve</span></div>
    <div><b><?= (int)$fleet['vehicles'] ?></b><span>Vehicles on record</span></div>
    <div><b><?= (int)$fleet['clients'] ?></b><span>Customers</span></div>
    <div><b><?= (int)$fleet['with_login'] ?></b><span>With portal logins</span></div>
  </div>
</div>

<h2>Expiring in the next 30 days</h2>
<table>
  <tr><th>Expires</th><th>No.</th><th>Vehicle</th><th>Customer</th><th></th></tr>
  <?php foreach ($expiring as $row): ?>
  <tr>
    <td><?= e(fmt_date($row['expiry_date'])) ?></td>
    <td><?= e((string)$row['number']) ?></td>
    <td><?= e($row['reg_no']) ?></td>
    <td><?= e($row['client_name']) ?></td>
    <td><button type="button" class="link-button renew-open"
      data-id="<?= (int)$row['id'] ?>"
      data-reg="<?= e($row['reg_no']) ?>"
      data-cert="<?= e((string)$row['number']) ?>"
      data-client="<?= e($row['client_name']) ?>">Renew</button></td>  </tr>
  <?php endforeach; ?>
  <?php if (!$expiring): ?><tr><td colspan="5">Nothing due in the next 30 days.</td></tr><?php endif; ?>
</table>
<?php require APP_DIR . '/views/renew_modal.php'; ?>
<?php layout_bottom(); ?>
