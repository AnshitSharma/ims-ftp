-- ============================================================
-- Rollback for: 2026_07_06_001_create-config-components
-- ============================================================
-- Safe: config_components is not yet written by any application code path
-- in this unit (DUAL_WRITE_ENABLED defaults off; nothing reads/writes this
-- table until later units). Dropping it is a pure no-op on legacy behavior.
-- ============================================================

DROP TABLE IF EXISTS `config_components`;
