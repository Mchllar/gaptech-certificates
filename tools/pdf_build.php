<?php
/**
 * Build certificate PDFs from the command line.
 *
 * Goes in tools/pdf_build.php. Run it from Command Prompt:
 *
 *   C:\xampp\php\php.exe C:\xampp\htdocs\gaptech-certificates\tools\pdf_build.php
 *   C:\xampp\php\php.exe ...\tools\pdf_build.php cert 12      one governor certificate
 *   C:\xampp\php\php.exe ...\tools\pdf_build.php tracking 4   one installation certificate
 *
 * ---------------------------------------------------------------------------
 * Why this exists
 * ---------------------------------------------------------------------------
 * Because a PDF only has to be made once, and it does not have to be made while
 * somebody is waiting for a download.
 *
 * certificate_pdf() already caches: once the file is on disk and pdf_path is set,
 * every later download is a file read. So if Chrome will run from Command Prompt
 * but not from Apache, this gets you working downloads today - build them here,
 * and the web server never launches Chrome at all.
 *
 * It is also the right thing to run after changing the sheet, to rebuild in one go
 * rather than one certificate at a time as clients happen to ask.
 *
 * NOTE: Apache is not running this, so there is no request to work the address out
 * from. 'base_url' must be set in config.php, and this script says so plainly if it
 * is missing. Apache itself does have to be running - the renderer fetches the
 * certificate over HTTP from this machine.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from Command Prompt, not a browser.\n");
}

require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';

$hasTracking = is_file(APP_DIR . '/tracking.php');
if ($hasTracking) {
    require APP_DIR . '/tracking.php';
}

// ------------------------------------------------------------------- checks
if (trim((string)($config['base_url'] ?? '')) === '') {
    echo "base_url is not set in config.php.\n\n"
       . "Add this line, with the folder your site actually sits in:\n\n"
       . "    'base_url' => 'http://127.0.0.1/gaptech-certificates/public/',\n\n"
       . "Apache works it out by itself from a browser request, but there is no\n"
       . "request here, so it has to be told.\n";
    exit(1);
}

if (pdf_engine() === null) {
    echo "No PDF engine found. Checked:\n";
    foreach (pdf_browser_candidates() as $p) {
        echo '  ' . (is_file($p) ? '[found]   ' : '[missing] ') . $p . "\n";
    }
    exit(1);
}

echo 'engine   : ' . pdf_engine_label() . "\n";
// The address the server uses to talk to ITSELF, which is not $config['base_url'].
// See the note on pdf_base_url().
echo 'renders  : ' . pdf_base_url() . "\n";
echo str_repeat('-', 64) . "\n";

// Apache has to be up, because the renderer fetches the certificate over HTTP.
$probe = @file_get_contents(pdf_base_url() . 'pdf_sheet.php', false,
    stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]));
if ($probe === false) {
    echo "Cannot reach " . pdf_base_url() . "\n\n"
       . "Start Apache in the XAMPP control panel. The renderer fetches each\n"
       . "certificate over HTTP from this machine, on 127.0.0.1. If Apache only\n"
       . "answers on some other address, set 'internal_url' in config.php to one\n"
       . "that reaches this site from this machine.\n";
    exit(1);
}

// --------------------------------------------------------------- what to do
$kind = $argv[1] ?? '';
$one  = (int)($argv[2] ?? 0);

$built = $skipped = $failed = 0;

/** Build one, report one line, never stop the run for a single failure. */
function build(string $label, callable $make): void
{
    global $built, $failed;
    try {
        $path = $make();
        if ($path === null) {
            echo "  $label  SKIPPED (no engine)\n";
            return;
        }
        echo "  $label  ok  " . number_format((int)filesize($path)) . " bytes\n";
        $built++;
    } catch (Throwable $e) {
        echo "  $label  FAILED  " . $e->getMessage() . "\n";
        $failed++;
    }
}

// ------------------------------------------------------- governor certificates
if ($kind === '' || $kind === 'cert') {
    $sql = "SELECT * FROM certificates
            WHERE approval_status = 'approved' AND status = 'issued' AND number <> ''";
    $params = [];
    if ($one > 0) { $sql .= ' AND id = ?'; $params[] = $one; }
    $sql .= ' ORDER BY number';

    $rows = q($sql, $params)->fetchAll();
    echo 'Governor certificates: ' . count($rows) . "\n";

    foreach ($rows as $cert) {
        build('cert ' . $cert['number'],
            static fn() => certificate_pdf($cert, true));
    }
}

// ------------------------------------------------------- tracking certificates
if ($hasTracking && ($kind === '' || $kind === 'tracking')) {
    $sql = "SELECT * FROM tracking_certificates WHERE approval_status = 'approved'";
    $params = [];
    if ($one > 0) { $sql .= ' AND id = ?'; $params[] = $one; }
    $sql .= ' ORDER BY id';

    $rows = q($sql, $params)->fetchAll();
    echo 'Installation certificates: ' . count($rows) . "\n";

    foreach ($rows as $cert) {
        build('tracking ' . tracking_label($cert),
            static fn() => tracking_pdf($cert, true));
    }
}

echo str_repeat('-', 64) . "\n";
echo "built $built, failed $failed\n";

if ($built > 0) {
    echo "\nThose certificates now download straight from disk - Apache does not\n"
       . "launch Chrome for them again.\n";
}

exit($failed > 0 ? 1 : 0);