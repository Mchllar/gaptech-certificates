<?php
/**
 * The client portal, built around vehicles rather than certificates.
 *
 * Goes in public/client_portal.php  (replaces what is there)
 *
 * A fleet client does not open this page to browse certificates. They open it to
 * find out which of their vehicles needs something from them today. So:
 *
 *   - one row per VEHICLE, not per certificate;
 *   - sorted by the date their cover runs out, soonest first, always;
 *   - counts across the top that double as filters, action states first;
 *   - search on the registration, matched on letters and digits only;
 *   - older certificates folded into the row, opened on click;
 *   - Renew on the row itself, so nobody picks their vehicle from a list.
 *
 * Everything is read from the certificates the client already owns - no new
 * columns, no new queries anywhere else. Vehicles are grouped on letters and
 * digits only, so the same plate stored as "KCC 003C" and "KCC003C" is one row.
 *
 * FLASH: if your layout already prints flash messages, delete the block marked
 * FLASH below, or you will see each message twice.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require APP_DIR . '/views/layout.php';

$client = require_client();

$today  = date('Y-m-d');
$window = max(0, (int)setting('early_renewal_days', 60));

$search = trim((string)($_GET['q'] ?? ''));
$view   = (string)($_GET['view'] ?? 'attention');
if (!in_array($view, ['all', 'attention', 'pending', 'scheduled', 'valid'], true)) {
    $view = 'all';
}
// A search looks across the whole fleet. Searching inside the current filter is
// how someone types their plate, gets nothing back, and concludes the vehicle is
// missing - when it is only sitting under a different chip.
if ($search !== '') {
    $view = 'all';
}

$perPage = (int)($_GET['per'] ?? 25);
if (!in_array($perPage, [10, 25, 50, 100], true)) {
    $perPage = 25;
}
$page = max(1, (int)($_GET['page'] ?? 1));

// ---------------------------------------------------------------- the fleet
//
// Every certificate this client owns, newest first, then grouped in PHP. A
// client with sixty vehicles and three years of history is under two hundred
// rows - small enough to group here, and far easier to follow than the SQL
// that would do the same job.

$rows = q("SELECT c.*, v.reg_no,
                  regexp_replace(upper(v.reg_no), '[^A-Z0-9]', '', 'g') AS plate_key
           FROM certificates c
           JOIN vehicles v ON v.id = c.vehicle_id
           WHERE c.client_id = ?
           ORDER BY c.issue_date DESC, c.id DESC", [$client['id']])->fetchAll();

$fleet = [];

foreach ($rows as $r) {
    $key = (string)$r['plate_key'];

    if (!isset($fleet[$key])) {
        $fleet[$key] = [
            'key'       => $key,
            'reg_no'    => $r['reg_no'],
            'current'   => null,   // in force today
            'pending'   => null,   // paid for, waiting on accounts
            'scheduled' => null,   // approved, starts later
            'latest'    => null,   // whatever is newest, for the rejected note
            'last_cover'=> null,   // the newest certificate that was ever in force
            'approved'  => false,  // has ever had a certificate issued at all
            'history'   => [],
        ];
    }

    $v = &$fleet[$key];

    $approved = $r['approval_status'] === 'approved' && $r['status'] === 'issued';
    $started  = strtotime((string)$r['issue_date'])  <= strtotime($today);
    $running  = strtotime((string)$r['expiry_date']) >= strtotime($today);

    if ($v['latest'] === null) {
        $v['latest'] = $r;
    }
    if ($r['approval_status'] === 'approved') {
        $v['approved'] = true;

        // The newest certificate that actually started. A renewal waiting to be
        // confirmed is NOT this - its expiry is a year away, and putting that on
        // the row would tell the client their expired vehicle is covered to 2027.
        if ($started && $v['last_cover'] === null) {
            $v['last_cover'] = $r;
        }
    }

    if ($approved && $started && $running && $v['current'] === null) {
        $v['current'] = $r;
    } elseif ($approved && !$started && $v['scheduled'] === null) {
        $v['scheduled'] = $r;
    } elseif ($r['approval_status'] === 'pending' && $r['status'] !== 'voided'
              && $v['pending'] === null) {
        // A voided certificate is not waiting for anything. Counting one as
        // pending is what would keep a vehicle locked behind an order that has
        // already been cancelled.
        $v['pending'] = $r;
    } else {
        $v['history'][] = $r;
    }

    unset($v);
}

// ------------------------------------------------------- what each row says
//
// One state per vehicle, chosen by what the client would have to do about it.

foreach ($fleet as $key => $v) {
    $cover = $v['current']['expiry_date'] ?? null;
    $days  = $cover
        ? (int)floor((strtotime($cover) - strtotime($today)) / 86400)
        : null;

    if ($cover === null && !$v['approved']) {
        // Nothing has ever been issued for this plate - a first application that
        // was turned down, or one still being prepared. "Expired" would be a lie.
        $state = 'none';
    } elseif ($cover === null) {
        $state = 'expired';
    } elseif ($days <= 30) {
        $state = 'expiring';
    } else {
        $state = 'valid';
    }

    // Can they renew it? Not while something is already in the pipe, and not
    // before the window opens.
    $renewable = $v['pending'] === null && $v['scheduled'] === null;
    $opensOn   = null;

    if ($renewable && $cover !== null) {
        $opensOn = date('Y-m-d', strtotime($cover . ' -' . $window . ' days'));
        if (strtotime($today) < strtotime($opensOn)) {
            $renewable = false;
        } else {
            $opensOn = null;
        }
    }

    // The new certificate runs on from the old one without a gap.
    $start = ($cover !== null && strtotime($cover) >= strtotime($today))
        ? date('Y-m-d', strtotime($cover . ' +1 day'))
        : $today;

    $fleet[$key]['state']     = $state;
    $fleet[$key]['cover_to']  = $cover;
    $fleet[$key]['days']      = $days;
    $fleet[$key]['renewable'] = $renewable;
    $fleet[$key]['opens_on']  = $opensOn;
    $fleet[$key]['renew_id']  = (int)($v['current']['id'] ?? $v['latest']['id'] ?? 0);
    $fleet[$key]['start']     = $start;
    $fleet[$key]['new_expiry']= date('Y-m-d', strtotime($start . ' +1 year -1 day'));

    // The sentence the renewal panel shows once this vehicle is chosen.
    $fleet[$key]['cover_line'] = $cover === null
        ? 'Covers you from ' . fmt_date($start) . ' to '
          . fmt_date($fleet[$key]['new_expiry']) . '.'
        : 'Your cover runs to ' . fmt_date($cover)
          . '. The new certificate covers you from ' . fmt_date($start)
          . ' to ' . fmt_date($fleet[$key]['new_expiry'])
          . ', and becomes available to download on ' . fmt_date($start) . '.';
}

/**
 * Does this vehicle want something from the client today?
 *
 * A vehicle whose renewal is already paid for - waiting on accounts, or approved
 * and starting later - is NOT one of them, however soon its current certificate
 * runs out. The client has done their part, and listing it under "needs
 * attention" would have them paying twice.
 */
function needs_attention(array $v): bool
{
    if ($v['pending'] || $v['scheduled']) {
        return false;
    }
    return in_array($v['state'], ['expired', 'expiring', 'none'], true);
}

// ------------------------------------------------------------- the counters
//
// Counted across the whole fleet, before any filter, so the numbers never move
// when the client clicks one.

$counts = ['all' => count($fleet), 'attention' => 0, 'pending' => 0,
           'scheduled' => 0, 'valid' => 0];

foreach ($fleet as $v) {
    if (needs_attention($v))     { $counts['attention']++; }
    if ($v['pending'])           { $counts['pending']++; }
    if ($v['scheduled'])         { $counts['scheduled']++; }
    if ($v['state'] === 'valid') { $counts['valid']++; }
}

// Land them where the work is. A client with nothing expiring opens on the
// whole fleet rather than on an empty page.
if (!isset($_GET['view']) && $counts['attention'] === 0) {
    $view = 'all';
}

// ----------------------------------------------------- filter, sort, paginate

$list = array_values(array_filter($fleet, function (array $v) use ($view, $search) {
    if ($search !== '') {
        $needle = preg_replace('/[^A-Z0-9]/', '', strtoupper($search));
        if ($needle !== '' && strpos($v['key'], $needle) === false) {
            return false;
        }
    }
    switch ($view) {
        case 'attention': return needs_attention($v);
        case 'pending':   return (bool)$v['pending'];
        case 'scheduled': return (bool)$v['scheduled'];
        case 'valid':     return $v['state'] === 'valid';
    }
    return true;
}));

// Soonest to run out first. A vehicle with no cover at all sorts to the very
// top, because that is the one standing still.
usort($list, function (array $a, array $b) {
    $ka = $a['cover_to'] ?? '0000-00-00';
    $kb = $b['cover_to'] ?? '0000-00-00';
    return $ka === $kb ? strcmp($a['reg_no'], $b['reg_no']) : strcmp($ka, $kb);
});

$total = count($list);
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);
$shown = array_slice($list, ($page - 1) * $perPage, $perPage);

/** Keep the current filter and search when linking to another page. */
$link = function (array $changes) use ($view, $search, $perPage, $page): string {
    $args = array_merge(
        ['view' => $view, 'q' => $search, 'per' => $perPage, 'page' => $page],
        $changes
    );
    $args = array_filter($args, fn($x) => $x !== '' && $x !== null);
    return 'client_portal.php?' . http_build_query($args);
};

$chips = [
    'attention' => 'Needs attention',
    'pending'   => 'Awaiting approval',
    'scheduled' => 'Paid, starts later',
    'valid'     => 'In order',
    'all'       => 'All vehicles',
];

layout_top('My Vehicles', 'client');
?>

<!-- FLASH -->
<?php if (!empty($_SESSION['flash_error'])): ?>
  <p class="flash flash-error"><?= e($_SESSION['flash_error']) ?></p>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>
<?php if ($msg = flash()): ?>
  <p class="flash flash-ok"><?= e($msg) ?></p>
<?php endif; ?>
<!-- /FLASH -->

<div id="fleet">

  <header class="fl-head">
    <div>
      <p class="fl-sub"><?= e($client['name']) ?> &middot;
         <?= (int)$counts['all'] ?> vehicle<?= $counts['all'] === 1 ? '' : 's' ?></p>
    </div>

    <form class="fl-search" method="get" action="client_portal.php">
      <input type="hidden" name="per" value="<?= (int)$perPage ?>">
      <label class="fl-sr" for="fl-q">Search by registration</label>
      <input type="search" name="q" id="fl-q" value="<?= e($search) ?>"
             placeholder="Registration, e.g. KCC 003C" autocomplete="off">
      <button type="submit">Search</button>
      <?php if ($search !== ''): ?>
        <a class="fl-clear" href="<?= e($link(['q' => null, 'page' => 1])) ?>">Clear</a>
      <?php endif; ?>
    </form>
  </header>

  <?php if ($search !== ''): ?>
    <p class="fl-searching">Vehicles matching &ldquo;<?= e($search) ?>&rdquo;
       &middot; <a href="client_portal.php">show all my vehicles</a></p>
  <?php else: ?>
  <nav class="fl-chips" aria-label="Filter vehicles">
    <?php foreach ($chips as $k => $label): ?>
      <?php if ($k !== 'all' && $counts[$k] === 0 && $view !== $k) { continue; } ?>
      <a class="fl-chip<?= $view === $k ? ' on' : '' ?> fl-chip-<?= e($k) ?>"
         href="<?= e($link(['view' => $k, 'page' => 1])) ?>">
        <?= e($label) ?><span><?= (int)$counts[$k] ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <?php
    // Renewal is a telephone call, not a button. The price is negotiated and the
    // tracking device has to be confirmed transmitting first, and neither of
    // those is something a web form should be pretending to decide.
    $dueSoon = 0;
    foreach ($fleet as $vv) {
        if ($vv['state'] === 'expired' || $vv['state'] === 'expiring') { $dueSoon++; }
    }
  ?>
  <p class="fl-call">
    <?php if ($dueSoon > 0): ?>
      <strong><?= (int)$dueSoon ?></strong>
      vehicle<?= $dueSoon === 1 ? ' needs' : 's need' ?> renewing.
    <?php endif; ?>
    To renew, call GAPTECH on
    <strong><?= e(setting('cell', '')) ?></strong>
  </p>

  <?php if (!$shown): ?>

    <p class="fl-empty">
      <?php if ($search !== ''): ?>
        No vehicle of yours matches &ldquo;<?= e($search) ?>&rdquo;.
      <?php elseif ($counts['all'] === 0): ?>
        You have no certificates yet. They will appear here once GAPTECH issues
        the first one.
      <?php else: ?>
        Nothing here. <a href="<?= e($link(['view' => 'all', 'page' => 1])) ?>">Show
        all <?= (int)$counts['all'] ?> vehicles</a>.
      <?php endif; ?>
    </p>

  <?php else: ?>

    <table class="fl-table">
      <thead>
        <tr>
          <th scope="col">Vehicle</th>
          <th scope="col">Status</th>
          <th scope="col">Covered until</th>
          <th scope="col" class="fl-right">&nbsp;</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($shown as $v): ?>
        <?php
          $cur  = $v['current'];
          $hist = $v['history'];
          $rid  = 'h-' . preg_replace('/[^A-Za-z0-9]/', '', $v['key']);
        ?>
        <tr class="fl-row fl-row-<?= e($v['state']) ?>">
          <td class="fl-plate">
            <span class="fl-reg"><?= e($v['reg_no']) ?></span>
            <?php if ($hist): ?>
              <button type="button" class="fl-more" aria-expanded="false"
                      aria-controls="<?= e($rid) ?>" data-target="<?= e($rid) ?>">
                <?= count($hist) ?> earlier
              </button>
            <?php endif; ?>
          </td>

          <td class="fl-state">
            <?php if ($v['state'] === 'none'): ?>
              <span class="fl-pill fl-expired">No certificate</span>
            <?php elseif ($v['state'] === 'expired'): ?>
              <span class="fl-pill fl-expired">Expired</span>
            <?php elseif ($v['state'] === 'expiring'): ?>
              <span class="fl-pill fl-expiring">
                <?= $v['days'] === 0 ? 'Expires today' : 'Expires in ' . (int)$v['days'] . ' days' ?>
              </span>
            <?php else: ?>
              <span class="fl-pill fl-valid">Valid</span>
            <?php endif; ?>

            <?php if ($v['pending']): ?>
              <span class="fl-tag fl-tag-pending">Renewal being confirmed</span>
            <?php elseif ($v['scheduled']): ?>
              <span class="fl-tag fl-tag-sched">Renewed &middot; starts
                <?= e(fmt_date($v['scheduled']['issue_date'])) ?></span>
            <?php elseif (($v['latest']['approval_status'] ?? '') === 'rejected'): ?>
              <span class="fl-tag fl-tag-no">Last payment was not accepted &mdash;
                please call the office</span>
            <?php endif; ?>
          </td>

          <td class="fl-when">
            <?php if ($v['cover_to']): ?>
              <?= e(fmt_date($v['cover_to'])) ?>
            <?php elseif ($v['state'] === 'none'): ?>
              <span class="fl-dim">&mdash;</span>
            <?php elseif ($v['last_cover']): ?>
              <span class="fl-dim">ended <?= e(fmt_date($v['last_cover']['expiry_date'])) ?></span>
            <?php else: ?>
              <span class="fl-dim">&mdash;</span>
            <?php endif; ?>
          </td>

          <td class="fl-do fl-right">
            <?php if ($cur && certificate_is_releasable($cur)): ?>
              <a class="fl-link" href="certificate_print.php?id=<?= (int)$cur['id'] ?>">View</a>
              <a class="fl-btn" href="certificate_print.php?id=<?= (int)$cur['id'] ?>&amp;download=1">Download</a>
            <?php endif; ?>

            <?php if ($v['opens_on']): ?>
              <span class="fl-dim fl-soon">Renewable from <?= e(fmt_date($v['opens_on'])) ?></span>
            <?php elseif ($v['renewable']): ?>
              <span class="fl-dim fl-soon">Call us to renew</span>
            <?php endif; ?>
          </td>
        </tr>

        <?php if ($hist): ?>
          <tr class="fl-hist" id="<?= e($rid) ?>" hidden>
            <td colspan="4">
              <p class="fl-hist-head">Earlier certificates for <?= e($v['reg_no']) ?></p>
              <ul class="fl-hist-list">
                <?php foreach ($hist as $h): ?>
                  <li>
                    <span class="fl-hist-no">No. <?= e($h['number'] ?: 'not issued') ?></span>
                    <span class="fl-hist-dates"><?= e(fmt_date($h['issue_date'])) ?>
                      &ndash; <?= e(fmt_date($h['expiry_date'])) ?></span>
                    <span class="fl-hist-state"><?= e(certificate_status($h)) ?></span>
                    <?php if (certificate_is_releasable($h)): ?>
                      <a href="certificate_print.php?id=<?= (int)$h['id'] ?>">View</a>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            </td>
          </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>


    <?php if ($pages > 1 || $total > 10): ?>
      <div class="fl-foot">
        <span class="fl-count">
          <?= (int)(($page - 1) * $perPage + 1) ?>&ndash;<?= (int)min($page * $perPage, $total) ?>
          of <?= (int)$total ?>
        </span>

        <?php if ($pages > 1): ?>
          <span class="fl-pager">
            <?php if ($page > 1): ?>
              <a href="<?= e($link(['page' => $page - 1])) ?>">&larr; Previous</a>
            <?php endif; ?>
            <span>Page <?= (int)$page ?> of <?= (int)$pages ?></span>
            <?php if ($page < $pages): ?>
              <a href="<?= e($link(['page' => $page + 1])) ?>">Next &rarr;</a>
            <?php endif; ?>
          </span>
        <?php endif; ?>

        <form class="fl-per" method="get" action="client_portal.php">
          <input type="hidden" name="view" value="<?= e($view) ?>">
          <input type="hidden" name="q" value="<?= e($search) ?>">
          <label for="fl-per">Show</label>
          <select name="per" id="fl-per" onchange="this.form.submit()">
            <?php foreach ([10, 25, 50, 100] as $n): ?>
              <option value="<?= $n ?>"<?= $n === $perPage ? ' selected' : '' ?>><?= $n ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
    <?php endif; ?>

  <?php endif; ?>
</div>

<style>
  /*
   * Everything is prefixed with #fleet. An id outranks the class selectors in
   * the other stylesheets, so nothing here can be restyled from a distance and
   * nothing here reaches anything else.
   */
  #fleet { max-width: 1040px; }

  #fleet .fl-head {
    display: flex; flex-wrap: wrap; align-items: flex-end;
    justify-content: space-between; gap: 16px; margin: 0 0 18px;
  }
  #fleet h1 { margin: 0; font-size: 24px; font-weight: 700; color: #0D4F72; }
  #fleet .fl-sub { margin: 4px 0 0; font-size: 20px; color: #0D4F72; }

  #fleet .fl-sr {
    position: absolute; width: 1px; height: 1px; overflow: hidden;
    clip: rect(0 0 0 0); white-space: nowrap;
  }
  #fleet .fl-search { display: flex; align-items: center; gap: 8px; margin: 0; }
  #fleet .fl-search input[type="search"] {
    width: 230px; padding: 9px 12px; border: 1px solid #C9D3DC; border-radius: 8px;
    font: inherit; font-size: 14.5px; color: #1C2430; background: #fff;
  }
  #fleet .fl-search input[type="search"]:focus {
    outline: none; border-color: #1890D0; box-shadow: 0 0 0 3px rgba(24,144,208,.16);
  }
  #fleet .fl-search button {
    font: inherit; font-size: 14.5px; font-weight: 600; padding: 9px 16px;
    border: 1px solid #0D4F72; border-radius: 8px; background: #0D4F72;
    color: #fff; cursor: pointer;
  }
  #fleet .fl-clear { font-size: 14px; color: #5C6775; }

  /* The counts. Action states first, and the one in use is filled in. */
  #fleet .fl-chips { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 16px; }
  #fleet .fl-chip {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 7px 14px; border-radius: 999px; border: 1px solid #D7DEE5;
    background: #fff; color: #3D4756; font-size: 14px; text-decoration: none;
  }
  #fleet .fl-chip span {
    font-weight: 700; font-variant-numeric: tabular-nums; color: #5C6775;
  }
  #fleet .fl-chip:hover { border-color: #9FB4C4; }
  #fleet .fl-chip.on { background: #0D4F72; border-color: #0D4F72; color: #fff; }
  #fleet .fl-chip.on span { color: #BFE0F3; }
  #fleet .fl-chip-attention span { color: #C3121A; }
  #fleet .fl-chip-attention.on span { color: #FFD9DA; }

  #fleet .fl-call {
    margin: 0 0 16px; padding: 13px 17px; border-radius: 10px;
    background: #F2F7FB; border: 1px solid #DCE8F2;
    font-size: 14.5px; line-height: 1.55; color: #3D4756;
  }
  #fleet .fl-call strong { color: #0D4F72; }

  #fleet .fl-searching {
    margin: 0 0 16px; font-size: 14.5px; color: #5C6775;
  }
  #fleet .fl-searching a { color: #1890D0; }

  #fleet .fl-empty {
    margin: 0; padding: 28px 22px; background: #fff; border: 1px solid #E4EBF2;
    border-radius: 12px; font-size: 15px; color: #3D4756;
  }

  #fleet .fl-table {
    width: 100%; border-collapse: collapse; background: #fff;
    border: 1px solid #E4EBF2; border-radius: 12px; overflow: hidden;
  }
  #fleet .fl-table th {
    padding: 12px 14px; text-align: left; font-size: 12px; font-weight: 700;
    letter-spacing: .6px; text-transform: uppercase; color: #5C6775;
    background: #F6F9FC; border-bottom: 1px solid #E4EBF2;
  }
  #fleet .fl-table td {
    padding: 13px 14px; font-size: 14.5px; color: #3D4756; vertical-align: middle;
    border-bottom: 1px solid #F0F4F8;
  }
  #fleet .fl-right { text-align: right; }
  #fleet .fl-dim { color: #5C6775; }

  /* A red edge on the vehicles that need something. Colour is the hint, the
     sort order is what actually does the work. */
  #fleet .fl-row-none     td:first-child,
  #fleet .fl-row-expired  td:first-child { box-shadow: inset 3px 0 0 #C3121A; }
  #fleet .fl-row-expiring td:first-child { box-shadow: inset 3px 0 0 #E8A33D; }

  #fleet .fl-reg {
    font-weight: 700; color: #1C2430; letter-spacing: .3px; white-space: nowrap;
  }
  #fleet .fl-more {
    display: block; margin: 3px 0 0; padding: 0; border: 0; background: none;
    font: inherit; font-size: 13px; color: #1890D0; cursor: pointer;
  }
  #fleet .fl-more:hover { text-decoration: underline; }
  #fleet .fl-more::before { content: '\25B8\00a0'; }
  #fleet .fl-more[aria-expanded="true"]::before { content: '\25BE\00a0'; }

  #fleet .fl-pill {
    display: inline-block; padding: 4px 11px; border-radius: 999px;
    font-size: 13px; font-weight: 600; white-space: nowrap;
  }
  #fleet .fl-valid    { background: #E8F5E0; color: #3F7312; }
  #fleet .fl-expiring { background: #FDF1DF; color: #8A5A10; }
  #fleet .fl-expired  { background: #FBE9E9; color: #A50F16; }

  #fleet .fl-tag {
    display: block; margin: 5px 0 0; font-size: 13px; color: #5C6775;
  }
  #fleet .fl-tag-pending::before,
  #fleet .fl-tag-sched::before { content: '\2713\00a0'; color: #1890D0; }
  #fleet .fl-tag-no { color: #A50F16; }

  #fleet .fl-when { white-space: nowrap; font-variant-numeric: tabular-nums; }

  #fleet .fl-nothing {
    margin: 0 0 14px; padding: 12px 16px; border-radius: 10px;
    background: #F2F7FB; border: 1px solid #DCE8F2;
    font-size: 14.5px; line-height: 1.5; color: #3D4756;
  }

  #fleet .fl-do { white-space: nowrap; }
  #fleet .fl-link {
    margin-right: 12px; font-size: 14.5px; color: #1890D0; text-decoration: none;
  }
  #fleet .fl-link:hover { text-decoration: underline; }
  #fleet .fl-btn {
    display: inline-block; font: inherit; font-size: 14px; font-weight: 600;
    padding: 7px 15px; border-radius: 8px; cursor: pointer;
    border: 1px solid #0D4F72; background: #fff; color: #0D4F72;
    text-decoration: none;
  }
  #fleet .fl-btn:hover { background: #0D4F72; color: #fff; }
  #fleet .fl-renew { margin-left: 8px; background: #0D4F72; color: #fff; }
  #fleet .fl-renew:hover { background: #0A4260; }
  #fleet .fl-soon {
    display: inline-block; margin-left: 12px; font-size: 13.5px;
  }

  #fleet .fl-hist td { background: #F8FBFD; }
  #fleet .fl-hist[hidden] { display: none; }
  #fleet .fl-hist-head {
    margin: 0 0 8px; font-size: 13px; font-weight: 700; letter-spacing: .4px;
    text-transform: uppercase; color: #5C6775;
  }
  #fleet .fl-hist-list { margin: 0; padding: 0; list-style: none; }
  #fleet .fl-hist-list li {
    display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px 18px;
    padding: 6px 0; font-size: 14px;
  }
  #fleet .fl-hist-no { font-weight: 600; color: #1C2430; min-width: 120px; }
  #fleet .fl-hist-dates { color: #5C6775; font-variant-numeric: tabular-nums; }
  #fleet .fl-hist-state { color: #5C6775; }
  #fleet .fl-hist-list a { color: #1890D0; }

  #fleet .fl-foot {
    display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
    gap: 12px; margin: 14px 2px 0; font-size: 14px; color: #5C6775;
  }
  #fleet .fl-pager { display: flex; align-items: center; gap: 14px; }
  #fleet .fl-pager a { color: #1890D0; text-decoration: none; }
  #fleet .fl-per { display: flex; align-items: center; gap: 8px; margin: 0; }
  #fleet .fl-per select {
    font: inherit; font-size: 14px; padding: 5px 8px;
    border: 1px solid #C9D3DC; border-radius: 6px; background: #fff;
  }

  @media (max-width: 760px) {
    #fleet .fl-search { width: 100%; }
    #fleet .fl-search input[type="search"] { flex: 1; width: auto; }
    #fleet .fl-table thead { display: none; }
    #fleet .fl-table tr { display: block; }
    #fleet .fl-table td {
      display: block; border: 0; padding: 4px 14px;
    }
    #fleet .fl-row td:first-child { padding-top: 14px; box-shadow: none; }
    #fleet .fl-row { border-bottom: 1px solid #E4EBF2; padding-bottom: 12px; }
    #fleet .fl-row-expired  { border-left: 3px solid #C3121A; }
    #fleet .fl-row-expiring { border-left: 3px solid #E8A33D; }
    #fleet .fl-right { text-align: left; }
    #fleet .fl-when::before { content: 'Covered until '; color: #5C6775; }
    #fleet .fl-do { padding-top: 10px; }
  }
</style>

<script>
(function () {
  var fleet = document.getElementById('fleet');
  if (!fleet) { return; }

  // Older certificates, folded away until asked for.
  fleet.addEventListener('click', function (ev) {
    var more = ev.target.closest ? ev.target.closest('.fl-more') : null;
    if (!more) { return; }
    var row = document.getElementById(more.getAttribute('data-target'));
    if (!row) { return; }
    var show = row.hidden;
    row.hidden = !show;
    more.setAttribute('aria-expanded', show ? 'true' : 'false');
  });

})();
</script>

<?php layout_bottom(); ?>