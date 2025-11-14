
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

$username = trim($input['username'] ?? '');
$email = trim($input['email'] ?? '');
$password = $input['password'] ?? '';
$firstname = trim($input['firstname'] ?? '');
$lastname = trim($input['lastname'] ?? '');

$errors = [];

// Validate input
if (empty($username)) {
    $errors[] = "Username is required";
} elseif (!preg_match("/^[a-zA-Z0-9_]{3,20}$/", $username)) {
    $errors[] = "Username must be 3-20 characters and contain only letters, numbers, and underscores";
}

if (empty($email)) {
    $errors[] = "Email is required";
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Invalid email format";
}

if (empty($password)) {
    $errors[] = "Password is required";
} elseif (strlen($password) < 8) {
    $errors[] = "Password must be at least 8 characters";
}

if (empty($firstname)) {
    $errors[] = "First name is required";
}

if (empty($lastname)) {
    $errors[] = "Last name is required";
}

if (!empty($errors)) {
    send_json_response(0, 0, 400, "Validation failed", ['errors' => $errors]);
}

try {
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("INSERT INTO users (firstname, lastname, username, email, password, acl) VALUES (:firstname, :lastname, :username, :email, :password, 0)");
    
    $stmt->bindParam(':firstname', $firstname, PDO::PARAM_STR);
    $stmt->bindParam(':lastname', $lastname, PDO::PARAM_STR);
    $stmt->bindParam(':username', $username, PDO::PARAM_STR);
    $stmt->bindParam(':email', $email, PDO::PARAM_STR);
    $stmt->bindParam(':password', $hashed_password, PDO::PARAM_STR);
    
    if ($stmt->execute()) {
        $userId = $pdo->lastInsertId();
        send_json_response(1, 1, 200, "Account created successfully", [
            'user_id' => (int)$userId,
            'username' => $username,
            'email' => $email
        ]);
    }
} catch (PDOException $e) {
    if ($e->getCode() == 23000) {
        send_json_response(0, 0, 400, "Username or email already exists");
    } else {
        error_log("Registration error: " . $e->getMessage());
        send_json_response(0, 0, 500, "Registration failed. Please try again.");
    }
}
?>