<?php
/**
 * Renew a certificate in one step.
 *
 * Everything carries over from the certificate being renewed - vehicle, governor,
 * customer, installation - so nothing can be retyped wrongly.
 *
 * The administrator MAY record a payment message or an LPO number if they have
 * one, and may leave both blank if they do not - accounts can fill either in when
 * they approve. Whichever of them types it, nothing is issued until accounts
 * approve. An administrator must never be able to both issue and approve.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';

$user = require_admin();
csrf_check();

$id  = (int)($_POST['id'] ?? 0);
$old = q('SELECT * FROM certificates WHERE id = ?', [$id])->fetch();
if (!$old) {
    http_response_code(404);
    exit('No such certificate.');
}

// A year from today, ending the day before the anniversary: renewed 13 Oct 2026
// expires 12 Oct 2027.
$issue  = date('Y-m-d');
$expiry = date('Y-m-d', strtotime($issue . ' +1 year -1 day'));

// Everything else comes from the old certificate's snapshot, not from the form,
// so a renewal cannot quietly change the vehicle or the governor.
$s = json_decode((string)$old['snapshot'], true) ?: [];

$in = [
    'client_name'     => $s['client']['name'] ?? '',
    'client_phone'    => $s['client']['phone'] ?? '',
    'client_address'  => $s['client']['address'] ?? '',
    'reg_no'          => $s['vehicle']['reg_no'] ?? '',
    'make'            => $s['vehicle']['make'] ?? '',
    'chassis_no'      => $s['vehicle']['chassis_no'] ?? '',
    'device_model'    => $s['device']['model'] ?? (trim((string)setting('default_model')) ?: 'INTELSPEED™'),
    'serial_no'       => $s['device']['serial_no'] ?? '',
    'set_speed_kmh'   => $s['device']['set_speed_kmh'] ?? (trim((string)setting('default_speed')) ?: '80'),
    'installed_on'    => !empty($s['installation']['installed_on_fmt'])
                           ? date('Y-m-d', strtotime($s['installation']['installed_on_fmt']))
                           : $old['issue_date'],
    'technician'      => $s['installation']['technician']
                           ?? (trim((string)setting('default_technician')) ?: 'GAPTECH'),
    'type'            => 'Renewal',
    'issue_date'      => $issue,
    'validity_months' => '12',
    'expiry_date'     => $expiry,
];

$new   = create_certificate($in, $user);
$newId = is_array($new) ? (int)$new['id'] : (int)$new;

// Whatever the administrator had to hand. Both are optional, and neither issues
// anything - payment_ref is deliberately NOT set here, because confirming money
// arrived is accounts' decision and an administrator must not pre-empt it by
// filling in the field that approval checks.
$note = trim((string)($_POST['payment_note'] ?? ''));
$lpo  = trim((string)($_POST['lpo_no'] ?? ''));

if ($note !== '' || $lpo !== '') {
    q('UPDATE certificates SET payment_note = ?, lpo_no = ? WHERE id = ?',
      [$note, $lpo, $newId]);
}

audit('renew_certificate', 'certificate', $newId, [
    'renewed_from' => $id,
    'note_given'   => $note !== '',
    'lpo_no'       => $lpo,
]);

$_SESSION['flash_ok'] = 'Renewal created for ' . ($s['vehicle']['reg_no'] ?? 'the vehicle')
    . ', expiring ' . fmt_date($expiry) . '. It is with accounts to approve.';

redirect($_POST['back'] ?? 'index.php');