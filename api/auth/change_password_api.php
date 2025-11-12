
<?php

require_once(__DIR__ . '/../../includes/db_config.php');
require_once(__DIR__ . '/../../includes/BaseFunctions.php');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: *');

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response(0, 0, 405, "Method Not Allowed");
}

// Check if user is logged in
$user = isUserLoggedIn($pdo);
if (!$user) {
    send_json_response(0, 0, 401, "Unauthorized");
}

$input = json_decode(file_get_contents('php://input'), true);

$currentPassword = $input['current_password'] ?? '';
$newPassword = $input['new_password'] ?? '';
$confirmPassword = $input['confirm_password'] ?? '';

$errors = [];

// Validate input
if (empty($currentPassword)) {
    $errors[] = "Current password is required";
}

if (empty($newPassword)) {
    $errors[] = "New password is required";
} elseif (strlen($newPassword) < 8) {
    $errors[] = "New password must be at least 8 characters";
}

if (empty($confirmPassword)) {
    $errors[] = "Confirm password is required";
} elseif ($newPassword !== $confirmPassword) {
    $errors[] = "New password and confirm password do not match";
}

if (!empty($errors)) {
    send_json_response(0, 0, 400, "Validation failed", ['errors' => $errors]);
}

try {
    // Verify current password
    if (!password_verify($currentPassword, $user['password'])) {
        send_json_response(0, 0, 400, "Current password is incorrect");
    }
    
    // Update password
    $hashedNewPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("UPDATE users SET password = :password WHERE id = :id");
    $stmt->bindParam(':password', $hashedNewPassword, PDO::PARAM_STR);
    $stmt->bindParam(':id', $user['id'], PDO::PARAM_INT);
    
    if ($stmt->execute()) {
        send_json_response(1, 1, 200, "Password changed successfully");
    } else {
        send_json_response(0, 0, 500, "Failed to update password");
    }
    
} catch (PDOException $e) {
    error_log("Change password error: " . $e->getMessage());
    send_json_response(0, 0, 500, "Database error occurred");
}
?>
