# 00 — Overview: How To Execute This Migration

Read this file first in EVERY session. It is deliberately short enough to always fit in context.

## What this migration does
Moves IMS from JSON-blob component storage + scattered validation to:
row-per-physical-unit schema, resource ledger, lifecycle state machines, a single ValidationEngine,
and an atomic command layer — as specified in `IMS_TARGET_ARCHITECTURE.md` (do NOT load that file
during implementation sessions; each execution pack quotes the parts you need).

## Phase ↔ folder map (execution order)
| Order | Phase | Folder | Concept |
|---|---|---|---|
| 1 | P0 stop-the-bleeding | 01-foundation | fail-closed + immutability guards + verify harness |
| 2 | P1 schema introduction | 02-schema | new tables, dual-write plumbing (flag OFF) |
| 3 | PL resource ledger | 06-resource-ledger | provider/consumer capacity rows |
| 4 | P2 backfill | 07-component-migration | JSON → rows, idempotent/resumable/dry-run |
| 5 | P3 state machines | 03-state-machines | status_v2 + transition tables (flag) |
| 6 | P4 validation engine | 04-validation-engine (U-V.*) | engine skeleton, shadow mode, diff reports |
| 7 | P5 rule migration | 04-validation-engine (U-R.*) | one rule family per unit |
| 8 | P6 command layer | 05-command-layer | commands underneath old APIs |
| 9 | P7 API migration | 08-api-adapters | adapters, shims, deprecation warnings |
| 10 | P8 read cutover | 09-cutover | reads from rows, verification battery |
| 11 | P9 legacy deletion | 10-cleanup | prove unused, delete, drop JSON columns |
| 12 | P10 post-cutover | 12-post-cutover | CI invariants, monitoring |
| — | verification (continuous) | 11-verification | report specs used by every gate |

Folders 06 and 07 are intentionally executed between 02 and 03 (see PLAN_VERIFICATION_REVIEW.md,
finding F-2). The folder numbers are namespaces, not the execution order. THE TABLE ABOVE AND
phase-status.json ARE THE EXECUTION ORDER.

## Hard rules for every session (summary — full version in SESSION_PROTOCOL.md)
1. One session = one execution pack = one unit. Never start a second unit.
2. Load ONLY: this README, ARCHITECTURAL_INVARIANTS.md, your pack, and the files/line-ranges the
   pack lists. ServerBuilder.php is 8,132 lines — NEVER load it whole; packs give line ranges.
3. A unit's box: ≤5 files changed, ≤500 LOC, ≤1 seeder, 1 concept (INV-11).
4. Finish = code + tests green + invariant checks + phase-status.json updated + handoff file written.
5. Anything surprising ⇒ STOP, write handoff with status "blocked". A blocked honest stop is a
   success; an improvised workaround is a failure.

## Flags (full table in FLAGS.md)
DUAL_WRITE_ENABLED (off|on) · STATE_MACHINE_ENABLED (off|shadow|enforce) ·
ENGINE_MODE (off|shadow|enforce) · COMMAND_LAYER_ENABLED (off|shadow|enforce) ·
READ_FROM_ROWS (off|sample|on). Defaults: all off. Reading pattern: copy
`PcieLaneBudgetValidator::currentMode()` (core/models/compatibility/PcieLaneBudgetValidator.php:50-65).

## Where things live
- New code roots (created by this migration):
  `core/models/config/` (repositories, TargetState), `core/models/validation/` (engine + rules/),
  `core/models/commands/`, `core/models/state/` (state machines), `scripts/verify/`, `scripts/backfill/`.
- Seeders: `database/seeders/2026_MM_DD_NNN_description.sql` + paired file in
  `database/seeders/rollback/` (INV-9). Use the next free date-sequence number; check with
  `ls database/seeders/ | tail -5`.
- Reports output: `reports/<report>-<YYYYMMDD-HHMMSS>.json` (gitignored), spec in 11-verification/.
- Handoffs: `migration/handoffs/<UNIT-ID>-<YYYYMMDD>.md` using 00-overview/HANDOFF_TEMPLATE.md.

## Design decision: execution packs are single files
The spec called for a 5-file pack folder. We ship each pack as ONE markdown file containing the five
mandated sections (Objective / Files To Read / Files To Modify / Acceptance Tests / Rollback) plus
Purpose/Inputs/Outputs/DB/Completion/Human-checklist. Rationale: a fresh weak-model session should
load exactly one pack artifact; five separate files quintuple the chance of partial context.
