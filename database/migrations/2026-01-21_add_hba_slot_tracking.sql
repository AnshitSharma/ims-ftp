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
DESCRIBE server_configurations;

-- ============================================================================
-- STEP 2: Migrate existing HBA assignments to new JSON format
-- ============================================================================

-- Perform migration
UPDATE server_configurations
SET hbacard_config = JSON_OBJECT(
    'uuid', hbacard_uuid,
    'slot_position', NULL,
    'added_at', NOW(),
    'serial_number', NULL,
    'migrated', TRUE,
    'migration_date', NOW()
)
WHERE hbacard_uuid IS NOT NULL
  AND hbacard_config IS NULL;

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
-- POST-MIGRATION STEPS (Manual)
-- ============================================================================

-- 1. Deploy application code changes (ServerBuilder.php, UnifiedSlotTracker.php)
-- 2. Test HBA addition on staging environment
-- 3. Monitor error logs for "HBA-TRACK:" prefixed messages
-- 4. After 2 releases (v1.3+), run cleanup migration to drop hbacard_uuid column
