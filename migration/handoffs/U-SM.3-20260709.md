# Handoff — U-SM.3 — StateMachine service + legacy sync — 2026-07-09

## What shipped
- `core/models/state/StatusMap.php` (new): single source for the lossy status_v2
  <-> legacy-int maps, both directions, both machines (config + inventory).
- `core/models/state/StateMachine.php` (new): `assertConfigTransition` /
  `applyConfigTransition` (config), `assertInventoryTransition` /
  `applyInventoryTransition` (inventory). Never opens a transaction or locks a
  row — every method requires the caller to already hold both.
- `core/models/server/ServerBuilder.php` (modified, 2 sites):
  - `finalizeConfiguration()`: the raw `UPDATE ... SET configuration_status = 3`
    is replaced by `StateMachine::applyConfigTransition($pdo, $configUuid,
    'finalized')`, which writes `status_v2` + the mapped legacy int + bumps
    `revision` + appends one `config_events('transition')` row in a single
    UPDATE + `ConfigComponentRepository::bumpRevision` call. `notes` is a
    separate follow-up UPDATE in the same transaction (StateMachine doesn't
    know about `notes`).
  - `updateComponentStatusAndServerUuid()`: appends `status_v2 = ?` (mapped via
    `StatusMap::INVENTORY_LEGACY_TO_V2[$newStatus]`) into the method's
    existing single dynamic UPDATE — same statement as the legacy `Status`
    write, so the two columns can never commit separately.
- `scripts/verify/inventory_report.php` (modified): new Check 3 —
  status_v2/Status mapping agreement per inventory table (flags both an
  illegal status_v2 value not in `StatusMap::INVENTORY_V2_TO_LEGACY`, and a
  legal value whose row's legacy `Status` disagrees with the map).
- `tests/state_machine_unit.php` (new): 13 assertions, standalone scratch DB
  (not golden — this is pure transition-substrate, needs no ims-data).

## Design decisions worth a human glance
- `applyInventoryTransition` exists as a general-purpose primitive (per the
  pack's explicit "Files Created" list) but ServerBuilder's actual call site
  does NOT call it — it appends `status_v2` directly into its own existing
  UPDATE instead. Reasoning is in both the method's docblock and the inline
  comment: guarantees the two columns commit/roll back together with zero risk
  of a second query decoupling them, and matches the checklist item "sync
  writes in same statements/tx as legacy writes" literally (same statement,
  not just same transaction). `applyInventoryTransition` is available for a
  future call site that doesn't already have its own dynamic UPDATE.
- `assertConfigTransition`/`assertInventoryTransition` have **no caller** in
  this unit — confirmed by design (pack: "Pins baseline: yes ... service has
  no enforcing callers yet"). They exist, are unit-tested, and are ready for
  U-SM.4 (StateGuard) to call.
- Inventory reverse map (`StatusMap::INVENTORY_V2_TO_LEGACY`) is a judgment
  call not given verbatim by any pack (only the config one was spelled out) —
  every state between "claimed" and "installed/running" collapses to legacy
  in_use (2); `retired` collapses to failed (0). Flagged in the class docblock.

## Verification performed
**Unit test** (`tests/state_machine_unit.php`, throwaway scratch DB, this
container, dropped after): all 13 assertions PASS — legal/illegal config
transitions with and without permission, no-such-edge rejection, revision +
event bump on apply, `InvalidArgumentException` on an unknown status_v2 value,
`RuntimeException` with no active transaction, `failed->available` correctly
rejected for inventory (edge deliberately absent per U-SM.2), and both
apply* methods writing status_v2 + the mapped legacy column together.

**inventory_report.php Check 3** (separate scratch DB, seeded from the real
`2026_07_10_001`/`2026_07_10_002` seeders + hand-built minimal inventory
tables): GREEN on clean data; then a deliberately mismatched row
(`status_v2='failed'` with `Status=1`) flipped it to RED with exactly the
expected `status_v2_legacy_mismatch` violation — proves the new check works,
mirroring the existing self-test style (not added as a `--self-test` branch
in this unit to stay inside the LOC box; the manual proof above stands in for
it — a follow-up could formalize it as `--self-test` if the human wants belt-
and-braces automation).

## INV-10 (characterization) — NOT run against the golden baseline; here's why that's acceptable
This unit **does** touch two real code paths inside `ServerBuilder.php`
(`finalizeConfiguration`, `updateComponentStatusAndServerUuid`), unlike
U-SM.1/U-SM.2 which were schema-only. `tests/characterize_compatibility.php`
needs the full `ims_compat_golden` DB seeded from a real production dump,
which isn't available in this sandbox — same limitation noted in prior
handoffs. Rather than skip the concern, here's the argument for why this
specific change is verdict-neutral by construction (reviewable without the
dump):
- `finalizeConfiguration`: the old code set `configuration_status = 3, notes
  = ?, updated_at = NOW()` in one UPDATE. The new code sets the exact same
  three effective values (`configuration_status` via
  `StatusMap::CONFIG_V2_TO_LEGACY['finalized'] === 3`, `notes` in a follow-up
  UPDATE, `updated_at = NOW()` inside `applyConfigTransition`) — value-
  identical, just split across two statements plus new bookkeeping
  (`status_v2`, `revision`, one `config_events` row) that nothing downstream
  reads yet. No branch, no return value, no JSON shape changed.
- `updateComponentStatusAndServerUuid`: `status_v2` is *appended* to the
  existing dynamic UPDATE; every other field (`Status`, `ServerUUID`,
  `UpdatedAt`, `InstallationDate`, `Location`, `RackPosition`) is untouched,
  same conditions, same order. The method's return value (`bool` from
  `$stmt->execute()`) is unaffected.
- Neither call site touches `validateConfiguration`, `checkComponentAvailability`,
  or any other function that actually produces a compatibility verdict —
  those are entirely separate code regions from the two write sites this unit
  modified.
A human with access to the real golden DB should still run
`php tests/characterize_compatibility.php` once against this branch before
merging, as the mechanical proof INV-10 asks for — the argument above is a
substitute for reasoning about it now, not a replacement for actually running
it when the dump is available.

## Invariant Check Results
| Invariant | Result |
|---|---|
| INV-2 (spirit: one validation owner) | PASS — no new validation/business-rule logic added to ServerBuilder; only a status-write delegation |
| INV-11 (unit box) | PASS — 5 files changed (2 new classes, 1 new test, 2 edited), 460 new/modified LOC (under 500 after trimming an initial 515-line draft), 0 new seeders, 1 concept |
| INV-10 (pin before change) | Argued verdict-neutral by construction (see above); full golden-DB run not possible in this sandbox — human should run it once before merge |

## Checklist (from pack)
- [x] Service never opens transactions — every method requires `$pdo->inTransaction()` already true, throws otherwise
- [x] Lossy reverse map documented — `StatusMap.php` docblocks, both directions, both machines
- [x] Sync writes in same statement as legacy writes — config: one UPDATE combines status_v2+legacy; inventory: status_v2 appended into ServerBuilder's existing UPDATE

## Next Prompt To Use
U-SM.3 is `implemented`, not `verified` (same caveat as U-SM.1/U-SM.2 — scratch-DB
verification here, not the project's golden/production-mirrored harness; this
unit additionally still owes a real characterization run once the human has
DB access to the golden dump). Once verified:
"Continue the IMS migration. Read migration/00-overview/README.md,
migration/ARCHITECTURAL_INVARIANTS.md, then execute unit U-SM.4 using
migration/03-state-machines/execution-packs/U-SM.4.md. ONE unit only; leave
'verified' to a separate session." U-SM.4 is StateGuard — the first unit that
actually calls assert*Transition to BLOCK an illegal mutation, gated behind
STATE_MACHINE_ENABLED (shadow -> enforce after a 7-day soak per the
checklist). Expect it to be the riskiest unit in this phase.

## Files To Load Into Context (next session)
- migration/00-overview/README.md
- migration/ARCHITECTURAL_INVARIANTS.md
- migration/handoffs/U-SM.3-20260709.md
- migration/03-state-machines/execution-packs/U-SM.4.md

## Expected Context Size
~25k tokens
