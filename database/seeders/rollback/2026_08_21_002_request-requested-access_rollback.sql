-- =============================================================================
-- Rollback for: 2026_08_21_002_request-requested-access.sql
-- Date:         2026-08-21
-- Tables:       tickets
--
-- Safe: with the column gone, approval falls back to granting the step's whole
-- effect_config (the Phase 1 behaviour). No grant already issued is affected.
-- Run the 2026_08_21_003 rollback first if the flexible request type is still
-- present -- without requested_access it would grant its entire ceiling on every
-- approval, which is wider than anyone asked for.
-- =============================================================================

ALTER TABLE `tickets`
    DROP COLUMN IF EXISTS `requested_access`;

SHOW COLUMNS FROM `tickets`;
