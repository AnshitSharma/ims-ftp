# Handoff — U-L.5 — 2026-07-12

## Current State
Implemented per `migration/06-resource-ledger/execution-packs/U-L.5.md`, immediately after U-L.4 in the
same session. Closes the **nic/hbacard/pciecard** half of the `ResourceCatalog` provider/consumer gap.
Status: **implemented, not verified** — per SESSION_PROTOCOL.md, an independent session must verify.

**After this unit, `ResourceCatalog::provides()`/`consumes()` no longer throw `CatalogException` for any
of the 10 component types under normal (even minimally-specified) real data.**
`ConfigComponentWriter::writeLedgerForAdd()`'s live dual-write path is safe for `DUAL_WRITE_ENABLED=on`
from a ResourceCatalog-coverage standpoint. **This does NOT itself certify `DUAL_WRITE_ENABLED` should be
flipped in production** — that is still a separate, human, production decision gated on U-B.4 and an
actual ≥24h soak, per the user's original instruction and SESSION_PROTOCOL.

## Completed Work
- `core/models/config/ResourceCatalog.php`:
  - `provides()`'s `case 'nic':` → new private `providesNic()`, reads the NIC spec's `ports` field
    (mirrors `NICPortTracker::getPortAssignmentInfo()`), returns one `sfp_port` provider row. Missing
    field → `[]`; non-numeric → throw (same posture as `providesCpu()`).
  - `consumes()`'s `case 'nic'/'hbacard'/'pciecard':` → new private `consumesPcieLanes()` +
    `extractLaneCount()` helper, mirroring `PcieLaneBudgetValidator::extractLaneCount()` exactly:
    `interface`/`pcie_interface`/`bus_interface` string regex (`/x(\d+)/i`) first, numeric `pcie_lanes`
    field fallback, else **0 lanes** (empty row set) — NOT a throw. This is the one deliberate exception
    to this file's fail-closed posture (documented explicitly in the class docblock so a future unit
    doesn't "fix" it to throw): legacy's own `computeLanesUsed()` treats an unparseable width as 0, so
    mirroring anything stricter would invent new behavior.
  - Class docblock updated: nic (provides) and nic/hbacard/pciecard (consumes) moved to "implemented",
    with the 0-lane-fallback caveat spelled out.
- `scripts/backfill/backfill.php`: `LEDGER_SKIP_PROVIDES = ['nic']` → `[]`. `LEDGER_SKIP_CONSUMES =
  ['nic', 'hbacard', 'pciecard', 'riser']` → `[]`. **Discovered during implementation**: `'riser'` was
  already dead in that list before this unit touched it — `backfillLedgerForConfig()` normalizes
  `$physicalType` to `'pciecard'` for riser rows *before* the skip-list `in_array()` check runs, so the
  literal string `'riser'` could never match. A riser card's spec has no `interface`/`pcie_lanes` field,
  so it naturally consumes 0 lanes via the new fallback chain — consistent with
  `PcieLaneBudgetValidator::computeLanesUsed()`'s own un-excluded walk of riser entries in
  `pciecard_configurations`. No `case 'riser'` was added to `ResourceCatalog` (correctly out of scope).
- Tests extended:
  - `tests/unit/resource_catalog_test.php`: nic provider fixtures (ports=4 → 1 row capacity 4; no ports
    → `[]`; non-numeric ports → throw) and nic/hbacard/pciecard consumption fixtures (interface string →
    lanes; no interface + no pcie_lanes → `[]` not throw; numeric pcie_lanes fallback with no parseable
    interface → that value; unknown UUID still throws — spec-not-found stays fail-closed, only the
    *field* fallback is fail-open).
  - `tests/backfill/ledger_backfill_test.php`: new Fixture D (CPU + HBA card with `interface: "PCIe 4.0
    x8"` + NIC with `ports: 4`) → `--execute` → `done=1 errors=0`, real `pcie_lane` consumption row
    (capacity 8, linked to the HBA card) + real `sfp_port` provider row (capacity 4). Fixture A's
    existing plain riser-slot card (`$cardUuid`, no `interface`/`pcie_lanes` field) re-verified under the
    now-active `consumesPcieLanes()` path — **confirmed behavior change**: previously this component was
    entirely skipped as a consumer (`LEDGER_SKIP_CONSUMES` included `pciecard`); now it's exercised and
    correctly contributes 0 lanes via the fallback, not a throw. Fixture A's `errors=0`/`slot_report
    GREEN` assertions still pass.

## Additional Verification (live dual-write path, not just backfill.php)
The pack's acceptance criteria are satisfied via `backfill.php`/`ResourceCatalog` directly, but the
user's original concern was specifically the LIVE add-component path
(`ConfigComponentWriter::writeLedgerForAdd()`, called from `afterLegacyAdd()`), since it has no skip
list unlike `backfill.php`. Extended `tests/regression/ledger_dual_write_test.php` with Scenarios F–I:
real, resolvable cpu/nic/hbacard/pciecard specs added through `ConfigComponentWriter::afterLegacyAdd()`
directly (not through the backfill CLI) — all four now complete with **no throw** and produce the
correct `config_resources` rows (cpu → 1 pcie_lane provider row capacity 64; nic → 1 sfp_port provider
row capacity 4; hbacard → 1 pcie_lane consumption row capacity 8 against a pre-seeded CPU budget;
pciecard → 1 pcie_lane consumption row capacity 4, same pattern). Scenario E (induced failure) still
throws for a NIC with an **unresolvable** spec UUID — correctly, since that's now "spec not found"
(fail-closed per INV-5), not the old "no confirmed fields" reason; its docblock comment was updated to
reflect this. All 27 checks in this file: **ALL PASS**.

## Known Risks / Discoveries
- **Pre-existing, unrelated gap found and worked around in the test fixtures (not fixed, out of this
  unit's box)**: a non-onboard NIC always gets `config_components.slot_ref = NULL` via
  `Extractor::extractNics()` — there is no slot-tracking path for add-on NICs in the backfill extractor
  today (`nic_config` entries carry no `slot_position`-equivalent field, unlike `pciecard_configurations`
  or `sfp_configuration`). This trips `scripts/verify/slot_report.php`'s `slotless_card` check for
  `nic`/`pciecard`/`hbacard` component types whenever a real (non-onboard) NIC is present. **This
  predates U-L.5 and is unrelated to ResourceCatalog** — it was only discovered because Fixture A (which
  asserts `slot_report GREEN`) initially had a NIC added to it, which is why the NIC proof was moved to
  Fixture D instead (Fixture D never calls `slot_report.php`). **Any real production config with a
  non-onboard/add-on NIC likely already trips `slotless_card` today, independent of this migration** —
  worth flagging to the user as a separate, pre-existing data-quality/tooling gap if `slot_report.php` is
  ever added to a gate's `gate_reports` list (it currently isn't, for any phase up to P8).
- Same local-environment gotchas as prior sessions apply; scratch environment (`C:\tmp\ims-ftp-scratch\`,
  MariaDB, `ims_compat_golden`) still running and current through this unit's changes.

## Invariant Check Results
| Invariant | Result |
|---|---|
| INV-5 (fail-closed) | PASS with one documented, deliberate exception — `consumesPcieLanes()`'s 0-lane fallback for an absent/unparseable interface field mirrors legacy `PcieLaneBudgetValidator::extractLaneCount()` exactly; spec-not-found still throws. Called out explicitly in the class docblock. |
| INV-11 (unit box) | PASS — 2 files modified + 2 test files extended. One concept: close the remaining nic/hbacard/pciecard half of the provider/consumer gap. |

## Acceptance Test Results (scratch DB, `C:\tmp\ims-ftp-scratch\`)
- `php -l` on both modified files: no syntax errors.
- `tests/unit/resource_catalog_test.php`: ALL PASS (43 checks total, incl. all new nic/hbacard/pciecard
  checks).
- `tests/backfill/ledger_backfill_test.php`: ALL PASS (24 checks total, incl. new Fixture D and the
  re-verified Fixture A plain-card behavior change).
- Full regression/unit suite re-run: `config_component_repository_test.php`, `nested_transaction_test.php`,
  `dual_write_test.php`, `ledger_dual_write_test.php`, `finalized_immutability_test.php` — ALL PASS,
  unaffected by this unit.
- `grep -n "LEDGER_SKIP" scripts/backfill/backfill.php`: both constants are `[]` (confirmed).
- `php scripts/verify/run_all.php --quick`: schema GREEN; inventory/orphan/equivalence RED — same
  pre-existing counts as before this unit (29 orphans / 3 inventory / 7 equivalence diffs), not a
  regression.
- `php tests/characterize_compatibility.php`: completes, exit 0.

## Next Prompt To Use
"Continue the IMS migration. Read migration/00-overview/README.md, migration/ARCHITECTURAL_INVARIANTS.md,
migration/handoffs/U-L.4-20260712.md and migration/handoffs/U-L.5-20260712.md (this file), then run the
independent verify pass for both units per SESSION_PROTOCOL.md. Also consider whether
scripts/verify/slot_report.php's slotless_card gap for non-onboard NICs (see this file's Known Risks)
needs its own follow-up unit before P8, or is acceptable as pre-existing/out-of-scope."

## Files To Load Into Context (next session)
- migration/00-overview/README.md
- migration/ARCHITECTURAL_INVARIANTS.md
- migration/handoffs/U-L.4-20260712.md
- migration/handoffs/U-L.5-20260712.md (this file)
- core/models/config/ResourceCatalog.php
- scripts/backfill/backfill.php

## Expected Context Size
~30k tokens
