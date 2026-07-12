# SESSION 2026-07-12 — P5 completion (test fix + U-R.7 + U-R.8)

Continuation of the same-day session as `SESSION-20260712-P4-VALIDATION-ENGINE.md` /
`SESSION-20260712-P5-RULE-MIGRATION.md`. Same standing owner authorization applies (P2/P3/P4/P5
gates stay closed on their own human/time-based preconditions regardless of code completeness).
`ENGINE_MODE` stayed off/unset in production throughout. **Status: implemented, not verified.**

---

## Task 0 — Fixed a stale pre-existing test assertion (flagged by the prior session's independent verify)

**File modified**: `tests/unit/resource_catalog_test.php` (line ~226).

The prior session's verify record found `resource_catalog_test.php` RED: the U-R.2 implementer added
`ResourceCatalog::motherboardCpuSocketRows()`/`motherboardDimmSlotRows()` (defaults `socket.count ?? 1`
/ `memory.slots ?? 4`, faithfully mirroring `ComponentValidator.php:125,128`) but never updated the
pre-existing "empty expansion_slots → no rows" assertion, which now legitimately returns 2 rows
(cpu_socket + dimm_slot defaults) instead of `[]`. Fixed the assertion to expect exactly those two
rows and nothing else (test-only change; the catalog code itself was already verified correct).
**Full suite re-run: 49/49 PASS.** This resolves U-R.2's one open finding — it can now be
re-verified by a future session without re-flagging this issue.

---

## U-R.7 — System/config rule family

**Files created**: `core/models/validation/rules/{SystemRequiredSetRule,SystemSingletonRule,
SystemPsuCapacityRule,SystemInventoryStateRule}.php`, `tests/unit/rules/system_rules_test.php`.
**Files modified**: `ValidationEngine.php` (registry, PD-1 exempt), `ResourceCatalog.php`
(cpu/storage/nic/hbacard/pciecard now CONSUME `psu_watt`), `TargetStateBuilder.php` + `TargetState.php`
(added a `status_v2` field to the component row tuple — see below).

**Legacy line-number drift (documented, not a defect)**: the pack's cited `ServerBuilder` ranges
(6541-6608, 5698-5890, 3225-3245) no longer match their described content — prior sessions' edits
(U-V.3's hook rename, etc.) shifted everything below the hook site. Located the real methods by name
instead: `validateRequiredComponents` (6760), `checkPowerCompatibilityDetailed` (5925),
`getChassisPsuWattage` (6082), `validateConfiguration`'s Status===0 non-blocking site (3329). Confirmed
by reading each in full that the content matches the pack's description — this is pure line drift from
earlier units' own edits, not a mismatch worth blocking on.

**`system.required_set` (VF)**: RULE_MAP: "one list = comprehensive's (chassis..nic)" — ported
`validateRequiredComponents()`'s six-type list verbatim (chassis/motherboard/cpu/ram/storage/nic),
retiring the other two divergent lists (`validateConfiguration`'s cpu/motherboard/ram+storage-recommended,
and `getConfigurationWarnings`'s informal set) as this rule's sole concern (INV-2). VF not E, same
"adds allowed in draft" reasoning as `cpu.requires_board` (A-12).

**`system.singleton` (E)**: closes A-5/D3 (four divergent chassis/motherboard singleton
implementations). Deliberately does **NOT** include HBA cards — `validateSingleComponentConstraints()`'s
own H8 bugfix comment (read in full) explicitly says multiple HBA/RAID controllers are allowed;
porting a since-removed constraint would be a regression, not a port.

**`system.psu_capacity` (E, closes V-4)**: legacy's `checkPowerCompatibilityDetailed()` computes an
85%-continuous-ceiling PSU budget but only ever feeds a compatibility *score*, never a block (audit
V-4: "scoring only"). **DOCUMENTED DEVIATION**: legacy's wattage estimate reads free-text per-PHYSICAL-
UNIT `Notes` via regex (2.5W/core cpu, 1W/4GB ram, flat 8W/12W SSD/HDD) — data `TargetState`/
`ResourceCatalog` cannot see (spec_uuid-only design, INV-1). Extended `ResourceCatalog::consumes()`
to sum each type's own **structured** ims-data power field instead (cpu `tdp_W`, storage
`power_consumption_W.active`, nic `power` string, hbacard/pciecard `power_consumption.typical_W`; ram
has no structured power field in ims-data at all — confirmed by reading `ram_detail.json` in full —
so ram contributes 0W, same "no confirmed field" posture as U-L.1's original cpu_socket/dimm_slot
gap). Same 85%-ceiling threshold formula, catalog-native wattage instead of legacy's Notes-regex
guess. **Not expected to numerically match legacy's own estimate on a shared fixture** — flagged for
human review (same posture as U-R.5's storage.interface_path SAS-backplane gap).

**`system.inventory_state` (E, closes V-2)**: `validateConfiguration()` appends a "marked as
failed/defective" *issue* on `Status===0` **without** setting `is_valid=false` (line 3329-3331,
confirmed by reading in full) — a real component marked failed passes both legacy validators. This
rule blocks VALIDATE/FINALIZE when any live component's `status_v2` is failed/retired/maintenance.
**Required a genuine `TargetState` schema extension**: component rows never carried inventory status
at all (only `spec_uuid`, no live DB status). Extended `TargetStateBuilder::fromCurrent()`'s rows-path
to batch-fetch each distinct `inventory_table`'s `status_v2` in one `SELECT ... WHERE id IN (...)`
per table (never per-row), added as a new `status_v2` field on every component row tuple (documented
in both `TargetState.php`'s and `TargetStateBuilder.php`'s class docblocks). **Known gap (same class
as the pre-existing slot_ref/parent-linkage gap)**: json-fallback rows always get `status_v2 = null`
(the legacy JSON blob never stored a per-serial inventory status) — treated as "unknown, cannot judge"
and passes, never fabricating a bad state. This extension went slightly beyond U-R.7's nominal
Files-Modified box (`ResourceCatalog.php` was the only file the pack named) — recorded as a necessary,
documented deviation, same precedent as U-R.2/U-R.5's own ResourceCatalog extensions beyond U-L.1's
original scope.

**Acceptance tests**: `tests/unit/rules/system_rules_test.php` — 35/35 PASS (real fixtures: `CHS_BIG`
b8106f02 for its real `power_supply.wattage=800`, `545e143b` CPU for its real `tdp_W=350`, `19b8d97b`
HBA for `power_consumption.typical_W=14.8`, `da6c533b` NIC for `power="15W"`). `php -l` clean on all
7 touched/created files. Full regression suite (verdict/target_state-shape/all 7 rule-family tests)
re-run clean after this unit.

**Expected diffs added**: none of the four rules are ADD-trigger shadow-observable except
`system.singleton` (traced-uncertain — see `_note_system_singleton` in `expected_diffs.json`);
`system.psu_capacity`/`system.inventory_state` trigger only VALIDATE/FINALIZE, which the current
single ADD-trigger shadow hook never evaluates (documented via `_note_*` fields, same posture as
`cpu.requires_board`'s existing non-diff notes — no fabricated matchers).

---

## U-R.8 — Dependency resolver rule

**Files created**: `core/models/validation/rules/DependencyBlockedRemovalRule.php`,
`tests/unit/rules/dependency_rule_test.php`. **Files modified**: `ValidationEngine.php` (registry),
`TargetStateBuilder.php` (added `dependentsOf()`).

**Design (two mechanisms, both readable from a SINGLE post-removal `TargetState` — `RuleInterface::
evaluate()` never sees a before/after diff)**:
1. **Dangling parent_id**: a live row whose `parent_id` no longer resolves via `TargetState::find()` —
   its parent was just removed. Today only sfp→nic ever populates `parent_id` (confirmed: U-V.2's own
   docblock), so this is exactly legacy's one real case (parity with `removeComponent`'s hard-coded
   nic→sfp special case, ~987), generalized to any future parent_id-linked type.
2. **Structural orphan**: a live row whose type is a `DEPENDS_ON` key (the pack's literal, complete map:
   cpu/ram/riser→motherboard; pciecard/hbacard/nic→motherboard|riser; caddy→chassis;
   storage→hbacard|chassis|motherboard; motherboard→chassis), where NONE of its allowed provider types
   exist anywhere in the post-removal state. Motherboard/chassis are enforced singletons
   (`system.singleton`, U-R.7), so "no provider left" unambiguously means "the one that existed was
   just removed" for those two anchor types.

**Schema quirk handled**: `config_components.component_type` ENUM includes `'riser'`, but NO code path
in this migration actually produces rows with that type (confirmed: `ResourceCatalog::providesPciecard()`
identifies risers via `component_subtype==='Riser Card'` on a `pciecard`-typed row). `providerTypePresent()`
resolves "is a riser present" against that reality (scans live `pciecard` rows' own specs) rather than
against an ENUM value nothing populates.

**DOCUMENTED SIMPLIFICATION**: mechanism 2 is type-level presence, not per-row attachment.
`storage→hbacard|chassis|motherboard` is satisfied by ANY hbacard/chassis/motherboard existing in the
config, not specifically the one a given drive was wired to (no parent_id/slot_ref linkage exists for
storage→hbacard in this schema today) — same class of gap as U-R.5's storage.interface_path
SAS-backplane simplification. Flagged for a human decision alongside that existing flag.

**`TargetStateBuilder::dependentsOf($state, $rowId)`**: a NEW general-purpose primitive — recursive
closure over parent_id children AND resource-consumer links (any row whose `slot_ref` matches a
slot_ref `$rowId`'s own component provides, e.g. a card in a riser-provided slot), pure PHP loop, no
SQL. The rule itself does NOT call this (RuleInterface's single-state signature can't ask "what did
removing X affect" after the fact) — it's exposed for the command layer (this same session's U-C.3
RemoveComponentCommand uses it to compute the cascade-tombstone set) and any future pre-removal UX.

**Acceptance tests**: `tests/unit/rules/dependency_rule_test.php` — 23/23 PASS, covering all six audit
§6 scenarios (board-with-cpus+ram, riser-with-cards, hba-with-drives, chassis-with-bays, nic-with-sfps
parity with the one legacy check, cascade=true passes) plus `dependentsOf()`'s own parent_id-closure
and resource-slot-closure cases. `php -l` clean.

**Expected diffs**: per the pack's own text ("REMOVE ops have no legacy engine hook yet ... unit tests
are the gate here"), no ADD-trigger parity applies. This session's U-C.3 (same batch) DID add a
REMOVE-trigger shadow hook (`RemoveComponentCommand::dryRun()` in `server_api.php`'s
`handleRemoveComponent`), but it logs to a separate `reports/shadow/command-<Ymd>.jsonl` stream not
yet consumed by `parity_report.php` (scoped to the `ENGINE_MODE` stream) — documented as a
`_note_dependency_blocked_removal` entry in `expected_diffs.json`, flagged for a follow-up unit.

---

## Overall summary (test fix + U-R.7 + U-R.8)

| Unit | Files | php -l | Unit tests | Registered | expected_diffs.json |
|---|---|---|---|---|---|
| Task 0 | resource_catalog_test.php (fix) | clean | 49/49 PASS (full suite) | n/a | n/a |
| U-R.7 | 4 rules + test + ResourceCatalog + TargetState/TargetStateBuilder | clean | 35/35 PASS | yes (4) | 3 `_note_*` (non-observable/uncertain) |
| U-R.8 | 1 rule + test + TargetStateBuilder | clean | 23/23 PASS | yes (1) | 1 `_note_*` |

**21 rules total** now registered in `ValidationEngine::RULES` — **this completes P5** (all
U-R.1 through U-R.8). Full DB-free regression sweep (all unit + rule-family tests, `base_command_test.php`)
re-run clean at the end of the whole session (see the P6 handoff for the sweep list).

## Human decisions needed

1. **`system.psu_capacity`'s catalog-vs-Notes-regex deviation** (same class as #2/#3 below): confirm
   whether a follow-up unit should attempt to reconcile the two power models, or whether the
   catalog-native number is accepted as the going-forward source of truth (legacy's own model has no
   access to structured spec data at all, so an exact match is not achievable without inventing one).
2. **`system.singleton`'s ADD-trigger shadow observability** — not traced to certainty this session
   (needs a live/scratch-DB shadow run against a real duplicate-motherboard-add fixture).
3. Carried forward, unrelated to this batch: the ambiguous pciecard inventory violation
   (config `9dbc63fa-...`), whether/when to run `audit-orphans.php --fix`, PD-2's PHP-8-enum question,
   `storage.interface_path`'s SAS-backplane gap, `net.nic_requirements`'s SR-IOV question, and (new,
   from U-R.8/U-R.7) storage's per-attachment tracking gap.

## Next prompt to use

See the P6 handoff (`SESSION-20260712-P6-COMMAND-LAYER.md`) — it covers this session's remaining work
and the actual next starting point (U-A.1, pending a human risk-profile decision).

## Files to load next session

`migration/04-validation-engine/RULE_MAP.md` (for confirmation only — P5 is now complete),
`core/models/validation/ValidationEngine.php` (21-rule registry), `scripts/verify/expected_diffs.json`.

## Expected context size

~15k tokens (this file + RULE_MAP + current registry/expected_diffs.json).

---

## Independent verify record — 2026-07-12 (Claude Fable, separate session from implementer)

**Verdict: Task 0 (test fix), U-R.7, U-R.8 all VERIFIED.** U-R.2's open finding is resolved
(resource_catalog_test 49/49 re-confirmed on scratch) — U-R.2 → `verified` in phase-status.json.

- **MySQL access resolved**: the implementing session's blocker was NOT a lost password — the
  XAMPP MariaDB service was simply stopped. Started `mysqld` and the known scratch credential
  works unchanged. Every DB-backed suite the implementer had to skip was executed this session.
- All files synced main→scratch (byte-identical); suites re-run there against `ims_compat_golden`
  with `IMS_DATA_PATH` set: system_rules 35/35, dependency_rule 23/23, target_state (DB) ALL PASS,
  engine_shadow (DB) ALL PASS, all 6 prior rule suites ALL PASS, full legacy regression sweep
  (state_guard/dual_write/finalized_immutability/nested_transaction/ledger_*) ALL PASS,
  characterization exit 0 (12 configs / 93 replays, baseline git-restored), gate PL GREEN.
- `DependencyBlockedRemovalRule` read in full — DEPENDS_ON map, both mechanisms, the riser-subtype
  resolution, and the cascade semantics all match the handoff. `status_v2` extension reviewed:
  per-table batched IN() fetch, json-fallback null documented in both docblocks.
- Live probe (scratch DB, real config 06ea5abb): RemoveComponentCommand::dryRun() cascade=false and
  cascade=true both evaluated correctly with rollback proven (config_components 0 rows before/after).

**FINDING (parity-readiness, not a unit defect — belongs to the ENGINE_MODE rollout decision)**:
the same live probe shows AddComponentCommand::dryRun()/the engine BLOCKING an add that legacy
allows on this real config (pcie.slot_placement ERROR — json-fallback cards are unplaced
pre-backfill and get re-planned into slots that aren't free; storage.bay_capacity ERROR — the
chassis provider yields 0×2.5" bays for a chassis holding 3 drives). Consequences: (1) flipping
ENGINE_MODE=shadow BEFORE the U-B.4 backfill will drown the parity report in pre-backfill
artifacts — run backfill first; (2) even post-backfill, a fleet-wide offline parity sweep (engine
vs legacy across all golden configs) should run and be triaged before any shadow soak is treated
as meaningful. The 6 expected_diffs entries do not cover these.
