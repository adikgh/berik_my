<?php
require_once __DIR__ . '/config/app.php';
$_SESSION = [];
session_destroy();
header('Location: ' . (qr_mode() ? '/menu.php' : '/login.php'));
