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
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($fields as $key => $label) {
        set_setting($key, trim((string)($_POST[$key] ?? '')));
    }
    // Signature and stamp images, stored as data URIs so they travel with the PDF.
    foreach (['signature_image' => 'signature', 'stamp_image' => 'stamp'] as $key => $input) {
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
    audit('update_settings', 'settings');
    flash('Settings saved. New certificates will use them; certificates already issued are unchanged.');
    redirect('settings.php');
}

layout_top('Settings');
layout_back('index.php', 'Back to certificates');
?>
<h1>Settings</h1>
<p class="hint">These values are printed on new certificates. Certificates already issued keep the details
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
          'stamp'     => ['Company stamp', setting('stamp_image', '')],
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
  <button type="submit">Save settings</button>
</form>
<?php layout_bottom(); ?>