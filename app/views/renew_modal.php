<?php
/**
 * The renewal popup. Include it once near the bottom of any page that has
 * Renew buttons:
 *
 *     <?php require APP_DIR . '/views/renew_modal.php'; ?>
 *
 * The buttons themselves need nothing but data attributes:
 *
 *     <button type="button" class="link-button renew-open"
 *             data-id="12" data-reg="KBY 940M" data-cert="13661"
 *             data-client="ELDORET GRAIN CARRIERS LTD">Renew</button>
 */

$gtRenewExpiry = date('Y-m-d', strtotime('+1 year -1 day'));
?>
<div id="renew-modal" class="modal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="renew-title">
    <div class="modal-head">
      <h2 id="renew-title" style="color:white;">Renew Certificate</h2>
      <button type="button" class="modal-close" aria-label="Close">&times;</button>
    </div>

    <div class="modal-body">
      <p class="modal-meta" id="renew-what"></p>
      <form method="post" action="renew.php" class="pay-edit">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" id="renew-id" value="">
        <input type="hidden" name="back"
               value="<?= e(basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'))) ?>">

        <label>Payment message <span class="req">*</span>
          <textarea name="payment_note" id="renew-note" rows="4" required
                    placeholder="Paste the M-Pesa or bank confirmation here"></textarea>
        </label>

        <p class="warn-note">Issued today, expiring on <?= e(fmt_date($gtRenewExpiry)) ?>.</p>
        <div class="pay-edit-actions" >
          <button type="submit">Create renewal</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('renew-modal');
  if (!modal) { return; }

  var idField = document.getElementById('renew-id');
  var what    = document.getElementById('renew-what');
  var note    = document.getElementById('renew-note');

  function shut() {
    modal.setAttribute('hidden', '');
    document.body.style.overflow = '';
  }

  function extractRef(text) {
    var up = (text || '').toUpperCase();
    var m = up.match(/M[-\s]?PESA\s*REF[:.\s]*([A-Z0-9]{8,15})/)
         || up.match(/\bREF(?:ERENCE)?[:.\s]*([A-Z0-9]{8,15})/)
         || up.match(/\b([A-Z0-9]{10})\b/);
    if (!m) { return ''; }
    // a real reference mixes letters and digits - this skips phone numbers and amounts
    return (/[0-9]/.test(m[1]) && /[A-Z]/.test(m[1])) ? m[1] : '';
  }

  document.querySelectorAll('.renew-open').forEach(function (btn) {
    btn.addEventListener('click', function () {
      idField.value = btn.dataset.id || '';
      what.textContent = 'Renewing ' + (btn.dataset.reg || '')
        + (btn.dataset.client ? ' \n ' + btn.dataset.client : '')
        + (btn.dataset.cert ? ' \nCertificate ' + btn.dataset.cert : '');

      note.value = '';
      manual = false;

      modal.removeAttribute('hidden');
      document.body.style.overflow = 'hidden';
      note.focus();
    });
  });

  modal.addEventListener('click', function (ev) {
    if (ev.target === modal || ev.target.classList.contains('modal-close')) { shut(); }
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && !modal.hasAttribute('hidden')) { shut(); }
  });
})();
</script>
