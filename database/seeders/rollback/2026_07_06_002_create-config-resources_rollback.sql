-- ============================================================
-- Rollback for: 2026_07_06_002_create-config-resources
-- ============================================================
-- Safe: config_resources is not yet written by any application code path
-- in this unit. Dropping it is a pure no-op on legacy behavior.
-- ============================================================

DROP TABLE IF EXISTS `config_resources`;
