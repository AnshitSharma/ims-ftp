# U-B.4 quarantine triage — first real-fleet backfill run (replica), 2026-07-15

**Status: U-B.4 stays `blocked` — the 24h dual-write soak precondition is now MET
(flag on since ~2026-07-14, owner-attested), but the backfill itself is blocked on
production data quality. Every item below needs an owner decision or data fix.**

## What ran

- Owner supplied a fresh phpMyAdmin dump of the production DB (generated
  2026-07-14 19:05 server time; file `imsbdcmsbharatda_Ims_Production.sql` at repo
  root, replacing the older reference copy).
- Loaded into a NEW local replica DB `ims_prod_replica` (scratch `ims_compat_golden`
  untouched; backed up first to
  `C:\tmp\ims-scratch-backups\ims_compat_golden_pre_replica_20260715.sql`).
- Scratch mirrors re-synced from the real trees (real `ims-data` had gained CPU
  E5-2640 v3 — added by the owner 2026-07-14 alongside new cpuinventory rows).
- `scripts/verify/dual_write_soak_monitor.php --since-hours 30` (pre-backfill):
  equivalence/orphan/inventory RED (known pre-backfill state), ledger GREEN,
  staleness INCONCLUSIVE (zero config mutations in window).
- `backfill.php` dry-run: 5 non-virtual configs, 24 would-migrate, **50
  would-quarantine, all `ambiguous-serial`**.
- `backfill.php --execute` (replica only): run-id `run-20260714-192824-9c9f01`,
  done=0, quarantined=4 configs, error=1 config. 22 config_components + 60
  config_resources + 22 config_events rows written for the resolvable entries.
- Post-backfill gate reports: all four RED (detail below).
- Per-entry decisions reproducible via `scripts/backfill/_triage_probe.php`
  (scratch-tree-only throwaway, added this session).

## Soak evidence caveat (important)

Production has had **zero server-config mutations since 2026-04-21**
(`server_configuration_history` newest rows; dump's `config_events` is empty).
The flag has been on ≥24h, satisfying U-B.4's literal precondition, but the
dual-write hook has never actually fired in production. A controlled test
mutation (create throwaway config → add one available component → remove →
delete; then `SELECT COUNT(*) FROM config_events` must be > 0) was attempted
this session and stopped by the tooling permission layer (production write not
covered by this session's replica-only authorization) — **owner should perform
or explicitly authorize this one test before trusting the soak.**

## Quarantine census (50 entries, all `ambiguous-serial`)

| config | server_name | ram | storage | caddy | pciecard | total |
|---|---|---|---|---|---|---|
| 019eca1d | DCMISGH631X7A5 | 8 | 2 | – | – | 10 |
| 06ea5abb | R180-F34-ZB-XX | 2 | 3 | 1 | – | 6 |
| 809d10c9 | 3X8H3W2 | 10 | 5 | – | 4 | 19 |
| 9dbc63fa | QCTB4A9FCFAC84E | 8 | – | 2 | 1 | 11 |
| b01a5f51 | SGH732T6MY | 4 | – | – | – | 4 |
| **total** | | **32** | **10** | **3** | **5** | **50** |

Root cause: these JSON entries are legacy serial-less `{"uuid": ..., "quantity": 1}`
rows. The extractor (by design, U-B.2) resolves a serial-less entry only when
exactly ONE inventory row exists with that spec UUID and `ServerUUID` = this
config. In production data:
- **RAM**: raminventory has only 5 rows total fleet-wide (4 linked: 2→9dbc63fa,
  2→b01a5f51). Most configs' RAM entries have ZERO backing inventory rows;
  b01a5f51 has 4 JSON entries but only 2 physical rows (and those rows' serials
  are junk: "2", "5", "6", "8").
- **Storage**: storageinventory has ~1 row; every storage entry (spec f54497fd /
  a82df310) has zero backing rows → also the orphan report's 12 `inventory_missing`.
- **pciecard 809d10c9**: 4 identical serial-less entries AND 4 linked inventory
  rows — a 1:1 zip is plausible but the extractor never guesses (>1 candidate =
  quarantine). If the owner wants auto-zip-when-counts-match-exactly, that is a
  new documented extractor unit, not a silent change.

## Owner decisions needed (per pack: "quarantine is not a landfill")

1. **Serialize the fleet's RAM/storage/caddy/pciecard units** (preferred, fixes
   orphan report too): for each in-use physical unit, create/link an inventory row
   (real serial, `ServerUUID` = config, Status=2). Ships as a seeder once the
   owner supplies the real serial list — no session can invent serials.
2. **Or prune phantom JSON entries** where the hardware doesn't actually exist
   (e.g. if DCMISGH631X7A5 doesn't really have 8 sticks) — config-repair seeder
   per config, owner attests the true contents.
3. **pciecard auto-zip policy** (optional, only helps 809d10c9's 4 cards): approve
   a new extractor unit for exact-count zip resolution.

## Other findings from this run (not quarantine)

- **F-PSU (real error, needs decision): `b01a5f51` failed `--execute` entirely**:
  "No provider found for resource 'psu_watt' ... to attach component id 24's
  consumption to". The config has a motherboard + CPU but NO chassis, so the
  ledger has no psu_watt provider. Question for the engine owners: how should the
  ledger treat consumption on chassis-less (mid-build) configs — pending/unattached
  consumption rows, or skip-with-log? Also worth checking the live
  AddComponentCommand path for the same add-CPU-before-chassis ordering.
- **Inventory report RED**: pciecard `07dc91dd` is referenced by config 9dbc63fa
  while its inventory row is Status=1 (available) — legacy never flipped it to
  in_use. Data fix (status → 2) or removal; owner call.
- **Ledger report RED ×3** (e.g. 06ea5abb `lane_model_mismatch`, ledger_used=6 vs
  legacy_used=2): NOT triaged this session — partially-migrated state is a
  plausible cause (quarantined siblings missing from rows), so re-triage only
  after quarantines resolve; treat as real if it survives.
- **Equivalence report RED 5/5**: `only_in_json` — the direct mirror image of the
  50 quarantines; resolves when they do.

## Replica bookkeeping

- `ims_prod_replica` left in place (WITH the executed backfill rows) for continued
  triage. Rebuild from the dump file if a clean copy is needed; the run can also be
  undone with `backfill.php --rollback-run run-20260714-192824-9c9f01`.
- Scratch `.env` was temporarily pointed at the replica and has been RESTORED to
  `ims_compat_golden`.
- Production was probed read-only via the API (permission rows confirm the
  2026_07_12_001 + 2026_07_13_002 seeders were run; `COMMAND_LAYER_ENABLED` reads
  off via the v2-action 403 message). Production `.env` was never read; no
  production write occurred.
