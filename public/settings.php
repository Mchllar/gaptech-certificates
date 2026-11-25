<?php
/** Company details printed on every certificate, plus the certificate counter. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require APP_DIR . '/views/layout.php';

require_supervisor();

$fields = [
    'company_name' => 'Company name', 'tagline' => 'Tagline',
    'address_line1' => 'Address line 1', 'address_line2' => 'Address line 2',
    'cell' => 'Cell', 'landline' => 'Landline', 'email' => 'E-mail',
    'reg_no' => 'Company reg. no.', 'dealer_no' => 'Dealer no.', 'dealer_name' => 'Dealer name',
    'kebs_permit' => 'KEBS permit', 'default_model' => 'Default governor model',
    'default_speed' => 'Default set speed (km/h)', 'last_cert_number' => 'Last certificate number used',
    'default_technician' => 'Default technician / agent',
    // Tracking certificates: how each bank is printed, and which agreements add months.
    'financier_names' => 'Financier names (PRINTED NAME = spelling | spelling ; next bank)',
    'financier_bonus' => 'Financier extra months on first installation (NAME:MONTHS, ...)',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($fields as $key => $label) {
        set_setting($key, trim((string)($_POST[$key] ?? '')));
    }
    // Signature and stamp images, stored as data URIs so they travel with the PDF.
    foreach (['signature_image' => 'signature', 'stamp_image' => 'stamp',
              'tracking_stamp_image' => 'tracking_stamp'] as $key => $input) {
        if (!empty($_FILES[$input]['tmp_name']) && is_uploaded_file($_FILES[$input]['tmp_name'])) {
            $type = mime_content_type($_FILES[$input]['tmp_name']);
            if (!in_array($type, ['image/png', 'image/jpeg'], true)) {
                flash('Signature and stamp must be PNG or JPEG.');
                redirect('settings.php');
            }
            set_setting($key, 'data:' . $type . ';base64,'
                . base64_encode(file_get_contents($_FILES[$input]['tmp_name'])));
        }
        if (!empty($_POST['clear_' . $input])) {
            set_setting($key, '');
        }
    }
    // Tracking certificates: the usual paper, and the printer's nudge in mm.
    set_setting('tracking_print_stock',
        ($_POST['tracking_print_stock'] ?? '') === 'preprinted' ? 'preprinted' : 'plain');
    foreach (['print_offset_x', 'print_offset_y'] as $key) {
        $mmv = (float)str_replace(',', '.', (string)($_POST[$key] ?? '0'));
        set_setting($key, (string)max(-15, min(15, round($mmv, 1))));   // more than 15mm is a wrong setting, not a drift
    }
    $pt = (float)str_replace(',', '.', (string)($_POST['preprinted_font_pt'] ?? '13'));
    set_setting('preprinted_font_pt', (string)max(7, min(18, round($pt, 1))));
    set_setting('tracking_print_stamp', !empty($_POST['tracking_print_stamp']) ? '1' : '0');
    set_setting('tracking_print_signature', !empty($_POST['tracking_print_signature']) ? '1' : '0');
    // Speed governor certificates: the same choices, for the landscape sheet.
    set_setting('governor_print_stock',
        ($_POST['governor_print_stock'] ?? '') === 'preprinted' ? 'preprinted' : 'plain');
    set_setting('governor_print_signature', !empty($_POST['governor_print_signature']) ? '1' : '0');
    foreach (['governor_offset_x', 'governor_offset_y'] as $key) {
        $mmv = (float)str_replace(',', '.', (string)($_POST[$key] ?? '0'));
        set_setting($key, (string)max(-15, min(15, round($mmv, 1))));
    }
    audit('update_settings', 'settings');
    flash('Settings saved. New certificates will use them; certificates already issued are unchanged.');
    redirect('settings.php');
}

layout_top('');
layout_back('index.php', 'Back to SG. Certificates');
?>
<style>
    .vfy-title { margin: 0 0 6px; font-size: 22px; font-weight: 700; letter-spacing: -.4px; color: #1C2430; line-height: 1.25; }
        /* ---- a little wider once there is room ---- */
    .vfy h1.vfy-title {
        margin: 0 0 6px;
        font-size: 22px; font-weight: 700; letter-spacing: -.4px;
        line-height: 1.25; color: #0D4F72;
        padding-left: 12px; border-left: 4px solid #8CC63F;
    }
    @media (min-width: 520px) { .vfy h1.vfy-title { font-size: 26px; } }
    .vfy-sub { font-size: 14px; color: #7A8696; line-height: 1.5;}
</style>
<div class="vfy">
<h1  class="vfy-title">SG. Settings</h1>
<p class="vfy-sub">These values are printed on new certificates. Certificates already issued keep the details
they were issued with.</p>

<form method="post" enctype="multipart/form-data" class="card-form">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <fieldset>
    <legend>Company</legend>
    <?php foreach ($fields as $key => $label): ?>
      <label><?= e($label) ?> <input name="<?= e($key) ?>" value="<?= e(setting($key, '')) ?>"></label>
    <?php endforeach; ?>
  </fieldset>
  <fieldset>
    <legend>Signature and stamp</legend>
    <p class="hint" style="text-align:left">PNG with a transparent background works best.
       Leave the file box empty to keep the current image.</p>

    <div class="img-settings">
      <?php foreach ([
          'signature' => ['Signature', setting('signature_image', '')],
          'stamp'     => ['Stamp on speed governor certificates', setting('stamp_image', '')],
          'tracking_stamp' => ['Stamp on tracking certificates', setting('tracking_stamp_image', '')],
      ] as $field => $info): ?>
        <div class="img-setting">
          <span class="img-label"><?= e($info[0]) ?></span>
          <?php if ($info[1] !== ''): ?>
            <div class="img-preview"><img src="<?= e($info[1]) ?>" alt="Current <?= e(strtolower($info[0])) ?>"></div>
          <?php else: ?>
            <div class="img-preview img-empty">Nothing uploaded yet</div>
          <?php endif; ?>
          <label>Replace it
            <input type="file" name="<?= e($field) ?>" accept="image/png,image/jpeg"></label>
          <?php if ($info[1] !== ''): ?>
            <label class="inline">
              <input type="checkbox" name="clear_<?= e($field) ?>" value="1"> Remove it</label>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </fieldset>
  <fieldset>
    <legend>Printing speed governor certificates</legend>
    <label>Usual paper
      <select name="governor_print_stock">
        <option value="plain" <?= setting('governor_print_stock', 'plain') !== 'preprinted' ? 'selected' : '' ?>>
          Plain paper &mdash; the whole certificate prints</option>
        <option value="preprinted" <?= setting('governor_print_stock', 'plain') === 'preprinted' ? 'selected' : '' ?>>
          Pre-printed certificates &mdash; only the entries print</option>
      </select>
    </label>
    <label class="inline" style="display:flex; gap:8px; align-items:center">
      <input type="checkbox" name="governor_print_signature" value="1" style="width:16px; margin:0"
             <?= setting('governor_print_signature', '1') === '1' ? 'checked' : '' ?>>
      Print the signature (changeable on each print; a PDF always has it)</label>
    <p class="hint" style="text-align:left">If the entries land off the lines on the pre-printed sheet,
       print the <a href="certificate_print.php?align=1" target="_blank">alignment test</a> onto a spoiled
       blank and read the rulers. Millimetres; minus for left or up. Separate from the tracking
       adjustment, because this sheet prints landscape.</p>
    <label>Move right by (mm)
      <input name="governor_offset_x" type="number" step="0.5" min="-15" max="15"
             value="<?= e(setting('governor_offset_x', '0')) ?>"></label>
    <label>Move down by (mm)
      <input name="governor_offset_y" type="number" step="0.5" min="-15" max="15"
             value="<?= e(setting('governor_offset_y', '0')) ?>"></label>
  </fieldset>
  <fieldset>
    <legend>Printing tracking certificates</legend>
    <label>Usual paper
      <select name="tracking_print_stock">
        <option value="plain" <?= setting('tracking_print_stock', 'plain') !== 'preprinted' ? 'selected' : '' ?>>
          Plain paper &mdash; the whole certificate prints</option>
        <option value="preprinted" <?= setting('tracking_print_stock', 'plain') === 'preprinted' ? 'selected' : '' ?>>
          Pre-printed certificates &mdash; only the entries print</option>
      </select>
    </label>
    <p class="hint" style="text-align:left">Both print buttons are always on the certificate;
       this only decides which comes first.</p>
    <label class="inline" style="display:flex; gap:8px; align-items:center">
      <input type="checkbox" name="tracking_print_stamp" value="1" style="width:16px; margin:0"
             <?= setting('tracking_print_stamp', '1') === '1' ? 'checked' : '' ?>>
      Print the stamp (it can still be ticked or unticked on each print; a PDF always has it)</label>
    <label class="inline" style="display:flex; gap:8px; align-items:center">
      <input type="checkbox" name="tracking_print_signature" value="1" style="width:16px; margin:0"
             <?= setting('tracking_print_signature', '1') === '1' ? 'checked' : '' ?>>
      Print the signature (the same: changeable on each print; a PDF always has it)</label>
    <label>Size of the entries on a pre-printed certificate (points, as in Word)
      <input name="preprinted_font_pt" type="number" step="0.5" min="7" max="18"
             value="<?= e(setting('preprinted_font_pt', '13')) ?>"></label>
    <p class="hint" style="text-align:left">If the entries land off the boxes on a pre-printed
       certificate, print the <a href="tracking_print.php?align=1" target="_blank">alignment test</a>
       onto a spoiled blank and read the rulers. Millimetres; use a minus for left or up. It applies
       to every pre-printed print from now on.</p>
    <label>Move right by (mm)
      <input name="print_offset_x" type="number" step="0.5" min="-15" max="15"
             value="<?= e(setting('print_offset_x', '0')) ?>"></label>
    <label>Move down by (mm)
      <input name="print_offset_y" type="number" step="0.5" min="-15" max="15"
             value="<?= e(setting('print_offset_y', '0')) ?>"></label>
  </fieldset>
  <button type="submit">Save settings</button>
</form>
</div>
<?php layout_bottom(); ?>