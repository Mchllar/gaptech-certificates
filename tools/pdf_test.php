<?php
/**
 * Does Chrome work from here?
 *
 * Goes in tools/pdf_test.php. Run it BOTH ways - that comparison is the whole
 * point of this file:
 *
 *   in a browser         http://localhost/gaptech-certificates/tools/pdf_test.php
 *   in Command Prompt    C:\xampp\php\php.exe C:\xampp\htdocs\gaptech-certificates\tools\pdf_test.php
 *
 * In the browser it runs as whatever account Apache runs as. In Command Prompt it
 * runs as you. If it works for you and not for Apache, the problem is not Chrome -
 * it is the account Apache is running under, and no amount of changing switches
 * will fix it.
 *
 * It writes one small PDF to storage/ and tells you exactly what happened. It
 * changes nothing else. Delete it when the real thing works.
 */
declare(strict_types=1);

$cli = (PHP_SAPI === 'cli');
if (!$cli) { header('Content-Type: text/plain; charset=utf-8'); }

function say(string $label, string $value = ''): void
{
    echo str_pad($label, 22) . $value . "\n";
}

echo "PDF engine test\n" . str_repeat('=', 64) . "\n\n";

// ---------------------------------------------------------------- who am I
say('running as', $cli ? 'Command Prompt (you)' : 'Apache (the web server)');
$who = '';
if (function_exists('exec')) { @exec('whoami', $o); $who = trim(implode('', (array)$o)); }
say('account', $who !== '' ? $who : (getenv('USERNAME') ?: 'unknown'));
say('exec() available', function_exists('exec') ? 'yes' : 'NO - disabled in php.ini');
echo "\n";

if (!function_exists('exec')) {
    echo "exec() is disabled, so nothing can run. Remove it from disable_functions\n"
       . "in " . (php_ini_loaded_file() ?: 'php.ini') . " and restart Apache.\n";
    exit(1);
}

// ------------------------------------------------------------------- config
$configPath = __DIR__ . '/../app/config.php';
if (!is_file($configPath)) {
    echo "Cannot find app/config.php - put this file in the project's tools/ folder.\n";
    exit(1);
}
$config = require $configPath;
require_once __DIR__ . '/../app/pdf.php';

say('storage', (string)($config['storage'] ?? '(not set)'));
say('chrome setting', ($config['chrome'] ?? '') !== '' ? (string)$config['chrome'] : '(empty - auto)');
say('engine found', pdf_engine_label());
echo "\n";

$engine = pdf_engine();
if ($engine === null) {
    echo "No engine found. Checked:\n";
    foreach (pdf_browser_candidates() as $p) {
        echo '  ' . (is_file($p) ? '[found]   ' : '[missing] ') . $p . "\n";
    }
    echo "\nSet 'chrome' in config.php to the full path of chrome.exe.\n";
    exit(1);
}

// ------------------------------------------------------- can we write there
$dir = rtrim((string)($config['storage'] ?? sys_get_temp_dir()), '/\\') . DIRECTORY_SEPARATOR . 'certificates';
if (!is_dir($dir)) { @mkdir($dir, 0770, true); }
say('folder exists', is_dir($dir) ? 'yes' : 'NO');
say('folder writable', is_writable($dir) ? 'yes' : 'NO - this account cannot write there');
echo "\n";

if (!is_dir($dir) || !is_writable($dir)) {
    echo "The account above cannot write to:\n  $dir\n\n"
       . "That is the problem. Give it write permission, or point 'storage' somewhere it can write.\n";
    exit(1);
}

// -------------------------------------------------------------- the attempt
$out = $dir . DIRECTORY_SEPARATOR . '_pdf_test.pdf';
@unlink($out);

// With an id, render the REAL certificate sheet rather than a toy page. That is the
// comparison that matters once the toy page already works:
//
//   browser          ...tools/pdf_test.php?id=12
//   Command Prompt   C:\xampp\php\php.exe ...\tools\pdf_test.php 12
//
$id = (int)($_GET['id'] ?? ($argv[1] ?? 0));

if ($id > 0) {
    require_once __DIR__ . '/../app/bootstrap.php';
    require_once APP_DIR . '/certificates.php';

    $cert = q('SELECT * FROM certificates WHERE id = ?', [$id])->fetch();
    if (!$cert) {
        echo "No certificate with id $id.\n";
        exit(1);
    }

    say('certificate', 'id ' . $id . ', number ' . ($cert['number'] ?: '(none yet)'));
    $html = render_sheet($cert, 'pdf');
    say('sheet HTML', number_format(strlen($html)) . ' bytes');
    say('file:// refs', (string)preg_match_all('~file://~', $html));
    say('data: URIs', (string)preg_match_all('~data:[a-z/+]+;base64~', $html));

    // Every local file the sheet asks for, and whether it is actually there. A
    // missing asset is the kind of thing that makes headless Chrome give up quietly.
    preg_match_all('~file://([^"\'\)\s]+)~', $html, $refs);
    $missing = [];
    foreach (array_unique($refs[1]) as $ref) {
        $local = str_replace('/', DIRECTORY_SEPARATOR, ltrim($ref, '/'));
        if (!is_file($local)) { $missing[] = $local; }
    }
    say('missing assets', $missing ? (string)count($missing) : 'none');
    foreach ($missing as $m) { echo '    MISSING  ' . $m . "\n"; }
    echo "\n";
    echo "Rendering the real certificate sheet...\n\n";
} else {
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>test</title></head>'
          . '<body style="font:28pt Arial; padding:20mm">PDF engine test &mdash; '
          . date('Y-m-d H:i:s') . '</body></html>';
    echo "Rendering a simple test page (pass ?id=NN to render a real certificate)...\n\n";
}

try {
    pdf_render_html($html, $out, 'landscape');
} catch (Throwable $e) {
    echo "FAILED\n\n" . $e->getMessage() . "\n\n";
    echo "If this says exit status 0 and 'said nothing', Chrome started and gave up\n"
       . "silently - which on Windows almost always means the account above cannot\n"
       . "run it. Run this same file in Command Prompt and compare.\n";
    exit(1);
}

// ------------------------------------------------------------------- result
$size = filesize($out);
$head = (string)file_get_contents($out, false, null, 0, 5);
$land = null;
if (preg_match('~/MediaBox\s*\[([^\]]*)\]~', (string)file_get_contents($out), $m)) {
    $v = array_map('floatval', preg_split('~\s+~', trim($m[1])));
    $w = $v[2] - $v[0]; $h = $v[3] - $v[1];
    $land = round($w * 25.4 / 72) . ' x ' . round($h * 25.4 / 72) . ' mm '
          . ($w > $h ? 'LANDSCAPE' : 'PORTRAIT');
}

say('result', 'WORKED');
say('file', $out);
say('size', number_format($size) . ' bytes');
say('starts with', $head);
say('page size', $land ?? 'could not read');
echo "\nChrome works from here. You can delete " . basename($out) . " and this test file.\n";