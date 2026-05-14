<?php
session_start();
$_SESSION = [];
session_unset();
session_destroy();

// Prevent caching after logout
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
header("Pragma: no-cache");

header("Location: login.php");
exit;