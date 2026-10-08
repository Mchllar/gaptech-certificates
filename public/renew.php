<?php
/** Renew a certificate: everything carries over, only the expiry date is chosen. */
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

$issue = date('Y-m-d');
$ts    = strtotime(trim((string)($_POST['expiry_date'] ?? '')));

if (!$ts || $ts <= strtotime($issue)) {
    $_SESSION['flash_error'] = 'Give an expiry date later than today.';
    redirect('certificate_view.php?id=' . $id);
}

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
    'expiry_date'     => date('Y-m-d', $ts),
];

$new   = create_certificate($in, $user);
$newId = is_array($new) ? (int)$new['id'] : (int)$new;

audit('renew_certificate', 'certificate', $newId, ['renewed_from' => $id]);

$_SESSION['flash_ok'] = 'Renewal created. It is with accounts for approval.';
redirect('certificate_view.php?id=' . $newId);