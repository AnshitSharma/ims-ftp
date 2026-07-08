-- ============================================================================
-- Date:     2026-06-11
-- Purpose:  Remove the legacy `acl_permissions` table. The live ACL system
--           (ACL class, hasPermission/loadUserPermissionData, role grants)
--           reads exclusively from `permissions`; `acl_permissions` was a
--           parallel table with an independent ID sequence. The API functions
--           assignPermissionToUser/revokePermissionFromUser/getAllPermissions
--           used to resolve names against it, so direct user grants made via
--           acl-assign_permission landed on user_permissions rows whose
--           permission_id pointed into the WRONG ID space (silently inert, or
--           worse, resolving to an unintended permission in `permissions`).
--           Those functions now use `permissions` (BaseFunctions.php), making
--           this table fully unreferenced.
-- Affected: user_permissions (orphan cleanup), acl_permissions (dropped)
-- Related:  ACL single-source-of-truth consolidation (api refactor 2026-06-11)
--
-- Verified against the 2026-06 production dump: all existing user_permissions
-- rows (user 38, ids 49884-49891) already resolve correctly against
-- `permissions`, so no data remap is required — only orphan cleanup.
-- ============================================================================

-- 1. Remove user_permissions rows whose permission_id does not exist in
--    `permissions`. These rows are inert today (the permission loader inner-
--    joins against `permissions`), so deleting them changes no effective
--    access; it only prevents future ID-collision surprises.
DELETE up
FROM user_permissions up
LEFT JOIN permissions p ON p.id = up.permission_id
WHERE p.id IS NULL;

-- 2. Drop the legacy table.
DROP TABLE IF EXISTS acl_permissions;
