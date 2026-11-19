<?php
/**
 * One-off: store every registration as "KDK 259X" rather than "KDK259X".
 *
 *   php tools/space_plates.php           show what would change
 *   php tools/space_plates.php --apply   back up, then change
 *
 * Only solid plates in the ordinary shape get a space (see plate_display()); anything
 * already spaced, or of another shape, is left exactly as it is. Covers the vehicles
 * table, tracking certificates, and the copy of the plate frozen in each certificate's
 * snapshot, so screens, reprints and verification all agree. Matching between records
 * uses letters and digits only, so nothing that links them changes.
 */
require __DIR__ . '/../app/bootstrap.php';
require_once APP_DIR . '/certificates.php';
require_once APP_DIR . '/tracking.php';

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
$apply = in_array('--apply', $argv, true);

$plan = ['vehicles' => [], 'certificates' => [], 'tracking_certificates' => []];

foreach (q('SELECT id, reg_no FROM vehicles')->fetchAll() as $r) {
    $new = plate_display((string)$r['reg_no']);
    if ($new !== $r['reg_no']) { $plan['vehicles'][] = ['id' => (int)$r['id'], 'from' => $r['reg_no'], 'to' => $new]; }
}
foreach (q('SELECT id, snapshot FROM certificates')->fetchAll() as $r) {
    $s = json_decode((string)$r['snapshot'], true);
    $old = (string)($s['vehicle']['reg_no'] ?? '');
    if ($old !== '' && plate_display($old) !== $old) {
        $plan['certificates'][] = ['id' => (int)$r['id'], 'from' => $old, 'to' => plate_display($old)];
    }
}
foreach (q('SELECT id, reg_no, snapshot FROM tracking_certificates')->fetchAll() as $r) {
    $s = json_decode((string)$r['snapshot'], true);
    $col = (string)$r['reg_no'];
    $snap = (string)($s['vehicle']['reg_no'] ?? '');
    if (plate_display($col) !== $col || ($snap !== '' && plate_display($snap) !== $snap)) {
        $plan['tracking_certificates'][] = ['id' => (int)$r['id'], 'from' => $col, 'to' => plate_display($col)];
    }
}

foreach ($plan as $table => $rows) {
    echo str_pad($table, 24), count($rows), " to change\n";
    foreach (array_slice($rows, 0, 5) as $r) { echo "    {$r['from']}  ->  {$r['to']}\n"; }
}
if (!$apply) {
    exit("\nNothing changed. Run again with --apply to make these changes.\n");
}

// Back up exactly what is about to change, so it can be put back.
$dir = rtrim((string)$config['storage'], '/\\') . '/backups';
if (!is_dir($dir)) { mkdir($dir, 0770, true); }
$backup = $dir . '/plates-before-' . date('Ymd-His') . '.json';
$saved = [
    'vehicles' => q('SELECT id, reg_no FROM vehicles WHERE id = ANY(?::int[])',
        ['{' . implode(',', array_column($plan['vehicles'], 'id') ?: [0]) . '}'])->fetchAll(),
    'certificates' => q('SELECT id, snapshot FROM certificates WHERE id = ANY(?::int[])',
        ['{' . implode(',', array_column($plan['certificates'], 'id') ?: [0]) . '}'])->fetchAll(),
    'tracking_certificates' => q('SELECT id, reg_no, snapshot FROM tracking_certificates WHERE id = ANY(?::int[])',
        ['{' . implode(',', array_column($plan['tracking_certificates'], 'id') ?: [0]) . '}'])->fetchAll(),
];
file_put_contents($backup, json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nBacked up to $backup\n";

$pdo = db();
$pdo->beginTransaction();
foreach ($plan['vehicles'] as $r) {
    q('UPDATE vehicles SET reg_no = ? WHERE id = ?', [$r['to'], $r['id']]);
}
foreach ($plan['certificates'] as $r) {
    q("UPDATE certificates SET snapshot = jsonb_set(snapshot::jsonb, '{vehicle,reg_no}', to_jsonb(?::text)) WHERE id = ?",
      [$r['to'], $r['id']]);
}
foreach ($plan['tracking_certificates'] as $r) {
    // jsonb_set leaves a snapshot with no "vehicle" section untouched, so no check is needed
    // (and a jsonb "?" operator would be mistaken for a query placeholder).
    q("UPDATE tracking_certificates SET reg_no = ?,
              snapshot = jsonb_set(snapshot::jsonb, '{vehicle,reg_no}', to_jsonb(?::text))
        WHERE id = ?", [$r['to'], $r['to'], $r['id']]);
}
$pdo->commit();
audit('space_plates', 'settings', null, ['changed' => array_map('count', $plan), 'backup' => basename($backup)]);
echo "Done.\n";
