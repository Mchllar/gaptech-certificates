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
                    $why  = trim((string)($_POST['correction_reason'] ?? ''));

                    // Changing the payment on a released certificate is editing a
                    // financial record after the fact. The audit trail can already
                    // show WHAT changed and who did it; without a reason it can
                    // never show why, and "why" is the only part a manager asking
                    // about it six months later actually wants.
                    if ($why === '') {
                        throw new RuntimeException('Say why the payment details are being '
                            . 'corrected. It goes on the record with the change.');
                    }

                    // The same floor approval has: a correction must not quietly
                    // leave the certificate released against nothing at all.
                    if ($ref === '' && $lpo === '' && $note === '') {
                        throw new RuntimeException('A certificate cannot be left with no payment '
                            . 'reference, no LPO and no message. Record at least one.');
                    }

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

                    // Both sides of every field, not just the reference. A trail
                    // that records the new value alone cannot answer "what did it
                    // say before?", which is the first question anybody asks.
                    audit('update_payment', 'certificate', $id, [
                        'reason'   => $why,
                        'ref_was'  => (string)$target['payment_ref'],
                        'ref_now'  => $ref,
                        'lpo_was'  => (string)($target['lpo_no'] ?? ''),
                        'lpo_now'  => $lpo,
                        'note_changed' => trim((string)$target['payment_note']) !== $note,
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

// Renewing and withdrawing belong to the certificate that is actually in force.
//
//   voided      already withdrawn; there is nothing left to take back
//   superseded  a renewal has already replaced it
//   replaced    a newer certificate is in force even if this row still says issued
//
// Renewing a replaced certificate would raise a renewal of the wrong one, and
// withdrawing it would withdraw a certificate that stopped mattering months ago -
// while leaving the one that does matter untouched.
//
// Expired is deliberately NOT in that list. A lapsed vehicle coming back is the
// ordinary way a renewal starts, so an expired certificate keeps its Renew button.
$superseded = ($cert['status'] ?? '') === 'superseded';
$inForce    = $approved && !$voided && !$superseded && !$replacement;

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
<!-- Details                                                            -->
<!--                                                                    -->
<!-- One table, the way the tracking module does it, rather than three  -->
<!-- under Certificate / Client / Vehicle and governor. The headings    -->
<!-- were costing three scroll-stops to read nine facts, and the        -->
<!-- groupings were ours rather than anything the reader needed: nobody -->
<!-- opens this page wondering "what do we know about the client" - they -->
<!-- open it to check one value.                                        -->
<!--                                                                    -->
<!-- Vehicle first, because that is what the page is looked up by.      -->
<!-- Status is not a row: it is already the pill beside the heading.    -->
<!-- A rejected certificate hides the dates that never came into effect.-->
<!-- ------------------------------------------------------------------ -->
<table class="details">
  <tr><th>Vehicle</th><td><?= e($snap(['vehicle', 'reg_no'], $cert['reg_no'])) ?></td>
      <th>Make</th><td><?= e($snap(['vehicle', 'make'], $cert['make'])) ?></td></tr>
  <tr><th>Chassis no.</th><td><?= e($snap(['vehicle', 'chassis_no'], $cert['chassis_no'])) ?></td>
      <!-- <th>Set speed</th>
      <td><?= e($snap(['device', 'set_speed_kmh'], $cert['set_speed_kmh'])) ?> km/h</td></tr> -->
  <!-- <tr><th>Governor</th><td><?= e($snap(['device', 'model'], $cert['device_model'])) ?></td> -->
      <th>Serial no.</th><td><?= e($snap(['device', 'serial_no'], $cert['serial_no'])) ?></td></tr>
  <tr><th>Customer</th><td><?= e($snap(['client', 'name'], $cert['client_name'])) ?></td>
      <th>Contact</th><td><?= e($snap(['client', 'phone'], $cert['client_phone'])) ?></td></tr>
  <!-- <tr><th>Address</th><td><?= e($snap(['client', 'address'], $cert['client_address'])) ?></td> -->
  <tr><th>Installed</th><td><?= e($snap(['installation', 'installed_on_fmt'], '—')) ?></td>
      <!-- <th>Technician</th><td><?= e($snap(['installation', 'technician'], '—')) ?></td></tr> -->
  <?php if (!$rejected): ?>
    <!-- <tr><th>Issued</th><td><?= e(fmt_date($cert['issue_date'])) ?></td> -->
        <th>Expires</th><td><?= e(fmt_date($cert['expiry_date'])) ?></td></tr>
  <?php endif; ?>
  <tr><th>Raised by</th><td><?= e($cert['issued_by_name'] ?? '—') ?></td>
      <th>Raised on</th>
      <td><?= e(fmt_date(substr((string)($cert['issued_at'] ?? $cert['issue_date']), 0, 10))) ?></td></tr>
        <tr><th>Type</th><td><?= e($snap(['cert', 'type'], $cert['type'])) ?></td></tr>

  <?php if ($approved): ?>
    <tr><th>Released by</th><td><?= e($cert['decided_by_name'] ?? '—') ?></td>
        <th>Released on</th>
        <td><?= e(fmt_date(substr((string)$cert['approved_at'], 0, 10))) ?></td></tr>
  <?php endif; ?>
</table>

<!-- ------------------------------------------------------------------ -->
<!-- Payment - only once there is something to show                      -->
<!-- ------------------------------------------------------------------ -->
<?php
// ---------------------------------------------------------------------------
// Only the facts that exist get a cell.
//
// A payment is recorded one of two ways - money that arrived, or an LPO promising
// it later - and most certificates have exactly one of them. The table used to
// reserve a column for each regardless, so an M-Pesa payment showed "LPO —" and an
// LPO payment showed "Reference —". An em dash next to a label reads as a value
// that is missing rather than one that was never expected, and on a payment record
// that is the wrong thing to make somebody wonder about.
//
// So the row is built from what is actually there and laid out two to a line, the
// way the tracking page does it. The message is a View link rather than the whole
// M-Pesa text inline, because that text is long enough to push the certificate's
// own buttons off the screen.
// ---------------------------------------------------------------------------
$payRef  = trim((string)$cert['payment_ref']);
$payLpo  = trim((string)($cert['lpo_no'] ?? ''));
$payNote = trim((string)$cert['payment_note']);

$payCells = [];
if ($payRef !== '')  { $payCells[] = ['Reference', e($payRef)]; }
if ($payLpo !== '')  { $payCells[] = ['LPO', e($payLpo)]; }
if ($payNote !== '') { $payCells[] = ['Message', '<a href="#" data-modal="#m-note">View</a>']; }
?>
<?php if ($hasPayment && !$rejected && $payCells): ?>
  <h2>Payment</h2>
  <table class="details">
    <?php foreach (array_chunk($payCells, 2) as $pair): ?>
      <tr>
        <th><?= e($pair[0][0]) ?></th>
        <?php if (count($pair) === 2): ?>
          <td><?= $pair[0][1] ?></td>
          <th><?= e($pair[1][0]) ?></th><td><?= $pair[1][1] ?></td>
        <?php else: ?>
          <td colspan="3"><?= $pair[0][1] ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
  </table>

  <?php if ($payNote !== ''): ?>
    <div class="gt-modal" id="m-note">
      <div class="gt-box">
        <h3>Payment message</h3>
        <p class="gt-hint">Exactly as it was pasted in. The reference is read out of this,
          so the two cannot disagree.</p>
        <p class="gt-msg"><?= nl2br(e($payNote)) ?></p>
        <div class="gt-foot">
          <button type="button" class="button secondary gt-close">Close</button>
        </div>
      </div>
    </div>
    <?php require_once APP_DIR . '/views/modal_base.php'; ?>
  <?php endif; ?>

  <?php if ($isAcc && $approved): ?>
    <details class="pay-correct">
      <summary>Correct the payment details</summary>
      <form method="post" class="card-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">
        <label class="field">LPO number
          <span class="field-row">
            <input name="lpo_no" maxlength="60" style="text-transform:uppercase"
                   value="<?= e((string)($cert['lpo_no'] ?? '')) ?>"></span></label>
        <label class="field" style="width:100%"> Payment Message
          <span class="field-row">
            <textarea name="payment_note" rows="2"
              style="width:100%; padding:9px 11px; border:1px solid var(--line); border-radius:6px;
                     font-family:inherit; font-size:14px; resize:vertical"><?= e((string)$cert['payment_note']) ?></textarea>
          </span></label>
          <label class="field">Payment reference
            <span class="field-row">
              <input name="payment_ref" maxlength="40" style="text-transform:uppercase"
                    value="<?= e((string)$cert['payment_ref']) ?>"></span></label>
 


        <?php /*
          Required here and checked again on the server. The browser's own
          "required" is a convenience; the handler is what actually enforces it,
          because a form can be submitted without one.

          Deliberately not pre-filled and not remembered between corrections: a
          reason carried over from last time is worse than none, because it reads
          as an explanation of this change when it explains a different one.
        */ ?>
        <label class="field" style="width:100%">Reason for the correction <span class="req">*</span>
          <span class="field-row">
            <input name="correction_reason" required maxlength="200"
                   placeholder="e.g. reference was mistyped, client paid after the LPO was raised">
          </span>
          <span class="field-hint">Goes on the record with the change, alongside what it
            was before and who changed it.</span>
        </label>

        <button type="submit" name="action" value="update_pay" class="secondary">Save the correction</button>
      </form>
    </details>
  <?php endif; ?>
<?php endif; ?>

<!-- ------------------------------------------------------------------ -->
<!-- Accounts: release or send back                                      -->
<!-- ------------------------------------------------------------------ -->
<?php if ($isAcc && $pending): ?>
  <h2>Issue certificate</h2>

  <p class="hint" style="text-align:left">
    An administrator has raised this. Issuing it gives it a serial number and the client
    can download it straight away. Look at it first &mdash; the preview is the sheet
    exactly as it will print.
  </p>
<!-- 
  <p><a class="button secondary" target="_blank"
        href="certificate_print.php?id=<?= (int)$cert['id'] ?>">Preview the certificate</a></p> -->

  <form method="post" class="card-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">

    <fieldset>
      <legend>Payment</legend>

      <?php if ($hasPayment): ?>
        <p class="hint" style="text-align:left; margin:0 0 12px; color:#8A5A00">
          The administrator recorded this when they raised it. Confirm it before
          releasing.</p>
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

<?php

$showCertActions = $isAdm || !$rejected;
?>
<?php if ($showCertActions): ?>

<div class="row-actions" style="margin-top:22px; display:flex; flex-wrap:wrap; align-items:center; gap:10px">
  <?php if ($live): ?>
    <a class="button" target="_blank"
       href="certificate_print.php?id=<?= (int)$cert['id'] ?>&amp;print=1">Print</a>
    <a class="button secondary"
       href="certificate_print.php?id=<?= (int)$cert['id'] ?>&amp;download=1">Download PDF</a>
  <?php elseif (!$rejected): ?>
    <?php if ($isAdm): ?>
      <a class="button secondary" target="_blank"
         href="certificate_print.php?id=<?= (int)$cert['id'] ?>">View (<?= e(strtolower($status)) ?>)</a>
    <?php endif; ?>
    <?php /*
      Administrators only. These lines explain the state of the certificate to the
      person waiting on somebody else to act - and for accounts that somebody else
      is themselves. "It cannot be printed until accounts release it" told to
      accounts is both redundant and faintly absurd, and the Release panel is
      directly below it saying the same thing in a form they can actually use.
    */ ?>
    <?php if ($isAdm): ?>
      <span class="hint" style="margin:0">
        <?= $pending
              ? 'It cannot be printed or downloaded until accounts release it.'
              : 'This certificate is not current, so it is view-only.' ?>
      </span>
    <?php endif; ?>
  <?php elseif ($isAdm): ?>
    <span class="hint" style="margin:0">A certificate that has been sent back has no number
      and cannot be printed.</span>
  <?php endif; ?>
 
  <?php if ($isAdm && $inForce): ?>
    <button type="button" class="button renew-open"
            data-id="<?= (int)$cert['id'] ?>"
            data-reg="<?= e((string)$cert['reg_no']) ?>"
            data-cert="<?= e((string)$cert['number']) ?>"
            data-client="<?= e((string)$cert['client_name']) ?>">Renew certificate</button>
    <button type="button" class="button danger" data-modal="#m-void">Withdraw</button>
  <?php endif; ?>
</div>
<?php endif; ?>
 
<?php if ($isAdm && $inForce): ?>
<?php /*
  Withdrawing used to be a form sitting open at the bottom of the page behind a
  browser confirm(). Two problems with that: a destructive action does not belong
  permanently on screen underneath Print and Download, and confirm() asks "are you
  sure" AFTER the reason has been typed, which is the wrong order and looks nothing
  like the rest of the system.

  It is now the same dialog as everything else, and the reason is typed inside it.
  The reason is required by the form AND by void_certificate(), so neither the
  screen nor the server will take a withdrawal without one.
*/ ?>
<div class="gt-modal" id="m-void">
  <form class="gt-box" method="post">
    <h3>Withdraw <?= e($label) ?></h3>

    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">

    <p class="gt-hint">
      For a certificate that should never have gone out, or a vehicle whose governor
      has been removed. The record is kept and verification will say it was withdrawn
      &mdash; a voided certificate may still be in somebody&rsquo;s glovebox, and an
      officer checking it deserves an answer rather than &ldquo;not found&rdquo;.
    </p>
    <p class="gt-hint"><strong>This is not how a certificate is replaced.</strong>
      A renewal supersedes the old one on its own, and cannot be undone here.</p>

    <table class="details" style="margin:0 0 14px">
      <tr><th>Vehicle</th><td><?= e((string)$cert['reg_no']) ?></td>
          <th>Customer</th><td><?= e((string)$cert['client_name']) ?></td></tr>
    </table>

    <label class="gt-f">Reason <span class="req">*</span>
      <input name="reason" required placeholder="e.g. vehicle sold, governor removed">
    </label>

    <div class="gt-foot">
      <button type="button" class="button secondary gt-close">Cancel</button>
      <button type="submit" name="action" value="void" class="button danger">Withdraw it</button>
    </div>
  </form>
</div>
<?php endif; ?>

<style>
  /* Only what this page adds; everything else is the shared stylesheet. */
  .pay-correct { margin: 0 0 22px; }
  .pay-correct summary {
    cursor: pointer; font-size: 14.5px; font-weight: 600; color: #0D4F72;
    padding: 6px 0;
  }
  .pay-correct[open] summary { margin-bottom: 10px; }
  /* .actions is gone: the button bar is .row-actions now, matching tracking. */
</style>

<?php if ($isAdm): ?>
  <?php require APP_DIR . '/views/renew_modal.php'; ?>
<?php endif; ?>

<?php layout_bottom(); ?>