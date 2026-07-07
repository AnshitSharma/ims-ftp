-- ============================================================
-- Rollback for: 2026_07_08_001_create-backfill-tables
-- ============================================================
-- Safe: both tables are pure bookkeeping for scripts/backfill/backfill.php
-- and are never read by any production request path. Dropping them is a
-- pure no-op on legacy behavior. Any in-flight backfill run's progress is
-- lost (re-run --dry-run then --execute with a fresh --run-id to restart).
-- ============================================================

DROP TABLE IF EXISTS `backfill_quarantine`;
DROP TABLE IF EXISTS `migration_backfill_state`;
