-- ============================================================
-- Seeder : 2026_07_13_001_add-finding2-finalize-edges
-- Unit   : Finding 2 fix (owner-authorized), migration/03-state-machines
--          (config_status_transitions, owned by U-SM.2)
-- Purpose: config_status_transitions only had ONE edge into 'finalized'
--          (validated -> finalized), forcing the full linear chain
--          draft -> building -> validating -> validated -> finalized.
--          Legacy ServerBuilder::finalizeConfiguration() has NO such
--          precondition at all when StateGuard::mode() is off (production's
--          default) -- it finalizes from whatever status_v2 the config is
--          currently sitting at, gated only by validateConfiguration()'s
--          component-completeness check. TransitionStatusCommand (the new
--          command-layer path, live once COMMAND_LAYER_ENABLED=enforce)
--          calls StateMachine::assertConfigTransition() UNCONDITIONALLY
--          (no flag gate), so a real fleet config sitting at draft/building
--          (the two statuses production configs are actually found in,
--          per finalize_command_test.php's own fixture comment) would be
--          silently BLOCKED from finalizing the moment COMMAND_LAYER_ENABLED
--          flips to enforce -- a regression vs. legacy, not a new
--          restriction anyone asked for. This is "Finding 2".
-- Fix    : add draft->finalized and building->finalized edges, same
--          required_permission/requires_validation as the existing
--          validated->finalized edge (server.finalize / full) -- legacy
--          always runs its own full validateConfiguration() check
--          regardless of starting status, so requires_validation='full'
--          mirrors that exactly. validating->finalized is intentionally
--          NOT added: it is one step short of 'validated' in the chain and
--          not named in Finding 2's own scope (draft/building only); revisit
--          separately if a real fleet config is ever found sitting at
--          'validating' when this matters.
-- Tables : config_status_transitions (2 new rows)
-- Notes  : Idempotent (INSERT IGNORE against the existing PRIMARY KEY
--          (from_status, to_status)). Safe to re-run.
-- ============================================================

INSERT IGNORE INTO `config_status_transitions` (`from_status`, `to_status`, `required_permission`, `requires_validation`) VALUES
  ('draft',    'finalized', 'server.finalize', 'full'),
  ('building', 'finalized', 'server.finalize', 'full');

-- ============================================================
-- Verification (optional, run after the seeder):
--
--   SELECT COUNT(*) FROM config_status_transitions;  -- expect 14 (was 12)
--   SELECT * FROM config_status_transitions WHERE to_status = 'finalized';
--   -- expect 3 rows: draft, building, validated (all -> finalized, all
--   -- server.finalize/full)
-- ============================================================
