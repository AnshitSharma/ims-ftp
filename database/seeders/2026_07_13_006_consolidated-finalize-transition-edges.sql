-- ============================================================
-- Seeder : 2026_07_13_006_consolidated-finalize-transition-edges
-- Date   : 2026-07-13
-- Purpose: CONSOLIDATED seeder (owner request) — carries the full content of
--          the previously-written, never-run 2026_07_13_001 (Finding 2 fix).
--          That file is marked SUPERSEDED via header comment; run THIS file
--          instead (running both is harmless — INSERT IGNORE — but pick one).
--
--          Finding 2: config_status_transitions only had ONE edge into
--          'finalized' (validated -> finalized), forcing the full linear
--          chain draft -> building -> validating -> validated -> finalized.
--          Legacy ServerBuilder::finalizeConfiguration() has NO such
--          precondition when StateGuard::mode() is off (production default) —
--          it finalizes from whatever status_v2 the config currently sits at,
--          gated only by validateConfiguration()'s completeness check.
--          TransitionStatusCommand (live once COMMAND_LAYER_ENABLED=enforce)
--          calls StateMachine::assertConfigTransition() UNCONDITIONALLY, so a
--          real fleet config sitting at draft/building (where production
--          configs are actually found) would be silently BLOCKED from
--          finalizing the moment the flag flips — a regression vs legacy.
-- Fix    : add draft->finalized and building->finalized edges with the same
--          required_permission/requires_validation as the existing
--          validated->finalized edge (server.finalize / full). The
--          server.finalize permission itself is created and granted by
--          companion seeder 2026_07_13_005 — run BOTH files.
--          validating->finalized is intentionally NOT added (out of Finding
--          2's scope; revisit only if a fleet config is ever found sitting at
--          'validating').
-- Tables : config_status_transitions (2 new rows)
-- Notes  : Idempotent (INSERT IGNORE against the PRIMARY KEY
--          (from_status, to_status)). Safe to re-run.
-- Feature: State machines (U-SM.2 + Finding 2, migration/03-state-machines).
-- ============================================================

INSERT IGNORE INTO `config_status_transitions` (`from_status`, `to_status`, `required_permission`, `requires_validation`) VALUES
  ('draft',    'finalized', 'server.finalize', 'full'),
  ('building', 'finalized', 'server.finalize', 'full');

-- ============================================================
-- Verification (run after the seeder):
--
--   SELECT COUNT(*) FROM config_status_transitions;  -- expect 14 (was 12)
--   SELECT * FROM config_status_transitions WHERE to_status = 'finalized';
--   -- expect 3 rows: draft, building, validated (all -> finalized, all
--   -- server.finalize/full)
--
-- Rollback (manual, if ever needed):
--   DELETE FROM config_status_transitions
--    WHERE to_status = 'finalized' AND from_status IN ('draft', 'building');
-- ============================================================
