<?php
// csrf.php - simple CSRF helpers
if (session_status() === PHP_SESSION_NONE) session_start();

function csrf_get_token() {
    if (!isset($_SESSION['csrf_token'])) {
        try {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $_SESSION['csrf_token'] = bin2hex(md5(uniqid('', true)));
        }
    }
    return $_SESSION['csrf_token'];
}

function csrf_validate_token($token) {
    if (!isset($_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], (string)$token);
}
?>