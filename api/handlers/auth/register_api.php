<?php
/**
 * DEPRECATED: This handler is no longer used directly.
 * All auth requests must go through the main router at api/api.php
 * Action: auth-register
 */
header('Content-Type: application/json');
http_response_code(403);
echo json_encode(['success' => false, 'message' => 'Direct handler access is not allowed. Use api/api.php with action=auth-register']);
exit;
