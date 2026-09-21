<?php
/**
 * Response.php — split out of BaseFunctions.php (audit Phase 4, roadmap item 23).
 *
 * The two functions with no dependency on anything else in this codebase: the response
 * serializer every handler calls to end a request, and a pure UUID generator. Mechanical
 * split — bodies unchanged, still global functions.
 */

/**
 * Generate UUID v4
 */
function generateUUID() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Send JSON response with proper error handling
 */
function send_json_response($success, $authenticated, $code, $message, $data = null) {
    // Clean any output buffer
    if (ob_get_level()) {
        ob_clean();
    }

    http_response_code($code);
    header('Content-Type: application/json');

    $response = [
        'success' => (bool)$success,
        'authenticated' => (bool)$authenticated,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s'),
        'code' => $code
    ];

    // E.1 (audit §5.1): `data` used to be OMITTED when null, so the envelope was
    // not one shape but two, and every consumer had to know which it might get.
    // The key is now always present, carrying null when there is nothing — a
    // client can destructure the envelope unconditionally.
    //
    // null rather than []: an error response has no data, and saying so is not the
    // same as claiming it has empty data. Checked before changing this — nothing in
    // the frontend branches on the key's PRESENCE (no `'data' in`, no
    // hasOwnProperty, no Object.keys over it) and every read is either a truthiness
    // guard or a `?? {}`, all of which treat null and absent identically. So this
    // widens the contract without breaking the existing one.
    $response['data'] = $data;

    // JSON-013: JSON_PRETTY_PRINT added ~26% to every payload before compression and no
    // consumer parses on whitespace (the only .text() reads in the frontend are HTML
    // partials). Kept available behind an explicit ?pretty=1 for hand-debugging.
    $flags = 0;
    if (isset($_REQUEST['pretty']) && $_REQUEST['pretty'] === '1') {
        $flags = JSON_PRETTY_PRINT;
    }

    echo json_encode($response, $flags);
    exit();
}
