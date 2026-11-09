<?php
/**
 * "What do we already know about this lorry?" - answered while somebody types.
 *
 * Goes in public/vehicle_check.php. Called by certificate_new.php as the
 * registration and chassis boxes are filled in; returns JSON, renders nothing.
 *
 *     vehicle_check.php?reg=KDY 3456E&chassis=MK12094
 *
 * BOTH fields go in one request, on purpose. The two answers depend on each
 * other - a chassis is only a clash if it belongs to a DIFFERENT registration -
 * so asking separately would let the page show a contradiction while the second
 * answer was still in flight.
 *
 * ---------------------------------------------------------------------------
 * This is a courtesy, not a control
 * ---------------------------------------------------------------------------
 * certificate_new.php checks the chassis again on save, through the same
 * function. This exists so nobody fills in a whole form before being told the
 * chassis belongs to another lorry. Anything that only runs in a browser is a
 * hint; the save is the rule.
 *
 * Staff only, because the answer names a customer.
 */
require __DIR__ . '/../app/bootstrap.php';
require_once APP_DIR . '/certificates.php';

require_staff();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$reg     = trim((string)($_GET['reg'] ?? ''));
$chassis = trim((string)($_GET['chassis'] ?? ''));

if (!function_exists('plate_on_file')) {
    // Not pasted into app/certificates.php yet. Saying nothing is right: the
    // form stays quiet rather than showing a reassurance it cannot stand behind.
    echo json_encode(['state' => 'unknown']);
    exit;
}

$out = ['state' => 'ok'];

// ---------------------------------------------------------------- the plate
if (strlen(preg_replace('/[^A-Za-z0-9]/', '', $reg)) >= 5) {
    $v = plate_on_file($reg);

    if ($v) {
        $out['plate'] = [
            'reg_no'     => $v['reg_no'],
            'client'     => (string)$v['client_name'],
            'make'       => (string)$v['make'],
            'chassis_no' => (string)$v['chassis_no'],
            'cert_id'    => $v['cert_number'] !== null ? (int)$v['cert_id'] : null,
            'number'     => $v['cert_number'] !== null ? (string)$v['cert_number'] : null,
            'expires'    => $v['expiry_date'] ? fmt_date($v['expiry_date']) : null,
            'covered'    => $v['expiry_date']
                            && strtotime((string)$v['expiry_date']) >= strtotime(date('Y-m-d')),
        ];

        // The chassis typed on the form against the chassis on file for this
        // same lorry. create_certificate() overwrites the stored one without
        // asking, so a slip here quietly rewrites the vehicle's identity.
        $typed = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $chassis));
        $known = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$v['chassis_no']));
        if ($typed !== '' && $known !== '' && $typed !== $known) {
            $out['plate']['chassis_differs'] = true;
        }
    } else {
        $out['plate'] = null;                 // a lorry nobody has certified before
    }

    // --------------------------------------------- the governor already on it
    // Compared against the serial being typed: a different one means the old
    // box is being replaced, which nothing in the system noticed before.
    if (function_exists('vehicle_current_unit')) {
        $fitted = vehicle_current_unit($reg);
        $serial = trim((string)($_GET['serial'] ?? ''));

        if ($fitted) {
            $out['fitted'] = [
                'serial_no'    => $fitted['serial_no'],
                'model'        => (string)$fitted['model'],
                'installed_on' => fmt_date((string)$fitted['installed_on']),
            ];

            if ($serial !== ''
                && strtoupper(trim($serial)) !== strtoupper(trim((string)$fitted['serial_no']))) {
                $out['fitted']['being_replaced'] = true;
            }
        }
    }
}

// -------------------------------------------------------------- the chassis
// Excluding the registration being certified, so a lorry never clashes with
// itself and a renewal does not report its own chassis as a duplicate.
if ($chassis !== '') {
    $other = chassis_on_file($chassis, $reg);

    if ($other) {
        $out['chassis'] = [
            'reg_no' => $other['reg_no'],
            'client' => (string)$other['client_name'],
            'make'   => (string)$other['make'],
        ];
    }
}

echo json_encode($out);