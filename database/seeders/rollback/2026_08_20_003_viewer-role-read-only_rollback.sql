-- =============================================================================
-- Rollback for: 2026_08_20_003_viewer-role-read-only.sql
-- Date:         2026-08-20
-- Tables:       role_permissions (role_id = 5 only)
--
-- Restores the `viewer` role to exactly the 90 permissions it held before the
-- lockdown, as captured in imsbdcmsbharatda_Ims_Production.sql (dump generated
-- 2026-08-18). It re-grants the 64 revoked permissions and drops pipeline.create,
-- which the forward seeder added.
--
-- WARNING: this puts server.create, server.delete_all, pipeline.manage,
-- permissions.manage and system.backup back on the DEFAULT role for every new
-- user. Only run it if the lockdown caused a problem you cannot fix forward, and
-- treat it as temporary.
--
-- NOTE: with the Requests module open to all roles, restoring pipeline.manage to
-- viewer means a viewer could reach the approval endpoints. The code guard in
-- PipelineManager::applyStageEffect() still requires the admin or super_admin
-- ROLE before any access is granted, so self-granting stays blocked -- but the
-- defence is then one layer deep instead of two.
-- =============================================================================

START TRANSACTION;

CREATE TEMPORARY TABLE IF NOT EXISTS `tmp_viewer_restore` (
    `name` VARCHAR(100) NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

TRUNCATE TABLE `tmp_viewer_restore`;

-- The 64 permissions 2026_08_20_003 revoked.
INSERT INTO `tmp_viewer_restore` (`name`) VALUES
    ('caddy.create'), ('caddy.delete'), ('caddy.edit'),
    ('chassis.create'), ('chassis.delete'), ('chassis.edit'),
    ('compatibility.manage_rules'), ('cpu.create'), ('cpu.delete'),
    ('cpu.edit'), ('dashboard.admin'), ('hbacard.create'),
    ('hbacard.delete'), ('hbacard.edit'), ('motherboard.create'),
    ('motherboard.delete'), ('motherboard.edit'), ('nic.create'),
    ('nic.delete'), ('nic.edit'), ('pciecard.create'),
    ('pciecard.delete'), ('pciecard.edit'), ('permissions.get_all'),
    ('permissions.manage'), ('pipeline.act'), ('pipeline.claim'),
    ('pipeline.manage'), ('pipeline.template_manage'), ('pipeline.view_all'),
    ('ram.create'), ('ram.delete'), ('ram.edit'),
    ('reports.create'), ('reports.export'), ('reports.schedule'),
    ('risercard.create'), ('risercard.delete'), ('risercard.edit'),
    ('server.create'), ('server.delete'), ('server.delete_all'),
    ('server.edit_all'), ('server.transition'), ('sfp.create'),
    ('sfp.delete'), ('sfp.edit'), ('storage.create'),
    ('storage.delete'), ('storage.edit'), ('system.backup'),
    ('system.logs'), ('system.maintenance'), ('system.manage_settings'),
    ('system.settings'), ('system.view_logs'), ('ticket.delete'),
    ('ticket.deploy'), ('ticket.edit_own'), ('ticket.manage'),
    ('ticket.reject'), ('ticket.view_all'), ('ticket.view_assigned'),
    ('ticket.view_own');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`, `granted`)
SELECT 5, p.id, 1
FROM `permissions` p
JOIN `tmp_viewer_restore` t ON t.`name` = p.`name`;

UPDATE `role_permissions` rp
JOIN `permissions` p ON p.id = rp.permission_id
JOIN `tmp_viewer_restore` t ON t.`name` = p.`name`
SET rp.`granted` = 1
WHERE rp.role_id = 5 AND rp.`granted` <> 1;

-- pipeline.create was ADDED by the forward seeder, so undo that too.
DELETE rp FROM `role_permissions` rp
JOIN `permissions` p ON p.id = rp.permission_id
WHERE rp.role_id = 5 AND p.`name` = 'pipeline.create';

DROP TEMPORARY TABLE IF EXISTS `tmp_viewer_restore`;

COMMIT;

-- Verification — expect 90.
SELECT COUNT(*) AS viewer_permission_count
FROM `role_permissions` WHERE `role_id` = 5 AND `granted` = 1;
