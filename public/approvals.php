<?php
/**
 * Accounts approval queue.
 *
 * A certificate is issued by an administrator but has no number and cannot be
 * printed or downloaded until accounts records the payment and approves it.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require APP_DIR . '/views/layout.php';

$user = require_accounts();
$errors = [];

$cert = null;
if (!empty($_GET['id'])) {
    $cert = q('SELECT * FROM certificates WHERE id = ?', [(int)$_GET['id']])->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $target = q('SELECT * FROM certificates WHERE id = ?', [(int)($_POST['id'] ?? 0)])->fetch();
    $action = (string)($_POST['action'] ?? '');

    if (!$target) {
        $errors[] = 'Certificate not found.';
    } else {
        try {
            if ($action === 'approve') {
                $done = approve_certificate($target, $user,
                    (string)($_POST['payment_ref'] ?? ''), trim((string)($_POST['payment_note'] ?? '')));
                flash('Approved. Certificate ' . $done['number'] . ' is now available to print and download.');
                redirect('approvals.php');
            } elseif ($action === 'reject') {
                reject_certificate($target, $user, (string)($_POST['reason'] ?? ''));
                flash('Rejected and sent back to the administrator.');
                redirect('approvals.php');
            } else {
                $errors[] = 'Choose approve or reject.';
            }
        } catch (Throwable $err) {
            $errors[] = $err->getMessage();
            $cert = $target;
        }
    }
}

$pending = q("SELECT c.*, v.reg_no, cl.name AS client_name, u.name AS issued_by_name
              FROM certificates c
              JOIN vehicles v ON v.id = c.vehicle_id
              JOIN clients cl ON cl.id = c.client_id
              LEFT JOIN users u ON u.id = c.issued_by
              WHERE c.approval_status = 'pending'
              ORDER BY c.issued_at")->fetchAll();

$recent = q("SELECT c.*, v.reg_no, cl.name AS client_name, u.name AS approved_by_name
             FROM certificates c
             JOIN vehicles v ON v.id = c.vehicle_id
             JOIN clients cl ON cl.id = c.client_id
             LEFT JOIN users u ON u.id = c.approved_by
             WHERE c.approval_status IN ('approved', 'rejected') AND c.approved_at IS NOT NULL
             ORDER BY c.approved_at DESC LIMIT 15")->fetchAll();

layout_top('Approvals');
?>
<h1>Certificates awaiting approval <span class="chip-count"><?= count($pending) ?></span></h1>
<p class="hint">A certificate can only be printed or downloaded once the payment is on record.
   Paste the payment message, check the reference, then approve.</p>

<?php foreach ($errors as $msg): ?><p class="error"><?= e($msg) ?></p><?php endforeach; ?>

<?php if ($cert && ($cert['approval_status'] ?? '') !== 'approved'):
    $d = json_decode($cert['snapshot'], true); ?>

  <div class="approve-panel">
    <h2 style="margin-top:0"><?= e(draft_ref($cert)) ?> &middot; <?= e($d['client']['name']) ?></h2>

    <table class="details">
      <tr><th>Vehicle</th><td><?= e($d['vehicle']['reg_no']) ?> (<?= e($d['vehicle']['make']) ?>)</td>
          <th>Chassis no.</th><td><?= e($d['vehicle']['chassis_no']) ?></td></tr>
      <tr><th>Serial no.</th><td><?= e($d['device']['serial_no']) ?></td>
          <th>Type</th><td><?= e($cert['type']) ?></td></tr>
      <tr><th>Issue date</th><td><?= e(fmt_date($cert['issue_date'])) ?></td>
          <th>Expires</th><td><?= e(fmt_date($cert['expiry_date'])) ?></td></tr>
      <tr><th>Contact</th><td colspan="3"><?= e($d['client']['phone']) ?></td></tr>
    </table>

    <p><a class="button secondary" href="sheet.php?id=<?= (int)$cert['id'] ?>" target="_blank">Preview the certificate</a></p>

    <?php if (($cert['approval_status'] ?? '') === 'rejected'): ?>
      <p class="error">Previously rejected: <?= e((string)$cert['rejection_reason']) ?></p>
    <?php endif; ?>

    <form method="post" class="card-form" style="margin-top:16px">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">

      <fieldset>
        <legend>Payment</legend>
        <label class="field" style="width:100%">Payment message
          <span class="field-row">
            <textarea name="payment_note" id="note" rows="3"
              placeholder="Paste the full M-Pesa or bank message here"
              style="width:100%; padding:9px 11px; border:1px solid var(--line); border-radius:6px;
                     font-family:inherit; font-size:14px; resize:vertical"><?= e($_POST['payment_note'] ?? '') ?></textarea>
          </span>
        </label>
        <label class="field">Reference code <span class="req">*</span>
          <span class="field-row">
            <input name="payment_ref" id="ref" required maxlength="40"
                   value="<?= e($_POST['payment_ref'] ?? '') ?>"
                   style="text-transform:uppercase">
          </span>
        </label>
        <p class="hint" style="text-align:left; margin:0">The reference is checked against every other
           certificate, so a payment reference cannot be reused.</p>
      </fieldset>

      <button type="submit" name="action" value="approve">Approve and issue the number</button>
    </form>

    <form method="post" class="reject-form"
          onsubmit="return confirm('Reject this certificate and send it back?');">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">
      <label>Or reject it <input name="reason" placeholder="Reason, e.g. no payment received"></label>
      <button type="submit" name="action" value="reject" class="danger">Reject</button>
    </form>
  </div>

<?php endif; ?>

<table>
  <tr><th>Draft No.</th><th>Vehicle</th><th>Customer</th><th>Type</th><th>Issued</th><th>By</th><th></th></tr>
  <?php foreach ($pending as $row): ?>
  <tr<?= $cert && (int)$cert['id'] === (int)$row['id'] ? ' style="background:#EAF1FB"' : '' ?>>
    <td><?= e(draft_ref($row)) ?></td>
    <td><?= e($row['reg_no']) ?></td>
    <td><?= e($row['client_name']) ?></td>
    <td><?= e($row['type']) ?></td>
    <td><?= e(fmt_date($row['issue_date'])) ?></td>
    <td><?= e((string)$row['issued_by_name']) ?></td>
    <td><a href="approvals.php?id=<?= (int)$row['id'] ?>">Review</a></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$pending): ?><tr><td colspan="7">Nothing waiting. Everything issued has been dealt with.</td></tr><?php endif; ?>
</table>

<h2>Recently Reviewed</h2>
<table>
  <tr><th>No.</th><th>Vehicle</th><th>Customer</th><th>Review</th><th>Reference</th><th>Date</th><th>By</th></tr>
  <?php foreach ($recent as $row): ?>
  <tr>
    <td><?= e(certificate_label($row)) ?></td>
    <td><?= e($row['reg_no']) ?></td>
    <td><?= e($row['client_name']) ?></td>
    <td class="<?= $row['approval_status'] === 'approved' ? 'status-valid' : 'status-voided' ?>">
      <?= $row['approval_status'] === 'approved' ? 'Approved' : 'Rejected' ?></td>
    <td><?= e((string)$row['payment_ref']) ?></td>
    <td><?= e(fmt_date(substr((string)$row['approved_at'], 0, 10))) ?></td>
    <td><?= e((string)$row['approved_by_name']) ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$recent): ?><tr><td colspan="7">Not Record.</td></tr><?php endif; ?>
</table>

<script>
// Pull the reference out of a pasted payment message, as a suggestion only.
// M-Pesa codes are ten letters and digits at the start of the message.
(function () {
  var note = document.getElementById('note'), ref = document.getElementById('ref');
  if (!note || !ref) { return; }
  function suggest() {
    if (ref.value.trim() !== '') { return; }          // never overwrite a typed value
    var m = note.value.toUpperCase().match(/\b([A-Z0-9]{10})\b/);
    if (m) { ref.value = m[1]; }
  }
  note.addEventListener('input', suggest);
  note.addEventListener('paste', function () { setTimeout(suggest, 50); });
  ref.addEventListener('input', function () { this.value = this.value.toUpperCase(); });
})();
</script>
<?php layout_bottom(); ?>
