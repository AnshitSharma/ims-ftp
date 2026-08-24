# IMS backend — as-built architecture

**Unit:** U-P.2 (`migration/12-post-cutover`). **Written:** 2026-08-24.

This describes the system **as it is**, not as `migration/00-overview/IMS_TARGET_ARCHITECTURE.md`
specifies it. Where the two differ, §12 says so explicitly and nothing else in this file
pretends otherwise.

**Evidence rule.** Every structural claim below carries a `file:line` reference that was read
in the session that wrote this file. Line numbers drift — when a citation and the prose
disagree, the code wins and this file is stale; fix it rather than working around it. Nothing
here is inferred from a migration pack: packs describe intent, and several of them are wrong
about today's code (see `../BACKLOG.md` §D).

---

## 1. Shape in one paragraph

One PHP entrypoint (`api/api.php`) dispatches action-named POSTs to per-module handlers.
Server-build mutations used to be methods on a 9,586-line `ServerBuilder`; they are now
**commands** (`core/models/commands/`) that own the transaction, evaluate a **TargetState**
against a single **ValidationEngine** rule registry under the config row lock, and write to a
**row store** (`config_components` / `config_resources` / `config_events`) while still
mirroring the legacy JSON columns. Hardware facts live outside the DB, in `ims-data/*.json`,
resolved through `ComponentSpecPaths`. Five migration flags select old path vs. new path; all
five are at their terminal values in production, so the new path is the live one and the old
path is reachable only by rolling a flag back.

---

## 2. Request path

- Single endpoint. `action` is parsed as `{module}-{operation}` by an
  `explode('-', $action, 2)` at `api/api.php:104-106`, so the module is everything before the
  *first* hyphen and the operation is the remainder verbatim.
- `auth-*` short-circuits before authentication (`api/api.php:115-119`); everything else
  requires a JWT (`api/api.php:123-126`).
- Module dispatch is a `switch ($module)` at `api/api.php:133`; `server` →
  `requireModulePermission('server', …)` then
  `require_once api/handlers/server/server_api.php` with the operation passed through
  `$GLOBALS['operation']` (`api/api.php:134-139`).
- `rack` carries a second, code-level role gate (admin / super_admin) *on top of* ACL
  (`api/api.php:153-155`). `compatibility` passes the bare underscore-style operation through
  (`api/api.php:141-146`).
- Action→permission mapping is centralised and strict: `api/permission_map.php`. The
  command-layer actions are mapped there — `replace-component => server.replace`
  (`api/permission_map.php:41`), `transition-status => server.transition`
  (`api/permission_map.php:42`) — as are the four temporary debug diagnostics
  (`api/permission_map.php:65-68`), each additionally role-gated inside its handler.
- Server operations are a `switch` in `api/handlers/server/server_api.php:51-157`, which
  includes `server-replace-component` (:66), `server-transition-status` (:71) and
  `server-debug-deadcode` (:157).
- Response shape is unchanged from pre-migration: `{success, authenticated, code, message,
  timestamp, data}` via `send_json_response()`.

**Engine verdicts reach the legacy response contract through a shim.**
`api/handlers/server/VerdictShim.php` maps a `Verdict` onto the legacy
`{success, error_type, message, …}` shape; it is called from four sites in the server handler
(`server_api.php:713-714`, `:1056-1057`, `:1198-1199`, `:1266-1267`).

---

## 3. Flags — the whole rollout regime

Declared in `migration/00-overview/FLAGS.md`; INV-12 forbids creating any others. Each is read
by exactly one class, all with the same `getenv → $_ENV → default 'off' → whitelist` shape:

| Flag | Values | Reader | Production value (2026-08-22) |
|---|---|---|---|
| `DUAL_WRITE_ENABLED` | off, on | `ConfigComponentWriter::mode()` — `core/models/config/ConfigComponentWriter.php:104-106` | `on` |
| `STATE_MACHINE_ENABLED` | off, shadow, enforce | `StateGuard::mode()` — `core/models/state/StateGuard.php:35-37` | `enforce` |
| `ENGINE_MODE` | off, shadow, enforce | `ValidationEngine::mode()` — `core/models/validation/ValidationEngine.php:87-89` | `enforce` |
| `COMMAND_LAYER_ENABLED` | off, shadow, enforce | `CommandLayer::mode()` — `core/models/commands/BaseCommand.php:22-24` | `enforce` (2026-08-21) |
| `READ_FROM_ROWS` | off, sample, on | `ConfigReadRouter::mode()` — `core/models/config/ConfigReadRouter.php:120-122` | `on` (2026-08-21) |

Values are from the live `server-debug-migration-flags` probe recorded in
`reports/cutover-signoff-20260822.md` §1. That table also records that **three of the five
promotion dates are unrecoverable** — only the last two are dated. Live values are readable at
any time through the debug action, whose handler reads all five
(`api/handlers/server/server_api.php:2118-2122`).

Semantics (FLAGS.md, honoured by every reader above): `off` = new path not executed; `shadow`
= new path runs, result logged/compared, **legacy result returned**; `enforce`/`on` = new path
authoritative. Rollback for every flag is setting it back down — instant, and lossless for
everything except data already written by the new path.

Four *legacy* authority flags predate the migration and are still read —
`PCIE_LANE_CHECK_ENABLED`, `VALIDATION_PIPELINE_ENABLED`, `SLOT_AUTHORITY_ENABLED`,
`STORAGE_CONNECTION_AUTHORITY_ENABLED` — plus `MEMORY_AUTHORITY_ENABLED` and
`STORAGE_BAY_AUTHORITY_ENABLED`. Live readers today: `MemoryAuthority.php`,
`PcieLaneBudgetValidator.php`, `SlotAuthority.php`, `StorageConnectionAuthority.php`,
`ValidationPipeline.php`, `ServerBuilder.php`, and two *replacement-side* files —
`core/models/validation/rules/PcieLaneBudgetRule.php` and
`core/models/validation/rules/StorageInterfacePathRule.php`. The last two matter: the
rule-side reader of `STORAGE_BAY_AUTHORITY_ENABLED` is not residue, so U-D.4 cannot clear that
marker by grep alone.

---

## 4. The command layer

### 4.1 `BaseCommand` — one skeleton, one transaction owner

`core/models/commands/BaseCommand.php`. `execute()` is `final` (`BaseCommand.php:236`) and
runs a fixed sequence:

1. begin transaction *only if not already inside one* — the nestable `ownTransaction` pattern
   (`BaseCommand.php:238-241`);
2. `SELECT … FOR UPDATE` the `server_configurations` row (`BaseCommand.php:164-173`), 404 if
   absent (`:245-247`);
3. `StateGuard::checkMutation()` (`:249-252`);
4. optimistic revision match, skipped when `expectedRevision` is null (`:254-260`);
5. `TargetStateBuilder::fromCurrent()` then the subclass's `buildTarget()` (`:262-263`);
6. `(new ValidationEngine())->evaluate($target, $this->trigger())`; a blocking verdict throws
   (`:265-268`);
7. `apply()` (`:270`), re-read revision (`:272`), commit (`:274-276`);
8. `afterCommit()` — cache invalidation only, outside the transaction (`:155-157`, `:289`).

Fail-closed is structural: `CommandFailed` rolls back and rethrows (`:277-281`), and *any*
other `Throwable` is wrapped into `CommandFailed('command_exception', …, 500)` after a
rollback (`:282-287`). There is no catch-and-continue site.

Two deliberate deviations from the pack are documented in the class docblock rather than
silently taken: PD-3 (revision/event bumps happen inside `apply()` via the repository, not in
`BaseCommand`) at `BaseCommand.php:87-98`, and PD-4 (`buildTarget()` receives the current
`TargetState`, not a builder instance) at `:106-115`.

`dryRun()` (`BaseCommand.php:308-332`) is the shadow-mode primitive: it locks, builds and
evaluates exactly as `execute()` would, never calls `apply()`, and **always** rolls back.

`assertInventoryAvailability()` (`BaseCommand.php:196-226`) is the post-lock availability gate
ported from the legacy path, including its two lenient edges (`override_used` bypasses
everything; a virtual config bypasses entirely).

### 4.2 The four commands

| Command | File | Trigger | Notes |
|---|---|---|---|
| `AddComponentCommand` | `core/models/commands/AddComponentCommand.php` | `ADD` (`:56-59`) | plans the slot and resolves `sfp→nic` parentage **before** evaluate (`:61-108`); `apply()` inserts the row, mirrors to legacy JSON via `ServerBuilder::updateServerConfigurationTable()`, re-syncs rack placement for a chassis, materialises onboard NICs, and flips inventory to in-use (`:110-179`) |
| `RemoveComponentCommand` | `core/models/commands/RemoveComponentCommand.php` | `REMOVE` | real cascade support via `TargetStateBuilder::withRemove($current, $id, $cascade)` (`:121`), closure computed against the **pre-removal** state (`:117-119`); unit release is fail-closed (docblock `:32-47`) |
| `ReplaceComponentCommand` | `core/models/commands/ReplaceComponentCommand.php` | `REPLACE` | one TargetState — remove old, add new with slot inheritance, re-anchor children (`:20-32`); no legacy counterpart, so there is nothing to shadow-diff against |
| `TransitionStatusCommand` | `core/models/commands/TransitionStatusCommand.php` | `FINALIZE`, fixed | scoped to transitions whose edge requires full validation; PD-6 explains why `trigger()` is unconditional (`:14-27`) |

`CommandShadowLog` (`core/models/commands/CommandShadowLog.php`) writes the
`COMMAND_LAYER_ENABLED=shadow` evidence stream to `reports/shadow/command-<Ymd>.jsonl`.

### 4.3 Where the dispatch happens

Per-handler, not centrally. `handleAddComponent` reads `CommandLayer::mode()` at
`api/handlers/server/server_api.php:629` and branches three ways:

- `shadow` (`:633-675`) — `dryRun()` inside a double catch (`CommandFailed` *and* raw
  `Throwable`, because `dryRun()` uses `finally` not a catch-all, `:644-651`), then the
  **legacy** `addComponent()` is the only mutation (`:656`), and every evaluation is logged,
  not just divergences (`:660-674`);
- `enforce` (`:676` onward) — the command is authoritative; `quantity > 1` maps to N
  sequential dispatches inside one outer transaction (`:687-697`);
- `off` — legacy only.

`handleRemoveComponent` mirrors this at `server_api.php:991`. The advisory pre-check that
audit A-4 called the third validation run is skipped **only** at enforce
(`server_api.php:540-541`, with the reasoning at `:527-539`). `server-replace-component` and
`server-transition-status` refuse outright while the flag is off (`:1144`, `:1229`).

`finalizeConfiguration()` is a shim rather than a handler branch:
`ServerBuilder.php:4308-4327` delegates the whole method to `TransitionStatusCommand` at
enforce and otherwise falls through to the legacy body. A sandbox/bench config is refused
*ahead of both* paths (`ServerBuilder.php:4299-4306`), so no flag state can route around it.

---

## 5. The legacy `ServerBuilder` path (still present)

`core/models/server/ServerBuilder.php` is **9,586 lines** and still holds the pre-migration
implementation. Load it by range, never whole. The methods that matter:

| Method | Line | Status today |
|---|---|---|
| `extractComponentsFromJson()` | 86 | live — the `READ_FROM_ROWS=off` answer and the `sample`-mode comparison baseline |
| `lockAndLoadConfigRow()` | 568 | private; `BaseCommand` keeps its own copy on purpose |
| `addComponent()` | 583 | legacy add; no reachable caller at enforce |
| `removeComponent()` | 1346 | legacy remove; **one** live external caller, `scripts/audit-orphans.php:190` |
| `getConfigurationWarnings()` | 2679 | live, called from the API |
| `getConfigurationDetails()` | 2953 | live; holds the config cache that sits *above* `ConfigReadRouter` |
| `updateServerConfigurationTable()` | 3116 | live — the commands call it as the legacy-JSON writer |
| `updateConfigurationMetrics()` | 3827 | live, not a deletion target |
| `validateConfiguration()` / `…Enhanced()` | 3932 / 4032 | only caller is `scripts/verify/performance_report.php:174-175` |
| `finalizeConfiguration()` | 4294 | live; enforce branch is the U-C.5 shim |
| `deleteConfiguration()` | 4461 | **live, and has no command replacement** |
| `validateCPUAddition()` / `validateRAMAddition()` | 4858 / 4954 | reachable only from `addComponent()` |
| `validateComponentAddition()` | 5152 | the `ENGINE_MODE` hook |
| `legacyValidateComponentAddition()` | 5273 | called by the `off`/`shadow` branches only |
| `assignComponentSlot()` | 5720 | legacy slot assignment; fail-open branch at 5849 |
| `validateComponentCompatibility()` | 5931 | the Phase-1.5 pairwise loop |
| `extractPCIeSlotSize()` | 5877-5897 | legacy width parser; `SlotPlanner::extractCardWidth()` is its port |
| `isSandboxConfig()` | 6169 | live |
| `updateComponentStatusAndServerUuid()` | 6489 | public for command reuse |
| `validateConfigurationComprehensive()` | 7732 | live from `server_api.php` and from `finalizeConfiguration()` |
| `validateComponentQuantity()` | 9248 | reachable only from `addComponent()` |

The four `beginTransaction()` sites in this file are inside `addComponent()` (`:625`),
`removeComponent()` (`:1353`), `finalizeConfiguration()` (`:4337`) and
`deleteConfiguration()` (`:4469`) — exactly the set U-C.6 exists to consolidate, and the
reason INV-3 is not yet satisfiable (§12).

The old validation authorities are all still on disk and still wired:
`ComponentCompatibility.php` (5,427 lines), `UnifiedSlotTracker.php` (2,221),
`StorageConnectionValidator.php` (2,076), `NICPortTracker.php`,
`SFPCompatibilityResolver.php`, `OnboardNICHandler.php`, `PcieLaneBudgetValidator.php`,
`ServerState.php`, and the three authority shells `SlotAuthority` /
`StorageConnectionAuthority` / `MemoryAuthority` behind `ValidationPipeline.php`.

---

## 6. TargetState, TargetStateBuilder, SlotPlanner

**`TargetState`** (`core/models/validation/TargetState.php`, 242 lines) is the immutable
proposed post-operation composition rules evaluate. It is constructed only by the builder.

**`TargetStateBuilder`** (`core/models/validation/TargetStateBuilder.php`, 322 lines) is pure
array math and never writes:

- `fromCurrent(PDO, $configUuid)` (`:37-53`) — takes the **rows** path when
  `ConfigComponentRepository::liveRows()` returns anything (`:41`), otherwise mirrors the
  legacy JSON columns via `jsonFallbackRows()` (`:52`, defined `:257`).
- `withAdd()` (`:56`), `withRemove($state, $id, $cascade)` (`:84`),
  `withReplace($state, $oldId, $newRow)` (`:111`), `dependentsOf()` (`:143`).
- The appended row is marked as the *subject* of the operation (`:71-77`) — F-24.
- Documented gaps, still true: the JSON fallback cannot recover `slot_ref` for
  pciecard/hbacard or parentage for anything but `sfp→nic`, and json-source rows always carry
  `status_v2 = null` (docblock `:18-33`).
- **The source selection is `!empty($rows)`, i.e. non-empty, not complete** (`:41`). A config
  whose row store covers only part of its legacy JSON takes the rows path anyway and the
  unmirrored components are invisible to every rule.
  `scripts/verify/partial_rows_report.php` exists to detect that; its last run says no live
  config is partial.

**`SlotPlanner`** (`core/models/validation/SlotPlanner.php`, 129 lines) is a pure function
over `TargetState` resources with no PDO. `extractCardWidth()` (`:37-46`) is the verbatim port
of `ServerBuilder::extractPCIeSlotSize()`; `SLOT_COMPATIBILITY` (`:26-31`) is the verbatim
port of `UnifiedSlotTracker::$slotCompatibility` (smallest compatible slot first); `plan()`
(`:63`) and `planManual()` (`:89`) implement the two intentional divergences from legacy — a
manual slot request is honoured *and validated* (A-7), and an unparseable width is an ERROR
rather than legacy's fail-open (A-8), both stated in the docblock at `:23-28`.

---

## 7. ValidationEngine

`core/models/validation/ValidationEngine.php` (169 lines).

- Registry: `const RULES` — **22 rule classes** (`:49-80`), grouped cpu(4) memory(5) pcie(2)
  storage(4) net(2) system(4) dependency(1), each with its originating U-R unit named in a
  comment. All 22 files live in `core/models/validation/rules/`.
- Vocabulary: `Trigger` = ADD, REMOVE, REPLACE, VALIDATE, FINALIZE
  (`core/models/validation/Trigger.php:8-12`); `Severity` = ERROR, VALIDATION_FAILURE, WARNING
  (`core/models/validation/Severity.php:16-18`). Both are constant classes, not PHP 8 enums —
  PD-2, recorded in `Severity.php:5-13`, because `ims-ftp/CLAUDE.md` pins PHP 7.4+ and this
  file is parsed on every request regardless of flag state.
- Blocking is a property of severity × trigger, computed once in `Verdict::blocking()`
  (`core/models/validation/Verdict.php:35-52`): ERROR always blocks; VALIDATION_FAILURE blocks
  only under VALIDATE/FINALIZE; WARNING never blocks.
- **FINALIZE subsumes VALIDATE** (F-26): `rulesFor()` (`ValidationEngine.php:129-136`) admits
  a rule to FINALIZE if it declares VALIDATE. The docblock above it (`:96-127`) records the
  live incident that forced this — 2026-07-28T20:03:44Z, config `a3177ce9`, legacy blocked,
  the command layer allowed with zero failures because only 4 of 22 rules declared FINALIZE.
  The relation is deliberately **not** symmetric: ADD/REMOVE/REPLACE stay strict.
- Fail-closed at rule granularity: a throwing rule is not swallowed but synthesised into a
  failed ERROR result `engine.rule_exception` (`:151-160`).
- `ShadowRunner` (`core/models/validation/ShadowRunner.php`) is the `ENGINE_MODE=shadow`
  recorder; its stream is `reports/shadow/engine-<Ymd>.jsonl`, consumed by
  `scripts/verify/parity_report.php`.

Expected engine-vs-legacy divergences are declared data, not commentary:
`scripts/verify/expected_diffs.json` holds 8 matcher entries, each citing an audit finding
(A-2, A-12, M11, D4, A-8, A-9, A-10, A-12-class), plus seven `_note_*` keys explaining the
divergences deliberately left **without** a matcher so a real occurrence surfaces as
unexplained rather than pre-approved.

---

## 8. Lifecycle state

- `StateGuard` (`core/models/state/StateGuard.php`) is the mutation gate:
  `status_v2 ∈ {draft, building, maintenance}` may be mutated (`:27`), anything else blocks
  (`:74-92`); a NULL `status_v2` falls back to the legacy int rule "blocked iff
  `configuration_status === 3`" (`:94-105`). `off` is a no-op, `shadow` logs only
  disagreements to `reports/shadow/state-guard.jsonl` and always returns null, `enforce`
  returns the verdict (`:53-71`).
- `StateMachine` (`core/models/state/StateMachine.php`) owns the transition tables and
  `applyConfigTransition()` (status_v2 + mapped legacy int + revision/event bump, atomically).
- `StatusMap` (`core/models/state/StatusMap.php`) is the single source of truth for the lossy
  v2↔legacy mapping: config `draft→0, building→2, validating→2, validated→1, finalized→3,
  deployed→3, maintenance→3, retired→3` (`:20-29`); inventory `available→1`, everything from
  `reserved` through `maintenance` → 2, `failed`/`retired` → 0 (`:47-56`). The inverse maps
  exist for one-shot backfills only and are explicitly not a true inverse (`:9-12`).
- Legacy ints are still authoritative for external readers. `TEMP-GUARD` markers from U-0.2
  are still present — 12 occurrences under `core/` + `api/` — because `StateGuard` at enforce
  requires callers to *skip* them, not delete them (`StateGuard.php:20-23`); physical removal
  is U-D.4.

---

## 9. The row store

Schema as pinned by `scripts/verify/expected_schema.json` (what `schema_report.php` asserts
against):

- **`config_components`** — one row per physical unit. Columns include
  `(inventory_table, inventory_id)`, `spec_uuid`, `serial_number`, `parent_id`, `slot_ref`,
  `added_at/by`, `removed_at`. Uniques: `uq_inventory_once (inventory_table, inventory_id)`
  and `uq_slot_occupancy (config_uuid, slot_ref, removed_at)`. Note both deviations from the
  target design: identity is a *table+id pair*, not an FK into a unified inventory table, and
  slot occupancy includes `removed_at` so tombstoned rows do not collide.
- **`config_resources`** — `resource` enum, `provider_id`, `slot_ref`, `capacity`,
  `consumer_id`; unique `uq_discrete (config_uuid, resource, slot_ref)`.
- **`config_events`** — append-only audit: `revision`, `event`, `component_type`,
  `component_id`, `actor`, `payload` JSON, with unique `uq_config_rev (config_uuid, revision)`.
  That unique is what makes INV-6 checkable.
- **`server_configurations`** gains `revision` (not null) and `status_v2` (nullable enum);
  each `{type}inventory` gains a nullable `status_v2`.
- **`config_status_transitions`** and **`inventory_status_transitions`** hold the edges.

Access classes:

- `ConfigComponentRepository` (`core/models/config/ConfigComponentRepository.php`) —
  `liveRows()`, `insert()`, `tombstone()`, `bumpRevision()`. `insert()`/`tombstone()` bump
  revision and append the event atomically with the row write, which is how commands satisfy
  INV-6 without `BaseCommand` doing it generically.
- `ConfigComponentWriter` (`core/models/config/ConfigComponentWriter.php`) — the
  `DUAL_WRITE_ENABLED` hook (`afterLegacyAdd`/`afterLegacyRemove`) that mirrors legacy JSON
  writes into rows and maintains the ledger. Virtual configs are excluded at the
  `afterLegacyAdd()` **call site** in `ServerBuilder::addComponent` (`if (!$isVirtual)`), not
  by the `isSandboxConfig()` guard in `finalizeConfiguration()` — different guards, different
  jobs.
- `ConfigReadRouter` (`core/models/config/ConfigReadRouter.php`, 541 lines) — the read seam.
  `components()` (`:139`) returns legacy verbatim at `off` (`:144-146`); at `sample` it runs
  both sides, logs the **outcome including agreement** and returns legacy unconditionally
  inside a `try/catch` (`:156-166`); at `on` the rows side is the answer and **a throw is
  deliberately not swallowed** (`:172-174`). The `kind` field
  (compared | divergence | skipped_virtual) is F-27's fix for a log whose emptiness was
  unreadable (`:17-22`). The `getConfigurationDetails()` cache sits *above* this router, so no
  mode can poison a cache entry (`:24-27`).
- `ResourceCatalog` (`core/models/config/ResourceCatalog.php`, 665 lines) — spec → provided
  and consumed resources. `provides()` (`:153`), `consumes()` (`:190`), and per-type providers
  for cpu / nic / chassis / motherboard / risercard. Since the 2026-08-14 riser/pciecard
  split, `provides('pciecard', …)` returns nothing and `providesRisercard()` (`:640`) is the
  riser provider — anything modelling a riser as a `pciecard` row is stale. `DEPENDS_ON` keys
  on `risercard`.

---

## 10. Hardware specs: the `ims-data` path

The DB stores inventory; `ims-data/*.json` stores what the hardware *is*.

`core/models/components/ComponentSpecPaths.php` is the only map, and the filenames are
irregular on purpose (`ComponentSpecPaths.php:5-17`): `cpu/Cpu-details-level-3.json`,
`ram/ram_detail.json`, `caddy/caddy_details.json`, `pciecard/pci-level-3.json`,
`risercard/riser-level-3.json`, `chassis/chasis-level-3.json` (the typo is load-bearing).
Never guess a path; never "fix" a filename.

Base-path resolution (`:28-49`, `:73-109`), in order:

1. `getenv('IMS_DATA_PATH')`;
2. an `IMS_DATA_PATH=` line in `<ims-ftp>/.env`, parsed by hand;
3. `dirname($projectRoot).'/ims-data'` then `$projectRoot.'/ims-data'`;
4. otherwise **throw** `RuntimeException` — resolution never degrades to "no specs".

`PLATFORM_PATH` (`:26`) points at `serverplatform/server-platform-level-3.json` and is
deliberately kept **out** of `PATHS`, because `getAll()` feeds loaders that treat every key as
a real component type with an inventory table behind it (`:19-25`). Server compute platforms
are a grouping over motherboard specs, not a 12th component type.

Consumers reach specs through `ComponentDataService` (request-level cache) and
`DataExtractionUtilities`. **`ComponentDataService` renames fields**: `extractNicSpecs()`
emits `interface_type` from ims-data's `interface`
(`core/models/components/ComponentDataService.php:612`). That single rename is the head of a
defect chain reaching the PCIe lane model and legacy slot assignment — see `../BACKLOG.md` §A.

---

## 11. Verification surface (pointer)

The as-built system carries its own instrumentation: `scripts/verify/*_report.php` gate
reports orchestrated by `scripts/verify/run_all.php`, the shadow streams under
`reports/shadow/`, `scripts/ci/invariants.sh` for the architectural invariants, and `tests/`
for the suites. How to run and read all of it is `docs/OPERATIONS.md`, not this file.

---

## 12. Where the target design and the as-built system differ

Stated plainly, because several migration documents read as though these are done.

1. **INV-3 is not satisfied.** `beginTransaction` still appears in `ServerBuilder` (4 sites:
   `:625`, `:1353`, `:4337`, `:4469`), `OnboardNICHandler` (`:67`, `:468`),
   `ServerConfiguration.php:143`, `ACL.php`, `BaseFunctions.php`, `PipelineManager`,
   `PipelineTemplateManager`, three API handlers and five verify scripts. Commands are *a*
   transaction owner, not *the* transaction owner. U-C.6 is the unit for this and is
   `in_progress`.
2. **`DeleteConfiguration` was never built.** The target design lists it as a command (§6);
   `core/models/commands/` contains four commands and none of them is it.
   `ServerBuilder::deleteConfiguration()` (`:4461`) is the only implementation.
3. **`replaceOnboardNIC` was never ported.** `IMS_TARGET_ARCHITECTURE.md:226` says it is
   "reimplemented as a ReplaceComponent specialization". It is not:
   `ReplaceComponentCommand.php:105-107` explicitly excludes `onboard-` UUIDs, and nothing
   calls `OnboardNICHandler::replaceOnboardNIC()`. The capability is unreachable from the API
   today — a shipped regression, documented in
   `migration/10-cleanup/FINDING-20260824-replaceOnboardNIC-not-superseded.md`.
4. **Inventory is not unified.** Eleven `{type}inventory` tables remain; `config_components`
   carries `(inventory_table, inventory_id)` with `orphan_report.php` as a *detection*
   control. Accepted as F-6 in `migration/PLAN_VERIFICATION_REVIEW.md:32`.
5. **The legacy JSON columns still exist**, and dual-write still maintains them. U-D.3 (the
   drop) has not run. So there are two stores, and equivalence between them is a gate, not an
   invariant.
6. **Legacy validators still exist.** The target design deletes
   `validateConfiguration`/`Enhanced`/`Comprehensive`, `getConfigurationWarnings`,
   `UnifiedSlotTracker`, both compatibility engines' validation halves, and the authority
   classes. All are on disk; several are live. `ValidateConfigService.php`, which U-D.2 names
   as the replacement for `server-validate-config`, **does not exist**.
7. **Correctness is still environment-configurable** in the sense the target design's §4
   forbids: the six legacy authority flags remain, and two of their readers are in the new
   rule files.
8. **Severity is a property of the rule (this one holds).** INV-7's check — no
   `getenv`/`_ENV` under `core/models/validation/rules/` — passes.
9. **Firmware/BIOS compatibility does not exist** in any form; it needs `ims-data` schema
   additions first (target design §7.3). NUMA-balance and thermal/airflow rules likewise are
   named in the target registry and absent from `ValidationEngine::RULES`.
10. **Virtual/sandbox configs remain outside the new machinery** by design (F-5): excluded
    from backfill, from equivalence, and from dual-write, though `handleImportVirtual()` now
    routes adds through `AddComponentCommand`. There is no sandbox schema.
