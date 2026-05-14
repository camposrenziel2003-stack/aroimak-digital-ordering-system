<?php
// Get client IP address
$client_ip = $_SERVER['REMOTE_ADDR'];

// Connect to your database
$conn = new mysqli("localhost", "root", "", "digital_menu");

// Check if the IP address is assigned
$sql = "SELECT * FROM tablets WHERE ip_address = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $client_ip);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    // IP is assigned, allow access
    // Continue loading the user interface
} else {
    // IP is NOT assigned, block access
    echo "Access Denied: Your device is not authorized.";
    exit;
}
?>