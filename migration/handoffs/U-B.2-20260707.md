# Handoff — U-B.2 — 2026-07-07

## Current State
`scripts/backfill/Extractor.php` exists: the real Extractor, replacing U-B.1's
"quarantine everything" stub. It mirrors `ServerBuilder::extractComponentsFromJson()`'s
per-column JSON shapes (independently — does not call it) and resolves every
legacy entry to exactly one physical inventory row before it becomes a
`config_components` insert plan; anything it can't confidently resolve is
quarantined with a distinct reason. `backfill.php --execute` now performs real
inserts (`config_components` + `config_events` event=`backfill`, carrying
`run_id`) instead of only quarantining. This is the SECOND unit in P2, whose
gate still needs U-B.3/U-B.4 verified too (unaffected by this session — gate
stays `closed`). This session did exactly one unit and left `phase-status.json`
at `implemented`, not `verified`.

## Completed Work
- `scripts/backfill/Extractor.php` (new): `extract(PDO, array $configRow): array{plans, quarantine}`
  covering all 10 legacy JSON shapes + quirks from the pack:
  - cpu/ram quantity>1 expansion (Q distinct inventory rows required, else
    quarantine `quantity-without-serials`); quantity=1 and every other
    single-unit type resolves via (UUID,SerialNumber) when given, else the
    unique (UUID,ServerUUID=config) row, else `ambiguous-serial`.
  - hbacard triple-format (array > single-object > `hbacard_uuid` scalar,
    scalar only used when `hbacard_config` is empty/`'[]'`) — this precedence
    fixes the dedup gap flagged in U-B.1's handoff.
  - pciecard retyped `riser` on uuid prefix `riser-` OR ims-data
    `component_subtype === 'Riser Card'` (mirrors `ResourceCatalog::providesPciecard()`).
    A plain card parents to the single riser present when its slot mentions
    "riser" and there is exactly one riser in the config (RV-2: no confirmed
    way to disambiguate with >1 risers — falls back to motherboard, not guessed).
  - onboard nic (uuid prefix `onboard-`) parents to motherboard; regular nic
    has no parent (matches `ConfigComponentWriter::resolveParentId()`).
  - sfp: `slot_ref='port_'.port_index` when assigned; `parent_ref` is the
    owning nic's spec_uuid, resolved by `backfill.php` against ids assigned so far.
  - storage: `slot_ref` from `connection.bay` if present else NULL, never quarantined for its absence.
  - `persistPlan()`: raw `config_components` INSERT mirroring
    `ConfigComponentRepository::insert()`'s SQL (incl. the tombstone-reactivation
    `ON DUPLICATE KEY UPDATE`), but with `event='backfill'` + `run_id` in the
    payload via the repository's already-public `bumpRevision()` — NOT via
    `insert()` itself, which hardcodes `event='add'` with no `run_id` and would
    make backfilled rows indistinguishable from live dual-write and
    un-rollback-able by run. `ConfigComponentRepository.php` itself is unmodified.
- `scripts/backfill/backfill.php` (modified): dry-run now reports real
  `would_migrate`/`would_quarantine`/`reasons` via the Extractor instead of a
  hardcoded stub count; `--execute` persists plans in parent-before-child
  order (resolving `parent_ref` markers against ids assigned earlier in the
  same config) and quarantines the rest; **fixed a real bug found during
  testing**: `--rollback-run` deleted `config_components` rows via one
  unordered `DELETE ... WHERE id IN (...)`, which threw `fk_cc_parent`
  ("Cannot delete or update a parent row") the first time it ran against REAL
  parent/child rows — U-B.1's handoff had already flagged this path as
  "untestable against real rows...forward-compatible dead code", and this
  session's real inserts hit it immediately. Fixed by ordering the delete by
  `revision DESC` (children always have a higher revision than the parent they
  reference, since `persistPlans()` always inserts parent-before-child) and
  deleting one row at a time in that order.
- `scripts/verify/equivalence_report.php` (modified): `TODO_UB2` flipped to
  `true` (onboard nics now get real rows/parent_ids, per the pack). Also fixed
  two pre-existing bugs this unit's own acceptance test exposed (see Known
  Risks — both are general equivalence_report defects, not something
  introduced by backfill, just never exercised end-to-end before).
- `tests/backfill/extractor_test.php` (new): 23 checks against a real scratch
  DB + throwaway ims-data fixture, covering every quirk above plus
  missing-uuid/serial-not-found quarantine paths.

## Remaining Work
(empty for U-B.2 itself. U-B.3/U-B.4 are next — not read this session.)

## Known Risks / Interpretation calls made
- **Found two pre-existing `equivalence_report.php` bugs, not introduced by
  this unit** (surfaced because U-B.2's own acceptance test is the FIRST
  end-to-end equivalence check against a config with all 10 types and real
  inventory-resolved serials/slots — previous regression tests
  (`dual_write_test.php`/`ledger_dual_write_test.php`) check row insertion
  directly, not round-trip equivalence):
  1. `canonicalTuple()`/`canonicalizeRowSide()` compared `serial_number` for
     every type, but `extractComponentsFromJson()` (the report's own
     "authoritative decoder") only ever populates `serial_number` for `cpu`
     entries — every other type's legacy JSON decodes without it (true even
     for hbacard, whose raw JSON DOES carry a serial key that the decoder
     just never reads back out). Meanwhile both live dual-write and backfill
     always write the real resolved serial row-side, for every type. This
     made the comparison spuriously RED for any non-cpu component with a real
     serial, regardless of whether the two stores actually agreed. **This bug
     already existed for live dual-write, not just backfill** — nobody had
     run `equivalence_report.php` against a real dual-written non-cpu
     component before. Fixed: serial is only compared for `cpu`.
  2. Same root cause for `slot_ref`: the decoder drops `slot_position`
     entirely for pciecard/riser/hbacard and never flattens storage's
     `connection.bay`, and exposes sfp's slot under `port_index` (not
     `slot_position`/`slot_ref`/`slot_id`, the only keys `canonicalTuple()`
     checked). Fixed: slot_ref is only compared for `sfp` (via `port_index`);
     for every other type it's forced to null on both sides. **This means
     slot placement equivalence for pciecard/riser/hbacard/storage is NOT
     actually verified by this report today** — only presence + spec (+
     serial for cpu, + slot for sfp) is. A follow-up unit with authorization
     to read more of `ServerBuilder`/a different data source should find the
     real way to compare slot placement for those types, or explicitly accept
     this as a permanent scope limit.
  3. Also fixed `isRiserPciecard()`: it checked `RISER_SUBTYPE_KEYS`
     (`subtype`/`card_type`/`type`) against the DECODED entry, which never
     actually carries those keys (confirmed by reading
     `extractComponentsFromJson`'s real output shape) — so that list never
     matched anything, and there was no uuid-prefix check at all, even though
     this unit's own pack confirms `riser-` prefix is part of the real rule.
     Added the prefix check. The `component_subtype` half (matching
     `ResourceCatalog`) is NOT implemented here — it would require loading
     `DataExtractionUtilities`/ims-data specs into a report that scans the
     whole fleet, a bigger perf-sensitive change outside this unit's file box.
     A config whose only riser signal is `component_subtype` (no `riser-`
     prefix) will show a false diff in this report even though `Extractor.php`
     resolves it correctly. Tracked as a known gap, folds in the
     previously-flagged "RISER_SUBTYPE_KEYS should be narrowed" item.
- RV-1/RV-2 (DIMM and discrete PCIe/riser slot ledger-consumer linking) are
  unaffected — this unit only writes `config_components`, not
  `config_resources` (ledger writes are out of scope per the pack; U-B.3/U-B.4
  presumably own that).
- RV-3/RV-4 (from U-L.2/U-L.3) unaffected — untouched files this session.
- Storage's `connection.bay` key name is BEST-EFFORT (not confirmed in this
  unit's read scope, same caveat class as the pre-existing `RISER_SUBTYPE_KEYS`
  note) — absence is expected and does not quarantine, per the pack.
- A plain pciecard/hbacard's riser-vs-motherboard parent link only resolves
  correctly when there is exactly ONE riser in the config; with 2+ risers it
  always falls back to motherboard (RV-2 territory — no confirmed way to
  disambiguate which riser a slot_position belongs to without reading
  `UnifiedSlotTracker.php`, out of this unit's scope).
- Fixed the `--rollback-run` FK-ordering bug described above; this is the
  first time that path has run against real rows (U-B.1's own version was
  only exercised at zero rows).

## Invariant Check Results
| Invariant | Result |
|---|---|
| INV-1 (one physical unit = one row) | PASS — every `persistPlan()` call is keyed on one resolved `(inventory_table, inventory_id)` pair; `grep -n "'quantity'" scripts/backfill/Extractor.php` finds no persisted quantity field (the JSON quirk is expanded into N rows, never stored as a count). |
| INV-11 (unit box) | PASS, but tight — 1 file created (`Extractor.php`, 349 lines) + 2 modified (`backfill.php` +83/-87, `equivalence_report.php` +56/-14) = ~488 changed lines, 3 files, 0 seeders, 1 concept (the real Extractor + the equivalence-report fixes needed to make this unit's own stated acceptance test achievable). |
| INV-5 (fail-closed) | PASS — every unresolved entry is quarantined with a reason, never silently dropped or defaulted; `persistPlan()` propagates any exception (no local catch) so a partial-config failure rolls back that config's whole transaction (existing `backfill.php` try/catch). |
| INV-6 (revision + config_events atomic) | PASS — `persistPlan()` calls `bumpRevision()` in the same transaction as its `config_components` INSERT, for every plan. |

## Acceptance Test Results
- `php -l` on all 3 changed/created files: no syntax errors.
- `tests/backfill/extractor_test.php`: 23/23 PASS (all quirks: quantity
  expansion, ambiguous-serial, hbacard dedup + triple-format, riser via prefix
  AND via component_subtype, single-riser card parenting, onboard vs regular
  nic, sfp assigned/unassigned, storage connection.bay present/absent,
  missing-uuid, serial-not-found).
- Full-fixture acceptance test (exact pack command): seeded a config with all
  10 types (motherboard, chassis, cpu, ram, storage, riser, plain pciecard,
  hbacard, nic, onboard nic, sfp) across real inventory tables (created ad-hoc
  in the scratch DB — these tables predate the seeder system and weren't
  present in this sandbox before; not a project seeder, just scratch
  infrastructure) →
  `backfill.php --execute --config <uuid>` → `done=1 quarantined=0 errors=0`,
  11 `config_components` rows, 11 `config_events` rows all `event='backfill'`
  with matching `run_id` →
  `equivalence_report.php --config <uuid>` → **GREEN, exit 0** (after the two
  equivalence_report fixes above — RED before them).
- `equivalence_report.php --self-test`: still PASS (induced cpu mismatch still
  detected — unaffected by the serial/slot narrowing since cpu keeps full comparison).
- `--resume --run-id <id>` on the `done` full-fixture config: `verified=1 errors=0`.
- `--rollback-run <id>`: cleared all 11 `config_components`/`config_events`
  rows (after the FK-ordering fix — failed with `fk_cc_parent` before it);
  `migration_backfill_state`/`backfill_quarantine` also cleared to 0.
- Quarantine path: a config with an unresolvable ram entry (unknown uuid) →
  `quarantined=1`, `backfill_quarantine` row with reason `ambiguous-serial`.
- Dry-run: `would_migrate`/`would_quarantine`/`reasons` reported correctly, zero DB writes.
- `php scripts/verify/run_all.php --quick` → exit 0, all 4 reports GREEN.
- `php scripts/verify/run_all.php --gate PL` → exit 0 (schema/ledger GREEN, regression skipped-by-design).
- `tests/regression/*.php`: `dual_write_test.php`, `finalized_immutability_test.php`,
  `ledger_dual_write_test.php`, `nested_transaction_test.php` → ALL PASS.
  `fail_closed_test.php` → same pre-existing environment-only failure as every
  prior session (`scandir(/home/user/ims-data)`, no ims-data dir in this
  sandbox) — unrelated to this unit's files.
- `tests/unit/config_component_repository_test.php`, `tests/unit/resource_catalog_test.php` → ALL PASS.
- `tests/nic_sfp_authority_unit.php`, `tests/slot_storage_authority_unit.php` → ALL PASS.
  `tests/lane_authority_unit.php`, `tests/memory_authority_unit.php`,
  `tests/storage_bay_authority_unit.php`, `tests/serverstate_equivalence.php` →
  same pre-existing environment gaps (no ims-data / different DB expectations)
  as every prior session, unrelated to this unit's files.
- `php tests/characterize_compatibility.php` → exit 0, 0/0 (no production
  dump, as always in this sandbox); golden baseline restored via `git checkout --`
  immediately after, confirmed clean via `git status --porcelain`.
- All fixture rows deleted after testing; scratch MariaDB stopped.

## Next Prompt To Use
"Continue the IMS migration. Read migration/00-overview/README.md,
migration/ARCHITECTURAL_INVARIANTS.md, then either (a) run an independent
verify pass on U-B.2 (per this project's implemented/verified split — do NOT
let the same session both implement and verify), or (b) if U-B.2 is already
verified, execute unit U-B.3 using
migration/07-component-migration/execution-packs/U-B.3.md. Read
migration/handoffs/U-B.2-20260707.md first, especially: the two
equivalence_report.php bugs found and fixed (serial/slot_ref comparison only
meaningful for cpu/sfp respectively — slot placement for
pciecard/riser/hbacard/storage is NOT verified by that report today, a known
gap for whichever future unit can read more of ServerBuilder or a different
data source to fix it properly), the still-partial riser detection in
equivalence_report.php (component_subtype half not implemented, uuid-prefix
half is), and the rollback-run FK-ordering fix (child-before-parent delete
order — re-verify if U-B.3/U-B.4 change insertion order). ONE unit only.
Follow migration/00-overview/SESSION_PROTOCOL.md exactly."

## Files To Load Into Context (next session)
- migration/00-overview/README.md
- migration/ARCHITECTURAL_INVARIANTS.md
- migration/handoffs/U-B.2-20260707.md (this file)
- migration/07-component-migration/execution-packs/U-B.3.md (if proceeding to implement)
- scripts/backfill/Extractor.php
- scripts/backfill/backfill.php

## Expected Context Size
~35k tokens
