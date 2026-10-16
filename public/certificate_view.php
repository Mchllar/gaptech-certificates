<?php
/** One certificate: what was issued, with print, PDF and void actions. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require APP_DIR . '/views/layout.php';

$user = require_staff();

// For a certificate that is no longer current, find what replaced it.
$current = null;
$awaiting = null;

$id = (int)($_GET['id'] ?? 0);
$cert = q("SELECT c.*,
                  iss.name AS issued_by_name,
                  ap.name  AS decided_by_name
             FROM certificates c
             LEFT JOIN users iss ON iss.id = c.issued_by
             LEFT JOIN users ap  ON ap.id  = c.approved_by
            WHERE c.id = ?", [$id])->fetch();
if (!$cert) {
    http_response_code(404);
    exit('Certificate not found.');
}
// For a certificate that is no longer current, find what replaced it.
$current = null;
$awaiting = null;
if (!certificate_is_releasable($cert)) {
    $current = q("SELECT id, number, expiry_date FROM certificates
                   WHERE vehicle_id = ? AND id <> ?
                     AND approval_status = 'approved' AND status = 'issued'
                     AND expiry_date >= current_date
                   ORDER BY issue_date DESC, number DESC LIMIT 1",
                 [$cert['vehicle_id'], $cert['id']])->fetch() ?: null;

    if (!$current) {
        $awaiting = q("SELECT id FROM certificates
                        WHERE vehicle_id = ? AND id <> ? AND approval_status = 'pending'
                        ORDER BY id DESC LIMIT 1",
                      [$cert['vehicle_id'], $cert['id']])->fetch() ?: null;
    }
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
            $user['role'] === 'accounts' ? 'Back to payments' : 'Back to SG. Certificates');

?>
<h1>SG. Certificate - <?= e($cert['number']) ?> <span class="status-<?= strtolower($status) ?>"><?= e($status) ?></span></h1>

<?php if (!empty($_SESSION['flash_error'])): ?>
  <p class="error"><?= e($_SESSION['flash_error']) ?></p><?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_ok'])): ?>
  <p class="hint"><?= e($_SESSION['flash_ok']) ?></p><?php unset($_SESSION['flash_ok']); ?>
<?php endif; ?>


<?php if ($user['role'] === 'administrator' && certificate_is_releasable($cert)): ?>
<?php elseif ($user['role'] === 'accounts'): ?>
<div class="actions">
<?php else: ?>
  <!-- ... the existing "not releasable" branch ... -->
  <?php endif; ?>

<table class="details">
  <tr><th>Customer</th><td><?= e($d['client']['name']) ?></td></tr>
      <th>Contact</th><td><?= e($d['client']['phone']) ?></td></tr>
      <th>Vehicle</th><td><?= e($d['vehicle']['reg_no']) ?> (<?= e($d['vehicle']['make']) ?>)</td></tr>
      <th>Chassis no.</th><td><?= e($d['vehicle']['chassis_no']) ?></td></tr>
      <th>Serial no.</th><td><?= e($d['device']['serial_no']) ?></td></tr>
      <th>Date installed</th><td><?= e($d['installation']['installed_on_fmt']) ?></td></tr>
      <th>Type</th><td><?= e($cert['type']) ?></tr>
   <?php if ($cert['approval_status'] === 'approved'): ?>
      <th>Payment ref.</th>
        <td><?= $cert['payment_ref'] !== '' ? '<code>' . e($cert['payment_ref']) . '</code>' : '&mdash;' ?></td></tr>
        <th>Date approved</th>
        <td><?= $cert['approved_at'] ? e(fmt_date(substr((string)$cert['approved_at'], 0, 10))) : '&mdash;' ?></td></tr>
    <tr><th>Prints</th><td><?= (int)$cert['print_count'] ?></td></tr>
        <th>Client downloads</th><td><?= (int)$cert['download_count'] ?></td></tr>
      <?php if (certificate_is_releasable($cert)): ?>
    <!-- <tr><th>Verification link</th>
        <td colspan="3"><a href="verify.php?t=<?= e($cert['verify_token']) ?>" target="_blank">Click to verify</a></td></tr> -->

  <?php elseif ($current): ?>
    <tr><th>Current SG. Certificate</th>
        <td colspan="3">
          <a href="certificate_view.php?id=<?= (int)$current['id'] ?>"><?= e((string)$current['number']) ?></a>
          &mdash; valid until <?= e(fmt_date($current['expiry_date'])) ?>
        </td></tr>

  <?php elseif ($awaiting): ?>
    <tr><th>Current certificate</th>
        <td colspan="3">
          None yet &mdash; a replacement is
          <a href="certificate_view.php?id=<?= (int)$awaiting['id'] ?>">waiting for accounts to approve</a>.
        </td></tr>

  <?php else: ?>
    <tr><th>Current SG. Certificate</th>
        <td colspan="3">
          This vehicle has no valid certificate.
          <?php if ($user['role'] === 'administrator'): ?>
            <a href="certificate_new.php?reg=<?= urlencode($d['vehicle']['reg_no']) ?>">Issue one</a>
          <?php endif; ?>
        </td></tr>
  <?php endif; ?>
     <?php elseif ($cert['approval_status'] === 'rejected'): ?>
    <th>Rejected</th>
        <td><?= $cert['approved_at'] ? e(fmt_date(substr((string)$cert['approved_at'], 0, 10))) : '&mdash;' ?></td></tr>
    <tr>
      <th>Rejected by</th><td><?= e((string)$cert['decided_by_name']) ?></td></tr>
      <th>Reason</th><td colspan="3" style="color:#C3121A; font-weight:600"><?= e((string)$cert['rejection_reason']) ?></td>    </tr>

  <?php else: ?>
    <tr><th>Status</th><td colspan="3"  style="color:brown;font-weight:600">Waiting for accounts to confirm payment.
        It has no certificate number and cannot be previewed, printed </br>or downloaded until approved.</td></tr>
  <?php endif; ?>
</table>

<?php if ($cert['status'] === 'voided'): ?>
  <?php if (trim((string)$cert['void_reason']) !== ''): ?>
    <p class="error">Reason: <?= e($cert['void_reason']) ?></p>
  <?php endif; ?>
<?php elseif ($user['role'] === 'administrator'): ?>
    <?php if ($cert['status'] === 'voided' && trim((string)$cert['void_reason']) !== ''): ?>
    <tr><th>Void reason</th><td colspan="3"><?= e($cert['void_reason']) ?></td></tr>
  <?php endif; ?>
<?php endif; ?>
  <?php if ($user['role'] === 'administrator' && certificate_is_releasable($cert)): ?><div class="actions">
    <a class="button" href="sheet.php?id=<?= (int)$cert['id'] ?>&amp;print=1" target="_blank">Print</a>
    <?php if ($pdfEnabled): ?>
      <a class="button" href="download.php?id=<?= (int)$cert['id'] ?>">Download PDF</a>
    <?php else: ?>
      <a class="button" href="sheet.php?id=<?= (int)$cert['id'] ?>&amp;print=1" target="_blank">Download PDF</a>
    <?php endif; ?>
    <a class="button" href="sheet.php?id=<?= (int)$cert['id'] ?>" target="_blank">Preview</a>
    <?php if ($status === 'Valid'): ?>
    <a class="button" href="certificate_edit.php?id=<?= (int)$cert['id'] ?>">Edit certificate</a>
    <?php elseif ($status === 'Expired'): ?>
      <button type="button" class="button renew-open"
              data-id="<?= (int)$cert['id'] ?>"
              data-reg="<?= e($d['vehicle']['reg_no'] ?? '') ?>"
              data-cert="<?= e(certificate_label($cert)) ?>"
              data-client="<?= e($d['client']['name'] ?? '') ?>">Renew</button>
    <?php endif; ?>
  <?php else: ?>
<div class="actions">
<?php if (certificate_is_releasable($cert)): ?>
  <a class="button" href="sheet.php?id=<?= (int)$cert['id'] ?>" target="_blank">Preview</a>
<?php endif; ?>
</div>
<?php endif; ?>

<?php if ($user['role'] === 'administrator'): ?>
  <?php if ($cert['status'] === 'voided' && trim((string)$cert['void_reason']) !== ''): ?>
    <tr><th>Void reason</th><td colspan="3"><?= e($cert['void_reason']) ?></td></tr>
  <?php endif; ?>
<?php endif; ?>
<?php require APP_DIR . '/views/renew_modal.php'; ?>
<?php layout_bottom(); ?>
