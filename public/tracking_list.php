<?php
/**
 * Tracking installation certificates: search, filter, paged list, what expires soon.
 *
 * Goes in public/tracking_list.php
 *
 * Both roles see this page. For accounts the "Pending" filter is their queue - there
 * is no separate approvals page for this module, because approving happens on the
 * certificate itself where the details are in front of them.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/tracking.php';
require APP_DIR . '/views/layout.php';

$user  = require_staff();
$isAdm = $user['role'] === 'administrator';

$search = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? 'all');
if (!in_array($status, ['all', 'pending', 'valid', 'expired', 'superseded', 'voided', 'rejected'], true)) {
    $status = 'all';
}
$perPage = (int)($_GET['per'] ?? 10);
if (!in_array($perPage, [10, 25, 50, 100], true)) {
    $perPage = 10;
}
$page = max(1, (int)($_GET['page'] ?? 1));

// Accounts land on their queue rather than on everything, unless they ask otherwise.
if (!$isAdm && !isset($_GET['status'])) {
    $status = 'pending';
}

// Build the filter once and use it for both the count and the page of rows.
$where  = [];
$params = [];

if ($search !== '') {
    // Registration is matched on letters and digits only, so KDF745B finds KDF 745B.
    $where[] = "(regexp_replace(upper(t.reg_no), '[^A-Z0-9]', '', 'g') LIKE ?
                 OR t.client_name ILIKE ? OR t.imei LIKE ? OR CAST(t.number AS text) LIKE ?)";
    $params[] = '%' . plate_key($search) . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

if ($status === 'pending') {
    $where[] = "t.approval_status = 'pending'";
} elseif ($status === 'rejected') {
    $where[] = "t.approval_status = 'rejected'";
} elseif ($status === 'valid') {
    $where[] = "t.approval_status = 'approved' AND t.status = 'issued' AND t.expiry_date >= current_date";
} elseif ($status === 'expired') {
    $where[] = "t.approval_status = 'approved' AND t.status = 'issued' AND t.expiry_date < current_date";
} elseif ($status === 'superseded') {
    $where[] = "t.status = 'superseded'";
} elseif ($status === 'voided') {
    $where[] = "t.status = 'voided'";
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total  = (int)q("SELECT count(*) FROM tracking_certificates t $whereSql", $params)->fetchColumn();
$pages  = max(1, (int)ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

$rows = q("SELECT t.* FROM tracking_certificates t $whereSql
           ORDER BY t.issued_at DESC LIMIT $perPage OFFSET $offset", $params)->fetchAll();

$counts = q("SELECT
    count(*) AS all_count,
    count(*) FILTER (WHERE approval_status = 'pending') AS pending_count,
    count(*) FILTER (WHERE approval_status = 'rejected') AS rejected_count,
    count(*) FILTER (WHERE approval_status = 'approved' AND status = 'issued'
                     AND expiry_date >= current_date) AS valid_count,
    count(*) FILTER (WHERE approval_status = 'approved' AND status = 'issued'
                     AND expiry_date < current_date) AS expired_count,
    count(*) FILTER (WHERE status = 'superseded') AS superseded_count,
    count(*) FILTER (WHERE status = 'voided') AS voided_count
    FROM tracking_certificates")->fetch();

/** Keep the current filters when building a link. */
function trk_link(array $overrides = []): string
{
    global $status, $perPage;
    $params = array_merge([
        'q'      => $_GET['q'] ?? '',
        'status' => $status,
        'per'    => $perPage,
        'page'   => $_GET['page'] ?? 1,
    ], $overrides);
    return 'tracking_list.php?' . http_build_query(
        array_filter($params, fn($v) => $v !== '' && $v !== null));
}

// What is about to run out is a sales matter, not an accounts one, so only
// administrators see it. The query does not run at all for anyone else.
$expiring = [];
if ($isAdm) {
    $expiring = q("SELECT id, number, expiry_date, reg_no, client_name
                   FROM tracking_certificates
                   WHERE approval_status = 'approved' AND status = 'issued'
                     AND expiry_date BETWEEN current_date AND current_date + 30
                   ORDER BY expiry_date")->fetchAll();
}

layout_top('Installations');
?>
<h1  class="page-title">Tracker Installation Certificates</h1>

<?php if ((int)$counts['pending_count'] > 0): ?>
  <p class="hint" style="text-align:left">
    <?= (int)$counts['pending_count'] ?> certificate<?= $counts['pending_count'] == 1 ? '' : 's' ?>
    waiting for accounts to confirm payment. They have no serial number and cannot be
    printed or downloaded until approved.
  </p>
<?php endif; ?>

<form method="get" class="searchbar">
  <input name="q" value="<?= e($search) ?>"
         placeholder="Registration, customer, IMEI or serial number">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <input type="hidden" name="per" value="<?= e((string)$perPage) ?>">
  <button type="submit">Search</button>
  <?php if ($search !== ''): ?>
    <a class="button secondary" href="<?= e(trk_link(['q' => '', 'page' => 1])) ?>">Clear</a>
  <?php endif; ?>
  <?php if ($isAdm): ?>
    <a class="button" href="tracking_new.php">New installation</a>
  <?php endif; ?>
</form>

<div class="filters">
  <?php foreach ([
      'all'        => ['All', $counts['all_count']],
      'pending'    => ['Pending', $counts['pending_count']],
      'valid'      => ['Valid', $counts['valid_count']],
      'expired'    => ['Expired', $counts['expired_count']],
      'superseded' => ['Replaced', $counts['superseded_count']],
      'rejected'   => ['Rejected', $counts['rejected_count']],
      'voided'     => ['Voided', $counts['voided_count']],
  ] as $key => $info): ?>
    <a class="chip <?= $status === $key ? 'chip-on' : '' ?>"
       href="<?= e(trk_link(['status' => $key, 'page' => 1])) ?>">
      <?= e($info[0]) ?> <span class="chip-count"><?= (int)$info[1] ?></span>
    </a>
  <?php endforeach; ?>
</div>

<table>
  <tr><th>Serial</th><th>Vehicle</th><th>Customer</th><th>IMEI</th>
      <th>Subscribed</th><th>Expires</th><th>Status</th><th></th></tr>
  <?php foreach ($rows as $row): $st = tracking_status($row); ?>
  <tr>
    <td><?= e(tracking_label($row)) ?></td>
    <td><?= e($row['reg_no']) ?></td>
    <td><?= e($row['client_name']) ?></td>
    <td><?= e($row['imei']) ?></td>
    <td><?= e(fmt_date($row['subscription_date'])) ?></td>
    <td><?= e(fmt_date($row['expiry_date'])) ?></td>
    <td class="status-<?= e(strtolower(str_replace(' ', '-', $st))) ?>"><?= e($st) ?></td>
    <td><a href="tracking_view.php?id=<?= (int)$row['id'] ?>">
      <?= $st === 'Pending approval' && !$isAdm ? 'Review' : 'Open' ?></a></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?>
    <tr><td colspan="8">Nothing matches those filters.</td></tr>
  <?php endif; ?>
</table>

<div class="pager">
  <form method="get" class="per-page">
    <input type="hidden" name="q" value="<?= e($search) ?>">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <label>Rows per page
      <select name="per" onchange="this.form.submit()">
        <?php foreach ([10, 25, 50, 100] as $n): ?>
          <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>

  <span class="pager-info">
    <?= $total ? ($offset + 1) . '&ndash;' . min($offset + $perPage, $total) : 0 ?> of <?= $total ?>
  </span>

  <span class="pager-buttons">
    <?php if ($page > 1): ?>
      <a class="button secondary" href="<?= e(trk_link(['page' => 1])) ?>">&laquo; First</a>
      <a class="button secondary" href="<?= e(trk_link(['page' => $page - 1])) ?>">Previous</a>
    <?php endif; ?>
    <span class="pager-page">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?>
      <a class="button secondary" href="<?= e(trk_link(['page' => $page + 1])) ?>">Next</a>
      <a class="button secondary" href="<?= e(trk_link(['page' => $pages])) ?>">Last &raquo;</a>
    <?php endif; ?>
  </span>
</div>

<?php if ($isAdm): ?>
<h2>Subscriptions expiring in the next 30 days</h2>
<table>
  <tr><th>Expires</th><th>Serial</th><th>Vehicle</th><th>Customer</th><th></th></tr>
  <?php foreach ($expiring as $row): ?>
  <tr>
    <td><?= e(fmt_date($row['expiry_date'])) ?></td>
    <td><?= e(str_pad((string)$row['number'], 5, '0', STR_PAD_LEFT)) ?></td>
    <td><?= e($row['reg_no']) ?></td>
    <td><?= e($row['client_name']) ?></td>
    <td><a href="tracking_view.php?id=<?= (int)$row['id'] ?>">Open</a></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$expiring): ?>
    <tr><td colspan="5">Nothing due in the next 30 days.</td></tr>
  <?php endif; ?>
</table>
<?php endif; ?>
<?php layout_bottom(); ?>
