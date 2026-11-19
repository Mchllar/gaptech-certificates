<?php
/**
 * Management reports: the numbers, the analysis and the charts.
 *
 * Shared by public/reports.php (the page) and public/report_pdf.php (the download),
 * so the PDF is always exactly what the page shows - one set of sums, never two.
 *
 * ---------------------------------------------------------------------------
 * What a report answers
 * ---------------------------------------------------------------------------
 *   Growth      how much work came in, and is the covered fleet getting bigger
 *   Mix         who finances the trackers, what vehicles, new work or repeat work
 *   Customers   who the business depends on
 *   Retention   how many customers renew, and what is coming up for renewal
 *   Operations  how quickly paperwork gets out, and what was taken off vehicles
 *
 * Every headline is compared with the period immediately before it, of the same
 * length, because "47 installations" means nothing until you know it was 30 last time.
 *
 * ---------------------------------------------------------------------------
 * Rules the sums follow (the same as the Today section, app/today.php)
 * ---------------------------------------------------------------------------
 *   - Only approved, non-voided certificates count as work done.
 *   - Work is dated on the day cover STARTS (issue_date / subscription_date).
 *   - A vehicle is matched by its plate, letters and digits only, per module, so
 *     "KDA 107G" and "KDA107G" are one vehicle.
 *   - There is no money: the system stores payment references, never amounts.
 *
 * Charts are plain SVG built here, so there is no library to load, nothing fetched
 * from the internet, and the PDF renders them exactly as the page does.
 */

/* =====================================================================
   Colours. Data colours are the validated categorical palette, in its fixed
   order; brand colours are for the page around the charts, never for data.
   ===================================================================== */

const RPT_GOV   = '#2a78d6';   // speed governor - categorical slot 1
const RPT_TRK   = '#eb6834';   // tracker        - categorical slot 2
const RPT_SLOTS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300'];
const RPT_OTHER = '#A3ABB5';   // "Other" is a neutral, never a seventh hue

/* =====================================================================
   Small helpers
   ===================================================================== */

function rpt_has_table(string $name): bool
{
    static $seen = [];
    return $seen[$name] ??= (bool)q('SELECT to_regclass(?) IS NOT NULL', ['public.' . $name])->fetchColumn();
}

function rpt_has_column(string $table, string $column): bool
{
    static $seen = [];
    return $seen["$table.$column"] ??= (bool)q(
        'SELECT count(*) FROM information_schema.columns
          WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
        [$table, $column])->fetchColumn();
}

function rpt_plate(string $reg): string
{
    return (string)preg_replace('/[^A-Z0-9]/', '', strtoupper($reg));
}

/** First word of the make, upper case: "Toyota Hiace" and "TOYOTA" are one make. */
function rpt_make(string $raw): string
{
    $raw = strtoupper(trim($raw));
    if ($raw === '') {
        return '';
    }
    $first = preg_split('/[\s\/,]+/', $raw)[0] ?? '';
    $alias = ['MERCEDES' => 'MERCEDES-BENZ', 'BENZ' => 'MERCEDES-BENZ', 'M/BENZ' => 'MERCEDES-BENZ',
              'VW' => 'VOLKSWAGEN', 'LANDROVER' => 'LAND ROVER', 'LAND' => 'LAND ROVER'];
    return $alias[$first] ?? $first;
}

/**
 * A key that groups spellings of one financier: "KCB Bank Kenya Ltd", "KCB BANK" and
 * "kcb" are one financier. '' means the vehicle is not financed.
 */
function rpt_financier_key(string $raw): string
{
    $k = strtoupper(trim((string)preg_replace('/\s+/', ' ', $raw)));
    $k = trim((string)preg_replace('/[^A-Z0-9& ]/', ' ', $k));
    if (in_array($k, ['', 'NONE', 'N A', 'NA', 'NIL', 'NO', 'CASH', 'SELF', 'PRIVATE', 'OWNER',
                      'NOT FINANCED', 'NO FINANCIER'], true)) {
        return '';
    }
    do {
        $before = $k;
        $k = trim((string)preg_replace('/\s+(BANK|KENYA|LTD|LIMITED|PLC|CO|COMPANY|GROUP)$/', '', $k));
    } while ($k !== $before && $k !== '');
    return trim((string)preg_replace('/\s+/', ' ', $k));
}

/** Percentage change, or null when there is nothing to compare with. */
function rpt_change(float $now, float $before): ?float
{
    if ($before == 0.0) {
        return null;
    }
    return ($now - $before) / $before * 100.0;
}

function rpt_pct(int $part, int $whole): ?float
{
    return $whole > 0 ? $part / $whole * 100.0 : null;
}

function rpt_fmt_pct(?float $v, int $dp = 0): string
{
    return $v === null ? '—' : number_format($v, $dp) . '%';
}

function rpt_day(DateTimeImmutable $d): string
{
    return $d->format('j M Y');
}

/** A rounded axis maximum: 37 becomes 40, 140 becomes 150. */
function rpt_nice_max(float $v): float
{
    if ($v <= 4) {
        return 4;
    }
    // Pick a round step for four gridlines, so the ticks read 10, 20, 30, 40 rather
    // than 13, 26, 39, 52. Steps must be whole numbers: these are counts.
    $raw = $v / 4;
    $exp = 10 ** floor(log10($raw));
    foreach ([1, 1.5, 2, 2.5, 3, 4, 5, 6, 7.5, 8, 10] as $m) {
        $step = $m * $exp;
        if ($step >= $raw && floor($step) == $step) {
            return (float)($step * 4);
        }
    }
    return (float)(10 * $exp * 4);
}

/* =====================================================================
   The period
   ===================================================================== */

/** The presets offered, in the order shown. */
function rpt_presets(): array
{
    return [
        'tm'   => 'This month',
        'lm'   => 'Last month',
        'tq'   => 'This quarter',
        'lq'   => 'Last quarter',
        'ytd'  => 'Year to date',
        'l12m' => 'Last 12 months',
        'ly'   => 'Last year',
        'custom' => 'Custom dates',
    ];
}

/**
 * Work out the period from the query string.
 *
 * Returns from, to, prev_from, prev_to (DateTimeImmutable), the preset, the module,
 * a label, and the buckets the line charts are drawn in.
 */
function rpt_period(array $in): array
{
    $today  = new DateTimeImmutable('today');
    $preset = (string)($in['p'] ?? 'l12m');
    if (!array_key_exists($preset, rpt_presets())) {
        $preset = 'l12m';
    }
    $module = (string)($in['m'] ?? 'all');
    if (!in_array($module, ['all', 'governor', 'tracking'], true)) {
        $module = 'all';
    }

    $monthStart = $today->modify('first day of this month');
    $qStartMonth = intdiv((int)$today->format('n') - 1, 3) * 3 + 1;
    $quarterStart = $today->setDate((int)$today->format('Y'), $qStartMonth, 1);

    switch ($preset) {
        case 'tm':  $from = $monthStart; $to = $today; break;
        case 'lm':  $from = $monthStart->modify('-1 month'); $to = $monthStart->modify('-1 day'); break;
        case 'tq':  $from = $quarterStart; $to = $today; break;
        case 'lq':  $from = $quarterStart->modify('-3 months'); $to = $quarterStart->modify('-1 day'); break;
        case 'ytd': $from = $today->setDate((int)$today->format('Y'), 1, 1); $to = $today; break;
        case 'ly':
            $y = (int)$today->format('Y') - 1;
            $from = $today->setDate($y, 1, 1); $to = $today->setDate($y, 12, 31);
            break;
        case 'custom':
            $f = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($in['from'] ?? ''));
            $t = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($in['to'] ?? ''));
            $from = $f ?: $monthStart->modify('-11 months');
            $to   = $t ?: $today;
            if ($from > $to) { [$from, $to] = [$to, $from]; }
            // five years is plenty, and keeps a typo like 1026 from asking for a millennium
            if ($from < $to->modify('-5 years')) { $from = $to->modify('-5 years'); }
            break;
        default:    $from = $monthStart->modify('-11 months'); $to = $today;
    }

    // The period before, the same length. Whole calendar months compare with whole
    // calendar months, so October is set against September, not "the 31 days before".
    $wholeMonths = $from->format('j') === '1' && $to->format('Y-m-d') === $to->modify('last day of this month')->format('Y-m-d');
    if ($wholeMonths) {
        $months = ((int)$to->format('Y') - (int)$from->format('Y')) * 12
                + (int)$to->format('n') - (int)$from->format('n') + 1;
        $prevFrom = $from->modify("-$months months");
        $prevTo   = $from->modify('-1 day');
    } else {
        $days     = (int)$from->diff($to)->days + 1;
        $prevTo   = $from->modify('-1 day');
        $prevFrom = $prevTo->modify('-' . ($days - 1) . ' days');
    }

    $span = (int)$from->diff($to)->days + 1;
    $unit = $span <= 92 ? 'week' : ($span <= 1100 ? 'month' : 'quarter');

    return [
        'preset' => $preset, 'module' => $module, 'today' => $today,
        'from' => $from, 'to' => $to, 'prev_from' => $prevFrom, 'prev_to' => $prevTo,
        'label' => rpt_day($from) . ' – ' . rpt_day($to),
        'prev_label' => rpt_day($prevFrom) . ' – ' . rpt_day($prevTo),
        'unit' => $unit,
        'buckets' => rpt_buckets($from, $to, $unit),
    ];
}

/** The periods a line chart is drawn in: weeks, months or quarters, clipped to the range. */
function rpt_buckets(DateTimeImmutable $from, DateTimeImmutable $to, string $unit): array
{
    $out = [];
    if ($unit === 'week') {
        $start = $from->modify('monday this week');
        if ($start > $from) { $start = $start->modify('-7 days'); }
        $step = '+7 days';
    } elseif ($unit === 'month') {
        $start = $from->modify('first day of this month');
        $step = '+1 month';
    } else {
        $q = intdiv((int)$from->format('n') - 1, 3) * 3 + 1;
        $start = $from->setDate((int)$from->format('Y'), $q, 1);
        $step = '+3 months';
    }
    $multiYear = $from->format('Y') !== $to->format('Y');

    for ($s = $start; $s <= $to; $s = $s->modify($step)) {
        $e = $s->modify($step)->modify('-1 day');
        $a = max($s, $from);
        $b = min($e, $to);
        if ($unit === 'week') {
            $label = $a->format('j M');
            $long  = 'Week of ' . $a->format('j M Y');
        } elseif ($unit === 'month') {
            $label = $multiYear ? $s->format("M ’y") : $s->format('M');
            $long  = $s->format('F Y');
        } else {
            $label = 'Q' . (intdiv((int)$s->format('n') - 1, 3) + 1) . ' ' . $s->format('y');
            $long  = 'Q' . (intdiv((int)$s->format('n') - 1, 3) + 1) . ' ' . $s->format('Y');
        }
        // A bucket cut short by the end of the period (this month, to date) is partial:
        // drawn as if complete it looks like a collapse in business.
        $partial = $e > $to || $s < $from;
        $out[] = ['from' => $a->format('Y-m-d'), 'to' => $b->format('Y-m-d'), 'label' => $label,
                  'long' => $long . ($partial ? ' (' . $a->format('j M') . '–' . $b->format('j M') . ' only)' : ''), 'partial' => $partial];
    }
    return $out;
}

/* =====================================================================
   The data
   ===================================================================== */

/**
 * Every approved, non-voided certificate, both modules, in one shape.
 * All of history, because cover-over-time and renewals need what came before.
 */
function rpt_events(string $module): array
{
    $rows = [];

    if ($module !== 'tracking') {
        $rem  = rpt_has_column('certificates', 'removed_on') ? 'c.removed_on' : 'NULL::date';
        $remR = rpt_has_column('certificates', 'removed_reason') ? 'c.removed_reason' : 'NULL::text';
        foreach (q("SELECT 'governor' AS kind, c.id, c.issue_date AS start_on, c.expiry_date AS end_on,
                           (c.type = 'Renewal') AS renewal, v.reg_no, coalesce(cl.name, '') AS client,
                           v.make, '' AS financier, c.status, $rem AS removed_on, $remR AS removed_reason
                      FROM certificates c
                      JOIN vehicles v ON v.id = c.vehicle_id
                      LEFT JOIN clients cl ON cl.id = c.client_id
                     WHERE c.approval_status = 'approved' AND c.status <> 'voided'")->fetchAll() as $r) {
            $rows[] = $r;
        }
    }

    if ($module !== 'governor' && rpt_has_table('tracking_certificates')) {
        $rem  = rpt_has_column('tracking_certificates', 'removed_on') ? 't.removed_on' : 'NULL::date';
        $remR = rpt_has_column('tracking_certificates', 'removed_reason') ? 't.removed_reason' : 'NULL::text';
        foreach (q("SELECT 'tracking' AS kind, t.id, t.subscription_date AS start_on, t.expiry_date AS end_on,
                           (t.renewed_from IS NOT NULL) AS renewal, t.reg_no, t.client_name AS client,
                           t.make_model AS make, t.financier, t.status,
                           $rem AS removed_on, $remR AS removed_reason
                      FROM tracking_certificates t
                     WHERE t.approval_status = 'approved' AND t.status <> 'voided'")->fetchAll() as $r) {
            $rows[] = $r;
        }
    }

    foreach ($rows as &$r) {
        $r['plate']   = rpt_plate((string)$r['reg_no']);
        $r['vkey']    = $r['kind'] . ':' . $r['plate'];
        $r['renewal'] = $r['renewal'] === true || $r['renewal'] === 't' || $r['renewal'] === 1 || $r['renewal'] === '1';
        $r['start_on'] = (string)$r['start_on'];
        $r['end_on']   = (string)$r['end_on'];
        $r['removed_on'] = $r['removed_on'] !== null ? (string)$r['removed_on'] : null;
    }
    unset($r);
    return $rows;
}

/** Throughput of the approvals desk, per module, for a period. */
function rpt_ops(string $table, string $from, string $to): array
{
    $voided = "count(*) FILTER (WHERE status = 'voided' AND issued_at::date BETWEEN ? AND ?)";
    $r = q("SELECT
              count(*) FILTER (WHERE issued_at::date BETWEEN ? AND ?) AS raised,
              count(*) FILTER (WHERE approval_status = 'approved' AND approved_at::date BETWEEN ? AND ?) AS approved,
              count(*) FILTER (WHERE approval_status = 'rejected' AND issued_at::date BETWEEN ? AND ?) AS rejected,
              $voided AS voided,
              count(*) FILTER (WHERE approval_status = 'pending') AS pending_now,
              max(current_date - issued_at::date) FILTER (WHERE approval_status = 'pending') AS oldest,
              percentile_cont(0.5) WITHIN GROUP (ORDER BY extract(epoch FROM (approved_at - issued_at)) / 86400.0)
                  FILTER (WHERE approved_at IS NOT NULL AND approved_at::date BETWEEN ? AND ?) AS median_days
            FROM $table",
        [$from, $to, $from, $to, $from, $to, $from, $to, $from, $to])->fetch();
    return array_map(static fn($v) => $v === null ? null : (float)$v, $r);
}

/**
 * Everything a report shows, worked out once.
 */
function rpt_build(array $P): array
{
    $from = $P['from']->format('Y-m-d');
    $to   = $P['to']->format('Y-m-d');
    $pf   = $P['prev_from']->format('Y-m-d');
    $pt   = $P['prev_to']->format('Y-m-d');
    $today = $P['today']->format('Y-m-d');
    $module = $P['module'];
    $hasTracking = rpt_has_table('tracking_certificates');
    $kinds = $module === 'all' ? ($hasTracking ? ['governor', 'tracking'] : ['governor']) : [$module];

    $events = rpt_events($module);

    // ---- per-vehicle history, sorted by start, for renewals and cover
    $byVehicle = [];
    foreach ($events as $i => $ev) {
        $byVehicle[$ev['vkey']][] = $i;
    }
    foreach ($byVehicle as &$list) {
        usort($list, static fn($a, $b) => strcmp($events[$a]['start_on'], $events[$b]['start_on']));
    }
    unset($list);

    // ---- work done in a period
    $work = static function (string $a, string $b) use ($events): array {
        $out = ['governor' => ['fit' => 0, 'ren' => 0], 'tracking' => ['fit' => 0, 'ren' => 0]];
        foreach ($events as $ev) {
            if ($ev['start_on'] >= $a && $ev['start_on'] <= $b) {
                $out[$ev['kind']][$ev['renewal'] ? 'ren' : 'fit']++;
            }
        }
        return $out;
    };
    $now  = $work($from, $to);
    $prev = $work($pf, $pt);
    $tot  = static fn(array $w, string $k) => $w['governor'][$k] + $w['tracking'][$k];

    // ---- vehicles covered on a given day
    $coveredOn = static function (string $day) use ($events): array {
        $seen = ['governor' => [], 'tracking' => []];
        foreach ($events as $ev) {
            if ($ev['start_on'] <= $day && $ev['end_on'] >= $day
                && ($ev['removed_on'] === null || $ev['removed_on'] > $day)) {
                $seen[$ev['kind']][$ev['plate']] = true;
            }
        }
        return ['governor' => count($seen['governor']), 'tracking' => count($seen['tracking'])];
    };
    $coverEnd   = $coveredOn(min($to, $today));
    $coverStart = $coveredOn($P['from']->modify('-1 day')->format('Y-m-d'));

    // ---- renewal: of the certificates whose cover ended in a window, how many
    //      were followed by another for the same vehicle. Units taken off a vehicle
    //      are left out - nobody renews a tracker that is no longer fitted.
    $renewalStats = static function (string $a, string $b) use ($events, $byVehicle, $today): array {
        $due = 0; $renewed = 0; $open = 0;
        $b = min($b, $today);
        foreach ($byVehicle as $list) {
            foreach ($list as $pos => $i) {
                $ev = $events[$i];
                if ($ev['end_on'] < $a || $ev['end_on'] > $b) { continue; }
                if ($ev['removed_on'] !== null) { continue; }
                $due++;
                $next = $list[$pos + 1] ?? null;
                if ($next !== null) { $renewed++; } else { $open++; }
            }
        }
        return ['due' => $due, 'renewed' => $renewed, 'open' => $open,
                'rate' => rpt_pct($renewed, $due)];
    };
    $ren     = $renewalStats($from, $to);
    $renPrev = $renewalStats($pf, $pt);

    // ---- the line series
    $labels = []; $long = [];
    $sInstall = ['governor' => [], 'tracking' => []];
    $sCover   = ['governor' => [], 'tracking' => []];
    $sRenew   = [];
    foreach ($P['buckets'] as $bk) {
        $labels[] = $bk['label'];
        $long[]   = $bk['long'];
        $w = $work($bk['from'], $bk['to']);
        foreach (['governor', 'tracking'] as $k) {
            $sInstall[$k][] = $w[$k]['fit'] + $w[$k]['ren'];
        }
        if ($bk['from'] <= $today) {
            $c = $coveredOn(min($bk['to'], $today));
            $sCover['governor'][] = $c['governor'];
            $sCover['tracking'][] = $c['tracking'];
            $rs = $renewalStats($bk['from'], $bk['to']);
            $sRenew[] = $rs['due'] > 0 ? round($rs['rate'], 1) : null;
        } else {
            $sCover['governor'][] = null; $sCover['tracking'][] = null; $sRenew[] = null;
        }
    }

    // ---- mixes for the period
    $inPeriod = array_values(array_filter($events, static fn($ev) => $ev['start_on'] >= $from && $ev['start_on'] <= $to));

    $finCount = []; $finName = [];
    foreach ($inPeriod as $ev) {
        if ($ev['kind'] !== 'tracking') { continue; }
        $key = rpt_financier_key((string)$ev['financier']);
        $finCount[$key] = ($finCount[$key] ?? 0) + 1;
        $spelled = trim((string)preg_replace('/\s+/', ' ', (string)$ev['financier']));
        if ($key !== '') { $finName[$key][$spelled] = ($finName[$key][$spelled] ?? 0) + 1; }
    }
    $finPrev = [];
    foreach ($events as $ev) {
        if ($ev['kind'] === 'tracking' && $ev['start_on'] >= $pf && $ev['start_on'] <= $pt) {
            $k = rpt_financier_key((string)$ev['financier']);
            $finPrev[$k] = ($finPrev[$k] ?? 0) + 1;
        }
    }
    arsort($finCount);
    $financiers = [];
    foreach ($finCount as $key => $n) {
        if ($key === '') {
            $name = 'Not financed';
        } else {
            arsort($finName[$key]);
            $name = (string)array_key_first($finName[$key]);
        }
        $financiers[] = ['name' => $name, 'n' => $n, 'prev' => $finPrev[$key] ?? 0, 'key' => $key];
    }

    $makeCount = [];
    foreach ($inPeriod as $ev) {
        $m = rpt_make((string)$ev['make']);
        $makeCount[$m === '' ? '' : $m] = ($makeCount[$m === '' ? '' : $m] ?? 0) + 1;
    }
    arsort($makeCount);
    $makes = [];
    foreach ($makeCount as $m => $n) {
        $makes[] = ['name' => $m === '' ? 'Not recorded' : ucwords(strtolower((string)$m)), 'n' => $n, 'blank' => $m === ''];
    }

    $clients = [];
    foreach ($inPeriod as $ev) {
        $key = strtoupper(trim((string)$ev['client']));
        if ($key === '') { continue; }
        $clients[$key] ??= ['name' => trim((string)$ev['client']), 'governor' => 0, 'tracking' => 0, 'n' => 0];
        $clients[$key][$ev['kind']]++;
        $clients[$key]['n']++;
    }
    usort($clients, static fn($a, $b) => [$b['n'], $a['name']] <=> [$a['n'], $b['name']]);
    $clientCount = count($clients);
    $top = array_slice($clients, 0, 10);
    $topShare = 0;
    foreach (array_slice($clients, 0, 5) as $c) { $topShare += $c['n']; }

    // ---- the renewal pipeline: the certificate in force on each vehicle today,
    //      and the month its cover ends, for the next six months
    $current = [];
    foreach ($byVehicle as $vkey => $list) {
        $last = null;
        foreach ($list as $i) {
            if ($events[$i]['start_on'] <= $today) { $last = $i; }
        }
        if ($last === null) { continue; }
        $ev = $events[$last];
        if ($ev['removed_on'] !== null || $ev['status'] === 'removed') { continue; }
        // a renewal already issued for a future start means this one is taken care of
        $hasFuture = false;
        foreach ($list as $i) { if ($events[$i]['start_on'] > $today) { $hasFuture = true; } }
        if ($hasFuture) { continue; }
        $current[] = $ev;
    }
    $pipe = []; $pipeLabels = []; $pipeLong = [];
    $m0 = $P['today']->modify('first day of this month');
    for ($i = 0; $i < 6; $i++) {
        $ms = $m0->modify("+$i months");
        $pipeLabels[] = $ms->format('M');
        $pipeLong[]   = $ms->format('F Y');
        $pipe['governor'][$i] = 0; $pipe['tracking'][$i] = 0;
    }
    $due30 = 0; $lapsed90 = 0;
    $in30 = $P['today']->modify('+30 days')->format('Y-m-d');
    $ago90 = $P['today']->modify('-90 days')->format('Y-m-d');
    foreach ($current as $ev) {
        if ($ev['end_on'] >= $today) {
            $idx = ((int)substr($ev['end_on'], 0, 4) - (int)$m0->format('Y')) * 12
                 + (int)substr($ev['end_on'], 5, 2) - (int)$m0->format('n');
            if ($idx >= 0 && $idx < 6) { $pipe[$ev['kind']][$idx]++; }
            if ($ev['end_on'] <= $in30) { $due30++; }
        } elseif ($ev['end_on'] >= $ago90) {
            $lapsed90++;
        }
    }

    // ---- removals in the period, by reason
    $removals = []; $removedN = 0; $removedPrev = 0;
    foreach ($events as $ev) {
        if ($ev['removed_on'] === null) { continue; }
        if ($ev['removed_on'] >= $pf && $ev['removed_on'] <= $pt) { $removedPrev++; }
        if ($ev['removed_on'] < $from || $ev['removed_on'] > $to) { continue; }
        $removedN++;
        $reason = trim((string)$ev['removed_reason']);
        $rk = strtoupper($reason === '' ? 'Not given' : $reason);
        $removals[$rk] ??= ['reason' => $reason === '' ? 'Not given' : $reason, 'governor' => 0, 'tracking' => 0, 'n' => 0];
        $removals[$rk][$ev['kind']]++;
        $removals[$rk]['n']++;
    }
    usort($removals, static fn($a, $b) => $b['n'] <=> $a['n']);

    // ---- the approvals desk
    $ops = [];
    if (in_array('governor', $kinds, true)) {
        $ops['Speed governor'] = rpt_ops('certificates', $from, $to);
    }
    if (in_array('tracking', $kinds, true) && $hasTracking) {
        $ops['Tracker installation'] = rpt_ops('tracking_certificates', $from, $to);
    }

    $R = [
        'P' => $P, 'kinds' => $kinds, 'hasTracking' => $hasTracking,
        'work' => $now, 'workPrev' => $prev,
        'installs' => $tot($now, 'fit') + $tot($now, 'ren'),
        'installsPrev' => $tot($prev, 'fit') + $tot($prev, 'ren'),
        'fittings' => $tot($now, 'fit'), 'fittingsPrev' => $tot($prev, 'fit'),
        'renewals' => $tot($now, 'ren'), 'renewalsPrev' => $tot($prev, 'ren'),
        'coverEnd' => $coverEnd, 'coverStart' => $coverStart,
        'ren' => $ren, 'renPrev' => $renPrev,
        'labels' => $labels, 'long' => $long,
        'partial' => array_column($P['buckets'], 'partial'),
        'lastPartial' => in_array(true, array_column($P['buckets'], 'partial'), true),
        'sInstall' => $sInstall, 'sCover' => $sCover, 'sRenew' => $sRenew,
        'financiers' => $financiers, 'makes' => $makes,
        'clients' => $top, 'clientCount' => $clientCount, 'top5Share' => $topShare,
        'pipe' => $pipe, 'pipeLabels' => $pipeLabels, 'pipeLong' => $pipeLong,
        'due30' => $due30, 'lapsed90' => $lapsed90,
        'removals' => $removals, 'removed' => $removedN, 'removedPrev' => $removedPrev,
        'ops' => $ops,
    ];
    $R['findings'] = rpt_findings($R);
    return $R;
}

/* =====================================================================
   The analysis: what a reader should take away, in plain sentences
   ===================================================================== */

function rpt_findings(array $R): array
{
    $out = [];
    $k = $R['kinds'];
    $both = count($k) > 1;

    // 1. volume, and what drove the change
    $ch = rpt_change($R['installs'], $R['installsPrev']);
    if ($R['installs'] === 0 && $R['installsPrev'] === 0) {
        $out[] = 'No certificates were issued in this period or the one before it.';
    } else {
        $s = '<b>' . $R['installs'] . '</b> certificate' . ($R['installs'] === 1 ? '' : 's') . ' issued';
        if ($ch !== null) {
            $s .= ', ' . ($ch >= 0 ? 'up ' : 'down ') . '<b>' . number_format(abs($ch)) . '%</b> on the previous period ('
                . $R['installsPrev'] . ')';
        } elseif ($R['installsPrev'] === 0) {
            $s .= ', against none in the previous period';
        }
        if ($both) {
            $dg = ($R['work']['governor']['fit'] + $R['work']['governor']['ren'])
                - ($R['workPrev']['governor']['fit'] + $R['workPrev']['governor']['ren']);
            $dt = ($R['work']['tracking']['fit'] + $R['work']['tracking']['ren'])
                - ($R['workPrev']['tracking']['fit'] + $R['workPrev']['tracking']['ren']);
            if ($dg !== 0 || $dt !== 0) {
                $lead = abs($dt) >= abs($dg) ? ['trackers', $dt] : ['speed governors', $dg];
                $s .= '. Most of the change came from ' . $lead[0] . ' (' . ($lead[1] >= 0 ? '+' : '') . $lead[1] . ')';
            }
        }
        $out[] = $s . '.';
    }

    // 2. new business versus repeat business
    if ($R['installs'] > 0) {
        $newShare = rpt_pct($R['fittings'], $R['installs']);
        $out[] = '<b>' . rpt_fmt_pct($newShare) . '</b> of the work was new installations (' . $R['fittings']
            . '), the rest renewals (' . $R['renewals'] . ').'
            . ($newShare !== null && $newShare < 30 ? ' The business is leaning on existing customers; new sales are thin.' : '');
    }

    // 3. fleet growth
    $endT = $R['coverEnd']['governor'] + $R['coverEnd']['tracking'];
    $startT = $R['coverStart']['governor'] + $R['coverStart']['tracking'];
    if ($endT > 0 || $startT > 0) {
        $d = $endT - $startT;
        $out[] = 'The covered fleet ' . ($d > 0 ? 'grew by <b>' . $d . '</b>' : ($d < 0 ? 'shrank by <b>' . abs($d) . '</b>' : 'held steady'))
            . ' vehicles over the period, to <b>' . $endT . '</b>.';
    }

    // 4. retention
    if ($R['ren']['due'] > 0) {
        $s = '<b>' . rpt_fmt_pct($R['ren']['rate']) . '</b> of cover that ended in the period was renewed ('
            . $R['ren']['renewed'] . ' of ' . $R['ren']['due'] . ')';
        if ($R['renPrev']['rate'] !== null) {
            $pts = $R['ren']['rate'] - $R['renPrev']['rate'];
            if (abs($pts) >= 1) {
                $s .= ', ' . ($pts > 0 ? 'up ' : 'down ') . number_format(abs($pts)) . ' points';
            }
        }
        $s .= '. <b>' . $R['ren']['open'] . '</b> vehicle' . ($R['ren']['open'] === 1 ? ' is' : 's are') . ' still unrenewed.';
        $out[] = $s;
    }

    // 5. financier concentration
    if (in_array('tracking', $k, true) && $R['financiers']) {
        $total = array_sum(array_column($R['financiers'], 'n'));
        $firstFinanced = null;
        foreach ($R['financiers'] as $f) { if ($f['key'] !== '') { $firstFinanced = $f; break; } }
        if ($firstFinanced) {
            $out[] = '<b>' . e($firstFinanced['name']) . '</b> is the largest financier, behind <b>'
                . rpt_fmt_pct(rpt_pct($firstFinanced['n'], $total)) . '</b> of tracker certificates ('
                . $firstFinanced['n'] . ' of ' . $total . ').';
        }
    }

    // 6. customer concentration
    if ($R['clientCount'] >= 5 && $R['installs'] > 0) {
        $share = rpt_pct($R['top5Share'], $R['installs']);
        $out[] = 'The top five customers account for <b>' . rpt_fmt_pct($share) . '</b> of the work, out of '
            . $R['clientCount'] . ' customers served.'
            . ($share !== null && $share >= 60 ? ' That is a concentration risk: losing one would show.' : '');
    }

    // 7. what is coming
    if ($R['due30'] > 0 || $R['lapsed90'] > 0) {
        $out[] = '<b>' . $R['due30'] . '</b> vehicle' . ($R['due30'] === 1 ? '' : 's') . ' come up for renewal in the next 30 days'
            . ($R['lapsed90'] > 0 ? ', and <b>' . $R['lapsed90'] . '</b> lapsed in the last 90 days without renewing — the easiest sales to win back' : '')
            . '.';
    }

    return $out;
}

/* =====================================================================
   Charts (SVG, server-side)
   ===================================================================== */

/**
 * A line chart. $series is a list of ['name' => .., 'color' => .., 'values' => [int|float|null]].
 * $fmt is 'int' or 'pct'. Nulls break the line rather than dropping it to zero.
 */
function rpt_line(array $labels, array $long, array $series, string $fmt = 'int', string $aria = '',
                  array $partial = []): string
{
    $W = 720; $H = 250; $L = 44; $Rt = 64; $T = 14; $B = 30;
    $n = count($labels);
    $pw = $W - $L - $Rt; $ph = $H - $T - $B;

    $max = 0.0;
    foreach ($series as $s) { foreach ($s['values'] as $v) { if ($v !== null) { $max = max($max, (float)$v); } } }
    $yMax = $fmt === 'pct' ? 100.0 : rpt_nice_max($max);
    $fx = static fn(int $i) => $n <= 1 ? $L + $pw / 2 : $L + $pw * $i / ($n - 1);
    $fy = static fn(float $v) => $T + $ph - $ph * ($v / $yMax);
    $fv = static fn($v) => $v === null ? '—' : ($fmt === 'pct' ? number_format((float)$v) . '%' : (string)(int)$v);

    $svg = '<svg class="rpt-svg" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($aria) . '">';

    // grid: four solid hairlines and the baseline
    for ($i = 0; $i <= 4; $i++) {
        $v = $yMax * $i / 4;
        $y = round($fy($v), 1);
        $svg .= '<line x1="' . $L . '" x2="' . ($W - $Rt + 8) . '" y1="' . $y . '" y2="' . $y . '" class="'
              . ($i === 0 ? 'rpt-base' : 'rpt-grid') . '"/>'
              . '<text x="' . ($L - 8) . '" y="' . ($y + 4) . '" text-anchor="end" class="rpt-tick">'
              . ($fmt === 'pct' ? (int)$v . '%' : (int)$v) . '</text>';
    }

    // x labels: thin them out when there are many
    $every = max(1, (int)ceil($n / 12));
    for ($i = 0; $i < $n; $i++) {
        if ($i % $every !== 0 && $i !== $n - 1) { continue; }
        if ($i !== $n - 1 && $n - 1 - $i < $every) { continue; }   // keep the last label clear
        $svg .= '<text x="' . round($fx($i), 1) . '" y="' . ($H - 9) . '" text-anchor="middle" class="rpt-tick">'
              . e($labels[$i]) . '</text>';
    }

    // Nothing to draw: say so, rather than lay flat lines along the axis.
    $any = false;
    foreach ($series as $s) { foreach ($s['values'] as $v) { if ($v !== null && $v != 0) { $any = true; } } }
    if (!$any) {
        return $svg . '<text x="' . ($L + $pw / 2) . '" y="' . ($T + $ph / 2) . '" text-anchor="middle" class="rpt-empty">'
             . 'No data in this period</text></svg>';
    }

    // the lines: solid between complete periods, dashed into a period that is only
    // partly inside the range (this month so far, or a week cut by the start date)
    $ends = [];
    foreach ($series as $s) {
        $d = ''; $dash = ''; $prev = null; $lastI = null;
        foreach ($s['values'] as $i => $v) {
            if ($v === null) { $prev = null; continue; }
            $pt = round($fx($i), 1) . ' ' . round($fy((float)$v), 1);
            if ($prev === null) {
                $d .= ' M ' . $pt;
            } elseif (!empty($partial[$i]) || !empty($partial[$prev])) {
                $dash .= ' M ' . round($fx($prev), 1) . ' ' . round($fy((float)$s['values'][$prev]), 1) . ' L ' . $pt;
                $d .= ' M ' . $pt;
            } else {
                $d .= ' L ' . $pt;
            }
            $prev = $i; $lastI = $i;
        }
        if ($lastI === null) { continue; }
        $svg .= '<path d="' . trim($d) . '" fill="none" stroke="' . $s['color'] . '" stroke-width="2"'
              . ' stroke-linejoin="round" stroke-linecap="round"/>';
        if ($dash !== '') {
            $svg .= '<path d="' . trim($dash) . '" fill="none" stroke="' . $s['color'] . '" stroke-width="2"'
                  . ' stroke-dasharray="3 4" stroke-linecap="round" opacity=".8"/>';
        }
        // a lone point would be invisible without a marker
        foreach ($s['values'] as $i => $v) {
            $prevNull = ($s['values'][$i - 1] ?? null) === null;
            $nextNull = ($s['values'][$i + 1] ?? null) === null;
            if ($v !== null && $prevNull && $nextNull && $i !== $lastI) {
                $svg .= '<circle cx="' . round($fx($i), 1) . '" cy="' . round($fy((float)$v), 1) . '" r="3" fill="' . $s['color'] . '"/>';
            }
        }
        $ends[] = ['x' => $fx($lastI), 'y' => $fy((float)$s['values'][$lastI]), 'v' => $s['values'][$lastI], 'c' => $s['color']];
    }

    // endpoint markers and direct labels, nudged apart if they collide
    usort($ends, static fn($a, $b) => $a['y'] <=> $b['y']);
    foreach ($ends as $i => $p) {
        $ends[$i]['ly'] = $i === 0 ? $p['y'] : max($p['y'], $ends[$i - 1]['ly'] + 14);
    }
    foreach ($ends as $p) {
        $svg .= '<circle cx="' . round($p['x'], 1) . '" cy="' . round($p['y'], 1) . '" r="4.5" fill="' . $p['c'] . '" stroke="#fff" stroke-width="2"/>'
              . '<text x="' . round($p['x'] + 10, 1) . '" y="' . round(($p['ly'] ?? $p['y']) + 4, 1) . '" class="rpt-end">'
              . $fv($p['v']) . '</text>';
    }

    // hover layer: one column per point, with the guide line the script moves
    $svg .= '<line class="rpt-guide" x1="0" x2="0" y1="' . $T . '" y2="' . ($T + $ph) . '" visibility="hidden"/>';
    $step = $n <= 1 ? $pw : $pw / ($n - 1);
    for ($i = 0; $i < $n; $i++) {
        $tip = '<b>' . e($long[$i]) . '</b>';
        foreach ($series as $s) {
            $tip .= '<span><i style="background:' . $s['color'] . '"></i>' . e($s['name']) . '<em>' . $fv($s['values'][$i]) . '</em></span>';
        }
        $x = $fx($i) - $step / 2;
        $svg .= '<rect class="rpt-hit" x="' . round(max($L - 6, $x), 1) . '" y="' . $T . '" width="' . round($step, 1)
              . '" height="' . $ph . '" data-x="' . round($fx($i), 1) . '" data-tip="' . e($tip) . '"/>';
    }

    return $svg . '</svg>';
}

/**
 * A ring chart with a 2px gap between segments. $slices: [['name','n','color'], ...].
 * Past six segments the tail folds into "Other": a seventh hue is never invented.
 */
function rpt_fold(array $items, string $nameKey = 'name', int $keep = 5): array
{
    $out = []; $other = 0; $i = 0;
    foreach ($items as $it) {
        if ($i < $keep || count($items) === $keep + 1) {
            $out[] = ['name' => $it[$nameKey], 'n' => (int)$it['n'], 'color' => RPT_SLOTS[$i] ?? RPT_OTHER];
            $i++;
        } else {
            $other += (int)$it['n'];
        }
    }
    if ($other > 0) {
        $out[] = ['name' => 'Other', 'n' => $other, 'color' => RPT_OTHER];
    }
    return $out;
}

function rpt_donut(array $slices, string $centre, string $caption): string
{
    $size = 180; $thick = 24.0;
    $c = $size / 2; $r = ($size - $thick) / 2;
    $total = array_sum(array_column($slices, 'n'));

    $svg = '<svg class="rpt-ring" viewBox="0 0 ' . $size . ' ' . $size . '" role="img" aria-label="' . e($caption) . '">';
    if ($total <= 0) {
        $svg .= '<circle cx="' . $c . '" cy="' . $c . '" r="' . $r . '" fill="none" stroke="#E8EDF2" stroke-width="' . $thick . '"/>';
    } else {
        $live = array_values(array_filter($slices, static fn($s) => $s['n'] > 0));
        $gap = count($live) > 1 ? 2.0 / $r : 0.0;      // 2px of surface between segments
        $a = -M_PI / 2;
        foreach ($live as $s) {
            $sweep = $s['n'] / $total * 2 * M_PI;
            $tip = '<b>' . e($s['name']) . '</b><span>' . $s['n'] . ' · ' . number_format($s['n'] / $total * 100, 1) . '%</span>';
            if (count($live) === 1) {
                $svg .= '<circle cx="' . $c . '" cy="' . $c . '" r="' . $r . '" fill="none" stroke="' . $s['color']
                      . '" stroke-width="' . $thick . '" class="rpt-seg" data-tip="' . e($tip) . '"/>';
                break;
            }
            $a1 = $a + $gap / 2; $a2 = $a + $sweep - $gap / 2;
            if ($a2 > $a1) {
                $x1 = $c + $r * cos($a1); $y1 = $c + $r * sin($a1);
                $x2 = $c + $r * cos($a2); $y2 = $c + $r * sin($a2);
                $svg .= '<path d="M ' . round($x1, 2) . ' ' . round($y1, 2) . ' A ' . $r . ' ' . $r . ' 0 '
                      . ($a2 - $a1 > M_PI ? 1 : 0) . ' 1 ' . round($x2, 2) . ' ' . round($y2, 2) . '" fill="none" stroke="'
                      . $s['color'] . '" stroke-width="' . $thick . '" class="rpt-seg" data-tip="' . e($tip) . '"/>';
            }
            $a += $sweep;
        }
    }
    $svg .= '<text x="' . $c . '" y="' . ($c + 3) . '" text-anchor="middle" class="rpt-ring-n">' . e($centre) . '</text>'
          . '<text x="' . $c . '" y="' . ($c + 21) . '" text-anchor="middle" class="rpt-ring-cap">' . e($caption) . '</text>';
    return $svg . '</svg>';
}

/** The key beside a ring: swatch, name, count and share. */
function rpt_key(array $slices): string
{
    $total = array_sum(array_column($slices, 'n'));
    $h = '<ul class="rpt-key">';
    foreach ($slices as $s) {
        $h .= '<li><i style="background:' . $s['color'] . '"></i><span>' . e($s['name']) . '</span>'
            . '<em>' . $s['n'] . '</em><small>' . ($total ? number_format($s['n'] / $total * 100) . '%' : '') . '</small></li>';
    }
    return $h . '</ul>';
}

/** Stacked columns, two series, for the renewal pipeline. */
function rpt_columns(array $labels, array $long, array $series): string
{
    $W = 720; $H = 220; $L = 44; $Rt = 14; $T = 18; $B = 30;
    $n = count($labels); $pw = $W - $L - $Rt; $ph = $H - $T - $B;
    $max = 0;
    for ($i = 0; $i < $n; $i++) {
        $sum = 0; foreach ($series as $s) { $sum += $s['values'][$i]; }
        $max = max($max, $sum);
    }
    $yMax = rpt_nice_max($max);
    $step = $pw / max($n, 1); $bw = min(46.0, $step * 0.5);

    $svg = '<svg class="rpt-svg" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Renewals due by month">';
    for ($i = 0; $i <= 4; $i++) {
        $v = $yMax * $i / 4; $y = round($T + $ph - $ph * $v / $yMax, 1);
        $svg .= '<line x1="' . $L . '" x2="' . ($W - $Rt) . '" y1="' . $y . '" y2="' . $y . '" class="' . ($i ? 'rpt-grid' : 'rpt-base') . '"/>'
              . '<text x="' . ($L - 8) . '" y="' . ($y + 4) . '" text-anchor="end" class="rpt-tick">' . (int)$v . '</text>';
    }
    for ($i = 0; $i < $n; $i++) {
        $x = $L + $step * $i + ($step - $bw) / 2;
        $base = $T + $ph; $sum = 0;
        $tip = '<b>' . e($long[$i]) . '</b>';
        $live = [];
        foreach ($series as $s) {
            $tip .= '<span><i style="background:' . $s['color'] . '"></i>' . e($s['name']) . '<em>' . (int)$s['values'][$i] . '</em></span>';
            if ($s['values'][$i] > 0) { $live[] = $s; }
        }
        foreach ($live as $j => $s) {
            $h = $ph * $s['values'][$i] / $yMax;
            $top = $j === count($live) - 1;
            $gap = $j > 0 ? 2 : 0;
            $y = $base - $h;
            $hh = max(0.0, $h - $gap);
            if ($top) {
                $r = min(4.0, $hh, $bw / 2);
                $svg .= '<path d="M ' . round($x, 1) . ' ' . round($y + $hh, 1) . ' V ' . round($y + $r, 1)
                      . ' Q ' . round($x, 1) . ' ' . round($y, 1) . ' ' . round($x + $r, 1) . ' ' . round($y, 1)
                      . ' H ' . round($x + $bw - $r, 1) . ' Q ' . round($x + $bw, 1) . ' ' . round($y, 1) . ' ' . round($x + $bw, 1) . ' ' . round($y + $r, 1)
                      . ' V ' . round($y + $hh, 1) . ' Z" fill="' . $s['color'] . '"/>';
            } else {
                $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bw, 1) . '" height="' . round($hh, 1) . '" fill="' . $s['color'] . '"/>';
            }
            $sum += $s['values'][$i];
            $base = $y;
        }
        if ($sum > 0) {
            $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . round($base - 6, 1) . '" text-anchor="middle" class="rpt-end">' . $sum . '</text>';
        }
        $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($H - 9) . '" text-anchor="middle" class="rpt-tick">' . e($labels[$i]) . '</text>'
              . '<rect class="rpt-hit rpt-hit-col" x="' . round($L + $step * $i, 1) . '" y="' . $T . '" width="' . round($step, 1) . '" height="' . $ph . '" data-tip="' . e($tip) . '"/>';
    }
    return $svg . '</svg>';
}

/** A legend row for the multi-series charts. */
function rpt_legend(array $series): string
{
    $h = '<div class="rpt-legend">';
    foreach ($series as $s) {
        $h .= '<span><i style="background:' . $s['color'] . '"></i>' . e($s['name']) . '</span>';
    }
    return $h . '</div>';
}

/** The same numbers as a chart, as a table: for screen readers, and for anyone who wants the figure. */
function rpt_data_table(array $long, array $series, string $fmt = 'int'): string
{
    $h = '<details class="rpt-data"><summary>View as table</summary><div class="rpt-scroll"><table><tr><th>Period</th>';
    foreach ($series as $s) { $h .= '<th class="num">' . e($s['name']) . '</th>'; }
    $h .= '</tr>';
    foreach ($long as $i => $l) {
        $h .= '<tr><td>' . e($l) . '</td>';
        foreach ($series as $s) {
            $v = $s['values'][$i];
            $h .= '<td class="num">' . ($v === null ? '—' : ($fmt === 'pct' ? number_format((float)$v, 1) . '%' : (int)$v)) . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</table></div></details>';
}

/* =====================================================================
   The report body - shared by the page and the PDF
   ===================================================================== */

/** A headline figure with its change on the previous period. */
function rpt_kpi(string $label, string $value, ?float $change, bool $upIsGood, string $foot, string $changeUnit = '%'): string
{
    $delta = '';
    if ($change !== null && abs($change) >= 0.5) {
        $good = ($change > 0) === $upIsGood;
        $arrow = $change > 0 ? '▲' : '▼';
        $delta = '<span class="rpt-delta ' . ($good ? 'good' : 'bad') . '">' . $arrow . ' '
               . number_format(abs($change)) . $changeUnit . '<span class="sr"> ' . ($good ? 'better' : 'worse') . '</span></span>';
    } elseif ($change !== null) {
        $delta = '<span class="rpt-delta flat">no change</span>';
    }
    return '<div class="rpt-kpi"><span class="rpt-kpi-label">' . e($label) . '</span>'
         . '<div class="rpt-kpi-row"><b>' . $value . '</b>' . $delta . '</div>'
         . '<span class="rpt-kpi-foot">' . $foot . '</span></div>';
}

function rpt_body(array $R, bool $pdf = false): string
{
    $P = $R['P'];
    $k = $R['kinds'];
    $both = count($k) > 1;
    $hasT = in_array('tracking', $k, true);
    $hasG = in_array('governor', $k, true);
    $vs = 'vs ' . e($P['prev_label']);
    ob_start();
    ?>
<section class="rpt-findings rpt-card">
  <h2>Key findings</h2>
  <?php if ($R['findings']): ?>
    <ul><?php foreach ($R['findings'] as $f): ?><li><?= $f ?></li><?php endforeach; ?></ul>
  <?php else: ?>
    <p class="rpt-muted">Nothing to report for this period yet.</p>
  <?php endif; ?>
</section>

<div class="rpt-kpis">
  <?php
  $coverEnd = $R['coverEnd']['governor'] + $R['coverEnd']['tracking'];
  $coverStart = $R['coverStart']['governor'] + $R['coverStart']['tracking'];
  $ren = $R['ren']; $rp = $R['renPrev'];
  echo rpt_kpi('Certificates issued', (string)$R['installs'], rpt_change($R['installs'], $R['installsPrev']), true,
      'Previous: ' . $R['installsPrev']);
  echo rpt_kpi('New installations', (string)$R['fittings'], rpt_change($R['fittings'], $R['fittingsPrev']), true,
      'Previous: ' . $R['fittingsPrev']);
  echo rpt_kpi('Renewals', (string)$R['renewals'], rpt_change($R['renewals'], $R['renewalsPrev']), true,
      'Previous: ' . $R['renewalsPrev']);
  echo rpt_kpi('Renewal rate', rpt_fmt_pct($ren['rate']),
      ($ren['rate'] !== null && $rp['rate'] !== null) ? $ren['rate'] - $rp['rate'] : null, true,
      $ren['renewed'] . ' of ' . $ren['due'] . ' due · previous ' . rpt_fmt_pct($rp['rate']), ' pts');
  echo rpt_kpi('Vehicles covered', (string)$coverEnd, rpt_change($coverEnd, $coverStart), true,
      'At period end · ' . $coverStart . ' at start');
  echo rpt_kpi('Units removed', (string)$R['removed'], rpt_change($R['removed'], $R['removedPrev']), false,
      'Previous: ' . $R['removedPrev']);
  ?>
</div>

<?php
$seriesInstall = []; $seriesCover = [];
if ($hasG) {
    $seriesInstall[] = ['name' => 'Speed governor', 'color' => RPT_GOV, 'values' => $R['sInstall']['governor']];
    $seriesCover[]   = ['name' => 'Speed governor', 'color' => RPT_GOV, 'values' => $R['sCover']['governor']];
}
if ($hasT) {
    $seriesInstall[] = ['name' => 'Tracker', 'color' => RPT_TRK, 'values' => $R['sInstall']['tracking']];
    $seriesCover[]   = ['name' => 'Tracker', 'color' => RPT_TRK, 'values' => $R['sCover']['tracking']];
}
$unitWord = ['week' => 'week', 'month' => 'month', 'quarter' => 'quarter'][$P['unit']];
?>

<h2 class="rpt-h">Growth</h2>
<div class="rpt-grid two">
  <section class="rpt-card">
    <h3>Certificates issued per <?= $unitWord ?></h3>
    <p class="rpt-cap">New installations and renewals, counted on the day cover starts.<?=
      $R['lastPartial'] ? ' Dashed lines lead into a ' . $unitWord . ' only partly inside the period, so it is not a fair comparison yet.' : '' ?></p>
    <?= count($seriesInstall) > 1 ? rpt_legend($seriesInstall) : '' ?>
    <?= rpt_line($R['labels'], $R['long'], $seriesInstall, 'int', 'Certificates issued per ' . $unitWord, $R['partial']) ?>
    <?= $pdf ? '' : rpt_data_table($R['long'], $seriesInstall) ?>
  </section>
  <section class="rpt-card">
    <h3>Vehicles under cover</h3>
    <p class="rpt-cap">How many vehicles held valid cover at the end of each <?= $unitWord ?>. This is the size of the business.</p>
    <?= count($seriesCover) > 1 ? rpt_legend($seriesCover) : '' ?>
    <?= rpt_line($R['labels'], $R['long'], $seriesCover, 'int', 'Vehicles under cover') ?>
    <?= $pdf ? '' : rpt_data_table($R['long'], $seriesCover) ?>
  </section>
</div>

<h2 class="rpt-h">Mix</h2>
<div class="rpt-grid three">
  <?php if ($hasT && $R['hasTracking']):
      $fin = rpt_fold($R['financiers']);
      $finTotal = array_sum(array_column($fin, 'n')); ?>
  <section class="rpt-card">
    <h3>Trackers by financier</h3>
    <p class="rpt-cap">Share of tracker certificates issued in the period. Spellings of one bank are grouped.</p>
    <div class="rpt-ring-wrap"><?= rpt_donut($fin, (string)$finTotal, 'trackers') ?></div>
    <?= $fin ? rpt_key($fin) : '<p class="rpt-muted">No tracker certificates in this period.</p>' ?>
  </section>
  <?php endif; ?>

  <section class="rpt-card">
    <h3>New work or repeat work</h3>
    <p class="rpt-cap">New installations are growth; renewals are customers kept.</p>
    <?php
    $mix = [];
    if ($hasG) {
        $mix[] = ['name' => 'Governor · new',     'n' => $R['work']['governor']['fit'], 'color' => RPT_SLOTS[0]];
        $mix[] = ['name' => 'Governor · renewal', 'n' => $R['work']['governor']['ren'], 'color' => RPT_SLOTS[2]];
    }
    if ($hasT) {
        $mix[] = ['name' => 'Tracker · new',      'n' => $R['work']['tracking']['fit'], 'color' => RPT_SLOTS[1]];
        $mix[] = ['name' => 'Tracker · renewal',  'n' => $R['work']['tracking']['ren'], 'color' => RPT_SLOTS[3]];
    }
    ?>
    <div class="rpt-ring-wrap"><?= rpt_donut($mix, (string)$R['installs'], 'issued') ?></div>
    <?= rpt_key($mix) ?>
  </section>

  <section class="rpt-card">
    <h3>Vehicle makes</h3>
    <p class="rpt-cap">What the customers drive, from certificates issued in the period.</p>
    <?php
    // "Not recorded" is not a make, so it never takes a colour slot: it joins Other.
    $named = array_values(array_filter($R['makes'], static fn($m) => !$m['blank']));
    $blank = array_sum(array_column(array_filter($R['makes'], static fn($m) => $m['blank']), 'n'));
    $mk = rpt_fold($named);
    if ($blank > 0) {
        $last = end($mk);
        if ($last && $last['name'] === 'Other') { $mk[count($mk) - 1]['n'] += $blank; $mk[count($mk) - 1]['name'] = 'Other / not recorded'; }
        else { $mk[] = ['name' => 'Not recorded', 'n' => $blank, 'color' => RPT_OTHER]; }
    }
    ?>
    <div class="rpt-ring-wrap"><?= rpt_donut($mk, (string)array_sum(array_column($mk, 'n')), 'vehicles') ?></div>
    <?= $mk ? rpt_key($mk) : '<p class="rpt-muted">Nothing issued in this period.</p>' ?>
  </section>
</div>

<?php if ($hasT && $R['financiers']): ?>
<section class="rpt-card rpt-keep">
  <h3>Financiers in detail</h3>
  <p class="rpt-cap">Every financier, with the previous period beside it. A bank that has gone quiet is a relationship to call.</p>
  <div class="rpt-scroll"><table class="rpt-table">
    <tr><th>Financier</th><th class="num">Trackers</th><th class="num">Share</th><th class="num">Previous</th><th class="num">Change</th></tr>
    <?php $ft = array_sum(array_column($R['financiers'], 'n'));
    foreach ($R['financiers'] as $f): $d = $f['n'] - $f['prev']; ?>
      <tr><td><?= e($f['name']) ?></td><td class="num"><?= $f['n'] ?></td>
          <td class="num"><?= rpt_fmt_pct(rpt_pct($f['n'], $ft), 1) ?></td>
          <td class="num"><?= $f['prev'] ?></td>
          <td class="num <?= $d > 0 ? 'pos' : ($d < 0 ? 'neg' : '') ?>"><?= $d > 0 ? '+' . $d : ($d < 0 ? '−' . abs($d) : '0') ?></td></tr>
    <?php endforeach; ?>
  </table></div>
</section>
<?php endif; ?>

<h2 class="rpt-h">Customers</h2>
<section class="rpt-card rpt-keep">
  <h3>Top customers this period</h3>
  <p class="rpt-cap">
    <?= $R['clientCount'] ?> customer<?= $R['clientCount'] === 1 ? '' : 's' ?> served. Tracker customers are matched on the name as typed,
    so one company spelled two ways appears twice.
  </p>
  <?php if ($R['clients']):
      $cmax = max(array_column($R['clients'], 'n')); ?>
    <?= $both ? rpt_legend([['name' => 'Speed governor', 'color' => RPT_GOV], ['name' => 'Tracker', 'color' => RPT_TRK]]) : '' ?>
    <div class="rpt-bars">
      <?php foreach ($R['clients'] as $i => $c): ?>
        <div class="rpt-bar-row">
          <span class="rpt-bar-name"><span class="rpt-rank"><?= $i + 1 ?></span><?= e($c['name']) ?></span>
          <span class="rpt-bar-track">
            <?php if ($hasG && $c['governor']): ?><span class="rpt-bar-fill" style="width:<?= round($c['governor'] / $cmax * 100, 2) ?>%;background:<?= RPT_GOV ?>" data-tip="<?= e('<b>' . e($c['name']) . '</b><span>Speed governor<em>' . $c['governor'] . '</em></span>') ?>"></span><?php endif; ?>
            <?php if ($hasT && $c['tracking']): ?><span class="rpt-bar-fill" style="width:<?= round($c['tracking'] / $cmax * 100, 2) ?>%;background:<?= RPT_TRK ?>" data-tip="<?= e('<b>' . e($c['name']) . '</b><span>Tracker<em>' . $c['tracking'] . '</em></span>') ?>"></span><?php endif; ?>
          </span>
          <span class="rpt-bar-val"><b><?= $c['n'] ?></b> <small><?= rpt_fmt_pct(rpt_pct($c['n'], $R['installs'])) ?></small></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="rpt-muted">No customers served in this period.</p>
  <?php endif; ?>
</section>

<h2 class="rpt-h">Retention</h2>
<div class="rpt-grid two">
  <section class="rpt-card">
    <h3>Renewal rate</h3>
    <p class="rpt-cap">
      Of the cover that ended each <?= $unitWord ?>, the share that was renewed. Units taken off a vehicle are left out.
      The latest <?= $unitWord ?>s can still rise as late renewals arrive.
    </p>
    <?= rpt_line($R['labels'], $R['long'], [['name' => 'Renewal rate', 'color' => RPT_SLOTS[2], 'values' => $R['sRenew']]], 'pct', 'Renewal rate', $R['partial']) ?>
    <?= $pdf ? '' : rpt_data_table($R['long'], [['name' => 'Renewal rate', 'values' => $R['sRenew']]], 'pct') ?>
  </section>
  <section class="rpt-card">
    <h3>Renewals coming up</h3>
    <p class="rpt-cap">
      Vehicles whose cover ends in each of the next six months, from today. This is the sales pipeline:
      <b><?= $R['due30'] ?></b> due within 30 days<?= $R['lapsed90'] ? ', <b>' . $R['lapsed90'] . '</b> lapsed in the last 90' : '' ?>.
    </p>
    <?php
    $pipeSeries = [];
    if ($hasG) { $pipeSeries[] = ['name' => 'Speed governor', 'color' => RPT_GOV, 'values' => $R['pipe']['governor']]; }
    if ($hasT) { $pipeSeries[] = ['name' => 'Tracker', 'color' => RPT_TRK, 'values' => $R['pipe']['tracking']]; }
    ?>
    <?= count($pipeSeries) > 1 ? rpt_legend($pipeSeries) : '' ?>
    <?= rpt_columns($R['pipeLabels'], $R['pipeLong'], $pipeSeries) ?>
    <?= $pdf ? '' : rpt_data_table($R['pipeLong'], $pipeSeries) ?>
  </section>
</div>

<h2 class="rpt-h">Operations</h2>
<div class="rpt-grid two">
  <section class="rpt-card rpt-keep">
    <h3>Getting certificates out</h3>
    <p class="rpt-cap">The approvals desk in this period. "Typical wait" is the median time from raised to released.</p>
    <div class="rpt-scroll"><table class="rpt-table">
      <tr><th></th><?php foreach ($R['ops'] as $name => $o): ?><th class="num"><?= e($name) ?></th><?php endforeach; ?></tr>
      <?php
      $rows = [
          'Raised' => 'raised', 'Approved' => 'approved', 'Rejected' => 'rejected', 'Voided' => 'voided',
          'Waiting now' => 'pending_now', 'Oldest waiting' => 'oldest', 'Typical wait' => 'median_days',
      ];
      foreach ($rows as $label => $key): ?>
        <tr><td><?= $label ?></td>
        <?php foreach ($R['ops'] as $o): $v = $o[$key]; ?>
          <td class="num"><?php
            if ($key === 'median_days') {
                echo $v === null ? '—' : ($v < 0.5 ? 'same day' : number_format($v, 1) . ' days');
            } elseif ($key === 'oldest') {
                echo $v === null ? '—' : '<span class="' . ($v > 7 ? 'neg' : '') . '">' . (int)$v . ' days</span>';
            } else {
                echo (int)$v;
            }
          ?></td>
        <?php endforeach; ?></tr>
      <?php endforeach; ?>
    </table></div>
  </section>

  <section class="rpt-card rpt-keep">
    <h3>Units removed</h3>
    <p class="rpt-cap">Governors and trackers taken off vehicles in the period, by the reason recorded.</p>
    <?php if ($R['removals']): ?>
      <div class="rpt-scroll"><table class="rpt-table">
        <tr><th>Reason</th><?php if ($both): ?><th class="num">Governor</th><th class="num">Tracker</th><?php endif; ?><th class="num">Total</th></tr>
        <?php foreach ($R['removals'] as $r): ?>
          <tr><td><?= e($r['reason']) ?></td>
            <?php if ($both): ?><td class="num"><?= $r['governor'] ?></td><td class="num"><?= $r['tracking'] ?></td><?php endif; ?>
            <td class="num"><b><?= $r['n'] ?></b></td></tr>
        <?php endforeach; ?>
      </table></div>
    <?php else: ?>
      <p class="rpt-muted">No units were removed in this period.</p>
    <?php endif; ?>
  </section>
</div>

<?php if (!$pdf): /* the PDF carries this in its header, where it costs no page */ ?>
<p class="rpt-note">
  Only approved certificates count; voided ones are excluded throughout. The system records payment references,
  not amounts, so this report measures volume rather than revenue.
</p>
<?php endif; ?>
<?php
    return (string)ob_get_clean();
}

/** Styles for the report body. One sheet for the page and the PDF. */
function rpt_css(): string
{
    return <<<'CSS'
.rpt { --ink:#1C2430; --body:#3D4756; --muted:#5C6775; --faint:#8793A3; --line:#E4EBF2; --soft:#F6F9FC;
       --navy:#0D4F72; --green:#8CC63F; --good:#2E7D32; --bad:#B42318; color: var(--body); }
.rpt h2.rpt-h { display:flex; align-items:center; gap:12px; margin: 28px 0 12px; font-size: 13px; font-weight: 700;
                letter-spacing: .7px; text-transform: uppercase; color: var(--muted); }
.rpt h2.rpt-h::after { content:""; flex:1; height:1px; background: var(--line); }
.rpt-card { background:#fff; border:1px solid var(--line); border-radius:14px; padding:18px 20px; min-width:0;
            box-shadow: 0 1px 2px rgba(13,79,114,.04), 0 6px 20px rgba(13,79,114,.05); }
.rpt-card h3 { margin:0 0 4px; font-size:15px; font-weight:700; color:var(--ink); letter-spacing:-.1px; text-transform:none; }
.rpt-cap { margin:0 0 12px; font-size:12.5px; line-height:1.5; color:var(--muted); }
.rpt-muted { color: var(--faint); font-size: 13px; margin: 8px 0 0; }
.rpt-grid { display:grid; gap:16px; grid-template-columns: 1fr; margin: 0 0 16px; }
@media (min-width: 980px) { .rpt-grid.two { grid-template-columns: 1fr 1fr; } }
@media (min-width: 900px) { .rpt-grid.three { grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); } }
.rpt-keep { margin: 0 0 16px; }

.rpt-findings { border-left: 4px solid var(--green); margin: 0 0 16px; }
.rpt-findings h2 { margin:0 0 8px; font-size:15px; font-weight:700; color:var(--ink); text-transform:none; letter-spacing:0; }
.rpt-findings ul { margin:0; padding:0 0 0 18px; }
.rpt-findings li { margin: 0 0 6px; font-size: 14px; line-height: 1.55; color: var(--body); }
.rpt-findings li b { color: var(--ink); font-variant-numeric: tabular-nums; }

.rpt-kpis { display:grid; gap:12px; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); margin: 0 0 8px; }
.rpt-kpi { background:#fff; border:1px solid var(--line); border-radius:14px; padding:14px 16px;
           box-shadow: 0 1px 2px rgba(13,79,114,.04), 0 6px 20px rgba(13,79,114,.05); }
.rpt-kpi-label { display:block; font-size:12px; font-weight:600; color:var(--muted); }
.rpt-kpi-row { display:flex; align-items:baseline; gap:8px; flex-wrap:wrap; margin: 4px 0 2px; }
.rpt-kpi-row b { font-size:28px; font-weight:700; color:var(--ink); line-height:1.1; font-variant-numeric: tabular-nums; letter-spacing:-.5px; }
.rpt-kpi-foot { display:block; font-size:11.5px; color:var(--faint); line-height:1.4; }
.rpt-delta { font-size:12px; font-weight:700; padding:2px 7px; border-radius:999px; white-space:nowrap; font-variant-numeric: tabular-nums; }
.rpt-delta.good { color: var(--good); background:#EAF5EA; }
.rpt-delta.bad  { color: var(--bad);  background:#FDECEA; }
.rpt-delta.flat { color: var(--muted); background: var(--soft); font-weight:600; }
.sr { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); }

.rpt-svg { display:block; width:100%; height:auto; overflow:visible; }
.rpt-svg .rpt-grid { stroke:#EEF2F6; stroke-width:1; }
.rpt-svg .rpt-base { stroke:#CBD5E0; stroke-width:1; }
.rpt-svg .rpt-tick { font-size:11px; fill:#8793A3; font-variant-numeric: tabular-nums; }
.rpt-svg .rpt-empty { font-size:13px; fill:#8793A3; paint-order:stroke; stroke:#fff; stroke-width:8px; stroke-linejoin:round; }
.rpt-svg .rpt-end  { font-size:12px; font-weight:700; fill:#1C2430; font-variant-numeric: tabular-nums; }
.rpt-svg .rpt-guide { stroke:#9AA7B6; stroke-width:1; }
.rpt-svg .rpt-hit { fill: transparent; cursor: crosshair; }
.rpt-svg .rpt-hit-col:hover { fill: rgba(13,79,114,.04); }

.rpt-legend { display:flex; flex-wrap:wrap; gap:6px 16px; margin: 0 0 8px; font-size:12.5px; color: var(--body); }
.rpt-legend span { display:inline-flex; align-items:center; gap:7px; }
.rpt-legend i { width:14px; height:3px; border-radius:2px; display:inline-block; }

.rpt-ring-wrap { display:flex; justify-content:center; margin: 4px 0 12px; }
.rpt-ring { width: 170px; height: 170px; }
.rpt-ring .rpt-seg { cursor: default; transition: opacity .15s ease; }
.rpt-ring:hover .rpt-seg { opacity: .55; }
.rpt-ring .rpt-seg:hover { opacity: 1; }
.rpt-ring-n { font-size: 30px; font-weight: 700; fill: #1C2430; font-variant-numeric: tabular-nums; }
.rpt-ring-cap { font-size: 11px; fill: #8793A3; letter-spacing: .5px; text-transform: uppercase; }
.rpt-key { list-style:none; margin:0; padding:0; }
.rpt-key li { display:grid; grid-template-columns: 10px 1fr auto 40px; align-items:center; gap:9px;
              padding: 6px 0; border-top: 1px solid #F0F3F7; font-size: 13px; }
.rpt-key li:first-child { border-top: 0; }
.rpt-key i { width:10px; height:10px; border-radius:3px; }
.rpt-key span { color: var(--body); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.rpt-key em { font-style:normal; font-weight:700; color: var(--ink); font-variant-numeric: tabular-nums; }
.rpt-key small { text-align:right; color: var(--faint); font-variant-numeric: tabular-nums; }

.rpt-bars { display:grid; gap:9px; }
.rpt-bar-row { display:grid; grid-template-columns: minmax(120px, 34%) 1fr 78px; align-items:center; gap:12px; font-size:13px; }
.rpt-bar-name { display:flex; align-items:center; gap:8px; color: var(--ink); overflow:hidden; white-space:nowrap; text-overflow:ellipsis; }
.rpt-rank { flex:none; width:20px; height:20px; border-radius:6px; background: var(--soft); color: var(--muted);
            font-size:11px; font-weight:700; display:inline-flex; align-items:center; justify-content:center; }
.rpt-bar-track { display:flex; gap:2px; height:10px; }
.rpt-bar-fill { height:100%; border-radius: 0 4px 4px 0; min-width: 3px; }
.rpt-bar-fill:first-child:not(:last-child) { border-radius: 0; }
.rpt-bar-val { text-align:right; font-variant-numeric: tabular-nums; }
.rpt-bar-val b { color: var(--ink); }
.rpt-bar-val small { color: var(--faint); margin-left: 4px; }
@media (max-width: 560px) { .rpt-bar-row { grid-template-columns: 1fr 64px; } .rpt-bar-track { grid-column: 1 / -1; grid-row: 2; } }

.rpt-scroll { overflow-x:auto; }
.rpt table.rpt-table, .rpt details table { width:100%; border-collapse:collapse; margin:0; box-shadow:none; border:0; border-radius:0; background:transparent; font-size:13px; }
.rpt .rpt-table th, .rpt details th { text-align:left; padding:8px 10px; font-size:11.5px; font-weight:700; letter-spacing:.3px;
              color: var(--muted); background: var(--soft); border-bottom:1px solid var(--line); text-transform:none; }
.rpt .rpt-table td, .rpt details td { padding:8px 10px; border-top:1px solid #F0F3F7; color: var(--body); font-weight:400; }
.rpt .rpt-table td:first-child { color: var(--ink); font-weight:500; }
.rpt .num, .rpt .rpt-table th.num, .rpt details th.num { text-align:right; font-variant-numeric: tabular-nums; }
.rpt .pos { color: var(--good); font-weight:600; }
.rpt .neg { color: var(--bad); font-weight:600; }
.rpt-data { margin-top: 10px; }
.rpt-data summary { cursor:pointer; font-size:12.5px; color: var(--navy); font-weight:600; }
.rpt-data table { margin-top: 8px !important; }
.rpt-note { margin: 20px 0 0; font-size: 12px; color: var(--faint); line-height: 1.5; }

.rpt-tip { position: fixed; z-index: 3000; pointer-events: none; min-width: 150px; max-width: 260px;
           background: #1C2430; color: #fff; border-radius: 9px; padding: 9px 11px; font-size: 12.5px; line-height: 1.45;
           box-shadow: 0 8px 24px rgba(0,0,0,.18); }
.rpt-tip b { display:block; margin-bottom: 4px; font-weight: 600; }
.rpt-tip span { display:flex; align-items:center; gap:7px; }
.rpt-tip i { width:9px; height:9px; border-radius:2px; flex:none; }
.rpt-tip em { margin-left:auto; padding-left: 14px; font-style:normal; font-weight:700; font-variant-numeric: tabular-nums; }
CSS;
}

/** Hover tooltips and the crosshair. Plain script, no library. */
function rpt_js(): string
{
    return <<<'JS'
(function () {
  var tip = document.createElement('div');
  tip.className = 'rpt-tip'; tip.hidden = true;
  document.body.appendChild(tip);
  function place(ev) {
    var x = ev.clientX + 14, y = ev.clientY + 14;
    var w = tip.offsetWidth, h = tip.offsetHeight;
    if (x + w > window.innerWidth - 8) { x = ev.clientX - w - 14; }
    if (y + h > window.innerHeight - 8) { y = ev.clientY - h - 14; }
    tip.style.left = x + 'px'; tip.style.top = y + 'px';
  }
  document.addEventListener('mouseover', function (ev) {
    var t = ev.target.closest && ev.target.closest('[data-tip]');
    if (!t || !t.closest('.rpt')) { return; }
    tip.innerHTML = t.getAttribute('data-tip'); tip.hidden = false; place(ev);
    if (t.hasAttribute('data-x')) {
      var g = t.ownerSVGElement && t.ownerSVGElement.querySelector('.rpt-guide');
      if (g) { g.setAttribute('x1', t.getAttribute('data-x')); g.setAttribute('x2', t.getAttribute('data-x')); g.setAttribute('visibility', 'visible'); }
    }
  });
  document.addEventListener('mousemove', function (ev) { if (!tip.hidden) { place(ev); } });
  document.addEventListener('mouseout', function (ev) {
    var t = ev.target.closest && ev.target.closest('[data-tip]');
    if (!t) { return; }
    tip.hidden = true;
    var g = t.ownerSVGElement && t.ownerSVGElement.querySelector('.rpt-guide');
    if (g) { g.setAttribute('visibility', 'hidden'); }
  });
})();
JS;
}
