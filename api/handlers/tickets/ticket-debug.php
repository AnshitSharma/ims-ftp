<?php
/**
 * REMOVED: Debug endpoint removed for production security.
 * This endpoint was for development diagnostics only.
 */
header('Content-Type: application/json');
http_response_code(404);
echo json_encode(['success' => false, 'message' => 'Endpoint not available']);
exit;
