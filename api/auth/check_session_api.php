<?php

require_once(__DIR__ . '/../../includes/db_config.php');
require_once(__DIR__ . '/../../includes/BaseFunctions.php');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: *');

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json_response(0, 0, 405, "Method Not Allowed");
}

$user = isUserLoggedIn($pdo);

if ($user) {
    send_json_response(1, 1, 200, "Session valid", [
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'firstname' => $user['firstname'],
            'lastname' => $user['lastname']
        ],
        'session_id' => session_id()
    ]);
} else {
    send_json_response(0, 0, 401, "Session invalid or expired");
}
?>