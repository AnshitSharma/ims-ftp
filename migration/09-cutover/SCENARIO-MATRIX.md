# Scenario matrix — exercising the 13 unobserved rules

Written 2026-07-30. Purpose: replace *soak-by-volume* with *targeted coverage*, per the
compressed-soak variant in `CUTOVER-RUNBOOK.md:19`.

## Why this exists

Across **2,688 shadow rows spanning the entire migration**, only **9 of 22 rules** have
ever been observed:

```
storage.caddy_pairing 57   dependency.blocked_removal 33   memory.downclock 29
pcie.lane_budget      16   storage.interface_path      5   cpu.requires_board 2
cpu.socket_count       2   pcie.slot_placement         2   storage.bay_capacity 1
```

Volume is not the constraint — **input diversity is**. Another 10,000 operations of the
same shape yields the same 9 rules. Waiting more calendar days on an idle fleet yields
them too. Only deliberately constructed configurations reach the other 13.

## Two hard rules for generating this traffic

1. **HTTP only, never CLI.** `command_parity_report` and `read_report` both discard
   `sapi=cli` rows (F-23) — harness output is not production evidence. Drive every
   scenario through `api.php`.
2. **At least one finalize must be one legacy ALLOWS.** A finalize the legacy pre-check
   rejected proves nothing about `COMMAND_LAYER=enforce`, which deletes that pre-check
   (F-32). Coverage wants rules to *fail*; the gate wants one clean *success*. Do both.

## Inventory reality (measured 2026-07-30 via `server-get-available-components`)

| Type | Available | Notes |
|---|---|---|
| cpu | 40 units / 17 models | sockets vary widely: LGA2011-3, LGA3647, AM4, LGA1700, SP3, SP5 |
| ram | 50 units / 9 models | **all DDR4, all ECC, all DIMM 288-pin** — no variety at all |
| storage | 42 units / 15 models | 2.5-inch ×10, **M.2 2280 ×3**, 2.5-inch U.2 ×2 |
| motherboard | 12 units / 6 models | sockets LGA3647 ×8, FCLGA3647, FCLGA2011-3, LGA2011-3 |
| nic | 14 units | |
| pciecard / hbacard | 26 / 15 | |
| **chassis** | **0** | all consumed by configs |
| **sfp** | **0** | none in inventory at all |
| caddy | 2 | 2.5-inch only |

`{type}-list` silently ignores `status=1`; only `server-get-available-components`
reports real availability.

---

## A. Reachable now — no new inventory (7 rules)

| # | Rule | Scenario | Op |
|---|---|---|---|
| A1 | `system.required_set` | Build a config with chassis+mobo+cpu+ram but **no storage and no nic**, then finalize. | finalize |
| A2 | `system.singleton` | Add a **second motherboard** to a config that already has one (12 units available). | add |
| A3 | `cpu.socket_match` | Add an **AM4 or LGA1700** CPU to an **LGA3647** board. | add |
| A4 | `cpu.mixed_models` | On a **2-socket** board, seat one LGA2011-3 and one LGA3647 CPU, then validate. Use a dual-socket board or `cpu.socket_count` blocks the second add first. | validate |
| A5 | `memory.slot_count` | Add RAM past the board's DIMM capacity. **Pick the lowest-slot-count board available** — on a 24-slot board this needs 25 adds. | add |
| A6 | `storage.m2_capacity` | Add **2–3 M.2 2280** drives (3 available) to a board with fewer `m2_slot` providers. | add |
| A7 | `system.inventory_state` | Put a component into a non-deployable state (`Status=0` / `status_v2` failed\|retired) while it sits in a config, then validate/finalize. | finalize |

Plus, mandatory for the gate itself:

| # | Purpose | Scenario |
|---|---|---|
| A0 | **F-32 gate evidence** | One coherent build that finalizes cleanly (`legacy_blocked=false`). Already satisfied on 2026-07-30 by `cacfeeb1`; re-do it if the window is reset. |

## B. Not reachable without creating inventory (6 rules)

These cannot be triggered by any combination of hardware you currently own.

| # | Rule | Blocker | To reach it |
|---|---|---|---|
| B1 | `memory.ecc` | Every available RAM model is ECC | Add a **non-ECC** RAM unit + a board/CPU without ECC support |
| B2 | `memory.type` | Every available RAM model is DDR4 | Add a **DDR3 or DDR5** RAM unit |
| B3 | `memory.form_factor` | Every available RAM model is DIMM 288-pin | Add a **SODIMM/UDIMM** unit |
| B4 | `net.sfp_port` | **Zero SFP units in inventory** | Add SFP units + an add-on NIC with a declared `port_type` |
| B5 | `system.psu_capacity` | **Zero chassis available**; needs draw > 85% of rated PSU | Free/add a chassis with a PSU spec, then load it with high-TDP CPUs + cards |
| B6 | `net.nic_requirements` | **Unreachable from data**: all 45 NIC specs in `ims-data/nic/nic-level-3.json` declare `port_type`, and the rule only fires when ports are declared *without* one | Would require editing a NIC spec. Matches the P5 checklist note calling this rule an "honestly-scoped placeholder" — recommend accepting the gap rather than inventing data |

**Decision required.** B1–B5 need `{type}-add` rows for hardware not physically
owned (B6 needs a spec edit and should simply be accepted). Options, in order of preference:

1. **Create MIG-prefixed units, exercise, then retire them** (`Status=0`) so they never
   enter a real build. Precedented — MIG- synthetic units already exist from earlier
   migration work.
2. **Accept the gap** and promote with 5 rules unexercised — they become authoritative
   over live inventory with zero evidence they agree with legacy. This is the honest
   residual risk of the compressed soak, and it is *not* reduced by any amount of time
   or traffic.

Do **not** fabricate these as permanent inventory: an IMS row asserts the hardware
exists, and a phantom DDR3 module will outlive the migration.

## Execution notes

- Run the battery after the matrix, not per-scenario:
  `php scripts/verify/command_parity_report.php --since <today>`
- Expect **divergences** — that is the point. Each one either maps to an audit finding in
  `expected_command_diffs.json` or is a real defect to fix before enforce.
- A rule appearing in `rule_coverage` means it *failed* at least once. A rule that ran and
  passed is invisible — so absence of a rule here is not proof it was never evaluated,
  only that it never blocked. Coverage is a floor, not a ceiling.
- Sync the server's shadow log before every report run; the report reads local
  `reports/shadow/*.jsonl`, not the server:
  `action=server-debug-shadow-log&stream=command&date=YYYYMMDD&limit=500`
