# Handoff — U-B.3-VERIFY — 2026-07-11

## Current State
Independent verify pass on U-B.3 (ledger backfill second pass), per the implemented/verified split
in SESSION_PROTOCOL.md. `phase-status.json` already listed U-B.3 as `verified` before this session
(likely a stale/premature entry — the U-B.3 handoff itself says `implemented`, not `verified`); this
session actually ran the acceptance tests for the first time and confirms `verified` is now accurate.
Also drafted a new unit (U-L.4) after discovering the `ResourceCatalog::provides('cpu')` gap is more
severe than documented, and reopened the PL gate pending it.

## Completed Work
- Ran `tests/backfill/ledger_backfill_test.php` (U-B.3's own acceptance test) against a real local
  scratch DB built from the production schema dump + all 23 seeders applied in order: **17/17 PASS**
  (was previously only claimed by the implementing session, never independently re-run).
- Ran `tests/unit/resource_catalog_test.php`, `tests/unit/config_component_repository_test.php`,
  `tests/regression/nested_transaction_test.php`, `tests/regression/dual_write_test.php`,
  `tests/regression/ledger_dual_write_test.php`, `tests/regression/finalized_immutability_test.php`:
  **ALL PASS**.
- Static code review of `scripts/backfill/backfill.php`'s ledger second pass and `rollbackRun()`
  found no defects beyond what the U-B.3 handoff already documented.
- Ran `php scripts/verify/run_all.php --quick` against the same scratch DB loaded with the real
  production dump (not just synthetic fixtures — this is apparently the first time `equivalence`/
  `orphan`/`inventory` have been run `--all` against real data in this migration): **schema GREEN,
  inventory RED (3 violations), orphan RED (29 orphans), equivalence RED (7 configs with diffs)**.
  All RED findings are `only_in_json` / `inventory_missing` / `referenced_while_available` — i.e.
  pre-existing production data-quality gaps and (expectedly) zero backfilled rows yet, **not**
  regressions caused by U-B.1/U-B.2/U-B.3. Reports saved under the scratch copy's `reports/` (not
  committed; see "Files To Load" below for exact filenames if a future session wants the raw JSON).
- **Found and confirmed a more severe form of the already-flagged `ResourceCatalog::provides('cpu')`
  gap**: traced `ConfigComponentWriter::writeLegderForAdd()` (the LIVE dual-write path, not just
  backfill) and confirmed it calls `$catalog->provides($type, $specUuid)` **unconditionally, with no
  skip list** (unlike `backfill.php`'s `LEDGER_SKIP_PROVIDES`). U-L.2's own handoff already flagged
  this in its "Known Risks" ("adding a CPU or NIC will always roll back... they should not [enable
  DUAL_WRITE_ENABLED] until these gaps close") but it was not surfaced again since, and the user was
  about to begin exactly that 24h soak. Drafted `migration/06-resource-ledger/execution-packs/U-L.4.md`
  to close the **cpu** half (pcie_lane provider, mirroring `PcieLaneBudgetValidator.php:156-171`'s
  `pcie_lanes` field read). The **nic/hbacard/pciecard** half is explicitly out of U-L.4's box and
  needs its own follow-up unit — flagged prominently in the pack itself.
- Updated `migration/phase-status.json`: added `U-L.4: not_started` to PL, reclosed PL's gate (was
  incorrectly `open` given this live-path risk), added `U-B.4: blocked` (was `not_started`) since its
  own precondition — `DUAL_WRITE_ENABLED=on` for ≥24h — is unmet (`DUAL_WRITE_ENABLED` is entirely
  absent from `ims-ftp/.env`, defaulting to `off`).
- Updated `migration/migration-checklist.md`'s PL section to list U-L.4 and the DUAL_WRITE_ENABLED
  warning.

## Remaining Work
- Implement U-L.4 (cpu pcie_lane provider) — not started, pack only.
- A follow-up unit for the nic/hbacard/pciecard half of the same gap — not yet drafted as a pack.
  **DUAL_WRITE_ENABLED must not go `on` in production until both land** (live add-component calls
  for cpu/nic/hbacard/pciecard will otherwise roll back with a `CatalogException`).
- U-B.4 (fleet backfill sign-off) stays blocked until: (a) the above land, (b) DUAL_WRITE_ENABLED has
  actually soaked ≥24h in production, (c) the pre-existing data-quality issues below are triaged.

## Known Risks
- **Local environment gotchas found and worked around this session (worth knowing for the next local
  verify session on this Windows machine):**
  1. `core/config/app.php`'s `loadEnvFile()` calls `putenv()` unconditionally for every key in
     `ims-ftp/.env`, including ones with an **empty value** (e.g. `IMS_DATA_PATH=`) — this clobbers
     any externally-set env var of the same name, including ones a test subprocess tries to inject
     via `proc_open()`'s `$env` parameter. Testing against the real `ims-ftp/.env` (with production
     DB creds) is therefore unsafe for local scratch testing regardless — do not attempt it.
  2. `proc_open()` on Windows silently breaks PDO's MySQL connection if the child `$env` array omits
     `SystemRoot` (a well-known PHP-on-Windows issue: PHP's socket/DNS layer needs it even for a
     127.0.0.1 TCP connection). `tests/backfill/ledger_backfill_test.php`'s `runBackfill()` and its
     inline `runFullScan()` closure both build minimal custom `$env` arrays lacking it. This is a
     **Windows-only portability gap**, not a logic bug — the test almost certainly works unmodified
     on the Linux environment prior sessions' "no ims-data / no production dump" sandboxes implied.
     Not fixed in the real repo (out of scope for a verify pass); only patched in a disposable local
     copy to make the test runnable here. If this migration ever needs to run its test suite in CI on
     Windows, both `$env` arrays need `'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows'` added.
  3. Root MariaDB (XAMPP) required a password to be set — `app.php` throws `RuntimeException` if
     `DB_PASS` is empty/falsy, so `root` with no password (XAMPP's default) doesn't work with this
     bootstrap. Set via `ALTER USER 'root'@'localhost' IDENTIFIED BY 'scratchpw123'` on the **local**
     MariaDB instance only.
- **Local scratch environment now exists and was left running** (not torn down, since the next
  session will likely need it for U-L.4):
  - MariaDB (XAMPP, `C:\xampp\mysql\bin\mysqld.exe`) running in the background, `root` password
    `scratchpw123`.
  - Database `ims_compat_golden` on that instance: production schema dump + all 23 seeders applied in
    order (current as of this session — matches `imsbdcmsbharatda_Ims_Production.sql` +
    `database/seeders/*.sql` through `2026_07_10_002`). Contains real (if stale) production data —
    treat with the same care as production data, even though the instance itself is local-only.
  - `C:\tmp\ims-ftp-scratch\`: a full copy of `ims-ftp/` with its `.env` DB_* pointed at
    `ims_compat_golden` (see risk #1 above for why a copy was necessary) and the two Windows
    `SystemRoot` patches from risk #2. **This copy is now stale relative to the real `ims-ftp/` the
    moment any future session edits the real tree** — refresh it (`cp -r` again, reapply the two
    tiny env/SystemRoot edits) before trusting it for a future verify pass; do not blindly reuse it
    for more than one session without checking `diff -r` against the real tree first.
  - The real `ims-ftp/.env` (the one that auto-deploys) was **never modified** — all DB-pointing edits
    were made only in the `C:\tmp` copy, specifically to avoid the deploy-on-save risk.
- Real production data quality issues surfaced by `equivalence`/`orphan`/`inventory --all` (see
  "Completed Work" above) are pre-existing and unrelated to this migration's units, but U-B.4's own
  pack requires "zero unexplained quarantines" — these 29 orphans / 3 inventory violations / 7
  equivalence-diff configs should be triaged (ticketed or extractor-fixed) before a real fleet run,
  not discovered mid-run for the first time.

## Invariant Check Results
| Invariant | Result |
|---|---|
| INV-5 (fail-closed) | PASS — confirmed via `ledger_dual_write_test.php` and `ledger_backfill_test.php` Fixture B: a `CatalogException` always rolls back the whole transaction, never partially commits. |
| INV-8 (dual-write windows never fork silently) | Reports run this session show real, current diffs (expected pre-backfill) — not a violation, since P2 gate is still closed and no phase gate is being claimed open on this data. |
| INV-9 (paired seeders) | N/A — no seeder created this session. |
| INV-11 (unit box) | U-L.4 pack scoped to 2 files modified + 2 test files extended, single concept (cpu provider gap only) — see pack for full justification. |

## Acceptance Test Results
- `tests/backfill/ledger_backfill_test.php` → 17/17 PASS (scratch DB, real fixtures).
- `tests/unit/resource_catalog_test.php` → ALL PASS.
- `tests/unit/config_component_repository_test.php` → ALL PASS.
- `tests/regression/nested_transaction_test.php` → ALL PASS.
- `tests/regression/dual_write_test.php` → ALL PASS.
- `tests/regression/ledger_dual_write_test.php` → ALL PASS.
- `tests/regression/finalized_immutability_test.php` → ALL PASS.
- `php scripts/verify/run_all.php --quick` → schema GREEN; inventory/orphan/equivalence RED (see
  "Known Risks" — pre-existing data issues, not this unit's regression).

## Next Prompt To Use
"Continue the IMS migration. Read migration/00-overview/README.md, migration/ARCHITECTURAL_INVARIANTS.md,
migration/handoffs/U-B.3-VERIFY-20260711.md (this file — especially the local-environment gotchas if
testing on this same Windows machine), then execute unit U-L.4 using
migration/06-resource-ledger/execution-packs/U-L.4.md. Follow migration/00-overview/SESSION_PROTOCOL.md
exactly. After U-L.4, a follow-up unit for the nic/hbacard/pciecard half of the same
ResourceCatalog gap is still needed before DUAL_WRITE_ENABLED can safely go `on` in production — flag
it explicitly in the U-L.4 handoff if not already drafted."

## Files To Load Into Context (next session)
- migration/00-overview/README.md
- migration/ARCHITECTURAL_INVARIANTS.md
- migration/handoffs/U-B.3-VERIFY-20260711.md (this file)
- migration/06-resource-ledger/execution-packs/U-L.4.md
- core/models/config/ResourceCatalog.php
- core/models/compatibility/PcieLaneBudgetValidator.php 150-190

## Expected Context Size
~35k tokens
