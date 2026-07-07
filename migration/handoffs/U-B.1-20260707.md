# Handoff — U-B.1 — 2026-07-07

## Current State
`scripts/backfill/backfill.php` exists: safe bulk-migration machinery (state tracking, dry-run,
resume, quarantine, rollback-run) with a stub Extractor that quarantines EVERY legacy component
entry it finds (reason `extractor-not-implemented`) — nothing is written to `config_components`/
`config_resources` yet. `migration_backfill_state` and `backfill_quarantine` tables exist (seeder
`2026_07_08_001`, paired rollback). This is the FIRST unit in P2 (`07-component-migration`), whose
gate depends on P1+PL (both open). This session did **exactly one unit** and left `phase-status.json`
at `implemented`, not `verified` — that flip is for a separate session, per the established
implemented/verified split.

## Completed Work
- `database/seeders/2026_07_08_001_create-backfill-tables.sql` (new) + paired
  `database/seeders/rollback/2026_07_08_001_create-backfill-tables_rollback.sql` (new): creates
  `migration_backfill_state` (PK = `(run_id, config_uuid)`, enforcing one state row per config per
  run) and `backfill_quarantine` (surrogate PK, since one config can produce many quarantine rows).
- `scripts/backfill/backfill.php` (new): four modes —
  - default (dry-run): keyset-paginated plan over non-virtual configs, zero DB writes (no
    transaction opened at all).
  - `--execute [--run-id <id>]`: per config, `SELECT ... FOR UPDATE` the config row inside its own
    transaction, extract legacy entries (stub: `extractLegacyEntries()`, mirrors
    `scripts/audit-orphans.php::extractRefs()`), quarantine every entry found (or mark `done` if
    none), commit. A per-config exception rolls back that config's transaction only and records
    `status='error'` — the run continues.
  - `--resume --run-id <id>`: re-attempts only `pending`/`error` configs for that run; `done`
    configs are re-verified via `equivalence_report.php --config` (idempotence — never
    re-inserted); `quarantined` configs are left alone (terminal for this stub).
  - `--rollback-run <id>`: deletes any `config_components`/`config_resources` rows the run created
    (via `config_events.payload.run_id` — forward-compatible with U-B.2's real Extractor; always
    finds zero today since nothing is ever inserted by the stub), those `config_events` rows, its
    `backfill_quarantine` rows, and its `migration_backfill_state` rows.
  - All modes accept `--config <uuid>` to scope to one config.
- `scripts/backfill/README.md` (new): operator runbook covering the dry-run → execute → resume →
  rollback-run workflow, the 24h `DUAL_WRITE_ENABLED=on` precondition for a real live run, and how
  to inspect quarantine.

## Remaining Work
(empty for U-B.1 itself — unit complete. The real Extractor — U-B.2 — is the next unit; until it
lands, `--execute` only ever quarantines, never migrates.)

## Known Risks / Interpretation calls made
- `extractLegacyEntries()` mirrors `scripts/audit-orphans.php::extractRefs()` exactly (the only
  file this unit's pack authorized reading for this pattern) and deliberately does NOT include
  `equivalence_report.php`'s `hbacard_uuid`/`hbacard_config` dedup refinement (that file was out of
  this unit's read scope) — a config with BOTH populated could quarantine `hbacard` twice under
  today's stub. Low-stakes today (everything is quarantined regardless, just possibly duplicated
  once), but **U-B.2's real Extractor must apply the dedup correctly** or it will attempt to insert
  the same physical unit twice, which `uq_inventory_once` would then correctly reject — not a
  silent-corruption risk, just something to get right the first time rather than debug via a
  constraint violation.
- `--rollback-run`'s component/ledger cleanup path (deleting rows found via
  `config_events.payload.run_id`) is untestable against REAL rows in this unit, since the stub
  Extractor never inserts any — it is forward-compatible dead code today, only exercised in this
  session's acceptance run against its trivial (zero-row) case. U-B.2 should re-verify this path
  once real inserts exist.
- Found and fixed a real bug during development: `reverifyDoneConfig()` initially closed the
  child `equivalence_report.php` process's stdout/stderr pipes without draining them first via
  `stream_get_contents()`. This is a genuine SIGPIPE race (reproduced directly: the exact same
  `proc_open` call returned exit 0 when pipes were read before closing, and exit 255 when they
  were closed unread) — fixed by draining both pipes before `fclose()`/`proc_close()`, matching
  the pattern `orphan_report.php`/`equivalence_report.php` already use correctly. Confirmed fixed:
  `--resume` on a `done` config now reliably reports `verified=1, errors=0`.
- `migration_backfill_state`/`backfill_quarantine` have no hard FK to `server_configurations`
  (documented in the seeder's own notes) — deliberate, since `--rollback-run` must be able to clean
  these up independently of the config's own lifecycle.
- The revision/`config_events` accounting implication of `--rollback-run` hard-deleting a
  `config_components` row that had bumped `server_configurations.revision` (INV-6) is NOT solved
  here — today it's unreachable (stub never bumps revision, since it never calls
  `ConfigComponentRepository::insert()`), but whichever unit makes `--execute` do real inserts
  (U-B.2 or later) must also decide how `--rollback-run` un-bumps revision correctly, or accept
  revision numbers with gaps (INV-6's mechanical check only requires `revision == MAX(config_events.revision)`,
  which gaps do not violate, but this deserves an explicit decision, not a default).

## Invariant Check Results
| Invariant | Result |
|---|---|
| INV-9 (seeder/rollback pairing) | PASS — `for f in database/seeders/2026_0[7-9]*_*.sql; do test -f rollback/... ; done` printed nothing. |
| INV-11 (unit box) | PASS — 2 files created (`backfill.php`, `backfill/README.md`), 1 seeder + 1 paired rollback, 1 architectural concept (backfill machinery). |
| INV-1 (one physical unit = one row) | N/A this unit — the stub never inserts any `config_components` row; `grep -rn "'quantity'" core/models/config/ core/models/commands/ core/models/validation/` unaffected by this unit's files (none of them live under those paths). |

## Acceptance Test Results
- `php -l scripts/backfill/backfill.php` → No syntax errors detected.
- Dry-run (default, `--config <fixture>`): plan JSON written to `reports/backfill-plan-*.json`;
  `server_configurations` row-count + revision checksum identical before/after (confirmed via
  `SELECT COUNT(*), MD5(GROUP_CONCAT(...))`); zero `migration_backfill_state`/`backfill_quarantine`
  rows after.
- `--execute --config <fixture>` (2-entry config: RAM + motherboard): both entries quarantined,
  `reason=extractor-not-implemented`, state row `quarantined`. Empty config (no legacy entries) in
  the same run: state row `done`, zero quarantine rows.
- `--resume --run-id <id>` (no `--config`, scoped to the run automatically): `quarantined` config
  skipped untouched; `done` config re-verified via `equivalence_report.php --config` → `verified=1,
  errors=0` (after fixing the SIGPIPE bug above).
- `--rollback-run <id>`: `migration_backfill_state`/`backfill_quarantine` rows for the run both go
  to 0; `config_components` stays 0 (nothing was ever inserted); `server_configurations` rows for
  the fixture configs are untouched (rollback never touches that table).
- `for f in database/seeders/2026_0[7-9]*_*.sql; do test -f rollback/...; done` → prints nothing
  (INV-9 pass).
- `php scripts/verify/run_all.php --quick` → exit 0, all 4 reports GREEN.
- `php scripts/verify/run_all.php --gate PL` → exit 0 (unaffected by this unit — schema/ledger
  GREEN, regression SKIPPED-by-design).
- Full existing regression/unit suite re-run and unaffected: `dual_write_test.php`,
  `ledger_dual_write_test.php`, `config_component_repository_test.php`, `resource_catalog_test.php`,
  `finalized_immutability_test.php`, `nested_transaction_test.php` → ALL PASS.
- `php tests/characterize_compatibility.php` (golden-master pin, flag off) → exit 0, 0
  configs/0 replays (no production dump); baseline diff appeared transiently as expected (same
  every session — no production dump means every run regenerates a trivial 0/0 file) and was
  restored via `git checkout --` immediately after (confirmed clean via `git status --porcelain`).
- All fixture configs/state/quarantine rows deleted after testing; scratch MariaDB stopped.

## Next Prompt To Use
"Continue the IMS migration. Read migration/00-overview/README.md, migration/ARCHITECTURAL_INVARIANTS.md,
then either (a) run an independent verify pass on U-B.1 (per this project's implemented/verified
split — do NOT let the same session both implement and verify), or (b) if U-B.1 is already verified,
execute unit U-B.2 using migration/07-component-migration/execution-packs/U-B.2.md — this is the
real Extractor that replaces U-B.1's 'quarantine everything' stub. Read
migration/handoffs/U-B.1-20260707.md first, especially the hbacard-dedup gap in
extractLegacyEntries() (fix it properly in the real Extractor) and the revision/rollback-run
interaction that was left as an open decision (harmless while nothing is inserted, but U-B.2 changes
that). ONE unit only. Follow migration/00-overview/SESSION_PROTOCOL.md exactly."

## Files To Load Into Context (next session)
- migration/00-overview/README.md
- migration/ARCHITECTURAL_INVARIANTS.md
- migration/handoffs/U-B.1-20260707.md (this file)
- migration/07-component-migration/execution-packs/U-B.2.md (if proceeding to implement)
- scripts/backfill/backfill.php
- core/models/config/ConfigComponentRepository.php

## Expected Context Size
~30k tokens
