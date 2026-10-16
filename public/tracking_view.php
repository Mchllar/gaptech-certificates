<?php
/**
 * One tracking installation certificate.
 *
 * Goes in public/tracking_view.php
 *
 * Both roles open this page and each sees what belongs to them:
 *   accounts        - the payment, and approve or reject
 *   administrators  - print, download, renew, void
 *
 * There is no separate approvals page for this module. Accounts approve here, with
 * the details they are approving in front of them.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/tracking.php';
require APP_DIR . '/views/layout.php';

$user  = require_staff();
$isAdm = $user['role'] === 'administrator';
$isAcc = $user['role'] === 'accounts';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $target = q('SELECT * FROM tracking_certificates WHERE id = ?', [$id])->fetch();
    $action = (string)($_POST['action'] ?? '');

    if (!$target) {
        $errors[] = 'Certificate not found.';
    } else {
        try {
            // Every action checks the role here, on the server. The buttons below are
            // hidden from the wrong role as a courtesy, not as the control.
            if ($action === 'approve' || $action === 'reject' || $action === 'update_ref') {
                if (!$isAcc) {
                    throw new RuntimeException('Approving and recording payments is for accounts.');
                }
            } elseif ($action === 'void' || $action === 'renew') {
                if (!$isAdm) {
                    throw new RuntimeException('That action is for administrators.');
                }
            }

            if ($action === 'approve') {
                $done = approve_tracking_cert($target, $user,
                    (string)($_POST['payment_ref'] ?? ''),
                    trim((string)($_POST['payment_note'] ?? '')));
                flash('Approved. Certificate ' . tracking_label($done)
                    . ' can now be printed and downloaded.');
                redirect('tracking_view.php?id=' . $id);

            } elseif ($action === 'reject') {
                reject_tracking_cert($target, $user, (string)($_POST['reason'] ?? ''));
                flash('Rejected and sent back to the administrator.');
                redirect('tracking_view.php?id=' . $id);

            } elseif ($action === 'update_ref') {
                // Accounts own the payment record, so they may correct a reference or
                // re-paste a message after approval. It is written to the audit trail.
                $note = trim((string)($_POST['payment_note'] ?? ''));
                $ref  = strtoupper(trim((string)($_POST['payment_ref'] ?? '')));
                if ($ref === '') {
                    throw new RuntimeException('The reference cannot be blank.');
                }
                $clash = q('SELECT id FROM tracking_certificates
                             WHERE upper(payment_ref) = ? AND id <> ?', [$ref, $id])->fetch();
                if ($clash) {
                    throw new RuntimeException('That reference is already on another certificate.');
                }
                q('UPDATE tracking_certificates SET payment_ref = ?, payment_note = ? WHERE id = ?',
                    [$ref, $note, $id]);
                audit('edit_tracking_payment', 'tracking_certificate', $id, [
                    'was' => $target['payment_ref'], 'now' => $ref,
                ]);
                flash('Payment details updated.');
                redirect('tracking_view.php?id=' . $id);

            } elseif ($action === 'void') {
                void_tracking_cert($target, $user, (string)($_POST['reason'] ?? ''));
                flash('Certificate ' . tracking_label($target) . ' is now void.');
                redirect('tracking_view.php?id=' . $id);

            } elseif ($action === 'renew') {
                $new = renew_tracking_cert($target, $user,
                    trim((string)($_POST['payment_note'] ?? '')),
                    (int)($_POST['term_months'] ?? 12));
                flash('Renewal created for ' . $new['reg_no'] . ', expiring '
                    . fmt_date($new['expiry_date']) . '. It is with accounts for approval.');
                redirect('tracking_view.php?id=' . $new['id']);

            } else {
                $errors[] = 'Nothing to do.';
            }
        } catch (Throwable $err) {
            $errors[] = $err->getMessage();
        }
    }
}

$cert = q("SELECT t.*, iss.name AS issued_by_name, dec.name AS decided_by_name,
                  old.number AS renewed_from_number, old.id AS renewed_from_id
           FROM tracking_certificates t
           LEFT JOIN users iss ON iss.id = t.issued_by
           LEFT JOIN users dec ON dec.id = t.approved_by
           LEFT JOIN tracking_certificates old ON old.id = t.renewed_from
           WHERE t.id = ?", [$id])->fetch();

if (!$cert) {
    layout_top('Installation certificate');
    echo '<h1>Not found</h1><p class="error">There is no installation certificate with that '
       . 'reference. It may have been opened from an old link.</p>'
       . '<p><a class="button" href="tracking_list.php">Back to Tracker Installations</a></p>';
    layout_bottom();
    exit;
}

$d        = json_decode((string)$cert['snapshot'], true) ?: [];
$status   = tracking_status($cert);
$pending  = $cert['approval_status'] === 'pending';
$rejected = $cert['approval_status'] === 'rejected';
$approved = $cert['approval_status'] === 'approved';
$live     = tracking_is_releasable($cert);

// A certificate that is not the current one: offer the one that is, rather than
// leaving someone to go and look for it.
$replacement = null;
if ($approved && !$live) {
    $cur = tracking_current_for_plate((string)$cert['reg_no']);
    if ($cur && (int)$cur['id'] !== (int)$cert['id']) {
        $replacement = $cur;
    }
}

layout_top('Installation ' . tracking_label($cert));
layout_back('tracking_list.php', 'Back to Tracker Installations');
?>
<h1><?= e(tracking_label($cert)) ?>
  <span class="status-pill status-<?= e(strtolower(str_replace(' ', '-', $status))) ?>">
    <?= e($status) ?></span></h1>
<p class="hint" style="text-align:left">Tracking installation certificate for
  <?= e($cert['reg_no']) ?> &mdash; <?= e($cert['client_name']) ?>.</p>

<?php foreach ($errors as $msg): ?><p class="error"><?= e($msg) ?></p><?php endforeach; ?>

<?php if ($pending): ?>
  <p class="hint" style="text-align:left; color:#8A5A00">
    This certificate has no serial number yet and cannot be printed or downloaded.
    It takes its number when accounts confirm the payment.</p>
<?php endif; ?>

<?php if ($rejected): ?>
  <p class="error">Rejected by <?= e((string)$cert['decided_by_name']) ?>
    on <?= e(fmt_date(substr((string)$cert['approved_at'], 0, 10))) ?>:
    <?= e((string)$cert['rejection_reason']) ?></p>
<?php endif; ?>

<?php if ($cert['status'] === 'voided'): ?>
  <p class="error">Voided: <?= e((string)$cert['void_reason']) ?></p>
<?php endif; ?>

<?php if ($replacement): ?>
  <p class="hint" style="text-align:left">
    <?= $cert['status'] === 'superseded' ? 'Replaced by' : 'The current certificate for this vehicle is' ?>
    <a href="tracking_view.php?id=<?= (int)$replacement['id'] ?>"><?= e(tracking_label($replacement)) ?></a>,
    expiring <?= e(fmt_date($replacement['expiry_date'])) ?>.</p>
<?php endif; ?>

<!-- ------------------------------------------------------------------ -->
<!-- Details. A rejected certificate shows only what was asked for, not -->
<!-- dates and serials that never came into effect.                     -->
<!-- ------------------------------------------------------------------ -->
<table class="details">
  <tr><th>Vehicle</th><td><?= e($cert['reg_no']) ?></td>
      <th>Make / model</th><td><?= e($cert['make_model']) ?></td></tr>
  <tr><th>Chassis no.</th><td><?= e($cert['chassis_no']) ?></td>
      <th>Engine no.</th><td><?= e($cert['engine_no']) ?></td></tr>
  <tr><th>Colour</th><td><?= e($cert['colour']) ?></td>
      <th>Track IMEI</th><td><?= e($cert['imei']) ?></td></tr>
  <tr><th>Customer</th><td><?= e($cert['client_name']) ?></td>
      <th>Contact</th><td><?= e($cert['client_phone']) ?></td></tr>
  <tr><th>Ownership</th><td><?= e($cert['ownership']) ?></td>
      <th>Financier</th><td><?= e($cert['financier'] !== '' ? $cert['financier'] : '—') ?></td></tr>
  <tr><th>Installed</th><td><?= e(fmt_date($cert['installed_on'])) ?></td>
      <th>Signed by</th><td><?= e($cert['signed_by']) ?></td></tr>
  <?php if (!$rejected): ?>
  <tr><th>Subscription</th><td><?= e(fmt_date($cert['subscription_date'])) ?></td>
      <th>Expires</th><td><?= e(fmt_date($cert['expiry_date'])) ?></td></tr>
  <?php endif; ?>
  <tr><th>Raised by</th><td><?= e((string)$cert['issued_by_name']) ?></td>
      <th>Raised on</th><td><?= e(fmt_date(substr((string)$cert['issued_at'], 0, 10))) ?></td></tr>
  <?php if ($approved): ?>
  <tr><th>Approved by</th><td><?= e((string)$cert['decided_by_name']) ?></td>
      <th>Approved on</th><td><?= e(fmt_date(substr((string)$cert['approved_at'], 0, 10))) ?></td></tr>
  <?php endif; ?>
  <?php if ($cert['renewed_from']): ?>
  <tr><th>Renewal of</th><td colspan="3">
      <a href="tracking_view.php?id=<?= (int)$cert['renewed_from_id'] ?>">
        <?= e($cert['renewed_from_number']
              ? str_pad((string)$cert['renewed_from_number'], 5, '0', STR_PAD_LEFT)
              : 'TRK-' . str_pad((string)$cert['renewed_from_id'], 5, '0', STR_PAD_LEFT)) ?></a></td></tr>
  <?php endif; ?>
</table>

<!-- ------------------------------------------------------------------ -->
<!-- Payment                                                            -->
<!-- ------------------------------------------------------------------ -->
<?php if (!$rejected): ?>
<h2>Payment</h2>
<table class="details">
  <tr><th>Reference</th>
      <td><?= $cert['payment_ref'] !== ''
            ? e($cert['payment_ref'])
            : '<span style="display:block; text-align:center">&mdash;</span>' ?></td>
      <th>Message</th>
      <td><?php if (trim((string)$cert['payment_note']) !== ''): ?>
            <a href="#" class="pay-view">View</a>
          <?php else: ?>
            <span style="display:block; text-align:center">&mdash;</span>
          <?php endif; ?></td></tr>
</table>
<?php if ($isAcc && $approved): ?>
  <p><a class="button secondary pay-edit" href="#">Correct the payment details</a></p>
<?php endif; ?>
<?php endif; ?>

<!-- ------------------------------------------------------------------ -->
<!-- Accounts: approve or reject                                        -->
<!-- ------------------------------------------------------------------ -->
<?php if ($isAcc && !$approved): ?>
  <div class="approve-panel">
    <h2 style="margin-top:0">Record the payment</h2>

    <?php if (trim((string)$cert['payment_ref']) !== ''): ?>
      <p class="hint" style="text-align:left; margin:0 0 10px; color:#8A5A00">
        The administrator who raised this recorded a payment. Check it against the
        statement before approving &mdash; it has not been verified by accounts.</p>
    <?php endif; ?>

    <p><a class="button secondary" href="tracking_print.php?id=<?= (int)$cert['id'] ?>"
          target="_blank">Preview the certificate</a></p>

    <form method="post" class="card-form" style="margin-top:16px">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">
      <label class="field" style="width:100%">Payment message
        <span class="field-row">
          <textarea name="payment_note" id="note" rows="3"
            placeholder="Paste the full M-Pesa or bank message here"
            style="width:100%; padding:9px 11px; border:1px solid var(--line); border-radius:6px;
                   font-family:inherit; font-size:14px; resize:vertical"><?= e((string)($_POST['payment_note'] ?? $cert['payment_note'])) ?></textarea>
        </span>
      </label>
      <label class="field">Reference code <span class="req">*</span>
        <span class="field-row">
          <input name="payment_ref" id="ref" required maxlength="40"
                 value="<?= e((string)($_POST['payment_ref'] ?? $cert['payment_ref'])) ?>"
                 style="text-transform:uppercase">
        </span>
      </label>
      <p class="hint" style="text-align:left; margin:0">The reference is checked against every
         other installation certificate, so one payment cannot approve two.</p>
      <button type="submit" name="action" value="approve">Approve and issue the serial number</button>
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

<!-- ------------------------------------------------------------------ -->
<!-- Administrators: print, download, renew, void                       -->
<!-- ------------------------------------------------------------------ -->
<?php if ($isAdm): ?>
  <div class="row-actions" style="margin-top:22px; display:flex; flex-wrap:wrap; gap:10px">
    <?php if ($live): ?>
      <a class="button" href="tracking_print.php?id=<?= (int)$cert['id'] ?>" target="_blank">Print</a>
      <a class="button secondary" href="tracking_print.php?id=<?= (int)$cert['id'] ?>&amp;download=1">Download PDF</a>
    <?php elseif ($approved): ?>
      <a class="button secondary" href="tracking_print.php?id=<?= (int)$cert['id'] ?>" target="_blank">
        View (<?= e(strtolower($status)) ?>)</a>
    <?php endif; ?>

    <?php if ($approved && $cert['status'] !== 'voided'): ?>
      <button class="button renew-open" type="button">Renew subscription</button>
    <?php endif; ?>

    <?php if ($approved && $cert['status'] !== 'voided'): ?>
      <button class="button danger void-open" type="button">Void</button>
    <?php endif; ?>

    <?php if ($rejected): ?>
      <a class="button" href="tracking_new.php?reg=<?= urlencode((string)$cert['reg_no']) ?>">
        Raise it again</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- ================= dialogs ================= -->

<div class="trk-modal" id="m-note">
  <div class="trk-box">
    <h3>Payment message</h3>
    <p class="trk-msg"><?= nl2br(e((string)$cert['payment_note'])) ?></p>
    <div class="trk-foot"><button type="button" class="button secondary trk-close">Close</button></div>
  </div>
</div>

<?php if ($isAcc && $approved): ?>
<div class="trk-modal" id="m-pay">
  <form class="trk-box" method="post">
    <h3>Correct the payment details</h3>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">
    <label class="trk-f">Payment message
      <textarea name="payment_note" rows="4"><?= e((string)$cert['payment_note']) ?></textarea>
    </label>
    <label class="trk-f">Reference code <span class="req">*</span>
      <input name="payment_ref" required maxlength="40" style="text-transform:uppercase"
             value="<?= e((string)$cert['payment_ref']) ?>">
    </label>
    <p class="trk-hint">Correcting this is recorded against your name in the audit trail.</p>
    <div class="trk-foot">
      <button type="button" class="button secondary trk-close">Cancel</button>
      <button type="submit" name="action" value="update_ref" class="button">Save</button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if ($isAdm && $approved && $cert['status'] !== 'voided'): ?>
<div class="trk-modal" id="m-renew">
  <form class="trk-box" method="post">
    <h3>Renew the subscription</h3>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">
    <p class="trk-hint">Everything carries over from this certificate &mdash; vehicle, unit,
      customer and the original installation date. Only the subscription dates move.</p>
    <table class="details" style="margin:0 0 14px">
      <tr><th>Vehicle</th><td><?= e($cert['reg_no']) ?></td>
          <th>IMEI</th><td><?= e($cert['imei']) ?></td></tr>
      <tr><th>New subscription</th><td><?= e(fmt_date(date('Y-m-d'))) ?></td>
          <th>New expiry</th><td id="renew-expiry"><?= e(fmt_date(default_expiry(date('Y-m-d'), 12))) ?></td></tr>
    </table>
    <label class="trk-f">Subscription period
      <select name="term_months" id="renew-term">
        <?php foreach ([12 => '1 year', 24 => '2 years', 36 => '3 years',
                        48 => '4 years', 60 => '5 years'] as $m => $text): ?>
          <option value="<?= $m ?>"
                  data-expiry="<?= e(fmt_date(default_expiry(date('Y-m-d'), $m))) ?>">
            <?= e($text) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="trk-f">Payment message <span class="req">*</span>
      <textarea name="payment_note" rows="4" required
        placeholder="Paste the full M-Pesa or bank message here"></textarea>
    </label>
    <p class="trk-hint">The reference is read out of the message, so it cannot disagree with
      the confirmation it came from. The renewal goes to accounts for approval.</p>
    <div class="trk-foot">
      <button type="button" class="button secondary trk-close">Cancel</button>
      <button type="submit" name="action" value="renew" class="button">Create the renewal</button>
    </div>
  </form>
</div>

<div class="trk-modal" id="m-void">
  <form class="trk-box" method="post">
    <h3>Void <?= e(tracking_label($cert)) ?></h3>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">
    <p class="trk-hint">The certificate stays on record so its serial number is never reused,
      but it stops being valid and cannot be printed.</p>
    <label class="trk-f">Reason <span class="req">*</span>
      <input name="reason" required placeholder="e.g. unit removed, subscription cancelled">
    </label>
    <div class="trk-foot">
      <button type="button" class="button secondary trk-close">Cancel</button>
      <button type="submit" name="action" value="void" class="button danger">Void it</button>
    </div>
  </form>
</div>
<?php endif; ?>

<style>
/* Dialogs: centred, wide enough not to scroll, no backdrop. */
.trk-modal { display: none; position: fixed; inset: 0; z-index: 2000; }
.trk-modal.on { display: block; }
.trk-box {
  position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%);
  width: min(720px, calc(100vw - 32px));
  background: #fff; border: 1px solid #D7DEE5; border-radius: 12px;
  box-shadow: 0 18px 50px rgba(13, 79, 114, 0.28);
  padding: 22px 24px 26px;
}
.trk-box h3 { margin: 0 0 14px; font-size: 19px; color: #0D4F72; }
.trk-f { display: block; margin: 0 0 14px; font-size: 13px; font-weight: 600; color: #44505E; }
.trk-f textarea, .trk-f input {
  display: block; width: 100%; margin-top: 5px; box-sizing: border-box;
  padding: 9px 11px; border: 1px solid #C9D3DC; border-radius: 6px;
  font-family: inherit; font-size: 14px; font-weight: 400; resize: vertical;
}
.trk-hint { margin: 0 0 14px; font-size: 13px; color: #6B7786; line-height: 1.5; }
.trk-msg {
  margin: 0 0 18px; padding: 14px 16px; background: #F2F7FB;
  border-left: 4px solid #1890D0; border-radius: 6px;
  font-size: 14px; line-height: 1.6; color: #1D2530; word-break: break-word;
}
.trk-foot { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; }
.status-pill {
  display: inline-block; margin-left: 10px; padding: 3px 11px; border-radius: 999px;
  font-size: 13px; font-weight: 600; vertical-align: middle;
  background: #EEF2F6; color: #44505E;
}
.status-pill.status-valid { background: #E8F6E0; color: #4A7A18; }
.status-pill.status-expired, .status-pill.status-voided,
.status-pill.status-rejected { background: #FBE9E9; color: #A4121A; }
.status-pill.status-pending-approval { background: #FDF3DF; color: #8A5A00; }
</style>

<script>
(function () {
  function open(sel) { var m = document.querySelector(sel); if (m) { m.classList.add('on'); } }
  function closeAll() {
    document.querySelectorAll('.trk-modal.on').forEach(function (m) { m.classList.remove('on'); });
  }

  var opens = [['.pay-view', '#m-note'], ['.pay-edit', '#m-pay'],
               ['.renew-open', '#m-renew'], ['.void-open', '#m-void']];
  opens.forEach(function (pair) {
    document.querySelectorAll(pair[0]).forEach(function (btn) {
      btn.addEventListener('click', function (ev) { ev.preventDefault(); open(pair[1]); });
    });
  });

  // Show the expiry the chosen period produces. The server works it out again.
  var term = document.getElementById('renew-term');
  var cell = document.getElementById('renew-expiry');
  if (term && cell) {
    term.addEventListener('change', function () {
      cell.textContent = this.options[this.selectedIndex].getAttribute('data-expiry');
    });
  }

  document.querySelectorAll('.trk-close').forEach(function (btn) {
    btn.addEventListener('click', closeAll);
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') { closeAll(); }
  });
})();

// Suggest the reference from a pasted message, without ever overwriting a typed one.
(function () {
  var note = document.getElementById('note'), ref = document.getElementById('ref');
  if (!note || !ref) { return; }
  function suggest() {
    if (ref.value.trim() !== '') { return; }
    var m = note.value.toUpperCase().match(/\b([A-Z0-9]{10})\b/);
    if (m) { ref.value = m[1]; }
  }
  note.addEventListener('input', suggest);
  note.addEventListener('paste', function () { setTimeout(suggest, 50); });
  ref.addEventListener('input', function () { this.value = this.value.toUpperCase(); });
})();
</script>
<?php layout_bottom(); ?>
