# IMS Validation & Lifecycle — Target Architecture (Day-One-Correct Design)

**Companion to:** IMS_COMPATIBILITY_VALIDATION_AUDIT.md
**Mandate applied:** root-cause fixes only; correct architecture over historical preservation; single source of truth; state-machine consistency; unified severity model; enterprise-vendor parity.

This document does not propose patches for the ~30 audit findings. It identifies the six architectural causes that generate them, specifies the system that makes those bug classes impossible, and gives the migration path. Every audit finding is mapped to the design element that eliminates it (§8).

---

## 1. Root Cause Analysis

Applying the four-level chain to the audit's finding classes:

| Symptom (audit) | Immediate cause | Systemic cause | Architectural cause |
|---|---|---|---|
| Orphaned CPUs/RAM/storage after removals (R-1, R-2); orphan-audit tooling exists | Only NIC→SFP checked on remove | No dependency model — dependencies are implicit in scattered `if ($componentType === …)` blocks | **AC-1: Components stored as untyped JSON blobs with no relational integrity and no declared dependency graph** |
| Validation runs 3× with two disagreeing engines (A-4); three full-config validators with different required sets (V-3); RAM slots checked in 3 places (D4); two PCIe lane models (A-9) | Each refactor added a layer without deleting the old one | No designated owner per rule; rules re-implemented at every call site | **AC-2: No single validation authority — rules have no registry, no owner, no shared invocation contract** |
| Finalized configs mutable (A-3); finalize double-gated with the weak check under the lock (V-1); statuses 0/1/2/3 with states 1–2 never set; virtual configs bypass everything | No code path checks status before mutating | Status is an integer column, not a state machine; transitions are ad-hoc UPDATEs | **AC-3: No lifecycle state machines — neither configurations nor inventory components have enforced states, transitions, or transition guards** |
| Replacement = two committed transactions with persisted invalid intermediate state (RP-1); post-commit onboard-NIC/SFP side effects (A-11); nested-transaction landmines (R-4) | No replace operation; side effects appended after commit | Operations are ad-hoc public methods, not atomic commands with declared effects | **AC-4: No command layer — mutations are not modeled as atomic, revisioned operations against a target state** |
| Thrown validation exceptions allow the add (A-1); unparseable slot size adds slot-less cards (A-8); "validation service unavailable" proceeds | `catch { error_log; continue; }` | Errors treated as availability problems, not correctness problems | **AC-5: Fail-open error philosophy — validation absence is treated as validation success** |
| Add-time lane check defaults to `warn`; verdicts depend on `PCIE_LANE_CHECK_ENABLED` / `VALIDATION_PIPELINE_ENABLED` env state; warnings/errors/read-time warnings classified differently everywhere (V-6) | Rollout flags left in place as permanent behavior switches | Severity is per-call-site opinion, not a system property | **AC-6: No unified severity model — and correctness is environment-configurable** |

The rest of this document designs AC-1 through AC-6 out of the system.

---

## 2. Data Model Redesign (eliminates AC-1)

### 2.1 The core defect

`server_configurations` stores components in ten heterogeneous JSON columns (`cpu_configuration`, `ram_configuration`, `hbacard_config` + legacy `hbacard_uuid` scalar, `pciecard_configurations`, `sfp_configuration`, `nic_config`, `motherboard_uuid`, `chassis_uuid`, …), each with its own shape, its own duplicate-matching logic, its own serial-number semantics, and its own updater function. Referential integrity, dependency queries, slot uniqueness, and quantity accounting are all reimplemented in PHP per type — which is *why* orphans, count drift (E-4), and per-type divergence exist. A JSON blob cannot enforce "a slot holds one card" or "this drive's HBA still exists." The database must.

### 2.2 Target schema

```sql
-- One row per PHYSICAL unit placed in a configuration. Serial-level, not model-level.
CREATE TABLE config_components (
    id                BIGINT PRIMARY KEY AUTO_INCREMENT,
    config_uuid       CHAR(36) NOT NULL,
    component_type    ENUM('chassis','motherboard','cpu','ram','storage','nic',
                           'hbacard','pciecard','riser','caddy','sfp') NOT NULL,
    inventory_id      BIGINT NOT NULL,          -- FK → unified inventory row (serial-level)
    spec_uuid         CHAR(36) NOT NULL,        -- model UUID → ims-data spec
    parent_id         BIGINT NULL,              -- FK → config_components.id (dependency edge instance)
    slot_ref          VARCHAR(64) NULL,         -- canonical slot/bay/port id (see resource ledger)
    added_at          DATETIME NOT NULL,
    added_by          INT NOT NULL,
    UNIQUE KEY uq_inventory_once (inventory_id),            -- a physical unit exists in ≤1 config, ever
    UNIQUE KEY uq_slot_occupancy (config_uuid, slot_ref),   -- a slot holds ≤1 component
    FOREIGN KEY (config_uuid)  REFERENCES server_configurations(config_uuid),
    FOREIGN KEY (parent_id)    REFERENCES config_components(id)
);

-- Resource ledger: every consumable declared by installed components, and every consumption.
CREATE TABLE config_resources (
    id            BIGINT PRIMARY KEY AUTO_INCREMENT,
    config_uuid   CHAR(36) NOT NULL,
    resource      ENUM('cpu_socket','dimm_slot','pcie_slot','pcie_lane','m2_slot',
                       'u2_slot','drive_bay_2_5','drive_bay_3_5','sfp_port',
                       'psu_watt','riser_slot') NOT NULL,
    provider_id   BIGINT NOT NULL,   -- config_components.id that PROVIDES capacity (board, riser, chassis, CPU, NIC, PSU)
    slot_ref      VARCHAR(64) NULL,  -- for discrete resources (slot/bay/port ids)
    capacity      INT NOT NULL,      -- lanes/watts for scalar resources; 1 for discrete
    consumer_id   BIGINT NULL,       -- config_components.id consuming it; NULL = free
    FOREIGN KEY (provider_id) REFERENCES config_components(id),
    FOREIGN KEY (consumer_id) REFERENCES config_components(id)
);
```

Consequences, for free, at the database layer:

- **Orphans are structurally impossible.** Removing a provider row violates the FK of every consumer/child row. "Remove motherboard while CPUs exist" is no longer a missing validation — it's a constraint violation unless the command layer cascades deliberately.
- **Duplicate physical placement is impossible** (`uq_inventory_once` replaces `isDuplicateComponent()` and its four sibling matchers).
- **Slot double-booking is impossible** (`uq_slot_occupancy` replaces most of `UnifiedSlotTracker`'s occupancy bookkeeping; the tracker's remaining job — *choosing* a slot — moves into the SlotAuthority rule).
- **Quantity semantics disappear.** One physical unit = one row. The CPU-quantity bypass (A-2) and count-drift-on-removal (E-4) bug classes cannot be expressed.
- **Dependency queries become SQL** (`SELECT … WHERE parent_id = ?` / recursive CTE for the full subtree), which is what the removal/replace resolver (§5) runs.
- **Riser-provided slots are just ledger rows** with `provider_id = riser row`; removing the riser with occupied provided slots is an FK/ledger conflict, not a special case.

`connection` paths for storage stop being persisted JSON — they are derived from ledger rows (drive → consumes `sfp_port`/`drive_bay`/`m2_slot` provided by HBA/backplane/board) and can be *rendered* on demand. The H9 "stale connection path" class ceases to exist because there is no stored path to go stale.

### 2.3 Inventory unification

Ten `{type}inventory` tables share ~90% of columns and all lifecycle logic. Unify into one `inventory_components` table (`component_type` discriminator, generated from the same enum) or, minimally, add a shared `inventory_lifecycle` table keyed by `(component_type, id)` that owns Status/ServerUUID/faildate. One inventory means one lifecycle implementation, one locking discipline, one availability query — Single Source of Truth applied to the data itself. The `hbacard_uuid` legacy scalar and its triple-format decoder are deleted, not preserved.

---

## 3. Lifecycle State Machines (eliminates AC-3)

### 3.1 Server configuration

Current: `0 Draft / 1 Validated / 2 Built / 3 Finalized`, with 1 and 2 unreachable and no transition enforcement. Replace with:

```
draft → building → validating → validated → finalized → deployed → maintenance → retired
                        ↑            |            |          ↕ (maintenance ⇄ deployed)
                        └──(mutation)┘            └→ (unfinalize, privileged) → building
```

Enforced by a transition table, not by convention:

```sql
CREATE TABLE config_status_transitions (
    from_status  VARCHAR(16) NOT NULL,
    to_status    VARCHAR(16) NOT NULL,
    required_permission VARCHAR(64) NOT NULL,
    requires_validation ENUM('none','full') NOT NULL,
    PRIMARY KEY (from_status, to_status)
);
```

Rules the machine encodes (each currently a missing check somewhere in the audit):

- **Mutability is a function of state.** `add/remove/replace` commands are legal only in `draft|building|maintenance`. This *is* the fix for A-3 — not an `if status == 3` guard sprinkled into handlers, but a precondition of the command layer evaluated under the row lock.
- `validating → validated` is the only transition that may be produced by the ValidationEngine, and `→ finalized` **requires** `requires_validation = full` executed inside the same transaction (V-1's TOCTOU becomes unrepresentable: there is no path to `finalized` that doesn't run the full validator under the lock).
- Any mutation of a `validated` config transitions it back to `building` automatically (validation verdicts can never be stale relative to state).
- `deployed → maintenance → deployed` is the sanctioned hardware-swap path (E-9): entering maintenance is audited, replace commands become legal, returning to `deployed` re-requires full validation. This mirrors how OpenManage/OneView treat servers under maintenance mode.

### 3.2 Inventory component

Current: `0 failed / 1 available / 2 in-use` with `faildate` columns nobody reads. Replace with:

```
available → reserved → allocated → installed → active
     ↑          |           |           |         |
     └──────────┴───────────┴───────────┴─────────┼→ maintenance → (available | failed)
                                                  └→ failed → retired
```

- `reserved` fixes the auto-resolve-serial race window honestly: adding to a *draft* config reserves; finalize promotes reserved→allocated; deploy promotes →installed/active. Today "in config JSON" and "Status=2" are the same bit, which is why virtual configs, deletes, and failed side effects leave the two out of sync.
- `failed` is a first-class state consumed by validation: a config containing a `failed` unit cannot pass `validating` (V-2 becomes a rule, not a forgotten flag), and `failed → available` is not a legal transition (no silent resurrection).
- Every transition is written to an `inventory_events` append-only log (observability requirement; also what capacity reporting reads, instead of re-deriving from mutable status).

---

## 4. Unified Severity Model (eliminates AC-6)

One enum, owned by the ValidationEngine, used by every rule, every endpoint, every UI surface:

| Severity | Meaning | Effect on add/remove/replace | Effect on `validating→validated` | Effect on finalize/deploy |
|---|---|---|---|---|
| **ERROR** | Physically or logically impossible (socket mismatch, lane over-subscription, slot conflict, unsupported RAM type, dependency violation) | **Blocks the command** | Blocks | Blocks |
| **VALIDATION_FAILURE** | Config is incomplete/invalid but legal to keep drafting (missing required component, unbalanced channels, PSU over-draw pending PSU add) | Allowed in `draft/building` | **Blocks** | Blocks |
| **WARNING** | Valid but suboptimal (memory downclock, uneven NUMA, suboptimal slot placement, mixed CPU steppings within support matrix) | Allowed, surfaced | Allowed, recorded | Allowed, recorded |

Two hard corollaries:

1. **Severity is a property of the rule, not of the trigger.** M.2 over-population is an ERROR whether encountered at add-time or at validate-time — the current situation (blocking bay check at add, M.2 as a get-config warning) cannot recur because a rule has exactly one severity.
2. **No environment flags on correctness.** `PCIE_LANE_CHECK_ENABLED`, `VALIDATION_PIPELINE_ENABLED`, `SLOT_AUTHORITY_ENABLED`, `STORAGE_CONNECTION_AUTHORITY_ENABLED` are rollout scaffolding and are deleted at cutover. A verdict that differs between two identically-loaded environments is a defect. (Feature flags remain legitimate for *advisory-tier* rules being trialed — i.e., flags may promote WARNING rules in/out, never ERROR rules.)

---

## 5. ValidationEngine — one authority (eliminates AC-2)

### 5.1 Structure

```
ValidationEngine::evaluate(TargetState, Trigger) → Verdict{results: [RuleResult], blocking: bool}

Rule (interface):
    id(): string                    // 'cpu.socket_match', 'pcie.lane_budget', 'power.psu_capacity'
    severity(): Severity            // exactly one
    triggers(): set<Trigger>        // ADD, REMOVE, REPLACE, VALIDATE, FINALIZE
    scope(): Scope                  // PAIR | RESOURCE | CONFIG
    evaluate(TargetState): RuleResult
```

The decisive design choice — the one that makes replace trivial and TOCTOU impossible — is that **rules never inspect the live config; they evaluate a `TargetState`**: the proposed post-operation composition (current rows ± the delta), assembled by the command layer under the row lock. Add validates `current + new`. Remove validates `current − target − cascade`. Replace validates `current − old + new` **as one state** — the invalid intermediate state of RP-1 is not "handled," it never exists as an evaluable object.

### 5.2 Rule registry (single source of truth applied)

Every business rule from the audit's compatibility matrix exists exactly once, with one owner:

| Domain | Rules (ERROR unless noted) | Replaces (deleted) |
|---|---|---|
| CPU | socket match; socket count vs installed CPUs; mixed-model support matrix (W); TDP vs board/chassis (VF); microcode/BIOS floor (VF, new) | `validateCPUAddition`, `validateMixedCPUCompatibility` (orphaned), parts of both engines |
| Memory | DDR generation; ECC requirement; form factor; slot count; RDIMM/LRDIMM mixing; rank limits (VF); channel population (VF); downclock (W) | `validateRAMAddition`, `validateComponentQuantity[ram]`, `MemoryAuthority`, `validateMemorySlotAvailability`, `checkRAMDecentralizedCompatibility` |
| PCIe/slots | slot existence+size+electrical width; occupancy (DB constraint + placement rule); riser slot mapping; **manual slot honored & validated**; lane budget (one model) | `assignComponentSlot`, `UnifiedSlotTracker` occupancy logic, `SlotAuthority`, `PcieLaneBudgetValidator`, `trackPCIeLaneAvailability`, `checkPCIeDecentralizedCompatibility` |
| Storage | interface vs HBA/backplane protocol; bay capacity per form factor; M.2/U.2 slot capacity (ERROR, not read-time warning); caddy pairing; boot-drive present (VF, new) | `StorageConnectionValidator`, `StorageConnectionAuthority`, `computeStorageConnectionPath` persistence, bay logic in 3 places |
| Network | NIC slot/lane; SFP↔NIC port type; port occupancy; SR-IOV lane dependency (W) | `NICPortTracker` checks, `SFPCompatibilityResolver` validation half, `validateSFPAddition` |
| Chassis/board | singletons (now also a DB reality: one `parent_id=NULL` chassis/board row); form-factor lock; riser support; GPU support | four singleton implementations (A-5/D3) |
| System (CONFIG scope) | required-component set (**one** list); power vs PSU capacity; thermal/airflow (VF); NUMA balance (W); inventory state ∉ {failed, maintenance}; firmware matrix (VF, new data model) | `validateConfiguration`, `validateConfigurationEnhanced`, `validateConfigurationComprehensive`, `getConfigurationWarnings`, `calculateHardwareCompatibilityScore` |

(W = WARNING, VF = VALIDATION_FAILURE.)

`server-validate-config` = `evaluate(currentState, VALIDATE)` over all scopes. Add/remove/replace = same engine, trigger-filtered. **The per-trigger question from the original audit ("which checks belong at add vs validate-config?") stops being a placement decision made per call site — it's the `triggers()` declaration on each rule, reviewable in one file.**

### 5.3 Dependency resolver

The dependency graph is declarative data, not code paths:

```php
const DEPENDS_ON = [
    'cpu'     => ['motherboard'],            'ram'   => ['motherboard'],
    'riser'   => ['motherboard'],            'sfp'   => ['nic'],
    'pciecard'=> ['motherboard|riser'],      'hbacard'=> ['motherboard|riser'],
    'nic'     => ['motherboard|riser'],      'caddy' => ['chassis'],
    'storage' => ['hbacard|backplane|motherboard(m2,u2)'],
    'motherboard' => ['chassis(form_factor)'],
];
```

materialized per-instance as `config_components.parent_id` + ledger consumption rows. `RemoveComponent` computes `dependentsOf(target)` (one recursive query); non-empty ⇒ ERROR `dependency.blocked_removal` listing the subtree, unless the command was issued with `cascade=true`, in which case the entire subtree is removed in the same transaction and the *resulting* TargetState is validated. This single mechanism covers every scenario in the original brief — motherboard-with-CPUs, CPU-with-RAM, HBA-with-drives, riser-with-cards, chassis-over-limits — plus every future component type, with zero new code per type.

Ordering asymmetries dissolve too: "CPU requires motherboard first" (A-12) is just the `cpu → motherboard` edge evaluated against TargetState — in `draft` it yields VALIDATION_FAILURE (add allowed, config not validatable), identical in kind to RAM's behavior. One consistent rule instead of a documented exception.

---

## 6. Command Layer (eliminates AC-4)

All mutations become commands with an identical skeleton — the *only* code allowed to open the transaction:

```
Command::execute():
  BEGIN; lock config row (SELECT … FOR UPDATE)
  assert state machine allows this command in current status          (§3)
  assert optimistic revision matches (config.revision = client's If-Match)
  build TargetState (delta + cascade)                                 (§5.1)
  verdict = ValidationEngine::evaluate(TargetState, trigger)
  if verdict.blocking → ROLLBACK, return verdict                      (fail-closed)
  apply delta: config_components / config_resources / inventory transitions
  bump config.revision; append config_events row; persist verdict
  COMMIT
  invalidate caches (single afterCommit hook — E-1 class closed)
```

Commands: `AddComponent`, `RemoveComponent(cascade?)`, **`ReplaceComponent`** (first-class: one TargetState, one commit — RP-1/RP-2 closed; `replaceOnboardNIC` is reimplemented as a ReplaceComponent specialization and loses its validation-free path), `TransitionStatus`, `DeleteConfiguration` (a transition to `retired` + inventory release, serial-accurate because releases are row-level now).

Non-negotiables encoded here:

- **Fail-closed (AC-5):** an exception anywhere between BEGIN and COMMIT rolls back. There is no catch-log-continue site because validation isn't sprinkled through the command — it's one call whose failure is the command's failure. "Could not determine slot size" is a RuleResult (ERROR `pcie.unknown_width` — spec data-shape problems block, per the mandate's operational-safety priority), not a logged shrug.
- **No post-commit side effects.** Onboard-NIC materialization is part of AddComponent(motherboard)'s delta (they're child rows with `parent_id` = board). SFP auto-assignment is a placement decision inside AddComponent(sfp/nic), never handler-level SQL. The API layer shrinks to: parse, authorize, dispatch command, render verdict — no compatibility logic, no advisory pre-runs (A-4's triple execution is deleted, not deduplicated).
- **Optimistic revisioning** gives concurrent administrators (a stated 10-year requirement) safe semantics beyond row locks: a stale client gets 409 + current revision instead of silently last-write-winning, and `config_events` gives the audit trail that FIR-grade operational forensics need.

---

## 7. Enterprise-Vendor Parity Notes

Behaviors where the target design deliberately mirrors OpenManage / OneView / XClarity / Intersight, and the current system diverges:

1. **Template-then-apply.** Vendors validate a *profile/template* (our TargetState) and only then touch state; they never mutate first and validate later. §5.1/§6 adopt this exactly.
2. **Maintenance mode as the swap gate.** Hardware replacement on a deployed server requires an explicit maintenance state with re-validation on exit — §3.1. The current system's silent mutability of finalized configs is the anti-pattern.
3. **Firmware/BIOS as a compatibility dimension.** Every vendor validates firmware floors per component against the platform matrix. The IMS has no firmware fields at all; §5.2 adds the (VALIDATION_FAILURE-tier) rules and the spec-schema additions (`min_bios`, `firmware_matrix`) to ims-data. Flagged per mandate: this is a genuine gap versus all five reference products.
4. **Degraded/failed inventory blocks deployment** and surfaces continuously, not only when a human opens a details page. §3.2 + the `inventory.state` CONFIG rule.
5. **Severity taxonomies are fixed and global** (Critical/Warning/Informational in OME terms). §4.

---

## 8. Finding-Class Closure Map

| Audit findings | Closed by | Mechanism |
|---|---|---|
| A-1, A-8 (fail-open) | §6 | single fail-closed command skeleton; no per-site catches |
| A-2, E-4 (quantity/count drift) | §2.2 | one physical unit = one row; quantity ceases to exist |
| A-3, E-9, V-1 (lifecycle/TOCTOU) | §3.1, §6 | state machine precondition under lock; finalize transition embeds full validation |
| A-4, A-5, D1–D11, V-3, V-6 (duplication/divergence) | §5 | rule registry: one rule, one owner, one severity, declared triggers; legacy validators deleted |
| A-7, A-10 (manual slots, M.2) | §5.2, §2.2 | placement rules + DB occupancy constraint; M.2 capacity is an ERROR rule |
| A-9 (two lane models) | §5.2 | single `pcie.lane_budget` rule evaluated at ADD and VALIDATE |
| A-11, E-1, E-2 (post-commit effects, stale cache, handler SQL) | §6 | side effects inside the delta; one afterCommit invalidation hook; API layer carries no SQL |
| A-12 | §5.3 | dependency edge → uniform VALIDATION_FAILURE semantics |
| R-1…R-6 (removal) | §2.2, §5.3, §6 | FKs + resolver + cascade-in-one-transaction; serial-level rows make `serial_number` API gaps unrepresentable |
| RP-1, RP-2 (replace) | §5.1, §6 | ReplaceComponent evaluates one TargetState; intermediate state never exists |
| V-2 (defective components) | §3.2, §5.2 | `failed` is a state the CONFIG rule reads; `failed→available` illegal |
| V-4, V-5 (power/thermal/NUMA/firmware) | §5.2 | CONFIG-scope rules with declared severities |
| E-3 | §6 API contract | commands take typed inputs; `count < 1` rejected at parse |
| E-5 (virtual configs) | §3.2 | virtual = a config whose components hold `reserved`-less *simulated* inventory rows in a sandbox schema — never share the mutation path's bypass branches (all `isVirtualConfig` branches deleted) |

---

## 9. Migration Plan

The mandate accepts schema change, API redesign, and migration scripts. Sequence chosen so each phase is independently shippable and reversible, with the highest-risk data move done under dual-write:

**Phase 0 — Stop the bleeding (days).** Two surgical changes that are *also* correct in the target design, not band-aids: make every validation exception blocking (A-1), and enforce status-based mutability in the service layer (A-3). Both survive the migration untouched.

**Phase 1 — Schema + backfill (1–2 sprints).** Create `config_components`, `config_resources`, `config_events`, transition tables. Write the extractor that materializes rows from the JSON columns (the logic already exists as `extractComponentsFromJson` + `audit-orphans.php`; orphans found during backfill are reported and quarantined, not silently dropped). Enter **dual-write**: commands write rows *and* legacy JSON; reads stay on JSON. `tests/serverstate_equivalence.php` and the golden baseline are extended to assert row/JSON equivalence continuously.

**Phase 2 — State machines (parallel to 1).** Introduce the config and inventory status enums with a mapping migration (`0→draft, 3→finalized`; inventory `0→failed, 1→available, 2→installed`), the transition tables, and `TransitionStatus`. Legacy integer columns become generated/read-only views for old clients during the deprecation window.

**Phase 3 — Engine + commands (2–3 sprints).** Implement ValidationEngine with rules ported from the *decentralized* engine and the authority classes (they're the newest and test-covered: `lane_authority_unit`, `memory_authority_unit`, `slot_storage_authority_unit` become the rule tests). Cut commands over one at a time — Remove first (biggest gap), then Add, then the new Replace. Each cutover deletes its legacy path in the same PR (Phase 1.5 pairwise loop, advisory API pre-check, the three full-config validators, `getConfigurationWarnings`, env correctness flags). The characterization tests (`characterize_compatibility.php`, `compatibility_baseline.json`) pin behavior across the swap; intentional verdict changes (the audit's missing checks) are recorded as baseline updates with rationale.

**Phase 4 — Read cutover + deletion (1 sprint).** Reads move to rows; JSON columns are dropped after a soak period (a final `audit-orphans.php` run must return zero). `hbacard_uuid`, `isVirtualConfig` branches, `UnifiedSlotTracker`, both compatibility engines' validation halves, and the flag plumbing are deleted. The API gains `revision`/`If-Match`; v1 endpoints remain as thin adapters for one deprecation cycle, then go.

**Exit criteria:** every business rule greps to exactly one `Rule` class; every mutation path is a command; `git grep 'Continue without'` returns nothing; the orphan auditor is demoted from operational tool to CI invariant check.

---

## 10. What Is Deliberately Rejected

Per the mandate, the following "cheaper" alternatives were considered and rejected as symptom fixes:

- *Adding dependency `if`-blocks to `removeComponent()`* — reduces R-1 today, but the class (implicit dependencies re-implemented per type) survives; the next component type reintroduces it. The graph + FKs make it impossible.
- *Passing `quantity` into the CPU validator* — fixes A-2's instance; per-entry quantity semantics keep generating drift bugs (E-4). Row-per-unit deletes the concept.
- *Adding a `status == 3` check to the two handlers* — leaves transitions unowned; the maintenance/deployed workflow the business actually needs (hardware swaps on live servers) remains unmodelable. The state machine provides both.
- *Deduplicating to "only run validation twice"* — any number of authorities above one regenerates divergence. The registry's one-owner rule is the invariant worth paying for.
