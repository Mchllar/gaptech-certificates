<?php
/**
 * Kept so every existing link keeps working.
 *
 * Goes in public/download.php, replacing what is there.
 *
 * The rules for who may print or download a certificate now live in one place,
 * certificate_print.php. This file only forwards to it.
 */
$id = (int)($_GET['id'] ?? 0);

header('Location: certificate_print.php?id=' . $id . '&download=1', true, 302);
exit;
