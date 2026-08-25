-- =============================================================================
-- Date:     2026-08-26
-- Purpose:  Let someone who cannot move a server ASK for it to be moved.
--
--           Relocating a server is admin/super_admin work: api.php role-gates
--           the whole rack module, so the "Move server" button on the server
--           card is invisible to everyone else. That is correct -- moving
--           hardware between sites is not a self-service action -- but it left
--           no route at all for the person who actually knows the machine needs
--           to move.
--
--           This adds the route. The requester names the server and where it
--           should end up; an admin approves; the system performs the move
--           through the same ServerRelocation::move() that the button calls.
--           No permission is granted to the requester at any point.
--
-- Tables:   pipeline_templates (1 row), pipeline_stages (1 row)
-- Feature:  Location hierarchy + server relocation, part 5 of 5
-- Requires: 2026_08_26_001 .. _004 (the locations, columns and history table the
--           action writes to). Section 3 additionally needs 2026_08_25_009 and
--           is deliberately placed after COMMIT -- see the note there.
--           The `server.relocate` action must also exist in
--           RequestActionExecutor::ACTION_TYPES, which ships with the code.
--
-- WHY effect_type = 'execute_request' AND NOT A PERMISSION GRANT
--   grant_temporary_permission was retired on 2026-08-23 (PipelineConfig:105).
--   A request now describes WORK, and approving it performs that work as an
--   automated action. The requester never holds rack.assign, not even for a
--   moment, and nothing has to be revoked afterwards.
--
-- WHY THE CEILING IS A SINGLE ACTION TYPE
--   effect_config lists exactly one action -- server.relocate. A request raised
--   from this type can do that and nothing else, and the list is snapshotted
--   onto ticket_stage_progress when the request is raised, so editing this type
--   later cannot widen what an in-flight request will perform.
--
-- Notes:    - is_system = 0, so the type can be renamed, re-worded or archived
--             from the Request Types page like any other.
--           - The step is owned by the `admin` ROLE, resolved by name, matching
--             every other action type (2026_08_23_004).
--           - The move is validated at APPROVAL time, not at request time. A
--             U-slot that was free when the request was raised may be occupied
--             by then; the approval fails whole with the engine's own overlap
--             message and nothing changes -- including no movement row.
--
-- Idempotent: ON DUPLICATE KEY UPDATE on both rows (pipeline_templates.name and
--             the stage's template+position are unique). Re-running rewrites
--             the same values in place.
-- Rollback:   rollback/2026_08_26_005_move-server-request-type_rollback.sql
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state -- the action-performing types that exist now, and whether
--    a Move Server type is already among them.
-- ---------------------------------------------------------------------------
SELECT t.`id`, t.`name`, t.`is_active`, s.`effect_type`, s.`effect_config`,
       (SELECT COUNT(*) FROM `tickets` k WHERE k.`pipeline_template_id` = t.`id`) AS requests_raised
  FROM `pipeline_templates` t
  LEFT JOIN `pipeline_stages` s ON s.`pipeline_template_id` = t.`id`
 WHERE s.`effect_type` = 'execute_request' OR t.`name` = 'Move Server'
 ORDER BY t.`name`;

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. The Request Type.
-- ---------------------------------------------------------------------------
INSERT INTO `pipeline_templates`
    (`name`, `description`, `is_active`, `is_system`, `created_by`, `created_at`, `updated_at`)
VALUES (
    'Move Server',
    'Ask for a server to be moved to a different location, rack or U position. Pick the server, then where it should end up. An admin approves and the system performs the move, updating the server and every component installed inside it in one step. You are never given rack access yourself. If the target position is occupied by the time it is approved, the move is refused and nothing changes.',
    1, 0, NULL, NOW(), NOW()
)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `is_active`   = 1,
    `is_system`   = 0,
    `updated_at`  = NOW();

-- ---------------------------------------------------------------------------
-- 2. Its single approval step, which performs the move.
-- ---------------------------------------------------------------------------
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
    'Approving this MOVES the named server, immediately. Every component installed in it moves with it: their location, rack and U are all re-stamped in the same transaction, and the move is written to the server''s movement history. Check the destination is right -- the system will not ask again. If the target U range is occupied, or the server would run past the top of the rack, the approval is rolled back whole and you will see the engine''s own reason. You cannot approve your own request.',
    'execute_request',
    '{"action_types":["server.relocate"]}',
    NOW(),
    NOW()
FROM `pipeline_templates` t
WHERE t.`name` = 'Move Server'
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
-- 3. Create-form shape. AFTER the COMMIT on purpose: these two columns arrived
--    with 2026_08_25_009, and if that file was never run this statement is the
--    only thing here that fails -- leaving the type itself correctly created
--    rather than rolling the whole seeder back over a cosmetic setting.
--
--    A move request names a SERVER (which machine is moving) but never a parts
--    list: the parts are whatever is inside it, and they all travel with it.
-- ---------------------------------------------------------------------------
UPDATE `pipeline_templates`
   SET `asks_for_server`     = 1,
       `asks_for_components` = 0,
       `updated_at`          = NOW()
 WHERE `name` = 'Move Server';

-- =============================================================================
-- Verification
-- =============================================================================

-- 1. The type and its step, with the ceiling. Expect one row:
--    Move Server / Admin Approval / execute_request / ["server.relocate"] / admin
SELECT t.`id`   AS template_id,
       t.`name` AS request_type,
       t.`is_active`,
       t.`is_system`,
       s.`name` AS step,
       r.`name` AS owner_role,
       s.`effect_type`,
       JSON_EXTRACT(s.`effect_config`, '$.action_types') AS ceiling
  FROM `pipeline_templates` t
  LEFT JOIN `pipeline_stages` s ON s.`pipeline_template_id` = t.`id`
  LEFT JOIN `roles` r           ON r.`id` = s.`default_assignee_role_id`
 WHERE t.`name` = 'Move Server';

-- 2. Exactly one step -- a second would mean the seeder was run against a type
--    that already had stages. MUST return 1.
SELECT COUNT(*) AS step_count
  FROM `pipeline_stages` s
  JOIN `pipeline_templates` t ON t.`id` = s.`pipeline_template_id`
 WHERE t.`name` = 'Move Server';

-- 3. Form shape applied (only meaningful if 2026_08_25_009 has been run).
SELECT `name`, `asks_for_server`, `asks_for_components`
  FROM `pipeline_templates` WHERE `name` = 'Move Server';
