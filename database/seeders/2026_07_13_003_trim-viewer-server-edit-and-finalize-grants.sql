-- ============================================================
-- Seeder : 2026_07_13_003_trim-viewer-server-edit-and-finalize-grants
-- Date   : 2026-07-13
-- Purpose: Owner decision (tenth session, 2026-07-13) on the viewer over-grant
--          advisory raised by the ninth-session verify record: `viewer`
--          currently holds `server.edit` (confirmed live in the scratch DB:
--          role_permissions has admin/super_admin/viewer all granted
--          server.edit) -- a read-only role should not be able to mutate a
--          server configuration. Owner picked TRIM: revoke viewer's
--          `server.edit` grant, and make sure viewer never inherits the new
--          `server.finalize` permission either (seeder 2026_07_13_002 mirrors
--          whichever roles hold server.edit AT THE TIME IT RUNS -- since
--          seeder run order is not guaranteed to place this file after
--          2026_07_13_002, both grants are revoked here explicitly rather
--          than relying on 002 to "just not pick viewer up").
-- Tables : role_permissions (deletes only, 0-2 rows)
-- Notes  : Idempotent. Safe to re-run (a DELETE...JOIN matching zero rows is
--          a no-op). Safe regardless of whether 2026_07_13_002 has already
--          run: if it ran first and granted viewer server.finalize (mirroring
--          its then-current server.edit grant), the second DELETE below
--          removes that grant too; if it runs after this seeder, viewer no
--          longer holds server.edit at that point so 002's own dynamic
--          mirror query will not pick viewer up in the first place -- either
--          run order converges to the same end state (viewer holds neither).
--          Does NOT touch admin/super_admin, and does NOT touch
--          server.replace/server.transition (out of this decision's scope --
--          only server.edit/server.finalize were flagged).
-- Feature: Owner decision on the ninth-session verify's viewer advisory
--          (migration/handoffs/SESSION-20260712-FINDINGS-ABC.md, ninth-session
--          verify record). Companion seeder 2026_07_13_004 records the
--          resulting correction to 2026_07_13_002's own footer "Expected"
--          comment (that file is never edited directly, per this repo's
--          seeder rule).
-- ============================================================

DELETE rp FROM `role_permissions` rp
JOIN `roles` r ON r.`id` = rp.`role_id` AND r.`name` = 'viewer'
JOIN `permissions` p ON p.`id` = rp.`permission_id` AND p.`name` = 'server.edit';

DELETE rp FROM `role_permissions` rp
JOIN `roles` r ON r.`id` = rp.`role_id` AND r.`name` = 'viewer'
JOIN `permissions` p ON p.`id` = rp.`permission_id` AND p.`name` = 'server.finalize';

-- ============================================================
-- Verification (optional, run after the seeder):
--
--   SELECT r.name AS role, p.name AS perm
--     FROM role_permissions rp
--     JOIN roles r ON r.id = rp.role_id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.name IN ('server.edit', 'server.finalize') ORDER BY r.name, p.name;
--
-- Expected: only admin and super_admin rows for server.edit; server.finalize
-- (once 2026_07_13_002 has also been run) granted to admin and super_admin
-- only -- viewer appears in neither list.
-- ============================================================
