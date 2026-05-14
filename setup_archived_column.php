<?php
/**
 * Setup script to add 'archived' column to orders table if it doesn't exist
 * Run once via browser: http://localhost/thai_digital/setup_archived_column.php
 */

session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin'])) {
    echo "<h2>Access Denied</h2><p>You must be logged in as admin.</p>";
    exit;
}

include "config.php";

try {
    // Check if 'archived' column exists
    $result = $conn->query("SHOW COLUMNS FROM orders LIKE 'archived'");
    
    if ($result && $result->num_rows === 0) {
        // Column doesn't exist, so add it
        $sql = "ALTER TABLE orders ADD COLUMN archived TINYINT(1) DEFAULT 0 AFTER id";
        
        if ($conn->query($sql)) {
            echo "<div style='padding:20px; background:#d4edda; border:1px solid #c3e6cb; border-radius:4px; color:#155724;'>";
            echo "<h2>✓ Success!</h2>";
            echo "<p>Added 'archived' column to orders table.</p>";
            echo "<p>Canceled orders will now be archived and visible in the Archived section.</p>";
            echo "</div>";
        } else {
            echo "<div style='padding:20px; background:#f8d7da; border:1px solid #f5c6cb; border-radius:4px; color:#721c24;'>";
            echo "<h2>✗ Error!</h2>";
            echo "<p>Failed to add 'archived' column: " . htmlspecialchars($conn->error) . "</p>";
            echo "</div>";
        }
    } else {
        echo "<div style='padding:20px; background:#d1ecf1; border:1px solid #bee5eb; border-radius:4px; color:#0c5460;'>";
        echo "<h2>ℹ Info</h2>";
        echo "<p>'archived' column already exists in orders table.</p>";
        echo "<p>Everything is set up correctly!</p>";
        echo "</div>";
    }
    
    echo "<hr>";
    echo "<p><a href='archived.php'>← Go to Archived</a> | <a href='index.php'>← Go to Dashboard</a></p>";
    
} catch (Exception $e) {
    echo "<div style='padding:20px; background:#f8d7da; border:1px solid #f5c6cb; border-radius:4px; color:#721c24;'>";
    echo "<h2>✗ Error!</h2>";
    echo "<p>Exception: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

$conn->close();
?>
