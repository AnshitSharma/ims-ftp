-- =============================================================================
-- Date:     2026-08-23
-- Purpose:  Replace the four access-shaped Request Types with action-shaped
--           ones, and disarm every stage that still grants permissions.
--
--           Add / Edit / Remove Hardware and Server Changes all describe a
--           PERMISSION the requester wants. That model is retired: a request now
--           describes WORK, and approval performs it. A type's approval step
--           carries the set of actions it is allowed to perform
--           (effect_type = 'execute_request') instead of the set of permissions
--           it is allowed to grant.
--
-- Tables:   pipeline_templates (8 INSERT, 4 archived)
--           pipeline_stages    (8 INSERT, all grant stages disarmed)
-- Feature:  Requests as automation (Phase 8)
--
-- RUN AFTER 2026_08_23_003_request-actions-table.sql. Until that table exists,
-- applyStageEffect() refuses to approve an execute_request step rather than
-- approving a request whose work it cannot read -- so these types would be
-- unapprovable, loudly, which is the correct failure but not a useful state to
-- sit in.
--
-- WHY THE OLD TYPES ARE ARCHIVED AND NOT DELETED
--   tickets.pipeline_template_id points at them. Deleting would leave the
--   requests raised from them without a type name, and there are 26 of them.
--   is_system is cleared as well, or the Request Types page could restore them
--   but never re-archive them -- updateTemplate() refuses to archive a system
--   type. Same reasoning as 2026_08_22_002 and 2026_08_22_004.
--
-- WHY SECTION 3 MATTERS MORE THAN IT LOOKS
--   Any stage still carrying effect_type = 'grant_temporary_permission' is a
--   stage that PROMISES a grant the code no longer performs. The engine treats
--   it as a retired no-op so old requests stay completable, but leaving the
--   value in place means an admin opening one of those types sees a step
--   claiming to grant access that nothing will grant. Clearing it makes the
--   data agree with the code.
--
-- Idempotent: matched by name via ON DUPLICATE KEY (pipeline_templates.name is
--             UNIQUE) and uq_pipeline_stages_position. Re-running is a no-op.
-- Rollback:   rollback/2026_08_23_004_action-request-types_rollback.sql
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state. Keep this output.
-- ---------------------------------------------------------------------------
SELECT t.`id`, t.`name`, t.`is_active`, t.`is_system`,
       s.`id` AS stage_id, s.`effect_type`,
       (SELECT COUNT(*) FROM `tickets` k WHERE k.`pipeline_template_id` = t.`id`) AS requests_raised
  FROM `pipeline_templates` t
  LEFT JOIN `pipeline_stages` s ON s.`pipeline_template_id` = t.`id`
 ORDER BY t.`is_active` DESC, t.`name`;

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. The eight action-shaped types.
--
--    One "Admin Approval" step each, owned by the admin role, carrying the set
--    of actions it may perform. The set is a CEILING, snapshotted onto each
--    request when it is raised -- editing a type later cannot change what an
--    already-open request will do.
--
--    is_system = 0 on purpose: nothing in the PHP or the JS looks a request type
--    up by name, so an admin can rename, re-word or archive any of these from
--    the Request Types page.
-- ---------------------------------------------------------------------------

INSERT INTO `pipeline_templates`
    (`name`, `description`, `is_active`, `is_system`, `created_by`, `created_at`, `updated_at`)
VALUES
    ('Install Hardware',
     'Ask for a component to be fitted into a server. Pick the server and the part in the builder as usual; if you do not have permission to fit it yourself, the change is raised as a request instead. An admin approves and the system fits it for you -- you are never given access to the build. If the part does not fit, the approval is refused and nothing changes.',
     1, 0, NULL, NOW(), NOW()),
    ('Return Hardware to Stock',
     'Ask for a component to be taken out of a server and returned to inventory. An admin approves and the system removes it, releasing the part back to available stock. Nothing is deleted -- the part returns to inventory and can be fitted somewhere else.',
     1, 0, NULL, NOW(), NOW()),
    ('Swap Hardware',
     'Ask for a component in a server to be exchanged for a different one. The part coming out returns to inventory; the part going in is fitted in its place. An admin approves and the system performs both halves together, or neither.',
     1, 0, NULL, NOW(), NOW()),
    ('New Server',
     'Ask for a new server configuration to be created. Give it a name, and optionally a description, location and rack position. An admin approves and the system creates it with you as its owner, so you can build it out yourself from there.',
     1, 0, NULL, NOW(), NOW()),
    ('Update Server Details',
     'Ask for a server''s name, description, location, rack position or notes to be changed. This does not touch the hardware inside it, and it cannot change the server''s status -- that is a separate request type. A finalized build cannot be edited this way.',
     1, 0, NULL, NOW(), NOW()),
    ('Change Server Status',
     'Ask for a server to be moved to a different lifecycle status -- for example from building to validated, or into maintenance. An admin approves and the system moves it, subject to the same lifecycle rules that apply to anyone making the change directly.',
     1, 0, NULL, NOW(), NOW()),
    ('Add Inventory Record',
     'Ask for a new component to be recorded in inventory. Fill in the normal Add Component form; if you do not have permission to add it yourself, it is raised as a request. An admin approves and the system creates the record, listing you as the person who added it.',
     1, 0, NULL, NOW(), NOW()),
    ('Update Inventory Record',
     'Ask for an existing inventory record to be corrected -- a serial number, a location, a status, a note. An admin approves and the system applies the change. Records are never deleted this way.',
     1, 0, NULL, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `is_active`   = 1,
    `is_system`   = 0,
    `updated_at`  = NOW();

-- ---------------------------------------------------------------------------
-- 2. One approval step per type, carrying its action ceiling.
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
    m.`instructions`,
    'execute_request',
    m.`effect_config`,
    NOW(),
    NOW()
FROM `pipeline_templates` t
JOIN (
    SELECT 'Install Hardware' AS tname,
           '{"action_types":["server.component.add"]}' AS effect_config,
           'Approving this FITS the part into the named server, immediately, as the requester. Check that the part and the server are the right ones -- the system will not ask again. If the compatibility engine refuses the part, the approval is rolled back whole and nothing changes; you will see the engine''s own reason. You cannot approve your own request.' AS instructions
    UNION ALL SELECT 'Return Hardware to Stock',
           '{"action_types":["server.component.remove"]}',
           'Approving this REMOVES the part from the named server and returns it to available inventory. The part is not deleted. If the removal fails, the approval is rolled back whole. You cannot approve your own request.'
    UNION ALL SELECT 'Swap Hardware',
           '{"action_types":["server.component.replace"]}',
           'Approving this EXCHANGES one part for another in the named server: the old part returns to inventory and the new one is fitted. Both halves happen together or neither does. You cannot approve your own request.'
    UNION ALL SELECT 'New Server',
           '{"action_types":["server.config.create"]}',
           'Approving this CREATES the server configuration, owned by the requester -- which means they can then build it out themselves without further requests. Check the name is one your team will recognise. You cannot approve your own request.'
    UNION ALL SELECT 'Update Server Details',
           '{"action_types":["server.config.update"]}',
           'Approving this CHANGES the named server''s details. It cannot touch the hardware inside it and it cannot change its status. A finalized build is refused. You cannot approve your own request.'
    UNION ALL SELECT 'Change Server Status',
           '{"action_types":["server.config.transition"]}',
           'Approving this MOVES the named server to the requested status, through the same lifecycle rules that apply to anyone making the change directly -- an illegal move is refused and the approval is rolled back. You cannot approve your own request.'
    UNION ALL SELECT 'Add Inventory Record',
           '{"action_types":["inventory.component.add"]}',
           'Approving this CREATES the inventory record, listing the requester as the person who added it. The component UUID is validated against the hardware catalogue before anything is written. You cannot approve your own request.'
    UNION ALL SELECT 'Update Inventory Record',
           '{"action_types":["inventory.component.edit"]}',
           'Approving this APPLIES the requested corrections to the inventory record. Records cannot be deleted this way. You cannot approve your own request.'
) m ON m.`tname` = t.`name`
ON DUPLICATE KEY UPDATE
    `name`                     = VALUES(`name`),
    `default_assignee_user_id` = VALUES(`default_assignee_user_id`),
    `default_assignee_role_id` = VALUES(`default_assignee_role_id`),
    `instructions`             = VALUES(`instructions`),
    `effect_type`              = VALUES(`effect_type`),
    `effect_config`            = VALUES(`effect_config`),
    `updated_at`               = NOW();

-- ---------------------------------------------------------------------------
-- 3. Disarm every stage that still promises a grant.
--
--    Covers the four access types below AND anything else that ever carried the
--    effect, including types already archived by 2026_08_22_004. A stage
--    advertising access that nothing grants is worse than a stage advertising
--    nothing.
-- ---------------------------------------------------------------------------
UPDATE `pipeline_stages`
   SET `effect_type`   = NULL,
       `effect_config` = NULL,
       `updated_at`    = NOW()
 WHERE `effect_type` = 'grant_temporary_permission';

-- ---------------------------------------------------------------------------
-- 4. Archive the access-shaped types. Never deleted -- see the header.
-- ---------------------------------------------------------------------------
UPDATE `pipeline_templates`
   SET `is_active`  = 0,
       `is_system`  = 0,
       `updated_at` = NOW()
 WHERE `name` IN ('Add Hardware', 'Edit Hardware', 'Remove Hardware', 'Server Changes',
                  'Temporary Access Request', 'Server Access Request', 'Inventory Access Request',
                  'Temporary Server Creation Access');

COMMIT;

-- =============================================================================
-- Verification
-- =============================================================================

-- 1. Eight active action-shaped types, each with exactly one execute_request
--    step. MUST return 8 rows, every one with stage_count = 1.
SELECT t.`name`, t.`is_active`, COUNT(s.`id`) AS stage_count,
       MAX(s.`effect_type`) AS effect_type, MAX(s.`effect_config`) AS ceiling
  FROM `pipeline_templates` t
  LEFT JOIN `pipeline_stages` s ON s.`pipeline_template_id` = t.`id`
 WHERE t.`name` IN ('Install Hardware','Return Hardware to Stock','Swap Hardware','New Server',
                    'Update Server Details','Change Server Status','Add Inventory Record',
                    'Update Inventory Record')
 GROUP BY t.`id`, t.`name`, t.`is_active`
 ORDER BY t.`name`;

-- 2. No stage anywhere still promises a grant. MUST return 0.
SELECT COUNT(*) AS stages_still_granting
  FROM `pipeline_stages` WHERE `effect_type` = 'grant_temporary_permission';

-- 3. Every access-shaped type is archived. MUST return 0.
SELECT COUNT(*) AS access_types_still_active
  FROM `pipeline_templates`
 WHERE `is_active` = 1
   AND `name` IN ('Add Hardware','Edit Hardware','Remove Hardware','Server Changes',
                  'Temporary Access Request','Server Access Request','Inventory Access Request',
                  'Temporary Server Creation Access');

-- 4. What a requester will now see in the type dropdown.
SELECT `id`, `name`, `is_system` FROM `pipeline_templates`
 WHERE `is_active` = 1 ORDER BY `name`;

-- 5. Requests raised from a now-archived type keep their history. Informational.
SELECT t.`name` AS archived_type, COUNT(k.`id`) AS requests_kept
  FROM `pipeline_templates` t
  JOIN `tickets` k ON k.`pipeline_template_id` = t.`id`
 WHERE t.`is_active` = 0
 GROUP BY t.`id`, t.`name` ORDER BY t.`name`;
