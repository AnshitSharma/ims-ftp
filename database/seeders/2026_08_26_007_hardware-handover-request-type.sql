-- =============================================================================
-- Date:     2026-08-26
-- Purpose:  The Hardware Handover request type -- the route that gets a part
--           from the site it is at to the site the server is at.
--
--           A server racked in Jaipur cannot be fitted with an SSD that is
--           physically in Noida. From 2026-08-26 the Requests engine refuses
--           that install at approval time (RequestActionExecutor's location
--           gate) instead of silently re-stamping the drive with an address
--           nobody carried it to. Refusing is only half an answer; this is the
--           other half.
--
--           The requester raises a Hardware Handover naming the unit, the
--           destination site, and WHO WILL CARRY IT. An admin approves, which
--           performs the location change. The named carrier then confirms they
--           actually handed it over, which closes the child request and
--           unfreezes the parent install.
--
-- Tables:   pipeline_templates (1 row), pipeline_stages (2 rows)
-- Feature:  Location-aware Requests + Hardware Handover, part 2 of 3
-- Requires: 2026_08_26_001 .. _003 (locations and the inventory location
--           columns) and 2026_08_26_006 (the movement history the action
--           writes). Section 3 additionally needs 2026_08_25_009 and is
--           deliberately placed after COMMIT -- see the note there.
--           The `inventory.component.relocate` action must also exist in
--           RequestActionExecutor::ACTION_TYPES, which ships with the code.
--           Carriers need 2026_08_26_008 or they cannot complete step 2.
--
-- WHY THE MOVE IS ON STEP 1 AND NOT STEP 2
--   PipelineManager::applyStageEffect() refuses ANY execute_request effect
--   unless the person completing the step is an admin or super_admin (Guard 1).
--   A carrier is not an admin -- that is the point of them being a carrier --
--   so an effect-bearing confirmation step could never be completed by the one
--   person who is supposed to complete it. The admin's approval performs the
--   move; the carrier's confirmation performs nothing and simply closes the
--   request.
--
--   The accepted trade-off: between those two moments the database says Jaipur
--   while the drive is still in a bag. The parent install stays FROZEN for the
--   whole of that window (PipelineManager::blockingChildren()), so nothing acts
--   on the premature address, and the request's own timeline records both
--   moments with their timestamps.
--
-- WHY STEP 2 HAS NO DEFAULT OWNER
--   The carrier is chosen per request, not per type -- it is a different person
--   every time. PipelineManager::createPipeline() reads handover_user_id off
--   the request's action payload and writes it into the step's assignee as a
--   stage override when the request is raised. Leaving both default_assignee
--   columns NULL is what makes that override the only owner: with no default
--   role to fall back on, nobody else can claim the step.
--
-- WHY asks_for_server = 0
--   A handover is about a PART, not a machine. The part may be destined for a
--   server, or for a shelf at the other site so it is there when it is needed.
--   Naming a server here would be a lie in the second case and a duplicate of
--   the parent request in the first.
--
-- Notes:    - is_system = 0, so the type can be renamed, re-worded or archived
--             from the Request Types page like any other.
--           - Step 1 is owned by the `admin` ROLE, resolved by name, matching
--             every other action type (2026_08_23_004).
--           - The move is validated at APPROVAL time, not at request time. A
--             unit that was loose stock when the request was raised may have
--             been installed by then; the approval fails whole and nothing
--             changes -- including no movement row.
--
-- Idempotent: ON DUPLICATE KEY UPDATE on all three rows (pipeline_templates
--             .name and pipeline_stages(template, position) are unique).
--             Re-running rewrites the same values in place.
-- Rollback:   rollback/2026_08_26_007_hardware-handover-request-type_rollback.sql
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state -- the action-performing types that exist now, and whether
--    a Hardware Handover type is already among them.
-- ---------------------------------------------------------------------------
SELECT t.`id`, t.`name`, t.`is_active`, s.`position`, s.`name` AS step,
       s.`effect_type`, s.`effect_config`,
       (SELECT COUNT(*) FROM `tickets` k WHERE k.`pipeline_template_id` = t.`id`) AS requests_raised
  FROM `pipeline_templates` t
  LEFT JOIN `pipeline_stages` s ON s.`pipeline_template_id` = t.`id`
 WHERE s.`effect_type` = 'execute_request' OR t.`name` = 'Hardware Handover'
 ORDER BY t.`name`, s.`position`;

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. The Request Type.
-- ---------------------------------------------------------------------------
INSERT INTO `pipeline_templates`
    (`name`, `description`, `is_active`, `is_system`, `created_by`, `created_at`, `updated_at`)
VALUES (
    'Hardware Handover',
    'Ask for a component to be moved from the site it is at now to another site -- normally because a server you want it fitted into is somewhere else. Pick the exact unit by serial number, the destination location, and the person who will physically carry it. An admin approves, which records the move; the carrier then confirms they have handed it over, which closes this request. Only loose stock can be handed over: a component that is currently installed in a server moves with that server, not on its own.',
    1, 0, NULL, NOW(), NOW()
)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `is_active`   = 1,
    `is_system`   = 0,
    `updated_at`  = NOW();

-- ---------------------------------------------------------------------------
-- 2a. Step 1 -- Admin Approval. This is the step that performs the move.
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
    'Approving this RECORDS THE MOVE of the named unit to the destination site, immediately -- its location, shelf and address text all change. The unit must still be loose stock: if it has been installed in a server since this was raised, the approval is refused whole and nothing changes. Approving does NOT mean the hardware has arrived; the carrier named on this request confirms that on the next step, and only then does this request close. Any install request waiting on this one stays frozen until it does. You cannot approve your own request.',
    'execute_request',
    '{"action_types":["inventory.component.relocate"]}',
    NOW(),
    NOW()
FROM `pipeline_templates` t
WHERE t.`name` = 'Hardware Handover'
ON DUPLICATE KEY UPDATE
    `name`                     = VALUES(`name`),
    `default_assignee_user_id` = VALUES(`default_assignee_user_id`),
    `default_assignee_role_id` = VALUES(`default_assignee_role_id`),
    `instructions`             = VALUES(`instructions`),
    `effect_type`              = VALUES(`effect_type`),
    `effect_config`            = VALUES(`effect_config`),
    `updated_at`               = NOW();

-- ---------------------------------------------------------------------------
-- 2b. Step 2 -- Handover Confirmation. Performs nothing; it is a signature.
--     Both default_assignee columns are NULL ON PURPOSE -- see the header.
-- ---------------------------------------------------------------------------
INSERT INTO `pipeline_stages`
    (`pipeline_template_id`, `name`, `position`,
     `default_assignee_user_id`, `default_assignee_role_id`,
     `instructions`, `effect_type`, `effect_config`, `created_at`, `updated_at`)
SELECT
    t.`id`,
    'Handover Confirmation',
    2,
    NULL,
    NULL,
    'You were named on this request as the person carrying this hardware. Complete this step only once the part is physically at the destination site and in the hands of whoever will use it -- not when it leaves, and not when it is in transit. Completing this closes the request and releases any install request that was waiting on it. If the handover did not happen, leave this step open and say so in a comment; an admin can cancel the request.',
    NULL,
    NULL,
    NOW(),
    NOW()
FROM `pipeline_templates` t
WHERE t.`name` = 'Hardware Handover'
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
--    A handover names neither a server nor a parts list from the generic
--    pickers: the unit, the destination and the carrier are all collected by
--    the action's own form.
-- ---------------------------------------------------------------------------
UPDATE `pipeline_templates`
   SET `asks_for_server`     = 0,
       `asks_for_components` = 0,
       `updated_at`          = NOW()
 WHERE `name` = 'Hardware Handover';

-- =============================================================================
-- Verification
-- =============================================================================

-- 1. The type and its two steps. Expect exactly two rows:
--    1 / Admin Approval          / execute_request / ["inventory.component.relocate"] / admin
--    2 / Handover Confirmation   / NULL            / NULL                             / NULL
SELECT t.`id`   AS template_id,
       t.`name` AS request_type,
       t.`is_active`,
       t.`is_system`,
       s.`position`,
       s.`name` AS step,
       r.`name` AS owner_role,
       s.`default_assignee_user_id` AS owner_user,
       s.`effect_type`,
       JSON_EXTRACT(s.`effect_config`, '$.action_types') AS ceiling
  FROM `pipeline_templates` t
  LEFT JOIN `pipeline_stages` s ON s.`pipeline_template_id` = t.`id`
  LEFT JOIN `roles` r           ON r.`id` = s.`default_assignee_role_id`
 WHERE t.`name` = 'Hardware Handover'
 ORDER BY s.`position`;

-- 2. Exactly two steps -- a third would mean the seeder was run against a type
--    that already had stages. MUST return 2.
SELECT COUNT(*) AS step_count
  FROM `pipeline_stages` s
  JOIN `pipeline_templates` t ON t.`id` = s.`pipeline_template_id`
 WHERE t.`name` = 'Hardware Handover';

-- 3. The admin role resolved. owner_role on step 1 above MUST NOT be NULL --
--    a NULL there means no role named 'admin' exists and step 1 is ownerless.
SELECT `id`, `name` FROM `roles` WHERE `name` = 'admin';

-- 4. Form shape applied (only meaningful if 2026_08_25_009 has been run).
SELECT `name`, `asks_for_server`, `asks_for_components`
  FROM `pipeline_templates` WHERE `name` = 'Hardware Handover';
