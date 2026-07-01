-- ============================================================
-- Seeder : 2026_06_24_002_drop-constraint-state-migration-artifacts
-- Date   : 2026-06-24
-- Purpose: Remove the database artifacts of the abandoned "ConstraintState"
--          compatibility-engine migration (audit L3). The parallel engine
--          (ConstraintStateCompatibilityAdapter / ServerConfigConstraintState /
--          ConstraintStateRepository / ConstraintDecision) was a dormant,
--          never-cut-over shadow path gated by the COMPATIBILITY_DUALRUN_LOG /
--          COMPATIBILITY_DUALRUN_WRITE / COMPATIBILITY_READS / COMPATIBILITY_WRITES
--          env flags (all OFF by default). The PHP classes and all ServerBuilder
--          wiring have been deleted; these orphaned DB objects are dropped here.
-- Tables : server_configurations (drop 3 columns), compatibility_dualrun_log (drop table)
-- Feature: Compatibility & validation remediation - dead-engine removal (L3).
--
-- SAFETY:
--   * Idempotent: every statement is guarded with IF EXISTS (MariaDB 10.6+).
--   * Data loss is intentional and safe: these columns/table only ever held the
--     shadow engine's cached blob and its dual-run comparison log. The
--     authoritative configuration data lives in the existing JSON line-item
--     columns (cpu_configuration, ram_configuration, storage_configuration,
--     nic_config, hbacard_config, pciecard_configurations, sfp_configuration, ...)
--     which are untouched. Nothing reads these objects any more.
--
-- Notes  : Run once against the server database. Safe to re-run.
-- ============================================================

-- 1) Drop the three shadow-state columns from server_configurations.
--    (constraint_state held the serialized blob; the other two its version/timestamp.)
ALTER TABLE `server_configurations`
    DROP COLUMN IF EXISTS `constraint_state`,
    DROP COLUMN IF EXISTS `constraint_state_version`,
    DROP COLUMN IF EXISTS `constraint_state_updated_at`;

-- 2) Drop the dual-run comparison log table (only written by the removed adapter).
DROP TABLE IF EXISTS `compatibility_dualrun_log`;
