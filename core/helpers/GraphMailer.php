<?php
/**
 * GraphMailer - notification email through Microsoft Graph, or mail().
 *
 * Graph path: app-only (client-credentials) token for the sign-in Entra app,
 * then POST /users/{MAIL_SENDER_UPN}/sendMail. Needs the Mail.Send APPLICATION
 * permission with admin consent, and an Exchange application access policy that
 * limits the app to that one mailbox -- without the policy, Mail.Send lets the
 * app send as anyone in the tenant.
 *
 * The token is fetched once per PHP request and held in memory only. It is not
 * cached to disk: unlike the JWKS, it is a credential.
 *
 * Fallback: with MAIL_SENDER_UPN unset, mail() -- the same transport the
 * password-reset email already uses.
 *
 * @package BDC_IMS
 * @subpackage Helpers
 */

class GraphMailer
{
    const HTTP_TIMEOUT = 10;

    private static $token = null;
    private static $tokenExpiresAt = 0;

    public static function usesGraph()
    {
        return defined('MAIL_SENDER_UPN') && MAIL_SENDER_UPN !== ''
            && defined('MS_TENANT_ID') && MS_TENANT_ID !== ''
            && defined('MS_CLIENT_ID') && MS_CLIENT_ID !== ''
            && defined('MS_CLIENT_SECRET') && MS_CLIENT_SECRET !== '';
    }

    public static function transportName()
    {
        return self::usesGraph() ? 'graph' : 'mail';
    }

    /**
     * @param string      $to
     * @param string      $subject
     * @param string      $title    heading inside the email
     * @param string      $body     plain text; escaped here
     * @param string|null $link     absolute URL for the button
     * @return array [bool ok, string|null error]
     */
    public static function send($to, $subject, $title, $body, $link = null)
    {
        $html = self::template($title, $body, $link);
        return self::usesGraph()
            ? self::sendViaGraph($to, $subject, $html)
            : self::sendViaMail($to, $subject, $html);
    }

    private static function sendViaGraph($to, $subject, $html)
    {
        $token = self::accessToken();
        if ($token === null) {
            return [false, 'Could not obtain a Graph token'];
        }

        $payload = json_encode([
            'message' => [
                'subject' => $subject,
                'body' => ['contentType' => 'HTML', 'content' => $html],
                'toRecipients' => [['emailAddress' => ['address' => $to]]],
            ],
            'saveToSentItems' => false,
        ]);

        $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode(MAIL_SENDER_UPN) . '/sendMail';
        list($status, $response) = self::http($url, $payload, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ]);

        if ($status === 202) {
            return [true, null];
        }
        // Graph's error code ("ErrorAccessDenied", "MailboxNotEnabledForRESTAPI")
        // is the useful part, and it carries nothing secret.
        $decoded = json_decode((string)$response, true);
        $code = $decoded['error']['code'] ?? '';
        return [false, 'Graph sendMail HTTP ' . $status . ($code !== '' ? " ($code)" : '')];
    }

    private static function accessToken()
    {
        if (self::$token !== null && time() < self::$tokenExpiresAt - 60) {
            return self::$token;
        }

        $url = 'https://login.microsoftonline.com/' . rawurlencode(MS_TENANT_ID) . '/oauth2/v2.0/token';
        list($status, $response) = self::http($url, http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => MS_CLIENT_ID,
            'client_secret' => MS_CLIENT_SECRET,
            'scope' => 'https://graph.microsoft.com/.default',
        ]), ['Content-Type: application/x-www-form-urlencoded']);

        $decoded = json_decode((string)$response, true);
        if ($status !== 200 || empty($decoded['access_token'])) {
            error_log('GraphMailer: token request returned HTTP ' . $status
                . (isset($decoded['error']) ? ' (' . $decoded['error'] . ')' : ''));
            return null;
        }

        self::$token = $decoded['access_token'];
        self::$tokenExpiresAt = time() + (int)($decoded['expires_in'] ?? 3600);
        return self::$token;
    }

    private static function sendViaMail($to, $subject, $html)
    {
        $fromAddress = getenv('MAIL_FROM_ADDRESS') ?: 'noreply@bdc-ims.com';
        $fromName = getenv('MAIL_FROM_NAME') ?: 'BDC IMS';

        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$fromName} <{$fromAddress}>\r\n";

        // Encoded so a Request title with non-ASCII text survives the header.
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        return @mail($to, $encodedSubject, $html, $headers)
            ? [true, null]
            : [false, 'mail() returned false'];
    }

    /**
     * @return array [int status (0 = no response), string body]
     */
    private static function http($url, $body, array $headers)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            // Host only -- the token URL carries the tenant id.
            error_log('GraphMailer: request to ' . parse_url($url, PHP_URL_HOST) . ' failed: ' . $error);
            return [0, ''];
        }
        return [$status, $response];
    }

    private static function template($title, $body, $link)
    {
        $e = function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        };
        $titleHtml = $e($title);
        $bodyHtml = nl2br($e($body));
        $button = $link
            ? '<p style="margin:28px 0 8px;"><a href="' . $e($link) . '" style="display:inline-block;padding:11px 26px;'
              . 'background:#0f766e;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;">Open in IMS</a></p>'
            : '';
        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f3f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
  <div style="max-width:600px;margin:0 auto;padding:24px 16px;">
    <div style="background:#0f766e;color:#ffffff;padding:16px 24px;border-radius:8px 8px 0 0;font-size:15px;font-weight:600;">
      BDC Inventory Management System
    </div>
    <div style="background:#ffffff;padding:28px 24px;border-radius:0 0 8px 8px;">
      <h2 style="margin:0 0 14px;font-size:19px;line-height:1.35;">{$titleHtml}</h2>
      <p style="margin:0;font-size:14px;line-height:1.6;">{$bodyHtml}</p>
      {$button}
    </div>
    <p style="text-align:center;color:#6b7280;font-size:12px;margin:18px 0 0;">
      &copy; {$year} BDC IMS. You can turn these emails off from the bell in IMS.
    </p>
  </div>
</body>
</html>
HTML;
    }
}
