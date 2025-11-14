
<?php

require_once(__DIR__ . '/../../includes/config.php');
require_once(__DIR__ . '/../../includes/BaseFunctions.php');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response(0, 0, 405, "Method Not Allowed");
}

$input = json_decode(file_get_contents('php://input'), true);
$email = trim($input['email'] ?? '');

if (empty($email)) {
    send_json_response(0, 0, 400, "Email is required");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    send_json_response(0, 0, 400, "Invalid email format");
}

try {
    // Check if email exists
    $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE email = :email");
    $stmt->bindParam(':email', $email, PDO::PARAM_STR);
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        // Don't reveal if email exists or not for security
        send_json_response(1, 1, 200, "If the email exists, a password reset link has been sent");
    }
    
    $user = $stmt->fetch();
    
    // Generate reset token
    $resetToken = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
    
    // Store reset token in database (you may need to create this table)
    $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (:user_id, :token, :expires_at) 
                          ON DUPLICATE KEY UPDATE token = :token, expires_at = :expires_at");
    $stmt->bindParam(':user_id', $user['id'], PDO::PARAM_INT);
    $stmt->bindParam(':token', $resetToken, PDO::PARAM_STR);
    $stmt->bindParam(':expires_at', $expiresAt, PDO::PARAM_STR);
    $stmt->execute();
    
    // Here you would typically send an email with the reset link
    // For now, we'll just return the token (remove this in production)
    $resetLink = "https://shubham.staging.cloudmate.in/bdc_ims/reset-password.php?token=" . $resetToken;
    
    send_json_response(1, 1, 200, "Password reset link has been sent to your email", [
        'reset_link' => $resetLink, // Remove this in production
        'token' => $resetToken // Remove this in production
    ]);
    
} catch (PDOException $e) {
    error_log("Forgot password error: " . $e->getMessage());
    send_json_response(0, 0, 500, "Error processing request");
}
?>