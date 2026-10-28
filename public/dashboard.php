<?php
/**
 * Dashboard - speed governor and tracker installations together.
 *
 * Charts are drawn as plain SVG from the data, so there is no charting library to
 * install, nothing to load from the internet, and it prints the same as it displays.
 *
 * ---------------------------------------------------------------------------
 * There is no money on this page
 * ---------------------------------------------------------------------------
 * The system records a payment reference or an LPO number, never an amount, so
 * there is no honest way to show revenue. Everything here is a number the data can
 * actually stand behind. If revenue is wanted later it means recording an amount
 * when accounts releases a certificate, and it would only be meaningful from that
 * day forward.
 *
 * ---------------------------------------------------------------------------
 * "Cover" versus "certificates"
 * ---------------------------------------------------------------------------
 * Two different questions, and the page is careful about which it is answering:
 *
 *   cover         how many VEHICLES are protected today - one row per vehicle,
 *                 counting only the certificate currently in force
 *   certificates  how many PIECES OF PAPER exist, in whatever state
 *
 * A vehicle renewed four times is one covered vehicle and four certificates. The
 * tiles count cover, because that is the business. The mix donut counts
 * certificates, because that is the paperwork. Each says which it is.
 *
 * Current cover is worked out once, at the top, and everything else is derived from
 * it. Counting "covered", "expiring" and "lapsed" with three separate queries is
 * how a dashboard ends up claiming 22 covered and 25 valid and leaving somebody to
 * work out which is the lie.
 *
 * Matching differs by module because the data does:
 *
 *   governor  by vehicle_id - those certificates point at a vehicles row
 *   tracking  by registration, letters and digits only - tracking_certificates
 *             stores the plate and customer as text, with no vehicles row to point
 *             at. So "KDA 107G" and "KDA107G" are one vehicle.
 *
 * A future-dated certificate is not cover yet: one paid for early does not start
 * until its start date. Voided certificates are excluded everywhere.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require APP_DIR . '/views/layout.php';

// Accounts see this too. They own the backlog half of it, and a page that shows
// them how long releases are taking is a page about their own work.
$user  = require_staff();
$isAdm = $user['role'] === 'administrator';

// Management reads the figures but may not open a certificate - enforce_role_access()
// refuses those pages. So the "Open" links are not offered either: a link that
// bounces you back to the page you clicked it on reads as a broken system, and
// whoever clicks it has no way of knowing it was never meant for them.
$canOpen = $user['role'] !== 'management';

/** Is this table present? Lets the page work before the tracking module is installed. */
function dash_has_table(string $name): bool
{
    static $seen = [];
    return $seen[$name] ??= (bool)q('SELECT to_regclass(?) IS NOT NULL', ['public.' . $name])->fetchColumn();
}

/** Is this column present? clients.password_hash arrived in a later migration. */
function dash_has_column(string $table, string $column): bool
{
    static $seen = [];
    return $seen["$table.$column"] ??= (bool)q(
        'SELECT count(*) FROM information_schema.columns
          WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
        [$table, $column])->fetchColumn();
}

$hasTracking = dash_has_table('tracking_certificates');

/* ---------------------------------------------------------------- the data */

// --- current cover, one row per vehicle ------------------------------------
$coverSql = "
WITH gov AS (
  SELECT DISTINCT ON (c.vehicle_id)
         'governor'::text AS kind, c.id AS cert_id, v.reg_no AS reg_no,
         cl.name AS client, c.number AS number, c.expiry_date AS ends
    FROM certificates c
    JOIN vehicles v ON v.id = c.vehicle_id
    LEFT JOIN clients cl ON cl.id = c.client_id
   WHERE c.approval_status = 'approved' AND c.status <> 'voided'
     AND c.issue_date <= current_date
   ORDER BY c.vehicle_id, c.issue_date DESC, c.number DESC
)";

if ($hasTracking) {
    $coverSql .= ", trk AS (
  SELECT DISTINCT ON (regexp_replace(upper(t.reg_no), '[^A-Z0-9]', '', 'g'))
         'tracking'::text, t.id, t.reg_no, t.client_name, t.number, t.expiry_date
    FROM tracking_certificates t
   -- 'removed' as well as 'voided': once the tracker is off the vehicle there is
   -- no box in it, so it is not covered however long the subscription had left.
   WHERE t.approval_status = 'approved' AND t.status NOT IN ('voided', 'removed')
     AND t.subscription_date <= current_date
   ORDER BY regexp_replace(upper(t.reg_no), '[^A-Z0-9]', '', 'g'),
            t.subscription_date DESC, t.number DESC
)
SELECT * FROM gov UNION ALL SELECT * FROM trk";
} else {
    $coverSql .= ' SELECT * FROM gov';
}

$cover = q($coverSql)->fetchAll();

$today   = new DateTimeImmutable('today');
$daysTo  = static fn(string $d): int =>
    (int)$today->diff(new DateTimeImmutable($d))->format('%r%a');

$blank = ['covered' => 0, 'soon' => 0, 'lapsed' => 0, 'd60' => 0, 'd90' => 0];
$cv    = ['governor' => $blank, 'tracking' => $blank];
$expiring = $lapsedRows = [];

foreach ($cover as $row) {
    $k = $row['kind'];
    $n = $daysTo((string)$row['ends']);
    $row['days'] = $n;

    if ($n < 0) {
        $cv[$k]['lapsed']++;
        $lapsedRows[] = $row;
        continue;
    }

    $cv[$k]['covered']++;
    if ($n <= 30)      { $cv[$k]['soon']++; $expiring[] = $row; }
    elseif ($n <= 60)  { $cv[$k]['d60']++; }
    elseif ($n <= 90)  { $cv[$k]['d90']++; }
}

usort($expiring,   static fn($a, $b) => $a['days'] <=> $b['days']);
usort($lapsedRows, static fn($a, $b) => $b['days'] <=> $a['days']);

$sum = static fn(string $key): int => $cv['governor'][$key] + $cv['tracking'][$key];

// --- the paperwork, by state ------------------------------------------------
$mixSql = "SELECT
    count(*) AS total,
    count(*) FILTER (WHERE approval_status = 'approved' AND status = 'issued'
                     AND expiry_date >= current_date) AS valid,
    count(*) FILTER (WHERE approval_status = 'approved' AND status = 'issued'
                     AND expiry_date < current_date) AS expired,
    count(*) FILTER (WHERE approval_status = 'pending')  AS pending,
    count(*) FILTER (WHERE approval_status = 'rejected') AS rejected,
    count(*) FILTER (WHERE status = 'superseded') AS superseded,
    count(*) FILTER (WHERE status = 'voided')     AS voided
  FROM ";

$counts = q($mixSql . 'certificates')->fetch();
$tcounts = $hasTracking
    ? q($mixSql . 'tracking_certificates')->fetch()
    : array_fill_keys(array_keys($counts), 0);

$both = [];
foreach ($counts as $k => $v) {
    $both[$k] = (int)$v + (int)$tcounts[$k];
}

// --- issued each month, both modules ----------------------------------------
// Counted on the date cover STARTS, not the date it was approved: a certificate
// paid for in March that runs from April is April's cover.
$monthlySql = "
SELECT to_char(d.m, 'Mon') AS label, to_char(d.m, 'Mon YYYY') AS full_label,
       coalesce(g.n, 0) AS a" . ($hasTracking ? ', coalesce(t.n, 0) AS b' : ', 0 AS b') . "
  FROM generate_series(date_trunc('month', current_date) - interval '11 months',
                       date_trunc('month', current_date), interval '1 month') AS d(m)
  LEFT JOIN (SELECT date_trunc('month', issue_date) mm, count(*) n
               FROM certificates
              WHERE approval_status = 'approved' AND status <> 'voided'
              GROUP BY 1) g ON g.mm = d.m";

if ($hasTracking) {
    $monthlySql .= "
  LEFT JOIN (SELECT date_trunc('month', subscription_date) mm, count(*) n
               FROM tracking_certificates
              WHERE approval_status = 'approved' AND status <> 'voided'
              GROUP BY 1) t ON t.mm = d.m";
}
$monthly = q($monthlySql . ' ORDER BY d.m')->fetchAll();

// --- fitting or renewal, both modules ---------------------------------------
// Governor says so in a column. Tracking does not, but renewed_from tells us: a
// certificate that came from another one is a renewal.
$fitting = ['Fitting' => 0, 'Renewal' => 0];

foreach (q("SELECT type, count(*) AS n FROM certificates
             WHERE approval_status = 'approved' AND status <> 'voided'
               AND issue_date >= current_date - interval '12 months'
             GROUP BY type")->fetchAll() as $r) {
    $label = stripos((string)$r['type'], 'renew') !== false ? 'Renewal' : 'Fitting';
    $fitting[$label] += (int)$r['n'];
}

if ($hasTracking) {
    $r = q("SELECT count(*) FILTER (WHERE renewed_from IS NULL)     AS fitting,
                   count(*) FILTER (WHERE renewed_from IS NOT NULL) AS renewal
              FROM tracking_certificates
             WHERE approval_status = 'approved' AND status <> 'voided'
               AND subscription_date >= current_date - interval '12 months'")->fetch();
    $fitting['Fitting'] += (int)$r['fitting'];
    $fitting['Renewal'] += (int)$r['renewal'];
}

// --- busiest customers, both modules ----------------------------------------
// Governor certificates point at a clients row; tracking ones carry the name as
// text. So these are matched on the name, which is why the panel says so: a
// customer spelled two ways on the tracking side counts as two customers.
$byClient = [];
foreach (q("SELECT cl.name, count(*) AS n
              FROM certificates c JOIN clients cl ON cl.id = c.client_id
             WHERE c.approval_status = 'approved' AND c.status <> 'voided'
             GROUP BY cl.name")->fetchAll() as $r) {
    $key = mb_strtoupper(trim((string)$r['name']));
    $byClient[$key] ??= ['name' => trim((string)$r['name']), 'gov' => 0, 'trk' => 0];
    $byClient[$key]['gov'] += (int)$r['n'];
}

if ($hasTracking) {
    foreach (q("SELECT client_name AS name, count(*) AS n
                  FROM tracking_certificates
                 WHERE approval_status = 'approved' AND status <> 'voided'
                   AND client_name <> ''
                 GROUP BY client_name")->fetchAll() as $r) {
        $key = mb_strtoupper(trim((string)$r['name']));
        $byClient[$key] ??= ['name' => trim((string)$r['name']), 'gov' => 0, 'trk' => 0];
        $byClient[$key]['trk'] += (int)$r['n'];
    }
}

foreach ($byClient as $k => $v) {
    $byClient[$k]['n'] = $v['gov'] + $v['trk'];
}
usort($byClient, static fn($a, $b) => [$b['n'], $a['name']] <=> [$a['n'], $b['name']]);
$topClients = array_slice($byClient, 0, 7);

// --- how approvals are moving ------------------------------------------------
// The median, not the average: one certificate that sat over a holiday should not
// decide what "typical" looks like.
$flowSql = "SELECT
    count(*) FILTER (WHERE approved_at >= date_trunc('month', current_date)) AS this_month,
    count(*) FILTER (WHERE approved_at >= date_trunc('month', current_date) - interval '1 month'
                      AND approved_at <  date_trunc('month', current_date))  AS last_month,
    count(*) FILTER (WHERE approval_status = 'pending') AS pending,
    max(current_date - issued_at::date) FILTER (WHERE approval_status = 'pending') AS oldest,
    percentile_cont(0.5) WITHIN GROUP (
        ORDER BY extract(epoch FROM (approved_at - issued_at)) / 86400.0)
        FILTER (WHERE approved_at IS NOT NULL AND approved_at >= current_date - 90) AS median_days
  FROM ";

$flow  = q($flowSql . 'certificates')->fetch();
$tflow = $hasTracking ? q($flowSql . 'tracking_certificates')->fetch() : null;

// --- the fleet ----------------------------------------------------------------
$withLogin = dash_has_column('clients', 'password_hash')
    ? (int)q('SELECT count(*) FROM clients WHERE password_hash IS NOT NULL')->fetchColumn()
    : null;

$fleet = [
    'vehicles' => (int)q('SELECT count(*) FROM vehicles')->fetchColumn(),
    'clients'  => (int)q('SELECT count(*) FROM clients')->fetchColumn(),
    'tracked'  => $hasTracking
        ? (int)q("SELECT count(DISTINCT regexp_replace(upper(reg_no), '[^A-Z0-9]', '', 'g'))
                    FROM tracking_certificates WHERE status <> 'voided'")->fetchColumn()
        : 0,
];

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

/**
 * Vertical bars for a twelve month series, two modules stacked.
 *
 * Stacked rather than side by side on purpose: the question a director asks first
 * is "how much work did we do in March", and a stacked bar answers it at a glance
 * while still showing the split. Side-by-side bars make you add them up yourself.
 *
 * Each row needs label, full_label, a (governor) and b (tracking).
 */
function gt_bars(array $rows): string
{
    $w = 640; $h = 240; $padL = 34; $padB = 30; $padT = 20;
    $max = 1;
    foreach ($rows as $r) {
        $max = max($max, (int)$r['a'] + (int)$r['b']);
    }
    $step = ($w - $padL - 10) / max(count($rows), 1);
    $bw   = min(38.0, $step * 0.62);
    $plot = $h - $padB - $padT;

    $svg = '<svg class="dash-bars" viewBox="0 0 ' . $w . ' ' . $h . '" role="img">';

    for ($i = 0; $i <= 4; $i++) {
        $v = (int)round($max * $i / 4);
        $y = $h - $padB - $plot * ($i / 4);
        $svg .= '<line x1="' . $padL . '" y1="' . round($y, 1) . '" x2="' . ($w - 6) . '" y2="' . round($y, 1)
              . '" stroke="#EDF2F7" stroke-width="1"/>'
              . '<text x="' . ($padL - 8) . '" y="' . round($y + 4, 1) . '" text-anchor="end"'
              . ' font-size="10" fill="#A8B4C2">' . $v . '</text>';
    }

    foreach ($rows as $i => $r) {
        $a = (int)$r['a'];                     // governor
        $b = (int)$r['b'];                     // tracking
        $n = $a + $b;

        $x  = $padL + $step * $i + ($step - $bw) / 2;
        $ha = $plot * ($a / $max);
        $hb = $plot * ($b / $max);
        $ya = $h - $padB - $ha;                // governor sits on the axis
        $yb = $ya - $hb;                       // tracking stacks on top

        $tip = e($r['full_label']) . ': ' . $a . ' governor, ' . $b . ' tracking';

        if ($a > 0) {
            $svg .= '<rect x="' . round($x, 1) . '" y="' . round($ya, 1) . '" width="' . round($bw, 1)
                  . '" height="' . round($ha, 1) . '" fill="#0D4F72"><title>' . $tip . '</title></rect>';
        }
        if ($b > 0) {
            $svg .= '<rect x="' . round($x, 1) . '" y="' . round($yb, 1) . '" width="' . round($bw, 1)
                  . '" height="' . round($hb, 1) . '" rx="3" fill="#1890D0"><title>' . $tip . '</title></rect>';
        }
        if ($n > 0) {
            $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . round(min($yb, $ya) - 6, 1) . '"'
                  . ' text-anchor="middle" font-size="10" font-weight="700" fill="#5A6472">' . $n . '</text>';
        }

        $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($h - 10) . '" text-anchor="middle"'
              . ' font-size="10" fill="#8793A3">' . e($r['label']) . '</text>';
    }

    return $svg . '</svg>';
}

$mix = [
    ['Valid',       $both['valid'],      '#2E9E4F'],
    ['Expired',     $both['expired'],    '#E0912F'],
    ['Pending',     $both['pending'],    '#1890D0'],
    ['Superseded',  $both['superseded'], '#A3B0BE'],
    ['Rejected',    $both['rejected'],   '#C3121A'],
    ['Voided',      $both['voided'],     '#5B6472'],
];

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
.dash-tile i { display: block; margin-top: 7px; font-size: 11.5px; font-style: normal;
               color: #A8B4C2; font-variant-numeric: tabular-nums; }
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

.dash-legend { display: flex; gap: 18px; font-size: 12px; color: #5A6472; margin: 10px 0 0 34px; }
.dash-legend span::before { content: ''; display: inline-block; width: 10px; height: 10px;
                            border-radius: 3px; margin-right: 7px; vertical-align: -1px; }
.dash-legend .lg-gov::before { background: #0D4F72; }
.dash-legend .lg-trk::before { background: #1890D0; }

.dash-hbar { display: grid; grid-template-columns: 1fr; gap: 9px; margin: 0; }
.dash-hbar div { font-size: 13px; }
.dash-hbar .dash-hbar-top { display: flex; justify-content: space-between; gap: 10px;
                            margin-bottom: 4px; color: #4A5568; }
.dash-hbar .dash-hbar-top strong { color: #1C2430; font-variant-numeric: tabular-nums; }
.dash-track { height: 8px; border-radius: 999px; background: #EDF2F7; overflow: hidden; display: flex; }
.dash-fill { height: 100%; }
.dash-fill.gov { background: #0D4F72; }
.dash-fill.trk { background: #1890D0; }

.dash-mini { display: flex; gap: 22px; flex-wrap: wrap; margin: 2px 0 0; }
.dash-mini div b { display: block; font-size: 22px; font-weight: 700; color: #0D4F72;
                   font-variant-numeric: tabular-nums; }
.dash-mini div span { font-size: 11.5px; color: #8793A3; }

.dash-pill { font-size: 11px; padding: 2px 8px; border-radius: 10px; white-space: nowrap; }
.dash-pill.gov { background: #E5EEF3; color: #0D4F72; }
.dash-pill.trk { background: #E3F1FA; color: #166F9E; }
.dash-late { color: #C3121A; font-weight: 600; }

@media print { .dash-panel, .dash-tile { break-inside: avoid; } }
</style>

<h1>Dashboard</h1>
<p class="hint">
  Speed governors and tracker installations together, as at <?= e(fmt_date($today->format('Y-m-d'))) ?>.
  The tiles count <strong>vehicles</strong>; the mix below counts <strong>certificates</strong>.
  A vehicle renewed four times is one covered vehicle and four certificates.
</p>

<div class="dash-tiles">
  <div class="dash-tile dash-ok">
    <b><?= $sum('covered') ?></b><span>Vehicles covered today</span>
    <i><?= $cv['governor']['covered'] ?> governor &middot; <?= $cv['tracking']['covered'] ?> tracking</i>
  </div>
  <div class="dash-tile dash-warn">
    <b><?= $sum('soon') ?></b><span>Cover ends within 30 days</span>
    <i><?= $cv['governor']['soon'] ?> governor &middot; <?= $cv['tracking']['soon'] ?> tracking</i>
  </div>
  <div class="dash-tile dash-bad">
    <b><?= $sum('lapsed') ?></b><span>Lapsed, nothing issued since</span>
    <i><?= $cv['governor']['lapsed'] ?> governor &middot; <?= $cv['tracking']['lapsed'] ?> tracking</i>
  </div>
  <div class="dash-tile dash-info">
    <b><?= (int)$flow['pending'] + (int)($tflow['pending'] ?? 0) ?></b><span>Waiting for accounts</span>
    <i><?= (int)$flow['pending'] ?> governor &middot; <?= (int)($tflow['pending'] ?? 0) ?> tracking</i>
  </div>
</div>

<div class="dash-grid two">
  <div class="dash-panel">
    <h3>Certificate mix</h3>
    <p class="dash-cap">Every certificate ever raised, both modules, in whatever state.</p>

    <div class="dash-donut-wrap">
      <?= gt_donut($mix) ?>
      <div class="dash-centre">
        <b><?= $both['total'] ?></b>
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
    <h3>Installations by month</h3>
    <p class="dash-cap">
      The last twelve months, counted on the date cover starts &mdash; a certificate paid for
      in March that runs from April is April's.
    </p>
    <?= gt_bars($monthly) ?>
    <div class="dash-legend">
      <span class="lg-gov">Speed governor</span>
      <span class="lg-trk">Tracker installation</span>
    </div>
  </div>
</div>

<div class="dash-grid two">
  <div class="dash-panel">
    <h3>Fitting or renewal</h3>
    <p class="dash-cap">
      Last twelve months, both modules. Renewals are repeat business; fittings are growth.
    </p>

    <?php
    $fitSlices = [
        ['Fitting', $fitting['Fitting'], '#8CC63F'],
        ['Renewal', $fitting['Renewal'], '#0D4F72'],
    ];
    $fitTotal = $fitting['Fitting'] + $fitting['Renewal'];
    ?>

    <div class="dash-donut-wrap">
      <?= gt_donut($fitSlices) ?>
      <div class="dash-centre">
        <b><?= $fitTotal ?></b>
        <span>issued</span>
      </div>
    </div>

    <ul class="dash-key">
      <?php foreach ($fitSlices as $s): ?>
        <li><i style="background:<?= e($s[2]) ?>"></i><?= e($s[0]) ?><em><?= (int)$s[1] ?></em></li>
      <?php endforeach; ?>
      <?php if ($fitTotal === 0): ?><li>Nothing issued in the last year.</li><?php endif; ?>
    </ul>
  </div>

  <div class="dash-panel">
    <h3>Busiest customers</h3>
    <p class="dash-cap">
      Certificates issued, all time, both modules. Matched on the customer's name, because
      tracking certificates store it as text rather than pointing at a customer record &mdash;
      so a name spelled two ways counts twice.
    </p>

    <?php $topMax = $topClients ? max(array_column($topClients, 'n')) : 1; ?>
    <div class="dash-hbar">
      <?php foreach ($topClients as $row): ?>
        <div>
          <div class="dash-hbar-top">
            <span><?= e($row['name']) ?></span><strong><?= (int)$row['n'] ?></strong>
          </div>
          <div class="dash-track">
            <?php if ($row['gov']): ?>
              <div class="dash-fill gov" style="width:<?= round($row['gov'] / max($topMax, 1) * 100, 1) ?>%"
                   title="<?= (int)$row['gov'] ?> governor"></div>
            <?php endif; ?>
            <?php if ($row['trk']): ?>
              <div class="dash-fill trk" style="width:<?= round($row['trk'] / max($topMax, 1) * 100, 1) ?>%"
                   title="<?= (int)$row['trk'] ?> tracking"></div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$topClients): ?><div>No certificates yet.</div><?php endif; ?>
    </div>
    <div class="dash-legend" style="margin-left:0">
      <span class="lg-gov">Speed governor</span>
      <span class="lg-trk">Tracker installation</span>
    </div>
  </div>
</div>

<div class="dash-panel" style="margin-bottom:16px">
  <h3>Getting certificates out</h3>
  <p class="dash-cap">
    How long a certificate waits between being raised and being released. The median over
    the last 90 days, not the average, so one certificate that sat over a holiday neither
    flatters nor spoils the picture.
  </p>
  <table>
    <tr><th>Module</th><th>Approved this month</th><th>Last month</th>
        <th>Waiting now</th><th>Oldest waiting</th><th>Typical release</th></tr>
    <?php
    $flows = ['Speed governor' => $flow];
    if ($hasTracking) { $flows['Tracker installation'] = $tflow; }
    foreach ($flows as $name => $f): ?>
      <tr>
        <td><?= e($name) ?></td>
        <td><?= (int)$f['this_month'] ?></td>
        <td><?= (int)$f['last_month'] ?></td>
        <td><?= (int)$f['pending'] ?></td>
        <td><?= $f['oldest'] !== null
               ? ((int)$f['oldest'] > 30 ? '<span class="dash-late">' . (int)$f['oldest'] . ' days</span>'
                                         : (int)$f['oldest'] . ' days')
               : '&mdash;' ?></td>
        <td><?php
          // "0.0 days" reads like missing data rather than like good news, which is
          // the opposite of what it means.
          if ($f['median_days'] === null) {
              echo '&mdash;';
          } elseif ((float)$f['median_days'] < 0.5) {
              echo 'same day';
          } else {
              echo e(number_format((float)$f['median_days'], 1)) . ' days';
          }
        ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="dash-cap" style="margin:14px 0 0">
    A certificate waiting is a vehicle on the road without its paperwork, so the oldest one
    waiting matters more than how many are.
  </p>
</div>

<div class="dash-panel" style="margin-bottom:16px">
  <h3>On the books</h3>
  <p class="dash-cap">How much of the fleet the system knows about.</p>
  <div class="dash-mini">
    <div><b><?= $fleet['vehicles'] ?></b><span>Vehicles on record</span></div>
    <div><b><?= $fleet['clients'] ?></b><span>Customers</span></div>
    <?php if ($hasTracking): ?>
      <div><b><?= $fleet['tracked'] ?></b><span>Vehicles with a tracker</span></div>
    <?php endif; ?>
    <?php if ($withLogin !== null): ?>
      <div><b><?= $withLogin ?></b><span>With portal logins</span></div>
    <?php endif; ?>
  </div>
</div>

<h2>Cover ending in the next 30 days</h2>
<p class="hint" style="text-align:left">
  One row per vehicle, showing the certificate currently in force. This is the renewal list.
</p>
<table>
  <tr><th>Cover ends</th><th>No.</th><th>Vehicle</th><th>Customer</th><th>Module</th><th></th></tr>
  <?php foreach (array_slice($expiring, 0, 12) as $row): ?>
  <tr>
    <td><?= e(fmt_date((string)$row['ends'])) ?>
        <small style="color:#A8B4C2">(<?= (int)$row['days'] ?>d)</small></td>
    <td><?= e((string)$row['number']) ?></td>
    <td><?= e($row['reg_no']) ?></td>
    <td><?= e($row['client'] ?: '—') ?></td>
    <td><span class="dash-pill <?= $row['kind'] === 'governor' ? 'gov' : 'trk' ?>">
      <?= $row['kind'] === 'governor' ? 'Governor' : 'Tracking' ?></span></td>
    <td><?php if ($canOpen): ?><a href="<?= $row['kind'] === 'governor'
          ? 'certificate_view.php?id=' . (int)$row['cert_id']
          : 'tracking_view.php?id=' . (int)$row['cert_id'] ?>">Open</a><?php endif; ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$expiring): ?><tr><td colspan="6">Nothing due in the next 30 days.</td></tr><?php endif; ?>
  <?php if (count($expiring) > 12): ?>
    <tr><td colspan="6" style="color:#A8B4C2">and <?= count($expiring) - 12 ?> more within 30 days.</td></tr>
  <?php endif; ?>
</table>

<h2 style="margin-top:26px">Lapsed cover</h2>
<p class="hint" style="text-align:left">
  Vehicles we used to cover whose certificate has expired with nothing issued since.
  Every row is a customer who has quietly stopped being one.
</p>
<table>
  <tr><th>Expired</th><th>No.</th><th>Vehicle</th><th>Customer</th><th>Module</th><th></th></tr>
  <?php foreach (array_slice($lapsedRows, 0, 12) as $row): ?>
  <tr>
    <td class="dash-late"><?= e(fmt_date((string)$row['ends'])) ?>
        <small>(<?= abs((int)$row['days']) ?>d ago)</small></td>
    <td><?= e((string)$row['number']) ?></td>
    <td><?= e($row['reg_no']) ?></td>
    <td><?= e($row['client'] ?: '—') ?></td>
    <td><span class="dash-pill <?= $row['kind'] === 'governor' ? 'gov' : 'trk' ?>">
      <?= $row['kind'] === 'governor' ? 'Governor' : 'Tracking' ?></span></td>
    <td><?php if ($canOpen): ?><a href="<?= $row['kind'] === 'governor'
          ? 'certificate_view.php?id=' . (int)$row['cert_id']
          : 'tracking_view.php?id=' . (int)$row['cert_id'] ?>">Open</a><?php endif; ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$lapsedRows): ?>
    <tr><td colspan="6">Nothing has lapsed &mdash; every vehicle ever covered is covered today.</td></tr>
  <?php endif; ?>
  <?php if (count($lapsedRows) > 12): ?>
    <tr><td colspan="6" style="color:#A8B4C2">and <?= count($lapsedRows) - 12 ?> more.</td></tr>
  <?php endif; ?>
</table>
<?php layout_bottom(); ?>