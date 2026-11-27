<?php
/** Company details printed on every certificate, plus the certificate counter. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require APP_DIR . '/views/layout.php';
require_once APP_DIR . '/mailer.php';

$me = require_supervisor();

// ---------------------------------------------------------------- test e-mail
//
// Sends one message through the same mailer the password reset codes use, from
// wherever this site is running - so on the live server it proves that server
// can send, without anybody needing to log in to it. Shows the exact error if not.
$mailResult = null;   // ['ok' => bool, 'text' => string]
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_mail') {
    csrf_check();
    $to = trim((string)($_POST['test_to'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $mailResult = ['ok' => false, 'text' => 'Type a valid e-mail address to send the test to.'];
    } elseif (!mail_configured()) {
        $mailResult = ['ok' => false, 'text' => 'E-mail is not set up on this server: SMTP_HOST and SMTP_FROM '
            . 'are empty in its .env file. Ask whoever deployed the site to fill them in.'];
    } else {
        $err  = null;
        $body = "This is a test from the GAPTECH certificate system.

"
            . "If you are reading it, password reset codes will be delivered.

"
            . 'Sent ' . date('d-m-Y H:i') . ' from ' . ($config['base_url'] ?? gethostname())
            . ' by ' . ($me['name'] ?? 'an administrator') . ".
";
        $ok = mail_send($to, 'Certificate system test message', $body, $err);
        audit('test_mail', 'settings', null, ['to' => $to, 'ok' => $ok, 'error' => $ok ? '' : (string)$err]);
        $mailResult = $ok
            ? ['ok' => true, 'text' => 'Sent to ' . $to . '. Check the inbox - and the spam folder; if it is '
                . 'there, the domain needs its SPF and DKIM records set up.']
            : ['ok' => false, 'text' => 'Could not send: ' . ($err ?: 'unknown error') . '.'];
    }
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'test_mail') {
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
            set_setting('images_changed_at', (string)time());   // saved PDFs are redrawn
        }
        if (!empty($_POST['clear_' . $input])) {
            set_setting($key, '');
            set_setting('images_changed_at', (string)time());
        }
    }
    // Tracking certificates: whether the stamp and signature print by default.
    set_setting('tracking_print_stamp', !empty($_POST['tracking_print_stamp']) ? '1' : '0');
    set_setting('tracking_print_signature', !empty($_POST['tracking_print_signature']) ? '1' : '0');
    // Speed governor certificates: whether the signature prints by default.
    set_setting('governor_print_signature', !empty($_POST['governor_print_signature']) ? '1' : '0');
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
    <label class="inline" style="display:flex; gap:8px; align-items:center">
      <input type="checkbox" name="governor_print_signature" value="1" style="width:16px; margin:0"
             <?= setting('governor_print_signature', '1') === '1' ? 'checked' : '' ?>>
      Print the signature (changeable on each print; a PDF always has it)</label>
  </fieldset>
  <fieldset>
    <legend>Printing tracking certificates</legend>
    <label class="inline" style="display:flex; gap:8px; align-items:center">
      <input type="checkbox" name="tracking_print_stamp" value="1" style="width:16px; margin:0"
             <?= setting('tracking_print_stamp', '1') === '1' ? 'checked' : '' ?>>
      Print the stamp (it can still be ticked or unticked on each print; a PDF always has it)</label>
    <label class="inline" style="display:flex; gap:8px; align-items:center">
      <input type="checkbox" name="tracking_print_signature" value="1" style="width:16px; margin:0"
             <?= setting('tracking_print_signature', '1') === '1' ? 'checked' : '' ?>>
      Print the signature (the same: changeable on each print; a PDF always has it)</label>
  </fieldset>
  <button type="submit">Save settings</button>
</form>

<?php $smtp = $config['smtp'] ?? []; ?>
<form method="post" class="card-form" style="margin-top:22px">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <fieldset>
    <legend>E-mail (password reset codes)</legend>
    <p class="hint" style="text-align:left">
      <?php if (mail_configured()): ?>
        Sending from <b><?= e((string)($smtp['from'] ?? '')) ?></b> through
        <b><?= e((string)($smtp['host'] ?? '')) ?>:<?= (int)($smtp['port'] ?? 0) ?></b>.
        These come from the server's .env file and can only be changed there.
      <?php else: ?>
        E-mail is <b>not set up</b> on this server &mdash; SMTP_HOST and SMTP_FROM are empty in its
        .env file, so password reset codes cannot be sent.
      <?php endif; ?>
      Send yourself a test to check it works from here.</p>
    <?php if ($mailResult): ?>
      <p class="<?= $mailResult['ok'] ? 'flash' : 'error' ?>" style="text-align:left"><?= e($mailResult['text']) ?></p>
    <?php endif; ?>
    <label>Send a test e-mail to
      <input name="test_to" type="email" required placeholder="you@example.com"
             value="<?= e((string)($_POST['test_to'] ?? '')) ?>"></label>
  </fieldset>
  <button type="submit" name="action" value="test_mail" class="secondary">Send a test e-mail</button>
</form>
</div>
<?php layout_bottom(); ?>