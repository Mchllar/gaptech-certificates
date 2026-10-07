<?php
/** One certificate: what was issued, with print, PDF and void actions. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require APP_DIR . '/views/layout.php';

$user = require_staff();
$cert = q('SELECT * FROM certificates WHERE id = ?', [(int)($_GET['id'] ?? 0)])->fetch();
if (!$cert) {
    http_response_code(404);
    exit('Certificate not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void') {
    csrf_check();
    require_supervisor();
    $reason = trim((string)($_POST['reason'] ?? ''));
    if ($reason === '') {
        flash('Give a reason for voiding.');
    } else {
        q("UPDATE certificates SET status = 'voided', void_reason = ?, voided_by = ?, voided_at = now()
           WHERE id = ?", [$reason, $user['id'], $cert['id']]);
        audit('void_certificate', 'certificate', (int)$cert['id'], ['reason' => $reason]);
        flash('Certificate ' . $cert['number'] . ' voided.');
    }
    redirect('certificate_view.php?id=' . $cert['id']);
}

$d = json_decode($cert['snapshot'], true);
$status = certificate_status($cert);
$pdfEnabled = !empty($config['wkhtmltopdf']);

layout_top('Certificate ' . $cert['number']);

layout_back($user['role'] === 'accounts' ? 'payments.php' : 'index.php',
            $user['role'] === 'accounts' ? 'Back to payments' : 'Back to certificates');

?>
<h1>Certificate <?= e($cert['number']) ?> <span class="status-<?= strtolower($status) ?>"><?= e($status) ?></span></h1>
<?php if ($user['role'] === 'administrator' && certificate_is_releasable($cert)): ?>
  ... the existing actions block with Print, Download, Preview, Edit, Renew ...
<?php elseif ($user['role'] === 'accounts'): ?>
<div class="actions">
  <a class="button" href="sheet.php?id=<?= (int)$cert['id'] ?>" target="_blank">Preview</a>
  <a class="button secondary" href="payments.php">Back to payments</a>
</div>
<?php else: ?>
  ... the existing "not releasable" branch ...
<?php endif; ?>
<div class="actions">
  <a class="button" href="sheet.php?id=<?= (int)$cert['id'] ?>&amp;print=1" target="_blank">Print</a>
  <?php if ($pdfEnabled): ?>
    <a class="button" href="download.php?id=<?= (int)$cert['id'] ?>">Download PDF</a>
  <?php else: ?>
    <a class="button" href="sheet.php?id=<?= (int)$cert['id'] ?>&amp;print=1" target="_blank">Save as PDF</a>
  <?php endif; ?>
  <a class="button" href="sheet.php?id=<?= (int)$cert['id'] ?>" target="_blank">Preview</a>

  <?php if ($status === 'Valid'): ?>
    <a class="button" href="certificate_edit.php?id=<?= (int)$cert['id'] ?>">Edit certificate</a>
  <?php elseif ($status === 'Expired'): ?>
    <a class="button" href="certificate_new.php?reg=<?= urlencode($d['vehicle']['reg_no']) ?>">Renew</a>
  <?php endif; ?>
</div>

<table class="details">
  <tr><th>Customer</th><td><?= e($d['client']['name']) ?></td>
      <th>Contact</th><td><?= e($d['client']['phone']) ?></td>
  </tr>
  <tr><th>Vehicle</th><td><?= e($d['vehicle']['reg_no']) ?> (<?= e($d['vehicle']['make']) ?>)</td>
      <th>Chassis no.</th><td><?= e($d['vehicle']['chassis_no']) ?></td>
  </tr>
  <tr>
      <th>Serial no.</th><td><?= e($d['device']['serial_no']) ?></td>
      <th>Date installed</th><td><?= e($d['installation']['installed_on_fmt']) ?></td>
  </tr>
  <tr>
    <th>Type</th><td><?= e($cert['type']) ?></td>
    <th>Payment ref.</th>
    <td><?= $cert['payment_ref'] !== '' ? '<code>' . e($cert['payment_ref']) . '</code>' : '&mdash;' ?></td>
  </tr>   
  <tr><th>Issued</th><td><?= e(fmt_date($cert['issue_date'])) ?></td>
      <th>Expiry</th><td><?= e(fmt_date($cert['expiry_date'])) ?></td>  
  </tr>
  <tr><th>Prints</th><td><?= (int)$cert['print_count'] ?></td>
      <th>Client downloads</th><td><?= (int)$cert['download_count'] ?></td>
  </tr>
  <tr>
    <th>Verification link</th>
    <td><a href= "<?= e(verify_url($cert)) ?>">Click to Verify</a> </td>
    <th>Status</th>
    <td><?= $cert['approved_at'] ? e(fmt_date(substr((string)$cert['approved_at'], 0, 10))) : 'Pending Approved' ?></td>
  </tr>
</table>

<?php if ($cert['status'] === 'voided'): ?>
  <p class="error">Voided: <?= e($cert['void_reason']) ?></p>
<?php else: ?>
<form method="post" class="void-form" onsubmit="return confirm('Void this certificate? This cannot be undone.');">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="void">
  <label>Void this certificate <input name="reason" placeholder="Reason"></label>
  <button type="submit" class="danger">Void</button>
</form>
<?php endif; ?>
<?php layout_bottom(); ?>
