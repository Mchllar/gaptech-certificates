<?php
/**
 * Payments register - the accounts side of the system.
 *
 * Every certificate that has been approved or rejected, with the payment reference
 * recorded against it. This is where accounts look when a client says they have paid,
 * or when a reference needs checking against the bank statement.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require APP_DIR . '/views/layout.php';

$user = require_accounts();

$search   = trim((string)($_GET['q'] ?? ''));
$decision = (string)($_GET['decision'] ?? 'approved');
$period   = (string)($_GET['period'] ?? 'month');

if (!in_array($decision, ['approved', 'rejected', 'all'], true)) {
    $decision = 'approved';
}
if (!in_array($period, ['month', 'last', 'quarter', 'all'], true)) {
    $period = 'month';
}

$perPage = (int)($_GET['per'] ?? 25);
if (!in_array($perPage, [25, 50, 100], true)) {
    $perPage = 25;
}
$page = max(1, (int)($_GET['page'] ?? 1));

$where = ["c.approved_at IS NOT NULL"];
$params = [];

if ($decision !== 'all') {
    $where[] = 'c.approval_status = ?';
    $params[] = $decision;
} else {
    $where[] = "c.approval_status IN ('approved', 'rejected')";
}

if ($period === 'month') {
    $where[] = "c.approved_at >= date_trunc('month', current_date)";
} elseif ($period === 'last') {
    $where[] = "c.approved_at >= date_trunc('month', current_date) - interval '1 month'";
    $where[] = "c.approved_at <  date_trunc('month', current_date)";
} elseif ($period === 'quarter') {
    $where[] = "c.approved_at >= current_date - interval '90 days'";
}

if ($search !== '') {
    $where[] = '(c.payment_ref ILIKE ? OR v.reg_no ILIKE ? OR cl.name ILIKE ?
                 OR CAST(c.number AS text) LIKE ?)';
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
}

$from = 'FROM certificates c
         JOIN vehicles v ON v.id = c.vehicle_id
         JOIN clients cl ON cl.id = c.client_id
         LEFT JOIN users u ON u.id = c.approved_by';
$whereSql = 'WHERE ' . implode(' AND ', $where);

$total  = (int)q("SELECT count(*) $from $whereSql", $params)->fetchColumn();
$pages  = max(1, (int)ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

$rows = q("SELECT c.*, v.reg_no, cl.name AS client_name, u.name AS decided_by
           $from $whereSql
           ORDER BY c.approved_at DESC LIMIT $perPage OFFSET $offset", $params)->fetchAll();

// headline figures for whatever period is showing
$sums = q("SELECT
    count(*) FILTER (WHERE approval_status = 'approved') AS approved,
    count(*) FILTER (WHERE approval_status = 'rejected') AS rejected
  FROM certificates c
  WHERE c.approved_at IS NOT NULL"
  . ($period === 'month'   ? " AND c.approved_at >= date_trunc('month', current_date)" : '')
  . ($period === 'last'    ? " AND c.approved_at >= date_trunc('month', current_date) - interval '1 month'
                              AND c.approved_at <  date_trunc('month', current_date)" : '')
  . ($period === 'quarter' ? " AND c.approved_at >= current_date - interval '90 days'" : ''))->fetch();

$waiting = (int)q("SELECT count(*) FROM certificates WHERE approval_status = 'pending'")->fetchColumn();

/** Keep the current filters when building a link. */
function reg_link(array $overrides = []): string
{
    $params = array_merge([
        'q' => $_GET['q'] ?? '', 'decision' => $_GET['decision'] ?? 'approved',
        'period' => $_GET['period'] ?? 'month', 'per' => $_GET['per'] ?? 25,
        'page' => $_GET['page'] ?? 1,
    ], $overrides);
    return 'payments.php?' . http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== null));
}

$periodNames = ['month' => 'This month', 'last' => 'Last month',
                'quarter' => 'Last 90 days', 'all' => 'Everything'];

layout_top('Payments');
layout_back('approvals.php', 'Back to approvals');
?>
<h1>Payments Register</h1>
<?php if (!empty($_SESSION['flash_error'])): ?>
  <p class="error"><?= e($_SESSION['flash_error']) ?></p><?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_ok'])): ?>
  <p class="hint"><?= e($_SESSION['flash_ok']) ?></p><?php unset($_SESSION['flash_ok']); ?>
<?php endif; ?>

<p class="hint">Every certificate accounts has reviewed, with the payment recorded against it.</p>

<div class="stat-row">
  <div class="stat">
    <span class="stat-num"><?= (int)$sums['approved'] ?></span>
    <span class="stat-label">Approved &middot; <?= e(strtolower($periodNames[$period])) ?></span>
  </div>
  <div class="stat">
    <span class="stat-num"><?= (int)$sums['rejected'] ?></span>
    <span class="stat-label">Rejected &middot; <?= e(strtolower($periodNames[$period])) ?></span>
  </div>
  <a class="stat stat-link" href="approvals.php">
    <span class="stat-num"><?= $waiting ?></span>
    <span class="stat-label">Waiting for you now</span>
  </a>
</div>

<form method="get" class="searchbar">
  <input name="q" value="<?= e($search) ?>"
         placeholder="Payment reference, registration, customer or certificate number">
  <input type="hidden" name="decision" value="<?= e($decision) ?>">
  <input type="hidden" name="period" value="<?= e($period) ?>">
  <button type="submit">Search</button>
  <?php if ($search !== ''): ?>
    <a class="button secondary" href="<?= e(reg_link(['q' => '', 'page' => 1])) ?>">Clear</a>
  <?php endif; ?>
</form>

<div class="filters">
  <?php foreach ($periodNames as $key => $label): ?>
    <a class="chip <?= $period === $key ? 'chip-on' : '' ?>"
       href="<?= e(reg_link(['period' => $key, 'page' => 1])) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<div class="filters">
  <?php foreach (['approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'Both'] as $key => $label): ?>
    <a class="chip <?= $decision === $key ? 'chip-on' : '' ?>"
       href="<?= e(reg_link(['decision' => $key, 'page' => 1])) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<div class="table-scroll">
<table class="register">
  <colgroup>
    <col class="col-date"><col class="col-cert"><col class="col-reg"><col class="col-cust">
    <col class="col-ref"><col class="col-status"><col class="col-by"><col class="col-act">
  </colgroup>
  <tr><th>Date</th><th>Cert no.</th><th>Reg no.</th><th>Customer</th>
      <th>Payment reference</th><th>Status</th><th>Approved by</th><th></th></tr>
  <?php foreach ($rows as $row): ?>
  <tr>
    <td class="nowrap"><?= e(fmt_date(substr((string)$row['approved_at'], 0, 10))) ?></td>
    <td><?= e(certificate_label($row)) ?></td>
    <td class="nowrap"><?= e($row['reg_no']) ?></td>
    <td class="ellipsis" title="<?= e($row['client_name']) ?>"><?= e($row['client_name']) ?></td>
    <td class="wrap">
      <?php if ($row['payment_ref'] !== ''): ?><code><?= e($row['payment_ref']) ?></code><?php else: ?>&mdash;<?php endif; ?>
      <?php if ($row['payment_edited_at']): ?><span class="edited-tag" title="Corrected after approval">edited</span><?php endif; ?>
      <?php if (!empty($row['payment_note'])): ?>
         <button type="button" class="note-open" data-id="<?= (int)$row['id'] ?>">View</button>
      <?php endif; ?>
    </td>
    <td class="<?= $row['approval_status'] === 'approved' ? 'status-valid' : 'status-voided' ?>">
      <?= $row['approval_status'] === 'approved' ? 'Approved' : 'Rejected' ?></td>
    <td class="ellipsis" title="<?= e((string)$row['decided_by']) ?>"><?= e((string)$row['decided_by']) ?></td>
    <td><a href="certificate_view.php?id=<?= (int)$row['id'] ?>">View Cert</a></td>

  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8">Nothing matches those filters.</td></tr><?php endif; ?>
</table>
  </div>
<div class="pager">
  <form method="get" class="per-page">
    <input type="hidden" name="q" value="<?= e($search) ?>">
    <input type="hidden" name="decision" value="<?= e($decision) ?>">
    <input type="hidden" name="period" value="<?= e($period) ?>">
    <label>Rows per page
      <select name="per" onchange="this.form.submit()">
        <?php foreach ([25, 50, 100] as $n): ?>
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
      <a class="button secondary" href="<?= e(reg_link(['page' => 1])) ?>">&laquo; First</a>
      <a class="button secondary" href="<?= e(reg_link(['page' => $page - 1])) ?>">Previous</a>
    <?php endif; ?>
    <span class="pager-page">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?>
      <a class="button secondary" href="<?= e(reg_link(['page' => $page + 1])) ?>">Next</a>
      <a class="button secondary" href="<?= e(reg_link(['page' => $pages])) ?>">Last &raquo;</a>
    <?php endif; ?>
  </span>
</div>

<div id="note-modal" class="modal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="note-title">
    <div class="modal-head">
      <h2 id="note-title">Payment</h2>
      <button type="button" class="modal-close" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body" id="note-body"></div>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('note-modal');
  var body  = document.getElementById('note-body');
  if (!modal || !body) { return; }

  function show() {
    modal.removeAttribute('hidden');
    document.body.style.overflow = 'hidden';
    modal.querySelector('.modal-close').focus();
  }
  function shut() {
    modal.setAttribute('hidden', '');
    document.body.style.overflow = '';
  }

  modal.addEventListener('click', function (ev) {
    if (ev.target === modal || ev.target.classList.contains('modal-close')) { shut(); }
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && !modal.hasAttribute('hidden')) { shut(); }
  });

  function extractRef(text) {
    var up = (text || '').toUpperCase();
    var m = up.match(/M[-\s]?PESA\s*REF[:.\s]*([A-Z0-9]{8,15})/)
         || up.match(/\bREF(?:ERENCE)?[:.\s]*([A-Z0-9]{8,15})/)
         || up.match(/\b([A-Z0-9]{10})\b/);
    if (!m) { return ''; }
    // a real reference mixes letters and digits - this skips phone numbers and amounts
    return (/[0-9]/.test(m[1]) && /[A-Z]/.test(m[1])) ? m[1] : '';
  }

  function wirePayEdit(scope) {
    var note = scope.querySelector('textarea[name="payment_note"]');
    var ref  = scope.querySelector('input[name="payment_ref"]');
    if (!note || !ref) { return; }

    var warn = document.createElement('p');
    warn.className = 'ref-warn';
    warn.hidden = true;
    ref.parentNode.appendChild(warn);

    // Once someone types the reference by hand, stop overwriting it.
    var manual = false;

    function check() {
      var found = extractRef(note.value);
      if (!found || found === ref.value.trim().toUpperCase()) { warn.hidden = true; return; }
      warn.textContent = 'The message says ' + found + '. ';
      var fix = document.createElement('button');
      fix.type = 'button';
      fix.className = 'note-open';
      fix.textContent = 'Use it';
      fix.addEventListener('click', function () { ref.value = found; manual = false; check(); });
      warn.appendChild(fix);
      warn.hidden = false;
    }

    ref.addEventListener('input', function () { manual = true; check(); });
    note.addEventListener('input', function () {
      if (!manual) {
        var found = extractRef(note.value);
        if (found) { ref.value = found; }
      }
      check();
    });

    check();
  }

  document.querySelectorAll('.note-open').forEach(function (btn) {
    if (!btn.dataset.id) { return; }          // skips the "Use it" button inside the popup
    btn.addEventListener('click', function () {
      body.innerHTML = '<p class="modal-meta">Loading&hellip;</p>';
      show();
      fetch('cert_summary.php?id=' + encodeURIComponent(btn.dataset.id), { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.text(); })
        .then(function (html) {
          body.innerHTML = html;
          wirePayEdit(body);
        })
        .catch(function () {
          body.innerHTML = '<p class="error" style="margin:18px">Could not load that payment.</p>';
        });
    });
  });
})();
</script>
<?php layout_bottom(); ?>

