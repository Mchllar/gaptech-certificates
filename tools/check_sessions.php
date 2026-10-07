<?php
/**
 * Why "Your session expired" keeps appearing.
 *
 * Put this in the project's tools/ folder and run it from the command line:
 *   php tools/check_sessions.php
 *
 * It checks three things: that PHP can write session files, that no PHP file
 * sends output before the session starts (a BOM or a blank line before <?php),
 * and that a session value survives from one request to the next.
 * Delete the file once the problem is fixed.
 */

$root = dirname(__DIR__);
$problems = 0;

echo "\n1. Session storage\n";
$path = ini_get('session.save_path') ?: sys_get_temp_dir();
echo "   save_path : {$path}\n";
if (!is_dir($path)) {
    echo "   PROBLEM   : that folder does not exist.\n";
    echo "               Create it, or set session.save_path in php.ini to C:\\xampp\\tmp\n";
    $problems++;
} elseif (!is_writable($path)) {
    echo "   PROBLEM   : the folder is not writable, so sessions cannot be saved.\n";
    $problems++;
} else {
    echo "   writable  : yes\n";
}
echo "   php.ini   : " . (php_ini_loaded_file() ?: 'none loaded') . "\n";

echo "\n2. Files that send output before PHP starts\n";
echo "   (a byte-order mark or a blank line before <?php breaks the session cookie)\n";
$found = 0;
$dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($dir as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $text = file_get_contents($file->getPathname());
    $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());

    if (substr($text, 0, 3) === "\xEF\xBB\xBF") {
        echo "   BOM       : {$rel}\n";
        $found++;
        continue;
    }
    $start = strpos($text, '<?php');
    if ($start === false) {
        continue;
    }
    if ($start > 0 && trim(substr($text, 0, $start)) === '') {
        echo "   blank line: {$rel} ({$start} characters before <?php)\n";
        $found++;
    }
    // whitespace after a closing tag at the end of a file is the other common cause
    $tail = rtrim($text);
    if (substr($tail, -2) === '?>' && strlen($text) > strlen($tail)) {
        echo "   trailing  : {$rel} (whitespace after the closing ?>)\n";
        $found++;
    }
}
if ($found === 0) {
    echo "   none found\n";
} else {
    $problems += $found;
    echo "\n   FIX: open each file in VS Code or Notepad++ (not Notepad).\n";
    echo "        VS Code: click the encoding in the bottom bar, Save with Encoding,\n";
    echo "        choose 'UTF-8' (NOT 'UTF-8 with BOM'). Delete anything above <?php,\n";
    echo "        and delete the final ?> at the end of pure-PHP files.\n";
}

echo "\n3. Writing and reading a session\n";
session_start();
$id = session_id();
$_SESSION['probe'] = 'ok';
session_write_close();
$file = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $id;
if (is_file($file)) {
    echo "   saved to  : {$file}\n";
    echo "   contents  : " . trim((string)file_get_contents($file)) . "\n";
    @unlink($file);
} else {
    echo "   PROBLEM   : no session file was written for id {$id}.\n";
    $problems++;
}

echo "\n" . ($problems === 0
    ? "Nothing wrong found here. If the message still appears, it is a stale page:\n"
      . "open login.php fresh with Ctrl+F5 and sign in without using the back button.\n"
      . "Also use ONE address - a form opened on localhost cannot be submitted on 192.168.x.x.\n"
    : "{$problems} thing(s) to fix above.\n") . "\n";
