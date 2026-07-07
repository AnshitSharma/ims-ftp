# Handoff — U-B.3 — 2026-07-07

## Current State
`scripts/backfill/backfill.php` now backfills the ledger too: after
`persistPlans()` inserts a config's `config_components` rows (U-B.2), a second
pass in the SAME transaction (`backfillLedgerForConfig()`) inserts
`config_resources` provider rows, attempts discrete slot consumer-linking,
and inserts scalar (pcie_lane) consumption rows via `ResourceCatalog`. This is
the THIRD unit in P2; gate still needs U-B.4 too (stays `closed`). Exactly one
unit done this session, left `implemented` not `verified`.

## Completed Work
- `scripts/backfill/backfill.php` (modified — no new production file, matching
  the pack's exact box):
  - `persistPlans()` now returns `{id, component_type, spec_uuid, slot_ref}`
    per inserted row (needed by the new second pass).
  - `backfillLedgerForConfig(PDO, configUuid, insertedRows)`: for each row,
    (a) inserts provider rows via `ResourceCatalog::provides()` — `riser` rows
    are queried as `pciecard` (ResourceCatalog doesn't know the
    config_components-level relabeling; `providesPciecard()` itself detects
    Riser Card via `component_subtype`); (b) attempts a plain `slot_ref`
    string match against existing unconsumed provider rows for discrete
    consumer-linking (`UPDATE config_resources SET consumer_id=... WHERE
    slot_ref=... AND consumer_id IS NULL`); (c) inserts scalar consumption
    rows via `ResourceCatalog::consumes()` (storage/pcie_lane only, today).
  - `LEDGER_SKIP_PROVIDES = ['cpu','nic']` / `LEDGER_SKIP_CONSUMES =
    ['nic','hbacard','pciecard','riser']`: types `ResourceCatalog` has no
    confirmed field for are skipped entirely rather than called-and-caught —
    calling `provides('cpu',...)` unconditionally would throw on literally
    every config with a CPU (i.e. nearly all of them), which is not what
    "CatalogException ⇒ error, spec-fixable" is meant to describe.
  - **Fixed a real bug found while testing** (same class the U-B.1 handoff
    predicted for `--rollback-run`, but hit here first): a bulk `DELETE FROM
    config_components WHERE id IN (...)` in `rollbackRun()` threw
    `fk_cc_parent` ("Cannot delete or update a parent row") the first time it
    ran against real parent/child rows, since MySQL doesn't guarantee delete
    order within one `IN (...)` and `parent_id` has no `ON DELETE` clause
    (RESTRICT by default). Fixed by ordering the fetched `component_id`s by
    `revision DESC` (children always have a higher revision than the parent
    they reference, since `persistPlans()` always inserts parent-before-child)
    and deleting one row at a time in that order.
- `tests/backfill/ledger_backfill_test.php` (new): two fixtures over a real
  scratch DB + throwaway ims-data —
  - Fixture A (happy path): motherboard + chassis + riser + a plain card
    occupying a slot_ref deliberately aligned with one the riser provides
    (proving the discrete-link mechanism works when naming coincides, per
    RV-2) + SATA storage (proves a non-lane-consuming type produces no
    `pcie_lane` rows). Asserts provider rows (psu_watt, pcie_slot, riser_slot,
    2x riser-provided pcie_slot), the discrete link, and runs `ledger_report`
    + `slot_report` full-fleet scans afterward — both GREEN (pack's stated
    acceptance test).
  - Fixture B (error path): NVMe storage with no `pcie_lane` provider
    anywhere in the config (real state — `cpu`'s `provides()` gap means one
    never exists) → `CatalogException` → config state `error`, NOT
    quarantined, `last_error` names the missing provider, whole-config
    transaction rolled back (no partial `config_components` rows), and
    `--resume` re-attempts (still errors, since nothing was actually fixed —
    proves the failure is surfaced every time, not silently dropped).

## Remaining Work
(empty for U-B.3 itself. U-B.4 is next — not read this session.)

## Known Risks / Interpretation calls made
- **The `cpu provides()` gap means almost every real NVMe-storage config will
  hit `error` status on a live backfill run today**, not just my synthetic
  Fixture B — this is not new (it already existed for live dual-write, per
  U-L.1/U-L.2/U-L.3 handoffs), but U-B.3 is the first unit to make it visible
  in bulk (every config with PCIe/NVMe storage, fleet-wide). This is the
  pack's own anticipated "spec fixable" error class, not a bug — but whoever
  runs the real fleet backfill should expect a large `error` bucket until a
  future unit implements `ResourceCatalog::provides('cpu', ...)`.
- Discrete slot consumer-linking (the plain `slot_ref` string match) will
  link almost nothing on real data, per the already-documented RV-2:
  `ResourceCatalog`'s slot_ref naming (`pcie_{n}_{width}`,
  `riser_provided_pcie_{i}_{width}`) has no confirmed relationship to the
  legacy slot-assignment system's actual slot_position strings. The mechanism
  is real and tested (Fixture A proves it works when names align), but on
  real production data it will very likely be inert until a future unit
  reconciles the two naming schemes.
- RV-1 (DIMM slot consumer-linking) stays open, as the pack expects —
  `ram`'s `slot_ref` is never populated by the Extractor (unknown legacy-side,
  per U-B.2), so ram rows never attempt discrete linking; `ledger_report`
  already tolerates NULL-consumer dimm-adjacent rows per its own design.
- Fixed the `--rollback-run` FK-ordering bug described above (second time
  this exact class of bug has been found in this same function — first was
  a slightly different manifestation caught in U-B.2's own testing, already
  fixed there; this session's fix targets a case U-B.2's fixtures didn't
  happen to trigger). No further FK-ordering issues found in this session's
  testing, but any future unit adding new component-row insert paths should
  re-verify `--rollback-run` against real rows before trusting it.
- `LEDGER_SKIP_PROVIDES`/`LEDGER_SKIP_CONSUMES` are hardcoded to mirror
  `ResourceCatalog`'s CURRENT implemented/not-implemented split exactly. If a
  future unit implements `provides('cpu', ...)` or `provides('nic', ...)` (or
  the `consumes()` cases for nic/hbacard/pciecard), these two lists must be
  updated in the same change, or backfill will keep silently skipping ledger
  data it could now produce correctly.

## Invariant Check Results
| Invariant | Result |
|---|---|
| INV-8 (dual-write windows never fork silently) | N/A directly (this unit backfills history, not a dual-write window), but the ledger rows it produces use the exact same `ResourceCatalog` calls `ConfigComponentWriter`'s live dual-write path uses, so a config touched by both paths ends up with consistent ledger semantics either way. |
| INV-11 (unit box) | PASS — 1 file modified (`backfill.php`, ledger second-pass logic + the rollback-run fix), 1 test file created, 0 seeders, 1 concept (ledger backfill). |
| INV-5 (fail-closed) | PASS — `backfillLedgerForConfig()` never catches; any `CatalogException` propagates to the existing per-config try/catch, which rolls back that config's whole transaction and marks it `error`. |

## Acceptance Test Results
- `php -l scripts/backfill/backfill.php` / `tests/backfill/ledger_backfill_test.php` → no syntax errors.
- `tests/backfill/ledger_backfill_test.php` → 17/17 PASS (provider rows,
  discrete link, non-lane-consumer proof, `ledger_report`/`slot_report` GREEN,
  CatalogException → error not quarantine, whole-config rollback, resume
  still-errors-without-a-real-fix).
- Re-ran U-B.2's own full-fixture acceptance test (all 10 types, real
  ims-data for motherboard/chassis/pciecard/storage) with U-B.3's ledger pass
  now wired in: `backfill.php --execute` still `done=1 errors=0`,
  `equivalence_report.php --config` still GREEN — confirms the ledger pass
  didn't regress U-B.2's happy path.
- `php scripts/verify/run_all.php --quick` → exit 0, all 4 reports GREEN.
- `php scripts/verify/run_all.php --gate PL` → exit 0.
- `tests/backfill/extractor_test.php`, `tests/regression/dual_write_test.php`,
  `finalized_immutability_test.php`, `ledger_dual_write_test.php`,
  `nested_transaction_test.php`, `tests/unit/config_component_repository_test.php`,
  `tests/unit/resource_catalog_test.php` → ALL PASS.
- INV-9 sanity check (no new seeder this unit): `for f in database/seeders/2026_0[7-9]*_*.sql; do test -f rollback/...; done` → prints nothing.
- `php tests/characterize_compatibility.php` → exit 0, 0/0 (no production
  dump); golden baseline restored via `git checkout --` immediately after,
  confirmed clean via `git status --porcelain`.
- All fixture rows deleted after testing; scratch MariaDB stopped.

## Next Prompt To Use
"Continue the IMS migration. Read migration/00-overview/README.md,
migration/ARCHITECTURAL_INVARIANTS.md, then either (a) run an independent
verify pass on U-B.3 (per this project's implemented/verified split — do NOT
let the same session both implement and verify), or (b) if U-B.3 is already
verified, execute unit U-B.4 using
migration/07-component-migration/execution-packs/U-B.4.md. Read
migration/handoffs/U-B.3-20260707.md first, especially: the cpu-provides()
gap meaning most real NVMe-storage configs will land in 'error' status on a
real fleet run (expected, not a bug, but worth knowing before running
--execute against production), the RV-2 discrete-slot-linking caveat (mostly
inert on real data until naming schemes reconcile), and the
rollback-run FK-ordering fix (re-verify if U-B.4 changes insertion order
again). ONE unit only. Follow migration/00-overview/SESSION_PROTOCOL.md exactly."

## Files To Load Into Context (next session)
- migration/00-overview/README.md
- migration/ARCHITECTURAL_INVARIANTS.md
- migration/handoffs/U-B.3-20260707.md (this file)
- migration/07-component-migration/execution-packs/U-B.4.md (if proceeding to implement)
- scripts/backfill/backfill.php
- scripts/backfill/Extractor.php

## Expected Context Size
~35k tokens
