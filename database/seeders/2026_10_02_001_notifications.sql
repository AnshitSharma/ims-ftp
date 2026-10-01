-- =============================================================================
-- 2026_10_02_001_notifications.sql
--
-- Date:     2026-10-02
-- Purpose:  Notifications -- the in-app bell, plus an outbox that delivers the
--           same item by email (Microsoft Graph, or mail() as a fallback) and to
--           Microsoft Teams (a Power Automate Workflows flow).
-- Tables:   notifications (NEW), notification_deliveries (NEW),
--           user_notification_prefs (NEW), permissions, role_permissions
-- Feature:  Notifications -- tasks/notifications.md
--
-- Code reaches production before this runs. Until it does, NotificationService
-- sees no tables and does nothing, and the notification-* actions answer 503.
-- Re-running is a no-op.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. notifications -- one row per recipient per event. This IS the in-app item;
--    link is relative to the UI root (pages/dashboard/requests.html?request=42).
--    No FK to users on purpose: a deleted user's rows are harmless and an FK
--    would make the seeder depend on users.id's exact column type.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'Recipient',
  `event` varchar(50) NOT NULL COMMENT 'stage_activated, stage_reassigned, pipeline_completed, ...',
  `ticket_id` int(11) DEFAULT NULL COMMENT 'The Request it is about, when there is one',
  `actor_user_id` int(11) DEFAULT NULL COMMENT 'Who caused it',
  `title` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `link` varchar(500) DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user_unread` (`user_id`, `read_at`),
  KEY `idx_notifications_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- 2. notification_deliveries -- the outbox. One row per external channel per
--    notification, written in the same transaction as the event, sent after the
--    API has answered. 'pending' rows are retried with backoff until sent or
--    attempts run out ('failed'); 'sending' is the claim that stops a double send.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notification_deliveries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `notification_id` int(11) NOT NULL,
  `channel` enum('email','teams') NOT NULL,
  `recipient` varchar(255) NOT NULL COMMENT 'Email address / Teams UPN at the time of the event',
  `status` enum('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `next_attempt_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_error` varchar(500) DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_deliveries_due` (`status`, `next_attempt_at`),
  KEY `idx_deliveries_notification` (`notification_id`),
  CONSTRAINT `fk_deliveries_notification` FOREIGN KEY (`notification_id`)
    REFERENCES `notifications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- 3. user_notification_prefs -- opt-outs. No row means both channels on; in-app
--    is always on and has no switch.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_notification_prefs` (
  `user_id` int(11) NOT NULL,
  `email_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `teams_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- 4. ACL. notification.view covers reading and managing your OWN notifications
--    and preferences -- every action is scoped to the caller -- so every existing
--    role gets it. A role created later starts without it (the bell then stays
--    hidden) until it is ticked in the role editor.
--    notification.manage is the admin test send; admin/super_admin bypass
--    hasPermission() anyway, the explicit rows just make it visible.
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`) VALUES
  ('notification.view',   'View Notifications',   'Receive and read your own notifications and set your email / Teams preferences', 'notifications', 1),
  ('notification.manage', 'Manage Notifications', 'Send test notifications and see whether email and Teams delivery are configured', 'notifications', 0);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`, `granted`)
SELECT r.`id`, p.`id`, 1
  FROM `roles` r
  JOIN `permissions` p ON p.`name` = 'notification.view';

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`, `granted`)
SELECT r.`id`, p.`id`, 1
  FROM `roles` r
  JOIN `permissions` p ON p.`name` = 'notification.manage'
 WHERE r.`name` IN ('admin', 'super_admin');

-- =============================================================================
-- Verification (run after the seeder):
--
--   SHOW TABLES LIKE 'notification%';                                   -- 2 rows
--   SHOW TABLES LIKE 'user_notification_prefs';                         -- 1 row
--   SELECT name FROM permissions WHERE name LIKE 'notification.%';      -- 2 rows
--   SELECT COUNT(*) FROM roles;                                         -- same as the next line
--   SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.name = 'notification.view';
-- =============================================================================
