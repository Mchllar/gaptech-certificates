<?php
/**
 * Renew panel for the client portal.
 *
 * Goes in app/views/client_renew_panel.php
 * It REPLACES client_renew_modal.php - delete that file once this is in.
 *
 * Include it once, just above the certificates table in client_portal.php:
 *
 *     <?php require APP_DIR . '/views/client_renew_panel.php'; ?>
 *
 * Nothing else to add. It works out for itself which of this client's vehicles
 * need renewing, so the portal page needs no extra variables.
 *
 * A button sits above the table. Clicking it opens the payment details and the
 * paste box underneath, in the page - no popup, nothing to scroll.
 *
 * Every rule is prefixed with #renew-box. An id outranks the class selectors in
 * the other stylesheets, so nothing here can be restyled from a distance.
 */

$gtPaybill = trim((string)setting('mpesa_paybill', ''));
$gtFee     = trim((string)setting('renewal_fee', '7000'));

/**
 * The client's vehicles that need renewing: nothing valid, and nothing already
 * waiting with accounts. Grouped on letters and digits only, so the same plate
 * stored two ways counts once.
 */
$gtRenewable = [];
foreach (q("SELECT regexp_replace(upper(v.reg_no),'[^A-Z0-9]','','g') AS plate,
                   (array_agg(v.reg_no ORDER BY c.issue_date DESC, c.id DESC))[1] AS reg_no,
                   bool_or(c.approval_status = 'approved' AND c.status = 'issued'
                           AND c.expiry_date >= current_date) AS has_valid,
                   bool_or(c.approval_status = 'pending') AS has_pending,
                   (array_agg(c.id ORDER BY c.issue_date DESC, c.id DESC))[1] AS latest_id
            FROM certificates c
            JOIN vehicles v ON v.id = c.vehicle_id
            WHERE c.client_id = ?
            GROUP BY 1
            ORDER BY 2", [$client['id']])->fetchAll() as $gtRow) {
    if (!$gtRow['has_valid'] && !$gtRow['has_pending']) {
        $gtRenewable[] = $gtRow;
    }
}

// Nothing to renew: say nothing at all.
if (!$gtRenewable) {
    return;
}
?>
<div id="renew-box">
  <button type="button" class="rn-toggle" aria-expanded="false" aria-controls="renew-panel">
    Renew a certificate
  </button>

  <div class="rn-panel" id="renew-panel" hidden>
    <?php if ($gtPaybill === ''): ?>

      <p class="rn-lead">Online renewal is not available yet. Please call the office on
         <?= e(setting('cell', '')) ?> to renew.</p>

    <?php else: ?>

      <form method="post" action="client_renew.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <?php if (count($gtRenewable) > 1): ?>
          <label for="renew-pick">Which vehicle?</label>
          <select name="id" id="renew-pick">
            <?php foreach ($gtRenewable as $gtRow): ?>
              <option value="<?= (int)$gtRow['latest_id'] ?>"
                      data-reg="<?= e($gtRow['reg_no']) ?>"><?= e($gtRow['reg_no']) ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input type="hidden" name="id" value="<?= (int)$gtRenewable[0]['latest_id'] ?>">
        <?php endif; ?>

        <p class="rn-lead">On M-Pesa, choose <strong>Lipa na M-Pesa</strong>, then
           <strong>Pay Bill</strong>:</p>

        <dl class="rn-pay">
          <dt>Business number</dt><dd><?= e($gtPaybill) ?></dd>
          <dt>Account number</dt>
          <dd id="renew-account"><?= e($gtRenewable[0]['reg_no']) ?></dd>
          <dt>Amount</dt><dd><?= e(number_format((float)$gtFee)) ?>/=</dd>
        </dl>

        <label for="renew-note">Paste the M-Pesa message <span class="rn-req">*</span></label>
        <textarea name="payment_note" id="renew-note" rows="3" required
                  placeholder="TKG4YR77Q1 Confirmed. Ksh7,000.00 sent to ..."></textarea>

        <p class="rn-note">Your current certificate stays valid until the new one is
           confirmed, usually the same working day.</p>

        <div class="rn-actions">
          <button type="button" class="rn-cancel">Cancel</button>
          <button type="submit" class="rn-send">Send renewal</button>
        </div>
      </form>

    <?php endif; ?>
  </div>
</div>

<style>
  #renew-box { margin: 0 0 20px; }

  #renew-box .rn-toggle {
    font: inherit; font-size: 15px; font-weight: 600;
    padding: 11px 20px; border-radius: 8px; cursor: pointer;
    border: 1px solid #0D4F72; background: #0D4F72; color: #fff;
  }
  #renew-box .rn-toggle[aria-expanded="true"] { background: #0A4260; }

  #renew-box .rn-panel {
    margin: 14px 0 0;
    padding: 20px 22px;
    background: #fff;
    border: 1px solid #E4EBF2;
    border-radius: 12px;
    max-width: 620px;
  }
  #renew-box .rn-panel[hidden] { display: none; }

  #renew-box form { margin: 0; padding: 0; }
  #renew-box input[type="hidden"] { display: none !important; }

  #renew-box .rn-lead {
    margin: 0 0 14px; font-size: 15px; line-height: 1.5; color: #3D4756;
  }

  /* Label and value on one line, values aligned. No boxes, no background. */
  #renew-box .rn-pay {
    display: grid; grid-template-columns: max-content 1fr; gap: 7px 16px;
    margin: 0 0 18px;
  }
  #renew-box .rn-pay dt { margin: 0; font-size: 15px; color: #5C6775; }
  #renew-box .rn-pay dd {
    margin: 0; font-size: 16px; font-weight: 700; color: #1C2430;
    font-variant-numeric: tabular-nums; letter-spacing: .3px;
  }

  #renew-box label {
    display: block; margin: 0 0 6px;
    font-size: 14px; font-weight: 600; color: #3D4756;
    text-transform: none; letter-spacing: 0;
  }
  #renew-box .rn-req { color: #C3121A; }

  #renew-box select,
  #renew-box textarea {
    display: block; width: 100%; box-sizing: border-box; margin: 0 0 16px;
    padding: 10px 12px; border: 1px solid #C9D3DC; border-radius: 6px;
    font-family: inherit; font-size: 15px; line-height: 1.45; color: #1C2430;
    background: #fff;
  }
  #renew-box textarea { margin-bottom: 0; resize: vertical; }
  #renew-box select:focus,
  #renew-box textarea:focus {
    outline: none; border-color: #1890D0; box-shadow: 0 0 0 3px rgba(24,144,208,.16);
  }

  #renew-box .rn-note {
    margin: 10px 0 0; font-size: 13px; line-height: 1.5; color: #5C6775;
  }

  #renew-box .rn-actions {
    display: flex; justify-content: flex-end; gap: 10px; margin: 18px 0 0;
  }
  #renew-box .rn-actions button {
    font: inherit; font-size: 15px; font-weight: 600;
    padding: 10px 20px; border-radius: 8px; cursor: pointer;
  }
  #renew-box .rn-cancel { border: 1px solid #C9D3DC; background: #fff; color: #0D4F72; }
  #renew-box .rn-send   { border: 1px solid #0D4F72; background: #0D4F72; color: #fff; }

  @media (max-width: 560px) {
    #renew-box .rn-toggle { width: 100%; }
    #renew-box .rn-panel { padding: 16px; }
    #renew-box .rn-pay { grid-template-columns: 1fr; gap: 2px 0; }
    #renew-box .rn-pay dd { margin-bottom: 8px; }
    #renew-box .rn-actions { flex-direction: column-reverse; }
    #renew-box .rn-actions button { width: 100%; }
  }
</style>

<script>
(function () {
  var box = document.getElementById('renew-box');
  if (!box) { return; }

  var toggle  = box.querySelector('.rn-toggle');
  var panel   = document.getElementById('renew-panel');
  var cancel  = box.querySelector('.rn-cancel');
  var pick    = document.getElementById('renew-pick');
  var account = document.getElementById('renew-account');
  var note    = document.getElementById('renew-note');

  function open(yes) {
    panel.hidden = !yes;
    toggle.setAttribute('aria-expanded', yes ? 'true' : 'false');
    if (yes && note) { note.focus(); }
  }

  toggle.addEventListener('click', function () { open(panel.hidden); });
  if (cancel) {
    cancel.addEventListener('click', function () {
      if (note) { note.value = ''; }
      open(false);
      toggle.focus();
    });
  }

  // The account number is the registration, so it follows the chosen vehicle.
  if (pick && account) {
    pick.addEventListener('change', function () {
      account.textContent = this.options[this.selectedIndex].getAttribute('data-reg');
    });
  }
})();
</script>
