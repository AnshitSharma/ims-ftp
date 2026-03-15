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
}
