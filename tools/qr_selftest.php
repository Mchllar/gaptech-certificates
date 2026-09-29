<?php
/**
 * Open this in a browser after installing and scan the codes with a phone.
 * All five should open the address printed underneath. Delete this file afterwards.
 */
require __DIR__ . '/../app/qr.php';

$samples = [
    'https://example.com/c/1-a1',
    'http://192.168.1.10/certificates/public/verify.php?c=13621-a8f3k2',
    'https://verify.gaptechsolutions.com/c/13621-a8f3k2',
    'https://verify.gaptechsolutions.com/c/999999-zzzzzzzzzzzz',
    str_repeat('https://verify.gaptechsolutions.com/c/', 2) . '123456-abcdefghijklmnop',
];
echo '<h1>QR self-test</h1><p>Scan each code. It must open exactly the address shown under it.</p>';
foreach ($samples as $text) {
    echo '<div style="display:inline-block;margin:12px;text-align:center;font:12px sans-serif;max-width:220px">';
    echo '<img src="' . QrCode::dataUri($text) . '" style="width:180px;height:180px"><br>';
    echo htmlspecialchars($text) . '</div>';
}
