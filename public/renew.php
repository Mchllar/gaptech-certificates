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

// Where to land afterwards. Only these two, never a URL taken from the form as it
// stands: that would let a crafted link send someone off to any site after renewing.
$fromList = ($_POST['back'] ?? '') === 'index.php';
$backTo   = $fromList ? 'index.php' : 'certificate_view.php?id=' . $id;

if (!$old) {
    flash('That certificate could not be found, so nothing was renewed.');
    redirect('index.php');
}

// Only the certificate actually in force can be renewed: released, and not withdrawn,
// removed from the vehicle, or already replaced. The button is hidden otherwise; this
// is the check that holds if the page was stale or the form was sent by hand.
if ($old['approval_status'] !== 'approved'
    || in_array((string)$old['status'], ['voided', 'removed', 'superseded'], true)) {
    flash('Certificate ' . ($old['number'] ?: '') . ' is not the one in force, so it cannot be renewed.');
    redirect($backTo);
}

// One renewal at a time. A renewal still waiting for accounts, or one already released
// that starts after this certificate, means this vehicle is already renewed. Creating
// another made a duplicate draft - easy to do when the page seemed to have failed.
$already = q("SELECT id, number, approval_status FROM certificates
               WHERE vehicle_id = ? AND id <> ? AND status NOT IN ('voided')
                 AND approval_status <> 'rejected'
                 AND (approval_status = 'pending' OR issue_date > ?)
               ORDER BY id DESC LIMIT 1",
    [(int)$old['vehicle_id'], $id, (string)$old['issue_date']])->fetch();
if ($already) {
    flash('This vehicle already has a renewal '
        . ($already['approval_status'] === 'pending'
            ? 'waiting for accounts (' . certificate_label($already) . ')'
            : '(' . certificate_label($already) . ')')
        . '. Nothing new was created.');
    redirect('certificate_view.php?id=' . (int)$already['id']);
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
// renewal_dates() is also what the Renew dialog shows, so the two always agree.
[$issue, $expiry] = renewal_dates((string)$old['expiry_date']);

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
$contract = trim((string)($_POST['contract_no'] ?? ''));

if ($note !== '' || $lpo !== '' || $contract !== '') {
    q('UPDATE certificates SET payment_note = ?, lpo_no = ?, contract_no = ? WHERE id = ?',
      [$note, $lpo, $contract, $newId]);
}

audit('renew_certificate', 'certificate', $newId, [
    'renewed_from' => $id,
    'note_given'   => $note !== '',
    'lpo_no'       => $lpo,
    'contract_no'  => $contract,
]);

// Say when the new cover starts, not just when it ends. Somebody renewing a month
// early needs to see that the month was kept rather than wonder where it went.
// flash(), which every page shows. It used to go into $_SESSION['flash_ok'], which only
// the old payments page ever displayed, so this message was never seen.
flash('Renewal created for ' . ($s['vehicle']['reg_no'] ?? 'the vehicle')
    . ($startsLater
        ? ', starting ' . fmt_date($issue) . ' when the current certificate ends'
        : ', starting today')
    . ' and running to ' . fmt_date($expiry) . '. It is with accounts to approve.');

// To the renewal just made, so it can be checked at once. It used to go back to
// "certificate_view.php" with no id, which is the "not found" page - after the
// renewal had already been saved. From the list, back to the list.
redirect($fromList ? 'index.php' : 'certificate_view.php?id=' . $newId);