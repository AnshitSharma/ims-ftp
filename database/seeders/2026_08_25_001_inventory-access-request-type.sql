-- =============================================================================
-- Date:     2026-08-25
-- Purpose:  Add the "Inventory Access" Request Type -- a viewer asking to be
--           given inventory permissions outright, instead of raising a separate
--           request for every component they handle.
--
-- Tables:   pipeline_templates (1 INSERT)
--           pipeline_stages    (1 INSERT)
-- Feature:  Requests as automation (Phase 8)
--
-- ============================ READ THIS FIRST ================================
--
-- THIS TYPE GRANTS NOTHING BY ITSELF, AND THAT IS DELIBERATE.
--
--   effect_type is NULL. Approving the step records a decision and closes the
--   request; it does not touch user_roles, role_permissions or
--   user_permissions. The admin performs the grant by hand on the ACL page.
--
--   It is NOT built as effect_type = 'grant_temporary_permission'. That effect
--   is retired: PipelineConfig::getStageEffectTypes() excludes it, so
--   PipelineTemplateManager::validateStageEffect() would reject it, and
--   2026_08_23_005 deleted every grant row that model had produced. Its own
--   header explains why it can never come back as written -- once the grant
--   filter leaves the two permission resolvers, an expired grant reads as a
--   permanent one and a server-scoped grant reads as a global one, silently.
--   An access request must therefore end in a REAL role or permission
--   assignment, which expires never and revokes normally, or in nothing at all.
--
-- WHY A REQUEST TYPE AND NOT JUST AN EMAIL
--   A viewer already holds pipeline.create and pipeline.view_own (verified on
--   production 2026-08-25 against role_id 5, which is is_default = 1), and the
--   Requests menu is shown to every role -- see sidebar-manager.js, "Requests is
--   open to everyone". So the asking half already works; only the type was
--   missing. This gives the ask a queue, an owner and an audit trail instead of
--   a message nobody can find later.
--
-- HOW THIS DIFFERS FROM "Add Inventory Record" (id 23)
--   That type is for ONE component: the requester fills in the Add Component
--   form, an admin approves, and the system creates that single record while the
--   requester stays without access. This type is for the access itself, so the
--   requester stops needing a request per component. Both are correct; they
--   answer different questions, and the descriptions below say so.
--
-- Idempotent: pipeline_templates.name is UNIQUE and uq_pipeline_stages_position
--             covers (template, position), so ON DUPLICATE KEY makes a re-run a
--             no-op that only refreshes wording.
-- Rollback:   rollback/2026_08_25_001_inventory-access-request-type_rollback.sql
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state. Keep this output.
-- ---------------------------------------------------------------------------
SELECT t.`id`, t.`name`, t.`is_active`, t.`is_system`,
       s.`id` AS stage_id, s.`position`, s.`effect_type`,
       (SELECT COUNT(*) FROM `tickets` k WHERE k.`pipeline_template_id` = t.`id`) AS requests_raised
  FROM `pipeline_templates` t
  LEFT JOIN `pipeline_stages` s ON s.`pipeline_template_id` = t.`id`
 WHERE t.`name` = 'Inventory Access'
 ORDER BY s.`position`;

-- ---------------------------------------------------------------------------
-- 1. The type.
--
--    is_system = 0: nothing in the PHP or the JS looks a request type up by
--    name (see api.js -- "Types are data, not code"), so an admin may rename,
--    re-word or archive this from the Request Types page.
-- ---------------------------------------------------------------------------

INSERT INTO `pipeline_templates`
    (`name`, `description`, `is_active`, `is_system`, `created_by`, `created_at`, `updated_at`)
VALUES
    ('Inventory Access',
     'Ask to be given inventory access yourself, so you can add and correct component records directly instead of raising a request for each one. Say in the description which component types you need (for example CPU, RAM and Storage) and whether you need to add records, correct them, or both. An admin reviews it and, if they agree, grants the access on the ACL page -- normally by assigning the Technician role. Approving this request does not grant anything on its own, so the access is real permission that revokes normally, not a temporary one that quietly expires. Until it is granted, use "Add Inventory Record" for individual components.',
     1, 0, NULL, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `is_active`   = 1,
    `is_system`   = 0,
    `updated_at`  = NOW();

-- ---------------------------------------------------------------------------
-- 2. One approval step, owned by the admin role.
--
--    effect_type / effect_config stay NULL: this step performs no automation.
--    The instructions carry the one thing that can go wrong -- completing the
--    step without actually granting anything, which closes the request and
--    leaves the requester exactly where they started.
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
    'Completing this step performs NOTHING by itself -- it records your decision and closes the request. If you agree, GRANT THE ACCESS FIRST: ACL -> the requester -> assign the Technician role, or the individual {type}.create / {type}.edit permissions they asked for. Only then complete this step. Completing it without granting closes the request while the requester still has no access, and they will have no way to tell. If you disagree, Reject with a reason rather than completing. This request cannot remove access the requester already has. You cannot approve your own request.',
    NULL,
    NULL,
    NOW(),
    NOW()
FROM `pipeline_templates` t
WHERE t.`name` = 'Inventory Access'
ON DUPLICATE KEY UPDATE
    `name`                     = VALUES(`name`),
    `default_assignee_role_id` = VALUES(`default_assignee_role_id`),
    `instructions`             = VALUES(`instructions`),
    `effect_type`              = NULL,
    `effect_config`            = NULL,
    `updated_at`               = NOW();

-- ---------------------------------------------------------------------------
-- 3. After-state. The stage must show effect_type = NULL.
--    If it shows anything else, STOP -- something else edited this type.
-- ---------------------------------------------------------------------------
SELECT t.`id`, t.`name`, t.`is_active`, t.`is_system`,
       s.`id` AS stage_id, s.`position`, s.`name` AS stage_name,
       r.`name` AS owner_role, s.`effect_type`, s.`effect_config`
  FROM `pipeline_templates` t
  LEFT JOIN `pipeline_stages` s ON s.`pipeline_template_id` = t.`id`
  LEFT JOIN `roles` r ON r.`id` = s.`default_assignee_role_id`
 WHERE t.`name` = 'Inventory Access'
 ORDER BY s.`position`;
