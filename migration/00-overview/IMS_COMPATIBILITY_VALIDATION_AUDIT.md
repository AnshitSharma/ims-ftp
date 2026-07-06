# IMS Component Compatibility Validation — Architecture Audit

**Repository:** ims-ftp (main)
**Scope:** Component addition / removal / replacement / full-server validation flows
**Date:** 2026-07-02
**Note:** Line numbers refer to the current `main` HEAD. Several previously-audited issues (C1 quantity coercion, C4 slot-persistence, C6 JSON existence gate, H3/H9 storage paths, TP-4C onboard NIC state, TP-5A N+1 queries, Phase-1 row locking) are already fixed in code and are **not** re-reported. This audit covers what remains.

---

## 0. Architecture Overview (as found)

Validation is spread across **six** overlapping surfaces:

1. **API layer** — `api/handlers/server/server_api.php` (param checks, ACL, an *advisory* full `validateComponentAddition()` pre-check, finalize pre-check)
2. **Service layer** — `ServerBuilder::addComponent()` (Phases 1–7: type check, pairwise precheck, duplicate check, JSON existence, CPU/RAM validators, consolidated `validateComponentAddition()`, availability, quantity, slot assignment)
3. **Compatibility engine** — `ComponentCompatibility` (pairwise + per-type "decentralized" checks)
4. **Authorities / pipeline** — `SlotAuthority`, `StorageConnectionAuthority`, `MemoryAuthority`, `PcieLaneBudgetValidator`, gated by env flags (`VALIDATION_PIPELINE_ENABLED`, `PCIE_LANE_CHECK_ENABLED`, …), wired via `ValidationPipeline`
5. **Full-config validators** — `validateConfiguration()` (finalize gate), `validateConfigurationEnhanced()`, `validateConfigurationComprehensive()` (validate-config endpoint) — three divergent implementations
6. **Read-time warnings** — `getConfigurationWarnings()` invoked from `handleGetConfiguration()`

There is **no replace operation** and **no deploy-stage validator** distinct from finalize.

---

## 1. Component Addition Flow

Actual execution order (`ServerBuilder::addComponent()`, line 440):

```
API: param/type check → ACL → validateComponentAddition() [ADVISORY, UNLOCKED]
Builder (in TX, config row FOR UPDATE):
  P1   isValidComponentType
  P1.1 serial auto-resolve
  P1.5 validateComponentCompatibility()      ← pairwise engine (run #2)
  P2   isDuplicateComponent (FOR UPDATE)
  P3   JSON-spec existence (ChassisManager / ComponentCompatibility)
  P4   lockAndCheckComponent (inventory row FOR UPDATE)
  P5.1 validateCPUAddition        (cpu only)
  P5.5 validateRAMAddition        (ram only)
  P6   validateComponentAddition()           ← decentralized engine + pipeline (run #3)
  P7   checkComponentAvailability
  --   isSingleInstanceComponent re-check
  P5.1b validateComponentQuantity
  P10.5 assignComponentSlot (pciecard/nic/hbacard)
  write JSON → write inventory status → metrics → commit
Post-commit: onboard-NIC auto-add (motherboard), NIC-config JSON update,
             API-layer SFP auto-assign (direct SQL, outside builder TX)
```

### Issues

**A-1 — Validation exceptions are fail-open (add proceeds unvalidated)**
1. `core/models/server/ServerBuilder.php`
2. `addComponent()` — Phase 5.1 catch (~line 668), Phase 6 catch (~line 709: "Continue without compatibility validation"); mirrored at API layer in `handleAddComponent()` (~line 445: "Validation service unavailable - component added without full compatibility checks")
3. If `validateCPUAddition()` or `validateComponentAddition()` **throws** (bad JSON spec, missing file, DB error), the exception is logged and the add **continues**.
4. A malformed spec file or transient error silently disables the entire compatibility gate. Combined with the API layer's identical fail-open, there is no authoritative backstop. Errors *returned* are blocking, but errors *thrown* are not — the exact failure mode most likely under data corruption.
5. Correct layer: Service layer (`addComponent`) — validation exceptions must roll back.
6. Trigger: add
7. **Severity: Critical**

**A-2 — CPU socket-count limit bypassed by `quantity > 1`**
1. `core/models/server/ServerBuilder.php`
2. `validateCPUAddition()` (3751), `validateComponentQuantity()` (7774), `updateCpuConfiguration()` (2447)
3. `validateCPUAddition()` compares `count($cpuConfig['cpus'])` (entry count) against `max_sockets`; `validateComponentQuantity()`'s `$slotBasedTypes` excludes `'cpu'`; but `updateCpuConfiguration()` persists `'quantity' => $quantity` per entry.
4. `add-component cpu quantity=4` on a 2-socket board with 0 CPUs passes (0 < 2) and persists a single entry with quantity 4 — socket over-allocation. The same gap applies to any per-entry quantity semantics vs. per-entry counting (mixed-model checks, TDP totals, lane budgets that count entries).
5. Correct layer: Component-specific validator — count **sum of quantities**, and add `cpu` to `validateComponentQuantity()`.
6. Trigger: add
7. **Severity: Critical**

**A-3 — No lifecycle-status guard: components can be added/removed on finalized configs**
1. `api/handlers/server/server_api.php`, `core/models/server/ServerBuilder.php`
2. `handleAddComponent()` (325), `handleRemoveComponent()` (698), `addComponent()`, `removeComponent()`
3. Only `handleUpdateConfiguration()` (128) checks `configuration_status == 3`. Add/remove never read the status.
4. A deployed (finalized, status 3) server's configuration can be mutated through the normal add/remove endpoints, invalidating the finalize-time validation verdict with no re-validation and no audit gate. This is the single biggest "wrong trigger event" issue: post-finalize mutation should require either un-finalize or a dedicated replace/maintenance flow.
5. Correct layer: Service layer (inside the locked transaction, so the check can't race with finalize).
6. Trigger: add, remove
7. **Severity: Critical**

**A-4 — The same compatibility validation executes three times per add**
1. `api/handlers/server/server_api.php`, `core/models/server/ServerBuilder.php`
2. API `handleAddComponent()` → `validateComponentAddition()` (advisory, unlocked, ~line 407); builder Phase 1.5 → `validateComponentCompatibility()` (pairwise, 4631); builder Phase 6 → `validateComponentAddition()` (decentralized + `ValidationPipeline::run()`).
3. Every add runs: full decentralized check + pipeline (API), then O(N) pairwise `checkComponentPairCompatibility()` loop (P1.5), then full decentralized check + pipeline again (P6).
4. (a) Cost: 3× spec loads and DB reads per add on large builds. (b) Correctness: the **pairwise** engine and the **decentralized** engine are different code paths that can disagree — the pairwise P1.5 run can veto adds the decentralized authorities would allow, and vice versa, making authority-flag rollouts (`VALIDATION_PIPELINE_ENABLED`) non-deterministic. (c) The advisory API run duplicates the singleton and riser checks verbatim.
5. Correct layer: One authoritative run in the Service layer under lock (Phase 6). Delete Phase 1.5 entirely (its only unique logic — chassis singleton — already exists in Phase 6). Reduce the API pre-check to nothing, or keep it only to surface warnings without the blocking branch.
6. Trigger: add
7. **Severity: High** (correctness divergence) / duplicate

**A-5 — Chassis/motherboard singleton enforced in three places, with a fourth generic re-check**
1. `core/models/server/ServerBuilder.php`
2. `validateComponentCompatibility()` (chassis-only, 4649), `validateComponentAddition()` (motherboard + chassis via `configData` columns, ~4026), `isSingleInstanceComponent()` re-check after Phase 7 (~line 745), plus `validateSingleComponentConstraints()` at validate-config.
3. Same invariant, four implementations, three different data sources (JSON extraction vs. `motherboard_uuid` column vs. `getConfigurationComponent()`).
4. Duplicate checks with divergent data sources drift: e.g., a config whose `chassis_uuid` column is set but whose JSON extraction misses it passes one gate and fails another with inconsistent messages.
5. Correct layer: single check in the component-specific validator, fed by one canonical component extraction.
6. Trigger: add
7. Severity: Medium / duplicate

**A-6 — Availability check runs last (Phase 7), after all expensive validation**
1. `core/models/server/ServerBuilder.php`
2. `addComponent()` — `checkComponentAvailability()` (~line 720)
3. A component that is in-use/failed passes through pairwise, JSON, CPU/RAM, and decentralized validation before availability is consulted.
4. Wrong ordering: cheap structural gates (type → duplicate → exists → **available**) should run before spec-level compatibility. Not a correctness bug (all inside one TX), but it burns the expensive path on requests that were never addable and produces compatibility-flavored errors for availability problems.
5. Correct layer: Service layer, immediately after `lockAndCheckComponent()` (Phase 4).
6. Trigger: add
7. Severity: Low (executed too late)

**A-7 — User-supplied `slot_position` is silently ignored for PCIe/NIC/HBA**
1. `core/models/server/ServerBuilder.php`
2. `assignComponentSlot()` (4433)
3. The `$manualSlotPosition` parameter is accepted but **never referenced in the function body**; the tracker always auto-assigns, and `addComponent()` then overwrites `$options['slot_position']` with the auto value.
4. Manual slot placement — an explicitly supported API parameter — has no effect and is never validated for occupancy/size. Users believe they've pinned a card to a slot; the system puts it elsewhere. Also means there is no path to validate a manual slot against slot electrical width or riser remapping.
5. Correct layer: Component-specific validator inside `assignComponentSlot()`: if manual slot given → validate exists + free + size-compatible, else auto-assign.
6. Trigger: add, update
7. **Severity: High**

**A-8 — Slot assignment fails open on unknown size and on exception**
1. `core/models/server/ServerBuilder.php`
2. `assignComponentSlot()` — unparseable slot size (~4555) and catch block (~4566) both return `success=true, slot_id=null`
3. Cards whose specs don't yield a width, or any tracker exception, are added slot-less.
4. This is exactly the state class the diagnostic seeder `2026_06_24_001_audit-stale-storage-and-slotless-cards-diagnostic.sql` hunts (slot-less cards invisible to the tracker). C4 fixed the "no free slot" case but the "can't parse size" and "exception" cases still create the same untracked-card state, silently defeating slot-count and fragmentation validation for every subsequent add.
5. Correct layer: Component-specific validator — treat unparseable width as a data-shape error (block or require manual slot), and treat exceptions as blocking.
6. Trigger: add
7. **Severity: High**

**A-9 — Add-time PCIe lane check defaults to `warn` while a second, different lane model runs at validate-config**
1. `core/models/compatibility/PcieLaneBudgetValidator.php`; `core/models/server/ServerBuilder.php`
2. `PcieLaneBudgetValidator::currentMode()` (57, default `'warn'`); `trackPCIeLaneAvailability()` (6752)
3. Add-time lane budgeting is env-gated and non-blocking by default; validate-config computes lanes with an entirely separate implementation.
4. Two lane models produce divergent verdicts (a build that adds cleanly can fail validate-config on lanes, and vice versa). The validator's own comment ("one lane model" entry point) acknowledges this. Also: lane *over-allocation* is a per-add resource check — deferring it to validate-config while enforce is off means over-subscribed builds persist.
5. Correct layer: one shared lane model, invoked at **add** (blocking once soaked) and re-used by validate-config.
6. Trigger: add + validate-config
7. Severity: Medium (High once multiple NVMe/GPU builds are common)

**A-10 — M.2 / U.2 capacity enforced only as a read-time warning**
1. `core/models/server/ServerBuilder.php`, `api/handlers/server/server_api.php`
2. `getConfigurationWarnings()` (1875, `m2_slots_exceeded`); chassis check explicitly bypasses M.2/U.2 (`ComponentCompatibility.php` ~3180); called only from `handleGetConfiguration()` (817)
3. M.2/U.2 drives bypass bay validation at add-time ("connects via PCIe/motherboard"); the only capacity check is a warning generated when someone happens to view the config.
4. Over-populating M.2 slots is never blocked at add and never appears in `server-validate-config` output — it lives exclusively in a GET endpoint. A check in the wrong layer *and* on the wrong trigger.
5. Correct layer: add-time (Storage authority: count M.2 slots like DIMMs) + validate-config error.
6. Trigger: add, validate-config (currently: none/read)
7. **Severity: High** / misplaced

**A-11 — Post-commit side effects are non-atomic and partially bypass the builder**
1. `core/models/server/ServerBuilder.php`; `api/handlers/server/server_api.php`
2. `addComponent()` post-commit onboard-NIC auto-add (~845) and NIC-config update; `handleAddComponent()` SFP auto-assignment (~485) writing `sfpinventory` and `server_configurations.sfp_configuration` with raw SQL
3. Onboard NICs are added *after* the motherboard's transaction commits; SFP auto-assignment is done at the **API layer** with direct UPDATEs, outside any builder transaction, outside the config row lock, and without invalidating `configCache`.
4. Failure mid-way leaves motherboards without onboard NICs (warning only) or SFP JSON inconsistent with `sfpinventory`; the API-layer SQL is a repository-layer concern living in a handler and races with concurrent adds on the same config.
5. Correct layer: Service layer, inside the same transaction as the triggering add (or an explicitly compensated saga). SFP auto-assign must move out of the handler into `ServerBuilder`.
6. Trigger: add
7. **Severity: High** (race / inconsistent state)

**A-12 — CPU is hard order-dependent ("add motherboard first"), unlike every other type**
1. `core/models/server/ServerBuilder.php`
2. `validateCPUAddition()` (3751; documented as "ORDERING CONTRACT (L4)")
3. CPU add is rejected when no motherboard exists; RAM/storage/NIC allow any order and defer.
4. Even if intentional, this is a "check executed too early" pattern: socket/count validation *can't* run without a board, but the correct response is the RAM pattern (accept + defer to board-add / validate-config), not a block. As written, a CPU-first build path is impossible while a RAM-first one is fine — inconsistent lifecycle semantics that also break replace-motherboard workflows (see R-1).
5. Correct layer: keep socket check in the CPU validator but make it conditional on board presence; the "required together" rule belongs to validate-config.
6. Trigger: add (should defer to validate-config)
7. Severity: Medium

**A-13 — Cosmetic: RAM quantity error interpolates an array**
1. `core/models/server/ServerBuilder.php`
2. `validateComponentQuantity()` (~7822): `"... (board has $dimms total, $existingRam currently used)"`
3. `$existingRam` is an array → message renders "Array currently used" (plus PHP notice).
4. Should be `count($existingRam)`.
5. Service layer. 6. add. 7. Severity: Low

---

## 2. Component Removal Flow

`ServerBuilder::removeComponent()` (932) performs: lock config row → find entry in JSON → **NIC→SFP check (the only dependency check in the system)** → motherboard? remove onboard NICs (failure tolerated) → write JSON → free inventory → recalc form-factor lock (chassis/storage only) → metrics → commit.

### Issues

**R-1 — No cascading dependency validation on removal (only NIC→SFP exists)**
1. `core/models/server/ServerBuilder.php`
2. `removeComponent()` (932)
3. Missing checks, all currently allowed:
   - **Motherboard removal** while CPUs, RAM, PCIe cards, HBAs, risers remain → every slot/socket assignment orphaned; `motherboard_uuid = NULL` while `cpu_configuration`, `ram_configuration`, `pciecard_configurations` stay populated with slot IDs of a board that no longer exists.
   - **CPU removal** while RAM remains (RAM validated in `cpu_only` mode is never re-checked) and while lane consumers remain (removing 1 of 2 CPUs halves the lane/channel budget with no re-validation).
   - **HBA removal** while storage entries carry `connection` paths routed through that HBA → stale `connection_data` (the same state class as the known H9 stale-storage diagnostics).
   - **Riser removal** while cards occupy riser-*provided* PCIe slots (`UnifiedSlotTracker` merges riser-provided slots into the pool at line ~76; nothing checks occupancy of those slots on riser removal) → cards assigned to slots that vanish.
   - **Chassis removal** while 2.5"/3.5" drives and caddies occupy its bays → only `recalculateFormFactorLock()` runs; bay assignments and caddy pairings are untouched.
4. The system cannot prevent invalid removals; it can only detect some consequences later *if* someone runs validate-config. Removal is effectively unvalidated.
5. Correct layer: Service layer, pre-removal, via a **dependency graph resolver** (see §6): compute dependents of the target; block (or require `cascade=true`) if non-empty; on allowed removals, **recompute** derived state (storage connection paths, slot assignments) inside the same transaction.
6. Trigger: remove
7. **Severity: Critical**

**R-2 — No post-removal recomputation of derived state**
1. `core/models/server/ServerBuilder.php`
2. `removeComponent()`; `computeStorageConnectionPath()` (2661) is called only on storage **add** (line 833)
3. Storage `connection` JSON and card `slot_position` values are never recomputed when the component they depend on (HBA, motherboard, riser, backplane-bearing chassis) is removed.
4. Produces exactly the orphan JSON references the repo's own tooling (`scripts/audit-orphans.php`, seeder 2026_06_24_001) exists to detect — i.e., the root cause is still live for the removal path even though the add path was fixed.
5. Correct layer: Service layer, same transaction as the removal.
6. Trigger: remove
7. **Severity: High**

**R-3 — Removal API cannot target a physical unit (no `serial_number` parameter)**
1. `api/handlers/server/server_api.php`
2. `handleRemoveComponent()` (698) — reads only `config_uuid`, `component_type`, `component_uuid`; `removeComponent()` supports a `$serialNumber` param that is never passed.
3. With two physical units of the same model (same UUID, different serials) installed, removal matches the **first** JSON entry and frees that serial's inventory row.
4. The add path went to great lengths (serial auto-resolve, serial-aware duplicate check) to support same-UUID multi-unit configs; the remove path can't address them, so the wrong physical drive/DIMM can be released, and the remaining JSON entry can end up referencing the serial that was actually freed.
5. Correct layer: API layer (accept `serial_number`) — the service already supports it.
6. Trigger: remove
7. **Severity: High**

**R-4 — `removeComponent()` / `deleteConfiguration()` use unconditional `beginTransaction()`**
1. `core/models/server/ServerBuilder.php`
2. `removeComponent()` (934), `deleteConfiguration()` (3603)
3. `addComponent()` and `finalizeConfiguration()` use the `$ownTransaction = !inTransaction()` pattern; remove/delete call `beginTransaction()` unconditionally and `rollback()` in catch without guards.
4. Any future composite flow (notably a **replace** operation wrapping remove+add in one transaction — the fix for §3) will throw "There is already an active transaction" the moment it calls `removeComponent()`. This asymmetry is the concrete reason atomic replacement can't be built today without touching these methods.
5. Correct layer: Service layer — adopt the same nestable-transaction pattern everywhere.
6. Trigger: remove
7. Severity: Medium

**R-5 — Onboard-NIC removal failure during motherboard removal is tolerated**
1. `core/models/server/ServerBuilder.php`
2. `removeComponent()` (~1025): `error_log("Warning: Failed to remove onboard NICs …")` then proceeds
3. Motherboard is removed even if its synthetic onboard NIC rows can't be cleaned.
4. Leaves `nicinventory` rows with `ParentComponentUUID` pointing at a board no longer in the config → orphan components; regenerated duplicates possible on re-add.
5. Correct layer: Service layer — blocking within the same transaction.
6. Trigger: remove
7. Severity: Medium

**R-6 — `deleteConfiguration()` releases inventory without serials; error path is unsafe**
1. `core/models/server/ServerBuilder.php`
2. `deleteConfiguration()` (3601)
3. Components are released via `updateComponentStatusAndServerUuid(..., serial = default null)` even though JSON entries carry serials; if the config row is missing, `$components` is undefined at `count($components)` after the commit (TypeError → not caught by `catch (Exception)` → 500 after a successful commit; rollback then targets no transaction).
4. Multi-unit same-UUID configs may free the wrong rows / rely on UUID-wide updates; the not-found path is structurally wrong.
5. Correct layer: Service layer.
6. Trigger: remove (delete-config)
7. Severity: Medium

---

## 3. Component Replacement Flow

**RP-1 — There is no replace operation; replacement is two committed transactions with a persisted invalid intermediate state**
1. `api/handlers/server/server_api.php` (action list, 49–116), `core/models/server/ServerBuilder.php`
2. n/a — operation absent; the error text at server_api.php:1554 ("Remove the existing component first if replacement is intended") makes remove→add the documented workflow.
3. Validation occurs: **before replacement — no; during — no; after — no.** Each half validates only itself.
4. Consequences per your scenarios:
   - **CPU A → CPU B:** after `remove A` commits, RAM sits with no CPU-compat anchor; if B's add then fails (socket, TDP, availability), the config persists in the degraded state indefinitely. Nothing re-validates RAM against B beyond the add-time RAM-is-not-the-new-component checks.
   - **Motherboard A → Motherboard B:** after `remove A`, all CPUs/RAM/cards are orphaned (R-1); worse, `validateCPUAddition()`'s "add motherboard first" is CPU-side only — but adding board B runs `checkMotherboardDecentralizedCompatibility()` against existing CPUs/RAM, so an *incompatible* B is rejected **after** A is already gone: the workflow strands the config in the invalid intermediate state by design.
   - **Chassis A → Chassis B:** `checkChassisDecentralizedCompatibility()` does validate B against existing storage form factors/bay capacity (good), but again only after A's removal has committed; if B fails, drives sit chassis-less with stale bay/backplane connection data (R-2).
   - **NIC with SFPs:** the one removal blocker forces a full teardown (remove every SFP → remove NIC → add NIC → re-add SFPs), multiplying intermediate states.
5. Correct layer: Service layer — a `replaceComponent()` that (a) opens one transaction + config lock, (b) validates the incoming component against the config *minus* the outgoing one (both singletons and dependency re-anchoring), (c) performs remove+add with derived-state recompute, (d) commits atomically. Blocked today by R-4.
6. Trigger: replace (new)
7. **Severity: Critical** (architectural gap)

**RP-2 — The one existing replace path (`replaceOnboardNIC`) performs zero compatibility validation**
1. `core/models/compatibility/OnboardNICHandler.php`
2. `replaceOnboardNIC()` (407)
3. Checks only: onboard NIC belongs to config, replacement exists, `Status == 1`. No slot assignment (`assignComponentSlot` never called), no PCIe/lane check, no SFP-port carry-over validation, no `configCache` invalidation; NIC-config JSON is rebuilt but the component NIC gets no `slot_position`.
4. A physical PCIe NIC replaces a zero-slot onboard NIC without consuming a slot or lanes — precisely the "slotless card" stale state again; validation happens neither before, during, nor after.
5. Correct layer: route through the Service-layer add path (or the new `replaceComponent()`).
6. Trigger: replace
7. **Severity: High**

---

## 4. Full Server Validation (`server-validate-config` vs finalize)

**V-1 — Finalize is double-gated by two *different* validators, with the weak one holding the lock**
1. `api/handlers/server/server_api.php`, `core/models/server/ServerBuilder.php`
2. `handleFinalizeConfiguration()` (1133) → `validateConfigurationComprehensive()` **unlocked**; then `finalizeConfiguration()` (3527) → `validateConfiguration()` (3166) **under lock**
3. The strong check (comprehensive) runs outside the transaction; the check inside the lock is the legacy one that only verifies cpu/motherboard/ram presence and computes non-blocking scores.
4. Classic TOCTOU with inverted strength: a concurrent add/remove between the API check and the lock is re-validated only by the weak gate, so a config that would fail comprehensive validation can still finalize. Also A-3 means it can be mutated *after* finalizing anyway.
5. Correct layer: run `validateConfigurationComprehensive()` **inside** `finalizeConfiguration()` under the row lock; delete the API-layer pre-run (or keep it purely informational).
6. Trigger: validate-config / deploy(finalize)
7. **Severity: High**

**V-2 — Defective components don't invalidate the finalize gate**
1. `core/models/server/ServerBuilder.php`
2. `validateConfiguration()` (~3232): `Status === 0` appends to `issues` **without** setting `is_valid = false`; `validateConfigurationComprehensive()` never reads inventory `Status`/`faildate` at all.
3. A component marked failed after being added passes both validators.
4. Missing check on the deploy gate — a failed drive/DIMM should block finalize, or at minimum be a comprehensive-validation error.
5. Correct layer: Global server validator (comprehensive) — add inventory-status verification (the repo even added `faildate` columns via seeder 2026_06_15_001; nothing in validation consumes them).
6. Trigger: validate-config, deploy
7. **Severity: High**

**V-3 — Three divergent full-config validators with different required-component sets**
1. `core/models/server/ServerBuilder.php`
2. `validateConfiguration()` (3166: requires cpu/motherboard/ram, storage "recommended"), `validateConfigurationEnhanced()` (3275), `validateConfigurationComprehensive()` (6414: requires chassis/motherboard/cpu/ram/**storage/nic**)
3. The finalize gate and the validate-config endpoint disagree on what a valid server *is* (a diskless/NIC-less config passes the internal finalize gate but fails validate-config), and `validateConfigurationEnhanced()` is a third, mostly-parallel implementation.
4. Users get contradictory verdicts depending on which endpoint they hit; maintenance burden triples; MemoryAuthority's finalize path hooks only into `Enhanced`.
5. Correct layer: one Global server validator; the other two become thin wrappers or are deleted.
6. Trigger: validate-config
7. **Severity: High** / duplicate

**V-4 — Power / thermal / PSU validation is absent from validate-config (exists only as legacy scoring)**
1. `core/models/server/ServerBuilder.php`
2. `checkPowerCompatibilityDetailed()` (5706) — reachable only from `calculateHardwareCompatibilityScore()` (legacy validators); `validateConfigurationComprehensive()` Steps 3–13 contain no power/thermal/cooling step; `updateConfigurationMetrics()` computes total power but gates nothing.
3. Total draw vs. `getChassisPsuWattage()` never blocks or errors; chassis cooling/airflow and TDP-vs-chassis limits are not validated anywhere.
4. These are exactly the checks that *belong* at validate-config (per your list: power, thermal, airflow) — they exist as dead-end scoring instead.
5. Correct layer: Global server validator (blocking error for PSU over-draw; warnings for thermal headroom).
6. Trigger: validate-config
7. **Severity: High** / missing + misplaced

**V-5 — Checks correctly deferred vs. wrongly placed (summary against your list)**
Belong ONLY in validate-config (and currently are, or should be, there):
- Overall PCIe lane budget → currently *both* add-time (warn) and validate-config with different models (A-9): unify; keep hard per-add slot checks, whole-budget verdict at validate-config once models match.
- Total power / thermal / airflow → **missing** (V-4).
- NUMA balance, CPU generation mix as *advisory*, storage performance & scalability warnings → partially present: `validateMixedCPUCompatibility` exists in `ComponentValidator` (377) but is not called from the add path or comprehensive path — **orphaned check**; NUMA/channel-population balance is absent entirely (RDIMM/LRDIMM mixing, rank limits, channel rules from your RAM list have no implementation — `MemoryAuthority` covers type/count only).
- Chassis airflow, BIOS/firmware/microcode requirements → absent everywhere (no firmware fields are validated).
Severity: Medium–High per item; consolidated in §7 missing-checks list.

**V-6 — Read-time warnings (`getConfigurationWarnings`) are a shadow validator on the wrong trigger**
1. `core/models/server/ServerBuilder.php` (1875), `api/handlers/server/server_api.php` (817)
2. M.2 capacity, caddy/storage form-factor count matching, missing-critical-component warnings are computed on **get-config** and nowhere else.
3. Misplaced: these duplicate/extend validate-config concerns; get-config should *display* the persisted/last-computed validation, not run its own divergent rule set.
5. Correct layer: fold into the Global server validator; get-config reads results.
6. Trigger: validate-config (currently: read)
7. Severity: Medium / misplaced + duplicate

---

## 5. Edge Cases

**E-1 — Stale configuration cache:** `configCache` (5-min TTL, ServerBuilder:40) is invalidated only in `addComponent`/`removeComponent`. Not invalidated by: `finalizeConfiguration()` (status change), `deleteConfiguration()`, API `handleUpdateConfiguration()` (direct SQL), `migrateNICSlotPositions()`, `replaceOnboardNIC()`, post-commit SFP auto-assign (A-11), onboard-NIC auto-add. `getConfigurationDetails()` can serve a deleted/finalized/mutated config for up to 5 minutes. Layer: Service/Repository. Trigger: all mutations. **Severity: Medium.** Also `ComponentQueryBuilder` result cache (195) can serve stale "available" component lists after status flips.

**E-2 — Concurrent updates:** largely addressed by the Phase-1 `FOR UPDATE` work for add/remove/finalize, **but**: the API-layer SFP auto-assign (A-11) and `handleUpdateConfiguration()`'s direct UPDATEs run outside the lock; and the advisory API validation (A-4) is by design a TOCTOU whose backstop is fail-open (A-1). Severity: High (via A-1/A-11).

**E-3 — Invalid quantities:** API accepts `quantity=0` / negative (`(int)$_POST['quantity']`, server_api:333) and feeds the raw value to the advisory validation (lane math with negative quantity) before the builder clamps with `max(1, …)`. Silent clamping also means "quantity=0" becomes an add of 1. Layer: API (reject `< 1` with 400). Trigger: add. Severity: Low.

**E-4 — Component count underflow / negative slots:** removal decrements by full-entry deletion; per-entry `quantity` on CPU/RAM entries is never decremented (remove drops the whole entry), so a quantity-4 entry removed frees only one inventory serial (`updateComponentStatusAndServerUuid` targets one row) while the JSON loses 4 — count drift between JSON and inventory. Layer: Service. Trigger: remove. **Severity: High** (interacts with A-2).

**E-5 — Duplicate components:** serial-aware dupe check is solid for real configs, but **virtual configs skip duplicate, JSON-existence, availability and inventory locking entirely**, and `import-virtual` re-adds components through `addComponent` only when `findAvailableComponent` succeeds — verify that unconvertible/duplicate virtual entries can't silently drop or double-import (handleImportVirtual, server_api:~1027). Severity: Medium (needs test coverage more than code change).

**E-6 — Partial/empty configurations:** comprehensive validation resource tracking (Step 5, 6484) runs socket/RAM/lane tracking only `if motherboard` and bay tracking only `if chassis` — a partial config with storage but no chassis gets **no** bay/connection feasibility feedback (silently skipped rather than reported as "unverifiable"). Empty config returns `no_components` correctly. Severity: Low–Medium.

**E-7 — Failed transaction rollback:** rollback discipline is good in add; remove/delete lack `inTransaction()` guards (R-4, R-6); post-commit side effects can't be rolled back at all (A-11).

**E-8 — Circular dependencies:** none possible in current graph (tree-shaped), but riser→provides-slots→card→(riser in a provided slot?) — `assignRiserSlotBySize` draws only from motherboard riser slots, so no cycle today; worth a guard comment/test if risers ever provide riser slots.

**E-9 — Hot swap:** no concept of it; every remove frees inventory and every add re-validates from scratch. Fine for a build tool; flag only that finalized-config mutation (A-3) is the de-facto "hot swap" path and is unvalidated.

**E-10 — Firmware/microcode/BIOS mismatch, mixed-generation hardware:** no data model or checks (see §7).

---

## 6. Removal Dependency Graph (as it should be enforced)

```
Chassis ──────────────┐ (bays, backplane, риser support, PSU)
Motherboard           │
├── CPU  ── blocks removal while: RAM present (cpu_only-validated), lane consumers exceed remaining budget
│    └── RAM (DDR gen / channel anchor = CPU+MB)
├── PCIe slots
│    ├── Riser Card ── blocks removal while: cards occupy riser-provided slots
│    │    └── PCIe/NIC/HBA cards in provided slots
│    ├── HBA ── blocks removal while: storage.connection.path routes through this HBA
│    │    └── Storage (SAS/SATA via HBA)
│    └── NIC ── blocks removal while: SFPs installed  ✅ (only implemented edge)
│         └── SFP
├── M.2 / U.2 slots ── Storage (NVMe direct)
└── Onboard NICs (synthetic; cascade-removed ✅, failure-tolerant ❌ R-5)
Chassis bays ── block chassis removal while: 2.5"/3.5" storage or caddies installed
Caddy ⇄ Storage pairing (count/form-factor; warning-only today, read-time)
```

**Currently enforced on removal: 1 edge of ~10 (NIC→SFP).** Everything else is allowed and produces orphans (R-1/R-2).

---

## 7. Missing Checks List

| # | Check | Trigger | Severity |
|---|-------|---------|----------|
| M1 | Any dependency check on removal beyond NIC→SFP (all edges in §6) | remove | Critical |
| M2 | Lifecycle guard: block add/remove on finalized configs | add/remove | Critical |
| M3 | CPU quantity-aware socket counting (`quantity` sum) | add | Critical |
| M4 | Atomic replace operation with pre-validated target state | replace | Critical |
| M5 | PSU capacity (blocking) + thermal/airflow (warning) | validate-config | High |
| M6 | Inventory Status/faildate re-verification (defective components block) | validate-config, deploy | High |
| M7 | M.2/U.2 slot capacity enforcement | add + validate-config | High |
| M8 | Derived-state recompute on removal (storage paths, slot maps) | remove | High |
| M9 | `serial_number` on the removal API | remove | High |
| M10 | Manual `slot_position` honoring + occupancy/size validation | add/update | High |
| M11 | Mixed CPU model/stepping check wired in (`validateMixedCPUCompatibility` is orphaned code) | add + validate-config | Medium |
| M12 | RAM channel-population / RDIMM-LRDIMM mixing / rank limits | validate-config | Medium |
| M13 | BIOS/firmware/microcode compatibility (no data model exists) | validate-config, deploy | Medium |
| M14 | Boot-drive requirement (storage exists ≠ bootable) | validate-config | Medium |
| M15 | HBA connector-count vs attached drive count; tri-mode verification at validate-config (add-time only today) | validate-config | Medium |
| M16 | SR-IOV / NIC lane dependency at whole-config level | validate-config | Low |
| M17 | Quantity ≥ 1 rejection at API (no silent clamp) | add | Low |
| M18 | "Unverifiable" reporting when resource tracking is skipped for partial configs | validate-config | Low |

## 8. Misplaced / Duplicate Checks List

| # | Check | Where it is | Where it belongs | Ref |
|---|-------|-------------|------------------|-----|
| D1 | Full `validateComponentAddition` | API (advisory) + Service ×2 engines | Service, once, under lock | A-4 |
| D2 | Pairwise engine (Phase 1.5) | Service | delete (superseded by decentralized+authorities) | A-4 |
| D3 | Chassis/MB singleton | 4 implementations | 1, component validator | A-5 |
| D4 | RAM slot availability | `validateRAMAddition` + `validateComponentQuantity` + `MemoryAuthority` | SlotAuthority-style single owner | A-4/§4 |
| D5 | M.2 capacity, caddy matching | get-config warnings | validate-config | A-10, V-6 |
| D6 | Power check | legacy scoring only | validate-config, blocking | V-4 |
| D7 | Availability check | after compat (Phase 7) | right after inventory lock (Phase 4) | A-6 |
| D8 | Comprehensive validation for finalize | API layer, unlocked | inside `finalizeConfiguration()` lock | V-1 |
| D9 | SFP auto-assign SQL | API handler | ServerBuilder, in-transaction | A-11 |
| D10 | PCIe lanes | two models (add vs validate) | one shared model | A-9 |
| D11 | "Motherboard first" for CPU | add-time hard block | defer like RAM; required-set at validate-config | A-12 |

## 9. Recommended Validation Architecture

```
API layer          param shape, types, quantity ≥ 1, ACL, serial_number on ALL mutations.
                   NO compatibility logic. NO SQL.

Service layer      addComponent / removeComponent / replaceComponent / finalize:
(ServerBuilder)    one nestable transaction + config row lock; ordering:
                   status-guard → type → duplicate → exists-in-JSON → inventory lock
                   → AVAILABILITY → quantity → AuthorityPipeline → slot/bay assignment
                   → persist → derived-state recompute → metrics → commit → cache invalidate.
                   Validation exceptions = rollback (fail-closed).
                   All side effects (onboard NICs, SFP assign) inside the transaction.

Component          One authority per resource domain, quantity-aware, used by BOTH
authorities        add-time and validate-config (single source of truth):
                   SlotAuthority (PCIe incl. riser-provided + manual slots)
                   MemoryAuthority (type, count, channels, mixing)
                   StorageConnectionAuthority (paths, bays, M.2/U.2, backplane)
                   LaneAuthority (one lane model)  ·  CpuAuthority (socket, count, mix, TDP)

Dependency         Directed graph (§6) with two queries:
resolver           dependentsOf(component) → gates REMOVE (block or cascade)
                   dependenciesOf(component) → drives REPLACE re-validation set

Global server      Single validateConfiguration(): required set, singletons,
validator          authority whole-config passes, power/thermal, inventory status,
(validate-config)  advisory tier (NUMA, mixed-gen, scalability). Finalize calls THIS,
                   under the same lock. get-config only displays its persisted result.

Deploy gate        finalize = global validator (blocking incl. defective inventory)
                   + status transition; finalized configs immutable except via
                   replaceComponent maintenance flow that re-runs the global validator.
```

**Suggested remediation order:** A-1 (fail-closed) → A-3/M2 (lifecycle guard) → A-2/M3 (CPU quantity) → R-1/R-2 (dependency resolver + recompute) → R-4 then RP-1 (nestable TX, then atomic replace) → V-1/V-2 (finalize gate) → consolidation (A-4, V-3, D-items).
