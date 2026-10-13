<?php
/** What a client sees: their vehicles and a download for each certificate. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require APP_DIR . '/views/layout.php';

$client = require_client();
$rows = q("SELECT c.*, v.reg_no FROM certificates c JOIN vehicles v ON v.id = c.vehicle_id
           WHERE c.client_id = ? ORDER BY c.number DESC", [$client['id']])->fetchAll();
// the first number from Settings, digits only, so it can be dialled
$officePhone = preg_replace('/[^0-9+]/', '', strtok((string)setting('cell'), '/'));

layout_top('My certificates', 'client');

?>
<h1 style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; 
font-size: 1.75rem; font-weight: 700; color: #0f172a; margin: 0 0 1rem 0; padding-left: 0.875rem; 
border-left: 4px solid #3b82f6; letter-spacing: -0.025em; line-height: 1.25;">My Certificates</h1>
<p class="hint">Valid certificates can be downloaded. Expired, replaced and cancelled ones can be
   viewed but not downloaded &mdash; call the office to renew.</p>
<table>
  <tr><th>Vehicle</th><th>Certificate no.</th><th>Issued</th><th>Expiry</th><th>Status</th><th></th></tr>
  <?php foreach ($rows as $row): ?>
  <tr>
    <td><?= e($row['reg_no']) ?></td>
    <td>
      <?php if ($row['number'] !== null && $row['number'] !== ''): ?>
        <?= e((string)$row['number']) ?>
      <?php else: ?>
        <span class="muted">Assigned after approval</span>
      <?php endif; ?>
    </td>    
    <td><?= e(fmt_date($row['issue_date'])) ?></td>
    <td><?= e(fmt_date($row['expiry_date'])) ?></td>
    <td class="status-<?= strtolower(certificate_status($row)) ?>"><?= e(certificate_status($row)) ?></td>
    <td>
      <?php $state = certificate_status($row); ?>
      <?php if ($state === 'Valid'): ?>
        <a class="button" href="download.php?id=<?= (int)$row['id'] ?>">Download</a>
        <a class="button secondary" href="sheet.php?id=<?= (int)$row['id'] ?>" target="_blank">Preview</a>

      <?php elseif ($state === 'Pending approval'): ?>
        <span class="muted">Awaiting approval</span>

      <?php elseif ($state === 'Expired'): ?>
        <a class="button" href="tel:<?= e($officePhone) ?>">Renew</a>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6">No certificates yet.</td></tr><?php endif; ?>
</table>
<?php layout_bottom(); ?>