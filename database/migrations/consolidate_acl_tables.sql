-- ============================================================================
-- ACL Tables Consolidation Migration
-- ============================================================================
-- Purpose: Consolidate acl_roles into roles table (single unified system)
-- Date: 2025-11-29
-- ============================================================================

-- BACKUP NOTE: Before running this migration, backup your database:
-- mysqldump -u username -p shubhams_ims_dev > backup_before_acl_migration.sql

START TRANSACTION;

-- ============================================================================
-- STEP 1: Migrate missing roles from acl_roles to roles
-- ============================================================================

-- Insert role ID 9 (admin) from acl_roles that doesn't exist in roles
-- Using INSERT IGNORE to skip if somehow it already exists
INSERT IGNORE INTO `roles` (`id`, `name`, `display_name`, `description`, `is_default`, `is_system`, `created_at`, `updated_at`)
SELECT
    `id`,
    `role_name` as `name`,
    CONCAT(UPPER(SUBSTRING(`role_name`, 1, 1)), SUBSTRING(REPLACE(`role_name`, '_', ' '), 2)) as `display_name`,
    `description`,
    0 as `is_default`,
    0 as `is_system`,
    `created_at`,
    `created_at` as `updated_at`
FROM `acl_roles`
WHERE `id` NOT IN (SELECT `id` FROM `roles`);

-- ============================================================================
-- STEP 2: Verify data migration
-- ============================================================================

-- Check if role ID 9 now exists in roles table
SELECT
    CASE
        WHEN EXISTS (SELECT 1 FROM `roles` WHERE `id` = 9)
        THEN 'SUCCESS: Role ID 9 migrated to roles table'
        ELSE 'ERROR: Role ID 9 NOT found in roles table'
    END as migration_status;

-- Show all roles in both tables for comparison
SELECT 'acl_roles (OLD)' as source, id, role_name as name, description FROM acl_roles
UNION ALL
SELECT 'roles (NEW)' as source, id, name, description FROM roles
ORDER BY source, id;

-- ============================================================================
-- STEP 3: Drop old acl_roles table
-- ============================================================================

DROP TABLE IF EXISTS `acl_roles`;

COMMIT;

-- ============================================================================
-- ROLLBACK INSTRUCTIONS
-- ============================================================================
-- If something goes wrong, run:
-- ROLLBACK;
-- Then restore from backup:
-- mysql -u username -p shubhams_ims_dev < backup_before_acl_migration.sql
