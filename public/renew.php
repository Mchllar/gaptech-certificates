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

// The new cover picks up the day after the current cover ends, so nothing the
// client has already paid for is thrown away.
//
// This used to start from today, which meant renewing a certificate with a month
// still to run quietly cost the client that month - and made leaving the renewal
// to the last possible day the safest thing to do, which is how cover lapses.
//
// A certificate that has ALREADY expired starts today instead; renewal_start_date()
// holds that rule. Backdating would issue cover for days the vehicle did not have.
$issue  = renewal_start_date((string)$old['expiry_date']);
$expiry = date('Y-m-d', strtotime($issue . ' +1 year -1 day'));

// Waiting its turn rather than taking over now. Only used for the message at the
// end - the certificate itself needs no flag, because a future issue date is
// exactly what "scheduled" means.
$startsLater = strtotime($issue) > strtotime(date('Y-m-d'));

// Everything else comes from the old certificate's snapshot, not from the form,
// so a renewal cannot quietly change the vehicle or the governor.
$s = json_decode((string)$old['snapshot'], true) ?: [];

// When the governor actually went on, from the installation the old certificate
// points at. The snapshot is a copy of that date; the installation row is the
// original, so it is asked first.
//
// A renewal does not refit anything, so this must not move. The old last resort
// was $old['issue_date'] - the date the previous CERTIFICATE was issued, which is
// not an installation date at all and walks forward a year on every renewal.
$fitted = !empty($old['installation_id'])
    ? q('SELECT installed_on, technician FROM installations WHERE id = ?',
        [(int)$old['installation_id']])->fetch()
    : null;

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
    // The installation record first, the old snapshot second, and only then the
    // old issue date - kept for a certificate so old it has neither, and which
    // create_certificate() now corrects from the record anyway.
    'installed_on'    => $fitted['installed_on'] ?? (
                             !empty($s['installation']['installed_on_fmt'])
                               ? date('Y-m-d', strtotime($s['installation']['installed_on_fmt']))
                               : $old['issue_date']),
    'technician'      => $fitted['technician']
                           ?? $s['installation']['technician']
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

// Say when the new cover starts, not just when it ends. Somebody renewing a month
// early needs to see that the month was kept rather than wonder where it went.
$_SESSION['flash_ok'] = 'Renewal created for ' . ($s['vehicle']['reg_no'] ?? 'the vehicle')
    . ($startsLater
        ? ', starting ' . fmt_date($issue) . ' when the current certificate ends'
        : ', starting today')
    . ' and running to ' . fmt_date($expiry) . '. It is with accounts to approve.';

redirect($_POST['back'] ?? 'index.php');