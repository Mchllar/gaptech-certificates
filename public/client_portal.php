<?php
/** What a client sees: their vehicles and a download for each certificate. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require APP_DIR . '/views/layout.php';

$client = require_client();
$rows = q("SELECT c.*, v.reg_no FROM certificates c JOIN vehicles v ON v.id = c.vehicle_id
           WHERE c.client_id = ? ORDER BY c.number DESC", [$client['id']])->fetchAll();

layout_top('My certificates', 'client');
?>
<h1>My certificates</h1>
<p class="hint">Download a certificate at any time. If a detail is wrong, call the office and we will correct it.</p>
<table>
  <tr><th>Vehicle</th><th>Certificate no.</th><th>Issued</th><th>Expires</th><th>Status</th><th></th></tr>
  <?php foreach ($rows as $row): ?>
  <tr>
    <td><?= e($row['reg_no']) ?></td>
    <td><?= e($row['number']) ?></td>
    <td><?= e(fmt_date($row['issue_date'])) ?></td>
    <td><?= e(fmt_date($row['expiry_date'])) ?></td>
    <td class="status-<?= strtolower(certificate_status($row)) ?>"><?= e(certificate_status($row)) ?></td>
    <td>
      <?php if ($row['status'] !== 'voided'): ?>
        <a href="download.php?id=<?= (int)$row['id'] ?>">Download</a>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6">No certificates yet.</td></tr><?php endif; ?>
</table>
<?php layout_bottom(); ?>
