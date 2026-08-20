-- =============================================================================
-- Date:     2026-08-20
-- Purpose:  Create the built-in "Temporary Server Creation Access" Request Type:
--           a one-step request a read-only user raises to ask for the ability to
--           build a server, which an admin approves. Completing that single step
--           fires the 'grant_temporary_permission' effect, granting the REQUEST'S
--           CREATOR server.create + server.view + server.edit for 24 hours.
--
--           After 24 hours the grant lapses by itself. Nothing revokes it, no job
--           runs: loadUserPermissionData() simply stops selecting the row. Server
--           configurations created during the window are untouched and remain.
--
-- Tables:   pipeline_templates (1 row), pipeline_stages (1 row)
-- Feature:  Temporary approval-gated server-build access (Requests module)
--
-- REQUIRES: 2026_08_20_002_pipeline-stage-effects.sql must be applied FIRST --
--           this seeder writes effect_type / effect_config and will fail loudly
--           without those columns. That failure is intentional: a request type
--           that approves but grants nothing is worse than no request type.
--
-- Notes:    - is_system = 1, so PipelineTemplateManager refuses to rename,
--             archive or delete it (updateTemplate L224-231, deleteTemplate L304).
--             Its STEPS remain editable, which is the intended escape hatch.
--           - The approval step is owned by the `admin` ROLE, resolved by name
--             rather than hardcoding id 2. super_admin can also act on it via
--             pipeline.manage. To let managers approve instead, change the role
--             name in the stage INSERT below (and grant manager the pipeline
--             permissions + add it to the gate in api/api.php) -- that is the one
--             line this decision lives on.
--           - Approving is protected three ways in PipelineManager::
--             applyStageEffect(): the actor must hold the admin/super_admin role,
--             the actor must not be the request's creator (separation of duties),
--             and the recipient is always tickets.created_by -- never a value
--             taken from the request body.
--           - The permission list here is re-checked at grant time against
--             TemporaryAccessManager::GRANTABLE_PERMISSIONS. Editing this JSON to
--             ask for users.delete or acl.manage will be refused by the code, not
--             quietly honoured.
--           - server.delete is deliberately NOT granted. server.edit IS, because
--             a builder session that cannot remove a mis-added component is
--             unusable -- note it is not row-scoped, so during the window the
--             user can also edit other people's configurations.
--           - Idempotent: re-running updates the existing type and step in place
--             rather than creating duplicates.
--           - Rollback: rollback/2026_08_20_004_temporary-server-access-request-type_rollback.sql
-- =============================================================================

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. The Request Type (uq_pipeline_templates_name makes this safe to re-run)
-- ---------------------------------------------------------------------------
INSERT INTO `pipeline_templates`
    (`name`, `description`, `is_active`, `is_system`, `created_by`, `created_at`, `updated_at`)
VALUES (
    'Temporary Server Creation Access',
    'Ask for permission to build a server. An admin approves, and you get server build access for 24 hours; it then expires on its own. Anything you build during that window stays.',
    1, 1, NULL, NOW(), NOW()
)
ON DUPLICATE KEY UPDATE
    `description` = VALUES(`description`),
    `is_active`   = 1,
    `is_system`   = 1,
    `updated_at`  = NOW();

-- ---------------------------------------------------------------------------
-- 2. Its single step (uq_pipeline_stages_position keys on template + position)
-- ---------------------------------------------------------------------------
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
    'Approve only if this person genuinely needs to build a server today. Approving immediately grants them server.create, server.view and server.edit for 24 hours. You cannot approve your own request.',
    'grant_temporary_permission',
    '{"permissions":["server.create","server.view","server.edit"],"duration_hours":24}',
    NOW(),
    NOW()
FROM `pipeline_templates` t
WHERE t.`name` = 'Temporary Server Creation Access'
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
-- Verification -- expect one row, owned by the admin role, carrying the effect.
-- ---------------------------------------------------------------------------
SELECT
    t.id   AS template_id,
    t.name AS request_type,
    t.is_system,
    s.position,
    s.name AS step,
    r.name AS owner_role,
    s.effect_type,
    s.effect_config
FROM `pipeline_templates` t
JOIN `pipeline_stages` s ON s.pipeline_template_id = t.id
LEFT JOIN `roles` r ON r.id = s.default_assignee_role_id
WHERE t.`name` = 'Temporary Server Creation Access'
ORDER BY s.position;
