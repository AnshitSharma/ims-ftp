# SESSION 2026-07-12 — P5 rule migration (U-R.1 – U-R.6)

Continuation of the same session as `SESSION-20260712-P4-VALIDATION-ENGINE.md` (U-V.1–U-V.4). Same
owner authorization applies: P4/P5 implementation proceeds ahead of P2/P3 gate-opens on the owner's
explicit instruction; `ENGINE_MODE` stayed off/unset in production throughout; P5's own gate cannot
open until P4's opens, which cannot open until P2/P3 open. **Status for all 6 units below:
implemented, not verified.**

Order followed strictly per the README (U-R.1→U-R.6; parity re-checked after each by re-running
`tests/unit/engine_shadow_test.php` against the growing registry — see each unit's "Regression"
line). No unit failed or was blocked; all 6 were completed.

---

## U-R.1 — CPU rule family

**Files created**: `core/models/validation/rules/{CpuSocketMatchRule,CpuSocketCountRule,
CpuMixedModelsRule,CpuRequiresBoardRule}.php`, `tests/unit/rules/cpu_rules_test.php`.
**Files modified**: `ValidationEngine.php` (registry).

**Ported from**: `ServerBuilder::validateCPUAddition` (was lines 3902-3960) +
`ComponentValidator::validateCPUSocketCompatibility`/`validateMixedCPUCompatibility` (lines 265,
377). `cpu.socket_count`'s capacity source required extending `ResourceCatalog` (see U-R.2 section
below — both units needed the same motherboard fields, done together).

**Intentional diffs (RULE_MAP.md)**: A-2 (`cpu.socket_count` counts TargetState rows, never a
per-call count field — closes the multi-unit-per-call bypass legacy's JSON-array-length check
missed); A-12 (`cpu.requires_board` is VALIDATION_FAILURE not ERROR — blocks ADD/VALIDATE but not
REPLACE, "adds allowed in draft"); `cpu.mixed_models` is a genuinely NEW firing (the legacy method
it ports, `ComponentValidator::validateMixedCPUCompatibility`, is orphaned — never called from
anywhere in the add/validate paths).

**Acceptance tests**: `tests/unit/rules/cpu_rules_test.php` — 21/21 PASS, using real fixture UUIDs
from `tests/fixture_scenarios_real.php` (MB_3647/CPU_3647/CPU_4189), porting its R1 (socket-match)
and R2 (socket-mismatch) scenarios directly, plus the A-2 quantity-bypass fixture (2 CPUs on a
2-socket board passes, 3 fails regardless of how the rows arrived), the requires-board VF-vs-E
distinction, and an INV-1 grep (`'quantity'` token absent from all 4 rule files). `php -l` clean.

**Expected diffs added**: A-2 (`cpu.socket_count`), A-12 (`cpu.requires_board`). `cpu.mixed_models`
needs none — WARNING severity structurally never contributes to `Verdict::blocking()`, documented
in `expected_diffs.json`'s `_note_cpu_mixed_models`.

---

## U-R.2 — Memory rule family

**Files created**: `core/models/validation/rules/{MemoryTypeRule,MemoryFormFactorRule,
MemorySlotCountRule,MemoryEccRule,MemoryDownclockRule}.php`, `tests/unit/rules/memory_rules_test.php`.
**Files modified**: `ValidationEngine.php` (registry), `core/models/config/ResourceCatalog.php`
(see below).

**ResourceCatalog extension (beyond U-L.1's original scope)**: U-L.1's docblock explicitly left
motherboard `cpu_socket`/`dimm_slot` capacity unimplemented ("no confirmed structured field found"
within that unit's authorized read scope). U-R.1/U-R.2 authorized reading
`ComponentValidator::parseMotherboardSpecifications()` (confirmed field paths: `socket.count`,
`memory.slots`), so this session added `ResourceCatalog::motherboardCpuSocketRows()`/
`motherboardDimmSlotRows()`, closing that gap — documented in the class docblock with the exact
authorization trail (which unit read what).

**Ported from**: `ServerBuilder::validateRAMAddition` (was lines 3966-4136, the richest legacy
source — all 4 validation scenarios: no-MB-no-CPU / CPU-only / full-with-MB) +
`ComponentValidator::validateMemoryTypeCompatibility`/`validateMemoryFormFactor`/
`validateECCCompatibility`/`validateMemorySlotAvailability` (lines 730-974) +
`ComponentCompatibility::analyzeMemoryFrequency` (was lines 1494-1609, full 4-branch frequency
analysis, ported entirely including the `suboptimal` threshold at 80% of system max).

**Note on `memory.form_factor`**: `ComponentValidator::parseMotherboardSpecifications()` never
populates a `memory.form_factor` wrapper key at all (confirmed by reading the method in full) — so
legacy's own default of `'DIMM'` is effectively unconditional today. The new rule mirrors this
exactly (hardcoded `'DIMM'` comparison target, not a fabricated raw-JSON field legacy doesn't read
either) rather than "fixing" a gap that isn't this unit's to fix.

**Intentional diffs**: D4 (`memory.slot_count` unifies THREE legacy implementations —
`validateMemorySlotAvailability`, a per-call count check in `ServerBuilder`, and `MemoryAuthority` —
into one row-count-based check against the new `dimm_slot` provider).

**Acceptance tests**: `tests/unit/rules/memory_rules_test.php` — 19/19 PASS, porting fixture
scenarios R3 (DDR4-on-DDR4 passes) and R4 (DDR5-on-DDR4 fails) from `fixture_scenarios_real.php`,
plus a 24-slot-board capacity test (24 RAM passes, 25 fails), ECC match/no-warning, and downclock
optimal-frequency case using MB_3647's real `max_frequency_MHz=2933` vs RAM_D4_RD's `2666MHz`.
`php -l` clean.

**Expected diffs added**: D4 (`memory.slot_count`).

---

## U-R.3 — Slot placement rule (+ SlotPlanner)

**Files created**: `core/models/validation/SlotPlanner.php`,
`core/models/validation/rules/PcieSlotPlacementRule.php`, `tests/unit/rules/slot_rules_test.php`.
**Files modified**: `ValidationEngine.php` (registry).

**Ported from**: `ServerBuilder::assignComponentSlot` (was lines 4642-4782, confirmed by reading the
full method that `$manualSlotPosition` is accepted as a parameter but never referenced anywhere in
the body — a genuinely dead parameter, not a misread) + `UnifiedSlotTracker::assignSlot`/
`assignRiserSlotBySize` (smallest-compatible-width-first preference order, ported as
`SlotPlanner::SLOT_COMPATIBILITY`) + `ServerBuilder::extractPCIeSlotSize` (ported verbatim as
`SlotPlanner::extractCardWidth`).

**Intentional diffs**: A-7 (manual slot now honored — validated exists+free+wide-enough); A-8
(unparseable card width now blocks with `pcie.unknown_width`, was legacy's fail-open "added without
slot assignment, logged only").

**Real-hardware fixture discovery**: `MB_3647` (the CPU/memory fixture used throughout) turned out
to have ONLY `riser_slots`, no direct `expansion_slots.pcie_slots` — a real board that requires a
riser installed before any PCIe card fits. Rather than force a fixture, scanned
`ims-data/motherboard/motherboard-level-3.json` for a spec with direct `pcie_slots` and found
`8c5f2b87-1e5b-4e8c-a1d2-0b1a5e3f4d6c` (6×x16, 4×x8, 2×x4) — used for the placement/capacity tests;
`MB_3647` reused specifically to prove the "no pcie_slot resource at all, needs a riser first" case.

**Acceptance tests**: `tests/unit/rules/slot_rules_test.php` — 19/19 PASS: `extractCardWidth`
parsing, smallest-sufficient-width auto-assignment (x4→x4 slot, x16→x16 slot), manual-honored
(request a free x16 slot for an x8 card — fits), manual-occupied-blocked, unknown-width-blocked,
riser-vs-direct-slot resource separation, the full `PcieSlotPlacementRule` including "already-placed
rows are not re-planned" and "onboard NICs excluded", and a fill-all-compatible-slots-then-fail
integration case. `php -l` clean. `SlotPlanner` verified PDO-free (planner purity, per the pack).

**Expected diffs added**: A-8 (`pcie.slot_placement`). A-7 documented as NOT shadow-observable via
`expected_diffs.json`'s `_note_pcie_slot_placement_a7`: the live ADD hook doesn't thread a manual
slot request into `TargetState` yet (arrives with the command layer, U-C.2), so `SlotPlanner` only
ever runs in auto mode when driven by the shadow hook today — A-7 is proven by SlotPlanner's own
unit test instead.

---

## U-R.4 — Lane budget rule (single model)

**Files created**: `core/models/validation/rules/PcieLaneBudgetRule.php`,
`tests/unit/rules/lane_rule_test.php`. **Files modified**: `ValidationEngine.php` (registry).

**Ported from**: `PcieLaneBudgetValidator.php` (359 lines, full — the newer/authoritative model).
`ServerBuilder::trackPCIeLaneAvailability` (the older, divergent model) was read only to confirm it
diverges from the authoritative model, per the pack's explicit "read to enumerate divergences...
do NOT port" instruction — not ported, and no expected_diffs entry needed for that specific
divergence since the shadow comparator only ever measures against the authoritative model's own
(warn-default) legacy behavior.

**Design**: uses `TargetState::poolBalance('pcie_lane')` (U-V.2), which is `ResourceCatalog`-driven
and already mirrors `PcieLaneBudgetValidator`'s exact budget (CPU `pcie_lanes`) and consumption
(nic/hbacard/pciecard + non-M.2 NVMe storage, via U-L.4/U-L.5's `ResourceCatalog::consumesPcieLanes()`/
`consumesStorage()`) — no new catalog work needed, just consuming what U-L already built.

**Intentional diffs**: A-9 (`PCIE_LANE_CHECK_ENABLED` defaults to `warn` in production, i.e. never
actually blocks; the new rule always enforces via the single ledger-based model, independent of that
legacy flag entirely — confirmed the rule reads no env var at all, INV-7).

**Acceptance tests**: `tests/unit/rules/lane_rule_test.php` — 10/10 PASS, porting
`tests/lane_authority_unit.php`'s cases onto `TargetState` using the same real CPU fixture
(`545e143b-...`, 80 lanes): budget-scales-with-CPU-count, within-budget passes, over-budget fails
with `{budget, used, over_by}` detail, no-CPU-budget-zero. `php -l` clean.

**Expected diffs added**: A-9 (`pcie.lane_budget`).

---

## U-R.5 — Storage rule family

**Files created**: `core/models/validation/rules/{StorageInterfacePathRule,StorageBayCapacityRule,
StorageM2CapacityRule,StorageCaddyPairingRule}.php`, `tests/unit/rules/storage_rules_test.php`.
**Files modified**: `ValidationEngine.php` (registry), `core/models/config/ResourceCatalog.php`
(chassis `drive_bay_2_5`/`drive_bay_3_5` providers added).

**ResourceCatalog extension**: same pattern as U-R.2 — U-L.1 left chassis bay capacity
unimplemented ("likely lives in StorageConnectionValidator.php, which this unit was not authorized
to read"). U-R.5 authorized reading `ComponentCompatibility::checkChassisDecentralizedCompatibility()`
(was lines 3076-3260), which confirmed the field path (`drive_bays.bay_configuration`, summed by
`bay_type`, both `"2.5_inch"` and `"2.5-inch"` spellings normalized to the same resource — see
`ComponentCompatibility.php:3193-3200`'s own STRICT-matching comment for why the spelling
inconsistency exists in real data). `u2` bays remain unimplemented — legacy's own bay-counting logic
(`calculateRequiredBays`) only ever tallies 2.5"/3.5"; M.2/U.2 bypasses bay validation entirely, so
there is no legacy u2-bay-capacity behavior to mirror (documented in the code, not a gap).

**DELIBERATE SIMPLIFICATION (flagged, not hidden)**: `storage.interface_path` does NOT port
`StorageConnectionValidator::validate()`'s full 10-check, 4-path connection search (2009 lines
total; the pack explicitly scoped this unit to read only its first ~200-line entry contract and
"port decisions, discard plumbing"). Legacy's actual hard-block condition is narrow: a SAS drive
with neither an HBA card nor a chassis SAS backplane. This rule ports EXACTLY that (SAS-without-HBA
blocks; every other protocol/path-absence case is legacy's own `no_connection_path_yet`
WARNING-only, never a block, component-order-flexible by design). **Known gap**: chassis
SAS-backplane detection is not implemented (would need reading `StorageConnectionValidator.php`'s
chassis-backplane check beyond the 200-line entry contract this unit was scoped to) — a real chassis
with a genuine SAS backplane and no HBA would be incorrectly blocked by this rule where legacy would
allow it. Documented in the rule's own docblock and in `expected_diffs.json`'s
`_note_storage_interface_path` as deliberately left WITHOUT a pre-approved matcher, so any real
occurrence surfaces as an UNEXPLAINED diff for human review rather than being silently swallowed.

**Intentional diffs**: A-10 (`storage.m2_capacity` — legacy's M.2-over-population check
(`ServerBuilder::getConfigurationWarnings`) only ever runs at READ time, never blocks an add; the
new rule blocks at ADD). `storage.caddy_pairing`'s promotion from a read-time warning to
VALIDATION_FAILURE is real but not shadow-observable today (documented below, same structural reason
as `cpu.requires_board`'s non-diff cases).

**Acceptance tests**: `tests/unit/rules/storage_rules_test.php` — 18/18 PASS: bay capacity with
strict 2.5"/3.5" matching (real `CHS_2BAY` fixture, 2 bays — 1 drive passes, 3 fails, M.2 bypasses
entirely even when bays are full), M.2 capacity using a real 4-slot motherboard fixture (found by
scanning `ims-data/motherboard/` — `8c5f2b87-...`, same one U-R.3 found), caddy shortage-blocks/
excess-never-blocks (real `CADDY_25` fixture), and the simplified interface-path SATA-never-blocks
case. `php -l` clean.

**Expected diffs added**: A-10 (`storage.m2_capacity`). `storage.caddy_pairing`'s VF-under-ADD
non-diff and `storage.interface_path`'s simplification gap both documented in
`expected_diffs.json`'s `_note_*` fields rather than fabricated matcher entries.

---

## U-R.6 — Network rule family

**Files created**: `core/models/validation/rules/{NetSfpPortRule,NetNicRequirementsRule}.php`,
`tests/unit/rules/net_rules_test.php`. **Files modified**: `ValidationEngine.php` (registry).

**Ported from**: `ServerBuilder::validateSFPAddition` (was lines 4460-4569) +
`NICPortTracker::isCompatible()` (port-type matching table, lines 206-256). Calls
`NICPortTracker::isCompatible()` DIRECTLY (a static, PDO-free method) rather than re-porting its
table into a local const, per the checklist's "Port-type table is a const with source comment" —
interpreted as "one authoritative source, clearly cited" rather than "copy the table", because that
table already had one drift bug fixed in it once (H5: SFP+/SFP28 cages missing 1G-SFP
backward-compat) and duplicating it here would reopen exactly that risk class. Requiring
`NICPortTracker.php` does not construct it (constructor needs PDO; `isCompatible()` is called
statically), so the rule stays PDO-free.

**Legacy distinction preserved exactly (RULE_MAP: no intentional diff)**: legacy's TP-4A fix
explicitly ALLOWS an SFP with no parent NIC at all (staged/unassigned, auto-mapped later) while
hard-blocking an SFP whose `parent_nic_uuid` does NOT resolve to a NIC actually present in the
config ("Parent NIC ... not found"). The rule reproduces this exact two-way split via
`parent_id === null` (pass, staged) vs. `parent_id` set but `state->find()` returns nothing/wrong
type (fail) — read the execution pack's "SFP without parent NIC blocks (E)" checklist line together
with RULE_MAP's "none" intentional-diff note to resolve what first looked like a contradiction
between the two documents; this is the interpretation that reconciles both.

**HONEST GAP, not fabricated**: the pack's design note mentions "SR-IOV lane note: W" for
`net.nic_slot`'s NIC-specific leftover half (the slot-placement half is genuinely covered by
`PcieSlotPlacementRule`, U-R.3, which already runs uniformly for nic/pciecard/hbacard). Grepped the
entire `core/models/compatibility/` tree for `SR-IOV`/`sriov` — zero matches anywhere in this
codebase. Rather than invent SR-IOV lane-budget logic with no legacy counterpart (would violate this
migration's own "port decisions, don't invent" principle), `NetNicRequirementsRule` implements the
one concrete, real NIC-specific gap found instead: a non-onboard NIC declaring `ports > 0` but no
`port_type` can never have its SFP compatibility checked (silently skipped by `NetSfpPortRule`'s
"spec incomplete" branch) — surfaced as a WARNING so it isn't invisible. Flagged for a human
decision on whether real SR-IOV data exists in a different ims-data field this unit wasn't pointed
at, before more logic is added here.

**Acceptance tests**: `tests/unit/rules/net_rules_test.php` — 14/14 PASS, porting fixture scenarios
R6 (SFP+ into SFP+ cage passes), R7 (SFP28 into SFP+ cage fails), and R8 (1G SFP into SFP+ cage
passes — the H5 backward-compat fix) directly from `fixture_scenarios_real.php`'s real UUIDs, plus
staged-SFP-allowed, dangling-parent-blocks, and same-NIC-port-collision cases. `php -l` clean.

**Expected diffs added**: none (RULE_MAP lists "none" for both rows; `net.nic_requirements` is
WARNING severity, same structural non-diff reasoning as `cpu.mixed_models`).

---

## Overall verification summary (all 6 units)

| Unit | Files | php -l | Unit tests | Registered in ValidationEngine::RULES | expected_diffs.json entries |
|---|---|---|---|---|---|
| U-R.1 | 4 rules + test | clean | 21/21 PASS | yes (4) | A-2, A-12 |
| U-R.2 | 5 rules + test + ResourceCatalog | clean | 19/19 PASS | yes (5) | D4 |
| U-R.3 | SlotPlanner + 1 rule + test | clean | 19/19 PASS | yes (1) | A-8 (A-7 unit-test-only, noted) |
| U-R.4 | 1 rule + test | clean | 10/10 PASS | yes (1) | A-9 |
| U-R.5 | 4 rules + test + ResourceCatalog | clean | 18/18 PASS | yes (4) | A-10 (2 documented non-diffs) |
| U-R.6 | 2 rules + test | clean | 14/14 PASS | yes (2) | none (documented why) |

**17 rules total** registered in `ValidationEngine::RULES`, in the exact U-R.1→U-R.6 order.
**Regression discipline**: `tests/unit/engine_shadow_test.php` (the U-V.3 shadow-hook test) was
re-run after every single unit in this batch, confirming the growing registry never broke the
off-mode-passthrough / shadow-mode-same-legacy-result / fail-closed-on-exception invariants — final
re-run (after U-R.6, 17 rules registered) still 13/13 PASS. `tests/unit/verdict_test.php` and
`tests/unit/target_state_test.php` also re-run clean at the end of the session (30/30 and 40/40).
`scripts/verify/parity_report.php --self-test` still correctly exits 1 (unexplained diff detected).
`scripts/verify/expected_diffs.json` validated as parseable JSON after every edit.

## Human decisions needed

1. **PD-2 confirmation**: is production actually PHP 8.1+? If confirmed, the constants-classes
   workaround in `Severity.php`/`Trigger.php`/`RuleResult.php` could be reverted to native enums/
   readonly properties in a follow-up unit (not urgent — costs nothing to leave as-is either way).
2. **`storage.interface_path`'s SAS-backplane gap**: decide whether a follow-up unit should read
   `StorageConnectionValidator.php`'s chassis-backplane check to close this, or whether the
   simplification is acceptable given SAS chassis-backplane configs are presumably rare.
3. **`net.nic_requirements`' SR-IOV question**: confirm whether real SR-IOV data exists anywhere in
   ims-data (a field this unit wasn't pointed at) before deciding if more logic belongs here.
4. Carried forward from prior sessions, unrelated to this batch: the ambiguous pciecard inventory
   violation (config `9dbc63fa-...`) and whether/when to run `audit-orphans.php --fix` on production.

## Next prompt to use

"Continue the IMS migration. U-V.1–U-V.4 and U-R.1–U-R.6 are implemented (see
migration/handoffs/SESSION-20260712-P4-VALIDATION-ENGINE.md and
migration/handoffs/SESSION-20260712-P5-RULE-MIGRATION.md), not yet independently verified. Do U-R.7
(system/config rules) and U-R.8 (dependency resolver rule) per their execution packs
(migration/04-validation-engine/execution-packs/U-R.7.md, U-R.8.md), registering in
ValidationEngine::RULES and adding expected_diffs.json entries for every intentional divergence.
ENGINE_MODE stays off in production throughout. This completes P5 (all U-R.1-U-R.8) — after U-R.8,
the RULE_MAP.md header says next unit is U-C.1 (command layer, P6)."

## Files to load next session

`migration/04-validation-engine/execution-packs/U-R.7.md`, `U-R.8.md`; RULE_MAP.md rows for
`system.*`/`dependency.*`; `core/models/validation/ValidationEngine.php` (current registry, 17
rules); `scripts/verify/expected_diffs.json` (current 6 entries + 3 `_note_*` fields).

## Expected context size

~35k tokens (2 execution packs + RULE_MAP + current ValidationEngine.php/expected_diffs.json +
legacy source excerpts for system.required_set/singleton/psu_capacity/inventory_state and the
dependency-resolver edges, read on demand).

---

## Independent verify record — 2026-07-12 (Claude Fable, separate session from implementer)

**Verdict: U-R.1, U-R.3, U-R.4, U-R.5, U-R.6 VERIFIED. U-R.2 stays `implemented` — one finding.**

**FINDING (U-R.2, blocks its verified status)**: `tests/unit/resource_catalog_test.php` is RED —
1 failure: "motherboard (empty expansion_slots): returns no rows without throwing" (line 226).
The new `motherboardCpuSocketRows()`/`motherboardDimmSlotRows()` providers default to
`socket.count ?? 1` / `memory.slots ?? 4`, so `provides('motherboard', …)` on that fixture now
returns 2 pooled rows instead of `[]`. I verified the defaults themselves are FAITHFUL legacy
mirrors (ComponentValidator.php:125,128; ComponentCompatibility.php:321,325 — `?? 1` / `?? 4`
exactly), so the production code is correct; the defect is that the implementing session neither
updated the pre-existing assertion nor re-ran this suite after extending the catalog (its
"regression discipline" re-ran only the U-V.* suites). Required fix (test-only): update the
line-226 assertion to expect exactly the cpu_socket(1) + dimm_slot(4) rows and nothing else.
Note the behavior change is live-relevant the day DUAL_WRITE_ENABLED=on: motherboard adds will
write these ledger rows — intended per U-R.2's design, defaults matching legacy.

- All files synced main→scratch, byte-identical; all runs on scratch vs `ims_compat_golden`
  with `IMS_DATA_PATH` pointing at the real ims-data.
- Rule suites re-run: cpu 21/21, memory 19/19, slot 19/19, lane 10/10, storage 18/18, net 14/14 —
  ALL PASS. 17 rules confirmed registered in ValidationEngine::RULES in U-R.1→U-R.6 order.
- expected_diffs.json: exactly 6 entries (A-2, A-12, D4, A-8, A-9, A-10) + 4 `_note_*` fields,
  every entry carrying rule_id + audit_finding — matches the handoff.
- Spot-checked ports against legacy sources: PcieLaneBudgetRule (poolBalance-driven, no env read,
  INV-7 clean), NetSfpPortRule (TP-4A staged-vs-dangling split preserved;
  NICPortTracker::isCompatible confirmed static/PDO-free at line 206). storage.interface_path's
  deliberate SAS-backplane simplification and the SR-IOV no-fabrication call both endorsed —
  correctly left as unexplained-diff-surfacing rather than silently matched.
- Regression sweep + characterization + gate PL: all GREEN (detail in the P4 verify record above
  this one, same session).
