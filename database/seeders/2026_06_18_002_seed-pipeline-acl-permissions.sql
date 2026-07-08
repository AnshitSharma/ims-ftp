-- ============================================================
-- Seeder : 2026_06_18_002_seed-pipeline-acl-permissions
-- Date   : 2026-06-18
-- Purpose: ACL permissions that gate the new `pipeline` module
--          (configurable multi-stage pipeline ticketing).
-- Tables : permissions (rows), role_permissions (rows)
-- Notes  : Idempotent. Safe to re-run.
--            * Permission rows inserted only when missing (NOT EXISTS).
--            * Each pipeline.* permission is granted to exactly the roles
--              that already hold a matching ticket.* permission, so
--              pipeline access mirrors existing ticket access (no
--              hardcoded role ids). super_admin/admin bypass ACL in code
--              and need no rows.
-- Feature: Pipeline / Workflow Ticketing.
-- Mapping (new pipeline perm  <-  mirrors existing ticket perm):
--   pipeline.template_view    <-  ticket.create
--   pipeline.template_manage  <-  ticket.manage
--   pipeline.create           <-  ticket.create
--   pipeline.view_own         <-  ticket.view_own
--   pipeline.view_all         <-  ticket.view_all
--   pipeline.claim            <-  ticket.deploy
--   pipeline.act              <-  ticket.deploy
--   pipeline.reassign         <-  ticket.assign
--   pipeline.cancel           <-  ticket.cancel
--   pipeline.manage           <-  ticket.manage
-- ============================================================

-- ------------------------------------------------------------
-- 1. Permission rows (grouped under the 'ticket' category so they sit
--    next to ticket permissions in the ACL UI). Each derived-table
--    column is aliased to avoid case-insensitive duplicate-column errors.
-- ------------------------------------------------------------
INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'pipeline.template_view' AS `name`, 'View Pipeline Types' AS `display_name`, 'View pipeline type definitions and their stages' AS `description`, 'ticket' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'pipeline.template_view');

INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'pipeline.template_manage' AS `name`, 'Manage Pipeline Types' AS `display_name`, 'Create, edit and delete pipeline types and their stages' AS `description`, 'ticket' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'pipeline.template_manage');

INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'pipeline.create' AS `name`, 'Create Pipelines' AS `display_name`, 'Start a new pipeline from a type' AS `description`, 'ticket' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'pipeline.create');

INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'pipeline.view_own' AS `name`, 'View Own Pipelines' AS `display_name`, 'View pipelines you created or are assigned (incl. via role)' AS `description`, 'ticket' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'pipeline.view_own');

INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'pipeline.view_all' AS `name`, 'View All Pipelines' AS `display_name`, 'View every pipeline in the system' AS `description`, 'ticket' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'pipeline.view_all');

INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'pipeline.claim' AS `name`, 'Claim Pipeline Stages' AS `display_name`, 'Accept (claim) a stage assigned to your team' AS `description`, 'ticket' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'pipeline.claim');

INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'pipeline.act' AS `name`, 'Act on Pipeline Stages' AS `display_name`, 'Complete / advance the stage you own' AS `description`, 'ticket' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'pipeline.act');

INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'pipeline.reassign' AS `name`, 'Reassign Pipeline Stages' AS `display_name`, 'Change the owner (user/role) of a stage' AS `description`, 'ticket' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'pipeline.reassign');

INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'pipeline.cancel' AS `name`, 'Cancel Pipelines' AS `display_name`, 'Cancel a pipeline at any stage' AS `description`, 'ticket' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'pipeline.cancel');

INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'pipeline.manage' AS `name`, 'Manage Pipelines' AS `display_name`, 'Bypass all pipeline restrictions (superuser)' AS `description`, 'ticket' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'pipeline.manage');

-- ------------------------------------------------------------
-- 2. Grant each pipeline.* permission to the roles that already hold the
--    equivalent ticket.* permission. Data-driven (no hardcoded role ids)
--    and idempotent. Grants are staged in a TEMPORARY table first so the
--    final INSERT never reads the role_permissions target inside a
--    subquery (avoids MySQL/MariaDB error 1093 on stricter versions).
-- ------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `_pipeline_grants`;
CREATE TEMPORARY TABLE `_pipeline_grants` (`role_id` INT NOT NULL, `permission_id` INT NULL);

-- Helper pattern: for a (new_perm, source_perm) pair, copy the grant set.
INSERT INTO `_pipeline_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'pipeline.template_view' ORDER BY `id` LIMIT 1)
FROM `role_permissions` rp
WHERE rp.`granted` = 1 AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'ticket.create');

INSERT INTO `_pipeline_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'pipeline.template_manage' ORDER BY `id` LIMIT 1)
FROM `role_permissions` rp
WHERE rp.`granted` = 1 AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'ticket.manage');

INSERT INTO `_pipeline_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'pipeline.create' ORDER BY `id` LIMIT 1)
FROM `role_permissions` rp
WHERE rp.`granted` = 1 AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'ticket.create');

INSERT INTO `_pipeline_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'pipeline.view_own' ORDER BY `id` LIMIT 1)
FROM `role_permissions` rp
WHERE rp.`granted` = 1 AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'ticket.view_own');

INSERT INTO `_pipeline_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'pipeline.view_all' ORDER BY `id` LIMIT 1)
FROM `role_permissions` rp
WHERE rp.`granted` = 1 AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'ticket.view_all');

INSERT INTO `_pipeline_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'pipeline.claim' ORDER BY `id` LIMIT 1)
FROM `role_permissions` rp
WHERE rp.`granted` = 1 AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'ticket.deploy');

INSERT INTO `_pipeline_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'pipeline.act' ORDER BY `id` LIMIT 1)
FROM `role_permissions` rp
WHERE rp.`granted` = 1 AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'ticket.deploy');

INSERT INTO `_pipeline_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'pipeline.reassign' ORDER BY `id` LIMIT 1)
FROM `role_permissions` rp
WHERE rp.`granted` = 1 AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'ticket.assign');

INSERT INTO `_pipeline_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'pipeline.cancel' ORDER BY `id` LIMIT 1)
FROM `role_permissions` rp
WHERE rp.`granted` = 1 AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'ticket.cancel');

INSERT INTO `_pipeline_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'pipeline.manage' ORDER BY `id` LIMIT 1)
FROM `role_permissions` rp
WHERE rp.`granted` = 1 AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'ticket.manage');

-- Drop grants that already exist (idempotency) and any that resolved to NULL.
DELETE g FROM `_pipeline_grants` g
JOIN `role_permissions` e ON e.`role_id` = g.`role_id` AND e.`permission_id` = g.`permission_id`;
DELETE FROM `_pipeline_grants` WHERE `permission_id` IS NULL;

-- Apply the remaining grants (source is the temp table only — no 1093 risk).
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `granted`)
SELECT `role_id`, `permission_id`, 1 FROM `_pipeline_grants`;

DROP TEMPORARY TABLE IF EXISTS `_pipeline_grants`;

-- ============================================================
-- Verification (optional, run after the seeder):
--
--   SELECT name, category FROM permissions WHERE name LIKE 'pipeline.%' ORDER BY name;
--   SELECT r.name AS role, p.name AS perm
--     FROM role_permissions rp
--     JOIN roles r ON r.id = rp.role_id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.name LIKE 'pipeline.%' ORDER BY r.name, p.name;
--
-- Expected: 10 pipeline.* permissions; grants mirror ticket.* grants
-- for every non-admin role. (super_admin/admin bypass ACL in code.)
-- ============================================================
