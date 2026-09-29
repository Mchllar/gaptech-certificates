<?php
/** Staff dashboard: search, filter by status, paged list, and what is expiring soon. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require APP_DIR . '/views/layout.php';

require_staff();

$search = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? 'all');
if (!in_array($status, ['all', 'valid', 'expired', 'voided'], true)) {
    $status = 'all';
}
$perPage = (int)($_GET['per'] ?? 25);
if (!in_array($perPage, [10, 25, 50, 100], true)) {
    $perPage = 25;
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
if ($status === 'voided') {
    $where[] = "c.status = 'voided'";
} elseif ($status === 'expired') {
    $where[] = "c.status = 'issued' AND c.expiry_date < current_date";
} elseif ($status === 'valid') {
    $where[] = "c.status = 'issued' AND c.expiry_date >= current_date";
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$from = 'FROM certificates c
         JOIN vehicles v ON v.id = c.vehicle_id
         JOIN clients cl ON cl.id = c.client_id';

$total = (int)q("SELECT count(*) $from $whereSql", $params)->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$rows = q("SELECT c.*, v.reg_no, cl.name AS client_name $from $whereSql
           ORDER BY c.number DESC LIMIT $perPage OFFSET $offset", $params)->fetchAll();

// Counts for the filter buttons
$counts = q("SELECT
    count(*) AS all_count,
    count(*) FILTER (WHERE status = 'issued' AND expiry_date >= current_date) AS valid_count,
    count(*) FILTER (WHERE status = 'issued' AND expiry_date < current_date) AS expired_count,
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

$expiring = q("SELECT c.number, c.expiry_date, v.reg_no, cl.name AS client_name
               FROM certificates c
               JOIN vehicles v ON v.id = c.vehicle_id
               JOIN clients cl ON cl.id = c.client_id
               WHERE c.status = 'issued'
                 AND c.expiry_date BETWEEN current_date AND current_date + 30
                 AND NOT EXISTS (SELECT 1 FROM certificates n
                                 WHERE n.vehicle_id = c.vehicle_id AND n.number > c.number)
               ORDER BY c.expiry_date")->fetchAll();

layout_top('Certificates');
?>
<h1>Certificates</h1>

<form method="get" class="searchbar">
  <input name="q" value="<?= e($search) ?>" placeholder="Registration number, customer or certificate number">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <input type="hidden" name="per" value="<?= e((string)$perPage) ?>">
  <button type="submit">Search</button>
  <?php if ($search !== ''): ?><a class="button secondary" href="<?= e(page_link(['q' => '', 'page' => 1])) ?>">Clear</a><?php endif; ?>
  <a class="button" href="certificate_new.php">New certificate</a>
</form>

<div class="filters">
  <?php foreach ([
      'all' => ['All', $counts['all_count']],
      'valid' => ['Valid', $counts['valid_count']],
      'expired' => ['Expired', $counts['expired_count']],
      'voided' => ['Voided', $counts['voided_count']],
  ] as $key => $info): ?>
    <a class="chip <?= $status === $key ? 'chip-on' : '' ?>"
       href="<?= e(page_link(['status' => $key, 'page' => 1])) ?>">
      <?= e($info[0]) ?> <span class="chip-count"><?= (int)$info[1] ?></span>
    </a>
  <?php endforeach; ?>
</div>

<table>
  <tr><th>Cert No.</th><th>Registration No.</th><th>Owner</th><th>Type</th><th>Issued</th><th>Expires</th><th>Status</th><th></th></tr>
  <?php foreach ($rows as $row): ?>
  <tr>
    <td><?= e($row['number']) ?></td>
    <td><?= e($row['reg_no']) ?></td>
    <td><?= e($row['client_name']) ?></td>
    <td><?= e($row['type']) ?></td>
    <td><?= e(fmt_date($row['issue_date'])) ?></td>
    <td><?= e(fmt_date($row['expiry_date'])) ?></td>
    <td class="status-<?= strtolower(certificate_status($row)) ?>"><?= e(certificate_status($row)) ?></td>
    <td><a href="certificate_view.php?id=<?= (int)$row['id'] ?>">Open</a></td>
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
  <tr><th>Expires</th><th>Registration No.</th><th>Owner</th><th></th></tr>
  <?php foreach ($expiring as $row): ?>
  <tr>
    <td><?= e(fmt_date($row['expiry_date'])) ?></td>
    <td><?= e($row['reg_no']) ?></td>
    <td><?= e($row['client_name']) ?></td>
    <td><a href="certificate_new.php?reg=<?= urlencode($row['reg_no']) ?>">Renew</a></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$expiring): ?><tr><td colspan="4">Nothing due in the next 30 days.</td></tr><?php endif; ?>
</table>
<?php layout_bottom(); ?>
