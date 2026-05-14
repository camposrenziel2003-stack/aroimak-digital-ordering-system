<?php
session_start();

// Ensure the request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

// Get the item ID from the POST request
$id_to_remove = $_POST['id'] ?? null;

// Check if an ID was provided
if ($id_to_remove === null) {
    echo json_encode(['status' => 'error', 'message' => 'Item ID not provided.']);
    exit;
}

// Check if the cart session exists
if (!isset($_SESSION['cart'])) {
    echo json_encode(['status' => 'error', 'message' => 'Cart is empty.']);
    exit;
}

// Check if the item exists in the cart and remove it
if (isset($_SESSION['cart'][$id_to_remove])) {
    unset($_SESSION['cart'][$id_to_remove]);
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Item not found in cart.']);
}
?>