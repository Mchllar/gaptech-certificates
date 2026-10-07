<?php
/** Accounts corrects the payment reference on an already-approved certificate. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';

$user = require_accounts();
csrf_check();

$id     = (int)($_POST['id'] ?? 0);
$ref    = strtoupper(trim((string)($_POST['payment_ref'] ?? '')));
$note   = trim((string)($_POST['payment_note'] ?? ''));
$reason = trim((string)($_POST['reason'] ?? ''));

$cert = q('SELECT * FROM certificates WHERE id = ?', [$id])->fetch();

$problem = null;
if (!$cert) {
    $problem = 'No such certificate.';
} elseif ($cert['approval_status'] === 'pending') {
    $problem = 'That certificate has not been approved yet. Use the approval queue.';
} elseif ($ref === '') {
    $problem = 'A payment reference is required.';
} elseif ($reason === '') {
    $problem = 'Say why the reference is being changed.';
} else {
    $clash = q('SELECT id, number FROM certificates
                 WHERE upper(payment_ref) = upper(?) AND id <> ?', [$ref, $id])->fetch();
    if ($clash) {
        $problem = 'That reference is already recorded against certificate '
            . ($clash['number'] ?: 'draft ' . $clash['id']) . '.';
    }
}

if ($problem) {
    $_SESSION['flash_error'] = $problem;
    redirect('payments.php');
}

// Nothing changed - no point writing an audit entry that says so.
if ($ref === strtoupper(trim((string)$cert['payment_ref'])) && $note === trim((string)$cert['payment_note'])) {
    $_SESSION['flash_ok'] = 'No change to save.';
    redirect('payments.php');
}

q("UPDATE certificates
      SET payment_ref = ?, payment_note = ?, payment_edited_by = ?, payment_edited_at = now()
    WHERE id = ?", [$ref, $note, $user['id'], $id]);

// The old values live here, so a correction can always be traced back.
audit('edit_payment_ref', 'certificate', $id, [
    'from_ref'  => (string)$cert['payment_ref'],
    'to_ref'    => $ref,
    'note_changed' => $note !== trim((string)$cert['payment_note']),
    'reason'    => $reason,
]);

$_SESSION['flash_ok'] = 'Payment details corrected on ' . certificate_label($cert) . '.';
redirect('payments.php');