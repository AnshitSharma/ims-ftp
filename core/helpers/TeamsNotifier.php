<?php
/**
 * TeamsNotifier - personal Teams messages through a Power Automate flow.
 *
 * IMS POSTs to TEAMS_WORKFLOW_URL, the trigger URL of a Workflows flow ("When a
 * Teams webhook request is received" -> "Post card in a chat or channel", posted
 * as Flow bot in a chat with the recipient). No Teams bot or app registration.
 *
 * The body is the standard Teams webhook message shape -- an `attachments` list
 * holding one Adaptive Card -- plus top-level `recipient`, `title`, `text` and
 * `link`. The flow reads `recipient` for whom to message and posts
 * attachments[0].content as the card. Because the shape is standard, the stock
 * "post to a channel" Workflows template accepts the same request unchanged.
 *
 * @package BDC_IMS
 * @subpackage Helpers
 */

class TeamsNotifier
{
    const HTTP_TIMEOUT = 10;

    public static function isConfigured()
    {
        return defined('TEAMS_WORKFLOW_URL') && TEAMS_WORKFLOW_URL !== '';
    }

    /**
     * @param string      $recipient the person's Microsoft 365 sign-in address
     * @param string      $title
     * @param string      $body
     * @param string|null $link absolute URL for the button
     * @return array [bool ok, string|null error]
     */
    public static function send($recipient, $title, $body, $link = null)
    {
        if (!self::isConfigured()) {
            return [false, 'Teams is not configured'];
        }

        $card = [
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'type' => 'AdaptiveCard',
            'version' => '1.4',
            'body' => [
                ['type' => 'TextBlock', 'text' => 'BDC IMS', 'size' => 'Small', 'isSubtle' => true, 'weight' => 'Bolder'],
                ['type' => 'TextBlock', 'text' => $title, 'size' => 'Medium', 'weight' => 'Bolder', 'wrap' => true],
                ['type' => 'TextBlock', 'text' => $body, 'wrap' => true],
            ],
        ];
        if ($link) {
            $card['actions'] = [['type' => 'Action.OpenUrl', 'title' => 'Open in IMS', 'url' => $link]];
        }

        $payload = json_encode([
            'type' => 'message',
            'recipient' => $recipient,
            'title' => $title,
            'text' => $body,
            'link' => $link,
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => $card,
            ]],
        ]);

        $ch = curl_init(TEAMS_WORKFLOW_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
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

        // Never the URL in a log line or an error: its query string is the
        // flow's signature.
        if ($response === false) {
            return [false, 'Teams flow unreachable: ' . $error];
        }
        // The trigger answers 202 Accepted once the run is queued. Whether the
        // flow then manages to post is only visible in its run history.
        if ($status >= 200 && $status < 300) {
            return [true, null];
        }
        return [false, 'Teams flow HTTP ' . $status];
    }
}
