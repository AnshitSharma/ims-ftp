<?php

require_once(__DIR__ . '/../../includes/config.php');
require_once(__DIR__ . '/../../includes/BaseFunctions.php');
require_once(__DIR__ . '/../../includes/JWTHelper.php');
require_once(__DIR__ . '/../../includes/ACL.php');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response(0, 0, 405, "Method Not Allowed");
}

$input = json_decode(file_get_contents('php://input'), true);

$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

if (empty($username) || empty($password)) {
    send_json_response(0, 0, 400, "Please enter both username and password");
}

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
    $stmt->bindParam(':username', $username, PDO::PARAM_STR);
    $stmt->execute();

    if ($stmt->rowCount() == 1) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $hash_user_password = $user['password'];

        if (password_verify($password, $hash_user_password)) {
            // Initialize JWT
            JWTHelper::init(JWT_SECRET);

            // Get user permissions
            $acl = new ACL($pdo);
            $permissions = $acl->getUserPermissions($user['id']);

            // Generate tokens
            $accessToken = JWTHelper::generateToken([
                'user_id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email']
            ]);

            $refreshToken = JWTHelper::generateRefreshToken();
            JWTHelper::storeRefreshToken($pdo, $user['id'], $refreshToken);

            send_json_response(1, 1, 200, "Login successful", [
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'firstname' => $user['firstname'],
                    'lastname' => $user['lastname']
                ],
                'tokens' => [
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken,
                    'token_type' => 'Bearer'
                ],
                'permissions' => $permissions
            ]);
        } else {
            send_json_response(0, 0, 401, "Invalid username or password");
        }
    } else {
        send_json_response(0, 0, 401, "User not found");
    }
} catch (PDOException $e) {
    error_log("Login error: " . $e->getMessage());
    send_json_response(0, 0, 500, "Database error occurred");
} catch (Exception $e) {
    error_log("Login error: " . $e->getMessage());
    send_json_response(0, 0, 500, "Login error occurred");
}
?>