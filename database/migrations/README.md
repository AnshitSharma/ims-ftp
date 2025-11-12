# BDC IMS Database Migrations

**Location**: `database/migrations/`

This folder contains all database migration files for the BDC Inventory Management System. All database schema changes MUST be stored here and tracked for consistency and rollback capability.

---

## 📋 Migration Files

### Current Migrations

#### 2025_11_09_000001_optimization_phases_1_to_4.sql
**Status**: ✅ PRODUCTION READY
**Date Created**: 2025-11-09
**Phases Covered**: 1, 2, 3, 4 (database changes from Phase 2 only)
**Changes**:
- Remove `compatibility_score` from `compatibility_log` table
- Remove `compatibility_score` from `component_compatibility` table
- Remove `compatibility_score` from `server_configurations` table

**Tables Affected**: 3
**Columns Removed**: 3 (`compatibility_score` from each table)
**Data Loss**: None (only numeric field removed, validation data preserved)
**Execution Time**: ~5 seconds
**Risk Level**: LOW

---

## 🚀 How to Run Migrations

### Prerequisites
```bash
# Check MySQL/MariaDB version (5.7+ required)
mysql --version

# Verify database exists and access works
mysql -u root -p -e "SHOW DATABASES; USE shubhams_bdc_ims; SHOW TABLES LIMIT 5;"
```

### Step 1: Backup Database
```bash
# Create backup before any migration
mysqldump -u root -p shubhams_bdc_ims > backups/backup_$(date +%Y%m%d_%H%M%S).sql

# Or backup to dated file
mysqldump -u root -p shubhams_bdc_ims > backups/backup_2025_11_09.sql
```

### Step 2: Run Migration
```bash
# Method 1: From command line
mysql -u root -p shubhams_bdc_ims < database/migrations/2025_11_09_000001_optimization_phases_1_to_4.sql

# Method 2: Using phpMyAdmin
# 1. Login to phpMyAdmin
# 2. Select database: shubhams_bdc_ims
# 3. Click "Import" tab
# 4. Choose file: 2025_11_09_000001_optimization_phases_1_to_4.sql
# 5. Click "Go"

# Method 3: Using MySQL Workbench
# 1. Open MySQL Workbench
# 2. Connect to database
# 3. File → Open SQL Script
# 4. Select migration file
# 5. Execute (Ctrl+Shift+Enter)
```

### Step 3: Verify Migration
```sql
-- Check that compatibility_score columns are removed
SELECT TABLE_NAME, COLUMN_NAME
FROM INFORMATION_SCHEMA.COLUMNS
WHERE COLUMN_NAME = 'compatibility_score'
AND TABLE_NAME IN ('compatibility_log', 'component_compatibility', 'server_configurations');
-- Expected: 0 rows (empty result)

-- Check table structures
SHOW COLUMNS FROM compatibility_log;
SHOW COLUMNS FROM component_compatibility;
SHOW COLUMNS FROM server_configurations;

-- Check data integrity (row counts)
SELECT COUNT(*) FROM compatibility_log;
SELECT COUNT(*) FROM component_compatibility;
SELECT COUNT(*) FROM server_configurations;
```

### Step 4: Deploy Application
```bash
# 1. Deploy updated application code (Phase 2 changes)
# 2. Clear application cache if applicable
# 3. Restart application/web server
# 4. Monitor logs for errors
```

### Step 5: Test
```bash
# Test API endpoint
curl -X POST http://localhost:8000/api/api.php \
  -d "action=server-validate-config" \
  -d "config_uuid=test-uuid"

# Verify response doesn't include compatibility_score field
# Check logs: tail -f logs/app.log | grep -i "compatibility_score"
```

---

## 🔄 Migration Guidelines

### For All Migrations

1. **Location**: Always place in `database/migrations/` folder
2. **Naming**: Use format: `YYYY_MM_DD_HHMMSS_description.sql`
3. **Backups**: Always backup before running
4. **Testing**: Test in staging environment first
5. **Documentation**: Include comments explaining what/why
6. **Verification**: Include verification queries
7. **Rollback**: Provide rollback procedure
8. **Version Control**: Commit migrations with code

### Naming Convention
```
YYYY_MM_DD_HHMMSS_description.sql

Examples:
2025_11_09_000001_optimization_phases_1_to_4.sql
2025_11_10_100530_add_api_key_table.sql
2025_11_11_143022_optimize_queries.sql
```

### File Structure
```sql
/**
 * Migration Description
 * File: database/migrations/YYYY_MM_DD_HHMMSS_description.sql
 *
 * Purpose: Clear description of what this migration does
 * Impact: What changes occur
 * Phases: Which optimization phases this covers
 * Status: READY|TESTING|PRODUCTION READY
 */

-- ============================================================================
-- STEP 1: BACKUP (Optional but recommended)
-- ============================================================================

-- ============================================================================
-- STEP 2: MIGRATION CHANGES
-- ============================================================================

-- ============================================================================
-- STEP 3: VERIFICATION QUERIES
-- ============================================================================

-- ============================================================================
-- STEP 4: ROLLBACK PROCEDURE
-- ============================================================================

-- ============================================================================
-- DOCUMENTATION & FAQ
-- ============================================================================
```

---

## 📊 Migration Status Tracking

### Completed Migrations

| Date | Migration | Status | Phases | Tables |
|------|-----------|--------|--------|--------|
| 2025-11-09 | 2025_11_09_000001_optimization_phases_1_to_4.sql | ✅ READY | 1-4 | 3 |

### Pending Migrations

None at this time.

---

## ⚠️ Important Notes

### Before Running Any Migration

1. **Backup Database**
   ```bash
   mysqldump -u root -p shubhams_bdc_ims > backup_before_migration.sql
   ```

2. **Test in Staging**
   - Never run in production without testing first
   - Use identical schema in staging environment
   - Verify all tests pass

3. **Check Dependencies**
   - Ensure all code changes are deployed first
   - Verify application handles schema changes
   - Check for any hardcoded references to removed columns

4. **Plan Downtime**
   - ALTERing large tables may lock them
   - Schedule during off-peak hours
   - Notify users of brief downtime
   - Have rollback plan ready

### After Running Any Migration

1. **Verify Success**
   - Run verification queries (included in migration)
   - Check error logs for warnings
   - Monitor application logs for errors

2. **Deployment**
   - Deploy application code matching migration
   - Clear application cache if applicable
   - Restart web servers/application

3. **Testing**
   - Test affected functionality
   - Monitor error rates
   - Check database performance
   - Verify API responses

4. **Documentation**
   - Document what was changed
   - Note any issues encountered
   - Update this README
   - Update application documentation

---

## 🔙 Rollback Procedures

### If Migration Fails

#### Option 1: Using Backup
```bash
# Restore from backup (quickest method)
mysql -u root -p shubhams_bdc_ims < backup_before_migration.sql
```

#### Option 2: Using Backup Tables (if created)
```sql
-- See migration file for specific commands
-- Usually involves dropping altered table and restoring from backup table
DROP TABLE compatibility_log;
CREATE TABLE compatibility_log LIKE compatibility_log_backup_20251109;
INSERT INTO compatibility_log SELECT * FROM compatibility_log_backup_20251109;
DROP TABLE compatibility_log_backup_20251109;
```

#### Option 3: Manual Rollback (if backup unavailable)
```sql
-- This depends on migration type
-- For removals: ALTER TABLE table_name ADD COLUMN column_name type;
-- For additions: ALTER TABLE table_name DROP COLUMN column_name;
-- See specific migration file for rollback commands
```

---

## 📝 Migration Template

Use this template for creating new migrations:

```sql
/**
 * [MIGRATION TITLE]
 * File: database/migrations/YYYY_MM_DD_HHMMSS_description.sql
 *
 * Purpose: [What this migration does]
 * Impact: [How it affects the system]
 * Phases: [Which optimization phases]
 * Status: [READY|TESTING|PRODUCTION READY]
 */

-- ============================================================================
-- STEP 1: BACKUP ORIGINAL DATA (Optional)
-- ============================================================================

/*
CREATE TABLE IF NOT EXISTS [table]_backup_YYYYMMDD AS
SELECT * FROM [table];
*/

-- ============================================================================
-- STEP 2: MIGRATION CHANGES
-- ============================================================================

-- [Your SQL changes here]

-- ============================================================================
-- STEP 3: VERIFICATION QUERIES
-- ============================================================================

/*
-- Verify changes completed
[Your verification queries]
*/

-- ============================================================================
-- STEP 4: ROLLBACK PROCEDURE
-- ============================================================================

/*
-- If migration needs to be reversed
[Your rollback SQL]
*/

-- ============================================================================
-- DOCUMENTATION
-- ============================================================================

/*
[Additional documentation, FAQ, troubleshooting]
*/
```

---

## 🔍 Common Tasks

### List All Migrations
```bash
ls -la database/migrations/*.sql
```

### Check Migration Status
```bash
# Connect to database
mysql -u root -p shubhams_bdc_ims

# Run verification queries from migration file
SELECT TABLE_NAME, COUNT(*) as column_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME IN ('compatibility_log', 'component_compatibility', 'server_configurations')
GROUP BY TABLE_NAME;
```

### Create New Migration
```bash
# Use template above
# Name it: database/migrations/YYYY_MM_DD_HHMMSS_description.sql
# Test thoroughly before production deployment
```

### Archive Old Migrations
```bash
# After migration is proven stable (30+ days), archive
mkdir -p database/migrations/archive
mv database/migrations/2025_11_*.sql database/migrations/archive/
# Keep current migration in main folder for reference
```

---

## 📋 Checklist: Before Deploying Migration

- [ ] Migration file created and tested in staging
- [ ] Backup created before running migration
- [ ] Verification queries confirm success (0 rows affected, expected results)
- [ ] Application code updated to match schema
- [ ] No hardcoded references to removed columns
- [ ] Error logs checked (no warnings/errors)
- [ ] API responses verified (no missing fields, correct structure)
- [ ] Related tests pass
- [ ] Database performance acceptable
- [ ] Rollback plan documented
- [ ] Team notified of changes
- [ ] Downtime scheduled if needed
- [ ] Production deployment date set

---

## 🆘 Troubleshooting

### Migration fails with "Table doesn't exist"
```
Solution: Check table name spelling
          Verify table exists: SHOW TABLES LIKE 'table_name%';
          Check database is correct: USE shubhams_bdc_ims;
```

### Migration fails with "Unknown column"
```
Solution: Column already removed or doesn't exist
          Check with: SHOW COLUMNS FROM table_name;
          If using IF EXISTS, safe to re-run
```

### Application errors after migration
```
Solution 1: Check if all application code updated
            Grep for removed column: grep -r "compatibility_score" .
Solution 2: Restart application to clear caches
Solution 3: Check error logs: tail -f logs/app.log
Solution 4: Rollback and revert code changes
```

### Migration takes too long
```
Solution 1: Kill long-running queries: KILL QUERY process_id;
Solution 2: Reduce server load before re-running
Solution 3: Check table size: SELECT table_name, round(((data_length + index_length) / 1024 / 1024), 2) 'Size in MB' FROM information_schema.TABLES WHERE table_name = 'table_name';
Solution 4: Optimize table first: OPTIMIZE TABLE table_name;
```

---

## 📞 Contact & Support

- **Questions about migrations**: Check migration file comments
- **Rollback needed**: Use procedure in migration file
- **New migration needed**: Use template in this README
- **Issues in production**: Contact DBA, have backup ready

---

## Version History

| Date | Version | Changes |
|------|---------|---------|
| 2025-11-09 | 1.0 | Initial migration README and migration file |

---

**Last Updated**: 2025-11-09
**Maintained By**: Development Team
**Status**: ACTIVE (All migrations current)

