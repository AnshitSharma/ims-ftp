-- ============================================================
-- Seeder : 2026_06_24_001_audit-stale-storage-and-slotless-cards-diagnostic
-- Date   : 2026-06-24
-- Purpose: Identify the stale states predicted by the compatibility audit
--          (COMPATIBILITY_VALIDATION_AUDIT.md §7) that already exist in live
--          data: storage persisted as `not_connected` (H9) and PCIe / NIC /
--          HBA cards persisted with no `slot_position` (C4).
-- Tables : server_configurations (read-only diagnostics)
-- Feature: Compatibility & validation remediation — stale-data cleanup (§7).
--
-- WHY THIS IS A DIAGNOSTIC, NOT AN IN-PLACE FIX:
--   The affected state lives INSIDE the JSON columns
--   (storage_configuration.[].connection, *_config[].slot_position).
--   Recomputing a storage connection path or assigning a PCIe slot requires
--   the application's validation engine together with the ims-data JSON specs
--   (backplane / HBA capability, slot sizes, lane budget). Pure SQL cannot
--   reproduce that logic correctly, so attempting an in-SQL "fix" would write
--   wrong connections / slots. The root causes are now fixed in code
--   (C4: no-free-slot blocking + pciecard slot persistence; H3: NVMe path;
--   H9: not_connected handling), so NEW configurations will not accumulate
--   these states.
--
-- HOW TO REMEDIATE EXISTING ROWS:
--   1. Run the SELECTs below to list the affected config_uuids.
--   2. For each, re-run server validation / re-save the configuration through
--      the (now-fixed) application so connection paths and slot assignments are
--      recomputed against ims-data. (server-validate-config / re-add the
--      affected component.)
--
-- Notes  : Read-only. Idempotent. Safe to run any number of times.
-- ============================================================

-- 1) Server configurations that contain at least one storage device persisted
--    with connection type `not_connected` (H9).
SELECT
    config_uuid,
    server_name,
    configuration_status,
    updated_at
FROM `server_configurations`
WHERE storage_configuration LIKE '%not_connected%'
ORDER BY updated_at DESC;

-- 2) Server configurations holding PCIe / NIC / HBA cards with an explicitly
--    null or empty slot_position (C4 — cards added without a slot assignment).
--    LIKE patterns cover the common serializations of a missing slot position.
SELECT
    config_uuid,
    server_name,
    configuration_status,
    updated_at
FROM `server_configurations`
WHERE pciecard_configurations LIKE '%"slot_position":null%'
   OR pciecard_configurations LIKE '%"slot_position":""%'
   OR pciecard_configurations LIKE '%"slot_position": null%'
   OR nic_config             LIKE '%"slot_position":null%'
   OR nic_config             LIKE '%"slot_position":""%'
   OR nic_config             LIKE '%"slot_position": null%'
   OR hbacard_config         LIKE '%"slot_position":null%'
   OR hbacard_config         LIKE '%"slot_position":""%'
   OR hbacard_config         LIKE '%"slot_position": null%'
ORDER BY updated_at DESC;

-- 3) Quick counts for a remediation summary.
SELECT
    SUM(storage_configuration LIKE '%not_connected%')                AS configs_with_not_connected_storage,
    SUM(
        pciecard_configurations LIKE '%"slot_position":null%' OR pciecard_configurations LIKE '%"slot_position":""%'
     OR nic_config             LIKE '%"slot_position":null%' OR nic_config             LIKE '%"slot_position":""%'
     OR hbacard_config         LIKE '%"slot_position":null%' OR hbacard_config         LIKE '%"slot_position":""%'
    )                                                                AS configs_with_slotless_cards
FROM `server_configurations`;
