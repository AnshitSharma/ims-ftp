-- =============================================================================
-- Date:     2026-08-22
-- Purpose:  Split the combined "Temporary Access Request" into TWO Request
--           Types, because server access and inventory access are different
--           asks with different shapes:
--
--             * Server Access Request     -- always about a configuration, so it
--                                            asks WHICH server (or "Any server")
--             * Inventory Access Request  -- never about a configuration, so the
--                                            question does not apply at all
--
--           One combined type meant every requester scrolled a 27-permission
--           list to find the five they wanted, and half of them were asked
--           "which server?" for an ask that has no server.
--
-- Tables:   pipeline_templates (2 rows INSERT, 1 row UPDATE)
--           pipeline_stages    (2 rows INSERT)
-- Feature:  Temporary approval-gated access (Requests module), phase 5
--
-- REQUIRES: 2026_08_20_002 (stage effects) and 2026_08_21_002 (requested_access)
--           must be applied FIRST. Without 002 this fails loudly at the INSERT.
--
-- NO PERMISSION IS GAINED OR LOST. The two new ceilings are exactly the old one
-- partitioned: 5 server + 22 inventory = the same 27. The hard whitelist in
-- TemporaryAccessManager::GRANTABLE_PERMISSIONS still applies on top, and there
-- is still no *.delete anywhere -- deletion is irreversible and inventory rows
-- are referenced by server configurations, so a 24-hour grant must not do damage
-- that outlives it.
--
-- WHAT HAPPENS TO THE COMBINED TYPE
--   - ARCHIVED (is_active = 0), not deleted: `tickets` rows point at it via
--     pipeline_template_id and deleting would orphan that history. Its step is
--     left intact for the same reason.
--   - is_system is cleared too, so the Archive/Restore toggle on the Request
--     Types page works both ways (PipelineTemplateManager::updateTemplate()
--     refuses to archive an is_system type -- leaving the flag set would create
--     a type the UI can restore but never re-archive). Same reasoning as
--     2026_08_22_002.
--   - Requests already raised from it are UNAFFECTED: is_active is only read
--     when a request is CREATED, so in-flight ones still advance and their
--     approval step still grants access. Grants already issued still lapse on
--     their own 24h schedule; nothing is revoked here.
--
-- Notes:    - The two new types are is_system = 0 on purpose. Nothing in the PHP
--             or the JS refers to a request type by name, so admins can rename,
--             re-word or archive these from the Request Types page without
--             breaking anything. Deletion is still refused by
--             PipelineTemplateManager::deleteTemplate() for any type a request
--             has ever been raised from.
--           - Someone who needs both at once raises two requests. That is the
--             honest consequence of the split, and each is approved on its own
--             merits.
--           - effect_config is a CEILING, not a grant. The requester ticks what
--             they need (tickets.requested_access) and the grant is the
--             intersection of that, this ceiling, and the code whitelist.
--           - Steps are owned by the `admin` ROLE, resolved by name.
--           - 2026_08_22_003 (copy re-word) only re-words the type this seeder
--             archives, so it is now moot. Running it first is harmless;
--             skipping it is fine.
--           - Idempotent: re-running updates all three rows in place.
--           - Rollback: rollback/2026_08_22_004_split-access-request-types_rollback.sql
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state -- how many requests exist on the combined type, and how many
--    are still open. Open ones keep working; this is for your awareness only.
-- ---------------------------------------------------------------------------
SELECT
    t.id        AS template_id,
    t.name      AS request_type,
    t.is_active AS is_active_before,
    t.is_system AS is_system_before,
    (SELECT COUNT(*) FROM `tickets` k
      WHERE k.`pipeline_template_id` = t.id)                       AS requests_total,
    (SELECT COUNT(*) FROM `tickets` k
      WHERE k.`pipeline_template_id` = t.id
        AND k.`status` IN ('draft','in_progress'))                 AS requests_still_open
FROM `pipeline_templates` t
WHERE t.`name` = 'Temporary Access Request';

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. Server Access Request -- the five server permissions.
-- ---------------------------------------------------------------------------
INSERT INTO `pipeline_templates`
    (`name`, `description`, `is_active`, `is_system`, `created_by`, `created_at`, `updated_at`)
VALUES (
    'Server Access Request',
    'Ask for temporary permission to build or change a server configuration. Pick the server it is for, or choose "Any server" if you are starting a new build. An admin approves, the access lasts 24 hours and then expires by itself. Anything you build or change during the window stays.',
    1, 0, NULL, NOW(), NOW()
)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `is_active`   = 1,
    `updated_at`  = NOW();

INSERT INTO `pipeline_stages`
    (`pipeline_template_id`, `name`, `position`,
     `default_assignee_user_id`, `default_assignee_role_id`,
     `instructions`, `effect_type`, `effect_config`, `created_at`, `updated_at`)
SELECT
    t.id,
    'Admin Approval',
    1,
    NULL,
    (SELECT r.id FROM `roles` r WHERE r.`name` = 'admin'),
    'Approve only the server permissions listed on the request. If a server is named, they apply to that one configuration; "Any server" means every configuration in the system, including ones built later, so check that is really what is needed. Access lasts 24 hours and then expires on its own. You cannot approve your own request.',
    'grant_temporary_permission',
    '{"permissions":["server.create","server.view","server.edit","server.replace","server.transition"],"duration_hours":24}',
    NOW(),
    NOW()
FROM `pipeline_templates` t
WHERE t.`name` = 'Server Access Request'
ON DUPLICATE KEY UPDATE
    `name`                     = VALUES(`name`),
    `default_assignee_user_id` = VALUES(`default_assignee_user_id`),
    `default_assignee_role_id` = VALUES(`default_assignee_role_id`),
    `instructions`             = VALUES(`instructions`),
    `effect_type`              = VALUES(`effect_type`),
    `effect_config`            = VALUES(`effect_config`),
    `updated_at`               = NOW();

-- ---------------------------------------------------------------------------
-- 2. Inventory Access Request -- create/edit on all 11 component types.
--    No server question: inventory is not owned by a configuration.
-- ---------------------------------------------------------------------------
INSERT INTO `pipeline_templates`
    (`name`, `description`, `is_active`, `is_system`, `created_by`, `created_at`, `updated_at`)
VALUES (
    'Inventory Access Request',
    'Ask for temporary permission to add or correct component inventory -- CPUs, RAM, storage, cards and the rest. Inventory is not owned by a server, so this access always applies system-wide. An admin approves, the access lasts 24 hours and then expires by itself. Anything you add or correct during the window stays.',
    1, 0, NULL, NOW(), NOW()
)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `is_active`   = 1,
    `updated_at`  = NOW();

INSERT INTO `pipeline_stages`
    (`pipeline_template_id`, `name`, `position`,
     `default_assignee_user_id`, `default_assignee_role_id`,
     `instructions`, `effect_type`, `effect_config`, `created_at`, `updated_at`)
SELECT
    t.id,
    'Admin Approval',
    1,
    NULL,
    (SELECT r.id FROM `roles` r WHERE r.`name` = 'admin'),
    'Approve only the inventory permissions listed on the request. Inventory access is always system-wide -- there is no per-server version of it. Nothing here can grant a delete permission, by design. Access lasts 24 hours and then expires on its own. You cannot approve your own request.',
    'grant_temporary_permission',
    '{"permissions":["cpu.create","cpu.edit","ram.create","ram.edit","storage.create","storage.edit","motherboard.create","motherboard.edit","nic.create","nic.edit","caddy.create","caddy.edit","chassis.create","chassis.edit","pciecard.create","pciecard.edit","risercard.create","risercard.edit","hbacard.create","hbacard.edit","sfp.create","sfp.edit"],"duration_hours":24}',
    NOW(),
    NOW()
FROM `pipeline_templates` t
WHERE t.`name` = 'Inventory Access Request'
ON DUPLICATE KEY UPDATE
    `name`                     = VALUES(`name`),
    `default_assignee_user_id` = VALUES(`default_assignee_user_id`),
    `default_assignee_role_id` = VALUES(`default_assignee_role_id`),
    `instructions`             = VALUES(`instructions`),
    `effect_type`              = VALUES(`effect_type`),
    `effect_config`            = VALUES(`effect_config`),
    `updated_at`               = NOW();

-- ---------------------------------------------------------------------------
-- 3. Retire the combined type. Archived, never deleted -- see the header.
-- ---------------------------------------------------------------------------
UPDATE `pipeline_templates`
   SET `is_active`  = 0,
       `is_system`  = 0,
       `updated_at` = NOW()
 WHERE `name` = 'Temporary Access Request';

COMMIT;

-- ---------------------------------------------------------------------------
-- Verification -- two live types owned by the admin role with 5 and 22
-- permissions, and the combined one archived.
-- ---------------------------------------------------------------------------
SELECT
    t.id          AS template_id,
    t.name        AS request_type,
    t.is_active,
    t.is_system,
    s.name        AS step,
    r.name        AS owner_role,
    s.effect_type,
    JSON_LENGTH(JSON_EXTRACT(s.effect_config, '$.permissions')) AS ceiling_size,
    JSON_EXTRACT(s.effect_config, '$.duration_hours')           AS hours
FROM `pipeline_templates` t
LEFT JOIN `pipeline_stages` s ON s.pipeline_template_id = t.id
LEFT JOIN `roles` r ON r.id = s.default_assignee_role_id
WHERE t.`name` IN ('Server Access Request', 'Inventory Access Request', 'Temporary Access Request')
ORDER BY t.`is_active` DESC, t.`name`;
