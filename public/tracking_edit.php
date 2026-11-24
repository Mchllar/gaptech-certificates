<?php
/**
 * Correct a tracking certificate: any detail, with a reason.
 *
 * Administrators only. The serial number and the payment are not here (accounts own
 * those). Every change - from what, to what, by whom and why - goes to the audit log and
 * shows on the certificate page as its edit history. The record and the printable copy
 * change together, and any stored PDF is redrawn on its next download.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/tracking.php';
require APP_DIR . '/views/layout.php';

$user = require_admin();
$id   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$cert = q('SELECT * FROM tracking_certificates WHERE id = ?', [$id])->fetch();
if (!$cert) {
    flash('That certificate could not be found.');
    redirect('tracking_list.php');
}

$locked = in_array((string)$cert['status'], ['voided', 'superseded'], true)
       || ($cert['approval_status'] ?? '') === 'rejected';
$errors = [];
$form   = trk_current_values($cert);
$reason = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {
    csrf_check();
    foreach (array_keys(TRK_EDITABLE) as $k) {
        $form[$k] = trim((string)($_POST[$k] ?? $form[$k]));
    }
    $reason = trim((string)($_POST['reason'] ?? ''));
    try {
        $changes = trk_amend($cert, $form, $user, $reason);
        flash(count($changes) . ' detail' . (count($changes) === 1 ? '' : 's') . ' changed ('
            . implode(', ', array_map(fn($k) => TRK_EDITABLE[$k], array_keys($changes)))
            . '). The reason is kept with the certificate.');
        redirect('tracking_view.php?id=' . $id);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$printed = (int)($cert['print_count'] ?? 0);
$approved = ($cert['approval_status'] ?? '') === 'approved';

/** One labelled box. */
function te_field(string $k, string $value, array $o = []): void
{
    $req = $o['required'] ?? true;
    echo '<label class="field">' . e(TRK_EDITABLE[$k]) . ($req ? ' <span class="req">*</span>' : '')
       . '<span class="field-row"><input name="' . e($k) . '" type="' . e($o['type'] ?? 'text') . '"'
       . ' value="' . e($value) . '" data-was="' . e($value) . '"'
       . ($req ? ' required' : '') . (!empty($o['caps']) ? ' class="caps"' : '')
       . (isset($o['list']) ? ' list="' . e($o['list']) . '" autocomplete="on"' : '')
       . (isset($o['inputmode']) ? ' inputmode="' . e($o['inputmode']) . '"' : '')
       . '></span>';
    if (!empty($o['hint'])) { echo '<span class="field-hint">' . e($o['hint']) . '</span>'; }
    echo '</label>';
}

layout_top('Edit ' . tracking_label($cert));
layout_back('tracking_view.php?id=' . $id, 'Back to the certificate');
?>
<style>
  .te-head h1 { margin: 0 0 6px; font-size: 26px; font-weight: 700; letter-spacing: -.4px; color: #0D4F72;
                padding-left: 12px; border-left: 4px solid #8CC63F; }
  .te-head p { margin: 0 0 16px; font-size: 14px; color: #5C6775; line-height: 1.5; }
  .te-warn { margin: 0 0 16px; padding: 11px 14px; border-radius: 10px; background: #FFF6E5;
             border: 1px solid #F2DDB0; color: #6B4A00; font-size: 13.5px; line-height: 1.5; }
  /* Two fields to a row where there is room, so the whole certificate fits on a screen. */
  #teForm fieldset { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 0 22px; }
  #teForm fieldset.te-reason { display: block; }
  #teForm label.field { max-width: none; }
  #teForm label.field input { width: 100%; max-width: 100%; }
  main form.card-form input.is-changed { border-color: #E0912F !important; background: #FFFBF3 !important; }
  .te-changed { margin: 0 0 12px; font-size: 13px; color: #8A5A00; min-height: 1.2em; }
  #teForm .te-reason label.field, #teForm .te-reason .field-row { display: block; width: 100%; max-width: 760px; }
  #teForm .te-reason textarea { width: 100%; max-width: 760px; min-height: 70px; padding: 10px 12px; border: 1px solid #D9E2EC;
                        border-radius: 9px; font: inherit; font-size: 14px; resize: vertical; }
</style>

<div class="te-head">
  <h1>Edit <?= e(tracking_label($cert)) ?> &middot; <?= e((string)$cert['reg_no']) ?></h1>
  <p>Correct any detail below and say why. The record and the printed certificate change together,
     and the change &mdash; from what, to what, and why &mdash; is kept with the certificate.
     The serial number and the payment are not changed here.</p>
</div>

<?php if ($locked): ?>
  <p class="error">This certificate is <?= e(($cert['approval_status'] ?? '') === 'rejected' ? 'rejected' : (string)$cert['status']) ?>
     and cannot be changed. <?= ($cert['approval_status'] ?? '') === 'rejected' ? 'Raise a new one instead.' : 'Edit the current certificate for this vehicle instead.' ?></p>
<?php else: ?>

<?php if ($approved && $printed > 0): ?>
  <p class="te-warn"><strong>This certificate has been printed <?= $printed ?> time<?= $printed === 1 ? '' : 's' ?>.</strong>
     Copies already handed out will not show the change: print it again after saving and replace them.</p>
<?php elseif ($approved): ?>
  <p class="te-warn">This certificate is already issued. The verification page and any new print or
     download will show the corrected details straight away.</p>
<?php endif; ?>

<?php foreach ($errors as $m): ?><p class="error"><?= e($m) ?></p><?php endforeach; ?>

<form method="post" class="card-form" autocomplete="off" id="teForm">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="id" value="<?= $id ?>">

  <fieldset>
    <legend>Customer</legend>
    <?php te_field('client_name', $form['client_name'], ['caps' => true, 'hint' => 'Shown in lists and searches.']); ?>
    <?php te_field('client_phone', $form['client_phone'], ['required' => false, 'type' => 'tel']); ?>
  </fieldset>

  <fieldset>
    <legend>Vehicle</legend>
    <?php te_field('reg_no', $form['reg_no'], ['caps' => true]); ?>
    <?php te_field('civilian_plate', $form['civilian_plate'], ['required' => false, 'caps' => true,
          'hint' => 'Government vehicles only. Kept on the record, not printed.']); ?>
    <?php te_field('make_model', $form['make_model'], ['caps' => true]); ?>
    <?php te_field('chassis_no', $form['chassis_no'], ['caps' => true]); ?>
    <?php te_field('engine_no', $form['engine_no'], ['caps' => true]); ?>
    <?php te_field('colour', $form['colour'], ['caps' => true]); ?>
    <?php te_field('ownership', $form['ownership'], ['caps' => true]); ?>
    <?php te_field('financier', $form['financier'], ['required' => false, 'caps' => true, 'list' => 'te-banks',
          'hint' => 'Blank prints N/A. Any spelling of a known bank prints its standard name.']); ?>
    <datalist id="te-banks">
      <?php foreach (array_unique(array_values(trk_financier_names())) as $bank): ?><option value="<?= e($bank) ?>"><?php endforeach; ?>
    </datalist>
  </fieldset>

  <fieldset>
    <legend>Units</legend>
    <?php te_field('imei', $form['imei'], ['inputmode' => 'numeric', 'hint' => '10 to 17 digits.']); ?>
    <?php te_field('imei_2', $form['imei_2'], ['required' => false, 'hint' => 'Only if a second unit is fitted.']); ?>
    <?php te_field('sim_card', $form['sim_card'], ['required' => false, 'hint' => 'Kept on the record, not printed.']); ?>
  </fieldset>

  <fieldset>
    <legend>Dates</legend>
    <?php te_field('installed_on', $form['installed_on'], ['type' => 'date']); ?>
    <?php te_field('subscription_date', $form['subscription_date'], ['type' => 'date']); ?>
    <?php te_field('expiry_date', $form['expiry_date'], ['type' => 'date',
          'hint' => 'Set directly - it is not worked out again from the subscription date.']); ?>
  </fieldset>

  <fieldset class="te-reason">
    <legend>Why</legend>
    <p class="te-changed" id="teChanged" aria-live="polite"></p>
    <label class="field">Reason for the change <span class="req">*</span>
      <span class="field-row">
        <textarea name="reason" required minlength="5" maxlength="300"
                  placeholder="e.g. Financier corrected to Equity Bank per the customer's logbook"><?= e($reason) ?></textarea>
      </span>
      <span class="field-hint">Kept with the certificate and shown in its history.</span>
    </label>
    <button type="submit" id="teSave">Save changes</button>
  </fieldset>
</form>

<script>
(function () {
  // Typed in capitals where the certificate prints capitals.
  document.querySelectorAll('#teForm input.caps').forEach(function (i) {
    i.addEventListener('input', function () {
      var p = i.selectionStart; i.value = i.value.toUpperCase(); i.setSelectionRange(p, p);
    });
  });
  // Show what is about to change, so nothing is altered by accident.
  var inputs = document.querySelectorAll('#teForm input[data-was]');
  var note = document.getElementById('teChanged'), save = document.getElementById('teSave');
  function mark() {
    var changed = [];
    inputs.forEach(function (i) {
      var on = i.value.trim() !== i.dataset.was;
      i.classList.toggle('is-changed', on);
      if (on) { changed.push(i.closest('label').firstChild.textContent.trim()); }
    });
    note.textContent = changed.length ? 'Changing: ' + changed.join(', ') + '.' : 'Nothing changed yet.';
    save.textContent = changed.length ? 'Save ' + changed.length + ' change' + (changed.length === 1 ? '' : 's') : 'Save changes';
  }
  inputs.forEach(function (i) { i.addEventListener('input', mark); i.addEventListener('change', mark); });
  mark();
})();
</script>
<?php endif; ?>
<?php layout_bottom(); ?>
