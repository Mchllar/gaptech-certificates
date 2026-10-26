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

$gtRenewIssue  = date('Y-m-d');
$gtRenewExpiry = date('Y-m-d', strtotime($gtRenewIssue . ' +1 year -1 day'));

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
      <tr><th>New issue</th><td><?= e(fmt_date($gtRenewIssue)) ?></td>
          <th>New expiry</th><td><?= e(fmt_date($gtRenewExpiry)) ?></td></tr>
    </table>

    <label class="gt-f">Payment message
      <textarea name="payment_note" id="renew-note" rows="4"
                placeholder="Paste it if you have it &mdash; or leave this to accounts"></textarea>
    </label>

    <label class="gt-f">LPO number
      <input name="lpo_no" id="renew-lpo" maxlength="60" style="text-transform:uppercase"
             placeholder="If the client is paying later against an LPO">
    </label>

    <p class="gt-hint">Both are optional &mdash; accounts can fill in either when they approve.
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
    lpo:    document.getElementById('renew-lpo')
  };

  document.querySelectorAll('.renew-open').forEach(function (btn) {
    btn.addEventListener('click', function (ev) {
      ev.preventDefault();

      field.id.value           = btn.dataset.id || '';
      field.reg.textContent    = btn.dataset.reg || '—';
      field.client.textContent = btn.dataset.client || '—';
      field.cert.textContent   = btn.dataset.cert
          ? 'certificate ' + btn.dataset.cert : '—';

      // Cleared every time. A message left over from the last certificate you
      // looked at is the kind of thing nobody notices until it is on a receipt.
      field.note.value = '';
      field.lpo.value  = '';

      modal.classList.add('on');
      field.note.focus();
    });
  });
})();
</script>