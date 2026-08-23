-- =============================================================================
-- Date:     2026-08-22
-- Purpose:  Re-shape the access Request Types around the ACTION being asked for
--           instead of the place it lands, because that is how people describe
--           the work: "I need to add hardware", not "I need inventory access".
--
--             * Add Hardware     -- inside a server, or into the inventory
--             * Edit Hardware    -- inside a server, or in the inventory
--             * Remove Hardware  -- inside a server ONLY (see the note below)
--
--           The create form asks WHERE first (server / inventory) and only then
--           shows the permissions that reach that place -- and the "which
--           server?" question appears only on the server branch, because an
--           inventory ask has no server to name.
--
-- Tables:   pipeline_templates (3 rows INSERT, up to 3 rows UPDATE)
--           pipeline_stages    (3 rows INSERT)
-- Feature:  Temporary approval-gated access (Requests module), phase 6
--
-- REQUIRES: 2026_08_20_002 (stage effects) and 2026_08_21_002 (requested_access)
--           must be applied FIRST. Without 002 this fails loudly at the INSERT.
--
-- SUPERSEDES 2026_08_21_003 / 2026_08_22_002 / _003 / _004. This file archives
-- every earlier access type by NAME, so it is correct whether or not those ran.
-- If you skipped 004, skip it for good -- run this instead.
--
-- WHY effect_config STAYS ONE FLAT LIST
--   Each type's ceiling is the UNION of its two branches. The grant is already
--   intersection(ceiling, tickets.requested_access, GRANTABLE_PERMISSIONS), so
--   the branch the requester picks simply narrows requested_access and the
--   existing engine does the rest -- no backend change was needed for any of
--   this. Being straight about it: "where?" is a USABILITY device, not a
--   security boundary. A hand-built POST could tick the other branch, and that
--   is fine, because both branches are inside the type's own ceiling.
--
-- WHY "Remove Hardware" HAS NO INVENTORY BRANCH
--   Removing a part from a server is reversible -- the component goes back to
--   inventory -- and needs only server.edit (permission_map.php gates
--   `server-remove-component` on it).
--   Deleting an INVENTORY record is a different thing, and it is not offered:
--   {type}.delete is deliberately outside
--   TemporaryAccessManager::GRANTABLE_PERMISSIONS, and deleteComponent()
--   (core/helpers/BaseFunctions.php) is a bare DELETE with no in-use or
--   configuration-reference check -- so a granted inventory delete could
--   destroy a row a live server build depends on, and the 24h expiry would
--   undo none of it. Decided with the user on 2026-08-22: ship the server
--   branch, leave the whitelist alone. If inventory deletes are ever wanted,
--   guard deleteComponent() FIRST.
--
-- Notes:    - No permission enters GRANTABLE_PERMISSIONS. Every permission used
--             here was already grantable; this file only regroups them.
--           - is_system = 0 on all three, so they can be renamed, re-worded or
--             archived from the Request Types page.
--           - Earlier types are ARCHIVED, never deleted: `tickets` rows point at
--             them via pipeline_template_id. In-flight requests still advance
--             and their approval still grants access -- is_active is only read
--             when a request is CREATED. Nothing is revoked here.
--           - is_system is cleared on the archived types too, or the Request
--             Types page could restore them but never re-archive them
--             (updateTemplate() refuses to archive an is_system type).
--           - Steps are owned by the `admin` ROLE, resolved by name.
--           - Idempotent: re-running updates all six rows in place.
--           - Rollback: rollback/2026_08_22_005_hardware-action-request-types_rollback.sql
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state -- which access types exist now, and how many requests were
--    raised from each. Open ones keep working; this is for your awareness.
-- ---------------------------------------------------------------------------
SELECT
    t.id        AS template_id,
    t.name      AS request_type,
    t.is_active,
    t.is_system,
    (SELECT COUNT(*) FROM `tickets` k
      WHERE k.`pipeline_template_id` = t.id)                       AS requests_total,
    (SELECT COUNT(*) FROM `tickets` k
      WHERE k.`pipeline_template_id` = t.id
        AND k.`status` IN ('draft','in_progress'))                 AS requests_still_open
FROM `pipeline_templates` t
WHERE t.`name` IN (
    'Temporary Access Request',
    'Temporary Server Access Request',
    'Server Access Request',
    'Inventory Access Request'
)
ORDER BY t.`name`;

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. Add Hardware
--    server branch    : server.create (gates `server-add-component`), server.view
--    inventory branch : create on all 11 component types
-- ---------------------------------------------------------------------------
INSERT INTO `pipeline_templates`
    (`name`, `description`, `is_active`, `is_system`, `created_by`, `created_at`, `updated_at`)
VALUES (
    'Add Hardware',
    'Ask for temporary permission to add hardware. Choose where it goes: into a server configuration (pick which server, or "Any server" if you are starting a new build), or into the component inventory. An admin approves, the access lasts 24 hours and then expires by itself. Anything you add during the window stays.',
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
    'Approve only what the request actually lists. A request for a server names the server it is for -- "Any server" means every configuration in the system, including ones built later, so check that is really what is needed. A request for the inventory is always system-wide; there is no per-server version of it. Access lasts 24 hours and then expires on its own. You cannot approve your own request.',
    'grant_temporary_permission',
    '{"permissions":["server.create","server.view","cpu.create","ram.create","storage.create","motherboard.create","nic.create","caddy.create","chassis.create","pciecard.create","risercard.create","hbacard.create","sfp.create"],"duration_hours":24}',
    NOW(),
    NOW()
FROM `pipeline_templates` t
WHERE t.`name` = 'Add Hardware'
ON DUPLICATE KEY UPDATE
    `name`                     = VALUES(`name`),
    `default_assignee_user_id` = VALUES(`default_assignee_user_id`),
    `default_assignee_role_id` = VALUES(`default_assignee_role_id`),
    `instructions`             = VALUES(`instructions`),
    `effect_type`              = VALUES(`effect_type`),
    `effect_config`            = VALUES(`effect_config`),
    `updated_at`               = NOW();

-- ---------------------------------------------------------------------------
-- 2. Edit Hardware
--    server branch    : server.edit, server.replace, server.view
--    inventory branch : edit on all 11 component types
-- ---------------------------------------------------------------------------
INSERT INTO `pipeline_templates`
    (`name`, `description`, `is_active`, `is_system`, `created_by`, `created_at`, `updated_at`)
VALUES (
    'Edit Hardware',
    'Ask for temporary permission to change hardware that is already recorded. Choose where: inside a server configuration (swap or rearrange its parts -- pick which server), or in the component inventory (correct a specification or a serial number). An admin approves, the access lasts 24 hours and then expires by itself. Anything you change during the window stays.',
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
    'Approve only what the request actually lists. A request for a server names the server it is for -- "Any server" means every configuration in the system, so check that is really what is needed. A request for the inventory is always system-wide. Nothing here can grant a delete permission, by design. Access lasts 24 hours and then expires on its own. You cannot approve your own request.',
    'grant_temporary_permission',
    '{"permissions":["server.edit","server.replace","server.view","cpu.edit","ram.edit","storage.edit","motherboard.edit","nic.edit","caddy.edit","chassis.edit","pciecard.edit","risercard.edit","hbacard.edit","sfp.edit"],"duration_hours":24}',
    NOW(),
    NOW()
FROM `pipeline_templates` t
WHERE t.`name` = 'Edit Hardware'
ON DUPLICATE KEY UPDATE
    `name`                     = VALUES(`name`),
    `default_assignee_user_id` = VALUES(`default_assignee_user_id`),
    `default_assignee_role_id` = VALUES(`default_assignee_role_id`),
    `instructions`             = VALUES(`instructions`),
    `effect_type`              = VALUES(`effect_type`),
    `effect_config`            = VALUES(`effect_config`),
    `updated_at`               = NOW();

-- ---------------------------------------------------------------------------
-- 3. Remove Hardware -- server branch only. See the header for why.
--    server.edit gates `server-remove-component`; server.view to load the build.
-- ---------------------------------------------------------------------------
INSERT INTO `pipeline_templates`
    (`name`, `description`, `is_active`, `is_system`, `created_by`, `created_at`, `updated_at`)
VALUES (
    'Remove Hardware',
    'Ask for temporary permission to take parts out of a server configuration. The parts return to the component inventory as available, so nothing is lost. Pick which server, or "Any server". An admin approves, the access lasts 24 hours and then expires by itself. Deleting inventory records is not available through a request -- ask an administrator directly.',
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
    'This grants the ability to take parts out of a server configuration; the parts go back to inventory as available, so the action is reversible. It does NOT grant inventory deletion and cannot be made to. If a server is named, the access applies to that one configuration; "Any server" covers every configuration in the system, so check that is really what is needed. Access lasts 24 hours and then expires on its own. You cannot approve your own request.',
    'grant_temporary_permission',
    '{"permissions":["server.edit","server.view"],"duration_hours":24}',
    NOW(),
    NOW()
FROM `pipeline_templates` t
WHERE t.`name` = 'Remove Hardware'
ON DUPLICATE KEY UPDATE
    `name`                     = VALUES(`name`),
    `default_assignee_user_id` = VALUES(`default_assignee_user_id`),
    `default_assignee_role_id` = VALUES(`default_assignee_role_id`),
    `instructions`             = VALUES(`instructions`),
    `effect_type`              = VALUES(`effect_type`),
    `effect_config`            = VALUES(`effect_config`),
    `updated_at`               = NOW();

-- ---------------------------------------------------------------------------
-- 4. Retire every place-shaped access type. Archived, never deleted.
--    Listed by name so this is correct whether or not 004 was ever run.
-- ---------------------------------------------------------------------------
UPDATE `pipeline_templates`
   SET `is_active`  = 0,
       `is_system`  = 0,
       `updated_at` = NOW()
 WHERE `name` IN (
    'Temporary Access Request',
    'Temporary Server Access Request',
    'Server Access Request',
    'Inventory Access Request'
);

COMMIT;

-- ---------------------------------------------------------------------------
-- Verification -- three live types owned by the admin role with ceilings of
-- 13 / 14 / 2, and every place-shaped type archived.
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
WHERE t.`name` IN (
    'Add Hardware', 'Edit Hardware', 'Remove Hardware',
    'Temporary Access Request', 'Temporary Server Access Request',
    'Server Access Request', 'Inventory Access Request'
)
ORDER BY t.`is_active` DESC, t.`name`;
