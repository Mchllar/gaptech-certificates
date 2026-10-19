<?php
/**
 * The client renewal popup.
 *
 * Goes in app/views/client_renew_modal.php
 *
 * Include it once near the bottom of client_portal.php:
 *
 *     <?php require APP_DIR . '/views/client_renew_modal.php'; ?>
 *
 * The buttons that open it need nothing but data attributes:
 *
 *     <button type="button" class="button renew-open"
 *             data-id="12" data-reg="KDG 567W">Renew</button>
 *
 * Every rule below is prefixed with #renew-modal. That is deliberate: an id
 * outranks the class selectors in the other stylesheets, so nothing here can be
 * restyled from a distance by a rule meant for forms or lists elsewhere.
 */

$gtPaybill = trim((string)setting('mpesa_paybill', ''));
$gtFee     = trim((string)setting('renewal_fee', '7000'));
?>
<div id="renew-modal" class="modal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="renew-title">
    <div class="modal-head">
      <h2 id="renew-title">Renew certificate</h2>
      <button type="button" class="modal-close" aria-label="Close">&times;</button>
    </div>

    <div class="modal-body">
      <?php if ($gtPaybill === ''): ?>

        <p class="rn-lead">Online renewal is not set up yet. Please call the office on
           <?= e(setting('cell', '')) ?> to renew.</p>

      <?php else: ?>

        <p class="rn-lead">On M-Pesa, choose <strong>Lipa na M-Pesa</strong>, then
           <strong>Pay Bill</strong>:</p>

        <dl class="rn-pay">
          <dt>Business number</dt><dd><?= e($gtPaybill) ?></dd>
          <dt>Account number</dt><dd id="renew-account">&mdash;</dd>
          <dt>Amount</dt><dd><?= e(number_format((float)$gtFee)) ?>/=</dd>
        </dl>

        <form method="post" action="client_renew.php">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="id" id="renew-id" value="">

          <label for="renew-note">Paste the M-Pesa message <span class="rn-req">*</span></label>
          <textarea name="payment_note" id="renew-note" rows="3" required
                    placeholder="TKG4YR77Q1 Confirmed. Ksh7,000.00 sent to ..."></textarea>

          <p class="rn-note">Your current certificate stays valid until the new one is
             confirmed, usually the same working day.</p>

          <div class="rn-actions">
            <button type="button" class="rn-cancel" data-close>Cancel</button>
            <button type="submit" class="rn-send">Send renewal</button>
          </div>
        </form>

      <?php endif; ?>
    </div>
  </div>
</div>

<style>
  #renew-modal .modal-card { padding: 0 0 20px; }
  /* Set here rather than inherited, so the white title can never end up on
     a white bar if the shared modal styling changes. */
  #renew-modal .modal-head {
    padding: 14px 20px; margin: 0;
    background: #0D4F72; border-radius: 12px 12px 0 0;
  }
  #renew-modal .modal-head h2 { color: #fff; font-size: 17px; margin: 0; }
  #renew-modal .modal-body { margin: 0; padding: 18px 20px 0; }

  #renew-modal .rn-lead {
    margin: 0 0 14px; font-size: 15px; line-height: 1.5; color: #3D4756;
  }

  /* Label and value on one line, values aligned. No boxes, no background. */
  #renew-modal .rn-pay {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 7px 16px;
    margin: 0 0 18px;
  }
  #renew-modal .rn-pay dt {
    margin: 0; font-size: 15px; color: #5C6775;
  }
  #renew-modal .rn-pay dd {
    margin: 0; font-size: 16px; font-weight: 700; color: #1C2430;
    font-variant-numeric: tabular-nums; letter-spacing: .3px;
  }

  #renew-modal label {
    display: block; margin: 0 0 6px;
    font-size: 14px; font-weight: 600; color: #3D4756;
    text-transform: none; letter-spacing: 0;
  }
  #renew-modal .rn-req { color: #C3121A; }

  #renew-modal textarea {
    display: block; width: 100%; box-sizing: border-box; margin: 0;
    padding: 10px 12px; border: 1px solid #C9D3DC; border-radius: 6px;
    font-family: inherit; font-size: 15px; line-height: 1.45; resize: vertical;
  }
  #renew-modal textarea:focus {
    outline: none; border-color: #1890D0; box-shadow: 0 0 0 3px rgba(24,144,208,.16);
  }

  #renew-modal .rn-note {
    margin: 10px 0 0; font-size: 13px; line-height: 1.5; color: #5C6775;
  }

  #renew-modal .rn-actions {
    display: flex; justify-content: flex-end; gap: 10px; margin: 18px 0 0;
  }
  #renew-modal .rn-actions button {
    font: inherit; font-size: 15px; font-weight: 600;
    padding: 10px 20px; border-radius: 8px; cursor: pointer;
  }
   #renew-modal form { margin: 0; padding: 0; }
  #renew-modal input[type="hidden"] { display: none !important; }
  #renew-modal .rn-pay { margin-bottom: 0; }
  #renew-modal .rn-cancel { border: 1px solid #C9D3DC; background: #fff; color: #0D4F72; }
  #renew-modal .rn-send   { border: 1px solid #0D4F72; background: #0D4F72; color: #fff; }
</style>

<script>
(function () {
  var modal = document.getElementById('renew-modal');
  if (!modal) { return; }

  var idField = document.getElementById('renew-id');
  var account = document.getElementById('renew-account');
  var note    = document.getElementById('renew-note');

  function show() {
    modal.removeAttribute('hidden');
    document.body.style.overflow = 'hidden';
    var close = modal.querySelector('.modal-close');
    if (close) { close.focus(); }
  }
  function shut() {
    modal.setAttribute('hidden', '');
    document.body.style.overflow = '';
  }

  document.querySelectorAll('.renew-open').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (idField) { idField.value = btn.dataset.id || ''; }
      if (account) { account.textContent = btn.dataset.reg || '—'; }
      if (note)    { note.value = ''; }
      show();
    });
  });

  modal.addEventListener('click', function (ev) {
    if (ev.target === modal
        || ev.target.classList.contains('modal-close')
        || ev.target.closest('[data-close]')) { shut(); }
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && !modal.hasAttribute('hidden')) { shut(); }
  });
})();
</script>
