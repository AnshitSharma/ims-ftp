# Handoff — U-L.3 — 2026-07-07

## Current State
`scripts/verify/slot_report.php` and `scripts/verify/ledger_report.php` now exist and are wired
into `run_all.php` (`available: true`, `lands_in: null` for both `slot` and `ledger` registry
entries). Both scan all non-virtual `server_configurations` (keyset-paginated, mirroring
`equivalence_report.php`), both ship a `--self-test` mode that seeds a real defect via raw SQL and
proves the report detects it (exit 1), both write JSON to `reports/` and print the standard
`<name>: GREEN|RED <path>` line. This session did **exactly one unit** and left
`phase-status.json` at `implemented`, not `verified` — that flip is for a separate session
(per the process established in `migration/handoffs/P1-PL-VERIFY-20260707.md` and honored again
in U-L.2/its verify pass).

## Interpretation calls made (README §4/§5 is terse; documenting the choices, mirroring the
project's existing precedent of flagging TODO_UB2/RISER_SUBTYPE_KEYS-style judgment calls)
- **slot_report check 3** ("every consumer's slot_ref exists as a provider ledger row"):
  `provider_id` is a hard FK (`fk_cr_provider`) into `config_components(id)`, so a config_resources
  row referencing a component that doesn't exist AT ALL is impossible by construction — the only
  reachable defect is a provider that's been **tombstoned** (removed_at set) without its ledger
  rows being cleaned up (i.e. a bug in some future write path that isn't
  `ConfigComponentWriter::cleanupLedgerForRemove()` — see RV-3 below). Implemented as: any
  `config_resources` row with `consumer_id IS NOT NULL AND slot_ref IS NOT NULL` whose
  `provider_id` does not resolve to a **live** (`removed_at IS NULL`) `config_components` row is a
  violation. The self-test proves this by tombstoning a provider via raw `UPDATE` (bypassing the
  normal remove path) rather than trying to insert a nonexistent `provider_id` (the FK would
  reject that at INSERT time — confirmed by hitting it during development).
- **slot_report check 4** ("no card row with NULL slot_ref ... after P2"): implemented
  unconditionally now (not gated on P2 having run) against live `nic`/`pciecard`/`hbacard` rows,
  excluding onboard NICs (`spec_uuid` prefix `onboard-`), mirroring `equivalence_report.php`'s
  `TODO_UB2` stance — onboard NICs legitimately have no discrete PCIe slot.
- **ledger_report check 4** ("lane totals match the single lane model recomputation"): implemented
  as, per config that has at least one `pcie_lane` row in the ledger, comparing
  `Σcapacity(consumer_id IS NULL)` / `Σcapacity(consumer_id IS NOT NULL)` against
  `PcieLaneBudgetValidator::computeLaneBudget()` / `computeLanesUsed()` run against that same
  config's **legacy JSON columns**. Only checked when the ledger has pcie_lane rows at all — a
  config with real legacy lane usage that simply hasn't been dual-written yet is a "not yet
  migrated" state, not a ledger defect (same stance `equivalence_report.php` already takes).

## NEW review item found this session — RV-4 (M.2 lane-exclusion divergence)
While building the acceptance fixture for ledger_report check 4, discovered a genuine, currently
latent divergence between `ResourceCatalog::consumesStorage()` (U-L.1/U-L.2) and
`PcieLaneBudgetValidator` (the pre-existing "single lane model"): `PcieLaneBudgetValidator`
deliberately **excludes M.2 NVMe** storage from the shared PCIe expansion-lane budget (comment
`TP-1C`: "M.2 NVMe drives use dedicated motherboard M.2 slots with their own chipset lanes, NOT
the shared PCIe expansion budget"), but `ResourceCatalog::consumesStorage()` has no such exclusion
— it treats ANY NVMe/PCIe-interface storage as consuming shared lanes regardless of form factor.
Proven with two fixtures: a `form_factor: 'U.2'` NVMe device produces matching ledger/legacy
totals (GREEN); the same setup with `form_factor: 'M.2 2280'` instead produces `ledger_used=4,
legacy_used=0` (RED) because the legacy model excludes it and the ledger model doesn't. This is
currently **unreachable via the real writer path** (no CPU can ever provide a pcie_lane budget —
U-L.1's `provides('cpu', ...)` still throws — so no M.2 storage add can succeed under
`DUAL_WRITE_ENABLED=on` today; this was only reachable in this session's synthetic fixture, which
seeded the CPU-side provider row directly to isolate the check). Not fixed in this unit (out of
its box — `ResourceCatalog.php` was not in this pack's file list) — flagged for whichever unit
next touches `ResourceCatalog::consumesStorage()` or fills the CPU `provides()` gap; the fix is
almost certainly to mirror `PcieLaneBudgetValidator`'s M.2 form_factor check and return `[]` for
M.2 NVMe. Assign alongside RV-1/RV-2/RV-3.

## Completed Work
- `scripts/verify/slot_report.php` (new): 4 checks per config (duplicate component slot_ref,
  duplicate resource slot_ref, discrete-consumer-missing-live-provider, slotless
  nic/pciecard/hbacard). `--self-test` seeds a discrete consumer row then tombstones its provider
  via raw SQL, proving check 3 detects the dangling reference. Default mode: keyset-paginated scan
  of all non-virtual configs.
- `scripts/verify/ledger_report.php` (new): 4 checks per config (Σconsumed ≤ Σcapacity per
  resource, consumer must reference a live component, provider must reference a live component,
  pcie_lane totals must match `PcieLaneBudgetValidator`'s legacy recomputation). `--self-test`
  seeds an over-consumed `pcie_lane` scalar (capacity 4 provided, 999 "consumed") via raw SQL,
  proving check 1 detects it.
- `scripts/verify/run_all.php` (modified): `ledger` and `slot` registry entries flipped from
  `available: false` to `available: true` (both `lands_in: null` now).

## Remaining Work
(empty for U-L.3 itself — unit complete. RV-4, discovered this session, and the still-open
RV-1/RV-2/RV-3 and ResourceCatalog cpu/nic/hbacard/pciecard gaps, remain real, tracked, out-of-box
work for future units.)

## Known Risks
- RV-4 (above): `ResourceCatalog::consumesStorage()` will silently over-count M.2 NVMe lane
  consumption once the CPU `provides()` gap closes, unless fixed first — `ledger_report`'s check 4
  is now the mechanism that will catch it in a real fleet scan the moment it becomes reachable.
- slot_report's checks 3/4 and ledger_report's checks 2/3/4 have never fired RED against real
  production data (no `ims-data/`, no production dump in this sandbox, `DUAL_WRITE_ENABLED` is off
  in production) — every proof in this unit is against synthetic fixtures built the same way every
  prior unit's tests were built.
- slot_report check 3 and ledger_report checks 2/3 are currently vacuous in practice: no discrete
  consumer row can exist today (RV-1/RV-2 — U-L.2's writer never links discrete slots), and
  `cleanupLedgerForRemove()` always removes both provider and consumer ledger rows together in the
  same transaction as a tombstone, so a "provider_not_live"/"consumer_not_live" state should never
  occur via the real writer path either — these checks exist as regression guards for future write
  paths, not because today's writer is known to produce violations.
- Outstanding items from prior handoffs (RAM `serial_number` legacy gap, ResourceCatalog
  cpu/nic/hbacard/pciecard gaps, seeders 2026_07_06_001..003 not yet applied to production,
  `RISER_SUBTYPE_KEYS` narrowing in `equivalence_report.php`) are unrelated to this unit and
  untouched by it.

## Invariant Check Results
| Invariant | Result |
|---|---|
| INV-11 (unit box) | PASS — 2 files created (`slot_report.php`, `ledger_report.php`), 1 file modified (`run_all.php`, the 2 registry flips the pack explicitly calls for), 0 seeders. |
| INV-5 (fail-closed, applied to observability) | N/A for gating writes (these are read-only reports), but both reports throw on setup errors (missing PDO, bad `--gate`) rather than silently reporting green — same posture as every other report in `scripts/verify/`. |

## Acceptance Test Results
- `php -l` on all three files → No syntax errors detected.
- `php scripts/verify/slot_report.php --self-test` → PASS, exit 1 (induced defect correctly
  detected).
- `php scripts/verify/ledger_report.php --self-test` → PASS, exit 1 (induced defect correctly
  detected).
- Realistic dual-written fixture (motherboard + chassis + non-M.2 NVMe storage + a directly-seeded
  CPU pcie_lane provider row, legacy JSON columns populated to match): both
  `slot_report.php` and `ledger_report.php` (default scan mode) → GREEN, exit 0. Same fixture with
  the storage's `form_factor` changed to `'M.2 2280'` instead of `'U.2'` → `ledger_report` correctly
  went RED on the newly-discovered RV-4 divergence (see above), confirming check 4 works as
  intended; fixture was then reverted to the U.2 (matching) form factor before being deleted, so no
  RED artifact of a "genuine" defect was left for the fleet.
- `php scripts/verify/run_all.php --quick` → exit 0, all 4 reports GREEN.
- `php scripts/verify/run_all.php --gate PL` → exit 0 (`schema`: GREEN, `ledger`: GREEN,
  `regression`: SKIPPED — no dedicated report script planned for it, unaffected by this unit).
- Full existing regression/unit suite re-run and unaffected: `dual_write_test.php`,
  `ledger_dual_write_test.php`, `config_component_repository_test.php`, `resource_catalog_test.php`,
  `finalized_immutability_test.php`, `nested_transaction_test.php` → ALL PASS. `fail_closed_test.php`
  shows its one pre-existing failure (`scandir(/home/user/ims-data)`) — a known environment gap (no
  real `ims-data/` in this sandbox, present since U-0.1, untouched by this unit).
- `php tests/characterize_compatibility.php` (golden-master pin, flag off) → exit 0, 0
  configs/0 replays (no production dump); baseline restored via `git checkout --` immediately
  after (confirmed no diff).
- All scratch fixtures and their throwaway `ims-data/` trees deleted; `config_resources` and
  `config_components` both count 0 rows after the full session's testing.

## Next Prompt To Use
"Continue the IMS migration. Read migration/00-overview/README.md, migration/ARCHITECTURAL_INVARIANTS.md,
then either (a) run an independent verify pass on U-L.3 (per this project's implemented/verified
split — do NOT let the same session both implement and verify; this would also be the moment to
decide whether all three PL units being verified means the PL gate can be flipped to open, since
its gate_reports are schema+ledger+regression and both schema/ledger already run GREEN here), or
(b) if U-L.3 is already verified and the PL gate is open, move on to P2
(migration/07-component-migration/, unit U-B.1) using its execution pack. Read
migration/handoffs/U-L.3-20260707.md first for RV-4 (a real ResourceCatalog/PcieLaneBudgetValidator
divergence on M.2 NVMe lane exclusion, currently latent but will bite the moment U-B.x or a
follow-up closes the CPU provides() gap) and the still-open RV-1/RV-2/RV-3 items. ONE unit only.
Follow migration/00-overview/SESSION_PROTOCOL.md exactly."

## Files To Load Into Context (next session)
- migration/00-overview/README.md
- migration/ARCHITECTURAL_INVARIANTS.md
- migration/handoffs/U-L.3-20260707.md (this file)
- migration/handoffs/U-L.2-20260707.md and migration/handoffs/UL2-VERIFY-20260707.md (RV-1/RV-2/RV-3 context)
- scripts/verify/slot_report.php
- scripts/verify/ledger_report.php
- core/models/compatibility/PcieLaneBudgetValidator.php (lines 66-140, 241-339 — the lane math ledger_report recomputes against)

## Expected Context Size
~30k tokens
