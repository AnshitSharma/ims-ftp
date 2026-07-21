# BDC IMS — Realistic Server Builds & Compatibility-Engine Defect Report

**Objective (the real one):** take servers that are **physically buildable** from the inventory we
actually have — a motherboard with its onboard NIC, an HBA, an NVMe adapter card, NVMe drives *on that
adapter*, SAS/SATA drives *behind the HBA*, plus discrete NIC/PCIe cards — try to build each one in
IMS, and **catalog every place the engine wrongly blocks a build that would work in the real world.**
Those wrong blocks are bugs. Fixing them is the goal.

Everything below was run **live against production** on 2026-07-21 using throwaway `is_virtual=1`
configs (zero stock reserved) — all four probe configs were deleted afterward and inventory was
confirmed byte-for-byte unchanged (motherboard 14, cpu 38, ram 1, storage 40, pciecard 10, hbacard 15,
nic 12; chassis/caddy/sfp 0).

> **Bottom line:** three engine defects make it **impossible to fully build any server today**, no
> matter how correct the hardware is. In order of impact: (1) storage can't connect through an HBA or
> PCIe adapter, (2) HPE/Dell boards reject every expansion card, (3) RAM is failed at validate-time by
> a check that contradicts the add-time check that just passed it. Each is root-caused to a file+line
> below, with a suggested fix.

---

## Part 1 — The realistic builds we attempted

Each of these is a loadout a hardware engineer would sign off on. The "Result" column is what the
engine actually did.

### Build A — HPE DL360 Gen9 (1U) · onboard-NIC branch
| Part | Type | Model UUID | Serial | Physically valid? | Engine result |
|---|---|---|---|---|---|
| HPE DL360 Gen9 | motherboard | `4c8f5e1b-2b4a-4c8d-b9e7-f6d2a3c1e9b8` | `2` | ✅ | **added** |
| 2× Xeon E5-2670 v3 | cpu | `6de47d5d-6649-4d09-9799-31feee56a7f3` | `35505255A0077`, `2L506050A0184` | ✅ | **added** |
| DDR4 DIMM | ram | `a2b3c4d5-e6f7-4a8b-9c0d-1e2f3a4b5c6d` | `RAM-TEMP-0002` | ✅ | added (but see Bug 3) |
| HBA 9500-16i Tri-Mode | hbacard | `d4b7202e-0c59-4557-a964-7c38c4b2ef32` | `BC9500-16I-001` | ✅ | ❌ **BLOCKED — Bug 2** |
| AORUS Gen4 NVMe adapter | pciecard | `07dc91dd-e630-4f4a-befb-272906748f36` | `1` | ✅ | ❌ **BLOCKED — Bug 2** |
| HP 331i 4-port NIC | nic | `6f8a0b2c-4d6e-4f8a-0b2c-4d6e8f0a2b4c` | `DCMISGH631X7A5` | ✅ | ❌ **BLOCKED — Bug 2** |
| NVMe + SAS drives | storage | (16 models tried) | — | ✅ | ❌ **BLOCKED — Bug 1** |

### Build B — Dell R630 (1U) · same shape, different vendor
Same story as Build A: board + 2× E5-2673 v4 (`dfbac286…`) + DIMM **add fine**; HBA, AORUS adapter and
Intel X540 NIC (`83f7ddb2…`) are **all blocked** (Bug 2); every drive is **blocked** (Bug 1).

### Build C — Quanta D52BQ-2U (2U) · the control that *should* pass
This board models its PCIe slots directly, so it does **not** hit Bug 2:
| Part | Type | Model UUID | Serial | Engine result |
|---|---|---|---|---|
| Quanta D52BQ-2U | motherboard | `c1d2e3f4-a5b6-4c7d-8e9f-0a1b2c3d4e5f` | `QTFCR290701BE` | added |
| Xeon Platinum 8173M | cpu | `3e31758b-04d4-495b-9b46-fc79102c16ff` | `4` | added |
| DDR4 DIMM | ram | `a2b3c4d5-e6f7-4a8b-9c0d-1e2f3a4b5c6d` | `RAM-TEMP-0002` | added |
| HBA 9500-16i | hbacard | `d4b7202e-0c59-4557-a964-7c38c4b2ef32` | `BC9500-16I-001` | ✅ added |
| AORUS Gen4 adapter | pciecard | `07dc91dd-e630-4f4a-befb-272906748f36` | `1` | ✅ added |
| HP 331i NIC | nic | `6f8a0b2c-4d6e-4f8a-0b2c-4d6e8f0a2b4c` | `DCMISGH631X7A5` | ✅ added |
| any drive | storage | (16 models tried) | — | ❌ **BLOCKED — Bug 1** |
| **validate-config** | | | | ❌ **`valid:false`, RAM scored 0 — Bug 3** |

Build C proves the point cleanly: a perfectly-specced Quanta server with CPU/RAM/HBA/adapter/NIC all
accepted **still cannot be finished** — no storage will attach (Bug 1), and validation zeroes the RAM
(Bug 3). **This is why all 11 servers already in the database are stuck as drafts.**

---

## Part 2 — Defects (ranked by impact)

### 🔴 Bug 1 — Storage will not connect through an HBA or PCIe adapter; only chassis bays / motherboard M.2 count
**What should happen:** a SAS/SATA drive connects behind an HBA; an NVMe/M.2 drive connects on a PCIe
NVMe adapter. That is the normal, primary way drives are attached.

**What the engine does:** every one of the 16 drive models is blocked at *add* time, even with an
LSI 9500-16i tri-mode HBA already in the config:
- 2.5"/3.5" drives → `Storage incompatible: No chassis bays available for 2.5-inch storage`
- M.2 drives → `Storage incompatible: No M.2 slots available on motherboard for M.2 storage`

The block's own `details` array proves the engine *saw* the HBA and threw the drive away anyway:
```json
"details": [
  "HBA supports SAS protocol", "HBA supports SATA protocol", "HBA supports NVMe protocol",
  "HBA: LSI 9500-16i - 16 internal ports, 0 used, 16 available",
  "No chassis bays available for 2.5-inch storage"
]
```
With **0 chassis** in inventory, this means **no drive can be added to any server, ever** — which
guts the whole "put NVMes on the adapter, SAS behind the HBA" requirement.

**Reproduce:**
```bash
CFG=$(auth -d action=server-create-start -d server_name=repro -d is_virtual=1 | jq -r .data.config_uuid)
auth -d action=server-add-component -d config_uuid=$CFG -d component_type=motherboard -d component_uuid=c1d2e3f4-a5b6-4c7d-8e9f-0a1b2c3d4e5f
auth -d action=server-add-component -d config_uuid=$CFG -d component_type=hbacard    -d component_uuid=d4b7202e-0c59-4557-a964-7c38c4b2ef32
auth -d action=server-add-component -d config_uuid=$CFG -d component_type=storage    -d component_uuid=319944c5-f35e-4298-ad75-b6afd243a31b  # -> BLOCKED
auth -d action=server-delete-config -d config_uuid=$CFG -d force=1
```

**Root cause:** the *enforced* add-time path only inspects `chassis_bays` / `motherboard_m2_slots`
and never consults the HBA or adapter:
- `core/models/components/ComponentValidator.php:983` (`validateChassisBayStorage` → "No chassis bays available")
- `core/models/components/ComponentValidator.php:1046` (`validateMotherboardM2Storage` → "No M.2 slots available")
- `core/models/components/ComponentValidator.php:1071` (`validateGenericStorage` — checks only bays/M.2/U.2)

A **more correct** validator already exists and is *not* the one gating the add:
`core/models/compatibility/StorageConnectionValidator.php:43` (`validate()`) has `CHECK 3` (HBA, line 91)
and `CHECK 4` (PCIe adapter, line 100), and for non-SAS drives merely *warns*
("Storage added but not yet connected") instead of blocking.

**Fix:** gate the add-time storage check through `StorageConnectionValidator` (or teach the
`ComponentValidator` path to count HBA ports and PCIe-adapter slots as valid connection paths). A drive
with a matching controller present must be allowed to connect to it.

---

### 🔴 Bug 2 — HPE DL360 Gen9 and Dell R630 reject **every** expansion card ("no PCIe slots — only riser slots available")
**What should happen:** a DL360 Gen9 (up to 3 PCIe 3.0 slots via risers) and an R630 (up to 3 PCIe
slots via risers) obviously accept an HBA, a NIC, or an NVMe adapter. On these 1U servers, the riser
*is* where the PCIe slots live.

**What the engine does:** on both boards, every card is blocked at add time —
- hbacard → `Cannot add hbacard: no free x8-compatible PCIe slot available on motherboard`
- pciecard → `Cannot add pciecard: no free x16-compatible PCIe slot available on motherboard`
- nic → `Failed to add component: No available PCIe slots for NIC (requires x8 slot)`

and validate-config emits `pcie_slot_warning: "Motherboard has no PCIe slots - only riser slots
available"`. The **identical cards add cleanly on the Quanta D52BQ-2U**, which models direct
`pcie_slots`.

**Root cause:** these board specs put 100% of their PCIe connectivity under a *riser* model with an
empty direct `pcie_slots` list. `UnifiedSlotTracker` only merges riser-provided slots when a separate
**riser-card component** is present in the config
(`core/models/compatibility/UnifiedSlotTracker.php:76-91`), and no riser card exists in inventory to
add — so `assignSlot()` returns nothing and the card is blocked
(`core/models/server/ServerBuilder.php:5004-5024`; NIC path `:2538-2542`). The engine demands a
riser-card component the catalog never ships, making these boards permanently un-expandable.

**Fix:** treat a motherboard's built-in riser slots as usable PCIe capacity by default (no separate
riser-card component required), **or** seed the riser cards these servers physically ship with. The
first is more correct — the slots exist on the board.

---

### 🔴 Bug 3 — RAM is failed at validate-time by a raw string compare, contradicting the add-time check that just passed it
**What should happen:** a DDR4 DIMM is compatible with a board whose memory type is "DDR4 ECC". (ECC is
a *feature* on top of the DDR generation, not a different generation. Non-ECC RAM in an ECC board runs
with ECC disabled; and server DIMMs are typically ECC anyway and just mislabeled "DDR4".)

**What the engine does — self-contradiction:**
- **Add-time says OK.** `server-add-component` returns `ram_compatibility.memory_type: "compatible"`,
  `ecc_support: "ECC configuration validated" (compatible)`, `form_factor: compatible`.
- **Validate-time says NO.** `server-validate-config` returns `category_scores.ram = 0`, `valid:false`,
  error `ram_type_incompatible: "DDR4 memory incompatible with motherboard supporting DDR4 ECC"`.

Same DIMM, same board, opposite verdicts — and the validate-time `ram=0` alone flips the whole config
to invalid, even on Build C where everything else is 100.

**Root cause:** `core/models/components/ComponentValidator.php:471` does a **raw**
`in_array($ramType, $supportedTypes)` — `in_array("DDR4", ["DDR4 ECC"])` → `false` — with **no
normalization**. It is called from `core/models/server/ServerBuilder.php:7826`, which then forces
`category_scores['ram'] = 0` (`:7835`). Two other paths get this right:
- the add-time ECC check (`ComponentValidator.php:914-920`) correctly rules non-ECC-in-ECC-system
  compatible-with-warning, and
- the newer pipeline rule `core/models/validation/rules/MemoryTypeRule.php:76-88` normalizes both sides
  via `DataNormalizationUtils::normalizeMemoryType()`, which **already strips the "ECC" suffix**
  (`core/models/shared/DataNormalizationUtils.php:30-32`, "DDR4 ECC" → "DDR4").

**Fix (small, contained):** in `validateRAMTypeCompatibility` normalize both operands with
`DataNormalizationUtils::normalizeMemoryType()` before comparing (compare the DDR generation), exactly
as `MemoryTypeRule` already does, and leave ECC to the dedicated ECC check. This unblocks the RAM score
for every server.

---

### Secondary findings (lower impact)
- **Contradictory RAM-slot display at add-time.** `ram_compatibility.slot_availability` returns
  `available_slots: 24` but `max_slots: 4`, and `can_add_more: false` despite `used_slots: 0` —
  internally inconsistent. The validate-time `resource_availability.ram_slots` is correct (max 24). Traces
  to the `?? 4` default in `ComponentValidator.php:953/969`.
- **Required-component deadlock.** validate-config lists `chassis`, `storage` and `nic` as *required*
  (missing → error). But chassis has **0** available inventory, storage can't attach without a chassis
  (Bug 1), and a discrete NIC can't be slotted on HPE/Dell (Bug 2). On a **real** (non-virtual) build
  the onboard NIC auto-materializes and satisfies the NIC requirement — but chassis + storage remain a
  hard deadlock until Bug 1 is fixed or chassis stock is registered.
- **Real blockers are hidden from the summary field.** validate-config returns the actual failures only
  in `validation.errors`; the top-level `issues`/`recommendations` fields were `null`. A caller reading
  `issues` sees nothing wrong.

---

## Part 3 — Which build hits which bug
| Build | Board slots (Bug 2) | Storage attach (Bug 1) | RAM validate (Bug 3) | Buildable end-to-end? |
|---|---|---|---|---|
| A — DL360 Gen9 | ❌ blocked | ❌ blocked | ❌ ram=0 | **No** |
| B — R630 | ❌ blocked | ❌ blocked | ❌ ram=0 | **No** |
| C — Quanta D52BQ-2U | ✅ OK | ❌ blocked | ❌ ram=0 | **No** |

---

## Part 4 — Suggested fix order
> ⚠️ Every code save auto-deploys to production ~20 s later, and these are the compatibility-engine
> hot paths. Any change here must be proven at parity against the golden baseline
> (`tests/characterize_compatibility.php`) before it lands. Get sign-off before editing.

1. **Bug 3 (RAM normalization)** — smallest, safest, highest leverage. One method in
   `ComponentValidator::validateRAMTypeCompatibility`. Unblocks the RAM score for every config.
2. **Bug 1 (storage via HBA/adapter)** — route add-time storage validation through
   `StorageConnectionValidator` (or credit HBA ports / adapter slots in the `ComponentValidator` path).
   Unblocks all drive attachment. Medium risk — touches the most-used validator.
3. **Bug 2 (riser slots)** — make built-in riser slots usable PCIe capacity by default, or seed riser
   cards. Unblocks HPE/Dell expansion. Medium risk — data + `UnifiedSlotTracker` behavior.

---

## Appendix — API workflow & verification method
Single endpoint; one action per call. Log in → `server-create-start` (→ `config_uuid`) →
`server-add-component` (motherboard first, then CPU, then the rest; carrier before what it carries) →
`server-validate-config` → `server-finalize-config`. Adding to a **real** config immediately marks the
unit In Use (`Status=2`) and requires `serial_number` when a UUID has more than one unit; `is_virtual=1`
rehearses without touching stock.

```bash
BASE=https://ims.bdcms.bharatdatacenter.com/Ims_backend/api/api.php
TOKEN=$(curl -s -X POST "$BASE" -d action=auth-login -d username=superadmin -d "password=$IMS_PASSWORD" | jq -r .data.tokens.access_token)
auth(){ curl -s -X POST "$BASE" -H "Authorization: Bearer $TOKEN" "$@"; }
```

All findings were produced by driving the live API with four `is_virtual=1` configs (storage
classification + Builds A/B/C), capturing every response, then deleting all four. Post-run inventory
matched the pre-run baseline exactly, with no leftover probe rows or configs.
