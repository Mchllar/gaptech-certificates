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
    'kebs_permit' => 'KEBS permit', 'signatory_name' => 'Authorised officer',
    'signatory_title' => 'Officer title', 'default_model' => 'Default governor model',
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
    <p class="hint">PNG with a transparent background works best. Leave empty to keep the current image.</p>
    <label>Signature image <input type="file" name="signature" accept="image/png,image/jpeg"></label>
    <label><input type="checkbox" name="clear_signature" value="1"> Remove the current signature</label>
    <label>Stamp image <input type="file" name="stamp" accept="image/png,image/jpeg"></label>
    <label><input type="checkbox" name="clear_stamp" value="1"> Remove the current stamp</label>
  </fieldset>
  <button type="submit">Save settings</button>
</form>
<?php layout_bottom(); ?>
