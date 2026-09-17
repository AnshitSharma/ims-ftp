<?php
/**
 * RequestHelper.php
 *
 * Helper class for standardized request parsing
 *
 * @package BDC_IMS
 * @subpackage Helpers
 */

class RequestHelper
{
    private static $cachedData = null;

    /**
     * Parse request data from POST or JSON body (cached per request)
     *
     * @return array Merged request data
     */
    public static function parseRequestData()
    {
        if (self::$cachedData !== null) {
            return self::$cachedData;
        }

        $data = $_POST;
        $rawInput = file_get_contents('php://input');

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isJson = strpos($contentType, 'application/json') !== false;

        if ($isJson || (!empty($rawInput) && empty($_POST))) {
            $jsonData = json_decode($rawInput, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                $data = array_merge($data, $jsonData);
            }
        }

        if (empty($data) && !empty($_SERVER['CONTENT_LENGTH']) && !empty($rawInput)) {
            parse_str($rawInput, $parsedData);
            if (is_array($parsedData)) {
                $data = array_merge($data, $parsedData);
            }
        }

        self::$cachedData = $data;
        return $data;
    }

    /**
     * Get a value from request data with default
     *
     * @param string $key Key to retrieve
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        return self::parseRequestData()[$key] ?? $default;
    }
    /**
     * Gate a pipeline handler on "the caller holds any one of these permissions".
     *
     * pipeline.manage is the blanket grant, so every handler accepted it alongside
     * its own specific permission; it is implied here and callers list only the
     * specific one(s). Pass an empty $anyOf for a handler that requires
     * pipeline.manage and nothing else.
     *
     * Sends the 403 and exits when none of them holds, so a caller that returns
     * has passed. The return value is whether the caller holds pipeline.manage,
     * which several handlers pass down to PipelineManager as the "may act on
     * anyone's stage" flag.
     *
     * @param object $acl     ACL instance
     * @param int    $userId  Authenticated user id
     * @param array  $anyOf   Permission names, any one of which grants access
     * @param string $message 403 message, kept per-handler so the wording does not change
     * @return bool           True when the caller holds pipeline.manage
     */
    public static function requirePipelinePermission($acl, $userId, array $anyOf, $message)
    {
        $canManage = $acl->hasPermission($userId, 'pipeline.manage');
        if ($canManage) {
            return true;
        }

        foreach ($anyOf as $permission) {
            if ($acl->hasPermission($userId, $permission)) {
                return false;
            }
        }

        send_json_response(false, true, 403, $message, null);
        exit;
    }

    /**
     * The pipeline id under any of the names the API accepts for it.
     *
     * Requests are tickets, so `ticket_id` is a long-standing alias of
     * `pipeline_id` and both arrive by POST or by query string. Sends the 400 and
     * exits when it is missing or non-numeric, so a caller that returns has a
     * usable id.
     *
     * @param string $message 400 message, kept per-handler
     * @return int
     */
    public static function pipelineId($message = 'pipeline_id is required and must be numeric')
    {
        $id = $_POST['pipeline_id'] ?? $_GET['pipeline_id'] ?? $_POST['ticket_id'] ?? $_GET['ticket_id'] ?? null;
        if (empty($id) || !is_numeric($id)) {
            send_json_response(false, true, 400, $message, null);
            exit;
        }
        return (int)$id;
    }

    /**
     * A required numeric request parameter, or a 400 and exit.
     *
     * @param string $key
     * @param string $message 400 message, kept per-handler
     * @return int
     */
    public static function requireNumeric($key, $message = null)
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? null;
        if (empty($value) || !is_numeric($value)) {
            send_json_response(false, true, 400, $message ?: "$key is required and must be numeric", null);
            exit;
        }
        return (int)$value;
    }
}
