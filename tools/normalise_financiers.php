<?php
/**
 * One-off: print every tracking certificate's financier the standard way
 * ("KCB BANK KENYA LTD", "HFCB BANK", ...), using the financier_names setting.
 *
 *   php tools/normalise_financiers.php           show what would change
 *   php tools/normalise_financiers.php --apply   back up, then change
 *
 * Changes the financier column and the copy frozen in each certificate's snapshot, and
 * clears any stored PDF of a changed certificate so its next download is drawn with the
 * new name. Blank financiers stay blank (they print as N/A).
 */
require __DIR__ . '/../app/bootstrap.php';
require_once APP_DIR . '/tracking.php';

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
$apply = in_array('--apply', $argv, true);

$plan = [];
foreach (q('SELECT id, number, reg_no, financier, snapshot FROM tracking_certificates ORDER BY id')->fetchAll() as $r) {
    $s    = json_decode((string)$r['snapshot'], true) ?: [];
    $col  = (string)$r['financier'];
    $snap = (string)($s['vehicle']['financier'] ?? '');
    $want = trk_financier_canonical($col !== '' ? $col : $snap);
    if ($want !== $col || (isset($s['vehicle']) && $want !== $snap)) {
        $plan[] = ['id' => (int)$r['id'], 'label' => tracking_label($r), 'reg' => $r['reg_no'],
                   'from' => $col !== '' ? $col : $snap, 'to' => $want];
    }
}

echo count($plan), " certificate(s) to change\n";
foreach ($plan as $p) {
    echo "    {$p['label']} {$p['reg']}: \"{$p['from']}\"  ->  \"" . ($p['to'] === '' ? '(none: N/A)' : $p['to']) . "\"\n";
}
if (!$apply || !$plan) {
    exit($plan ? "\nNothing changed. Run again with --apply to make these changes.\n" : '');
}

$dir = rtrim((string)$config['storage'], '/\\') . '/backups';
if (!is_dir($dir)) { mkdir($dir, 0770, true); }
$backup = $dir . '/financiers-before-' . date('Ymd-His') . '.json';
$ids = '{' . implode(',', array_column($plan, 'id')) . '}';
file_put_contents($backup, json_encode(
    q('SELECT id, financier, snapshot, pdf_path, pdf_sha256 FROM tracking_certificates WHERE id = ANY(?::int[])', [$ids])->fetchAll(),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nBacked up to $backup\n";

$pdo = db();
$pdo->beginTransaction();
foreach ($plan as $p) {
    q("UPDATE tracking_certificates
          SET financier = ?,
              snapshot = CASE WHEN snapshot::jsonb ?? 'vehicle'
                              THEN jsonb_set(snapshot::jsonb, '{vehicle,financier}', to_jsonb(?::text))
                              ELSE snapshot::jsonb END,
              pdf_path = NULL, pdf_sha256 = NULL
        WHERE id = ?", [$p['to'], $p['to'], $p['id']]);
}
$pdo->commit();
echo "Done.\n";
