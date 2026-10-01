<?php
/**
 * NotificationService - in-app notifications plus the email / Teams outbox.
 *
 * notify() is called from INSIDE the event's own transaction. It writes the
 * in-app row (`notifications`) and one `notification_deliveries` row per
 * external channel, so an event that rolls back never notifies anybody.
 *
 * Nothing is sent inline. The first notify() of a request registers a shutdown
 * function that, after the API response has been flushed, delivers the rows that
 * are due -- this request's, and up to SWEEP_LIMIT older retries. The server has
 * no shell and no cron, so retries ride on later traffic: any request that
 * queues something, and every notification-* call (the bell polls once a
 * minute), runs a sweep.
 *
 * Every delivery is CLAIMED with a conditional UPDATE before it is sent, so two
 * overlapping sweeps cannot send it twice. A claim that is never resolved (the
 * process died mid-send) becomes claimable again after STALE_SENDING_MINUTES.
 *
 * Fails open in the one direction that matters: a missing table, a missing
 * helper file or a Graph outage skips the notification. It never fails the
 * Request action that caused it.
 *
 * @package BDC_IMS
 * @subpackage Helpers
 */

require_once(__DIR__ . '/SchemaHelper.php');

// New files, so loaded defensively: a channel whose helper has not deployed
// yet is skipped, not fatal.
foreach (['GraphMailer.php', 'TeamsNotifier.php'] as $notificationHelper) {
    if (is_readable(__DIR__ . '/' . $notificationHelper)) {
        require_once(__DIR__ . '/' . $notificationHelper);
    }
}
unset($notificationHelper);

class NotificationService
{
    const MAX_ATTEMPTS = 5;
    const SWEEP_LIMIT = 10;
    const STALE_SENDING_MINUTES = 10;

    /** Minutes to wait after the Nth failed attempt. */
    const BACKOFF_MINUTES = [1, 5, 30, 120];

    const REQUEST_LINK = 'pages/dashboard/requests.html?request=';

    private static $drainRegistered = false;

    /**
     * Has seeder 2026_10_02_001 been applied?
     */
    public static function isAvailable(PDO $pdo)
    {
        return SchemaHelper::hasTable($pdo, 'notifications')
            && SchemaHelper::hasTable($pdo, 'notification_deliveries')
            && SchemaHelper::hasTable($pdo, 'user_notification_prefs');
    }

    public static function teamsAvailable()
    {
        return class_exists('TeamsNotifier') && TeamsNotifier::isConfigured();
    }

    public static function emailAvailable()
    {
        return class_exists('GraphMailer');
    }

    // -------------------------------------------------------------------------
    // Request events
    // -------------------------------------------------------------------------

    /**
     * Notify about one Request event. The wording lives here, so PipelineManager
     * only says what happened and to whom.
     *
     * @param string $event      stage_activated | stage_reassigned | pipeline_completed
     *                           | pipeline_rejected | pipeline_cancelled | prerequisite_resolved
     * @param int    $ticketId
     * @param int[]  $recipientIds
     * @param int    $actorId    never notified about their own action
     * @param array  $extra      step, reason, note -- whichever the event uses
     */
    public static function requestEvent(PDO $pdo, $event, $ticketId, array $recipientIds, $actorId, array $extra = [])
    {
        try {
            if (empty($recipientIds) || !self::isAvailable($pdo)) {
                return;
            }

            $stmt = $pdo->prepare("SELECT ticket_number, title FROM tickets WHERE id = ?");
            $stmt->execute([(int)$ticketId]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$ticket) {
                return;
            }

            $number = '#' . $ticket['ticket_number'];
            $name = '"' . $ticket['title'] . '"';
            $step = isset($extra['step']) ? "'" . $extra['step'] . "'" : 'a step';
            $by = self::actorName($pdo, $actorId);
            $byText = $by !== '' ? " by $by" : '';
            $reason = isset($extra['reason']) && trim((string)$extra['reason']) !== ''
                ? ' Reason: ' . trim((string)$extra['reason'])
                : '';

            switch ($event) {
                case 'stage_activated':
                    $title = "Request $number needs you: $step";
                    $body = "$name has reached the step $step, which is assigned to you or your team. "
                        . 'Open the request to claim or complete it.';
                    break;
                case 'stage_reassigned':
                    $title = "Request $number: step $step reassigned to you";
                    $body = "The step $step of $name was reassigned to you or your team$byText.";
                    break;
                case 'pipeline_completed':
                    $title = "Request $number completed";
                    $body = "$name has finished all its steps.";
                    break;
                case 'pipeline_rejected':
                    $title = "Request $number was rejected";
                    $body = "$name was rejected at the step $step$byText.$reason";
                    break;
                case 'pipeline_cancelled':
                    $title = "Request $number was cancelled";
                    $body = "$name was cancelled$byText.$reason";
                    break;
                case 'prerequisite_resolved':
                    $title = "Request $number: a prerequisite was resolved";
                    $body = isset($extra['note']) ? (string)$extra['note'] : "A prerequisite of $name was resolved.";
                    break;
                default:
                    return;
            }

            self::notify($pdo, $event, $recipientIds, $actorId, [
                'ticket_id' => (int)$ticketId,
                'title' => $title,
                'body' => $body,
                'link' => self::REQUEST_LINK . (int)$ticketId,
            ]);
        } catch (\Throwable $e) {
            error_log('NotificationService::requestEvent failed: ' . $e->getMessage());
        }
    }

    /**
     * The users who own a step: the named user, or every active member of the
     * owning role.
     *
     * @return int[]
     */
    public static function stageOwnerIds(PDO $pdo, $userId, $roleId)
    {
        if (!empty($userId)) {
            return [(int)$userId];
        }
        if (empty($roleId)) {
            return [];
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT ur.user_id FROM user_roles ur
                   JOIN users u ON u.id = ur.user_id
                  WHERE ur.role_id = ? AND u.status = 'active'"
            );
            $stmt->execute([(int)$roleId]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            error_log('NotificationService::stageOwnerIds failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * The person who raised a Request.
     *
     * @return int[] zero or one id
     */
    public static function requesterIds(PDO $pdo, $ticketId)
    {
        try {
            $stmt = $pdo->prepare("SELECT created_by FROM tickets WHERE id = ?");
            $stmt->execute([(int)$ticketId]);
            $id = (int)$stmt->fetchColumn();
            return $id > 0 ? [$id] : [];
        } catch (\Throwable $e) {
            error_log('NotificationService::requesterIds failed: ' . $e->getMessage());
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Queueing
    // -------------------------------------------------------------------------

    /**
     * Write one notification per recipient, plus its email / Teams deliveries
     * as each person's preferences allow. Never throws.
     *
     * @param array $content ticket_id, title, body, link (relative to the UI root)
     */
    public static function notify(PDO $pdo, $event, array $recipientIds, $actorId, array $content)
    {
        try {
            if (!self::isAvailable($pdo)) {
                return;
            }

            $ids = [];
            foreach ($recipientIds as $id) {
                $id = (int)$id;
                if ($id > 0 && $id !== (int)$actorId) {
                    $ids[$id] = $id;
                }
            }
            if (empty($ids)) {
                return;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                "SELECT u.id, u.email, p.email_enabled, p.teams_enabled
                   FROM users u
                   LEFT JOIN user_notification_prefs p ON p.user_id = u.id
                  WHERE u.id IN ($placeholders) AND u.status = 'active'"
            );
            $stmt->execute(array_values($ids));
            $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $teamsOn = self::teamsAvailable();
            $emailOn = self::emailAvailable();
            $queued = false;

            foreach ($recipients as $r) {
                $notificationId = self::insertNotification($pdo, (int)$r['id'], $event, $actorId, $content);

                $email = trim((string)$r['email']);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue; // in-app only
                }
                // No prefs row means both channels on.
                if ($emailOn && $r['email_enabled'] !== '0' && $r['email_enabled'] !== 0) {
                    self::insertDelivery($pdo, $notificationId, 'email', $email);
                    $queued = true;
                }
                if ($teamsOn && $r['teams_enabled'] !== '0' && $r['teams_enabled'] !== 0) {
                    self::insertDelivery($pdo, $notificationId, 'teams', $email);
                    $queued = true;
                }
            }

            if ($queued) {
                self::scheduleSweep($pdo);
            }
        } catch (\Throwable $e) {
            error_log('NotificationService::notify failed: ' . $e->getMessage());
        }
    }

    private static function insertNotification(PDO $pdo, $userId, $event, $actorId, array $content)
    {
        $pdo->prepare(
            "INSERT INTO notifications (user_id, event, ticket_id, actor_user_id, title, body, link)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $userId,
            $event,
            isset($content['ticket_id']) ? (int)$content['ticket_id'] : null,
            $actorId ? (int)$actorId : null,
            mb_substr((string)$content['title'], 0, 255),
            isset($content['body']) ? (string)$content['body'] : null,
            isset($content['link']) ? (string)$content['link'] : null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    private static function insertDelivery(PDO $pdo, $notificationId, $channel, $recipient)
    {
        $pdo->prepare(
            "INSERT INTO notification_deliveries (notification_id, channel, recipient) VALUES (?, ?, ?)"
        )->execute([$notificationId, $channel, $recipient]);
        return (int)$pdo->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // Delivery
    // -------------------------------------------------------------------------

    /**
     * Run a delivery sweep once this request has answered. Idempotent.
     */
    public static function scheduleSweep(PDO $pdo)
    {
        if (self::$drainRegistered) {
            return;
        }
        self::$drainRegistered = true;

        register_shutdown_function(function () use ($pdo) {
            try {
                // A request that died mid-transaction: its rows were never
                // committed, and this connection can still see them.
                if ($pdo->inTransaction() || !self::isAvailable($pdo)) {
                    return;
                }
                ignore_user_abort(true);
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } elseif (function_exists('litespeed_finish_request')) {
                    litespeed_finish_request();
                }
                self::sweep($pdo, self::SWEEP_LIMIT);
            } catch (\Throwable $e) {
                error_log('NotificationService sweep failed: ' . $e->getMessage());
            }
        });
    }

    /**
     * Deliver up to $limit due rows.
     */
    public static function sweep(PDO $pdo, $limit)
    {
        $stmt = $pdo->prepare(
            "SELECT id FROM notification_deliveries
              WHERE (status = 'pending' AND next_attempt_at <= NOW())
                 OR (status = 'sending' AND updated_at < NOW() - INTERVAL " . (int)self::STALE_SENDING_MINUTES . " MINUTE)
              ORDER BY id
              LIMIT " . (int)$limit
        );
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $deliveryId) {
            self::deliver($pdo, (int)$deliveryId);
        }
    }

    /**
     * Claim, send and record one delivery.
     *
     * @return array|null ['ok' => bool, 'error' => string|null], or null when
     *                    somebody else holds the claim / it is not due
     */
    public static function deliver(PDO $pdo, $deliveryId)
    {
        $claim = $pdo->prepare(
            "UPDATE notification_deliveries
                SET status = 'sending', attempts = attempts + 1
              WHERE id = ?
                AND ((status = 'pending' AND next_attempt_at <= NOW())
                  OR (status = 'sending' AND updated_at < NOW() - INTERVAL " . (int)self::STALE_SENDING_MINUTES . " MINUTE))"
        );
        $claim->execute([$deliveryId]);
        if ($claim->rowCount() !== 1) {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT d.channel, d.recipient, d.attempts, n.title, n.body, n.link
               FROM notification_deliveries d
               JOIN notifications n ON n.id = d.notification_id
              WHERE d.id = ?"
        );
        $stmt->execute([$deliveryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $link = self::absoluteLink($row['link']);
        try {
            if ($row['channel'] === 'email' && class_exists('GraphMailer')) {
                list($ok, $error) = GraphMailer::send($row['recipient'], '[IMS] ' . $row['title'], $row['title'], (string)$row['body'], $link);
            } elseif ($row['channel'] === 'teams' && class_exists('TeamsNotifier')) {
                list($ok, $error) = TeamsNotifier::send($row['recipient'], $row['title'], (string)$row['body'], $link);
            } else {
                list($ok, $error) = [false, 'Channel helper not deployed'];
            }
        } catch (\Throwable $e) {
            list($ok, $error) = [false, 'Exception: ' . $e->getMessage()];
        }

        if ($ok) {
            $pdo->prepare(
                "UPDATE notification_deliveries SET status = 'sent', sent_at = NOW(), last_error = NULL WHERE id = ?"
            )->execute([$deliveryId]);
            return ['ok' => true, 'error' => null];
        }

        $attempts = (int)$row['attempts'];
        $error = mb_substr((string)$error, 0, 500);
        if ($attempts >= self::MAX_ATTEMPTS) {
            $pdo->prepare(
                "UPDATE notification_deliveries SET status = 'failed', last_error = ? WHERE id = ?"
            )->execute([$error, $deliveryId]);
        } else {
            $backoff = self::BACKOFF_MINUTES[min($attempts, count(self::BACKOFF_MINUTES)) - 1];
            $pdo->prepare(
                "UPDATE notification_deliveries
                    SET status = 'pending', last_error = ?, next_attempt_at = NOW() + INTERVAL " . (int)$backoff . " MINUTE
                  WHERE id = ?"
            )->execute([$error, $deliveryId]);
        }
        error_log("NotificationService: {$row['channel']} delivery $deliveryId failed (attempt $attempts): $error");
        return ['ok' => false, 'error' => $error];
    }

    // -------------------------------------------------------------------------
    // Admin test
    // -------------------------------------------------------------------------

    /**
     * Send the caller one notification on every channel, synchronously, and
     * report what each channel did. Ignores the caller's opt-outs on purpose:
     * it tests the plumbing, not the preferences.
     *
     * @return array
     */
    public static function sendTest(PDO $pdo, $userId)
    {
        $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->execute([(int)$userId]);
        $email = trim((string)$stmt->fetchColumn());
        $hasEmail = (bool)filter_var($email, FILTER_VALIDATE_EMAIL);

        $notificationId = self::insertNotification($pdo, (int)$userId, 'test', null, [
            'title' => 'Test notification from IMS',
            'body' => 'If you are reading this, IMS notifications reach you here.',
            'link' => 'pages/dashboard/index.html',
        ]);

        $result = [
            'in_app' => ['sent' => true],
            'email' => [
                'transport' => self::emailAvailable() ? GraphMailer::transportName() : null,
                'sent' => false,
                'error' => null,
            ],
            'teams' => ['configured' => self::teamsAvailable(), 'sent' => false, 'error' => null],
        ];

        if (!$hasEmail) {
            $result['email']['error'] = 'Your IMS account has no email address';
            $result['teams']['error'] = 'Your IMS account has no email address';
            return $result;
        }

        foreach (['email' => self::emailAvailable(), 'teams' => self::teamsAvailable()] as $channel => $on) {
            if (!$on) {
                $result[$channel]['error'] = $channel === 'teams' ? 'TEAMS_WORKFLOW_URL is not set' : 'Mail helper not deployed';
                continue;
            }
            $outcome = self::deliver($pdo, self::insertDelivery($pdo, $notificationId, $channel, $email));
            $result[$channel]['sent'] = $outcome !== null && $outcome['ok'];
            $result[$channel]['error'] = $outcome === null ? 'Could not claim the delivery' : $outcome['error'];
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function actorName(PDO $pdo, $actorId)
    {
        if (empty($actorId)) {
            return '';
        }
        $stmt = $pdo->prepare("SELECT firstname, lastname, username FROM users WHERE id = ?");
        $stmt->execute([(int)$actorId]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            return '';
        }
        $full = trim(trim((string)$u['firstname']) . ' ' . trim((string)$u['lastname']));
        return $full !== '' ? $full : (string)$u['username'];
    }

    /**
     * The UI is served from the domain root, the same host as the API. Same rule
     * as the password-reset link: FRONTEND_URL when set, else this request's host.
     */
    private static function absoluteLink($relative)
    {
        if ($relative === null || $relative === '') {
            return null;
        }
        $base = getenv('FRONTEND_URL');
        if (!$base) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            $host = $_SERVER['HTTP_HOST'] ?? '';
            if ($host === '') {
                return null;
            }
            $base = ($https ? 'https' : 'http') . '://' . $host;
        }
        return rtrim($base, '/') . '/' . ltrim($relative, '/');
    }
}
