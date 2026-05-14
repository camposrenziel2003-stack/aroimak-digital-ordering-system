<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
    header("Location: login.php");
    exit;
}
?>