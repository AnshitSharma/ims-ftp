-- =============================================================================
-- ROLLBACK for 2026_08_23_005_retire-temporary-access-grants.sql
-- Date:     2026-08-23
-- Purpose:  Restore the archived temporary/scoped permission grants.
--
-- Tables:   user_permissions (rows restored from user_permissions_retired_grants)
-- Feature:  Requests as automation (Phase 8)
--
-- ======================== DO NOT RUN THIS CASUALLY ===========================
--
-- ONLY meaningful once the Phase 8 CODE has also been reverted -- specifically
-- once TemporaryAccessManager::activeGrantClause() is back in BOTH permission
-- resolvers (BaseFunctions::loadUserPermissionData and ACL::loadUserPermissions).
--
-- Restoring these rows into a codebase WITHOUT that clause re-creates, by hand,
-- exactly the escalation the forward seeder existed to prevent: expired grants
-- resolve as permanent, revoked grants resolve as live, and grants scoped to one
-- server configuration resolve as global. Check the clause is present before
-- running this, not after.
--
-- Restoring grants also does not restore the request types that issued them.
-- That needs rollback/2026_08_23_004_action-request-types_rollback.sql as well.
--
-- Note: the requests cancelled by section 3 of the forward seeder are NOT
-- reopened. Their steps were marked skipped and the tickets cancelled, and
-- re-deriving which were active is not reliable after the fact. Raise fresh
-- requests instead -- the history of the cancelled ones is intact in
-- ticket_history.
-- =============================================================================

-- What is about to be restored, and to whom. Read this first.
SELECT r.`user_id`, u.`username`, r.`permission_name`,
       r.`scope_type`, r.`scope_id`, r.`expires_at`, r.`revoked_at`,
       CASE WHEN r.`revoked_at` IS NOT NULL          THEN 'was revoked'
            WHEN r.`expires_at` < NOW()              THEN 'ALREADY EXPIRED'
            ELSE 'still within its window'
       END AS state
  FROM `user_permissions_retired_grants` r
  LEFT JOIN `users` u ON u.`id` = r.`user_id`
 ORDER BY r.`user_id`, r.`permission_name`;

START TRANSACTION;

INSERT IGNORE INTO `user_permissions`
    (`id`,`user_id`,`permission_id`,`scope_type`,`scope_id`,
     `expires_at`,`revoked_at`,`granted_by`,`source_ticket_id`,`created_at`)
SELECT `id`,`user_id`,`permission_id`,`scope_type`,`scope_id`,
       `expires_at`,`revoked_at`,`granted_by`,`source_ticket_id`,`created_at`
  FROM `user_permissions_retired_grants`;

COMMIT;

-- =============================================================================
-- Verification
-- =============================================================================

-- Every archived row is back. The two counts MUST match.
SELECT
  (SELECT COUNT(*) FROM `user_permissions_retired_grants`) AS archived,
  (SELECT COUNT(*) FROM `user_permissions`
    WHERE `expires_at` IS NOT NULL OR `revoked_at` IS NOT NULL
       OR `source_ticket_id` IS NOT NULL
       OR `scope_type` <> '' OR `scope_id` <> '') AS restored;

-- Anything restored that is already past its expiry is inert ONLY while the
-- grant clause is in place. Informational, and a reason not to leave it long.
SELECT COUNT(*) AS restored_but_already_expired
  FROM `user_permissions`
 WHERE `expires_at` IS NOT NULL AND `expires_at` < NOW();
