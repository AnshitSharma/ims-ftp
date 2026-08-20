-- =============================================================================
-- Date:     2026-08-21
-- Purpose:  Create the built-in "Temporary Access Request" type -- ONE request
--           type that covers every kind of temporary access, instead of a
--           separate type per bundle.
--
--           The requester ticks what they need (tickets.requested_access) and
--           may name one server (tickets.target_server_uuid). An admin approves.
--           The grant is the INTERSECTION of:
--               what was requested
--             * this step's effect_config below  (the ceiling for this type)
--             * TemporaryAccessManager::GRANTABLE_PERMISSIONS (the hard ceiling)
--           and lasts 24 hours.
--
--           When a target server is named, the SERVER permissions are scoped to
--           that one configuration -- the requester can change that build and no
--           other. Inventory permissions have no per-server meaning and are
--           granted normally.
--
-- Tables:   pipeline_templates (1 row), pipeline_stages (1 row)
-- Feature:  Temporary approval-gated access (Requests module), phase 2
--
-- REQUIRES: 2026_08_20_002 (stage effects) and 2026_08_21_002 (requested_access)
--           must be applied FIRST. Without 002 this fails loudly at the INSERT.
--           Without 2026_08_21_002 it would still work, but every approval would
--           grant the WHOLE ceiling below, because there would be nowhere to
--           record what was actually asked for -- so apply both.
--
-- Notes:    - effect_config here is a CEILING, not a grant. It is deliberately
--             the complete grantable set: narrowing happens per request. Trim it
--             in a NEW seeder if you want this type to be able to grant less.
--           - NO *.delete anywhere in the list, by decision: deletion is
--             irreversible and inventory rows are referenced by server
--             configurations, so a 24-hour grant could do damage that outlives
--             it. The code whitelist refuses deletes even if this JSON is edited.
--           - is_system = 1, so the type cannot be renamed, archived or deleted
--             through the API. Its steps stay editable.
--           - The step is owned by the `admin` ROLE, resolved by name. To let
--             managers approve, change 'admin' below AND grant manager the
--             pipeline permissions AND add manager to the gate in api/api.php.
--           - The earlier "Temporary Server Creation Access" type (2026_08_20_004)
--             is intentionally LEFT IN PLACE as a one-click shortcut for the
--             common case. It has no requested_access, so it grants its whole
--             (smaller) ceiling -- unchanged behaviour.
--           - Idempotent: re-running updates the type and step in place.
--           - Rollback: rollback/2026_08_21_003_flexible-access-request-type_rollback.sql
-- =============================================================================

START TRANSACTION;

INSERT INTO `pipeline_templates`
    (`name`, `description`, `is_active`, `is_system`, `created_by`, `created_at`, `updated_at`)
VALUES (
    'Temporary Access Request',
    'Ask for permission you do not normally have -- building or changing a server, or adding and correcting inventory. Choose what you need and, if it is about one particular server, name it. An admin approves and the access lasts 24 hours, then expires by itself. Anything you create or change during the window stays.',
    1, 1, NULL, NOW(), NOW()
)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `is_active`   = 1,
    `is_system`   = 1,
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
    'Check what was requested before approving -- the grant is exactly the access listed on the request. If a server is named, the server permissions apply to that configuration only. Access lasts 24 hours and then expires on its own. You cannot approve your own request.',
    'grant_temporary_permission',
    '{"permissions":["server.create","server.view","server.edit","server.replace","server.transition","cpu.create","cpu.edit","ram.create","ram.edit","storage.create","storage.edit","motherboard.create","motherboard.edit","nic.create","nic.edit","caddy.create","caddy.edit","chassis.create","chassis.edit","pciecard.create","pciecard.edit","risercard.create","risercard.edit","hbacard.create","hbacard.edit","sfp.create","sfp.edit"],"duration_hours":24}',
    NOW(),
    NOW()
FROM `pipeline_templates` t
WHERE t.`name` = 'Temporary Access Request'
ON DUPLICATE KEY UPDATE
    `name`                     = VALUES(`name`),
    `default_assignee_user_id` = VALUES(`default_assignee_user_id`),
    `default_assignee_role_id` = VALUES(`default_assignee_role_id`),
    `instructions`             = VALUES(`instructions`),
    `effect_type`              = VALUES(`effect_type`),
    `effect_config`            = VALUES(`effect_config`),
    `updated_at`               = NOW();

COMMIT;

-- ---------------------------------------------------------------------------
-- Verification -- one row, owned by the admin role, ceiling of 27 permissions.
-- ---------------------------------------------------------------------------
SELECT t.id AS template_id, t.name AS request_type, t.is_system,
       s.name AS step, r.name AS owner_role, s.effect_type, s.effect_config
FROM `pipeline_templates` t
JOIN `pipeline_stages` s ON s.pipeline_template_id = t.id
LEFT JOIN `roles` r ON r.id = s.default_assignee_role_id
WHERE t.`name` = 'Temporary Access Request';
