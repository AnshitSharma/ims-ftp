-- =============================================================================
-- Date:     2026-08-20
-- Purpose:  Make the `viewer` role (id 5) actually read-only.
--
--           Despite its description ("Read-only access to inventory data"),
--           viewer currently holds 90 of the 116 permissions -- including
--           server.create, server.delete, server.delete_all, server.edit_all,
--           pipeline.manage, permissions.manage, system.backup,
--           system.maintenance and full create/edit/delete on all 11 component
--           types. Read-only in name only.
--
--           This matters beyond tidiness. `viewer` is is_default = 1, so it is
--           what every newly registered user receives. And the temporary-access
--           feature this ships alongside is meaningless while viewers already
--           hold server.create -- there would be nothing to request.
--
--           It also closes a live escalation: the Requests module is now open to
--           every role (so anyone can raise a request), and viewer holds
--           pipeline.manage, which would let a viewer APPROVE THEIR OWN access
--           request. Until this seeder runs, the only thing preventing that is
--           the code guard in PipelineManager::applyStageEffect(), which requires
--           the approver to hold the admin or super_admin role.
--
-- Tables:   role_permissions (role_id = 5 only)
-- Feature:  Temporary approval-gated server-build access (Requests module)
--
-- !! BLAST RADIUS !! This changes what EVERY current viewer-role user can do,
--    and what every future new user starts with. 64 permissions are revoked and
--    1 (pipeline.create) is granted. Nothing outside role_permissions is touched
--    -- no user is deleted, no role is removed, no other role changes. Rerunning
--    is safe. Reversible via the rollback script.
--
-- Notes:    - Written declaratively: the keep-list below is the ENTIRE intended
--             permission set for viewer. The DELETE removes everything else, so
--             this seeder is idempotent AND self-correcting -- rerunning it after
--             a drifted manual grant puts viewer back to exactly this list.
--           - Roles admin (2), manager (3) and technician (4) have the same
--             problem (115 / 111 / 108 permissions) and are DELIBERATELY LEFT
--             ALONE here. Tightening them is a separate decision with its own
--             blast radius. admin and super_admin are unaffected in practice
--             regardless: hasPermission() gives them a blanket bypass.
--           - pipeline.view_own is kept and pipeline.create added so a viewer can
--             raise a request and follow it. pipeline.act / .claim / .manage /
--             .view_all / .template_manage are revoked: viewers ask, they do not
--             approve.
--           - server.view_all and server.view_statistics are KEPT on purpose.
--             They are reads, and dropping them would blank the servers list for
--             every existing viewer -- a regression nobody asked for.
--           - All ticket.* are revoked: the legacy linear ticket engine is
--             retired and those permissions are inert. Housekeeping.
--           - reports.export / .create / .schedule are revoked as writes. If
--             viewers are expected to export, re-add reports.export in a NEW
--             seeder rather than editing this one.
--           - Uses no information_schema: the application DB user has no access
--             to that schema on this host.
--           - Rollback: rollback/2026_08_20_003_viewer-role-read-only_rollback.sql
--
-- RUN THIS LAST. Apply 001, 002, deploy the code, apply 004, verify the whole
-- request -> approve -> build loop, and only then run this. That ordering means
-- nobody is locked out of a feature that is not yet working.
-- =============================================================================

START TRANSACTION;

-- The complete intended permission set for `viewer`, by name.
CREATE TEMPORARY TABLE IF NOT EXISTS `tmp_viewer_keep` (
    `name` VARCHAR(100) NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

TRUNCATE TABLE `tmp_viewer_keep`;

INSERT INTO `tmp_viewer_keep` (`name`) VALUES
    -- Sign in and manage your own password
    ('auth.login'), ('auth.logout'), ('auth.change_password'),
    -- Read every component type (11)
    ('cpu.view'), ('ram.view'), ('storage.view'), ('motherboard.view'),
    ('nic.view'), ('caddy.view'), ('chassis.view'), ('pciecard.view'),
    ('risercard.view'), ('hbacard.view'), ('sfp.view'),
    -- Read servers and their compatibility results
    ('server.view'), ('server.view_all'), ('server.view_statistics'),
    ('compatibility.check'), ('compatibility.view_statistics'),
    -- Navigate the app
    ('dashboard.view'), ('search.global'), ('search.advanced'),
    ('reports.view'), ('users.view'), ('roles.view'),
    -- Raise a request (e.g. for temporary build access) and follow it
    ('pipeline.create'), ('pipeline.view_own');

-- ---------------------------------------------------------------------------
-- 1. Revoke everything not on the keep-list (64 rows as of 2026-08-18)
-- ---------------------------------------------------------------------------
DELETE rp FROM `role_permissions` rp
JOIN `permissions` p ON p.id = rp.permission_id
WHERE rp.role_id = 5
  AND p.name NOT IN (SELECT `name` FROM `tmp_viewer_keep`);

-- ---------------------------------------------------------------------------
-- 2. Grant anything on the keep-list that is missing (pipeline.create)
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`, `granted`)
SELECT 5, p.id, 1
FROM `permissions` p
JOIN `tmp_viewer_keep` k ON k.`name` = p.`name`;

-- Any keep-list entry with granted = 0 (there are none today, but be explicit)
UPDATE `role_permissions` rp
JOIN `permissions` p ON p.id = rp.permission_id
JOIN `tmp_viewer_keep` k ON k.`name` = p.`name`
SET rp.`granted` = 1
WHERE rp.role_id = 5 AND rp.`granted` <> 1;

DROP TEMPORARY TABLE IF EXISTS `tmp_viewer_keep`;

COMMIT;

-- ---------------------------------------------------------------------------
-- Verification — expect 26 rows, and NONE of server.create / pipeline.manage /
-- permissions.manage / system.backup among them.
-- ---------------------------------------------------------------------------
SELECT COUNT(*) AS viewer_permission_count
FROM `role_permissions` WHERE `role_id` = 5 AND `granted` = 1;

SELECT p.`name`
FROM `role_permissions` rp
JOIN `permissions` p ON p.id = rp.permission_id
WHERE rp.`role_id` = 5 AND rp.`granted` = 1
ORDER BY p.`name`;
