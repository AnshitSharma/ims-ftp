-- =============================================================================
-- Date:     2026-08-25
-- Purpose:  Add two PHYSICAL access Request Types -- "Inventory Room Access" and
--           "Server Room Access" -- so the prerequisite mechanism added by
--           2026_08_25_007 has something real to be raised as.
--
-- Tables:   pipeline_templates (2 INSERTs)
--           pipeline_stages    (2 INSERTs, one approval step each)
-- Feature:  Child Requests / prerequisite blocking
-- Requires: 2026_08_25_007 (only for the "raise as a child" flow; these two
--           types work as ordinary top-level requests without it)
--
-- ============================ READ THIS FIRST ================================
--
-- THESE TYPES GRANT NOTHING, AND THEY GRANT NOTHING IN A DIFFERENT WAY FROM
-- EVERY OTHER TYPE.
--
--   effect_type is NULL, exactly as in 2026_08_25_001. But the reason is
--   stronger here: what is being asked for is a DOOR. There is no row in this
--   database that represents standing in the inventory room, so there is
--   nothing approval could perform even in principle. The approver opens the
--   door -- badge system, key, escort -- and then records that they did.
--
--   This is not the ACL "Inventory Access" type (2026_08_25_001). That one asks
--   to be given inventory PERMISSIONS in the application. This one asks to be
--   let into a physical room. A tech can need either, both, or neither, and the
--   descriptions below say so.
--
-- WHAT THESE ARE FOR
--   Raised as a CHILD of the request that needs them. A tech raises "Add
--   Inventory Record" for a component he has just taken delivery of, then
--   raises "Inventory Room Access" from inside it. The parent freezes until this
--   one is resolved; when it completes, the parent unfreezes and its own
--   approval performs the inventory write. Nothing here approves the parent --
--   that stays a separate decision by a separate admin.
--
--   They work perfectly well as standalone top-level requests too. Nothing in
--   the schema or the PHP requires a type to be used as a child.
--
-- WHY is_system = 0
--   Nothing in the PHP or the JS looks a request type up by name (see api.js --
--   "Types are data, not code"), so an admin may rename, re-word or archive
--   either of these from the Request Types page.
--
-- Idempotent: pipeline_templates.name is UNIQUE and uq_pipeline_stages_position
--             covers (template, position), so ON DUPLICATE KEY makes a re-run a
--             no-op that only refreshes wording. No information_schema is read.
-- Rollback:   rollback/2026_08_25_008_room-access-request-types_rollback.sql
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state. Keep this output.
-- ---------------------------------------------------------------------------
SELECT t.`id`, t.`name`, t.`is_active`, t.`is_system`,
       s.`id` AS stage_id, s.`position`, s.`effect_type`,
       (SELECT COUNT(*) FROM `tickets` k WHERE k.`pipeline_template_id` = t.`id`) AS requests_raised
  FROM `pipeline_templates` t
  LEFT JOIN `pipeline_stages` s ON s.`pipeline_template_id` = t.`id`
 WHERE t.`name` IN ('Inventory Room Access', 'Server Room Access')
 ORDER BY t.`name`, s.`position`;

-- ---------------------------------------------------------------------------
-- 1. Inventory Room Access.
-- ---------------------------------------------------------------------------
INSERT INTO `pipeline_templates`
    (`name`, `description`, `is_active`, `is_system`, `created_by`, `created_at`, `updated_at`)
VALUES
    ('Inventory Room Access',
     'Ask to be let into the inventory room so you can physically handle stock -- unpack a delivery, pull a part off a shelf, or put one back. Say in the description when you need to be in there and what for. This is about the room, not the software: it does not let you add or change any record in IMS. If you also need to record components yourself, use "Inventory Access" for that, and if you just need one component recorded, use "Add Inventory Record" and it will be done for you. Raise this from inside the request that needs it -- your request then waits for this one instead of being approved while you are still locked out.',
     1, 0, NULL, NOW(), NOW())
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
    t.`id`,
    'Admin Approval',
    1,
    NULL,
    (SELECT r.`id` FROM `roles` r WHERE r.`name` = 'admin'),
    'Completing this step performs NOTHING by itself -- it records your decision and closes the request. If you agree, OPEN THE DOOR FIRST: badge access, key handover, or arrange an escort. Only then complete this step. Completing it without actually letting them in closes the request while the requester is still locked out, and if this is a prerequisite for another request, that one unfreezes and gets approved on the strength of access nobody granted. If you disagree, Reject with a reason -- the parent request stays frozen and its requester is told why. This request grants no permission in IMS and cannot take access away.',
    NULL,
    NULL,
    NOW(),
    NOW()
FROM `pipeline_templates` t
WHERE t.`name` = 'Inventory Room Access'
ON DUPLICATE KEY UPDATE
    `name`                     = VALUES(`name`),
    `default_assignee_role_id` = VALUES(`default_assignee_role_id`),
    `instructions`             = VALUES(`instructions`),
    `effect_type`              = NULL,
    `effect_config`            = NULL,
    `updated_at`               = NOW();

-- ---------------------------------------------------------------------------
-- 2. Server Room Access.
-- ---------------------------------------------------------------------------
INSERT INTO `pipeline_templates`
    (`name`, `description`, `is_active`, `is_system`, `created_by`, `created_at`, `updated_at`)
VALUES
    ('Server Room Access',
     'Ask to be let into the server room or a specific rack aisle so you can physically work on a machine -- fit a part, pull one out, or check something in place. Name the server or rack and when you need to be there. This is about the room, not the software: it does not let you change any server configuration in IMS. The configuration change itself is a separate request ("Install Hardware", "Swap Hardware", "Return Hardware to Stock") which an admin approves and the system applies for you. Raise this from inside that request -- it then waits for this one instead of being approved while you cannot reach the machine.',
     1, 0, NULL, NOW(), NOW())
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
    t.`id`,
    'Admin Approval',
    1,
    NULL,
    (SELECT r.`id` FROM `roles` r WHERE r.`name` = 'admin'),
    'Completing this step performs NOTHING by itself -- it records your decision and closes the request. If you agree, ARRANGE THE ACCESS FIRST: badge the requester in for the window they asked for, hand over the key, or book an escort. Only then complete this step. Completing it without actually arranging it closes the request while the requester still cannot reach the machine, and if this is a prerequisite for another request, that one unfreezes and gets approved on the strength of access nobody granted. Check the window is reasonable and the machine is not mid-change by someone else. If you disagree, Reject with a reason -- the parent request stays frozen and its requester is told why. This request grants no permission in IMS.',
    NULL,
    NULL,
    NOW(),
    NOW()
FROM `pipeline_templates` t
WHERE t.`name` = 'Server Room Access'
ON DUPLICATE KEY UPDATE
    `name`                     = VALUES(`name`),
    `default_assignee_role_id` = VALUES(`default_assignee_role_id`),
    `instructions`             = VALUES(`instructions`),
    `effect_type`              = NULL,
    `effect_config`            = NULL,
    `updated_at`               = NOW();

-- ---------------------------------------------------------------------------
-- 3. After-state. BOTH stages must show effect_type = NULL and an owner_role of
--    'admin'. If either shows anything else, STOP -- something else edited it.
-- ---------------------------------------------------------------------------
SELECT t.`id`, t.`name`, t.`is_active`, t.`is_system`,
       s.`id` AS stage_id, s.`position`, s.`name` AS stage_name,
       r.`name` AS owner_role, s.`effect_type`, s.`effect_config`
  FROM `pipeline_templates` t
  LEFT JOIN `pipeline_stages` s ON s.`pipeline_template_id` = t.`id`
  LEFT JOIN `roles` r ON r.`id` = s.`default_assignee_role_id`
 WHERE t.`name` IN ('Inventory Room Access', 'Server Room Access')
 ORDER BY t.`name`, s.`position`;
