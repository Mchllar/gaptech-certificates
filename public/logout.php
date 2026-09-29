<?php
require __DIR__ . '/../app/bootstrap.php';
start_session();
$_SESSION = [];
session_destroy();
redirect('login.php');
