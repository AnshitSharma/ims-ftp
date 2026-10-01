<?php
/**
 * Notification handler -- the bell, the preferences, the admin test send.
 *
 * Included by api/api.php for the `notification` module, after
 * requireModulePermission() has checked notification.view / .manage. Every read
 * and write is scoped to the caller's own user id; no operation takes a user id
 * as input.
 *
 * Lives in handlers/users/ rather than a handlers/notifications/ of its own: a
 * file in a brand-new directory has failed to deploy before (see the `spec` case
 * in api.php).
 */

function handleNotificationOperations($operation, $user) {
    global $pdo;

    $servicePath = __DIR__ . '/../../../core/helpers/NotificationService.php';
    if (is_readable($servicePath)) {
        require_once($servicePath);
    }
    if (!class_exists('NotificationService') || !NotificationService::isAvailable($pdo)) {
        send_json_response(0, 1, 503, "Notifications are not available yet");
    }

    $userId = (int)$user['id'];

    // Retries have no cron; the bell's polling is what keeps them moving.
    NotificationService::scheduleSweep($pdo);

    switch ($operation) {
        case 'list':
            $limit = (int)($_POST['limit'] ?? 20);
            $limit = max(1, min(50, $limit));
            $beforeId = (int)($_POST['before_id'] ?? 0);

            $sql = "SELECT id, event, ticket_id, title, body, link, read_at, created_at
                      FROM notifications
                     WHERE user_id = ?" . ($beforeId > 0 ? " AND id < ?" : "") . "
                     ORDER BY id DESC
                     LIMIT $limit";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($beforeId > 0 ? [$userId, $beforeId] : [$userId]);

            $items = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $items[] = [
                    'id' => (int)$row['id'],
                    'event' => $row['event'],
                    'ticket_id' => $row['ticket_id'] !== null ? (int)$row['ticket_id'] : null,
                    'title' => $row['title'],
                    'body' => $row['body'],
                    'link' => $row['link'],
                    'read' => $row['read_at'] !== null,
                    'created_at' => $row['created_at'],
                ];
            }

            send_json_response(1, 1, 200, "OK", [
                'items' => $items,
                'unread_count' => notificationUnreadCount($pdo, $userId),
                'has_more' => count($items) === $limit,
            ]);
            break;

        case 'unread-count':
            send_json_response(1, 1, 200, "OK", ['unread_count' => notificationUnreadCount($pdo, $userId)]);
            break;

        case 'mark-read':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                send_json_response(0, 1, 400, "id is required");
            }
            $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL")
                ->execute([$id, $userId]);
            send_json_response(1, 1, 200, "OK", ['unread_count' => notificationUnreadCount($pdo, $userId)]);
            break;

        case 'mark-all-read':
            $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL")
                ->execute([$userId]);
            send_json_response(1, 1, 200, "OK", ['unread_count' => 0]);
            break;

        case 'get-preferences':
            send_json_response(1, 1, 200, "OK", notificationPreferences($pdo, $userId));
            break;

        case 'set-preferences':
            $current = notificationPreferences($pdo, $userId);
            $email = isset($_POST['email_enabled']) ? notificationFlag($_POST['email_enabled']) : (int)$current['email_enabled'];
            $teams = isset($_POST['teams_enabled']) ? notificationFlag($_POST['teams_enabled']) : (int)$current['teams_enabled'];

            $pdo->prepare(
                "INSERT INTO user_notification_prefs (user_id, email_enabled, teams_enabled) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE email_enabled = VALUES(email_enabled), teams_enabled = VALUES(teams_enabled)"
            )->execute([$userId, $email, $teams]);

            send_json_response(1, 1, 200, "Preferences saved", notificationPreferences($pdo, $userId));
            break;

        case 'test':
            send_json_response(1, 1, 200, "Test notification sent", NotificationService::sendTest($pdo, $userId));
            break;

        default:
            send_json_response(0, 1, 400, "Invalid notification operation: $operation");
    }
}

function notificationUnreadCount($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

/**
 * The caller's switches, plus whether each channel can reach them at all, so
 * the UI can explain a disabled toggle instead of offering a dead one.
 */
function notificationPreferences($pdo, $userId) {
    $stmt = $pdo->prepare(
        "SELECT u.email, p.email_enabled, p.teams_enabled
           FROM users u
           LEFT JOIN user_notification_prefs p ON p.user_id = u.id
          WHERE u.id = ?"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $hasEmail = (bool)filter_var(trim((string)($row['email'] ?? '')), FILTER_VALIDATE_EMAIL);

    return [
        'email_enabled' => !isset($row['email_enabled']) || (int)$row['email_enabled'] === 1,
        'teams_enabled' => !isset($row['teams_enabled']) || (int)$row['teams_enabled'] === 1,
        'has_email' => $hasEmail,
        'email_available' => $hasEmail && NotificationService::emailAvailable(),
        'teams_available' => $hasEmail && NotificationService::teamsAvailable(),
    ];
}

function notificationFlag($value) {
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
}
