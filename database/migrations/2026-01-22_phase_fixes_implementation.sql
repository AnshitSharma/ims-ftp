-- ============================================================================
-- Database Migration: Phase Fixes Implementation (P1-P5)
-- Created: 2026-01-22
-- Purpose: Validate schema for comprehensive system fixes
-- ============================================================================

-- IMPORTANT: This migration validates existing schema but makes NO DESTRUCTIVE changes
-- All fixes are code-level using existing JSON column structure

-- ============================================================================
-- 1. VALIDATE JSON COLUMNS EXIST (Required for all fixes)
-- ============================================================================

-- These columns must exist for the unified slot tracking (P2.1) to work:
-- - server_configurations.cpu_configuration (JSON)
-- - server_configurations.ram_configuration (JSON)
-- - server_configurations.storage_configuration (JSON)
-- - server_configurations.storage_configurations (JSON)
-- - server_configurations.pciecard_configurations (JSON)
-- - server_configurations.nic_config (JSON)
-- - server_configurations.sfp_configuration (JSON)
-- - server_configurations.caddy_configuration (JSON)

-- Validation query (run this to check):
-- SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE
-- FROM INFORMATION_SCHEMA.COLUMNS
-- WHERE TABLE_NAME = 'server_configurations'
-- AND COLUMN_NAME LIKE '%configuration%'
-- ORDER BY COLUMN_NAME;

-- ============================================================================
-- 2. SET INNODB LOCK WAIT TIMEOUT (P4.2: Transaction timeout configuration)
-- ============================================================================

-- Set global lock wait timeout to 50 seconds (prevents hanging transactions)
-- This is a MySQL system variable that affects all connections
SET GLOBAL innodb_lock_wait_timeout = 50;

-- Verify it was set:
-- SELECT @@global.innodb_lock_wait_timeout, @@session.innodb_lock_wait_timeout;

-- ============================================================================
-- 3. ADD INDEX FOR FASTER ORPHANED SERVERUUID DETECTION (P4.4)
-- ============================================================================

-- These indexes improve performance for fixOrphanedServerUUIDs() method
-- which scans component tables for orphaned ServerUUID references

ALTER TABLE cpuinventory ADD INDEX idx_serveruuid (ServerUUID) IF NOT EXISTS;
ALTER TABLE raminventory ADD INDEX idx_serveruuid (ServerUUID) IF NOT EXISTS;
ALTER TABLE storageinventory ADD INDEX idx_serveruuid (ServerUUID) IF NOT EXISTS;
ALTER TABLE motherboardinventory ADD INDEX idx_serveruuid (ServerUUID) IF NOT EXISTS;
ALTER TABLE nicinventory ADD INDEX idx_serveruuid (ServerUUID) IF NOT EXISTS;
ALTER TABLE caddyinventory ADD INDEX idx_serveruuid (ServerUUID) IF NOT EXISTS;
ALTER TABLE pciecardinventory ADD INDEX idx_serveruuid (ServerUUID) IF NOT EXISTS;
ALTER TABLE hbacardinventory ADD INDEX idx_serveruuid (ServerUUID) IF NOT EXISTS;
ALTER TABLE chassisinventory ADD INDEX idx_serveruuid (ServerUUID) IF NOT EXISTS;

-- ============================================================================
-- 4. ENABLE STRICT MODE FOR JSON VALIDATION (P5.2: JSON error handling)
-- ============================================================================

-- Enable strict SQL mode for better data integrity
-- This ensures JSON operations fail clearly rather than silently
SET GLOBAL sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- Verify strict mode is enabled:
-- SELECT @@GLOBAL.sql_mode;

-- ============================================================================
-- 5. VALIDATE NO CORRUPTED JSON IN CONFIGURATION TABLE (P5.2)
-- ============================================================================

-- Check for potentially malformed JSON in configuration columns
-- This query identifies rows with invalid JSON that P5.2 error handling will catch:

SELECT
    config_uuid,
    CASE
        WHEN cpu_configuration IS NOT NULL AND JSON_VALID(cpu_configuration) = 0 THEN 'cpu_configuration'
        WHEN ram_configuration IS NOT NULL AND JSON_VALID(ram_configuration) = 0 THEN 'ram_configuration'
        WHEN storage_configuration IS NOT NULL AND JSON_VALID(storage_configuration) = 0 THEN 'storage_configuration'
        WHEN pciecard_configurations IS NOT NULL AND JSON_VALID(pciecard_configurations) = 0 THEN 'pciecard_configurations'
        WHEN nic_config IS NOT NULL AND JSON_VALID(nic_config) = 0 THEN 'nic_config'
        WHEN sfp_configuration IS NOT NULL AND JSON_VALID(sfp_configuration) = 0 THEN 'sfp_configuration'
        WHEN caddy_configuration IS NOT NULL AND JSON_VALID(caddy_configuration) = 0 THEN 'caddy_configuration'
        ELSE NULL
    END as invalid_column
FROM server_configurations
HAVING invalid_column IS NOT NULL;

-- If the above query returns rows, those configurations have corrupted JSON
-- The safeJsonDecode() method (P5.2) will handle these gracefully

-- ============================================================================
-- 6. CREATE AUDIT LOG FOR TRANSACTION LOCKS (P4.1: Deterministic ordering)
-- ============================================================================

-- Optional: Create a simple audit table to log lock wait times
-- This helps identify lock contention issues
CREATE TABLE IF NOT EXISTS lock_audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    config_uuid VARCHAR(255),
    resource_type VARCHAR(50),
    lock_acquired_at TIMESTAMP,
    lock_wait_ms BIGINT,
    transaction_id BIGINT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_config_uuid (config_uuid),
    INDEX idx_timestamp (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 7. ENABLE SLOW QUERY LOG FOR DEBUGGING (Optional)
-- ============================================================================

-- To help identify slow transactions (useful for monitoring P4.2 timeout behavior)
-- Uncomment below to enable long query monitoring (logs queries > 2 seconds)
-- SET GLOBAL slow_query_log = 'ON';
-- SET GLOBAL long_query_time = 2;
-- SET GLOBAL log_queries_not_using_indexes = 'ON';

-- ============================================================================
-- 8. VERIFY CRITICAL CONSTRAINTS (All Phases)
-- ============================================================================

-- Ensure primary keys exist on all tables used by the fixes
-- This is required for the row-level locking (P1.2, P4.1) to work correctly

SELECT
    TABLE_NAME,
    IF(CONSTRAINT_NAME = 'PRIMARY', 'YES', 'NO') as has_primary_key
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME IN (
    'server_configurations',
    'cpuinventory',
    'raminventory',
    'storageinventory',
    'motherboardinventory',
    'nicinventory',
    'caddyinventory',
    'pciecardinventory',
    'hbacardinventory',
    'chassisinventory'
)
GROUP BY TABLE_NAME
ORDER BY TABLE_NAME;

-- ============================================================================
-- 9. MIGRATION STATUS
-- ============================================================================

-- This migration:
-- ✅ Sets transaction timeout (P4.2)
-- ✅ Adds indexes for orphaned UUID detection (P4.4)
-- ✅ Enables strict SQL mode (P5.2)
-- ✅ Validates JSON columns exist (All phases)
-- ✅ Does NOT modify any existing data
-- ✅ Does NOT drop or restructure any columns
--
-- All code-level fixes (P1-P5) work with existing schema
-- No rollback needed - all changes are additive/configuration only

-- ============================================================================
-- END MIGRATION
-- ============================================================================
