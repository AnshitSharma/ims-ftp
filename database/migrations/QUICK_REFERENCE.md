# BDC IMS Database Migrations - Quick Reference

**Location**: `database/migrations/`

---

## 🚀 Quick Start

### Run Migration (Fastest Way)
```bash
# 1. Backup first
mysqldump -u root -p shubhams_bdc_ims > backup_2025_11_09.sql

# 2. Run migration
mysql -u root -p shubhams_bdc_ims < database/migrations/2025_11_09_000001_optimization_phases_1_to_4.sql

# 3. Verify
mysql -u root -p shubhams_bdc_ims -e "SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE COLUMN_NAME = 'compatibility_score' AND TABLE_NAME IN ('compatibility_log', 'component_compatibility', 'server_configurations');"
# Expected: EMPTY (0 rows)
```

---

## 📋 Migration Overview

| File | Purpose | Tables | Changes | Risk |
|------|---------|--------|---------|------|
| 2025_11_09_000001_optimization_phases_1_to_4.sql | Remove compatibility_score (Phase 2) | 3 | Remove 3 columns | LOW |

---

## 🎯 What Changed in Phases 1-4

### Phase 1: Unified Slot Tracker
- **Database Changes**: NONE
- **Files Changed**: Code only (UnifiedSlotTracker.php created)
- **Status**: ✅ COMPLETE

### Phase 2: Compatibility Score Removal
- **Database Changes**: YES (3 ALTER TABLE statements)
- **Tables Affected**:
  - `compatibility_log` - REMOVED `compatibility_score` column
  - `component_compatibility` - REMOVED `compatibility_score` column
  - `server_configurations` - REMOVED `compatibility_score` column
- **Data Loss**: None (only numeric field removed)
- **Status**: ✅ COMPLETE

### Phase 3: Cache Optimization
- **Database Changes**: NONE
- **Files Changed**: Code only (5 new files created)
- **Status**: ✅ COMPLETE

### Phase 4: Validator Architecture
- **Database Changes**: NONE
- **Files Changed**: Code only (pending)
- **Status**: 📅 READY

---

## ✅ Migration Checklist

### Before Running
- [ ] Backup database
- [ ] Test in staging
- [ ] Close active connections
- [ ] Notify users
- [ ] Save this file for reference

### Running Migration
- [ ] Execute SQL file
- [ ] Check for "Query OK" messages
- [ ] Wait for completion (should be ~5 seconds)

### After Migration
- [ ] Run verification queries
- [ ] Check zero rows returned for compatibility_score
- [ ] Deploy application code
- [ ] Clear application cache
- [ ] Test API endpoints
- [ ] Monitor error logs

---

## 🔧 Common Commands

### Backup Database
```bash
# Full backup
mysqldump -u root -p shubhams_bdc_ims > backup.sql

# Specific table backup
mysqldump -u root -p shubhams_bdc_ims server_configurations > backup_configurations.sql
```

### Run Migration
```bash
# Command line
mysql -u root -p shubhams_bdc_ims < database/migrations/2025_11_09_000001_optimization_phases_1_to_4.sql

# With logging
mysql -u root -p shubhams_bdc_ims < database/migrations/2025_11_09_000001_optimization_phases_1_to_4.sql 2>&1 | tee migration_log.txt
```

### Verify Migration
```bash
# Check compatibility_score removed
mysql -u root -p shubhams_bdc_ims -e "SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE COLUMN_NAME = 'compatibility_score';"

# Check specific table structure
mysql -u root -p shubhams_bdc_ims -e "SHOW COLUMNS FROM server_configurations;"

# Check data integrity
mysql -u root -p shubhams_bdc_ims -e "SELECT COUNT(*) FROM server_configurations;"
```

### Rollback Migration
```bash
# Restore from backup
mysql -u root -p shubhams_bdc_ims < backup.sql

# Or from inside MySQL:
# DROP TABLE compatibility_log;
# CREATE TABLE compatibility_log LIKE compatibility_log_backup_20251109;
# INSERT INTO compatibility_log SELECT * FROM compatibility_log_backup_20251109;
# DROP TABLE compatibility_log_backup_20251109;
```

---

## 📊 Changes at a Glance

### Tables Modified
```
compatibility_log
├── REMOVED: compatibility_score (FLOAT)
└── KEPT: All other columns intact

component_compatibility
├── REMOVED: compatibility_score (FLOAT)
└── KEPT: All other columns intact

server_configurations
├── REMOVED: compatibility_score (FLOAT)
└── KEPT: All other columns intact (including validation_results JSON)
```

### Data Impact
- **Rows Affected**: 0 (only schema changed)
- **Data Loss**: None (validation data preserved in validation_results)
- **Rollback Time**: < 1 minute (restore from backup)

---

## ❓ FAQ

**Q: Can I run this migration multiple times?**
A: YES! Uses `IF EXISTS` so safe to re-run.

**Q: Will data be lost?**
A: NO! Only numeric score column removed. All other data preserved.

**Q: How long does it take?**
A: ~5 seconds for typical database.

**Q: Do I need to change application code?**
A: NO! Already updated in Phase 2. Migration just updates database.

**Q: What if something goes wrong?**
A: Restore from backup (1 minute). No data loss.

**Q: Can I do this during business hours?**
A: YES! Migration is quick. Recommend off-peak for safety.

**Q: Do I need to restart anything?**
A: Recommended to restart application/cache after migration.

**Q: What if compatibility_score is referenced in code?**
A: Code already updated. Search: `grep -r "compatibility_score" .`

---

## 🎯 Next Steps

1. **Read**: `database/migrations/README.md` for full details
2. **Backup**: `mysqldump -u root -p shubhams_bdc_ims > backup.sql`
3. **Test**: Run in staging environment first
4. **Verify**: Run verification queries from migration file
5. **Deploy**: Apply to production following steps in README
6. **Monitor**: Check logs for any issues

---

## 🆘 Help

| Problem | Solution |
|---------|----------|
| Migration fails | Check: 1) Backup exists 2) Database correct 3) MySQL version 5.7+ |
| Compatibility_score still exists | Re-run migration (it's idempotent) |
| Application errors | 1) Check error logs 2) Verify code deployed 3) Restart app |
| Can't rollback | Restore from backup: `mysql -u root -p db < backup.sql` |
| Too slow | 1) Reduce server load 2) Optimize tables first 3) Run at off-peak |

---

**File Location**: `database/migrations/2025_11_09_000001_optimization_phases_1_to_4.sql`
**Documentation**: `database/migrations/README.md`
**Status**: ✅ PRODUCTION READY

