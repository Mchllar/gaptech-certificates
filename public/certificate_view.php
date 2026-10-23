<?php
/**
 * One speed governor certificate: everything about it, and everything anyone
 * does to it.
 *
 * Goes in public/certificate_view.php  (replaces what is there)
 *
 * Built to the same shape as tracking_view.php. Both roles open this page and
 * each sees what belongs to them:
 *
 *   accounts        - the payment, and release or reject
 *   administrators  - print, download, renew, withdraw
 *
 * Accounts release the certificate HERE, with the details they are releasing in
 * front of them. That is the point of the page: approvals.php showed a payment
 * form and a number, and somebody had to go and look at the certificate in
 * another tab to know what they were approving.
 *
 * Needs the two functions in certificates_helpers_add.php appended to
 * app/certificates.php: void_certificate() and certificate_current_for_plate().
 */
require __DIR__ . '/../app/bootstrap.php';
require_once APP_DIR . '/certificates.php';
require APP_DIR . '/views/layout.php';

$user  = require_staff();
$isAdm = ($user['role'] ?? '') === 'administrator';
$isAcc = ($user['role'] ?? '') === 'accounts';

$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$errors = [];

// ---------------------------------------------------------------- actions

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $target = q('SELECT * FROM certificates WHERE id = ?', [$id])->fetch();
    $action = (string)($_POST['action'] ?? '');

    if (!$target) {
        $errors[] = 'Certificate not found.';
    } else {
        try {
            // Every action checks the role here, on the server. Hiding a button
            // from the wrong role is a courtesy; this is the control.
            if (in_array($action, ['approve', 'reject', 'update_pay'], true) && !$isAcc) {
                throw new RuntimeException('Releasing certificates and recording payments is for accounts.');
            }
            if ($action === 'void' && !$isAdm) {
                throw new RuntimeException('Withdrawing a certificate is for administrators.');
            }

            switch ($action) {

                case 'approve':
                    $done = approve_certificate($target, $user,
                        (string)($_POST['payment_ref'] ?? ''),
                        trim((string)($_POST['payment_note'] ?? '')),
                        trim((string)($_POST['lpo_no'] ?? '')));

                    flash('Certificate ' . $done['number'] . ' issued'
                        . (trim((string)$done['payment_ref']) === '' && trim((string)$done['lpo_no']) !== ''
                            ? ' against LPO ' . $done['lpo_no'] : '')
                        . '. The client can download it now.');
                    redirect('certificate_view.php?id=' . $id);

                case 'reject':
                    reject_certificate($target, $user, (string)($_POST['reason'] ?? ''));
                    flash('Sent back to the administrator who raised it.');
                    redirect('certificate_view.php?id=' . $id);

                case 'void':
                    void_certificate($target, $user, (string)($_POST['reason'] ?? ''));
                    flash('Certificate withdrawn. Verification will now say so rather '
                        . 'than reporting it as valid.');
                    redirect('certificate_view.php?id=' . $id);

                case 'update_pay':
                    $ref  = strtoupper(trim((string)($_POST['payment_ref'] ?? '')));
                    $note = trim((string)($_POST['payment_note'] ?? ''));
                    $lpo  = trim((string)($_POST['lpo_no'] ?? ''));

                    // One payment cannot be on two certificates - the same rule
                    // approval enforces, applied again when it is corrected.
                    if ($ref !== '') {
                        $clash = q('SELECT number, id FROM certificates
                                    WHERE upper(payment_ref) = ? AND id <> ?',
                                   [$ref, $id])->fetch();
                        if ($clash) {
                            throw new RuntimeException('That payment reference is already on '
                                . 'certificate ' . ($clash['number'] ?: 'draft ' . $clash['id']) . '.');
                        }
                    }

                    q('UPDATE certificates SET payment_ref = ?, payment_note = ?, lpo_no = ?
                        WHERE id = ?', [$ref, $note, $lpo, $id]);

                    audit('update_payment', 'certificate', $id, [
                        'was' => $target['payment_ref'], 'now' => $ref, 'lpo_no' => $lpo,
                    ]);
                    flash('Payment details updated.');
                    redirect('certificate_view.php?id=' . $id);

                default:
                    $errors[] = 'Unknown action.';
            }
        } catch (Throwable $err) {
            $errors[] = $err->getMessage();
        }
    }
}

// ------------------------------------------------------------- the record

$cert = q("SELECT c.*, v.reg_no, v.make, v.chassis_no,
                  cl.name AS client_name, cl.phone AS client_phone, cl.address AS client_address,
                  d.model AS device_model, d.serial_no, d.set_speed_kmh,
                  iss.name AS issued_by_name, dec.name AS decided_by_name
           FROM certificates c
           LEFT JOIN vehicles v  ON v.id  = c.vehicle_id
           LEFT JOIN clients cl  ON cl.id = c.client_id
           LEFT JOIN devices d   ON d.id  = c.device_id
           LEFT JOIN users iss   ON iss.id = c.issued_by
           LEFT JOIN users dec   ON dec.id = c.approved_by
           WHERE c.id = ?", [$id])->fetch();

if (!$cert) {
    layout_top('Certificate');
    echo '<h1>Not found</h1><p class="error">There is no certificate with that reference. '
       . 'It may have been opened from an old link.</p>'
       . '<p><a class="button" href="index.php">Back to certificates</a></p>';
    layout_bottom();
    exit;
}

$d        = json_decode((string)$cert['snapshot'], true) ?: [];
$status   = certificate_status($cert);
$pending  = $cert['approval_status'] === 'pending';
$rejected = $cert['approval_status'] === 'rejected';
$approved = $cert['approval_status'] === 'approved';
$voided   = $cert['status'] === 'voided';
$live     = certificate_is_releasable($cert);

// Anything recorded about payment at all - the block stays hidden until there is
// something in it, rather than showing a heading over two dashes.
$hasPayment = trim((string)$cert['payment_ref']) !== ''
           || trim((string)$cert['payment_note']) !== ''
           || trim((string)($cert['lpo_no'] ?? '')) !== '';

// A certificate that is no longer the current one: offer the one that is, rather
// than leaving somebody to go and look for it.
$replacement = null;
if ($approved && !$live && !$voided && function_exists('certificate_current_for_plate')) {
    $cur = certificate_current_for_plate((string)$cert['reg_no']);
    if ($cur && (int)$cur['id'] !== (int)$cert['id']) {
        $replacement = $cur;
    }
}

/** Snapshot first, live row second: what was printed beats what has changed since. */
$snap = function (array $path, $fallback = '') use ($d) {
    $at = $d;
    foreach ($path as $key) {
        if (!is_array($at) || !array_key_exists($key, $at)) {
            return $fallback;
        }
        $at = $at[$key];
    }
    return ($at === '' || $at === null) ? $fallback : $at;
};

$label = function_exists('certificate_label')
    ? certificate_label($cert)
    : ($cert['number'] ? 'No. ' . $cert['number'] : 'Draft ' . $cert['id']);

layout_top('Certificate ' . $label);
layout_back('index.php', 'Back to certificates');
?>

<h1><?= e($label) ?>
  <span class="status-pill status-<?= e(strtolower(str_replace(' ', '-', $status))) ?>">
    <?= e($status) ?></span>
</h1>

<?php foreach ($errors as $msg): ?><p class="error"><?= e($msg) ?></p><?php endforeach; ?>

<?php if ($voided): ?>
  <p class="error">Withdrawn<?= trim((string)$cert['void_reason']) !== ''
      ? ': ' . e($cert['void_reason']) : '.' ?></p>
<?php elseif ($rejected): ?>
  <p class="error">Sent back to the administrator<?= trim((string)$cert['rejection_reason']) !== ''
      ? ': ' . e($cert['rejection_reason']) : '.' ?>
     It carries no number and cannot be printed.</p>
<?php elseif ($replacement): ?>
  <p class="hint">This is no longer the current certificate for
     <?= e($cert['reg_no']) ?>.
     <a href="certificate_view.php?id=<?= (int)$replacement['id'] ?>">Open the one in
     force now</a> (No. <?= e($replacement['number']) ?>).</p>
<?php endif; ?>

<!-- ------------------------------------------------------------------ -->
<!-- The certificate                                                    -->
<!-- ------------------------------------------------------------------ -->
<h2>Certificate</h2>
<table class="details">
  <tr><th>Type</th><td><?= e($snap(['cert', 'type'], $cert['type'])) ?></td>
      <th>Status</th><td><?= e($status) ?></td></tr>
  <tr><th>Issued</th><td><?= e(fmt_date($cert['issue_date'])) ?></td>
      <th>Expires</th><td><?= e(fmt_date($cert['expiry_date'])) ?></td></tr>
  <tr><th>Raised by</th><td><?= e($cert['issued_by_name'] ?? '—') ?></td>
      <th>Raised on</th>
      <td><?= e(fmt_date(substr((string)($cert['issued_at'] ?? $cert['issue_date']), 0, 10))) ?></td></tr>
  <?php if ($approved): ?>
    <tr><th>Released by</th><td><?= e($cert['decided_by_name'] ?? '—') ?></td>
        <th>Released on</th>
        <td><?= e(fmt_date(substr((string)$cert['approved_at'], 0, 10))) ?></td></tr>
  <?php endif; ?>
</table>

<h2>Client</h2>
<table class="details">
  <tr><th>Name</th><td colspan="3"><?= e($snap(['client', 'name'], $cert['client_name'])) ?></td></tr>
  <tr><th>Contact</th><td><?= e($snap(['client', 'phone'], $cert['client_phone'])) ?></td>
      <th>Address</th><td><?= e($snap(['client', 'address'], $cert['client_address'])) ?></td></tr>
</table>

<h2>Vehicle and governor</h2>
<table class="details">
  <tr><th>Registration</th><td><?= e($snap(['vehicle', 'reg_no'], $cert['reg_no'])) ?></td>
      <th>Make</th><td><?= e($snap(['vehicle', 'make'], $cert['make'])) ?></td></tr>
  <tr><th>Chassis no.</th><td><?= e($snap(['vehicle', 'chassis_no'], $cert['chassis_no'])) ?></td>
      <th>Set speed</th>
      <td><?= e($snap(['device', 'set_speed_kmh'], $cert['set_speed_kmh'])) ?> km/h</td></tr>
  <tr><th>Governor</th><td><?= e($snap(['device', 'model'], $cert['device_model'])) ?></td>
      <th>Serial no.</th><td><?= e($snap(['device', 'serial_no'], $cert['serial_no'])) ?></td></tr>
  <tr><th>Installed</th><td><?= e($snap(['installation', 'installed_on_fmt'], '—')) ?></td>
      <th>Technician</th><td><?= e($snap(['installation', 'technician'], '—')) ?></td></tr>
</table>

<!-- ------------------------------------------------------------------ -->
<!-- Payment - only once there is something to show                      -->
<!-- ------------------------------------------------------------------ -->
<?php if ($hasPayment && !$rejected): ?>
  <h2>Payment</h2>
  <table class="details">
    <tr><th>Reference</th>
        <td><?= trim((string)$cert['payment_ref']) !== '' ? e($cert['payment_ref']) : '&mdash;' ?></td>
        <th>LPO</th>
        <td><?= trim((string)($cert['lpo_no'] ?? '')) !== '' ? e($cert['lpo_no']) : '&mdash;' ?></td></tr>
    <?php if (trim((string)$cert['payment_note']) !== ''): ?>
      <tr><th>Message</th><td colspan="3"><?= nl2br(e($cert['payment_note'])) ?></td></tr>
    <?php endif; ?>
  </table>

  <?php if ($isAcc && $approved): ?>
    <details class="pay-correct">
      <summary>Correct the payment details</summary>
      <form method="post" class="card-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">
        <label class="field">Payment reference
          <span class="field-row">
            <input name="payment_ref" maxlength="40" style="text-transform:uppercase"
                   value="<?= e((string)$cert['payment_ref']) ?>"></span></label>
        <label class="field">LPO number
          <span class="field-row">
            <input name="lpo_no" maxlength="60" style="text-transform:uppercase"
                   value="<?= e((string)($cert['lpo_no'] ?? '')) ?>"></span></label>
        <label class="field" style="width:100%">Message
          <span class="field-row">
            <textarea name="payment_note" rows="2"
              style="width:100%; padding:9px 11px; border:1px solid var(--line); border-radius:6px;
                     font-family:inherit; font-size:14px; resize:vertical"><?= e((string)$cert['payment_note']) ?></textarea>
          </span></label>
        <button type="submit" name="action" value="update_pay" class="secondary">Save the correction</button>
      </form>
    </details>
  <?php endif; ?>
<?php endif; ?>

<!-- ------------------------------------------------------------------ -->
<!-- Accounts: release or send back                                      -->
<!-- ------------------------------------------------------------------ -->
<?php if ($isAcc && $pending): ?>
  <h2>Release this certificate</h2>

  <p class="hint" style="text-align:left">
    An administrator has raised this. Releasing it gives it a number and the client
    can download it straight away. Look at it first &mdash; the preview is the sheet
    exactly as it will print.
  </p>

  <p><a class="button secondary" target="_blank"
        href="certificate_print.php?id=<?= (int)$cert['id'] ?>">Preview the certificate</a></p>

  <form method="post" class="card-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">

    <fieldset>
      <legend>Payment</legend>

      <?php if ($hasPayment): ?>
        <p class="hint" style="text-align:left; margin:0 0 12px; color:#8A5A00">
          The administrator recorded this when they raised it. Check it before
          releasing &mdash; nobody in accounts has looked at it yet.</p>
      <?php endif; ?>

      <label class="field" style="width:100%">Payment message
        <span class="field-row">
          <textarea name="payment_note" rows="3"
            placeholder="Paste the M-Pesa or bank message, if there is one"
            style="width:100%; padding:9px 11px; border:1px solid var(--line); border-radius:6px;
                   font-family:inherit; font-size:14px; resize:vertical"><?= e((string)($_POST['payment_note'] ?? $cert['payment_note'])) ?></textarea>
        </span>
      </label>

      <label class="field">Payment reference
        <span class="field-row">
          <input name="payment_ref" maxlength="40" style="text-transform:uppercase"
                 placeholder="M-Pesa code or receipt number"
                 value="<?= e((string)($_POST['payment_ref'] ?? $cert['payment_ref'])) ?>">
        </span>
      </label>

      <label class="field">LPO number
        <span class="field-row">
          <input name="lpo_no" maxlength="60" style="text-transform:uppercase"
                 placeholder="If they are paying later"
                 value="<?= e((string)($_POST['lpo_no'] ?? ($cert['lpo_no'] ?? ''))) ?>">
        </span>
      </label>

      <p class="hint" style="text-align:left; margin:0">All three are optional. A payment
         reference is checked against every other certificate, so one payment cannot
         release two. An LPO is not &mdash; one LPO routinely covers a whole fleet.</p>
    </fieldset>

    <button type="submit" name="action" value="approve">Release the certificate</button>
  </form>

  <form method="post" class="reject-form"
        onsubmit="return confirm('Send this back to the administrator?');">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">
    <label>Or send it back
      <input name="reason" placeholder="Reason, e.g. no payment received" required></label>
    <button type="submit" name="action" value="reject" class="danger">Send back</button>
  </form>
<?php endif; ?>

<!-- ------------------------------------------------------------------ -->
<!-- What anyone can do with it                                          -->
<!-- ------------------------------------------------------------------ -->
<h2>The certificate itself</h2>

<p class="actions">
  <?php if ($live): ?>
    <a class="button" target="_blank"
       href="certificate_print.php?id=<?= (int)$cert['id'] ?>&amp;print=1">Print</a>
    <a class="button secondary"
       href="certificate_print.php?id=<?= (int)$cert['id'] ?>&amp;download=1">Download PDF</a>
  <?php elseif (!$rejected): ?>
    <a class="button secondary" target="_blank"
       href="certificate_print.php?id=<?= (int)$cert['id'] ?>">View on screen</a>
    <span class="hint">
      <?= $pending
            ? 'It cannot be printed or downloaded until accounts release it.'
            : 'This certificate is not current, so it is view-only.' ?>
    </span>
  <?php else: ?>
    <span class="hint">A certificate that has been sent back has no number and cannot be printed.</span>
  <?php endif; ?>

  <?php if ($isAdm && $approved && !$voided): ?>
    <button type="button" class="link-button renew-open"
            data-id="<?= (int)$cert['id'] ?>"
            data-reg="<?= e((string)$cert['reg_no']) ?>"
            data-cert="<?= e((string)$cert['number']) ?>"
            data-client="<?= e((string)$cert['client_name']) ?>">Renew</button>
  <?php endif; ?>
</p>

<?php if ($isAdm && !$voided && $approved): ?>
  <h2>Withdraw</h2>
  <p class="hint" style="text-align:left">
    For a certificate that should never have gone out, or a vehicle whose governor
    has been removed. The record is kept and verification will say it was withdrawn
    &mdash; a voided certificate may still be in somebody&rsquo;s glovebox, and an
    officer checking it deserves an answer rather than &ldquo;not found&rdquo;.
    This is not how a certificate is replaced; a renewal does that on its own.
  </p>
  <form method="post" class="reject-form"
        onsubmit="return confirm('Withdraw certificate <?= e($label) ?>? This cannot be undone.');">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">
    <label>Reason <input name="reason" required
           placeholder="e.g. vehicle sold, governor removed"></label>
    <button type="submit" name="action" value="void" class="danger">Withdraw it</button>
  </form>
<?php endif; ?>

<style>
  /* Only what this page adds; everything else is the shared stylesheet. */
  .pay-correct { margin: 0 0 22px; }
  .pay-correct summary {
    cursor: pointer; font-size: 14.5px; font-weight: 600; color: #0D4F72;
    padding: 6px 0;
  }
  .pay-correct[open] summary { margin-bottom: 10px; }
  .actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
  .actions .hint { margin: 0; }
</style>

<?php if ($isAdm): ?>
  <?php require APP_DIR . '/views/renew_modal.php'; ?>
<?php endif; ?>

<?php layout_bottom(); ?>