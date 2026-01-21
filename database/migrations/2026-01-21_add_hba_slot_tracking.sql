-- ============================================================================
-- Migration: Add HBA Card Slot Position Tracking
-- Issue: COMPAT-004 (Priority 2.2)
-- Date: 2026-01-21
-- ============================================================================
--
-- PURPOSE:
-- Track which PCIe slot on the motherboard the HBA card physically occupies.
-- Previously, HBA cards were stored as simple UUID with no slot position,
-- causing PCIe slot exhaustion to not be detected.
--
-- BEFORE:
-- hbacard_uuid VARCHAR(36) - Stores: "hba-001" (no slot position!)
--
-- AFTER:
-- hbacard_config JSON - Stores:
-- {
--   "uuid": "hba-001",
--   "slot_position": "pcie_x16_slot_1",  ← NEW!
--   "added_at": "2026-01-21 10:30:00",
--   "serial_number": "HBA-SN12345"
-- }
--
-- IMPACT:
-- - Prevents PCIe slot over-allocation (HBA cards consume PCIe slots)
-- - Enables accurate "available PCIe slots" reporting
-- - Improves server configuration validation
--
-- ROLLBACK:
-- To rollback, run: 2026-01-21_rollback_hba_slot_tracking.sql
--
-- ============================================================================

USE bdc_ims;

-- ============================================================================
-- STEP 1: Add new hbacard_config JSON column
-- ============================================================================

ALTER TABLE server_configurations
ADD COLUMN hbacard_config JSON DEFAULT NULL
COMMENT 'HBA card configuration with PCIe slot position tracking (Issue COMPAT-004)'
AFTER hbacard_uuid;

-- Verify column added
SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'bdc_ims'
  AND TABLE_NAME = 'server_configurations'
  AND COLUMN_NAME IN ('hbacard_uuid', 'hbacard_config');

-- ============================================================================
-- STEP 2: Migrate existing HBA assignments to new JSON format
-- ============================================================================

-- Count rows to migrate
SELECT COUNT(*) AS rows_to_migrate
FROM server_configurations
WHERE hbacard_uuid IS NOT NULL;

-- Perform migration
UPDATE server_configurations
SET hbacard_config = JSON_OBJECT(
    'uuid', hbacard_uuid,
    'slot_position', NULL,  -- Will be auto-assigned on next edit
    'added_at', NOW(),
    'serial_number', NULL,
    'migrated', TRUE,  -- Flag indicating this was migrated from old format
    'migration_date', NOW()
)
WHERE hbacard_uuid IS NOT NULL
  AND hbacard_config IS NULL;  -- Only migrate if not already done

-- Verify migration
SELECT
    config_uuid,
    server_name,
    hbacard_uuid AS old_format,
    JSON_EXTRACT(hbacard_config, '$.uuid') AS new_format_uuid,
    JSON_EXTRACT(hbacard_config, '$.slot_position') AS slot_position,
    JSON_EXTRACT(hbacard_config, '$.migrated') AS was_migrated
FROM server_configurations
WHERE hbacard_uuid IS NOT NULL
LIMIT 10;

-- ============================================================================
-- STEP 3: Backward Compatibility - Keep old column for 2 releases
-- ============================================================================

-- DO NOT drop hbacard_uuid column yet!
-- Keep it for backward compatibility for 2 release cycles
-- Application code will:
-- 1. Write to BOTH columns during transition
-- 2. Read from hbacard_config preferentially
-- 3. Fall back to hbacard_uuid if hbacard_config is NULL

-- Add comment to old column indicating deprecation
ALTER TABLE server_configurations
MODIFY COLUMN hbacard_uuid VARCHAR(36) DEFAULT NULL
COMMENT 'DEPRECATED: Use hbacard_config JSON column instead (will be removed in v1.3)';

-- ============================================================================
-- STEP 4: Add index for performance
-- ============================================================================

-- Add functional index on JSON uuid field (MySQL 5.7+)
-- This speeds up lookups by HBA UUID
CREATE INDEX idx_hbacard_config_uuid
ON server_configurations ((CAST(JSON_EXTRACT(hbacard_config, '$.uuid') AS CHAR(36))));

-- ============================================================================
-- STEP 5: Validation queries
-- ============================================================================

-- Check for rows with HBA but no slot position (need manual assignment)
SELECT
    config_uuid,
    server_name,
    JSON_EXTRACT(hbacard_config, '$.uuid') AS hba_uuid,
    JSON_EXTRACT(hbacard_config, '$.slot_position') AS slot_position,
    'NEEDS_SLOT_ASSIGNMENT' AS status
FROM server_configurations
WHERE hbacard_config IS NOT NULL
  AND JSON_EXTRACT(hbacard_config, '$.slot_position') IS NULL
ORDER BY created_at DESC;

-- Summary statistics
SELECT
    'Total Configurations' AS metric,
    COUNT(*) AS count
FROM server_configurations
UNION ALL
SELECT
    'Configurations with HBA',
    COUNT(*)
FROM server_configurations
WHERE hbacard_uuid IS NOT NULL OR hbacard_config IS NOT NULL
UNION ALL
SELECT
    'HBAs in old format (hbacard_uuid)',
    COUNT(*)
FROM server_configurations
WHERE hbacard_uuid IS NOT NULL AND hbacard_config IS NULL
UNION ALL
SELECT
    'HBAs in new format (hbacard_config)',
    COUNT(*)
FROM server_configurations
WHERE hbacard_config IS NOT NULL
UNION ALL
SELECT
    'HBAs with slot assigned',
    COUNT(*)
FROM server_configurations
WHERE hbacard_config IS NOT NULL
  AND JSON_EXTRACT(hbacard_config, '$.slot_position') IS NOT NULL;

-- ============================================================================
-- STEP 6: Update application version marker
-- ============================================================================

-- Track that this migration has been applied
CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration_name VARCHAR(255) NOT NULL UNIQUE,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    description TEXT,
    rollback_file VARCHAR(255)
);

INSERT INTO schema_migrations (migration_name, description, rollback_file)
VALUES (
    '2026-01-21_add_hba_slot_tracking',
    'Add hbacard_config JSON column for PCIe slot position tracking (Issue COMPAT-004)',
    '2026-01-21_rollback_hba_slot_tracking.sql'
);

-- ============================================================================
-- POST-MIGRATION STEPS (Manual)
-- ============================================================================

-- 1. Deploy application code changes (ServerBuilder.php, UnifiedSlotTracker.php)
-- 2. Test HBA addition on staging environment
-- 3. Monitor error logs for "HBA-TRACK:" prefixed messages
-- 4. After 2 releases (v1.3+), run cleanup migration to drop hbacard_uuid column

-- ============================================================================
-- MIGRATION COMPLETE
-- ============================================================================

SELECT 'Migration 2026-01-21_add_hba_slot_tracking COMPLETE' AS status;
SELECT NOW() AS completed_at;
