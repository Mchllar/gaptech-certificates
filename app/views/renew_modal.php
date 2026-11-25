<?php
/**
 * The renewal popup, built to match the tracking module's dialogs.
 *
 * Include it once near the bottom of any page that has Renew buttons:
 *
 *     <?php require APP_DIR . '/views/renew_modal.php'; ?>
 *
 * The buttons carry the details, so one dialog serves a whole list:
 *
 *     <button type="button" class="renew-open link-button"
 *             data-id="12" data-reg="KBY 940M" data-cert="13661"
 *             data-client="ELDORET GRAIN CARRIERS LTD">Renew</button>
 *
 * ---------------------------------------------------------------------------
 * Two things this deliberately does NOT do
 * ---------------------------------------------------------------------------
 * 1. It does not let anything be edited. The vehicle, governor, serial and
 *    customer all carry over from the certificate being renewed - renew.php reads
 *    them from that certificate's snapshot and ignores the form entirely. So the
 *    dialog shows them as plain text rather than as fields, because a field you
 *    can type in is a promise that typing in it does something.
 *
 * 2. It has no subscription-period selector, unlike the tracking dialog. The
 *    certificate itself prints "This Certificate is valid for 12 months from date
 *    of Issuing", so offering two or five years would put the screen and the paper
 *    in direct contradiction. Tracking subscriptions genuinely can run longer;
 *    speed governor certificates cannot.
 *
 * The old version of this file used .modal / .modal-card markup whose styles were
 * defined somewhere else, which is why the header rendered as an empty navy block
 * and the help text wrapped ragged. Everything it needs is now in this one file.
 */

// The renewal's dates come from each Renew button (data-start / data-end), because
// they depend on when THAT certificate's cover ends - not on today.

// The dialog look and the open/close machinery. require_once, so a page with two
// dialogs still only emits it once.
require_once __DIR__ . '/modal_base.php';
?>
<div class="gt-modal" id="m-renew">
  <form class="gt-box" method="post" action="renew.php">
    <h3>Renew the certificate</h3>

    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" id="renew-id" value="">
    <input type="hidden" name="back"
           value="<?= e(basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'))) ?>">

    <p class="gt-hint">Everything carries over from this certificate &mdash; vehicle, governor,
      serial number and customer. Only the dates move.</p>

    <table class="details" style="margin:0 0 14px">
      <tr><th>Vehicle</th><td id="renew-reg">&mdash;</td>
          <th>Customer</th><td id="renew-client">&mdash;</td></tr>
      <tr><th>Renewing</th><td id="renew-cert">&mdash;</td>
          <th>Valid for</th><td>12 months</td></tr>
      <tr><th>New cover starts</th><td id="renew-start">&mdash;</td>
          <th>New expiry</th><td id="renew-end">&mdash;</td></tr>
    </table>

    <label class="gt-f">Payment message
      <textarea name="payment_note" id="renew-note" rows="4"
                placeholder="Paste it if you have it &mdash; or leave this to accounts"></textarea>
    </label>

    <label class="gt-f">LPO number
      <input name="lpo_no" id="renew-lpo" maxlength="60" style="text-transform:uppercase"
             placeholder="If the client is paying later against an LPO">
    </label>

    <label class="gt-f">Contract number
      <input name="contract_no" id="renew-contract" maxlength="60" style="text-transform:uppercase"
             placeholder="If the client is on a contract">
    </label>

    <p class="gt-hint">All optional &mdash; accounts can fill in any of them when they approve.
      The certificate takes its number, and becomes available to the client, only once
      they do.</p>

    <div class="gt-foot">
      <button type="button" class="button secondary gt-close">Cancel</button>
      <button type="submit" class="button">Create the renewal</button>
    </div>
  </form>
</div>

<script>
(function () {
  var modal = document.getElementById('m-renew');
  if (!modal) { return; }

  var field = {
    id:     document.getElementById('renew-id'),
    reg:    document.getElementById('renew-reg'),
    client: document.getElementById('renew-client'),
    cert:   document.getElementById('renew-cert'),
    note:   document.getElementById('renew-note'),
    lpo:    document.getElementById('renew-lpo'),
    contract: document.getElementById('renew-contract'),
    start:  document.getElementById('renew-start'),
    end:    document.getElementById('renew-end')
  };

  document.querySelectorAll('.renew-open').forEach(function (btn) {
    btn.addEventListener('click', function (ev) {
      ev.preventDefault();

      field.id.value           = btn.dataset.id || '';
      field.reg.textContent    = btn.dataset.reg || '—';
      field.client.textContent = btn.dataset.client || '—';
      field.cert.textContent   = btn.dataset.cert
          ? 'certificate ' + btn.dataset.cert : '—';
      // The dates the renewal will really have: the day after the current cover ends,
      // or today if it has already lapsed. Worked out by renewal_dates() on the server,
      // the same function renew.php saves with. This used to always say "today".
      field.start.textContent  = btn.dataset.start || '—';
      field.end.textContent    = btn.dataset.end || '—';

      // Cleared every time. A message left over from the last certificate you
      // looked at is the kind of thing nobody notices until it is on a receipt.
      field.note.value = '';
      field.lpo.value  = '';
      field.contract.value = '';

      modal.classList.add('on');
      field.note.focus();
    });
  });
})();
</script>