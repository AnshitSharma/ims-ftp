-- ============================================================
-- Seeder : 2026_07_25_001_revoke-wildcard-user-role-write-grants
-- Date   : 2026-07-25
-- Purpose: Corrective counterpart to the ACL wildcard fix in
--          core/auth/ACL.php::assignRolePermissions() (audit finding H).
--
--          The bootstrap permission sets granted 'manager' and 'technician'
--          the wildcards '*.create' and '*.edit'. Those expand to
--          LIKE '%.create' / '%.edit', which ALSO match users.create,
--          users.edit and any roles.* write permission. So a manager (and a
--          technician) could create and edit USER ACCOUNTS.
--
--          That the over-grant was accidental is visible in the permission
--          set itself: it separately lists 'users.view' and 'roles.view' by
--          name. Enumerating read access explicitly only makes sense if the
--          wildcards were never intended to convey write access to those
--          namespaces.
--
--          The code fix prevents this from being re-created, but
--          assignRolePermissions() only runs at bootstrap (when the roles
--          table is empty). If production was ever bootstrapped through that
--          path, the bad grants are already in role_permissions and no code
--          change removes them. This seeder removes them.
--
-- Tables : role_permissions (deletes only)
-- Notes  : Idempotent -- a DELETE ... JOIN matching zero rows is a no-op, so
--          this is safe to re-run and safe on an installation that was seeded
--          from SQL rather than bootstrapped (where it will simply match
--          nothing).
--
--          Scope is deliberately narrow:
--            * only the users.* and roles.* namespaces
--            * only NON-view permissions -- '.view' grants are left exactly as
--              they are, matching the code fix, which also leaves '*.view'
--              untouched. Widening or narrowing read access is a policy
--              decision, not part of closing this escalation.
--            * admin and super_admin are excluded; they are meant to hold
--              these permissions.
-- ============================================================

-- Step 1: revoke users.* / roles.* WRITE grants from every non-admin role.
DELETE rp
FROM role_permissions rp
JOIN roles r      ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE r.name NOT IN ('admin', 'super_admin')
  AND (p.name LIKE 'users.%' OR p.name LIKE 'roles.%')
  AND p.name NOT LIKE '%.view';

-- ============================================================
-- VERIFICATION (run manually after applying; expects ZERO rows)
--
--   SELECT r.name AS role_name, p.name AS permission_name
--   FROM role_permissions rp
--   JOIN roles r       ON r.id = rp.role_id
--   JOIN permissions p ON p.id = rp.permission_id
--   WHERE r.name NOT IN ('admin', 'super_admin')
--     AND (p.name LIKE 'users.%' OR p.name LIKE 'roles.%')
--     AND p.name NOT LIKE '%.view'
--   ORDER BY r.name, p.name;
--
-- To see what this seeder WOULD remove before running it, execute the query
-- above first -- it is the exact inverse of the DELETE.
--
-- Read access is intentionally still present; this should still return the
-- users.view / roles.view rows for whichever roles hold them:
--
--   SELECT r.name AS role_name, p.name AS permission_name
--   FROM role_permissions rp
--   JOIN roles r       ON r.id = rp.role_id
--   JOIN permissions p ON p.id = rp.permission_id
--   WHERE (p.name LIKE 'users.%' OR p.name LIKE 'roles.%')
--   ORDER BY r.name, p.name;
-- ============================================================
