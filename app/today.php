<?php
/**
 * "Today" - the top of the Reports page: cover in force now, what is about to end,
 * what has lapsed, and what is waiting for accounts. Not affected by the period
 * picker below it; everything about a PERIOD is in app/reports.php.
 *
 * This was dashboard.php. The two pages are one now, so there is a single place to
 * look and no figure can appear twice with two values. dashboard.php redirects here.
 *
 * "Cover" counts VEHICLES, one row each, using only the certificate currently in
 * force. A vehicle renewed four times is one covered vehicle. Current cover is worked
 * out once and everything else is derived from it, so "covered", "ending" and "lapsed"
 * always add up.
 *
 * Matching differs by module because the data does:
 *   governor  by vehicle_id - those certificates point at a vehicles row
 *   tracking  by registration, letters and digits only - tracking_certificates stores
 *             the plate as text, so "KDA 107G" and "KDA107G" are one vehicle.
 *
 * A future-dated certificate is not cover yet. Voided and removed units never count.
 */
declare(strict_types=1);

function today_has_table(string $name): bool
{
    static $seen = [];
    return $seen[$name] ??= (bool)q('SELECT to_regclass(?) IS NOT NULL', ['public.' . $name])->fetchColumn();
}

/** Everything the Today section shows. */
function today_data(): array
{
    $hasTracking = today_has_table('tracking_certificates');

    $sql = "
    WITH gov AS (
      SELECT DISTINCT ON (c.vehicle_id)
             'governor'::text AS kind, c.id AS cert_id, v.reg_no AS reg_no,
             cl.name AS client, c.number AS number, c.expiry_date AS ends
        FROM certificates c
        JOIN vehicles v ON v.id = c.vehicle_id
        LEFT JOIN clients cl ON cl.id = c.client_id
       WHERE c.approval_status = 'approved' AND c.status NOT IN ('voided', 'removed')
         AND c.issue_date <= current_date
       ORDER BY c.vehicle_id, c.issue_date DESC, c.number DESC
    )";
    if ($hasTracking) {
        $sql .= ", trk AS (
      SELECT DISTINCT ON (regexp_replace(upper(t.reg_no), '[^A-Z0-9]', '', 'g'))
             'tracking'::text, t.id, t.reg_no, t.client_name, t.number, t.expiry_date
        FROM tracking_certificates t
       WHERE t.approval_status = 'approved' AND t.status NOT IN ('voided', 'removed')
         AND t.subscription_date <= current_date
       ORDER BY regexp_replace(upper(t.reg_no), '[^A-Z0-9]', '', 'g'),
                t.subscription_date DESC, t.number DESC
    )
    SELECT * FROM gov UNION ALL SELECT * FROM trk";
    } else {
        $sql .= ' SELECT * FROM gov';
    }

    $today = new DateTimeImmutable('today');
    $blank = ['covered' => 0, 'soon' => 0, 'lapsed' => 0];
    $cv = ['governor' => $blank, 'tracking' => $blank];
    $expiring = $lapsed = [];

    foreach (q($sql)->fetchAll() as $row) {
        $n = (int)$today->diff(new DateTimeImmutable((string)$row['ends']))->format('%r%a');
        $row['days'] = $n;
        if ($n < 0) {
            $cv[$row['kind']]['lapsed']++;
            $lapsed[] = $row;
            continue;
        }
        $cv[$row['kind']]['covered']++;
        if ($n <= 30) {
            $cv[$row['kind']]['soon']++;
            $expiring[] = $row;
        }
    }
    usort($expiring, static fn($a, $b) => $a['days'] <=> $b['days']);
    usort($lapsed,   static fn($a, $b) => $b['days'] <=> $a['days']);

    $flowSql = "SELECT count(*) FILTER (WHERE approval_status = 'pending') AS pending,
                       max(current_date - issued_at::date) FILTER (WHERE approval_status = 'pending') AS oldest
                  FROM ";
    $flow  = q($flowSql . 'certificates')->fetch();
    $tflow = $hasTracking ? q($flowSql . 'tracking_certificates')->fetch() : ['pending' => 0, 'oldest' => null];

    return [
        'today'    => $today,
        'tracking' => $hasTracking,
        'cv'       => $cv,
        'expiring' => $expiring,
        'lapsed'   => $lapsed,
        'pending'  => ['governor' => (int)$flow['pending'], 'tracking' => (int)$tflow['pending']],
        'oldest'   => ($flow['oldest'] === null && $tflow['oldest'] === null)
                        ? null : max((int)$flow['oldest'], (int)$tflow['oldest']),
    ];
}

/** One of the two lists: the renewal list or the lapsed list. */
function today_list(array $rows, bool $lapsed, bool $canOpen, int $limit = 8): string
{
    ob_start(); ?>
    <table class="td-list">
      <thead><tr><th><?= $lapsed ? 'Expired' : 'Cover ends' ?></th><th>Vehicle</th><th>Customer</th><th></th></tr></thead>
      <tbody>
      <?php foreach (array_slice($rows, 0, $limit) as $row): ?>
        <tr>
          <td class="td-when"><?= e(fmt_date((string)$row['ends'])) ?>
            <small class="<?= $lapsed ? 'td-late' : '' ?>"><?= $lapsed
              ? abs((int)$row['days']) . 'd ago'
              : ((int)$row['days'] === 0 ? 'today' : 'in ' . (int)$row['days'] . 'd') ?></small></td>
          <td><span class="td-reg"><?= e((string)$row['reg_no']) ?></span>
            <span class="td-kind <?= $row['kind'] === 'governor' ? 'gov' : 'trk' ?>"><?= $row['kind'] === 'governor' ? 'Governor' : 'Tracker' ?></span></td>
          <td class="td-client"><?= e((string)($row['client'] ?: '—')) ?></td>
          <td class="td-open"><?php if ($canOpen): ?><a href="<?= $row['kind'] === 'governor'
              ? 'certificate_view.php?id=' . (int)$row['cert_id']
              : 'tracking_view.php?id=' . (int)$row['cert_id'] ?>">Open</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="4" class="td-none"><?= $lapsed
          ? 'Nothing has lapsed: every vehicle ever covered is covered today.'
          : 'Nothing due in the next 30 days.' ?></td></tr>
      <?php elseif (count($rows) > $limit): ?>
        <tr><td colspan="4" class="td-none">and <?= count($rows) - $limit ?> more.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    <?php return (string)ob_get_clean();
}

/** The whole Today section. */
function today_html(array $T, bool $canOpen): string
{
    $cv  = $T['cv'];
    $sum = static fn(string $k): int => $cv['governor'][$k] + $cv['tracking'][$k];
    $split = static fn(int $g, int $t): string => $g . ' governor &middot; ' . $t . ' tracker';
    $pend = $T['pending']['governor'] + $T['pending']['tracking'];
    ob_start(); ?>
<section class="td" aria-labelledby="td-title">
  <h2 class="rpt-h" id="td-title">Today <span class="td-asat">as at <?= e(fmt_date($T['today']->format('Y-m-d'))) ?></span></h2>

  <div class="td-tiles">
    <div class="td-tile ok">
      <b><?= $sum('covered') ?></b><span>Vehicles covered</span>
      <i><?= $split($cv['governor']['covered'], $cv['tracking']['covered']) ?></i>
    </div>
    <div class="td-tile warn">
      <b><?= $sum('soon') ?></b><span>Cover ends within 30 days</span>
      <i><?= $split($cv['governor']['soon'], $cv['tracking']['soon']) ?></i>
    </div>
    <div class="td-tile bad">
      <b><?= $sum('lapsed') ?></b><span>Lapsed, nothing since</span>
      <i><?= $split($cv['governor']['lapsed'], $cv['tracking']['lapsed']) ?></i>
    </div>
    <div class="td-tile info">
      <b><?= $pend ?></b><span>Waiting for accounts</span>
      <i><?= $T['oldest'] === null ? $split($T['pending']['governor'], $T['pending']['tracking'])
          : 'Oldest waiting <strong class="' . ($T['oldest'] > 7 ? 'td-late' : '') . '">' . $T['oldest']
            . ' day' . ($T['oldest'] === 1 ? '' : 's') . '</strong>' ?></i>
    </div>
  </div>

  <div class="td-lists">
    <div class="td-panel">
      <h3>Renewals due <span><?= count($T['expiring']) ?> in the next 30 days</span></h3>
      <?= today_list($T['expiring'], false, $canOpen) ?>
    </div>
    <div class="td-panel">
      <h3>Lapsed cover <span><?= count($T['lapsed']) ?> not renewed</span></h3>
      <?= today_list($T['lapsed'], true, $canOpen) ?>
    </div>
  </div>
</section>
    <?php return (string)ob_get_clean();
}

function today_css(): string
{
    return <<<'CSS'
.td-asat { font-weight: 500; text-transform: none; letter-spacing: 0; color: #8793A3; margin-left: 4px; }
.td-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; margin: 0 0 16px; }
.td-tile { background: #fff; border: 1px solid #E4EBF2; border-left: 3px solid #E4EBF2; border-radius: 14px;
           padding: 15px 18px; box-shadow: 0 1px 2px rgba(13,79,114,.04), 0 6px 20px rgba(13,79,114,.05); }
.td-tile b { display: block; font-size: 30px; font-weight: 700; line-height: 1.05; font-variant-numeric: tabular-nums; }
.td-tile span { display: block; margin-top: 3px; font-size: 12.5px; color: #5C6775; }
.td-tile i { display: block; margin-top: 7px; font-size: 11.5px; font-style: normal; color: #8793A3; }
.td-tile.ok   { border-left-color: #2E9E4F; } .td-tile.ok b   { color: #1E7B34; }
.td-tile.warn { border-left-color: #E0912F; } .td-tile.warn b { color: #B06A00; }
.td-tile.bad  { border-left-color: #C3121A; } .td-tile.bad b  { color: #C3121A; }
.td-tile.info { border-left-color: #1890D0; } .td-tile.info b { color: #0D4F72; }
.td-late { color: #C3121A; font-weight: 600; }

.td-lists { display: grid; grid-template-columns: 1fr; gap: 14px; margin: 0 0 8px; }
@media (min-width: 980px) { .td-lists { grid-template-columns: 1fr 1fr; } }
.td-panel { background: #fff; border: 1px solid #E4EBF2; border-radius: 14px; padding: 14px 16px 6px; min-width: 0;
            box-shadow: 0 1px 2px rgba(13,79,114,.04), 0 6px 20px rgba(13,79,114,.05); }
.td-panel h3 { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; margin: 0 0 8px;
               font-size: 14px; font-weight: 700; color: #1C2430; }
.td-panel h3 span { font-size: 12.5px; font-weight: 500; color: #8793A3; }

/* Plain list tables inside the panels: no navy header bar, no card of their own. */
main .td-panel table.td-list { width: 100%; margin: 0; border: 0; box-shadow: none; border-radius: 0; background: none; }
main .td-panel table.td-list th { background: none; color: #8793A3; font-size: 11px; font-weight: 700;
  letter-spacing: .5px; text-transform: uppercase; padding: 6px 8px; border-bottom: 1px solid #E4EBF2; }
main .td-panel table.td-list td { padding: 9px 8px; border-bottom: 1px solid #EEF2F6; font-size: 13.5px; vertical-align: middle; }
main .td-panel table.td-list tr:last-child td { border-bottom: 0; }
.td-when { white-space: nowrap; font-variant-numeric: tabular-nums; }
.td-when small { display: block; font-size: 11.5px; color: #8793A3; }
.td-reg { font-weight: 600; white-space: nowrap; margin-right: 6px; }
.td-kind { font-size: 11px; padding: 1px 7px; border-radius: 10px; white-space: nowrap; }
.td-kind.gov { background: #E5EEF3; color: #0D4F72; }
.td-kind.trk { background: #FDEFE6; color: #A4481A; }
.td-client { color: #5C6775; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.td-open { text-align: right; white-space: nowrap; }
.td-open a { font-weight: 600; color: #0D4F72; }
.td-none { color: #8793A3; }
CSS;
}
