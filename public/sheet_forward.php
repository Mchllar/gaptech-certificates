<?php
/**
 * Kept so every existing link keeps working.
 *
 * Goes in public/sheet.php, replacing what is there.
 *
 * The rules for who may print or download a certificate now live in one place,
 * certificate_print.php. This file only forwards to it, carrying the id and the
 * print flag across.
 */
$id    = (int)($_GET['id'] ?? 0);
$query = 'id=' . $id . (empty($_GET['print']) ? '' : '&print=1');

header('Location: certificate_print.php?' . $query, true, 302);
exit;
