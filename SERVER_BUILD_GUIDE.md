# BDC IMS — Real Server Build Guide

**Purpose:** a *buildable* recipe for assembling a server from your current inventory and
recreating it in the IMS so you can confirm it "works." Every step below was **verified against
the live production engine** on 2026-07-21 using throwaway *virtual* configs and temporary rows
that were all deleted afterward — **no production data was changed** by the verification.

> How to read this: Part 1 is the workflow. Part 2 is a live inventory snapshot. Part 3 gives two
> ready-to-run builds (both proven to add cleanly). Part 4 is the honest part — the hard limits
> discovered in *your* data that stop a build from becoming "fully valid," and exactly what to fix.

---

## Part 1 — How an IMS build works

Single endpoint, one action per call:

```
BASE=https://ims.bdcms.bharatdatacenter.com/Ims_backend/api/api.php
```

1. **Log in** → get a JWT (valid ~30 min), sent as `Authorization: Bearer <token>` on every later call.
2. **`server-create-start`** → returns a `config_uuid` (the draft you build into). Only `server_name` is required.
3. **`server-add-component`** (once per part) → each add is validated *at add time*. Order matters:
   add the **motherboard first**, then CPU, then everything else. Add a **carrier before the thing it carries**
   (adapter/HBA before drives).
4. **`server-validate-config`** → whole-config check (stricter than add-time).
5. **`server-finalize-config`** → locks the config.

**Two facts that matter:**

- Adding a part to a **real** (non-virtual) config **immediately marks that physical unit In Use**
  (`Status=2`) and, when a model UUID has more than one physical unit, you **must pass its
  `serial_number`** (the add fails closed otherwise). Use `is_virtual=1` to rehearse without touching stock.
- **Onboard NICs auto-materialize** when you add a motherboard to a *real* config (they are skipped in
  virtual configs). You do **not** add a NIC card for a board that has one — this is your "onboard → no NIC" rule.

### Auth snippet (used by every example)

```bash
BASE=https://ims.bdcms.bharatdatacenter.com/Ims_backend/api/api.php
TOKEN=$(curl -s -X POST "$BASE" -d action=auth-login \
  -d username=superadmin -d "password=$IMS_PASSWORD" | jq -r .data.tokens.access_token)
auth(){ curl -s -X POST "$BASE" -H "Authorization: Bearer $TOKEN" "$@"; }   # helper
```

---

## Part 2 — Live availability snapshot (2026-07-21)

| Type | Available | Usable highlights |
|---|---|---|
| motherboard | 14 | **Quanta D52BQ-2U** `c1d2e3f4…` ×2, **Quanta D52B-1U** `d2e3f4a5…` ×2, DL380 Gen10 ×3, DL360 Gen9 ×2, R630 ×5 |
| cpu | 38 | E5-2670 v3 ×12, E5-2673 v4 ×4, E5-2699 v4 ×4, E5-2680 v3 ×3, E5-2640 v3 ×4; Scalable singles: Platinum 8173M, 8168, 8163, Gold 6149, 5120, 6338 |
| ram | **1** | `a2b3c4d5…` (`RAM-TEMP-0002`) — the only free DIMM |
| pciecard | 10 | AORUS Gen4 AIC `07dc91dd…` ×6, AORUS M.2 x2 adapter `8f3a2c1d…` ×2, `e20b7772…` ×2 |
| hbacard | 15 | tri-mode 9500-16i `d4b7202e…`, 9500-8i `266cf3e8…`, 9600-16i `098d3c20…`, 9600-8i `c790040c…`, + 9400/9300/ATTO/Microchip |
| storage | 40 | 16 models (mixed SATA/SAS/NVMe) |
| chassis / caddy / sfp | **0 / 0 / 0** | none free |

---

## Part 3 — Two ready-to-build servers (verified)

Both boards below are the **only** available boards the engine will let you install add-in PCIe cards
into (see Part 4). Both have an onboard NIC, so they illustrate the two NIC branches you asked for:
**Build A relies on the onboard NIC (no NIC card); Build B adds a discrete NIC.**

Run these **for real** by omitting `is_virtual` (or `-d is_virtual=0`). Each `add` returned
`success:true` in verification.

### Build A — onboard-NIC branch · Quanta **D52BQ-2U** (2U, LGA3647)

Onboard: 1× Dedicated 1GbE LOM (auto-added) → **no NIC card**. 3 PCIe cards. Power ≈ 353 W.

| Slot | Part | type | model UUID | serial to use |
|---|---|---|---|---|
| Board | Quanta D52BQ-2U | motherboard | `c1d2e3f4-a5b6-4c7d-8e9f-0a1b2c3d4e5f` | `QTFCR290701BE` |
| CPU | Xeon Platinum 8173M | cpu | `3e31758b-04d4-495b-9b46-fc79102c16ff` | `4` |
| RAM | DDR4 DIMM | ram | `a2b3c4d5-e6f7-4a8b-9c0d-1e2f3a4b5c6d` | `RAM-TEMP-0002` |
| PCIe | GIGABYTE AORUS Gen4 AIC (NVMe carrier) | pciecard | `07dc91dd-e630-4f4a-befb-272906748f36` | `gigabyte` |
| PCIe | Broadcom HBA 9500-16i Tri-Mode | hbacard | `d4b7202e-0c59-4557-a964-7c38c4b2ef32` | `BC9500-16I-001` |
| PCIe | add-in card | pciecard | `e20b7772-bdb9-454d-941e-4e43fd1e5fba` | `BN26160154813` |
| NIC | — | *(onboard, auto-added — do not add a card)* | | |

```bash
CFG=$(auth -d action=server-create-start -d server_name="Build-A D52BQ-2U" | jq -r .data.config_uuid)

auth -d action=server-add-component -d config_uuid=$CFG -d component_type=motherboard \
     -d component_uuid=c1d2e3f4-a5b6-4c7d-8e9f-0a1b2c3d4e5f -d serial_number=QTFCR290701BE
# ^ response includes onboard_nics_added: {"count":1, ... "Dedicated 1GbE LOM"} — that's your NIC.
auth -d action=server-add-component -d config_uuid=$CFG -d component_type=cpu \
     -d component_uuid=3e31758b-04d4-495b-9b46-fc79102c16ff -d serial_number=4
auth -d action=server-add-component -d config_uuid=$CFG -d component_type=ram \
     -d component_uuid=a2b3c4d5-e6f7-4a8b-9c0d-1e2f3a4b5c6d -d serial_number=RAM-TEMP-0002
auth -d action=server-add-component -d config_uuid=$CFG -d component_type=pciecard \
     -d component_uuid=07dc91dd-e630-4f4a-befb-272906748f36 -d serial_number=gigabyte
auth -d action=server-add-component -d config_uuid=$CFG -d component_type=hbacard \
     -d component_uuid=d4b7202e-0c59-4557-a964-7c38c4b2ef32 -d serial_number=BC9500-16I-001
auth -d action=server-add-component -d config_uuid=$CFG -d component_type=pciecard \
     -d component_uuid=e20b7772-bdb9-454d-941e-4e43fd1e5fba -d serial_number=BN26160154813

auth -d action=server-get-config -d config_uuid=$CFG          # review
auth -d action=server-validate-config -d config_uuid=$CFG     # see Part 4 re: RAM/chassis/storage
```

### Build B — add-NIC branch · Quanta **D52B-1U** (1U, LGA3647)

Onboard is only a single 1GbE LOM, so we **add a 4-port data NIC** (HP 331i). Demonstrates the
add-in NIC path (the card takes a PCIe slot). 3 PCIe cards + NIC. Power ≈ 281 W.

| Slot | Part | type | model UUID | serial to use |
|---|---|---|---|---|
| Board | Quanta D52B-1U | motherboard | `d2e3f4a5-b6c7-4d8e-9f0a-1b2c3d4e5f6a` | `QTFCR2905019A` |
| CPU | Xeon Gold 5120 | cpu | `6def2015-bbf6-478d-8e48-4ae32d5443c1` | `CPU5120-TEMP-0001` |
| RAM | DDR4 DIMM | ram | `a2b3c4d5-e6f7-4a8b-9c0d-1e2f3a4b5c6d` | `RAM-TEMP-0002` |
| **NIC** | **HP Ethernet 1Gb 4-port 331i** | **nic** | **`6f8a0b2c-4d6e-4f8a-0b2c-4d6e8f0a2b4c`** | **`DCMISGH631X7A5`** |
| PCIe | GIGABYTE AORUS Gen4 AIC | pciecard | `07dc91dd-e630-4f4a-befb-272906748f36` | (next free AORUS serial, e.g. `1`) |
| PCIe | Broadcom HBA 9500-16i Tri-Mode | hbacard | `d4b7202e-0c59-4557-a964-7c38c4b2ef32` | `BC9500-16I-001` |
| PCIe | add-in card | pciecard | `e20b7772-bdb9-454d-941e-4e43fd1e5fba` | `BN26160154813` |

```bash
CFG=$(auth -d action=server-create-start -d server_name="Build-B D52B-1U" | jq -r .data.config_uuid)
auth -d action=server-add-component -d config_uuid=$CFG -d component_type=motherboard \
     -d component_uuid=d2e3f4a5-b6c7-4d8e-9f0a-1b2c3d4e5f6a -d serial_number=QTFCR2905019A
auth -d action=server-add-component -d config_uuid=$CFG -d component_type=cpu \
     -d component_uuid=6def2015-bbf6-478d-8e48-4ae32d5443c1 -d serial_number=CPU5120-TEMP-0001
auth -d action=server-add-component -d config_uuid=$CFG -d component_type=ram \
     -d component_uuid=a2b3c4d5-e6f7-4a8b-9c0d-1e2f3a4b5c6d -d serial_number=RAM-TEMP-0002
auth -d action=server-add-component -d config_uuid=$CFG -d component_type=nic \
     -d component_uuid=6f8a0b2c-4d6e-4f8a-0b2c-4d6e8f0a2b4c -d serial_number=DCMISGH631X7A5
auth -d action=server-add-component -d config_uuid=$CFG -d component_type=pciecard \
     -d component_uuid=07dc91dd-e630-4f4a-befb-272906748f36 -d serial_number=1
auth -d action=server-add-component -d config_uuid=$CFG -d component_type=hbacard \
     -d component_uuid=d4b7202e-0c59-4557-a964-7c38c4b2ef32 -d serial_number=BC9500-16I-001
auth -d action=server-add-component -d config_uuid=$CFG -d component_type=pciecard \
     -d component_uuid=e20b7772-bdb9-454d-941e-4e43fd1e5fba -d serial_number=BN26160154813
```

> **Undo a real build:** `server-remove-component` releases each unit back to Available (`Status=1`);
> `server-delete-config` removes the draft. (Removing the motherboard also cascades its onboard NIC.)

---

## Part 4 — Reality check: what blocks a *fully-valid* server (and how to fix it)

These were all confirmed live. They don't stop the two builds above from **adding** (they produce a
draft config identical in shape to every server already in your DB) — but they stop `validate-config`
from passing. That's not a quirk of this guide: **all 11 servers currently in your system are drafts
for exactly these reasons.**

1. **RAM is rejected by validation on every board.** Every DIMM's spec says **`DDR4`**, but every
   board's spec requires **`DDR4 ECC`**, so `validate-config` returns
   *"DDR4 memory incompatible with motherboard supporting DDR4 ECC"* — even for `9e1a4532…`, the DIMM
   used in all your real servers. **Fix:** correct the memory specs (label the ECC server DIMMs
   `DDR4 ECC`) or the board `memory.type` list, so the two sides match. This is the single biggest
   blocker to finalizing *any* server.

2. **Only the two Quanta boards accept add-in PCIe cards.** DL360 Gen9, DL380 Gen10 and R630 expose
   **no usable PCIe slots** to the engine (`"no free x8/x16 slot available"`) — their specs model
   expansion through riser cards that aren't in inventory. **Fix (optional):** register the riser
   cards, or add `expansion_slots.pcie_slots` to those board specs, if you want to build on HPE/Dell boards.

3. **No storage can be attached right now.** A drive needs a **chassis bay** (2.5"/3.5"/U.2) or a
   **motherboard M.2 slot**; there are **0 chassis** and no board here has M.2 slots. A tri-mode HBA
   supplies *ports* but not *bays*. Even after registering a chassis, a drive must also match the
   **motherboard's** supported interface list — e.g. a SAS drive on the Quanta board fails
   *"Storage requires SAS 12Gb/s but motherboard only supports: …"*. **Fix / real-build path:** when you
   physically build the box, register its chassis and drives, and make sure the drive interface is one
   the board spec lists. To add these parts: `chassis-add`, `caddy-add`, `storage-add`
   (params are DB columns: `UUID`, `SerialNumber`, `Status=1`).

4. **No available board lacks an onboard NIC.** Every board here (including R630 and D52B-1U) auto-adds
   an onboard NIC. So the strict "board has *no* onboard NIC → add a NIC" case has no candidate in
   current inventory; Build B instead shows adding a discrete data NIC on top of a minimal 1GbE LOM.

5. **RAM stock = 1 DIMM.** Both builds validate the memory step with a single module; register more
   DIMMs before a production build.

### To reach a server that passes `validate-config`
Register the real parts your physical server will have (**a chassis with a backplane/bays matching your
drives, ECC-labelled DIMMs, drives whose interface the board lists, caddies**), add them in the same
sequence as Part 3, then `server-validate-config` → `server-finalize-config`. Item **#1 (RAM ECC
labelling)** must be corrected in the specs first, or validation will still reject the memory.

---

## Appendix — verification method

Everything above was checked by driving the live API with `is_virtual=1` configs (no stock reserved)
and a few temporary inventory rows (chassis/RAM/caddy) that were created, tested, and **deleted**.
Post-run inventory matched the pre-run baseline exactly (motherboard 14, cpu 38, ram 1, pciecard 10,
hbacard 15, nic 12, storage 40; chassis/caddy/sfp 0), with no leftover probe rows or configs.
