<?php
/**
 * app/zip.php - send several files to the browser as one .zip.
 *
 * Written out by hand rather than with ZipArchive because the zip extension is
 * switched off in XAMPP's php.ini by default, and a download that only works on
 * the machines where somebody remembered to turn it on is not a feature.
 *
 * The files are STORED, not compressed. Everything that goes through here is a
 * PDF, which is already compressed inside, so deflating it again would cost time
 * on every download and save almost nothing.
 */

/**
 * Stream a zip made of $files straight to the browser.
 *
 * @param array<string,string> $files  name inside the zip => path on disk
 */
function zip_send(array $files, string $zipName): void
{
    // DOS date and time for the headers - a zip has no other kind.
    $t       = getdate();
    $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
    $dosDate = (max($t['year'], 1980) - 1980) << 9 | ($t['mon'] << 5) | $t['mday'];

    $entries = [];
    $total   = 0;
    foreach ($files as $name => $path) {
        $name = str_replace(['\\', '/'], '-', (string)$name);
        $size = filesize($path);
        $crc  = hexdec(hash_file('crc32b', $path));
        $entries[] = compact('name', 'path', 'size', 'crc');
        // local header (30) + name + data, and the central record (46) + name
        $total += 30 + 46 + 2 * strlen($name) + $size;
    }
    $total += 22;   // end of central directory

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (function_exists('download_started')) {
        download_started();   // stops the page's spinner
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . $total);
    header('Cache-Control: private, no-store');

    $offset  = 0;
    $central = '';
    foreach ($entries as $f) {
        $len = strlen($f['name']);
        // bit 11 of the flags: the name is UTF-8
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $dosTime, $dosDate,
                      $f['crc'], $f['size'], $f['size'], $len, 0) . $f['name'];
        echo $local;
        readfile($f['path']);

        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $dosTime,
                         $dosDate, $f['crc'], $f['size'], $f['size'], $len, 0, 0, 0, 0,
                         0, $offset) . $f['name'];
        $offset += strlen($local) + $f['size'];
    }

    echo $central;
    echo pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries),
              strlen($central), $offset, 0);
}
