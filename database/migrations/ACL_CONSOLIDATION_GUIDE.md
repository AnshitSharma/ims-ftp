# ACL Tables Consolidation Guide

## 📋 File Changes

- [database/migrations/consolidate_acl_tables.sql](../migrations/consolidate_acl_tables.sql) - Migration script created
- [core/helpers/BaseFunctions.php:303](../../core/helpers/BaseFunctions.php#L303) - Updated getUserRoles() to use `roles` table
- [core/helpers/BaseFunctions.php:412](../../core/helpers/BaseFunctions.php#L412) - Updated getAllRoles() to use `roles` table
- [core/helpers/BaseFunctions.php:447](../../core/helpers/BaseFunctions.php#L447) - Updated createRole() to use `roles` table
- [core/helpers/BaseFunctions.php:470](../../core/helpers/BaseFunctions.php#L470) - Updated updateRole() to use `roles` table
- [core/helpers/BaseFunctions.php:497](../../core/helpers/BaseFunctions.php#L497) - Updated deleteRole() to use `roles` table

## 📊 Problem Summary

**Issue:** Dual ACL system causing confusion and errors
- `acl_roles` table (old system) - Contains role ID 9 (admin)
- `roles` table (new system) - Missing role ID 9
- API endpoint `roles-get` queried `roles` table only → Role ID 9 not found ❌
- Role ID 3806 worked because it exists in `roles` table ✅

## 🔧 Solution

**Consolidated into single unified system using `roles` table**

### Why keep `roles` over `acl_roles`?
1. Better schema: `display_name`, `is_system`, `is_default`, `updated_at` fields
2. Used by modern ACL.php class
3. Used by active roles_api.php endpoint
4. More feature-rich and maintainable

## 📝 Migration Steps

### 1. Backup Database
```bash
mysqldump -u username -p shubhams_ims_dev > backup_before_acl_migration.sql
```

### 2. Run Migration Script
```bash
mysql -u username -p shubhams_ims_dev < database/migrations/consolidate_acl_tables.sql
```

**What the migration does:**
- Migrates missing roles from `acl_roles` to `roles` (including role ID 9)
- Converts `role_name` → `name`
- Generates `display_name` from role_name
- Verifies migration success
- Drops old `acl_roles` table

### 3. Code Changes (Already Applied)

**BaseFunctions.php updated to use new schema:**
- ✅ All queries now use `roles` instead of `acl_roles`
- ✅ Column names updated: `role_name` → `name`, added `display_name`
- ✅ Insert/Update queries include `display_name` auto-generation
- ✅ All role functions now compatible with unified system

## 🧪 Testing Checklist

After migration, test:

1. **Role retrieval:**
   ```bash
   curl -X POST "http://localhost:8000/api/api.php" \
     -H "Authorization: Bearer YOUR_TOKEN" \
     -d "action=roles-get" \
     -d "id=9"
   ```
   Expected: ✅ Role ID 9 details returned

2. **List all roles:**
   ```bash
   curl -X POST "http://localhost:8000/api/api.php" \
     -H "Authorization: Bearer YOUR_TOKEN" \
     -d "action=roles-list"
   ```
   Expected: ✅ All roles including ID 9 listed

3. **User role assignment:**
   - Verify existing user-role assignments still work
   - Test assigning role ID 9 to a user

## 📈 Migration Verification

**Before Migration:**
```
acl_roles: IDs 1, 3, 4, 5, 6, 9
roles:     IDs 1, 2, 3, 4, 5, 3806
```

**After Migration:**
```
roles: IDs 1, 2, 3, 4, 5, 6, 9, 3806 (unified)
acl_roles: DROPPED ✅
```

## 🔄 Rollback Instructions

If issues occur:

```bash
# Restore from backup
mysql -u username -p shubhams_ims_dev < backup_before_acl_migration.sql

# Revert code changes
git checkout HEAD -- core/helpers/BaseFunctions.php
```

## ⚠️ Important Notes

1. **Foreign Keys:** `user_roles` and `role_permissions` tables already reference `roles` table, not `acl_roles`
2. **Permissions:** The `acl_permissions` table remains unchanged (separate from roles)
3. **ACL.php:** Modern ACL class already uses `roles` table - no changes needed
4. **API Endpoints:** roles_api.php already uses `roles` table - no changes needed

## ✅ Benefits

- Single source of truth for roles
- No more confusion between two tables
- Role ID 9 now accessible via API
- Consistent schema across all role operations
- Better maintainability going forward
