<?php
/**
 * ticket-debug.php
 *
 * Diagnostic endpoint to debug POST data reception issues
 *
 * Action: ticket-debug
 * Method: POST/GET
 * Permission: None (for debugging only - REMOVE IN PRODUCTION)
 *
 * This endpoint echoes back exactly what the server receives
 * to help diagnose Postman configuration issues
 */

header('Content-Type: application/json');

$debug = [
    'server_info' => [
        'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? 'NOT SET',
        'CONTENT_TYPE' => $_SERVER['CONTENT_TYPE'] ?? 'NOT SET',
        'CONTENT_LENGTH' => $_SERVER['CONTENT_LENGTH'] ?? 'NOT SET',
        'HTTP_AUTHORIZATION' => isset($_SERVER['HTTP_AUTHORIZATION']) ? 'SET (hidden)' : 'NOT SET',
    ],
    'received_data' => [
        'POST' => $_POST,
        'GET' => $_GET,
        'POST_count' => count($_POST),
        'GET_count' => count($_GET),
    ],
    'raw_input' => file_get_contents('php://input'),
    'parsed_attempts' => []
];

// Try parsing raw input as form data
parse_str(file_get_contents('php://input'), $parsedForm);
$debug['parsed_attempts']['form_urlencoded'] = $parsedForm;

// Try parsing as JSON
$jsonData = json_decode(file_get_contents('php://input'), true);
$debug['parsed_attempts']['json'] = [
    'success' => json_last_error() === JSON_ERROR_NONE,
    'error' => json_last_error_msg(),
    'data' => $jsonData
];

// Check for rejection_reason specifically
$debug['rejection_reason_check'] = [
    'in_POST' => isset($_POST['rejection_reason']),
    'in_GET' => isset($_GET['rejection_reason']),
    'POST_value' => $_POST['rejection_reason'] ?? null,
    'GET_value' => $_GET['rejection_reason'] ?? null,
    'POST_empty' => empty($_POST['rejection_reason']),
    'GET_empty' => empty($_GET['rejection_reason'])
];

// Search for any key containing "rejection"
$debug['keys_containing_rejection'] = [];
foreach (array_merge($_POST, $_GET) as $key => $value) {
    if (stripos($key, 'rejection') !== false) {
        $debug['keys_containing_rejection'][$key] = [
            'value' => $value,
            'length' => strlen($value),
            'type' => gettype($value)
        ];
    }
}

echo json_encode($debug, JSON_PRETTY_PRINT);
exit;
