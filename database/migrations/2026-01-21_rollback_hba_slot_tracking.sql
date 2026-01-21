-- ============================================================================
-- Rollback Migration: HBA Card Slot Position Tracking
-- Issue: COMPAT-004 (Priority 2.2)
-- Date: 2026-01-21
-- ============================================================================
--
-- PURPOSE:
-- Rollback the hbacard_config JSON column changes if issues detected
--
-- WARNING:
-- This will remove all slot position assignments for HBA cards!
-- Only run this if critical issues found in production.
--
-- ============================================================================

USE bdc_ims;

-- ============================================================================
-- STEP 1: Verify what will be lost
-- ============================================================================

SELECT
    'ROLLBACK PREVIEW - Data that will be lost:' AS warning;

SELECT
    config_uuid,
    server_name,
    JSON_EXTRACT(hbacard_config, '$.uuid') AS hba_uuid,
    JSON_EXTRACT(hbacard_config, '$.slot_position') AS slot_position_WILL_BE_LOST,
    JSON_EXTRACT(hbacard_config, '$.serial_number') AS serial_number_WILL_BE_LOST
FROM server_configurations
WHERE hbacard_config IS NOT NULL;

-- ============================================================================
-- STEP 2: Restore old format (migrate back)
-- ============================================================================

-- Restore hbacard_uuid from JSON for rows that only have new format
UPDATE server_configurations
SET hbacard_uuid = JSON_UNQUOTE(JSON_EXTRACT(hbacard_config, '$.uuid'))
WHERE hbacard_config IS NOT NULL
  AND hbacard_uuid IS NULL;

-- Verify restoration
SELECT
    config_uuid,
    server_name,
    hbacard_uuid AS restored_uuid,
    hbacard_config AS will_be_dropped
FROM server_configurations
WHERE hbacard_config IS NOT NULL;

-- ============================================================================
-- STEP 3: Drop new column and index
-- ============================================================================

-- Drop index first
DROP INDEX IF EXISTS idx_hbacard_config_uuid ON server_configurations;

-- Drop new column
ALTER TABLE server_configurations
DROP COLUMN hbacard_config;

-- ============================================================================
-- STEP 4: Restore old column comment
-- ============================================================================

ALTER TABLE server_configurations
MODIFY COLUMN hbacard_uuid VARCHAR(36) DEFAULT NULL
COMMENT 'HBA card UUID (single HBA per configuration)';

-- ============================================================================
-- STEP 5: Remove migration record
-- ============================================================================

DELETE FROM schema_migrations
WHERE migration_name = '2026-01-21_add_hba_slot_tracking';

-- ============================================================================
-- VERIFICATION
-- ============================================================================

-- Verify structure restored
SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'bdc_ims'
  AND TABLE_NAME = 'server_configurations'
  AND COLUMN_NAME = 'hbacard_uuid';

-- Verify data restored
SELECT COUNT(*) AS hba_configs_restored
FROM server_configurations
WHERE hbacard_uuid IS NOT NULL;

-- ============================================================================
-- ROLLBACK COMPLETE
-- ============================================================================

SELECT 'Rollback 2026-01-21_rollback_hba_slot_tracking COMPLETE' AS status;
SELECT 'CRITICAL: Application code must be rolled back to match!' AS warning;
SELECT NOW() AS completed_at;
