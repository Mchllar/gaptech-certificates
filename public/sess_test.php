<?php
session_start();
$_SESSION['n'] = ($_SESSION['n'] ?? 0) + 1;
echo 'Counter: ' . $_SESSION['n'] . ' - reload this page a few times.';