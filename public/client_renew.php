<?php
/**
 * A client renewing their own certificate from the portal.
 *
 * Goes in public/client_renew.php
 *
 * The client pays by M-Pesa and pastes the confirmation. That creates a PENDING
 * renewal carrying the message, exactly like a renewal raised by an administrator.
 * Accounts still check it against the statement and approve it. Nothing a client
 * types here can produce a valid certificate on its own.
 *
 * Everything on the new certificate comes from the old one's snapshot, never from
 * the form, so a client cannot change a vehicle, a governor or a date by renewing.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';

$client = require_client();
csrf_check();

$back = 'client_portal.php';

$id  = (int)($_POST['id'] ?? 0);
$old = q('SELECT * FROM certificates WHERE id = ?', [$id])->fetch();

if (!$old) {
    $_SESSION['flash_error'] = 'That certificate could not be found.';
    redirect($back);
}

// The certificate has to be this client's. Without this check anyone signed in
// could renew anyone else's vehicle by changing the id in the form.
if ((int)$old['client_id'] !== (int)$client['id']) {
    http_response_code(403);
    exit('That certificate belongs to another account.');
}

// The paybill must be set, or the client has been told to pay nobody.
$paybill = trim((string)setting('mpesa_paybill', ''));
if ($paybill === '') {
    $_SESSION['flash_error'] = 'Online renewal is not available yet. Please call the office.';
    redirect($back);
}

// A vehicle with a valid certificate does not need renewing. This also stops a
// client raising a second renewal while the first is still with accounts.
// Matched on letters and digits only, so the same plate stored two ways counts once.
$reg  = (string)q('SELECT reg_no FROM vehicles WHERE id = ?', [$old['vehicle_id']])->fetchColumn();
$live = q("SELECT c.id, c.approval_status FROM certificates c
           JOIN vehicles v ON v.id = c.vehicle_id
           WHERE regexp_replace(upper(v.reg_no), '[^A-Z0-9]', '', 'g') = ?
             AND c.client_id = ?
             AND ((c.approval_status = 'approved' AND c.status = 'issued'
                   AND c.expiry_date >= current_date)
                  OR c.approval_status = 'pending')
           LIMIT 1", [plate_key($reg), $client['id']])->fetch();

if ($live) {
    $_SESSION['flash_error'] = $live['approval_status'] === 'pending'
        ? 'A renewal for this vehicle has already been sent and is waiting to be confirmed.'
        : 'This vehicle already has a valid certificate, so it does not need renewing yet.';
    redirect($back);
}

$note = trim((string)($_POST['payment_note'] ?? ''));
if ($note === '') {
    $_SESSION['flash_error'] = 'Paste the M-Pesa message to renew.';
    redirect($back);
}

// The reference is read out of the message rather than typed, so it cannot
// disagree with the confirmation it came from.
$ref = extract_payment_ref($note);
if ($ref === '') {
    $_SESSION['flash_error'] = 'No M-Pesa code could be found in that message. '
        . 'Paste the whole confirmation, starting with the code.';
    redirect($back);
}

// One payment, one certificate. The same check accounts rely on when approving.
$used = q('SELECT id FROM certificates WHERE upper(payment_ref) = upper(?)', [$ref])->fetch();
if ($used) {
    $_SESSION['flash_error'] = 'That M-Pesa code has already been used for a renewal. '
        . 'If you think this is wrong, please call the office.';
    redirect($back);
}

// A year from today, ending the day before the anniversary.
$issue  = date('Y-m-d');
$expiry = date('Y-m-d', strtotime($issue . ' +1 year -1 day'));

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

try {
    // No member of staff raised this, so issued_by is null. create_certificate()
    // reads only $user['id'], and the column is nullable.
    $new   = create_certificate($in, ['id' => null]);
    $newId = is_array($new) ? (int)$new['id'] : (int)$new;

    // create_certificate() re-derives the client from the name and phone in the
    // snapshot, and that lookup can land on a different clients row. The portal
    // filters on the signed-in client's id, so the renewal would then be invisible
    // to the person who paid for it. The client is already known here, so pin the
    // certificate back to them rather than trusting the lookup.
    q('UPDATE certificates
          SET payment_ref = ?, payment_note = ?, requested_by_client = ?, client_id = ?
        WHERE id = ?', [$ref, $note, $client['id'], (int)$old['client_id'], $newId]);

    audit('client_renew_request', 'certificate', $newId, [
        'renewed_from' => $id,
        'client_id'    => (int)$client['id'],
        'payment_ref'  => $ref,
    ]);
} catch (Throwable $err) {
    error_log('client renewal failed: ' . $err->getMessage());
    $_SESSION['flash_error'] = 'Something went wrong saving your renewal. '
        . 'Your payment is safe — please call the office and quote ' . $ref . '.';
    redirect($back);
}

flash('Thank you. Your renewal for ' . ($s['vehicle']['reg_no'] ?? 'your vehicle')
    . ' has been received and is being confirmed. Your new certificate will appear '
    . 'here once the payment is checked.');

redirect($back);