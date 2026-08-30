# IMS backend — open-work register

**Unit:** U-P.2 (`migration/12-post-cutover`). **Opened:** 2026-08-24.

Every entry states: **what**, **why it is open**, **what unblocks it**, and `file:line`
references. Each cites the unit or finding it came from, per U-P.2's checklist. Nothing here is
a nice-to-have restatement of the target design — items are open because something concrete is
unfinished, wrong, or unverified today.

**Location note.** `scripts/ci/invariants.sh:42` and `:317` point readers at
`migration/BACKLOG.md`, and U-P.2's pack names that path too. This file is `ims-ftp/BACKLOG.md`
(root of the backend sub-project) because the register covers more than the migration. The two
pointers in `invariants.sh` should be repointed here in whatever unit next touches that file —
they are comments, so this is cosmetic, but it is a dangling reference. **Repointed 2026-08-29**
(`invariants.sh:44`, `:366`) — both now read `../../BACKLOG.md`.

Status vocabulary: **OPEN** · **BLOCKED** (on a named item) · **OWNER** (cannot be done from a
code session) · **CLOSED** (recorded because a live document still says otherwise).

---

## §A — Logged-but-unfixed code defects

Seven defects that are known, located, and deliberately not patched. Five of the seven form one
chain, which is why they are listed together: **`interface` → `interface_type`**.

### A-1 · `ComponentDataService` renames `interface` to `interface_type` — OPEN

**What.** `extractNicSpecs()` emits `'interface_type' => $component['interface'] ?? ''`
(`core/models/components/ComponentDataService.php:612`). The normalised NIC spec therefore has
no `interface` key, while the ims-data source and every other normaliser use `interface`
(compare `:601` in the immediately preceding extractor, which keeps `'interface'`).

**Why open.** It is the head of A-2…A-5 below. Renaming it back is a one-line change with a
blast radius across every consumer of normalised NIC specs, and the migration's own rule is that
a legacy verdict-producing path is not modified without the characterization suite green on the
preceding commit (INV-10). That suite cannot currently gate (§B-4).

**Unblocks.** A `--diff` mode for the characterization harness (§B-4), then one change that
fixes the producer and audits all consumers together.

### A-2 · `filterNICSpecs()` drops `interface` from the frontend payload — OPEN

**What.** `core/models/compatibility/OnboardNICHandler.php:378` allow-lists
`['controller','model','ports','port_type','speed','speeds','connector']`. Neither `interface`
nor `interface_type` survives.

**Why open.** The filtered array is a *display* payload, but it is persisted into
`server_configurations.nic_config` and later read back as if it were a spec — see A-3. Widening
the allow-list without fixing A-3 would paper over the real defect (a cache being used as an
authority).

**Unblocks.** A-3 first: stop treating the cached blob as a spec source. Then this list becomes
a pure display concern and can be left alone.

### A-3 · `PcieLaneBudgetValidator` trusts the frontend display cache — OPEN

**What.** `core/models/compatibility/PcieLaneBudgetValidator.php:303` reads
`$specs = $nic['specifications'] ?? null;` from the decoded `nic_config` JSON and only falls
back to `ComponentDataService::getComponentSpecifications()` when that key is absent (`:304-306`).
The cached blob is the A-2 display payload, so it never carries a lane width.

**Why open.** Hard rule 3 in `CLAUDE.md` ("never hardcode hardware specifications — load specs
via `ComponentDataService`") is violated in spirit here: a persisted display cache is being used
as a spec authority. Deleting the branch changes lane arithmetic on live configs, so it needs
the parity/characterization evidence path (§B-4).

**Unblocks.** §B-4, plus a decision on whether `nic_config.specifications` is retained at all
after U-D.3 drops the JSON columns.

### A-4 · Lane-width candidate chains omit `interface_type` — OPEN

**What.** Two parsers probe `interface` / `pcie_interface` / `bus_interface` and never
`interface_type`:

- `PcieLaneBudgetValidator::extractLaneCount()` — `PcieLaneBudgetValidator.php:348`;
- `ServerBuilder::extractPCIeSlotSize()` — `ServerBuilder.php:5877-5897`, probing `interface`
  (`:5879`), `slot_type` (`:5885`), `pcie_interface` (`:5891`), returning `null` otherwise.

Fed a `ComponentDataService`-normalised NIC spec (A-1), both parse **nothing**: lanes count as
0, width resolves to `null`.

**Why open.** Fixing the chain here is the cheap half; fixing A-1 is the correct half. Doing
only this would leave two parsers agreeing on a workaround for a rename that should not exist.
Note `SlotPlanner::extractCardWidth()` (`core/models/validation/SlotPlanner.php:37-46`) is a
verbatim port of the legacy parser and inherits the same blind spot — so this is not a
legacy-only issue.

**Unblocks.** A-1.

### A-5 · `assignComponentSlot()` fail-opens on an unparseable width — OPEN

**What.** When `extractPCIeSlotSize()` returns null, the legacy path logs and returns
`['success' => true, 'slot_id' => null, …]` (`core/models/server/ServerBuilder.php:5849-5853`).
The card is added with no slot, invisible to the slot tracker. The comment above it says so
explicitly (`:5845-5848`) and defers the fix to "the data-shape / fail-open remediation".

**Why open and why it matters.** Combined with A-1 + A-4 this is the *cause* of a real
production data defect: `database/seeders/2026_08_24_002_backfill-nic-slot-ref-config-235.sql`
traces the `slotless_card` on config 235 through exactly this chain and repairs the data while
stating that the code is not repaired. The `ADD` path is fixed *forward* —
`SlotPlanner`/`PcieSlotPlacementRule` make an unparseable width an ERROR (audit A-8, declared in
`scripts/verify/expected_diffs.json`) — so at `COMMAND_LAYER_ENABLED=enforce` this branch is not
reached. It becomes reachable again the moment that flag is rolled back.

**Unblocks.** Either U-D.2 deleting `assignComponentSlot` (BLOCKED, §C-2) or A-1 making the
branch unreachable in practice. Do not "fix" it by hard-blocking without A-1: that would start
refusing adds that succeed today.

### A-6 · `AddComponentCommand`'s slot-planning gate omits `risercard` — OPEN

**What.** `core/models/commands/AddComponentCommand.php:82` gates slot planning on
`in_array($this->componentType, ['nic','pciecard','hbacard'], true)`. But `planSlot()` handles
`risercard` explicitly (`:239-241`) and computes `riser_slot` vs `pcie_slot` from it (`:251-253`).
So the riser branch of the planner is dead code reached only from `ReplaceComponentCommand`'s
own gate — which has the same three-type list (`ReplaceComponentCommand.php:105-107`).

**Why open.** A riser added through the command layer gets `slot_ref = null`, i.e. the A-5 class
of defect reintroduced on the *new* path. The 2026-08-14 riser/pciecard split created this: the
list predates `risercard` being a type. Production currently has **no riser units at all**
(`risercardinventory` is empty and zero `pciecardinventory` rows carry a risercard-spec UUID —
all 20 checked), so it is not firing.

**Unblocks.** Nothing external. This is a small, testable change: add `'risercard'` to both
gates and extend `tests/unit/rules/slot_rules_test.php` with a riser fixture. It stays open only
because it is a behaviour change on the live add path and wants its own unit and its own
regression run.

### A-7 · NVMe lane budget under-counts because NIC lanes resolve to zero — OPEN

**What.** `StorageConnectionValidator::checkPCIeLaneBudget()` delegates at
`core/models/compatibility/StorageConnectionValidator.php:1019-1025` to
`PcieLaneBudgetValidator::evaluateAssembledStorageLaneBudget()`. That method's "used" term walks
non-onboard NICs at `PcieLaneBudgetValidator.php:182-191`, fetching specs via
`getComponentSpecifications('nic', $uuid)` (`:187`) — the A-1 normalised shape — and feeding them
to `extractLaneCount()` (`:189`), which cannot read `interface_type` (A-4). **Every discrete NIC
therefore contributes 0 lanes**, so the budget check under-reports usage and can pass an NVMe
drive that should be blocked.

Note `StorageConnectionValidator::extractCardLaneWidth()` (`:1041-1052`) has the same candidate
chain but defaults to **8** rather than 0 (`:1042`, `:1051`) — so the two lane calculators
disagree on the fallback, which is the residue of H4/TP-1B (its own docblock claims they
"derive a card's width the same way", `:1031-1035`).

**Why open.** Same root as A-1. Fixing the lane count changes verdicts on live configs.

**Unblocks.** A-1, then re-run `ledger_report` (which is the independent cross-check: it already
reports `lane_model_mismatch` — ledger_used 8 vs legacy_used 0 — on config 235, §B-7).

---

## §B — Verification, harness, and operational gaps

### B-1 · Three architectural invariants are failing — OPEN / OWNER

Evidence: `reports/archive/invariants-20260824.log` (nightly, 2026-08-24T08:14Z),
RESULT **RED**.

| Invariant | Failure | Disposition |
|---|---|---|
| INV-1/1 | `grep "'quantity'"` returns 3 hits: `core/models/config/ConfigReadRouter.php:68` (a comment), `:454` (`'quantity' => 1` in the legacy output shape), `core/models/validation/TargetStateBuilder.php:295` (reads legacy JSON quantity in the fallback path) | All three are **legacy-compatibility surfaces**, not new quantity semantics. Either amend INV-1's CHECK to exclude the legacy-shape/fallback paths, or wait for U-D.3 to delete them. Do **not** silence it by renaming a variable. |
| INV-8/1 | RETIRED 2026-08-30 — invariant CLOSED with U-D.3c; `equivalence_report.php` deleted | No second store left to fork from. |
| INV-9/1 | 35 seeders under `2026_0[7-9]*` have no paired rollback file, including both 2026-08-24 seeders | Real policy debt (§B-2). |

INV-3 is `info-RED`, correctly: `inv_extract.php` resolves its "After U-C.6" condition against
`phase-status.json`, U-C.6 reads `in_progress`, so the check runs and reports but does not gate.
It gates the moment U-C.6 reads `verified` — see §C-1.

**Unblocks.** Each row separately. Note that until B-1 clears, `nightly.sh` records every day as
RED, which means `soak_status.php` can never certify a GREEN streak — so **U-D.3's 30-day
evidence requirement cannot begin to accumulate** (§C-3).

### B-2 · 35 seeders have no paired rollback (INV-9) — OPEN

**What.** Running INV-9's CHECK verbatim prints `MISSING:` for every seeder from
`2026_07_12_001_add-server-replace-transition-permissions.sql` through
`2026_08_24_002_backfill-nic-slot-ref-config-235.sql`. `database/seeders/rollback/` contains only
two files, both from 2026-08-20/23.

**Why open.** INV-9 says "no exceptions", so this is either 35 rollback files to write or a
scoped amendment to the invariant (e.g. "seeders created by a migration *unit*", which is what
the invariant's text actually means — many of the 35 are ordinary feature/data seeders that
predate or sit outside the migration). Writing 35 rollback files retroactively, some for data
repairs that cannot be inverted, would be theatre.

**Unblocks.** An owner ruling on the scope of INV-9. Whichever way it goes, the CHECK's glob
must be narrowed to match the ruling, or the gate stays permanently red for a reason nobody acts
on — the definition of a check that has stopped being information.

### B-3 · `deploy_skew` is not a gate check — OPEN

**What.** Production carries 16 PHP files that no longer exist locally (146 local vs 162
deployed under the deadcode scan roots). FTP uploads and never deletes.
`reports/deploy-skew-20260824.md` is the full analysis and names the fix: a `deploy_skew` entry
in `scripts/verify/run_all.php`'s REGISTRY that fails when the deployed `php_files_scanned`
exceeds the local count.

**Why open.** The deadcode gate is a *deletion authority* running against the deployed tree, so
its corpus is a superset of the source of truth and **nothing detects when that superset starts
mattering**. Checked 2026-08-24: currently benign (0 production-only files cited by any of the
23 deployed manifest symbols). Benign today is not benign by construction.

**Unblocks.** Nothing. It needs the check written plus a production listing of `api/`, `core/`,
`scripts/` to diff against — the listing is OWNER (FTP client or shell).

### B-4 · The characterization baseline cannot gate — OPEN

**What.** `tests/characterize_compatibility.php` is a **capture** tool: it overwrites
`tests/golden/compatibility_baseline.json` and exits 0 unconditionally (non-zero only for
cannot-connect / json-encode-failed / cannot-mkdir). `run_all.php:92` therefore keeps
`baseline` at `available => false`, with the reasoning inline at `:69-78`: wiring it would be a
**worse** fail-open than SKIPPED, because the gate would rewrite the very baseline it is meant
to check against and then always report GREEN.

**Why it matters beyond the gate.** INV-10 forbids modifying a legacy verdict-producing path
unless the characterization suite passed on the preceding commit. With no compare mode, **INV-10
is unsatisfiable**, which is why every §A defect is "logged, not fixed".

**Unblocks.** Add a `--diff` / `--check` mode that compares against the pinned baseline and
exits non-zero on drift, keeping `--capture` as an explicit opt-in. Then flip
`baseline` to `available => true`. This is the single highest-leverage item in this file: it
unblocks §A wholesale.

### B-5 · `tests/api/*` can pass without running their criteria — OPEN

**What.** `tests/api/add_remove_response_shape_test.php` and `new_actions_test.php` print
per-criterion `SKIPPED` lines, then `ALL CHECKS PASS`, and exit 0 without
`IMS_HTTP_HARNESS_URL`. They never emit the `SKIPPED: 0 check(s) run` marker
`tests/run_tests.php` greps for (`run_tests.php:88`), so the runner counts them as **pass**.

**Why open.** Their offline structural checks are real, so exiting 0 is defensible — but a pass
on those lines never implies the HTTP criteria ran. **U-A.1 must not be promoted on that
signal.** Documented in `tests/MANIFEST.md`.

**Unblocks.** Either emit the runner's ran-nothing marker when the harness URL is absent, or
split each file into an offline suite and a harness-gated suite. Then U-A.1's acceptance
artefact means something.

### B-6 · `ledger_report` and `slot_report` need a `--config` scope — OPEN

**What.** `tests/backfill/ledger_backfill_test.php` asserts "fixture A: ledger_report GREEN" and
"fixture A: slot_report GREEN", but both reports run a **full scan of the whole database**, so
unrelated production data fails an assertion labelled as being about one fixture.

**Why open, and why the test was left RED.** Scoping the assertion to fixture A would turn the
suite green in one edit and was deliberately **not** done: it would retire the only check that
caught the config-235 drift, and narrowing a test until it passes is the exact failure family
already logged as F-11 / F-18 / F-21 / F-24. Two defects are reported instead of reconciled
away: the production data defect (§B-7) and this assertion label/scope mismatch.

**Unblocks.** Give both reports a `--config <uuid>` mode (the other reports already accept
`--since`, so the argument plumbing exists), then point the fixture assertions at fixture A
*and* keep a separate full-scan invocation in the gate. Fixing scope without keeping the
full-scan call would recreate the blind spot.

### B-7 · Production data defect on config 235 — OWNER

**What.** Config `4dee234b-d4ab-447a-95cd-e321313b1af8` (id 235) reports two violations:

- `ledger_report`: `lane_model_mismatch` — ledger_used **8** vs legacy_used **0**, budgets
  agreeing at 40 (`reports/ledger-20260824-081413.json`);
- `slot_report`: `slotless_card` — nic `component_id` **10291** has no `slot_ref`
  (`reports/slot-20260824-081413.json`).

**Why open.** `reports/ledger-20260730-112054.json` and `slot-20260730-190430.json` were both
GREEN, so this is **drift in production data since 2026-07-30 that nothing was watching** — the
only suite asserting these reports go green had never been discovered. `slotless_card` is
precisely the class U-B.4's slot backfill was meant to close. Root cause of the slot half is the
§A-1/A-4/A-5 chain; root cause of the lane half is §A-7 (legacy_used 0 is exactly "every NIC
contributed 0 lanes").

**Unblocks.** `database/seeders/2026_08_24_002_backfill-nic-slot-ref-config-235.sql` repairs the
`slot_ref` (writes `pcie_3_x8`, the value `SlotPlanner::plan()` produces for that card today).
**Not run against production.** The lane half needs §A-7 fixed, not a data repair.

### B-8 · `equivalence_report` RED — one chassis row present only in JSON — CLOSED 2026-08-30

> **Closed by U-D.3c, and it is worth being precise about how.** The config it names,
> `2c7f2dfb`, is not in production any more, so the specific diff is unreproducible. The class
> of defect was closed structurally rather than repaired: the JSON side no longer exists, so a
> component cannot be "in JSON and not in rows". Every configuration's nine columns were copied
> into `server_configurations_json_archive` first, and `2026_08_30_003` refused to drop until
> that archive was complete — so if such a row still existed at drop time, its JSON is preserved
> there rather than lost. `equivalence_report.php` itself is deleted, and INV-8 is closed.
>
> The `referenced_while_available` half of this defect is still checked, by
> `inventory_report.php` Check 2, now resolving through `config_components.inventory_id`.

**Original entry:**

**What.** `reports/equivalence-20260824-081420.json`: 3 configs scanned, 1 diff — chassis
`4981e5a2-74b5-46ed-ac9d-7f9bbfdbc6d5` appears in the legacy JSON of config
`2c7f2dfb-4cc3-4ba9-ae6d-341b5577b556` and **not** in `config_components`. The same chassis
also drives the `referenced_while_available` violation in `inventory_report`
(`reports/inventory-20260824-081420.json`), so it is one defect with two symptoms: the config
references it while the inventory row still reads available.

**Why open.** INV-8 says a dual-write diff is "a blocking defect, never a known issue", and it
gates P2, P6, P8 and P9. It also means the row store is **incomplete for a live config**, which
`partial_rows_report` does not catch (it measures completeness per config against the JSON
fallback and reported all three measured configs COMPLETE — worth re-checking against this diff).

**Unblocks.** Diagnose whether the chassis was added before dual-write, or whether a
`ConfigComponentWriter::afterLegacyAdd()` call was skipped. Then a data repair seeder. Do not
add it to `expected_diffs.json` — that file is for *engine verdict* divergences, not for missing
rows.

### B-16 · Config `1f61541b` claims an SFP unit that no longer exists — CAUSE FIXED 2026-08-30 · one data repair OPEN / OWNER

**What.** `config_components` row 10388 (config `1f61541b-db3e-4541-83eb-da0c78ffa1d8`, type
`sfp`, spec `9a412f95-fe0f-487b-a9a1-443fb0b05172`) points at `sfpinventory` ID 99. That row is
gone: production holds 74 SFP units, IDs 22-100, with 99 absent. The configuration still
displays the SFP in `server-get-config`, sourced from the row rather than from stock.

**How it was found.** By the repaired `scripts/audit-orphans.php`, on its first run after U-D.3c
repointed it at `config_components`. The old JSON-based walk could not have found it: it matched
`UUID = ? LIMIT 1`, and other healthy units of that model exist, so it would have reported green.
Confirmed on live production, not only on the dump.

**The cause, found and closed 2026-08-30.** `BaseFunctions::deleteComponent()` was a bare
`DELETE FROM {type}inventory WHERE id = ?` with no in-use or configuration-reference check, and
both `{type}-delete` and `{type}-bulk-delete` route through it. Deleting a unit a live server
used therefore destroyed the inventory row and left the `config_components` row claiming it
behind — exactly this defect. It was a *known* hazard, written down at
`RequestActionExecutor.php:31` ("still a bare DELETE … it can destroy an inventory row a live
server depends on") as the reason request automation was never given delete rights, and left
standing.

It now refuses with a 409 naming the configuration, and fail-closed if the claim query cannot be
answered. Matched on `(inventory_table, inventory_id)`, never `component_type` — one
serverplatform unit is claimed by both a motherboard row and a chassis row. Deliberately NOT
keyed on `Status`/`ServerUUID`: those drift (§B-9 has 28 mismatches and 4 units at `Status=2`
with no server), so keying on them would refuse deletes whose real problem is a stale column.

Verified on live production, both branches, using only rows the probe created: a claimed unit
returned `409 "This ram is installed in server configuration 33ff4c41-…"` and survived; the same
unit deleted cleanly once released. Pinned by `tests/regression/component_delete_guard_test.php`
(18 checks, no DB required), which was mutation-checked — all four load-bearing assertions
detect the pre-fix implementation.

`ServerBuilder::deleteConfiguration()`, the sibling path, was checked and is NOT affected: it
refuses while components are installed, releases bound units, then purges the rows.

**What is still open (OWNER).** The data repair itself — one row, and it needs a physical fact
this session cannot supply: whether SFP `9a412f95-…` was actually pulled from the machine
(tombstone the row — `php scripts/audit-orphans.php --fix` does exactly that via
`ServerBuilder::removeComponent()`) or the inventory row was deleted in error (restore it from
the dump). No new ones can appear through the API now.

### B-17 · Eight test files lived where the runner never looked — CLOSED 2026-08-30

**What it was.** `run_tests.php` discovered by globbing `SUITE_DIRS`, which listed `api/`,
`backfill/`, `regression/` and `unit/` — never `tests/` itself, where eight real test files sat
unrun. Probed 2026-08-30: three passed outright, one failed, four hard-fataled. Two of the four
required `core/models/compatibility/MemoryAuthority.php` / `SlotAuthority.php`, which do not
exist anywhere under `core/` (CLAUDE.md named all three "authority classes" until this same day
and has been corrected). This was the *exact* defect `run_tests.php`'s own header was written
about, one level up: "all six of the omitted ones were exiting 255 ... the suite had been red
for an unknown length of time while the sweep reported green." A glob cannot drift from the
directory it globs, but it cannot see a directory it was never pointed at either — the
2026-08-24 note about adding `api/`/`backfill/` had already learned this lesson and stopped one
directory short.

**Fixed per file, same day.**

- `lane_authority_unit.php`, `nic_sfp_authority_unit.php`, `storage_bay_authority_unit.php` —
  already passing; just needed discovering.
- `state_machine_unit.php` — 1 FAIL, not obsolete: its minimal fixture schema had no
  `user_permissions` table, so `ACL::loadUserPermissions`'s direct-grant JOIN (added 2026-08-30
  alongside the temporary-access work) threw a schema-probe error and failed every permission
  check closed. Fixed by adding the table, empty — nothing here exercises a temporary grant.
- `getDashboardDataShapeTest.php` — fatal, not obsolete: its `FakePDO` stubbed only `prepare()`,
  and `inventoryTableExists()` (added for the risercard/serverplatform rollout) calls `query()`
  directly. Fixed by stubbing `query()`, and separately by reading its expected type list from
  `VALID_COMPONENT_TYPES` instead of a hand-typed list that had silently stopped covering
  `risercard` and `serverplatform`. Its `check()` helper also printed no `PASS`/`FAIL` lines, so
  the runner counted it as "ran nothing" the moment it was discovered; fixed to print one line
  per assertion like every other suite here.
- `memory_authority_unit.php`, `slot_storage_authority_unit.php` — **deleted**. Their subject
  classes exist nowhere under `core/`.
- `serverstate_equivalence.php` — **deleted**. It asserted
  `ServerState::getComponents() ≡ ServerBuilder::extractComponentsFromJson()`; U-D.3a deleted
  `extractComponentsFromJson()` outright, so one side of the equivalence no longer exists. Same
  class of defect as the two above — a subject gone by design, not by accident — and the same
  disposition `fixture_scenarios_real.php` got: retired, not stubbed back into passing.
- `run_tests.php`'s `NOT_A_SUITE` gained itself (self-recursion), `characterize_compatibility.php`
  (a golden-master CAPTURE tool — running it would overwrite
  `tests/golden/compatibility_baseline.json` every sweep; still not a working parity gate, B-4),
  and `fixture_scenarios_real.php` (deliberately exits 2, not pass/fail).
- The `state_machine_unit.php` DROP-DATABASE landmine this section used to warn about (a
  misrouted `SM_TEST_DB_NAME` destroyed the shared fixture once during this same probe) is now
  enforced in code: the suite refuses to run unless its DB name contains `scratch` and none of
  `golden`/`compat`/`prod`.

**Result.** 50 → 55 discovered, 47 → 52 passed, 0 failed, 3 ran nothing (unchanged — all three
pre-existing and documented in `tests/MANIFEST.md`). See its 2026-08-30 reconciliation note for
the full per-file detail.

### B-9 · `inventory_report` RED — 33 violations — OPEN / OWNER

**What.** 28 × `status_v2_legacy_mismatch`, 4 × `installed_without_server` (sfp
`b6ab72fc-b9b9-4e24-a565-8f4af6eea319`, four serials, `Status=2` with `ServerUUID` NULL), 1 ×
`referenced_while_available` (§B-8).

**Why open.** The 28 mismatches are the direct consequence of seeder
`2026_07_28_001_backfill-missing-status-v2.sql` never having been run — a code-vs-seeder ordering
gap, not a code defect. The 4 orphaned SFP rows are a separate leak.

**Unblocks.** Run `2026_07_28_001`. The SFP rows need their own diagnosis (which config claimed
them, and why the release did not clear `Status`).

### B-10 · CLOSED 2026-08-30 · `expected_schema.json` omitted two tables, and pinned a third key wrongly

**What (as found).** `scripts/verify/expected_schema.json` pinned `status_v2` on ten inventory tables — cpu,
ram, storage, motherboard, chassis, nic, caddy, pciecard, hbacard, sfp — and **not**
`risercardinventory`, even though production's `risercardinventory` does carry
`status_v2 enum(…) DEFAULT NULL` (verified in the repo-root dump). `inventory_report.php:56`
*does* include `'risercard' => 'risercardinventory'`, which is why it reports
`tables_checked: 11`.

**Why it mattered.** `schema_report` read GREEN while silently not asserting those tables. A
schema change dropping or altering their `status_v2` would not have been caught.

**Fixed, and it was worse than logged — two omissions, not one.**
`serverplatforminventory` was missing as well; this entry counted only the 2026-08-14
riser/pciecard split and not the 2026-08-25 platform type. Both carry
`status_v2 enum(…) DEFAULT NULL`, confirmed in the repo-root dump before adding. The file now
pins **12** inventory tables, matching `VALID_COMPONENT_TYPES` exactly.

**And running the gate then found a third, larger problem.** With the tables added,
`schema_report` came back **RED** — on `uq_inventory_once`, expected
`(inventory_table, inventory_id)` but actually `(inventory_table, inventory_id, component_type)`.
That widening is *correct and deliberate*: seeder `2026_08_25_005_widen-inventory-once-key.sql`
made it so one compute-platform unit could back both the motherboard row and the chassis row it
legitimately fills. `expected_schema.json` was never updated to match, so the P1 schema gate had
been RED on a false alarm since 2026-08-25 — the failure mode where a gate cries wolf until
people stop reading it. Expectation corrected to the shipped three-column key.

`schema_report` is now **GREEN** against a production-shaped database, and its `--self-test`
still flags an induced defect, so the GREEN is not vacuous.

### B-11 · Three flag-promotion dates are unrecoverable — OWNER

**What.** `reports/cutover-signoff-20260822.md` §1 records `DUAL_WRITE_ENABLED=on`,
`STATE_MACHINE_ENABLED=enforce` and `ENGINE_MODE=enforce` with "not recorded" promotion dates.
No session record was written; `FLAGS.md` carries no dates. Only the 2026-08-21 pair
(`COMMAND_LAYER_ENABLED`, `READ_FROM_ROWS`) is dated, from the owner's report corroborated by
`.env` mtime.

**Why open.** INV-8's audit intent is partially unmet on those rows: there is no way to bound
which traffic ran under which regime. The sign-off explicitly assigns recovery to U-P.2.

**Unblocks.** Server-side evidence only — `.env` backups, host file-modification history, or
hosting-panel logs. If nothing survives, record "unrecoverable" as the final answer in
`FLAGS.md` rather than leaving the row blank, and add a dated row to `FLAGS.md` for every future
promotion.

### B-12 · `P1`'s gate is `open` while `U-1.5` is `implemented` — OPEN

**What.** `migration/phase-status.json`'s own legend says a phase gate may read `open` only when
**all** its units are `verified`. P1's gate reads `open`; `U-1.5` reads `implemented`. The gate
was opened before the 2026-07-27 demotion and never re-closed.

**Why open.** The file is machine-read (`scripts/ci/inv_extract.php` resolves phase-conditional
invariant checks against it), so an internally inconsistent state file is not cosmetic.

**Unblocks.** Either verify U-1.5 or close P1's gate. An owner call on which.

### B-13 · Cross-node config-cache staleness — OPEN (accepted risk, A-4)

**What.** `ServerBuilder::getConfigurationDetails()`'s file-based config cache is per-node.
`BaseCommand::afterCommit()` (`core/models/commands/BaseCommand.php:155-157`) is the single
invalidation site and fixes same-node staleness; cross-node staleness is unaddressed.

**Why open.** Recorded as assumption A-4 in `migration/PLAN_VERIFICATION_REVIEW.md` (F-10's
list): "multiple PHP-FPM nodes share one MySQL; row locks are the concurrency truth… cross-node
staleness is a pre-existing condition, unchanged by this migration, backlog candidate."

**Unblocks.** Only relevant if the deployment becomes multi-node. Verify the current topology
before spending anything here.

### B-14 · CLOSED · shared scratch-DB credential resolver

**Recorded because three live documents still say it is open.**
`tests/regression/_scratch_db.php:78-92` now defines `test_db_password(string $prefix)` with one
documented precedence (`{PREFIX}_PASS` → readable non-blank `{PREFIX}_PASS_FILE` → `''`,
returned not thrown). `scratch_db_password()` (`:93-96`) binds the `GOLDEN_DB` family;
`tests/state_machine_unit.php:29` uses `SM_TEST_DB` and `tests/fixture_scenarios_real.php:34`
uses `PROBE_DB`. **20 test files call it** — every DB-backed suite.

Stale claims to ignore: `scripts/ci/invariants.sh:36-42`,
`migration/phase-status.json`'s `root_cause_NOT_fixed`, and
`reports/regression-green-20260824.md` ("a shared `_scratch_db.php` credential resolver … is NOT
done here"). Repoint or delete those notes when next touching those files.

### B-15 · CLOSED · `partial_rows` registration

**Recorded because `phase-status.json` says it is pending.** `partial_rows` is registered at
`scripts/verify/run_all.php:91` (`available => true`) and P9's gate list at `:133` reads
`['deadcode', 'partial_rows', 'equivalence', 'regression']` (both `partial_rows` and
`equivalence` left that list on 2026-08-30 when U-D.3c retired both reports; P9 is now
`['deadcode', 'deploy_skew', 'regression']`). The `registration_PENDING` note in
`phase-status.json` ("drafted but NOT applied — another agent held run_all.php") is stale.

---

## §C — Units blocked, and the rulings behind them

### C-1 · U-C.6 — transaction-ownership consolidation — BLOCKED, and its scope is wrong as written

**What the pack asks for.** `migration/05-command-layer/execution-packs/U-C.6.md`: guard the four
`ServerBuilder` mutation entries so that at enforce they are reachable only via commands, make
`OnboardNICHandler::replaceOnboardNIC()` begin-guarded, and make INV-3's CHECK pass with a
documented allowlist.

**The ruling — three facts the pack does not account for.**

1. **`deleteConfiguration()` has no command replacement.**
   `core/models/server/ServerBuilder.php:4461`, with its own `beginTransaction` at `:4469`. The
   target design lists `DeleteConfiguration` as a command
   (`migration/00-overview/IMS_TARGET_ARCHITECTURE.md` §6); `core/models/commands/` contains four
   commands and it is not among them. A `CommandGate::require()` guard on this method at enforce
   would make configuration deletion **unreachable**, not migrated.
2. **`finalizeConfiguration()`'s entry is already the U-C.5 shim.**
   `ServerBuilder.php:4308-4327` delegates to `TransitionStatusCommand` at enforce and falls
   through to the legacy body otherwise. Adding a caller-must-be-a-command guard on top of a
   method that *is* the dispatcher would gate the dispatcher against itself. This entry needs no
   guard; it needs its legacy body deleted by U-D.2, which is itself blocked.
3. **`scripts/audit-orphans.php:190` calls `removeComponent()` directly.**
   `$result = $sb->removeComponent($o['config_uuid'], $o['type'], $o['uuid'], $o['serial']);` in
   `--fix` mode. This is a live operational tool — the orphan remediation path — and the target
   design's own exit criteria demote it to a CI check rather than deleting it. A guard on
   `removeComponent()` at enforce breaks it unless the script is repointed at
   `RemoveComponentCommand` first.

**Why open.** Executing the pack literally would ship two regressions (1 and 3) and one
tautology (2). Per the migration's own protocol, a pack that conflicts with reality is a STOP,
not an improvisation.

**What unblocks it.** In order: (a) an owner ruling on `deleteConfiguration` — build
`DeleteConfigurationCommand`, or exempt the method from the guard and record it as a permanent
INV-3 allowlist entry; (b) repoint `scripts/audit-orphans.php` at `RemoveComponentCommand`, or
allowlist it as a command-equivalent caller; (c) drop the `finalizeConfiguration` guard from the
pack's scope. Note U-C.6's `COMMAND_LAYER_ENABLED=enforce` soaked-7-days precondition was
**waived** by owner decision 2026-08-22, so calendar time is not the blocker.

Also note INV-3's real surface is wider than the pack's grep map: `beginTransaction` appears in
`OnboardNICHandler.php:67` and `:468`, `ServerConfiguration.php:143`, `core/auth/ACL.php` (3),
`core/helpers/BaseFunctions.php` (3), `PipelineManager.php` (6),
`PipelineTemplateManager.php` (3), `api/handlers/auth/auth_api.php` (2),
`api/handlers/server/compatibility_api.php:778`, `api/handlers/server/server_api.php:688` and
`:1632`, `api/handlers/vendors/vendor_api.php:156`, plus five `scripts/verify/*` reports. Most
are outside the server-build domain INV-3 is about; the allowlist needs to say so explicitly
rather than leaving a 16-file grep result to be re-triaged every run.

### C-2 · U-D.1 and U-D.2 — legacy deletion — BLOCKED on C-1

**What.** U-D.1 deletes the Phase-1.5 pairwise loop
(`ServerBuilder::validateComponentCompatibility`, `:5931`) and the shadow dispatch branches.
U-D.2 deletes the full-config validators, the read-time warnings, the authority classes and
`PcieLaneBudgetValidator`, and creates `core/models/validation/ValidateConfigService.php` to
re-wire `server-validate-config`.

**Why blocked.** Four independent reasons:

1. Both are additionally gated on U-C.6 reading `verified`.
2. **P9 is a rewrite, not a deletion.** Census against the deployed tree: ~4,900 lines across
   ~9 modified and 5 deleted files. `ValidateConfigService.php` **does not exist** and
   `server-validate-config` is a live public action, so U-D.2a is a *build* before it is a
   delete. `PcieLaneBudgetValidator` has 3 live consumers — a port, not a delete.
   `checkComponentPairCompatibility` must be **retained** (live in `getCompatibleComponents()`
   and in `TicketValidator`, and `compatibility_api.php` is a documented external contract).
3. **U-D.2 and U-D.4 are coupled.** The `off` and `shadow` branches still call
   `ServerBuilder::legacyValidateComponentAddition()` (`:5273`), so deleting it forecloses
   rolling `COMMAND_LAYER_ENABLED` or `ENGINE_MODE` back. Deleting the flags (U-D.4) is what
   makes the deletion coherent.
4. The deadcode gate is not green: 26 manifest symbols, **14 RED**
   (`reports/deadcode-20260824-013731.json`), and the repaired 26-symbol manifest **has not
   reached production** (the deployed scan still reports 23), so the four newly-GREEN subgraph
   symbols cannot be gate-confirmed production-side.

**Unblocks.** C-1, then the manifest sync, then build `ValidateConfigService`, then delete in
the one commit Phase B collapsed into. Two cautions carried forward: never delete a GREEN
*cascade* symbol without confirming its parent target is also GREEN (`extractPCIeSlotSize` is
GREEN only because `assignComponentSlot` is a target — and `assignComponentSlot` is RED because
live `addComponent()` calls it); and re-run `server-debug-deadcode` against the deployed tree
immediately before any deletion (§B-3).

### C-3 · U-D.3 — drop the legacy JSON columns — **DONE 2026-08-30**

> **This entry's recommendation was overtaken by events and is kept only as the record of what
> had to be true first.** The owner ran all three seeders against production on 2026-08-30;
> `server-list-configs` no longer carries any of the nine columns, every configuration reads
> 200, and a live create -> add -> read -> remove -> delete cycle succeeds end to end. What
> actually happened to each objection below:
>
> 1. **Sign-off** — the owner's decision, taken and executed.
> 2. **30-day GREEN streak** — never accumulated. Replaced by direct evidence rather than
>    waived: the drop was rehearsed on a clone of the production dump, and the full suite ran
>    GREEN against a database with the columns already gone before anything touched production.
> 3. **§B-8's diff** — it named config `2c7f2dfb`, which no longer exists. The general case was
>    resolved by ARCHIVING instead of repairing: `2026_08_30_002` copies all nine columns for
>    every configuration into `server_configurations_json_archive`, and `2026_08_30_003`
>    refuses to drop unless that archive is complete. Nothing was deleted without a copy.
> 4. **Ordering** — U-D.1 and U-D.2 were completed by P9 before this ran.
> 5. **The `!empty($rows)` selection** — fixed, not merely detected. `fromCurrent()` has no JSON
>    branch left to fall back to; it returns an empty TargetState. "Partly mirrored" is not a
>    state that can exist once there is no second store to be half of.
> 6. **Live data defects** — the row side now carries its own cross-check. `audit-orphans.php`
>    and `inventory_report.php` Check 2 resolve each claim to one exact unit through
>    `config_components.inventory_id`, which is stricter than the JSON cross-check ever was: it
>    immediately found one the old instrument could not (§B-16).
> 7. **The equivalence checker** — retired rather than converted, with INV-8 closed alongside
>    it. Its rows-vs-inventory half lives on in `inventory_report.php` Check 2, which was
>    written, mutation-probed and proven BEFORE `equivalence_report.php` was deleted.
> 8. **Deploy skew** — unchanged and still open (§B-3). The 16 production-only files remain a
>    real residual risk against a dropped column; none was hit by any live probe.
>
> One deviation from the pack: `motherboard_uuid` and `chassis_uuid` were NOT dropped. The pack
> says to drop them, but they are still written and read as scalars, so removing them was out of
> this unit's scope.

**Original entry, unedited:**

This is the point of no return: `ALTER server_configurations DROP COLUMN` across nine JSON
columns plus `hbacard_uuid`, `motherboard_uuid` and `chassis_uuid`, with a restore *procedure*
rather than a rollback SQL (the one INV-9 exemption).

**Recommendation: do not run it. Reasons, each independently sufficient:**

1. **Its own sign-off is unsigned.** `reports/cutover-signoff-20260822.md` §6 is blank, and
   U-D.3's precondition is that block being *filled in*, not the file existing.
2. **The 30-day daily-GREEN evidence does not exist and cannot currently accumulate.**
   `reports/archive/` holds exactly one day (`battery-20260824.json`), and that day is RED. Until
   §B-1 clears, `soak_status.php` cannot count a single GREEN day.
3. **INV-8 is RED right now.** One config's chassis exists in JSON and not in rows (§B-8).
   Dropping the JSON side deletes the only copy of that component.
4. **Order violation.** `migration/10-cleanup/README.md` mandates strict U-D.1 → U-D.4 ordering;
   U-D.1 is `in_progress`, U-D.2 `not_started`, and both are blocked (C-2).
5. **The JSON columns are still a live fallback.** `TargetStateBuilder::fromCurrent()`
   (`core/models/validation/TargetStateBuilder.php:41`) selects the rows path on `!empty($rows)`
   — non-empty, not complete. While that gap is unfixed, the JSON columns are what makes a
   partially-backfilled config recoverable. Dropping them converts a detectable gap into
   silent data loss.
6. **Live data defects are open on the row side.** Config 235 carries both
   `lane_model_mismatch` and `slotless_card` (§B-7), and 28 inventory `status_v2` mismatches
   remain because seeder `2026_07_28_001` was never run (§B-9). The JSON side is currently the
   cross-check that found them.
7. **The equivalence checker itself changes meaning.** U-D.3's pack retires the JSON half of
   `equivalence_report` and converts it into a rows-vs-inventory consistency check. That is a
   new instrument, and it should be written and proven *before* the thing it replaces is gone.
8. **Deploy skew is unresolved** (§B-3). Sixteen unknown PHP files on production, any of which
   could read a dropped column.

**What would unblock it** (all of it, not a subset): C-2 complete; §B-1 clear so a GREEN streak
can accumulate; §B-8 diff resolved; the `!empty($rows)` selection fixed rather than only
detected; a restore-tested logical backup with 90-day retention — the one precondition already
**met** (the repo-root dump restored into a pristine MariaDB 10.4.32 datadir, 46/46 tables, 1/1
trigger, exit 0, 7.3 s, zero restore-induced loss); and a signed §6 block.

### C-4 · U-D.4 — delete the flags and temp guards — BLOCKED on C-2/C-3

**What.** Delete the five migration flags (readers become constants), the `TEMP-GUARD(U-0.2)`
markers, and the legacy authority flags.

**Why blocked.** `DUAL_WRITE_ENABLED`'s reader can only become a constant after U-D.3 drops the
JSON columns. Deleting `COMMAND_LAYER_ENABLED`/`ENGINE_MODE` forecloses the rollback that
U-D.2's remaining legacy body still provides (C-2, reason 3). Two concrete corrections to the
pack's target list, already recorded in its own 2026-08-24 appendix:

- the marker count is **12**, not 11 — `STORAGE_BAY_AUTHORITY_ENABLED` was missed by earlier
  counts and `MEMORY_AUTHORITY_ENABLED` was absent from the Targets list;
- `STORAGE_BAY_AUTHORITY_ENABLED` has a **live replacement-side reader** in
  `core/models/validation/rules/StorageInterfacePathRule.php`, so it is not residue and cannot
  be cleared by grep. `PCIE_LANE_CHECK_ENABLED` likewise has a reader in
  `core/models/validation/rules/PcieLaneBudgetRule.php`.

`TEMP-GUARD` currently appears **12 times** under `core/` + `api/`.

**Unblocks.** C-3, plus resolving the two rule-file readers by hand.

### C-5 · `replaceOnboardNIC` — a shipped regression, not dead code — OPEN / OWNER

**What.** `migration/10-cleanup/FINDING-20260824-replaceOnboardNIC-not-superseded.md`. The
dead-code gate rates `OnboardNICHandler::replaceOnboardNIC()` (`:449-575`) GREEN — 0 blocking, 0
internal callers, confirmed by tree-wide grep. **Do not delete it.**

- It contains the **only** `Flag = 'replaced'` write in the codebase
  (`core/models/compatibility/OnboardNICHandler.php:530`). Three surviving branches read that
  flag and exist only to honour it: `:108` (skips a replaced port when a motherboard is
  re-added), `:420` and `:421` (`Status`/`status_v2` `CASE WHEN Flag='replaced'` on detach).
  Deleting the producer makes all three permanently take the `ELSE` branch — syntactically
  reachable, semantically dead forever, and the gate would then rate *them* GREEN on the same
  reasoning, dismantling the TP-4C invariant one individually-justified piece at a time.
- **The supersession claim is false.** `IMS_TARGET_ARCHITECTURE.md:226` says it is
  "reimplemented as a ReplaceComponent specialization". `ReplaceComponentCommand.php:105-107`
  explicitly **excludes** `onboard-` UUIDs and `onboard` appears nowhere else in that file. So
  onboard-NIC replacement is **already unreachable from the API** — a regression that shipped,
  and this function is the last surviving specification of the behaviour.
- Current exposure: zero `nicinventory` rows carry `Flag='replaced'` in the 2026-08-24 dump. The
  exposure is capability loss, not data corruption.

**Disposition (owner).** (a) Mark the manifest entry `retain: true` with this finding as the
reason so the gate stops proposing it. (b) File the real unit: either extend
`ReplaceComponentCommand` to handle `onboard-` NICs (the genuine U-C.4 completion), or record an
explicit product decision that onboard-NIC replacement is withdrawn — in which case the three
consumer branches and the `Flag='replaced'` vocabulary come out **together**, as one reviewed
change.

### C-6 · Sole-writer detection in `deadcode_scan.php` — OPEN

**What.** The scanner asks one question: does any file name this symbol? It cannot ask the one
that decides whether deletion is safe: does anything still depend on state this code is the only
producer of? C-5 is the worked example.

**Why open.** This is the durable lesson from C-5 — the same fail-open family as the rest of the
migration, a check returning a verdict because it could not see the thing that mattered.

**Unblocks.** Nothing external. Implement sole-writer detection in
`scripts/verify/deadcode_scan.php`, or at minimum a declared `hazard` field in
`scripts/verify/deadcode_manifest.json` that the report surfaces. Note the scanner's
`deadcodeReferencePattern()` understands only `class` and `method`, which is also why the U-D.4
*markers* can never be gated by it and are grepped by hand.

### C-7 · Maintenance-mode hardware swap is not operable — OPEN

**What.** The target design's sanctioned swap path (`deployed → maintenance → deployed`, with
re-validation on exit, §3.1/§7.2) does not exist as a flow. `StateGuard` already admits
`maintenance` as mutable (`core/models/state/StateGuard.php:27`) and `StatusMap` maps it
(`core/models/state/StatusMap.php:53`), but `TransitionStatusCommand` is scoped to finalize-only
transitions (`TransitionStatusCommand.php:14-27`), and the guard's own message says so out
loud: *"Move it to maintenance (not yet available) or unfinalize via an administrator"*
(`StateGuard.php:100-101`).

**Why open.** U-P.2's pack asks for a "maintenance-mode swap runbook for operators". There is no
runbook to write, because there is no supported flow — writing one would document a capability
that does not exist. `docs/OPERATIONS.md` §8.4 records the honest version instead.

**Unblocks.** A unit that adds a general transition command (or widens
`TransitionStatusCommand`'s scope with its own trigger handling — its docblock explicitly warns
against reusing it as-is) plus the `config_status_transitions` rows and ACL permission for the
`maintenance` edges. Until then a swap on a finalized config is an owner/DBA action. Do **not**
work around the guard by editing `configuration_status` directly: that desynchronises
`status_v2`, `revision` and `config_events`, and INV-6's check will report it.

### C-8 · Two DB-backed suite failures are unreconciled — OPEN

**What.** The archived nightly (`reports/archive/invariants-20260824.log`, 2026-08-24T08:14Z)
ran the `tests/regression/` + `tests/unit/rules/` subset — 24 suites, **22 passed, 2 failed**:

- `read_router_test.php` — `=on preserves component IDENTITY on all 3 configs (2 matched)`;
- `remove_command_test.php` — `the SAME removal with cascade=true does not block on
  dependency.blocked_removal`.

Both failures are DB-backed assertions, and **both suites are recorded as passing** in
`reports/regression-green-20260824.md`'s enumerated 41.

**Why open.** Either the fixture state differed between the two runs (plausible — one config in
the restored dump has a JSON-only chassis, §B-8, which is exactly what an identity comparison
across 3 configs would catch, "2 matched"), or a change landed between them. Until it is
reconciled, **no suite count in this repo should be quoted as evidence of the suite being green
against a provisioned fixture.**

**Unblocks.** One re-run from a rebuilt-pristine fixture with both runners
(`php tests/run_tests.php` and `sh scripts/ci/invariants.sh`) in the same session, and a
recorded per-suite comparison. If `read_router_test` is failing *because* of §B-8, say so and
the suite is telling the truth.

---

## §D — Pack, criterion and document corrections

### D-1 · Amend U-A.1's acceptance criterion — OWNER

**What.** `migration/08-api-adapters/execution-packs/U-A.1.md`'s checklist reads
`- [ ] No SQL left in either handler`. That is **unsatisfiable as written**: the owner's own
2026-07-12 decision kept the SFP auto-assign SQL, which is still live at
`api/handlers/server/server_api.php:771` (`UPDATE sfpinventory SET ParentNICUUID = ?, PortIndex = ?
…`) and `:779` (`UPDATE server_configurations SET sfp_configuration = ? …`).

**Unblocks.** An owner amendment to the criterion naming the retained SQL and its rationale.
U-A.1 cannot be promoted to `verified` against a criterion that contradicts a standing owner
decision — and separately must not be promoted on `tests/api/*` output (§B-5).

### D-2 · Amend U-L.1's acceptance criterion — OWNER

**What.** `migration/06-resource-ledger/execution-packs/U-L.1.md`'s Tests section requires
`grep -rn "ResourceCatalog" core/ api/ | grep -v config/ResourceCatalog\|tests` to be **empty**.
It is not, and by later units' design it cannot be: **13 files** legitimately reference
`ResourceCatalog` — `ConfigComponentWriter.php`, `TargetState.php`, `TargetStateBuilder.php`, six
rule files (`CpuSocketCountRule`, `MemorySlotCountRule`, `PcieLaneBudgetRule`,
`StorageBayCapacityRule`, `StorageM2CapacityRule`, `SystemPsuCapacityRule`),
`scripts/backfill/backfill.php`, `scripts/backfill/repair-onboard-nic-rows.php`,
`scripts/verify/ledger_report.php`, and `scripts/verify/deadcode_manifest.json`.

**Unblocks.** An owner amendment. The criterion made sense the day U-L.1 landed (nothing should
have referenced a brand-new class yet) and became false the moment U-L.2 wired it.

### D-3 · U-V.2 is promotable — OWNER

**What.** `migration/04-validation-engine/execution-packs/U-V.2.md` lists exactly one acceptance
artefact: `tests/unit/target_state_test.php`. It passed on 2026-08-24, in a session that did
**not** author `TargetState.php` or `TargetStateBuilder.php` — which discharges the 2026-07-28
self-certification objection that demoted the unit from `verified` to `implemented`.

**Unblocks.** An owner setting `U-V.2` to `verified` in `phase-status.json`. Left un-applied
deliberately rather than self-certified.

### D-4 · U-R.5's HONEST_LIMIT is discharged; the unit still cannot be promoted — OWNER

**What.** The 2026-08-19 instruction on `U-R.5` ("RUN `storage_rules_test.php` IN AN ENVIRONMENT
WITH ims-data BEFORE TRUSTING THE ENGINE HALF") is **discharged**: it ran with `IMS_DATA_PATH`
set and passed.

**Why still open.** U-R.5's other criterion is a shadow-log parity criterion, and it is
**structurally unobtainable at `ENGINE_MODE=enforce`** — the enforce branch no longer calls the
legacy body, so no comparison row is ever written (see
`core/models/server/ServerBuilder.php:5200-5226`, which documents that cost deliberately).

**Unblocks.** An owner ruling: either accept the unit test as the acceptance evidence and amend
the criterion, or temporarily return `ENGINE_MODE` to `shadow` to collect parity rows. The
second option is a production change for a bookkeeping reason and is probably the wrong trade —
but it is the owner's call, not a code session's.

### D-5 · Stale claims found in live documents — OPEN

Each of these is currently believed by some document and is false about today's code. Left as
edits for whichever unit next touches the file (this unit does not modify `.php`, `.sql`, or
migration files).

| Document | Claim | Reality |
|---|---|---|
| `migration/00-overview/IMS_TARGET_ARCHITECTURE.md:226` | `replaceOnboardNIC` "is reimplemented as a ReplaceComponent specialization" | Never happened; `ReplaceComponentCommand.php:105-107` excludes `onboard-` (C-5) |
| `core/models/validation/TargetStateBuilder.php:13` | JSON fallback is "today's actual production reality, since `DUAL_WRITE_ENABLED` is off" | `DUAL_WRITE_ENABLED` has been `on` since ~2026-07-19; the rows path is the normal path |
| ~~`scripts/ci/invariants.sh:36-42`~~ | only `serial_less_unit_identity_test.php` honours `GOLDEN_DB_PASS_FILE` | 20 test files now share one resolver (B-14). **CORRECTED 2026-08-29** |
| `migration/phase-status.json` `root_cause_NOT_fixed` | shared credential resolver "is the real fix; ~10 test files. BACKLOG" | Done 2026-08-24 (B-14) |
| `migration/phase-status.json` `registration_PENDING` | `partial_rows` registry entry + P9 gate addition "drafted but NOT applied" | Applied: `run_all.php:91`, `:133` (B-15) |
| `reports/regression-green-20260824.md` | the credential resolver "is NOT done here" | Done later the same day (B-14) |
| `tests/MANIFEST.md` §1 | 45 discovered files, `tests/regression/` = 15 | 46 discovered today; `regression/` = 16 — the table omits `transaction_ownership_test.php`, which exists and passes |
| `migration/README.md` | "`ServerBuilder.php` is 8,132 lines" | 9,586 lines |
| `migration/10-cleanup/execution-packs/U-D.4.md` appendix | `COMMAND_LAYER_ENABLED` and `READ_FROM_ROWS` "terminal value NOT yet reached (as of 2026-07-30)" | Both reached terminal values 2026-08-21 |
| `migration/10-cleanup/execution-packs/U-D.2.md` cascade note | harness call sites `scripts/verify/ledger_report.php` "57, 166, 226" | Actual `PcieLaneBudgetValidator` sites are `:52` (require), `:63` (type hint), `:172`, `:288`, `:308` (three `new`) — **five**, not three. The pack's "type hint + two `new`" is an undercount, so an editor following it would miss two call sites |
| `migration/handoffs/PL-GATE-20260707.md` outstanding items | RV-4 (M.2 lane exclusion) open | Fixed — `core/models/config/ResourceCatalog.php:275`, `:295-298` (E-4) |
| `ims-ftp/CLAUDE.md` "Deep reference" | points at `docs/api/API_REFERENCE.md`, `docs/architecture/DATABASE_SCHEMA.md`, `docs/architecture/ARCHITECTURE.md`, `docs/development/DEVELOPMENT_GUIDELINES.md` | None of those existed; `docs/` was created by this unit and contains only `ARCHITECTURE.md` and `OPERATIONS.md` |

The last row deserves a note: `ims-ftp/CLAUDE.md` has been directing readers to four
non-existent documents. `docs/ARCHITECTURE.md` now exists but is not the "request-flow detail"
document that entry describes. Either write the missing ones or correct the pointer list.

### D-6 · CLOSED · U-P.1 is complete but reads `not_started`

**Closed 2026-08-29.** The negative proof was run and archived
(`reports/inv5-negative-proof-20260829.md`); `U-P.1` is now `implemented` in `phase-status.json`.
Running it first required a change to `invariants.sh`: it aborted on an unreachable scratch DB
before any grep-form invariant executed, so the proof was impossible on a host without the
fixture. `--static-only` runs those checks alone, marks DB-backed ones SKIPPED rather than
passed, and cannot exit 0. `U-P.2` was promoted to `implemented` in the same pass on existing
evidence — all three deliverables exist and the origin-citation checklist item holds.

Two limits carried forward, which is why neither unit is `verified`: the negative proof's exit
code proves nothing on its own (the tree is already statically RED on INV-9, §B-2), so the
evidence is at check granularity only and wants one rerun where INV-9 is green; and U-P.2's
second checklist item — the ops doc followed cold by a human — cannot be discharged by a code
session. The original entry follows.

### D-6 (original) · U-P.1 is complete but reads `not_started` — was OPEN

**What.** `phase-status.json` records `U-P.1: not_started`. Its two deliverables exist and run:
`scripts/ci/invariants.sh` (332 lines) and `scripts/ci/nightly.sh` (152 lines), plus
`scripts/ci/inv_extract.php` (353 lines), which is what satisfies the pack's only checklist item
("every INV check runs verbatim … no paraphrase drift") without a hand-copied command anywhere.
The archived run at `reports/archive/invariants-20260824.log` is the proof it executes.

**Why open.** The pack's Tests section also requires the negative proof: "intentionally violate
INV-5 in a scratch branch: CI RED". That has not been recorded.

**Unblocks.** Run the negative proof once and record it, then set `U-P.1` to `implemented` (and
`verified` once the negative proof is independently reproduced). Note the pack expected a
`.github/workflows/` file; the CI-agnostic `sh` form is a documented, deliberate substitution
(`invariants.sh:4-9`), not an omission.

---

## §E — Deferred design work (named by U-P.2's pack)

These are not defects. They are scope deliberately excluded from the migration, recorded so the
exclusion stays a decision rather than becoming an assumption.

### E-1 · Inventory-table unification + hard FK (F-6) — DEFERRED

**What.** `config_components` carries `(inventory_table, inventory_id)` with a unique
`uq_inventory_once` over the pair, and no foreign key — because a hard FK needs one inventory
table and there are eleven. `scripts/verify/orphan_report.php` is the soft guard.

**Why deferred.** `migration/PLAN_VERIFICATION_REVIEW.md:32` (F-6, ACCEPTED): unification "would
double the plan". The explicit risk note is the important half: **`orphan_report` is a detection
control, not a prevention control, for this one edge.** The target design's claim that "orphans
are structurally impossible" (§2.2) is therefore not true of the as-built schema.

**Unblocks.** A unification project of its own: one `inventory_components` table with a
`component_type` discriminator, or a shared `inventory_lifecycle` table keyed by
`(component_type, id)` owning Status/ServerUUID/faildate. Owner scope decision.

### E-2 · Demote the legacy int status columns to generated columns — DEFERRED

**What.** `server_configurations.configuration_status` and `{type}inventory.Status` are
**retained** by U-D.4 ("external consumers unknown") and are to be demoted to generated columns
mirroring `status_v2` in a **follow-up seeder** that U-D.4 explicitly defers to this backlog,
not to itself.

**Why deferred.** Two writers for one fact. `StatusMap` (`core/models/state/StatusMap.php`) is
the single mapping authority and the mapping is lossy by design (`draft→0`, `building→2`,
`validating→2`, `validated→1`, and every post-finalize state → `3`), so a generated column is
the only way to make the legacy int unforgeable.

**Unblocks.** An inventory of external consumers of the int columns (the reason they were
retained), then one seeder per table plus its rollback. Blocked behind U-D.4 (C-4).

### E-3 · RV-1 — DIMM consumer links — DEFERRED

**What.** `ConfigComponentWriter::afterLegacyAdd()` links `consumer_id` on a matching provider
row only when the `slot_ref` is known. DIMM `slot_ref` is unknown on the legacy side, so DIMM
consumption is left NULL and recorded as review item **RV-1** in `ledger_report`
(`migration/06-resource-ledger/execution-packs/U-L.2.md:14`, and the pack's own checklist item
"RV-1 (dimm consumer links deferred to backfill) noted in code comment").

**Why deferred.** It was scoped to backfill (U-B.3) and stayed open through it
(`migration/handoffs/U-B.3-20260707.md:80`). Still marked as deliberate in code at
`core/models/config/ConfigComponentWriter.php:74`. The structural reason it cannot be linked
today: `ResourceCatalog::motherboardDimmSlotRows()` emits **one aggregate row** —
`['resource' => 'dimm_slot', 'slot_ref' => null, 'capacity' => $slots]`
(`core/models/config/ResourceCatalog.php:527`) — so there are no discrete DIMM `slot_ref`s for a
consumer to be linked to in the first place.

**Unblocks.** A DIMM slot-assignment decision — either the add path starts choosing a DIMM slot
(the way `SlotPlanner` chooses a PCIe slot) or `MemorySlotCountRule` stays count-based and DIMM
provider rows stay unconsumed by design. Bundle with E-4.

### E-4 · RV-2 / RV-3 / RV-4 and the `ResourceCatalog` field-name gaps — DEFERRED

**What.** From `migration/handoffs/PL-GATE-20260707.md:30-36`: RV-2 (`slot_ref` scheme
reconciliation between the ledger namespace and `UnifiedSlotTracker`'s), RV-3 (provider-removal
ledger drift), RV-4 (M.2 lane exclusion), plus the catalog field-name gaps (cpu/nic `provides`;
nic/hbacard/pciecard `consumes`; motherboard sockets/DIMMs; chassis bays).

**RV-4 is CLOSED.** PL-GATE recorded it as real — `ResourceCatalog::consumesStorage()` passing
every storage lane value into `pcie_lane` consumption with no M.2 exclusion, while
`PcieLaneBudgetValidator` excludes M.2 (TP-1C, `PcieLaneBudgetValidator.php:211` and `:325`).
The catalog now excludes it too: `core/models/config/ResourceCatalog.php:295-298` returns `[]`
for an `m.2`/`m2` form factor, and the docblock at `:275` names it "RV-4 fix". Recorded here
because PL-GATE's outstanding-items list still counts it.

**RV-1 and RV-2 are open, and still documented as deliberate in code** —
`core/models/config/ConfigComponentWriter.php:74` (DIMM slot consumer-linking) and `:76`
(discrete PCIe/riser slot consumer-linking), with `ResourceCatalog.php:185` giving RV-2's reason
(`slot_ref` naming). RV-3 (provider-removal ledger drift) has no marker in code — treat its
status as unverified until someone re-derives it.

**RV-2 is visible in live artefacts:** seeder `2026_08_24_002`'s header spells out the two
namespaces — the ledger's `pcie_3_x8` (what `TargetState::freeSlots()` and `SlotPlanner` match
against) versus `nic_config`'s legacy `pcie_x8_slot_1`. Two slot vocabularies for one slot.

**Unblocks.** One unit that fixes the catalog gaps and RV-1…RV-3 **together** — PL-GATE's own
recommendation ("bundle with the catalog-gap fix unit"). It needs real `ims-data` field names,
which is the reason it stalled: do not invent them.

### E-5 · Firmware / BIOS rule family — DEFERRED (needs `ims-data` schema first)

**What.** No firmware or BIOS fields exist anywhere in the system. The target design (§5.2,
§7.3) specifies a VALIDATION_FAILURE-tier rule family plus `min_bios` and
`system.firmware_matrix` additions to the `ims-data` spec schema, and flags this as *"a genuine
gap versus all five reference products"* (OpenManage / OneView / XClarity / Intersight).

**Why deferred.** The data does not exist. A rule cannot be written against an absent field, and
inventing field names is explicitly forbidden by the unit protocol.

**Unblocks.** In order: (1) an `ims-data` schema addition defining `min_bios` per component and
`firmware_matrix` per platform, with real vendor data; (2) `ComponentSpecPaths`/
`ComponentDataService` exposure; (3) one rule unit adding
`system.firmware_matrix` to `ValidationEngine::RULES` with severity VALIDATION_FAILURE.

### E-6 · NUMA-balance rule (WARNING) — DEFERRED

**What.** The target design's rule registry (§5.2, System scope) lists a NUMA-balance WARNING
rule. `ValidationEngine::RULES` (`core/models/validation/ValidationEngine.php:49-80`) has 22
rules and none of them is it. Thermal/airflow (VALIDATION_FAILURE) is likewise named and absent.

**Why deferred.** WARNING-tier rules were never on the critical path — they change no verdict
(`Verdict::blocking()`, `core/models/validation/Verdict.php:35-52`, never blocks on WARNING), so
they were correctly sequenced last.

**Unblocks.** Nothing structural. A rule unit, a fixture, and a row in
`ValidationEngine::RULES`. Note the target design permits flags to promote WARNING rules in and
out (§4) — that is the one legitimate remaining use of a feature flag on validation, and it does
not violate INV-12 because it is not one of the five migration flags.

### E-7 · Sandbox design for virtual configs (F-5) — DEFERRED

**What.** Virtual/sandbox configs are excluded from backfill, from equivalence, and from
dual-write by design. The exclusion guard is `if (!$isVirtual)` at the
`ConfigComponentWriter::afterLegacyAdd()` call site in `ServerBuilder::addComponent` — **not**
the `isSandboxConfig()` guard in `finalizeConfiguration()`, which blocks *finalizing* a bench
build. Sandbox implies virtual, so both are covered, but they are different guards doing
different jobs.

**Why deferred.** `migration/PLAN_VERIFICATION_REVIEW.md` F-5 (FIXED-with-residual): backfilling
virtual configs would violate INV-1 (fake inventory ids) and poison equivalence. "Virtual flows
stay legacy-only until a sandbox design lands (BACKLOG via U-P.2). ACCEPTED, owner: backlog."

**Current interaction to watch.** `handleImportVirtual()` now routes adds through
`AddComponentCommand` (the 2026-08-22 bypass fix), so virtual configs use the new **mutation**
path while remaining outside the equivalence **denominator**. That is a coherent position but
not an obviously stable one, and it is not covered by any gate.

**Unblocks.** The target design's own proposal (§8, E-5): virtual = a config whose components
hold simulated inventory rows in a **sandbox schema**, never sharing the mutation path's bypass
branches, with all `isVirtualConfig` branches deleted. That is a design project, and it is the
prerequisite for U-D.3 being able to claim complete coverage.
