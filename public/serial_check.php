<?php
/**
 * "Is this governor free?" - answered while somebody is still typing.
 *
 * Goes in public/serial_check.php. Called by certificate_new.php as the serial
 * number is filled in; it returns JSON and renders nothing.
 *
 *     serial_check.php?serial=6038124225        raising a new certificate
 *     serial_check.php?serial=...&except=12      editing certificate 12
 *
 * ---------------------------------------------------------------------------
 * This is a courtesy, not a control
 * ---------------------------------------------------------------------------
 * certificate_new.php checks the same thing again on save, through the same
 * function. This endpoint exists so nobody fills in a whole form before being
 * told the box is still in another lorry - not to decide whether the certificate
 * may be raised. Anything that only runs in a browser is a hint; the save is the
 * rule.
 *
 * Staff only, because the answer names a customer and a vehicle.
 */
require __DIR__ . '/../app/bootstrap.php';
require_once APP_DIR . '/certificates.php';

require_staff();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$serial = trim((string)($_GET['serial'] ?? ''));
$except = (int)($_GET['except'] ?? 0) ?: null;

// The lorry being certified. Without it a RENEWAL reports itself as a clash -
// same box, same lorry, which is exactly what makes it a renewal - and the form
// would show a red warning on every renewal GAPTECH raises.
$reg = trim((string)($_GET['reg'] ?? ''));

// Too short to mean anything yet. Said plainly rather than guessed at, so the
// field stays quiet while somebody is halfway through typing.
if (strlen($serial) < 4) {
    echo json_encode(['state' => 'short']);
    exit;
}

if (!function_exists('governor_serial_in_use')) {
    // The functions have not been pasted into app/certificates.php yet. Saying
    // nothing is right: the form falls back to its own quiet behaviour rather
    // than showing a reassuring "free to fit" it cannot stand behind.
    echo json_encode(['state' => 'unknown']);
    exit;
}

$held = governor_serial_in_use($serial, $except, $reg);

// A certificate waiting with accounts has claimed this box even though nothing
// has been issued yet. Different words, same answer: it cannot be used here.
if ($held && ($held['approval_status'] ?? '') === 'pending') {
    echo json_encode([
        'state'     => 'pending',
        'vehicle'   => $held['reg_no'],
        'client'    => $held['client_name'],
        'cert_id'   => (int)$held['id'],
        'raised_on' => fmt_date(substr((string)$held['issued_at'], 0, 10)),
    ]);
    exit;
}

if ($held) {
    echo json_encode([
        'state'   => 'in_use',
        'vehicle' => $held['reg_no'],
        'client'  => $held['client_name'],
        'cert_id' => (int)$held['id'],
        'label'   => (string)$held['number'],
        'expires' => fmt_date($held['expiry_date']),
    ]);
    exit;
}

// Free - but worth saying where it has been. A governor taken off a scrapped
// lorry and refitted is the normal case here, and showing the technician that
// this box last came off the lorry they think it did is a cheap way to catch the
// wrong box in hand.
$history = governor_device_history($serial);
$last    = null;
foreach ($history as $row) {
    if (($row['status'] ?? '') === 'removed') { $last = $row; break; }
}

if ($last) {
    echo json_encode([
        'state'      => 'returning',
        'vehicle'    => $last['reg_no'],
        'client'     => $last['client_name'],
        'removed_on' => fmt_date((string)$last['removed_on']),
        'reason'     => (string)$last['removed_reason'],
    ]);
    exit;
}

echo json_encode(['state' => 'free', 'seen' => count($history)]);