<?php
/** Administrator dashboard: search, filter by status, paged list, and what expires soon. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require APP_DIR . '/views/layout.php';

// A client who lands here goes to their own portal rather than a refusal.
if (!current_user() && current_client()) {
    redirect('client_portal.php');
}
$user = require_admin();

$search = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? 'all');
if (!in_array($status, ['all', 'pending', 'valid', 'expired', 'superseded', 'voided', 'rejected', 'scheduled'], true)) {
    $status = 'all';
}
$perPage = (int)($_GET['per'] ?? 10);
if (!in_array($perPage, [10, 25, 50, 100], true)) {
    $perPage = 10;
}
$page = max(1, (int)($_GET['page'] ?? 1));

// Build the filter once and use it for both the count and the page of rows.
$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(v.reg_no ILIKE ? OR cl.name ILIKE ? OR CAST(c.number AS text) LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($status === 'pending') {
    $where[] = "c.approval_status = 'pending'";
} elseif ($status === 'rejected') {
    $where[] = "c.approval_status = 'rejected'";
} elseif ($status === 'valid') {
    $where[] = "c.approval_status = 'approved' AND c.status = 'issued'
                AND c.issue_date <= current_date AND c.expiry_date >= current_date";
} elseif ($status === 'scheduled') {
    $where[] = "c.approval_status = 'approved' AND c.status = 'issued'
                AND c.issue_date > current_date";
} elseif ($status === 'expired') {
    $where[] = "c.approval_status = 'approved' AND c.status = 'issued' AND c.expiry_date < current_date";
} elseif ($status === 'superseded') {
    $where[] = "c.status = 'superseded'";
} elseif ($status === 'voided') {
    $where[] = "c.status = 'voided'";
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$from = 'FROM certificates c
         JOIN vehicles v ON v.id = c.vehicle_id
         JOIN clients cl ON cl.id = c.client_id';

$total  = (int)q("SELECT count(*) $from $whereSql", $params)->fetchColumn();
$pages  = max(1, (int)ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

$rows = q("SELECT c.*, v.reg_no, cl.name AS client_name $from $whereSql
           ORDER BY c.issued_at DESC LIMIT $perPage OFFSET $offset", $params)->fetchAll();

// For superseded rows, find the certificate that replaced them, so the row can
// link to the one that is current as well as to itself. One query for the whole
// page, not one per row.
$currentFor = [];
$plates = [];
foreach ($rows as $row) {
    if ($row['status'] === 'superseded') {
        $plates[] = plate_key((string)$row['reg_no']);
    }
}
if ($plates) {
    $plates = array_values(array_unique($plates));
    $marks = implode(',', array_fill(0, count($plates), '?'));
    $found = q("SELECT DISTINCT ON (regexp_replace(upper(v.reg_no), '[^A-Z0-9]', '', 'g'))
                       regexp_replace(upper(v.reg_no), '[^A-Z0-9]', '', 'g') AS plate,
                       c.id
                FROM certificates c
                JOIN vehicles v ON v.id = c.vehicle_id
                WHERE regexp_replace(upper(v.reg_no), '[^A-Z0-9]', '', 'g') IN ($marks)
                  AND c.approval_status = 'approved' AND c.status = 'issued'
                ORDER BY regexp_replace(upper(v.reg_no), '[^A-Z0-9]', '', 'g'),
                         c.expiry_date DESC, c.number DESC", $plates)->fetchAll();
    foreach ($found as $f) {
        $currentFor[$f['plate']] = (int)$f['id'];
    // Some superseded rows have no live replacement because the renewal is still
// with accounts. Find those too, so the row says so instead of going quiet.
$pendingFor = [];
$missing = array_values(array_diff($plates, array_keys($currentFor)));
if ($missing) {
    $marks = implode(',', array_fill(0, count($missing), '?'));
    $found = q("SELECT DISTINCT ON (regexp_replace(upper(v.reg_no), '[^A-Z0-9]', '', 'g'))
                       regexp_replace(upper(v.reg_no), '[^A-Z0-9]', '', 'g') AS plate, c.id
                FROM certificates c
                JOIN vehicles v ON v.id = c.vehicle_id
                WHERE regexp_replace(upper(v.reg_no), '[^A-Z0-9]', '', 'g') IN ($marks)
                  AND c.approval_status = 'pending'
                ORDER BY regexp_replace(upper(v.reg_no), '[^A-Z0-9]', '', 'g'),
                         c.issued_at DESC", $missing)->fetchAll();
    foreach ($found as $f) {
        $pendingFor[$f['plate']] = (int)$f['id'];
    }
}
    }
}

// Counts for the filter chips
$counts = q("SELECT
    count(*) AS all_count,
    count(*) FILTER (WHERE approval_status = 'pending') AS pending_count,
    count(*) FILTER (WHERE approval_status = 'rejected') AS rejected_count,
    count(*) FILTER (WHERE approval_status = 'approved' AND status = 'issued'
                     AND issue_date <= current_date
                     AND expiry_date >= current_date) AS valid_count,
    count(*) FILTER (WHERE approval_status = 'approved' AND status = 'issued'
                     AND issue_date > current_date) AS scheduled_count,
    count(*) FILTER (WHERE approval_status = 'approved' AND status = 'issued'
                     AND expiry_date < current_date) AS expired_count,
    count(*) FILTER (WHERE status = 'superseded') AS superseded_count,
    count(*) FILTER (WHERE status = 'voided') AS voided_count
    FROM certificates")->fetch();

/** Keep the current filters when building a link. */
function page_link(array $overrides = []): string
{
    $params = array_merge([
        'q' => $_GET['q'] ?? '',
        'status' => $_GET['status'] ?? 'all',
        'per' => $_GET['per'] ?? 25,
        'page' => $_GET['page'] ?? 1,
    ], $overrides);
    return 'index.php?' . http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== null));
}

// Only approved, current certificates can expire - a pending one has not started yet.
$expiring = q("SELECT c.id, c.number, c.expiry_date, v.reg_no, cl.name AS client_name
               FROM certificates c
               JOIN vehicles v ON v.id = c.vehicle_id
               JOIN clients cl ON cl.id = c.client_id
               WHERE c.approval_status = 'approved' AND c.status = 'issued'
                 AND c.expiry_date BETWEEN current_date AND current_date + 30
               ORDER BY c.expiry_date")->fetchAll();

layout_top('Speed Governor Certificates');
?>
<style>
  .vfy-title { margin: 0 0 6px; font-size: 22px; font-weight: 700; letter-spacing: -.4px; color: #1C2430; line-height: 1.25; }
     /* ---- a little wider once there is room ---- */
  .vfy h1.vfy-title {
    margin: 0 0 6px;
    font-size: 22px; font-weight: 700; letter-spacing: -.4px;
    line-height: 1.25; color: #0D4F72;
    padding-left: 12px; border-left: 4px solid #8CC63F;
  }
  @media (min-width: 520px) { .vfy h1.vfy-title { font-size: 26px; } }
  .vfy-sub { font-size: 14px; color: #7A8696; line-height: 1.5; text-align: center; }

</style>

<div class="vfy">
<h1 class="vfy-title">Speed Governor Certificates</h1>
<?php if ((int)$counts['pending_count'] > 0): ?>
  <p class="vfy-sub" style="text-align:left">
    <?= (int)$counts['pending_count'] ?> certificate<?= $counts['pending_count'] == 1 ? '' : 's' ?>
    waiting for accounts to confirm payment. They cannot be printed or downloaded until approved.
  </p>
<?php endif; ?>

<form method="get" class="searchbar">
  <input name="q" value="<?= e($search) ?>" placeholder="Registration number, customer or certificate number">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <input type="hidden" name="per" value="<?= e((string)$perPage) ?>">
  <button type="submit">Search</button>
  <?php if ($search !== ''): ?>
    <a class="button secondary" href="<?= e(page_link(['q' => '', 'page' => 1])) ?>">Clear</a>
  <?php endif; ?>
  <a class="button" href="certificate_new.php">New certificate</a>
</form>

<div class="filters">
  <?php foreach ([
      'all' => ['All', $counts['all_count']],
      'pending' => ['Pending', $counts['pending_count']],
      'valid' => ['Valid', $counts['valid_count']],
      'scheduled'  => ['Scheduled', $counts['scheduled_count']],
      'expired' => ['Expired', $counts['expired_count']],
      'superseded' => ['Superseded', $counts['superseded_count']],
      'rejected' => ['Rejected', $counts['rejected_count']],
      'voided' => ['Voided', $counts['voided_count']],
  ] as $key => $info): ?>
    <a class="chip <?= $status === $key ? 'chip-on' : '' ?>"
       href="<?= e(page_link(['status' => $key, 'page' => 1])) ?>">
      <?= e($info[0]) ?> <span class="chip-count"><?= (int)$info[1] ?></span>
    </a>
  <?php endforeach; ?>
</div>

<table>
  <tr><th>No.</th><th>Vehicle</th><th>Customer</th><th>Type</th><th>Issued</th><th>Expires</th><th>Status</th><th></th></tr>
  <?php foreach ($rows as $row): ?>
  <tr>
    <td><?= e(certificate_label($row)) ?></td>
    <td><?= e($row['reg_no']) ?></td>
    <td><?= e($row['client_name']) ?></td>
    <td><?= e($row['type']) ?></td>
    <td><?= e(fmt_date($row['issue_date'])) ?></td>
    <td><?= e(fmt_date($row['expiry_date'])) ?></td>
    <td class="status-<?= strtolower(certificate_status($row)) ?>"><?= e(certificate_status($row)) ?></td>
    <td class="row-links">
      <?php
        $key  = plate_key((string)$row['reg_no']);
        $sup  = $row['status'] === 'superseded';
        $cur  = $sup ? ($currentFor[$key] ?? null) : null;
        $pend = ($sup && !$cur) ? ($pendingFor[$key] ?? null) : null;
      ?>
      <a href="certificate_view.php?id=<?= (int)$row['id'] ?>">Open</a>
      <?php if ($cur): ?>
        <span class="sep">&middot;</span>
        <a href="certificate_view.php?id=<?= $cur ?>">View current</a>
      <?php elseif ($pend): ?>
        <span class="sep">&middot;</span>
        <a href="certificate_view.php?id=<?= $pend ?>" style="color:gray;">Pending Approval</a>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8">Nothing matches those filters.</td></tr><?php endif; ?>
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
      <a class="button secondary" href="<?= e(page_link(['page' => 1])) ?>">&laquo; First</a>
      <a class="button secondary" href="<?= e(page_link(['page' => $page - 1])) ?>">Previous</a>
    <?php endif; ?>
    <span class="pager-page">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?>
      <a class="button secondary" href="<?= e(page_link(['page' => $page + 1])) ?>">Next</a>
      <a class="button secondary" href="<?= e(page_link(['page' => $pages])) ?>">Last &raquo;</a>
    <?php endif; ?>
  </span>
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
<div>
<?php require APP_DIR . '/views/renew_modal.php'; ?>
<?php layout_bottom(); ?>
