<?php
/**
 * Setup check. Put this file in the project's tools/ folder and open it in a browser:
 *   http://localhost/certificates/tools/check_setup.php
 *
 * It only reads; it changes nothing. Delete it once every row is OK.
 */
$results = [];

function check(string $name, bool $ok, string $detail, string $fix = ''): array
{
    return ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'fix' => $fix];
}

// --- PHP itself -----------------------------------------------------------
$results[] = check('PHP version', PHP_VERSION_ID >= 80000, PHP_VERSION,
    'The system needs PHP 8.0 or newer.');

$results[] = check('pdo_pgsql extension', extension_loaded('pdo_pgsql'),
    extension_loaded('pdo_pgsql') ? 'loaded' : 'missing',
    'In php.ini remove the semicolon before extension=pdo_pgsql and extension=pgsql, then restart Apache. '
    . 'php.ini in use: ' . (php_ini_loaded_file() ?: 'unknown'));

// --- configuration --------------------------------------------------------
$configPath = __DIR__ . '/../app/config.php';
$configOk = is_file($configPath);
$results[] = check('config.php found', $configOk, $configPath,
    'Put this script in the project tools/ folder, beside app/ and public/.');

$config = $configOk ? require $configPath : null;

// --- database -------------------------------------------------------------
$pdo = null;
if ($config) {
    $c = $config['db'];
    try {
        $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['name']}",
            $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $results[] = check('Database connection', true, "connected to {$c['name']} as {$c['user']}");
    } catch (Throwable $err) {
        $results[] = check('Database connection', false, $err->getMessage(),
            'Check the db settings in app/config.php. "password authentication failed" means the password '
            . 'there does not match the one you gave the gaptech user.');
    }
}

if ($pdo) {
    $expected = ['audit_log', 'certificates', 'clients', 'devices', 'installations', 'settings', 'users', 'vehicles'];
    $found = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_diff($expected, $found);
    $results[] = check('Tables', !$missing,
        $missing ? 'missing: ' . implode(', ', $missing) : count($expected) . ' tables present',
        'Load the schema in psql:  \i \'C:/path/to/certificates/sql/schema.sql\'');

    if (!$missing) {
        $last = $pdo->query("SELECT value FROM settings WHERE key = 'last_cert_number'")->fetchColumn();
        $results[] = check('Certificate counter', $last !== false, 'next certificate will be ' . ((int)$last + 1),
            'Set it in Settings to the highest number already used on paper.');

        $staff = (int)$pdo->query('SELECT count(*) FROM users WHERE active')->fetchColumn();
        $results[] = check('Staff accounts', $staff > 0, $staff . ' active',
            'Create one:  php tools/create_user.php "Your Name" you@gaptechsolutions.com supervisor');

        $model = $pdo->query("SELECT value FROM settings WHERE key = 'default_model'")->fetchColumn();
        $results[] = check('Text encoding', strpos((string)$model, '™') !== false, (string)$model,
            'The ™ came through wrong. Reload the schema with \encoding UTF8 set first.');
    }
}

// --- files ----------------------------------------------------------------
if ($config) {
    $storage = $config['storage'] . '/certificates';
    $writable = is_dir($storage) ? is_writable($storage) : @mkdir($storage, 0770, true);
    $results[] = check('Storage folder writable', (bool)$writable, $storage,
        'Give the web server write access to the storage folder (Linux: sudo chown -R www-data:www-data storage).');

    $wk = $config['wkhtmltopdf'];
    $results[] = check('wkhtmltopdf (optional)', $wk === '' || is_file($wk),
        $wk === '' ? 'not configured - the browser Save as PDF will be used' : $wk,
        'Either install wkhtmltopdf and set its full path, or leave the setting empty.');

    $results[] = check('base_url set', !str_contains((string)$config['base_url'], 'localhost/certificates/public')
        || PHP_SAPI === 'cli-server', (string)$config['base_url'],
        'Set base_url to the address staff will type, e.g. http://192.168.1.10/certificates/public. '
        . 'It is what the QR codes point to, so localhost will not work from a phone.');
}

$assets = __DIR__ . '/../public/assets';
$needed = ['gap_logo.png', 'gap_logo_gold.png', 'kebs_mark.png', 'sticker_bg.png', 'style.css'];
$missingAssets = array_values(array_filter($needed, fn($f) => !is_file($assets . '/' . $f)));
$results[] = check('Certificate images', !$missingAssets,
    $missingAssets ? 'missing: ' . implode(', ', $missingAssets) : 'all present',
    'Restore them from the zip into public/assets.');

$fails = count(array_filter($results, fn($r) => !$r['ok']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Setup check</title>
<style>
 body { font-family: "Segoe UI", Arial, sans-serif; max-width: 860px; margin: 30px auto; padding: 0 16px; color: #1c2230; }
 h1 { font-size: 22px; }
 table { border-collapse: collapse; width: 100%; }
 th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #d5dce8; vertical-align: top; font-size: 14px; }
 th { background: #f1f4f9; }
 .ok { color: #1a7a3c; font-weight: 600; }
 .bad { color: #C8102E; font-weight: 600; }
 .fix { color: #5a6275; font-size: 13px; }
 .summary { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; }
 .good { background: #e9f6ec; border: 1px solid #b7dcc0; }
 .warn { background: #fdecec; border: 1px solid #f0b6b6; }
</style>
</head>
<body>
<h1>Certificate system setup check</h1>
<p class="summary <?= $fails ? 'warn' : 'good' ?>">
  <?= $fails ? $fails . ' item(s) need attention.' : 'Everything checks out. Sign in at ../public/login.php, then delete this file.' ?>
</p>
<table>
  <tr><th>Check</th><th>Result</th><th>Detail</th></tr>
  <?php foreach ($results as $r): ?>
  <tr>
    <td><?= htmlspecialchars($r['name']) ?></td>
    <td class="<?= $r['ok'] ? 'ok' : 'bad' ?>"><?= $r['ok'] ? 'OK' : 'Fix' ?></td>
    <td>
      <?= htmlspecialchars($r['detail']) ?>
      <?php if (!$r['ok'] && $r['fix']): ?><div class="fix"><?= htmlspecialchars($r['fix']) ?></div><?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
</body>
</html>
