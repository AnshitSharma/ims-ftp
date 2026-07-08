-- ============================================================
-- Rollback for: 2026_07_10_002_create-status-transitions
-- Safe to run any time while STATE_MACHINE_ENABLED=off (nothing reads these
-- tables yet in this unit).
-- ============================================================

DROP TABLE IF EXISTS `config_status_transitions`;
DROP TABLE IF EXISTS `inventory_status_transitions`;
