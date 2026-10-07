<?php
/** Read-only certificate summary for the accounts popup, plus the payment correction form. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';

$user = require_accounts();

$id = (int)($_GET['id'] ?? 0);
$cert = q("SELECT c.*, v.reg_no, cl.name AS client_name, cl.phone AS client_phone,
                  iss.name AS issued_by_name, app.name AS approved_by_name,
                  edt.name AS edited_by_name
             FROM certificates c
             JOIN vehicles v  ON v.id  = c.vehicle_id
             JOIN clients cl  ON cl.id = c.client_id
             LEFT JOIN users iss ON iss.id = c.issued_by
             LEFT JOIN users app ON app.id = c.approved_by
             LEFT JOIN users edt ON edt.id = c.payment_edited_by
            WHERE c.id = ?", [$id])->fetch();

if (!$cert) {
    http_response_code(404);
    exit('<p class="error" style="padding:18px">No such certificate.</p>');
}

$d = json_decode((string)$cert['snapshot'], true) ?: [];
$status = certificate_status($cert);
$canEdit = $cert['approval_status'] !== 'pending';
?>
<div class="cert-pop">
  <p class="cert-head">
    <strong><?= e($cert['reg_no']) ?></strong>
    <span class="cert-sep">&middot;</span> <?= e($cert['client_name']) ?>
    <span class="status-<?= strtolower($status) ?>"><?= e($status) ?></span>
  </p>

  <dl class="cert-grid">
    <div><dt>Certificate no.</dt><dd><?= e(certificate_label($cert)) ?></dd></div>
    <div><dt>Type</dt><dd><?= e($cert['type']) ?></dd></div>
    <div><dt>Vehicle</dt><dd><?= e($cert['reg_no']) ?> <span class="muted">(<?= e($d['vehicle']['make'] ?? '') ?>)</span></dd></div>
    <div><dt>Chassis no.</dt><dd><?= e($d['vehicle']['chassis_no'] ?? '') ?></dd></div>
    <div><dt>Serial no.</dt><dd><?= e($d['device']['serial_no'] ?? '') ?></dd></div>
    <div><dt>Contact</dt><dd><?= e($cert['client_phone']) ?></dd></div>
    <div><dt>Issue date</dt><dd><?= e(fmt_date($cert['issue_date'])) ?></dd></div>
    <div><dt>Expires</dt><dd><?= e(fmt_date($cert['expiry_date'])) ?></dd></div>
    <div><dt>Issued by</dt><dd><?= e((string)$cert['issued_by_name']) ?></dd></div>
    <div><dt>Approved by</dt><dd><?= e((string)$cert['approved_by_name']) ?>
      <?= $cert['approved_at'] ? '<span class="muted">&middot; ' . e(fmt_date(substr((string)$cert['approved_at'], 0, 10))) . '</span>' : '' ?></dd></div>
  </dl>

  <?php if ($cert['payment_edited_at']): ?>
    <p class="edited-note">Payment details corrected by <?= e((string)$cert['edited_by_name']) ?>
       on <?= e(fmt_date(substr((string)$cert['payment_edited_at'], 0, 10))) ?>.
       The original is in the audit log.</p>
  <?php endif; ?>

  <?php if ($canEdit): ?>
  <form method="post" action="payment_edit.php" class="pay-edit">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">

    <label>Payment reference
      <input name="payment_ref" value="<?= e((string)$cert['payment_ref']) ?>"
             style="text-transform:uppercase" required>
    </label>

    <label>Payment message
      <textarea name="payment_note" rows="5"><?= e((string)$cert['payment_note']) ?></textarea>
    </label>
    <label>Why is this being changed? <span class="req">*</span>
      <input name="reason" placeholder="e.g. reference was mistyped at approval" required>
    </label>

    <div class="pay-edit-actions">
      <button type="submit">Save correction</button>
      <a class="button secondary" href="sheet.php?id=<?= (int)$cert['id'] ?>" target="_blank">Preview certificate</a>
    </div>
  </form>
  <?php else: ?>
    <p class="muted pad">This certificate is still waiting for approval. Handle it from the queue.</p>
  <?php endif; ?>
</div>
