<?php
/**
 * Why is the QR code still wrong?
 *
 * Save as tools\check_qr.php and run it from a command prompt:
 *
 *     cd C:\xampp\htdocs\gaptech-certificates
 *     C:\xampp\php\php.exe tools\check_qr.php 13772
 *
 * There are three separate things that can keep a QR code wrong, and they look
 * identical from the outside. This says which one you have instead of guessing:
 *
 *   1. base_url is not set            -> the link has no host
 *   2. verify_url() is the old copy   -> the paste did not land
 *   3. the PDF is the cached one      -> the code is fixed but the file is old
 *
 * Reads only. It changes nothing.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this from a command prompt, not a browser.\n");
}

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';
require_once APP_DIR . '/certificates.php';

/**
 * bootstrap.php creates $config at its own top level (line 10,
 * $config = require APP_DIR . '/config.php';) and a top-level require brings it
 * into this scope, so the variable really is here.
 *
 * Editors cannot follow a variable through a require and will underline every
 * use of $config below as undefined. The annotation tells them where it comes
 * from; the check turns "undefined variable" warnings scattered down the file
 * into one clear sentence if it is ever genuinely missing.
 *
 * @var array $config
 */
if (!isset($config) || !is_array($config)) {
    exit("app/bootstrap.php did not provide \$config - check it loads app/config.php.\n");
}

$number = (int)($argv[1] ?? 0);
if ($number <= 0) {
    exit("Usage: php tools\\check_qr.php <certificate number>\n");
}

echo "\n";

// ---------------------------------------------------------------- 1. config
$base = trim((string)($config['base_url'] ?? ''));
echo "1. base_url in app/config.php\n";
if ($base === '') {
    echo "   NOT SET. This is the problem - a QR code cannot be built without it.\n";
    echo "   Set it to the address a phone can reach, with no trailing slash:\n";
    echo "     'base_url' => 'http://192.168.100.162/gaptech-certificates/public',\n";
} else {
    echo "   " . $base . "\n";
    if (substr($base, -1) === '/')          { echo "   (trailing slash is harmless - it is trimmed)\n"; }
    if (strpos($base, 'localhost') !== false || strpos($base, '127.0.0.1') !== false) {
        echo "   WARNING: that address only means anything on this machine. A phone\n";
        echo "   scanning it will try to reach ITSELF. Use the network address.\n";
    }
}
if (!empty($config['internal_url'])) {
    echo "   internal_url is also set to " . $config['internal_url'] . "\n";
    echo "   That one is only for the server reaching itself. If base_url above is\n";
    echo "   missing, check it was not renamed to this by mistake.\n";
}

// -------------------------------------------------------------- 2. the code
echo "\n2. which verify_url() is running\n";
$fn  = new ReflectionFunction('verify_url');
$src = implode('', array_slice(file($fn->getFileName()),
        $fn->getStartLine() - 1, $fn->getEndLine() - $fn->getStartLine() + 1));
$new = strpos($src, 'HTTP_HOST') !== false;
echo "   " . $fn->getFileName() . " line " . $fn->getStartLine() . "\n";
echo "   " . ($new ? "the NEW version (it checks the request host)\n"
                   : "the OLD four-line version - the paste has not been applied\n");

$cert = q('SELECT * FROM certificates WHERE number = ?', [$number])->fetch();
if (!$cert) {
    exit("\n   No certificate numbered $number on file.\n\n");
}
echo "   it produces: " . (verify_url($cert) ?: '(empty - no QR would be printed)') . "\n";
echo "   (run from the command line there is no request host, so an empty answer\n";
echo "    here only means base_url is unset, not that the browser would fail)\n";

// --------------------------------------------------------------- 3. the PDF
echo "\n3. the stored PDF for this certificate\n";
$pdf = rtrim((string)$config['storage'], '/\\') . '/certificates/certificate-' . $number . '.pdf';
if (!is_file($pdf)) {
    echo "   none stored - the next download will be rendered fresh.\n";
} else {
    echo "   " . $pdf . "\n";
    echo "   made " . date('j M Y, H:i', (int)filemtime($pdf))
       . ", " . number_format(filesize($pdf)) . " bytes\n";
    echo "   THIS FILE IS SERVED AS-IS on every download. If it was made before the\n";
    echo "   fix, it still carries the old QR code however correct the code is now.\n";
    echo "   Delete it and download again:\n";
    echo "     del \"" . str_replace('/', '\\', $pdf) . "\"\n";
}

echo "\n";