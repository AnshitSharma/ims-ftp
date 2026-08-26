# P9 EXECUTION PLAN — 2026-08-26

**Status: PREPARATION ONLY. Nothing in this document has been executed.** No file under
`core/`, `api/`, `database/` or `scripts/` was created, modified or deleted for this plan.
No git operation was run. This `.md` is the only artifact, and `.md` is never uploaded by
the SFTP watcher.

Every line number below was **re-measured against the working tree on 2026-08-26**. Do not
trust any number in `U-D.*-PLAN-20260713.md` or the execution packs, and do not trust the
corrections recorded in `phase-status.json`'s `twenty_ninth_session_2026_08_24` either —
`ServerBuilder.php` gained roughly **+415 lines** between 2026-08-24 and today across four
commits (`bed5ac3`, `68222b3`, `802f330`, `34715f3`, `3c87a20`; the `serverplatform` work),
so every 08-24 citation for that file is stale by that much. `server_api.php` additionally
has **uncommitted working-tree changes** right now, so its line numbers are working-tree
numbers and will move again.

Measured today: `ServerBuilder.php` = 10,023 lines; `server_api.php` = 4,088 lines;
157 PHP files under the manifest's scan roots locally.

---

## 0. Headline

**P9 is not a deletion phase. It is a rewrite with a large deletion tail.**

Of the 26 manifest symbols, **7 are GREEN and genuinely safe to delete today**, and every
one of those 7 is in a single closed subgraph plus one symbol the 2026-08-24 finding says
to keep. The other 19 are blocked by live production call sites, and clearing those call
sites requires **four builds that do not exist yet** — one of which (`ValidateConfigService.php`)
the pack lists under "Files Created (1)" as though it were already written.

Three gates are also unmet, independently of any code work:

| Gate | Required | Actual (2026-08-26) |
|---|---|---|
| P9 gate | open | `closed` (`phase-status.json:191-207`) |
| P8 gate open ≥14 days | open ≥14d | `closed`; `U-X.2` is `in_progress` |
| `U-C.6` (enforce soak) | `verified` | `in_progress` (`phase-status.json:155`) |

`U-D.1` reads `in_progress` in `phase-status.json:202` while the P9 gate reads `closed`.
That is the same legend violation already logged for P1/U-1.5 in the 08-24 owner-actions
list, now present a second time. Flagging, not changing.

---

## 1. Per-symbol census (all 26)

Verdict key: **GREEN** = zero blocking sites · **RED** = a live blocking site outside any
deletion target · **RED_INTERNAL** = blocked only by same-file callers, one or more of
which is live.

"Enclosed?" answers the question that decides real cost: *is this call site inside another
symbol that is itself being deleted?* An enclosed site costs no separate edit — it vanishes
with its parent. A non-enclosed site is real, hand-written commit work.

### U-D.1 set

| # | Symbol | Location (measured) | Lines | Blocking call sites | Enclosed? | Verdict |
|---|---|---|---|---|---|---|
| 1 | `validateComponentCompatibility` | `ServerBuilder.php:6346-6457` | 112 | `ServerBuilder.php:743` (in `addComponent`) | **No — LIVE** | RED |
| 2 | `checkComponentPairCompatibility` | `ComponentCompatibility.php:258` | — | `ServerBuilder.php:2424` (`getCompatibleComponents`) · `ServerBuilder.php:6435` (`validateComponentCompatibility`) · `TicketValidator.php:361` · `ComponentCompatibility.php:1087` (own file) · `compatibility_api.php:127,183,205,296,468,620` (allow-listed) | 6435 yes; 2424 and 361 **No — LIVE** | RED — **RETAIN** |
| 3 | `checkPowerCompatibility` | `ServerBuilder.php:7453-7456` | 4 | none tree-wide | — | **GREEN** |
| 4 | `checkPowerCompatibilityDetailed` | `ServerBuilder.php:7461-7529` | 69 | `ServerBuilder.php:7454` | Yes (#3) | **GREEN** (cascade, parent GREEN) |
| 5 | `formatPowerBreakdown` | `ServerBuilder.php:7598-7604` | 7 | `ServerBuilder.php:7493, 7511, 7515` | Yes (#4) | **GREEN** (cascade, chain GREEN) |
| 6 | `getChassisPsuWattage` | `ServerBuilder.php:7618-7638` | 21 | `ServerBuilder.php:7486` | Yes (#4) | **GREEN** (cascade, chain GREEN) |
| 7 | `checkFormFactorCompatibility` | `ServerBuilder.php:7534-7537` | 4 | `ComponentCompatibility.php:593` — **false positive**, allow-listed | n/a | **GREEN locally / RED on the deployed scan** |
| 8 | `checkFormFactorCompatibilityDetailed` | `ServerBuilder.php:7542-7593` | 52 | `ServerBuilder.php:7535` | Yes (#7) | **GREEN** (cascade, parent GREEN) |

Symbols 3-8 form one **contiguous closed subgraph: `ServerBuilder.php:7450-7638`, 189 lines
including docblocks** (157 lines of function bodies). This is the block the 08-24 record
called "the 190-line closed subgraph at `ServerBuilder.php:7016-7205`" — same block, shifted
**+434 lines**. Nothing outside it calls into it, and it calls nothing outside itself.

Symbol 7's blocking hit is verified again today as the documented name collision: the
ServerBuilder copy is `private function checkFormFactorCompatibility($components)`
returning a score (`:7534`); `ComponentCompatibility.php:1738` is a different, LIVE
`private function checkFormFactorCompatibility($storageSpecs, $motherboardSpecs, $existingStorage = [])`
returning a verdict array, called from `:593` by dynamic dispatch. **Do not delete
ComponentCompatibility's copy.**

### U-D.2a — full-config validators

| # | Symbol | Location (measured) | Lines | Blocking call sites | Enclosed? | Verdict |
|---|---|---|---|---|---|---|
| 9 | `validateConfiguration` | `ServerBuilder.php:4277-4372` | 96 | `ServerBuilder.php:4384` (`validateConfigurationEnhanced`, #10) · `ServerBuilder.php:4742` (`finalizeConfiguration`) · `performance_report.php:174` (allow-listed harness) | 4384 yes; **4742 No — LIVE** | RED_INTERNAL |
| 10 | `validateConfigurationEnhanced` | `ServerBuilder.php:4377-4588` | 212 | `performance_report.php:175` only — allow-listed, and its **sole remaining caller tree-wide** | n/a | **GREEN** (after harness edit) |
| 11 | `calculateJSONExistenceScore` | `ServerBuilder.php:4593-4606` | 14 | `ServerBuilder.php:4546` | Yes (#10) | **GREEN** (cascade, parent GREEN) |
| 12 | `calculateCompatibilityMatrixScore` | `ServerBuilder.php:4611-4634` | 24 | `ServerBuilder.php:4547` | Yes (#10) | **GREEN** (cascade, parent GREEN) |
| 13 | `validateConfigurationComprehensive` | `ServerBuilder.php:8169-8291` | 123 | `server_api.php:2312` (`handleFinalizeConfiguration`) · `server_api.php:2886` (`handleValidateConfiguration`) · `ServerBuilder.php:4725` (`finalizeConfiguration`) | **No — all three LIVE** | RED |
| 14 | `calculateFinalCompatibilityScore` | `ServerBuilder.php:9479-9498` | 20 | `ServerBuilder.php:8270` | Yes (#13) | GREEN flag set, **parent RED — do not delete alone** |

### U-D.2b — add-path per-type validators

| # | Symbol | Location (measured) | Lines | Blocking call sites | Enclosed? | Verdict |
|---|---|---|---|---|---|---|
| 15 | `validateCPUAddition` | `ServerBuilder.php:5273-5363` | 91 | `ServerBuilder.php:856` (`addComponent`) | **No — LIVE** | RED |
| 16 | `validateRAMAddition` | `ServerBuilder.php:5369-5539` | 171 | `ServerBuilder.php:879` (`addComponent`) | **No — LIVE** | RED |
| 17 | `validateComponentQuantity` | `ServerBuilder.php:9685-9868` | 184 | `ServerBuilder.php:964` (`addComponent`) | **No — LIVE** | RED |
| 18 | `assignComponentSlot` | `ServerBuilder.php:6135-6287` | 153 | `ServerBuilder.php:990` (`addComponent`) | **No — LIVE** | RED |
| 19 | `extractPCIeSlotSize` | `ServerBuilder.php:6293-6313` | 21 | `ServerBuilder.php:6195` (inside `assignComponentSlot`, #18) | Yes (#18) | GREEN flag set, **parent RED — see §7** |

All five `addComponent` call sites (`743, 856, 879, 964, 990`) were read at source today.
None is inside a flag branch or a conditional the flags can turn off — `743` and `964` are
unconditional; `856`, `879`, `990` are gated only on `$componentType`. `addComponent()` is
still the real mutation path at `COMMAND_LAYER_ENABLED=shadow`, and `server_api.php:2160-2168`
records in its own comment that the 2026-08-21 import bypass kept `addComponent()` reachable
"and with it the whole legacy validation chain P9 is meant to delete".

Symbol 19's allow-listed hits re-verified today and still name collisions:
`ComponentCompatibility.php:2252, 2469, 3716, 3731` all call
`$this->dataExtractor->extractPCIeSlotSize(...)` — that is `ComponentDataExtractor.php:512`,
a third public copy. `UnifiedSlotTracker.php:1430` calls its own private at `:1501`.
Neither reaches ServerBuilder's copy.

### U-D.2c — authority classes

| # | Symbol | File | Lines | Blocking call sites | Enclosed? | Verdict |
|---|---|---|---|---|---|---|
| 20 | `replaceOnboardNIC` | `OnboardNICHandler.php:474-592` | 119 | none tree-wide (only comments at `:122, :407, :427, :586`) | — | **GREEN — but RETAIN, see §6** |
| 21 | `MemoryAuthority` | `MemoryAuthority.php` (whole file) | 127 | `ValidationPipeline.php:175, 176` | Yes (#24) | RED → free once #24 goes |
| 22 | `SlotAuthority` | `SlotAuthority.php` (whole file) | 124 | `ValidationPipeline.php:113, 120` | Yes (#24) | RED → free once #24 goes |
| 23 | `StorageConnectionAuthority` | `StorageConnectionAuthority.php` (whole file) | 140 | `ValidationPipeline.php:114, 130` | Yes (#24) | RED → free once #24 goes |
| 24 | `ValidationPipeline` | `ValidationPipeline.php` (whole file) | 180 | `ServerBuilder.php:4474, 4485` (in `validateConfigurationEnhanced`, #10) · **`ServerBuilder.php:5903, 5904` (in `legacyValidateComponentAddition`)** | 4474/4485 yes; **5903/5904 No — LIVE at `ENGINE_MODE` off/shadow** | RED |
| 25 | `PcieLaneBudgetValidator` | `PcieLaneBudgetValidator.php` (whole file) | 359 | `StorageConnectionValidator.php:1020, 1021` · `ServerBuilder.php:5700, 5701` (in `legacyValidateComponentAddition`) · `ledger_report.php:52, 63, 172, 288, 308` (allow-listed) | **No — both production sites LIVE** | RED — **PORT, NOT DELETE (§3)** |

### U-D.2d — read-time warnings

| # | Symbol | Location (measured) | Lines | Blocking call sites | Enclosed? | Verdict |
|---|---|---|---|---|---|---|
| 26 | `getConfigurationWarnings` | `ServerBuilder.php:3017-3218` | 202 | `server_api.php:1774` in `handleGetConfiguration` (`:1726`), surfaced to the client at `server_api.php:1844` as `'warnings' => $configWarnings` | **No — LIVE API response** | RED |

### Census totals

- **GREEN and safe today (parent chain also GREEN): 6** — symbols 3-8, the closed subgraph.
- **GREEN but must not be deleted: 1** — symbol 20 (`replaceOnboardNIC`, §6).
- **GREEN after a harness-only edit: 3** — symbols 10, 11, 12 (`performance_report.php:175`).
- **GREEN flag set but parent RED (trap): 2** — symbols 14, 19 (§7).
- **RETAINED by contract: 1** — symbol 2.
- **RED on a live production call site: 13** — symbols 1, 9, 13, 15, 16, 17, 18, 21, 22, 23, 24, 25, 26.

---

## 2. Measured scope

**Deletable function/class bodies, measured:**

| Group | Lines |
|---|---|
| `ServerBuilder.php` — 19 target methods (bodies only) | 1,580 |
| `ServerBuilder.php` — docblocks on those methods (approx.) | ~80 |
| Five class files deleted whole (`MemoryAuthority` 127, `SlotAuthority` 124, `StorageConnectionAuthority` 140, `ValidationPipeline` 180, `PcieLaneBudgetValidator` 359) | 930 |
| `OnboardNICHandler::replaceOnboardNIC` — **RETAINED, not counted** | (119) |
| **Deleted subtotal** | **~2,590** |

**Non-deleting edit work** (each of these is hand-written, none of it vanishes with a parent):

| Site | Work |
|---|---|
| `ServerBuilder.php:743, 856, 879, 964, 990` | 5 call-site removals inside `addComponent`, each with its own error-return block (~10-15 lines each) |
| `ServerBuilder.php:4725, 4742` | 2 call-site removals inside `finalizeConfiguration` (U-C.5 territory) |
| `ServerBuilder.php:5692-5720` | PCIe lane-budget flag block inside `legacyValidateComponentAddition` (~29 lines) |
| `ServerBuilder.php:5903-5909` | `ValidationPipeline::run()` override block inside `legacyValidateComponentAddition` (~7 lines) |
| `server_api.php:1774 + 1844` | warnings call + response key (needs `VerdictShim`, §3) |
| `server_api.php:2312, 2886` | two `validateConfigurationComprehensive` call sites (need `ValidateConfigService`, §3) |
| `StorageConnectionValidator.php:1020-1021` | repoint to `PcieLaneBudgetRule` (§3) |
| `performance_report.php:174-175` | repoint at the engine (§3) |
| `ledger_report.php:52, 63, 172, 288, 308` | 1 require + 1 type hint + 3 instantiations. **The manifest says "type hint plus two instantiations" — the real count is three instantiations plus the require.** |
| `FLAGS.md:4` | repoint the canonical flag-read pattern away from `PcieLaneBudgetValidator::currentMode()` |
| `ResourceCatalog.php:26, 58` · `UnifiedSlotTracker.php:453` · `StorageInterfacePathRule.php:112` | provenance comments asserting a *current* call site; repoint |

**Files touched, by disposition:**

- **Deleted (5):** `MemoryAuthority.php`, `SlotAuthority.php`, `StorageConnectionAuthority.php`, `ValidationPipeline.php`, `PcieLaneBudgetValidator.php`.
- **Created (1, minimum):** `core/models/validation/ValidateConfigService.php`. A second file is likely if the `PcieLaneBudgetRule` port needs an adapter for `evaluateAssembledStorageLaneBudget()`.
- **Modified, production code (7):** `ServerBuilder.php`, `server_api.php`, `StorageConnectionValidator.php`, `ResourceCatalog.php`, `UnifiedSlotTracker.php`, `StorageInterfacePathRule.php`, `ComponentDataService.php` (comment).
- **Modified, harness/docs (5):** `performance_report.php`, `ledger_report.php`, `deadcode_manifest.json`, `FLAGS.md`, `phase-status.json`.

**Total: ~2,590 deleted lines, ~300 lines of hand-written edit churn, 5 files deleted,
1-2 created, 12 modified, across 13 commits (§4).**

> The 08-24 record cites "roughly 4900 lines across ~9 modified and 5 deleted files" for the
> 23 deployed symbols. My measured body total for the fuller 26-symbol set is ~2,590. The
> gap is almost certainly that the 08-24 figure counted the whole enclosing regions rather
> than the brace-matched method bodies. Both numbers are the same order of magnitude; use
> **~2,590 deleted / ~300 edited** as the reviewable figure, since every line of it is
> traceable to a range in §1.

---

## 3. The real blockers are builds, not deletes

These four items are the actual critical path. **None of them is a deletion. Do not schedule
any of them as one.**

### B1 — `ValidateConfigService.php` DOES NOT EXIST

`U-D.2.md:11-12` lists it under **"Files Created (1)"** as though it were done. Verified at
source today: `core/models/validation/` contains `RuleInterface.php`, `RuleResult.php`,
`Severity.php`, `ShadowRunner.php`, `SlotPlanner.php`, `TargetState.php`,
`TargetStateBuilder.php`, `Trigger.php`, `ValidationEngine.php`, `Verdict.php`, `rules/`.
**No `ValidateConfigService.php`.**

Meanwhile `server-validate-config` is a live public action:
`permission_map.php:46` (`'validate-config' => 'server.view'`) and
`server_api.php:115-116` (`case 'validate-config': case 'server-validate-config':`),
dispatching to `handleValidateConfiguration()` at `server_api.php:2864`, which calls
`validateConfigurationComprehensive()` at `server_api.php:2886`.

And `validateConfigurationComprehensive` is **also** reached from
`ServerBuilder::finalizeConfiguration()` at `ServerBuilder.php:4725` — a live path, not a
validate-only one. Deleting it therefore breaks finalize as well as validate.

**This is a build, and it gates the largest single deletion in the phase.**

### B2 — `getConfigurationWarnings` is a live API response and needs `VerdictShim`

`server_api.php:1774` calls it inside `handleGetConfiguration()`; `server_api.php:1844`
puts the result on the wire as `'warnings' => $configWarnings`. Deleting the producer
without a replacement changes a public response shape.

`VerdictShim` already exists at `api/handlers/server/VerdictShim.php` and is used at four
sites (`server_api.php:1034, 1382, 1528, 1596`) — but **none of them is the warnings path**.
Extending it to map an engine `Verdict` onto the legacy warnings array is new work.

### B3 — `PcieLaneBudgetValidator` is a port, not a delete

Three live consumers, verified today:

1. `StorageConnectionValidator.php:1020-1021` — `evaluateAssembledStorageLaneBudget()`. Production code, not a target, not flag-gated.
2. `ServerBuilder.php:5700-5701` — inside `legacyValidateComponentAddition()`, live at `ENGINE_MODE` off/shadow.
3. `ledger_report.php:52, 63, 172, 288, 308` — allow-listed harness, five sites.

Additionally **`FLAGS.md:4` cites `PcieLaneBudgetValidator::currentMode()` (file lines 50-65)
as the canonical flag-read pattern for the whole migration**, and
`ValidationEngine.php:38` points at `FLAGS.md`'s citation in turn. Deleting the class
without repointing that reference leaves the migration's flag documentation pointing at a
file that no longer exists.

`PcieLaneBudgetRule.php` exists and is the intended successor, but it deliberately reads no
env (`PcieLaneBudgetRule.php:28-29`), so it is not a drop-in for consumer 2's three-mode
`enforce`/`warn`/`off` behaviour (`ServerBuilder.php:5692-5720`). **The port has to decide
what happens to `warn` mode.**

### B4 — `checkComponentPairCompatibility` must be RETAINED

Already `retain: true` in the manifest. Restating because both `U-D.1.md` and
`U-D.1-PLAN-20260713.md` frame it as conditional. It is not conditional. Live sites today:

- `ServerBuilder.php:2424` — inside `getCompatibleComponents()` (`ServerBuilder.php:2028`), reached by the public `get-compatible` / `server-get-compatible` action (`permission_map.php:45`, `server_api.php:120-121`, dispatch at `server_api.php:2964`).
- `TicketValidator.php:361` — live. (The 07-13 plan cites `:329`; drifted +32.)
- `compatibility_api.php` — six sites, the documented external contract.

Only `ServerBuilder.php:6435`, inside `validateComponentCompatibility` itself, goes away —
and it goes away for free, enclosed.

### B5 — `performance_report.php:174-175` must be repointed at the engine

```php
$r1 = $builder->validateConfiguration($cfg);
$r2 = $builder->validateConfigurationEnhanced($cfg);
```

Line 175 is `validateConfigurationEnhanced`'s **only** remaining caller tree-wide — it is
the single thing standing between a 250-line deletion (symbols 10+11+12) and GREEN. Both
lines must be repointed at `ValidationEngine.evaluate(VALIDATE)` **in the same commit as
the deletion**, or the harness fatals. Its own header docblock (`performance_report.php:6-7`)
also names both symbols and needs the same edit.

---

## 4. Commit sequence

Every commit below is **single-purpose with its own rollback**. This is not a style
preference. `2c8ab2f "Update"` is already on `origin/main` carrying migration work mixed
with unrelated temporary-access and rack-placement work; it cannot be split without
force-pushing over merged PR #38 and breaking every clone. `rollback-playbook.md`'s
**R-MIXED** section documents that one exception and supplies a path-scoped revert map for
it. R-UNIT's "one commit per unit" assumption holds for everything after 2026-08-23, and
**must keep holding** — a second mixed commit would mean a second permanent exception.

Prefix every commit `[UNIT-ID]` per R-UNIT step 1.

### Phase A — prerequisites (no deletions)

| # | Commit | Rollback |
|---|---|---|
| **C0** | `[U-D.1] Mark replaceOnboardNIC retain:true in deadcode_manifest.json`, citing `FINDING-20260824-...md`. Manifest-only. Doubles as the test of whether `.json` uploads at all (§5). | `git revert`; manifest is read-only input to the gate, no runtime effect |
| **C1** | `[U-D.0] Resolve deploy skew` — get the repaired 26-symbol manifest onto production, run `deploy_skew_report.php`, remove stale server-side PHP. **Not a repo commit for the file removals** — those are server-side operations; the commit carries only whatever tooling change is needed. | none needed for a scan-corpus fix; re-upload the old manifest if the new one misbehaves |
| **C2** | `[U-D.2a] Create ValidateConfigService, wire handleValidateConfiguration` (B1). Creates one file, edits `server_api.php:2864-2890`. **Deletes nothing** — `validateConfigurationComprehensive` stays until C9. | `git revert`; the old call path is still present, so revert is a pure no-op restore |
| **C3** | `[U-D.2d] Extend VerdictShim to the warnings surface` (B2). Edits `VerdictShim.php` + `server_api.php:1774, 1844`. Response shape must be byte-identical. | `git revert` |
| **C4** | `[U-D.2c] Port PcieLaneBudgetValidator consumers to PcieLaneBudgetRule` (B3). Edits `StorageConnectionValidator.php:1020-1021`, `ServerBuilder.php:5692-5720`, `ledger_report.php` (5 sites), repoints `FLAGS.md:4`. **Class file stays** until C12. | `git revert`; behavioural — needs its own parity run before and after |
| **C5** | `[U-D.2a] Repoint performance_report at ValidationEngine` (B5). Harness only, never deployed. | `git revert` |

### Phase B — deletions, in dependency order

| # | Commit | Depends on | Rollback |
|---|---|---|---|
| **C6** | `[U-D.1] Delete the closed power/form-factor subgraph` — `ServerBuilder.php:7450-7638`, symbols 3-8, 189 lines. **Pure deletion, zero call sites to edit.** Repoint `ResourceCatalog.php:26, 58` and `SystemPsuCapacityRule.php:9, 32` provenance comments in the same commit. | C1 | `git revert` — clean, nothing calls in |
| **C7** | `[U-D.1] Remove the Phase 1.5 pairwise loop` — `ServerBuilder.php:743` call site + method `6346-6457`. | C1, U-C.6 `verified` | `git revert` |
| **C8** | `[U-D.2a] Delete validateConfigurationEnhanced + 2 cascades` — `4377-4588`, `4593-4606`, `4611-4634` (250 lines). Also removes the enclosed `ValidationPipeline::runFinalize()` block at `4473-4490` for free. | C5 | `git revert` |
| **C9** | `[U-D.2a] Delete validateConfiguration + Comprehensive + calculateFinalCompatibilityScore` — `4277-4372`, `8169-8291`, `9479-9498`, plus five live call sites (`ServerBuilder.php:4725, 4742`; `server_api.php:2312, 2886`). | C2, C8, U-C.5 finalize work | `git revert`. **Highest-risk commit in the phase** — touches finalize AND validate |
| **C10** | `[U-D.2d] Delete getConfigurationWarnings` — `3017-3218` (202 lines) + `server_api.php:1774`. | C3 | `git revert` |
| **C11** | `[U-D.2b] Delete the add-path per-type validators` — four `addComponent` call sites (`856, 879, 964, 990`) + `validateCPUAddition` `5273-5363`, `validateRAMAddition` `5369-5539`, `validateComponentQuantity` `9685-9868`, `assignComponentSlot` `6135-6287`, `extractPCIeSlotSize` `6293-6313`. Repoint `UnifiedSlotTracker.php:453`. | C1, U-C.6 `verified` | `git revert`. Consider splitting per symbol if the parity run diverges |
| **C12** | `[U-D.2c] Delete ValidationPipeline + the three authorities` — `ServerBuilder.php:5903-5909` call block + 4 class files (571 lines). | C4, C8, **and the §5 flag-rollback decision** | `git revert` |
| **C13** | `[U-D.2c] Delete PcieLaneBudgetValidator` — 359-line file, now callerless. | C4, C12 | `git revert` |
| **C14** | `[U-D.4] Flags, TEMP-GUARD blocks, legacy env-flag residue` (§5). | C12, C13, U-D.3 | `git revert` |

`U-D.3` (the JSON column drop, point of no return) is deliberately **not** sequenced here.
Its backup precondition is now met per the 08-24 record, but the README's strict ordering
puts it after U-D.1 and U-D.2, and its own preconditions (P8 signoff ≥30 days, 30 days of
GREEN `equivalence --all`) are not close. It gets its own plan when the phase actually opens.

---

## 5. Couplings and gates

### U-D.2 and U-D.4 are COUPLED, not independent

`ServerBuilder::validateComponentAddition()` (`ServerBuilder.php:5567`) is the `ENGINE_MODE`
dispatch. Read at source today, all three branches are live:

- `:5570-5573` — `if ($mode === 'off') { return $this->legacyValidateComponentAddition(...); }`
- `:5645-5646` — `if ($mode === 'shadow') { $legacyResult = $this->legacyValidateComponentAddition(...); ... return $legacyResult; }`
- `:5658-5660` — enforce, and any unrecognised mode, returns the engine verdict.

`legacyValidateComponentAddition()` is defined at `ServerBuilder.php:5688` and is the sole
enclosing scope for two U-D.2c blockers: the `PcieLaneBudgetValidator` block (`5692-5720`)
and the `ValidationPipeline::run()` override (`5903-5909`).

**Therefore: deleting `legacyValidateComponentAddition` forecloses flag rollback.** Once it
is gone, `ENGINE_MODE=off` and `ENGINE_MODE=shadow` no longer have a legacy path to return,
and R-FLAG — the playbook's *first response to any incident*, the layer described as
"instant and safe" — stops working for the engine layer. U-D.4's own premise ("all flags at
terminal values ⇒ deletion is identity") must be **committed to and signed off before C12**,
not discovered at C12. This is an owner decision, not an engineering one.

Note the ordering trap: U-D.4 is scheduled *last* by the README, but the decision it
represents has to be made *before* C12. Sequence the decision, not just the commit.

### Both U-D.1 and U-D.2 are gated on U-C.6 reading `verified`

`phase-status.json:155` reads `"U-C.6": "in_progress"`. Not `verified`. The 07-13 plans
already flagged this for U-D.1's dispatch-block scope and for U-D.2 wholesale; it is still
unmet, and the status has moved only from `blocked` to `in_progress` in the intervening six
weeks. **C7 and C11 must not proceed on the current value.**

### U-D.4 has 12 flag markers, not 11

`STORAGE_BAY_AUTHORITY_ENABLED` was missed by every count before 2026-08-24.

Verified today, and this refines the 08-24 record: `STORAGE_BAY_AUTHORITY_ENABLED` appears
at `StorageInterfacePathRule.php:51` and `:114`, and at `StorageConnectionAuthority.php:16`
— **all three are comments. There is no `getenv('STORAGE_BAY_AUTHORITY_ENABLED')` or
`$_ENV['STORAGE_BAY_AUTHORITY_ENABLED']` anywhere in the tree.** The 08-24 record's phrase
"has a LIVE reader in `StorageInterfacePathRule.php`" is accurate about the *rule* being
live replacement-side logic that reasons about the flag's `enforce` semantics
(`StorageInterfacePathRule.php:112-114` documents exactly that), but it is not a runtime
flag read. **Consequence for U-D.4's acceptance test:** the grep will come back non-empty on
comment hits, and the reviewer must resolve those by hand rather than treating a non-empty
grep as a failure. Budget for that; it is the difference between a clean grep gate and a
manual review.

The other five legacy env flags all have genuine runtime readers, confirmed today:
`MEMORY_AUTHORITY_ENABLED` (`MemoryAuthority.php:42, 44`),
`SLOT_AUTHORITY_ENABLED` (`SlotAuthority.php:41, 43`),
`STORAGE_CONNECTION_AUTHORITY_ENABLED` (`StorageConnectionAuthority.php:43, 45`),
`VALIDATION_PIPELINE_ENABLED` (`ValidationPipeline.php:55, 57`),
`PCIE_LANE_CHECK_ENABLED` (`PcieLaneBudgetValidator.php:57, 59`).
All five live in files C12/C13 delete outright — so U-D.4 really is residue-mopping for
these, as the pack predicts.

---

## 6. Commit 0 — GO/NO-GO

Commit 0 as proposed bundles two things. They have different answers.

### Half A — `replaceOnboardNIC` (119 lines, `OnboardNICHandler.php:474-592`)

**Evidence FOR deleting:**
- `server-debug-deadcode` against the deployed tree reports it GREEN: `blocking_callers: 0`, `internal_callers: 0`.
- It is GREEN under the OLD 23-symbol manifest too, so the §5 deploy gap does not affect it.
- Tree-wide grep confirms independently: the only surviving occurrences today are comments at `OnboardNICHandler.php:122, 407, 427, 586` plus `deadcode_scan.php:18, 23`. Zero invocation sites in any `.php` or `.js` in the monorepo.
- It is gate-legal. Every written precondition for deleting it is satisfied.

**Evidence AGAINST:**
- `FINDING-20260824-replaceOnboardNIC-not-superseded.md` is unambiguous: **DO NOT DELETE.**
- It contains the **sole `Flag = 'replaced'` write in the entire codebase** (`OnboardNICHandler.php:530`). Three surviving branches read that flag and exist only to honour it: `:108` (skip a replaced port on motherboard re-add), `:420` and `:421` (`Status = CASE WHEN Flag='replaced' ...` on detach). Deleting the producer makes all three permanently false-branch, and the gate would never report it — they stay syntactically reachable and semantically unreachable forever.
- The supersession claim is false. `IMS_TARGET_ARCHITECTURE.md:226` says it is reimplemented as a `ReplaceComponent` specialization; `ReplaceComponentCommand.php:105-107` **explicitly excludes** `onboard-` prefixed UUIDs. The capability was never ported and is already unreachable from the API. This is a shipped regression, and this function is its last surviving specification.
- Production exposure today is capability loss, not data corruption — zero `nicinventory` rows carry `Flag = 'replaced'` as of the 2026-08-24 dump. That is why it is a finding rather than an incident, and why there is time to do it properly.

**Recommendation: NO-GO.** The gate is right and insufficient. Deleting a symbol that is the
only writer of persisted state other code reads is exactly the fail-open family this
migration has been closing all along. Instead: **C0** marks it `retain: true` in the manifest
with the finding as the reason, so the gate stops proposing it, and a separate unit decides
between extending `ReplaceComponentCommand` to onboard NICs or formally withdrawing the
capability (in which case the three consumer branches and the `Flag='replaced'` vocabulary
come out **together**, as one reviewed change).

### Half B — the closed subgraph (`ServerBuilder.php:7450-7638`, 189 lines, symbols 3-8)

**Evidence FOR:**
- All six symbols are GREEN locally. The subgraph is genuinely closed: verified today that nothing outside `7450-7638` calls into it, and it calls nothing outside itself.
- Its only external tie is provenance comments (`ResourceCatalog.php:26, 58`; `SystemPsuCapacityRule.php:9, 32`), which are documentation, not calls.
- It is a pure deletion — the single cheapest 189 lines in the whole phase, with zero call-site edits.

**Evidence AGAINST:**
- **The repaired manifest has not reached production.** Local has 26 symbols; the deployed scan reports 23, after repeated checks. `.json` is not in the SFTP ignore list and PHP files *are* syncing, so this specific edit failed to upload for an unexplained reason. Four of these six symbols are GREEN **only because of `internal_callers_also_deleted` flags added on 2026-08-24**, which production cannot see.
- `checkFormFactorCompatibility` is GREEN only because of an `allowed_callers` entry added the same day. On the deployed manifest it is **RED**, with exactly one blocking site at `ComponentCompatibility.php:593`.
- **The sync proof is now stale.** The 08-24 record justified editing `ServerBuilder.php` by showing deployed `internal_sites` (7017, 7049, 7056, 7074, 7078, 7098) matching local exactly. Those lines are now 7454, 7486, 7493, 7511, 7515 — the file moved **+434 lines** across five commits on 2026-08-25/26. That proof has to be re-established, not assumed.

**Recommendation: NO-GO for now, GO once C1 lands.** This is the right first deletion — it is
the only one with no call-site edits at all — but it must wait for the manifest to reach
production and for a fresh `server-debug-deadcode` run showing all six GREEN against the
26-symbol manifest and a re-synced corpus.

**Net: Commit 0 as written is NO-GO on both halves, for two unrelated reasons.** The
substitute C0 in §4 is safe, useful, and doubles as the diagnostic for why the manifest
would not upload.

---

## 7. `CAVEAT_cascade_greens_are_thin` — restated as a hard check

> **Never delete a GREEN cascade symbol without confirming its parent target is also GREEN.**

A symbol carrying `internal_callers_also_deleted: true` is GREEN by *declaration*, not by
measurement. The flag asserts one narrow thing: no code **outside** this file needs the
helper. It says nothing about whether a live path still reaches it **through** its parent.

The canonical instance, re-verified today:

- `extractPCIeSlotSize` (`ServerBuilder.php:6293-6313`) reports **GREEN**.
- Its only same-file caller is `ServerBuilder.php:6195`, inside `assignComponentSlot`.
- `assignComponentSlot` (`6135-6287`) is a manifest target — which is why the flag was set.
- But `assignComponentSlot` is **RED**: live `addComponent()` calls it at `ServerBuilder.php:990`, unconditionally for `pciecard`/`risercard`/`nic`/`hbacard`.
- So a live production request still transitively reaches `extractPCIeSlotSize` today. Deleting it on its GREEN would break a live add.

**The check, to run per symbol before every deletion:**

1. Does the symbol carry `internal_callers_also_deleted: true`? If no, skip — its GREEN was measured.
2. If yes: name every same-file caller.
3. For each caller, confirm it is (a) itself a manifest target **and** (b) currently GREEN.
4. If any caller is RED, **the symbol is not deletable**, whatever the report says. Delete it only in the same commit that removes its parent's live call site.

**Applying it to the 26-symbol set today:**

| Symbol | Parent(s) | Parent verdict | Safe alone? |
|---|---|---|---|
| `calculateJSONExistenceScore` | `validateConfigurationEnhanced` | GREEN (after C5) | Yes, with the parent, in C8 |
| `calculateCompatibilityMatrixScore` | `validateConfigurationEnhanced` | GREEN (after C5) | Yes, with the parent, in C8 |
| `calculateFinalCompatibilityScore` | `validateConfigurationComprehensive` | **RED** (3 live sites) | **NO** — C9 only |
| `extractPCIeSlotSize` | `assignComponentSlot` | **RED** (`addComponent:990`) | **NO** — C11 only |
| `checkPowerCompatibilityDetailed` | `checkPowerCompatibility` | GREEN (callerless) | Yes — C6 |
| `formatPowerBreakdown` | `checkPowerCompatibilityDetailed` | GREEN (chain) | Yes — C6 |
| `getChassisPsuWattage` | `checkPowerCompatibilityDetailed` | GREEN (chain) | Yes — C6 |
| `checkFormFactorCompatibilityDetailed` | `checkFormFactorCompatibility` | GREEN locally / RED deployed | Yes — C6, **after C1** |

Two of the eight cascade greens are traps. Both are in `ServerBuilder.php`. Both would
delete code a live request reaches.

---

## 8. Pre-flight checklist — run IMMEDIATELY before ANY deletion

Not once per phase. **Once per commit, immediately before it.** Each item exists because a
specific thing has already gone wrong.

**1. Re-run `server-debug-deadcode` against the DEPLOYED tree.**
*Why:* the deployed tree carries **161-162 PHP files** against **157 local** (measured today;
it was 144-145 local on 08-24, so both sides have moved). FTP uploads on save and **never
deletes**, so production is accumulating stale PHP the local tree no longer has — any of
which could be an invisible blocking caller. Precedent: a stray
`_ServerBuilder_unpatched_probe.php` once made **11 of 17 symbols falsely RED**.
**Local GREEN is never sufficient evidence.** This host has no shell, so
`server-debug-deadcode` is the only way the scan runs against production at all.

**2. Confirm the manifest the deployed gate is reading has 26 symbols, not 23.**
*Why:* the repaired manifest **has not reached production**. The deployed scan still reports
23 after repeated checks, so **the four newly-GREEN subgraph symbols cannot be
gate-confirmed production-side**, and `checkFormFactorCompatibility`'s `allowed_callers`
false-positive fix is not in effect there either. If the deployed scan reports 23, stop —
the gate is evaluating a manifest that predates the repair.

**3. Run `scripts/verify/deploy_skew_report.php` and read the delta by hand.**
*Why:* it exists precisely to gate "is the corpus the gate scanned the same set of files as
the source of truth". Its own header documents the two silent failure modes: an orphan
citing a manifest symbol produces a permanent unexplained RED no local grep can reproduce;
worse, an orphan that is a stale *copy* of a file a caller was removed from keeps the gate
RED after the real fix landed. The 08-24 delta was checked by hand and found benign — that
finding does not transfer to today.

**4. Re-derive every line number in the commit from the working tree.**
*Why:* `ServerBuilder.php` moved **+415 lines** between 2026-08-24 and 2026-08-26 alone.
Every citation in the 07-13 plans is stale, and so are the 08-24 corrections
(`validateComponentCompatibility` 4631 → 5931 → **6346**; `validateConfiguration` 3166 →
3932 → **4277**; `Enhanced` 3275 → 4032 → **4377**; `Comprehensive` 6414 → 7732 → **8169**;
`getConfigurationWarnings` 1875 → 2679 → **3017**). Numbers in *this* document are dated
2026-08-26 and will be stale too.

**5. Re-establish the local↔deployed sync proof for the file being edited.**
*Why:* the only evidence that editing `ServerBuilder.php` is safe was the 08-24 observation
that deployed `internal_sites` matched local line numbers exactly. That proof is void — the
file has moved +434 lines at the subgraph. Compare a fresh deployed `internal_sites` list
against local before touching the file.

**6. Confirm `phase-status.json` gates still read as expected**, specifically `U-C.6` =
`verified` (currently `in_progress`) and the P9 gate = `open` (currently `closed`).
*Why:* these are the two written preconditions of the whole phase and both are unmet today.

**7. `php -l` every touched file before the watcher uploads it** (XAMPP's `php.exe`; `php`
is not on PATH). *Why:* the watcher uploads on save with no staging. A syntax error is a
live 500, and a mid-edit snapshot is what strands a broken file in production.

**8. Keep each edit individually valid.** *Why:* same reason. A deletion that removes a
method in one save and its call site in the next leaves production calling a method that
does not exist for as long as the gap lasts. **Remove the call site first, save, verify;
then remove the method.**

**9. After the commit: `php -l` tree, `characterize_compatibility.php` ZERO diffs,
`run_all.php --gate P9`.**
*Caveat on the middle one:* `characterize_compatibility.php` is a **CAPTURE** tool, not a
comparison — it overwrites `tests/golden/compatibility_baseline.json` and exits 0
unconditionally. Capture the baseline **before** the deletion, diff by hand after. Wiring
it as a gate would be a worse fail-open than skipping it, since the gate would rewrite the
baseline it is meant to check against. It needs a `--diff` mode first; that is on the backlog.

---

## 9. Ordered prerequisites before any deletion is possible

1. **Get the 26-symbol `deadcode_manifest.json` onto production** and establish why it did not upload. Until this is done, no gate verdict from production is trustworthy for 4 of the 6 cheapest symbols. *(C1)*
2. **Reconcile the deployed/local PHP file gap** (161-162 vs 157) via `deploy_skew_report.php` and remove stale server-side files. *(C1)*
3. **Build `ValidateConfigService.php`** and wire `handleValidateConfiguration` (`server_api.php:2864`). Gates the largest deletion in the phase. *(C2)*
4. **Extend `VerdictShim` to the warnings surface** so `server_api.php:1844`'s response shape survives. *(C3)*
5. **Port the three `PcieLaneBudgetValidator` consumers** to `PcieLaneBudgetRule`, decide what happens to `warn` mode, and repoint `FLAGS.md:4`. *(C4)*
6. **Repoint `performance_report.php:174-175`** — the last thing standing between a 250-line deletion and GREEN. *(C5)*
7. **`U-C.6` must reach `verified`.** Time and soak, not code. Gates C7 and C11.
8. **Complete U-C.5's finalize work** — remove `ServerBuilder.php:4725` and `:4742`. Gates C9.
9. **Owner decision on flag-rollback foreclosure** — U-D.4's terminal-value premise must be signed off before C12, because deleting `legacyValidateComponentAddition` removes R-FLAG's first response for the engine layer.
10. **Owner decision on `replaceOnboardNIC`** — extend `ReplaceComponentCommand` to onboard NICs, or formally withdraw the capability. Either way, not a deletion. *(C0 records the retain in the meantime.)*
11. **Open the P9 gate** — it reads `closed`, and P8 must be open ≥14 days first (`U-X.2` is `in_progress`).

Items 1-6 are engineering. Items 7-11 are decisions and time. **Nothing in the deletion set
can move until at least items 1, 7 and 11 clear**, and the cheapest possible first deletion
(C6, the closed subgraph, 189 lines, zero call-site edits) needs only item 1.

---

## 10. Explicit non-actions

No file under `core/`, `api/`, `database/` or `scripts/` was created, modified or deleted.
`ServerBuilder.php`, `server_api.php`, `phase-status.json`, `run_all.php`,
`deadcode_manifest.json` and everything under `scripts/verify/` were **read only**. No flag
was set. No seeder was written. No `git commit`, `git revert`, `git push` or any other git
write was run. No deadcode report was run. `phase-status.json`'s P9 entries are unchanged.
This document is the only artifact.
