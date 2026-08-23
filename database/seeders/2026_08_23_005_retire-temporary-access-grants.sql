-- =============================================================================
-- Date:     2026-08-23
-- Purpose:  Remove every live temporary/scoped permission grant, ahead of the
--           code that reads them being deleted.
--
-- Tables:   user_permissions                 (rows archived, then DELETED)
--           user_permissions_retired_grants  (new archive table)
--           tickets / ticket_stage_progress / ticket_history
--                                            (in-flight access requests cancelled)
-- Feature:  Requests as automation (Phase 8)
--
-- ============================ READ THIS FIRST ================================
--
-- RUN ORDER IS NOT THE USUAL ONE. This seeder must be applied BEFORE the code
-- change that removes the grant filter from the two permission resolvers, not
-- after it.
--
-- Both resolvers currently pass user_permissions through
-- TemporaryAccessManager::activeGrantClause() -- BaseFunctions.php:277 and
-- ACL.php:410. That clause is the ONLY thing excluding rows that have expired,
-- been revoked, or are scoped to a single server. Delete the clause and what
-- remains is a plain `WHERE user_id = ?`, so:
--
--     * every EXPIRED 24-hour grant becomes a PERMANENT permission,
--     * every REVOKED grant becomes live again,
--     * every grant SCOPED to one configuration becomes GLOBAL.
--
-- Nothing in the API would report this. It is silent, and it is a privilege
-- escalation.
--
-- WHY THESE ROWS ARE DELETED AND NOT REVOKED
--   Marking them revoked would not help: the revoked_at filter disappears with
--   the same clause, so a revoked row would resolve exactly like a live one.
--   Revoking here would be strictly worse than doing nothing, because it would
--   look like the problem had been dealt with. The rows are archived into
--   user_permissions_retired_grants first, so this remains reversible.
--
-- WHAT IS NOT TOUCHED
--   * PERMANENT per-user grants (acl-assign_permission / assignPermissionToUser):
--     expires_at NULL, revoked_at NULL, scope_type '', scope_id '',
--     source_ticket_id NULL. The predicate below matches none of them.
--   * role_permissions, user_roles, permissions. Nobody's ROLE-based access
--     changes, which is where essentially all real access comes from.
--   * The user_permissions columns themselves. scope_type / scope_id /
--     expires_at / revoked_at / granted_by / source_ticket_id are left INERT
--     rather than dropped: dropping is irreversible on a production database
--     with no rehearsal environment, and scope_type / scope_id are part of the
--     UNIQUE key added by 2026_08_21_001, so removing them means rebuilding that
--     index -- which would fail outright while duplicate (user_id, permission_id)
--     pairs still exist across scopes. Revisit as a standalone change later, if
--     at all.
--
-- Idempotent: INSERT IGNORE into the archive, and a DELETE that matches nothing
--             on a second run.
-- Rollback:   rollback/2026_08_23_005_retire-temporary-access-grants_rollback.sql
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state. KEEP THIS OUTPUT -- it is the only record of what was live.
-- ---------------------------------------------------------------------------
SELECT up.`id`, up.`user_id`, u.`username`, p.`name` AS permission,
       up.`scope_type`, up.`scope_id`, up.`expires_at`, up.`revoked_at`,
       up.`granted_by`, up.`source_ticket_id`, up.`created_at`,
       CASE WHEN up.`expires_at` IS NULL AND up.`revoked_at` IS NULL
             AND up.`scope_type` = '' AND up.`scope_id` = ''
             AND up.`source_ticket_id` IS NULL
            THEN 'PERMANENT -- kept'
            ELSE 'grant -- archived + deleted'
       END AS disposition
  FROM `user_permissions` up
  LEFT JOIN `permissions` p ON p.`id` = up.`permission_id`
  LEFT JOIN `users`       u ON u.`id` = up.`user_id`
 ORDER BY disposition, up.`user_id`, p.`name`;

-- Whoever runs this is recorded as the actor on the cancellation history rows.
SET @actor := (
    SELECT ur.`user_id`
      FROM `user_roles` ur
      JOIN `roles` r ON r.`id` = ur.`role_id`
     WHERE r.`name` = 'super_admin'
     ORDER BY ur.`user_id`
     LIMIT 1
);
SELECT @actor AS acting_user_id;   -- must NOT be NULL

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. Archive. The permission NAME is snapshotted too, so the record stays
--    readable even if a permission id is ever recycled.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_permissions_retired_grants` (
  `id`               int(11)          NOT NULL,
  `user_id`          int(11)          NOT NULL,
  `permission_id`    int(11)          NOT NULL,
  `permission_name`  varchar(100)              DEFAULT NULL,
  `scope_type`       varchar(32)      NOT NULL DEFAULT '',
  `scope_id`         varchar(64)      NOT NULL DEFAULT '',
  `expires_at`       datetime                  DEFAULT NULL,
  `revoked_at`       datetime                  DEFAULT NULL,
  `granted_by`       int(6)  UNSIGNED          DEFAULT NULL,
  `source_ticket_id` int(10) UNSIGNED          DEFAULT NULL,
  `created_at`       timestamp        NOT NULL DEFAULT current_timestamp(),
  `retired_at`       datetime         NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_upr_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Temporary/scoped grants removed by seeder 2026_08_23_005';

INSERT IGNORE INTO `user_permissions_retired_grants`
    (`id`,`user_id`,`permission_id`,`permission_name`,`scope_type`,`scope_id`,
     `expires_at`,`revoked_at`,`granted_by`,`source_ticket_id`,`created_at`,`retired_at`)
SELECT up.`id`, up.`user_id`, up.`permission_id`, p.`name`,
       up.`scope_type`, up.`scope_id`, up.`expires_at`, up.`revoked_at`,
       up.`granted_by`, up.`source_ticket_id`, up.`created_at`, NOW()
  FROM `user_permissions` up
  LEFT JOIN `permissions` p ON p.`id` = up.`permission_id`
 WHERE up.`expires_at`       IS NOT NULL
    OR up.`revoked_at`       IS NOT NULL
    OR up.`source_ticket_id` IS NOT NULL
    OR up.`scope_type` <> ''
    OR up.`scope_id`   <> '';

-- ---------------------------------------------------------------------------
-- 2. Remove them. Identical predicate to section 1 -- nothing is deleted that
--    was not archived one statement earlier, inside this transaction.
-- ---------------------------------------------------------------------------
DELETE FROM `user_permissions`
 WHERE `expires_at`       IS NOT NULL
    OR `revoked_at`       IS NOT NULL
    OR `source_ticket_id` IS NOT NULL
    OR `scope_type` <> ''
    OR `scope_id`   <> '';

-- ---------------------------------------------------------------------------
-- 3. Cancel any request still open on a retired access type.
--
--    Without this, an admin opens one, approves it, and NOTHING HAPPENS --
--    silently, because the effect it carries no longer exists. A cancelled
--    request carrying a reason is honest; a silent no-op approval is not.
--    Mirrors PipelineManager::cancelPipeline() exactly.
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `_retired_access_requests`;
CREATE TEMPORARY TABLE `_retired_access_requests` (`ticket_id` INT UNSIGNED NOT NULL);

INSERT INTO `_retired_access_requests` (`ticket_id`)
SELECT k.`id`
  FROM `tickets` k
  JOIN `pipeline_templates` t ON t.`id` = k.`pipeline_template_id`
 WHERE t.`name` IN ('Add Hardware','Edit Hardware','Remove Hardware','Server Changes',
                    'Temporary Access Request','Server Access Request','Inventory Access Request',
                    'Temporary Server Creation Access')
   AND k.`status` IN ('draft','in_progress');

UPDATE `ticket_stage_progress`
   SET `status` = 'skipped', `updated_at` = NOW()
 WHERE `ticket_id` IN (SELECT `ticket_id` FROM `_retired_access_requests`)
   AND `status` IN ('active','pending');

UPDATE `tickets`
   SET `status`                    = 'cancelled',
       `current_stage_progress_id` = NULL,
       `rejection_reason`          = 'Temporary access requests have been retired. Approval no longer grants permissions - raise the work itself instead, and it will be performed for you.',
       `updated_at`                = NOW()
 WHERE `id` IN (SELECT `ticket_id` FROM `_retired_access_requests`);

INSERT INTO `ticket_history` (`ticket_id`,`action`,`old_value`,`new_value`,`changed_by`,`notes`,`created_at`)
SELECT r.`ticket_id`, 'pipeline_cancelled', 'in_progress', 'cancelled', @actor,
       'Cancelled by seeder 2026_08_23_005 - the temporary-access grant model was retired',
       NOW()
  FROM `_retired_access_requests` r
 WHERE @actor IS NOT NULL;

DROP TEMPORARY TABLE IF EXISTS `_retired_access_requests`;

COMMIT;

-- =============================================================================
-- Verification -- run all of these before touching the resolver code.
-- =============================================================================

-- 1. Zero grant-shaped rows remain. MUST return 0.
--    Do not proceed to the code change until this reads 0.
SELECT COUNT(*) AS grant_rows_remaining
  FROM `user_permissions`
 WHERE `expires_at`       IS NOT NULL
    OR `revoked_at`       IS NOT NULL
    OR `source_ticket_id` IS NOT NULL
    OR `scope_type` <> ''
    OR `scope_id`   <> '';

-- 2. Everything that survives is a permanent, unscoped, direct grant.
SELECT up.`id`, up.`user_id`, u.`username`, p.`name` AS permission
  FROM `user_permissions` up
  LEFT JOIN `permissions` p ON p.`id` = up.`permission_id`
  LEFT JOIN `users`       u ON u.`id` = up.`user_id`
 ORDER BY up.`user_id`, p.`name`;

-- 3. The archive holds exactly what was removed.
SELECT COUNT(*) AS archived_rows,
       SUM(`scope_type` <> '') AS were_scoped,
       MIN(`created_at`) AS oldest,
       MAX(`expires_at`) AS latest_expiry
  FROM `user_permissions_retired_grants`;

-- 4. No access request is still open. MUST return 0.
SELECT COUNT(*) AS open_access_requests
  FROM `tickets` k
  JOIN `pipeline_templates` t ON t.`id` = k.`pipeline_template_id`
 WHERE t.`name` IN ('Add Hardware','Edit Hardware','Remove Hardware','Server Changes',
                    'Temporary Access Request','Server Access Request','Inventory Access Request',
                    'Temporary Server Creation Access')
   AND k.`status` IN ('draft','in_progress');
