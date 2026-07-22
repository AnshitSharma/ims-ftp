# BDC IMS — Realistic Server Builds & Compatibility-Engine Defect Report

**Objective (the real one):** take servers that are **physically buildable** from the inventory we
actually have, try to build each one in IMS **in the correct order**, and **catalog every place the
engine wrongly blocks or crashes on a build that would work in the real world.** Those wrong
blocks/crashes are bugs. Fixing them is the goal.

Verified live against production on 2026-07-21/22 using `is_virtual=1` configs (reserve nothing) plus
**one temporary chassis unit** (registered, used across all builds, then deleted). All probe configs
were deleted; the temp chassis was removed. *Note:* the live system is in active use by the team, so
raw availability counts drift between runs from concurrent edits — that is expected, not caused by
these dry-runs (virtual configs reserve nothing).

> **Bottom line — 5 defects, now including a hard crash.** Adding a **chassis before storage**
> (the correct order) *does* let drives attach — proving the earlier "no storage possible" wall was
> only the missing chassis. But doing it surfaced worse problems:
> 1. 🔴 **`validate-config` returns HTTP 500** (server crash) the moment a config has a chassis + an
>    attached drive.
> 2. 🔴 RAM is scored **0** at validate-time by a check that contradicts the add-time check.
> 3. 🔴 **HPE/Dell boards reject every expansion card** (HBA/NIC/adapter).
> 4. 🔴 Storage **ignores the HBA / PCIe adapter** as a connection path (M.2-on-adapter never works;
>    with no chassis, nothing attaches).
> 5. 🟠 The **Quanta board rejects backplane-connected drives** through a phantom motherboard-interface
>    check.

---

## Part 1 — The 3 builds, in the corrected order (chassis before storage)

Order used: **motherboard → CPU → RAM → chassis → (HBA / adapter / NIC) → storage → validate.**
Chassis used for the dry-run: **Dell PowerEdge R740** (2U, 16× 2.5″ bays, SAS3 backplane,
SAS/SATA/NVMe) — `327e585c-8c3a-4ef5-80a3-c434df5c79a4`.

### Build A — HPE DL360 Gen9 · Build B — Dell R630 (same result)
| Step | Component | Result |
|---|---|---|
| motherboard, 2× CPU, RAM | | ✅ added |
| **chassis (R740)** | | ✅ added |
| HBA 9500-16i / AORUS adapter / HP 331i NIC | | ❌ **BLOCKED — Bug 3** (no PCIe slots) |
| 3× 2.5″ SAS/SATA drives | `319944c5`, `4d5e6f7a`, `b1a9a8a0` | ✅ **ATTACHED via chassis backplane** |
| M.2 NVMe drive `c9281a4b` | | ❌ **BLOCKED — Bug 4** (no M.2 slot; adapter ignored) |
| **validate-config** | | 🔴 **HTTP 500 — Bug 1 (crash)** |

> Key result: on HPE/Dell the drives attach **through the chassis SAS3 backplane with no HBA needed** —
> but you can't add any PCIe card, and validation then **crashes**.

### Build C — Quanta D52BQ-2U
| Step | Component | Result |
|---|---|---|
| motherboard, CPU, RAM | | ✅ added |
| **chassis (R740)** | | ✅ added |
| HBA 9500-16i / AORUS adapter / HP 331i NIC | | ✅ **all added** (Quanta exposes PCIe slots) |
| 2.5″ drives `319944c5` … | | ❌ **BLOCKED — Bug 5**: *"Storage requires SAS 12Gb/s but motherboard only supports: "* (empty) |
| M.2 NVMe drive | | ❌ **BLOCKED — Bug 4** |
| **validate-config** | | ❌ `valid:false`, RAM scored 0 — **Bug 2** (no crash, because no storage attached) |

> Mirror image of A/B: the Quanta board **takes every card** but **rejects the very drives that
> attached fine to the same chassis on DL360/R630.**

---

## Part 2 — Defects (ranked by severity)

### 🔴 Bug 1 (NEW, most severe) — `validate-config` crashes with HTTP 500 when a chassis has an attached drive
**Trigger, isolated to a minimal repro:** motherboard + chassis → validates fine (`200`). Add **one**
backplane-connected drive → `validate-config` returns **HTTP 500 "Internal server error", `data:null`.**

**Why it matters:** this is a hard server crash on the main validation endpoint — fail-closed. Any real
server that actually has drives in a chassis (i.e. every real server) cannot be validated at all.

**Root cause direction:** the crash is a PHP **fatal `Error`** (not an `Exception`), so it slips past
the `catch (Exception $e)` guards in `ServerBuilder::validateConfigurationComprehensive()`
(`:7024`) and the storage sub-methods (`:7455`, `:7513`, `:7736`) — which only catch `Exception`, not
`Error`/`TypeError`. It fires in the storage-with-chassis path (Steps 6–7:
`trackStorageBayAvailability` `:7393` / `validateStorageConnections` `:7525`), reached only when a drive
resolves to a `chassis_bay` primary path. Pinning the exact line needs the server PHP error log.
**Fix:** find the fatal (likely a null/type deref in the bay/caddy handling), fix it, and widen the
guards to `catch (\Throwable $e)` so a single bad drive can never 500 the whole endpoint.

**Reproduce:**
```bash
CFG=$(auth -d action=server-create-start -d server_name=repro -d is_virtual=1 | jq -r .data.config_uuid)
auth -d action=server-add-component -d config_uuid=$CFG -d component_type=motherboard -d component_uuid=4c8f5e1b-2b4a-4c8d-b9e7-f6d2a3c1e9b8
auth -d action=server-add-component -d config_uuid=$CFG -d component_type=chassis    -d component_uuid=<an available chassis unit>
auth -d action=server-add-component -d config_uuid=$CFG -d component_type=storage    -d component_uuid=319944c5-f35e-4298-ad75-b6afd243a31b
auth -d action=server-validate-config -d config_uuid=$CFG    # -> HTTP 500
auth -d action=server-delete-config   -d config_uuid=$CFG -d force=1
```

---

### 🔴 Bug 2 — RAM scored 0 at validate-time by a raw string compare that contradicts add-time
Add-time returns `memory_type: compatible`, `ecc_support: "ECC configuration validated"`. Validate-time
returns `category_scores.ram = 0`, `valid:false`, `ram_type_incompatible: "DDR4 memory incompatible
with motherboard supporting DDR4 ECC"`.

**Root cause:** `ComponentValidator::validateRAMTypeCompatibility()` `:471` does a raw
`in_array("DDR4", ["DDR4 ECC"])` → `false`, with **no** normalization — called from
`ServerBuilder.php:7826`, which forces `category_scores['ram'] = 0`. The codebase already has the
correct normalization (`DataNormalizationUtils::normalizeMemoryType()` strips the ECC suffix,
`:30-32`; the newer `MemoryTypeRule` uses it) — it just isn't applied on this method. **Fix:**
normalize both operands here. *(Note: finishing the ValidationPipeline migration does NOT auto-fix
this — that pipeline is add-time/slot+storage only and never touches this validate-time method.)*

---

### 🔴 Bug 3 — HPE DL360 Gen9 / Dell R630 reject **every** expansion card ("no PCIe slots — only riser slots available")
HBA → *"no free x8-compatible PCIe slot"*, adapter → *"no free x16-compatible PCIe slot"*, NIC →
*"No available PCIe slots for NIC"*. Same cards add fine on Quanta.

**Root cause:** those boards model 100% of PCIe as **riser** slots with an empty direct `pcie_slots`
list; `UnifiedSlotTracker` only credits riser slots when a separate **riser-card component** is present
(`:76-91`), and none exists in inventory → `assignSlot()` returns nothing
(`ServerBuilder.php:5004-5024`, NIC path `:2538-2542`). **Fix:** treat built-in riser slots as usable
PCIe capacity by default, or seed the riser cards these servers ship with.

---

### 🔴 Bug 4 — Storage ignores the HBA / PCIe adapter as a connection path
With **no chassis**, every one of the 16 drive models is hard-blocked at add-time
(*"No chassis bays available"* / *"No M.2 slots available"*) — **even with an LSI 9500-16i tri-mode HBA
present** whose own detail line reads *"16 ports, 0 used, 16 available"*. And **even with a chassis**,
an **M.2 drive on a PCIe M.2 adapter** is still blocked (*"No M.2 slots"*), because the enforced path
never credits the adapter.

**This is the design point you raised — and you're right.** An HBA/adapter supplies the *data path*; a
chassis bay (or on-board M.2/U.2) supplies the *physical mount*. The correct behavior (which the good
validator `StorageConnectionValidator` already implements — HBA `CHECK 3` `:91`, adapter `CHECK 4`
`:100`, "add chassis later" `:180-189`) is **defer, don't hard-block**: accept the drive, record its
form factor, and enforce "needs a bay" at **finalize** — then constrain the *chassis* choice to the
drives already present (`validateChassisAgainstExistingConfig` `:1508` already does exactly this:
*"Cannot add chassis with 3.5-inch bays - configuration has 2.5-inch storage"*).

**Root cause:** the enforced add-time path
(`ComponentValidator::validateChassisBayStorage` `:983` / `validateMotherboardM2Storage` `:1046` /
`validateGenericStorage` `:1071`) only inspects `chassis_bays` / `motherboard_m2_slots`. **Fix:** route
add-time storage through `StorageConnectionValidator` (credits HBA/adapter, defers when unconnected),
and let finalize enforce completeness.

---

### 🟠 Bug 5 — Quanta board rejects backplane-connected drives via a phantom motherboard-interface gate
On the Quanta D52BQ-2U, the same 2.5″ SAS drives that attach through the R740 backplane on DL360/R630
are rejected at add-time: *"Component … is not compatible with existing motherboard … Storage requires
SAS 12Gb/s but motherboard only supports: "* — the supported-list is **empty**.

**Two problems combine:** (a) the Quanta board spec has an **empty storage-interface list**, so it
"supports" nothing; and (b) the add-time check gates the drive on the **motherboard's** interface list
even though the drive connects through the **chassis backplane / HBA**, not the board. A backplane/HBA
drive should be gated by the *backplane/HBA* protocol, not the motherboard. **Fix:** populate the
Quanta board's storage interfaces, and skip the motherboard-interface gate when the drive's connection
path is a chassis backplane or HBA.

---

## Part 3 — Which build hits which bug
| Build | Cards (Bug 3) | Storage attach | Validate |
|---|---|---|---|
| A — DL360 Gen9 | ❌ blocked | ✅ 2.5″ via backplane; M.2 blocked (Bug 4) | 🔴 **500 crash (Bug 1)** |
| B — R630 | ❌ blocked | ✅ 2.5″ via backplane; M.2 blocked (Bug 4) | 🔴 **500 crash (Bug 1)** |
| C — Quanta D52BQ | ✅ all cards add | ❌ drives rejected (Bug 5); M.2 blocked (Bug 4) | ❌ ram=0 (Bug 2) |

**Net: still not one buildable server** — but for *different, deeper* reasons than before, which is the
point of the exercise.

---

## Part 4 — Suggested fix order
> ⚠️ Compatibility-engine hot paths; every save auto-deploys to production ~20 s later. Prove parity
> against the golden baseline (`tests/characterize_compatibility.php`) before landing. Get sign-off
> before editing.

1. **Bug 1 (500 crash)** — highest severity; a crash on the main validate endpoint. Widen the guards to
   `catch (\Throwable)` immediately (stops the 500), then fix the underlying fatal.
2. **Bug 2 (RAM normalize)** — smallest, safest, unblocks the RAM score for every config.
3. **Bug 5 (Quanta interface)** — data fix + skip the wrong gate; unblocks Quanta storage.
4. **Bug 4 (storage via HBA/adapter, defer-don't-block)** — the structural fix you described.
5. **Bug 3 (riser slots)** — unblocks HPE/Dell expansion.

---

## Appendix — API workflow & verification
Log in → `server-create-start` (→ `config_uuid`) → `server-add-component` (motherboard first, then CPU,
then chassis, then cards, then storage) → `server-validate-config` → `server-finalize-config`. Real
adds mark the unit In Use (`Status=2`) and need `serial_number` when a UUID has more than one unit;
`is_virtual=1` rehearses without touching stock.

```bash
BASE=https://ims.bdcms.bharatdatacenter.com/Ims_backend/api/api.php
TOKEN=$(curl -s -X POST "$BASE" -d action=auth-login -d username=superadmin -d "password=$IMS_PASSWORD" | jq -r .data.tokens.access_token)
auth(){ curl -s -X POST "$BASE" -H "Authorization: Bearer $TOKEN" "$@"; }
```

Findings came from driving the live API with virtual configs plus one temporary Dell R740 chassis unit
(registered, used across all three builds in the corrected order, then deleted). No probe configs
remain; the temp chassis was removed. Because the system is in active use, absolute availability counts
shift between runs from concurrent edits — the dry-runs themselves reserve nothing.
