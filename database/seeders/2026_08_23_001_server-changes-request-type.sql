-- =============================================================================
-- Date:     2026-08-23
-- Purpose:  Give whole-SERVER work its own Request Type, instead of smuggling it
--           through "Add Hardware" and its "Any server" option.
--
--             * Server Changes -- start a brand-new server, or change an
--                                existing one's name, location, rack position,
--                                notes or status.
--
--           The three hardware types stay about hardware. This one is about the
--           machine, and grants no component permission at all -- which is now
--           load-bearing: the API refuses server-add-component /
--           -remove-component / -replace-component to a request-granted holder
--           who lacks the matching {type}.create / {type}.edit permission. So a
--           Server Changes grant cannot touch parts, and a hardware grant cannot
--           roam the build. The two halves are enforced, not just described.
--
-- Tables:   pipeline_templates (1 row INSERT, 1 row UPDATE)
--           pipeline_stages    (1 row INSERT)
-- Feature:  Temporary approval-gated access (Requests module), phase 7
--
-- REQUIRES: 2026_08_22_005 (the three hardware types) must be applied FIRST --
--           this file updates the Add Hardware description it wrote.
--
-- WHY THE CEILING IS server.create + .edit + .transition + .view
--   server.create   gates server-create-start, i.e. a brand-new build. It can
--                   only be used UNSCOPED, because there is no configuration to
--                   name yet -- which is why such a request must answer the
--                   server question with "Any server". The create form already
--                   says so on the access panel.
--   server.edit     gates server-update-config, the one path that writes
--                   server_name / description / location / rack_position /
--                   notes / configuration_status. Scoped to the named server.
--   server.transition gates server-transition-status.
--   server.view     so the holder can open the configuration they are changing.
--
--   Rack VIEW placement (rack-assign-server) is deliberately NOT here: rack.* is
--   absent from TemporaryAccessManager::GRANTABLE_PERMISSIONS and api.php gates
--   the whole rack module to admin/super_admin by ROLE before any permission
--   check. Granting it would need both of those relaxed. The configuration's own
--   `location` and `rack_position` fields are covered by server.edit above.
--
-- NOTES   - is_system = 0: an admin may rename or archive this type later.
--         - Step is owned by the `admin` ROLE, resolved by name.
--         - Idempotent: re-running updates both rows in place
--           (uq_pipeline_templates_name, uq_pipeline_stages_position).
--         - Rollback: rollback/2026_08_23_001_server-changes-request-type_rollback.sql
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state -- the live access types and how many requests came from each.
-- ---------------------------------------------------------------------------
SELECT
    t.id        AS template_id,
    t.name      AS request_type,
    t.is_active,
    (SELECT COUNT(*) FROM `tickets` k
      WHERE k.`pipeline_template_id` = t.id)                       AS requests_total,
    (SELECT COUNT(*) FROM `tickets` k
      WHERE k.`pipeline_template_id` = t.id
        AND k.`status` IN ('draft','in_progress'))                 AS requests_still_open
FROM `pipeline_templates` t
WHERE t.`is_active` = 1
ORDER BY t.`name`;

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. Server Changes
-- ---------------------------------------------------------------------------
INSERT INTO `pipeline_templates`
    (`name`, `description`, `is_active`, `is_system`, `created_by`, `created_at`, `updated_at`)
VALUES (
    'Server Changes',
    'Ask for temporary permission to change the server itself, rather than the hardware inside it: start a brand-new server, or change an existing one''s name, description, location, rack position, notes or status. Pick which server -- or "Any server", which is what starting a new build needs, because there is no configuration to name yet. This access cannot add, remove or swap parts; use Add, Edit or Remove Hardware for that. An admin approves, the access lasts 24 hours and then expires by itself. Anything you change during the window stays.',
    1, 0, NULL, NOW(), NOW()
)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `is_active`   = 1,
    `is_system`   = 0,
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
    'Approve only what the request actually lists. "Any server" means every configuration in the system, including ones built later -- it is what a brand-new build needs, so expect it on a request to CREATE a server and question it on a request to change one that already exists. This access cannot touch hardware: adding, removing or swapping a part needs the matching component permission, and this request type grants none. Access lasts 24 hours and then expires on its own. You cannot approve your own request.',
    'grant_temporary_permission',
    '{"permissions":["server.create","server.edit","server.transition","server.view"],"duration_hours":24}',
    NOW(),
    NOW()
FROM `pipeline_templates` t
WHERE t.`name` = 'Server Changes'
ON DUPLICATE KEY UPDATE
    `name`                     = VALUES(`name`),
    `default_assignee_user_id` = VALUES(`default_assignee_user_id`),
    `default_assignee_role_id` = VALUES(`default_assignee_role_id`),
    `instructions`             = VALUES(`instructions`),
    `effect_type`              = VALUES(`effect_type`),
    `effect_config`            = VALUES(`effect_config`),
    `updated_at`               = NOW();

-- ---------------------------------------------------------------------------
-- 2. Add Hardware stops advertising itself as the way to start a new build --
--    that job moved above. Its ceiling is UNCHANGED: server.create is still
--    what gates server-add-component, so the type still needs it.
-- ---------------------------------------------------------------------------
UPDATE `pipeline_templates`
   SET `description` = 'Ask for temporary permission to add hardware. Choose where it goes: into a server configuration (pick which server), or into the component inventory. Only the hardware you tick can be added -- nothing else in that server changes. To start a brand-new server, or to change a server''s name, location or status, use "Server Changes" instead. An admin approves, the access lasts 24 hours and then expires by itself. Anything you add during the window stays.',
       `updated_at`  = NOW()
 WHERE `name` = 'Add Hardware';

COMMIT;

-- ---------------------------------------------------------------------------
-- Verification -- four live access types owned by the admin role, ceilings of
-- 13 (Add) / 14 (Edit) / 2 (Remove) / 4 (Server Changes), all 24 hours.
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
WHERE t.`name` IN ('Add Hardware', 'Edit Hardware', 'Remove Hardware', 'Server Changes')
ORDER BY t.`name`;

-- The new ceiling names no component type, which is what stops it touching
-- parts. This should return exactly the four server permissions.
SELECT
    JSON_EXTRACT(s.effect_config, '$.permissions') AS server_changes_ceiling
FROM `pipeline_templates` t
JOIN `pipeline_stages` s ON s.pipeline_template_id = t.id
WHERE t.`name` = 'Server Changes';
