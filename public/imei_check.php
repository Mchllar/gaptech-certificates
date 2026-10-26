<?php
/**
 * "Is this unit free?" - answered while somebody is still typing.
 *
 * Goes in public/imei_check.php. Called by tracking_new.php as the IMEI boxes are
 * filled in; it returns JSON and renders nothing.
 *
 *     imei_check.php?imei=865784052222222          checking a new certificate
 *     imei_check.php?imei=...&except=12            editing certificate 12
 *
 * ---------------------------------------------------------------------------
 * This is a courtesy, not a control
 * ---------------------------------------------------------------------------
 * tracking_new.php checks the same thing again on save, through the same
 * function. This endpoint exists so nobody fills in a whole form before being
 * told the unit is still in another vehicle - not to decide whether the
 * certificate may be raised. Anything that only runs in a browser is a hint;
 * the save is the rule.
 *
 * Staff only, because the answer names a customer and a vehicle.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/tracking.php';

require_staff();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$imei   = trim((string)($_GET['imei'] ?? ''));
$except = (int)($_GET['except'] ?? 0) ?: null;

// Too short to mean anything yet. Said plainly rather than guessed at, so the
// field stays quiet while somebody is halfway through typing.
if (strlen($imei) < 10) {
    echo json_encode(['state' => 'short']);
    exit;
}

$held = tracking_imei_in_use($imei, $except);

// A certificate waiting with accounts has claimed this unit even though nothing
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
        'label'   => tracking_label($held),
        'expires' => fmt_date($held['expiry_date']),
    ]);
    exit;
}

// Free - but worth saying where it has been. A recovered unit being refitted is
// the normal case here, and showing the technician that this box last came off
// the vehicle they think it did is a cheap way to catch the wrong box in hand.
$history = tracking_device_history($imei);
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