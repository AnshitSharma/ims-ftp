# Handoff — U-X.1 + F-7, 2026-07-28

Read `migration/phase-status.json` → `twenty_third_session_2026_07_28` for full detail.
This file is the short version: state, traps, next move.

## State

Units `verified=36 implemented=11 blocked=1 not_started=7` of 55. **No gate is open past P1.**

- **U-X.1 → implemented.** `core/models/config/ConfigReadRouter.php` (new) +
  one routed call site in `ServerBuilder::getConfigurationDetails` +
  `tests/regression/read_router_test.php` (42 assertions). Not `verified`: implemented and
  tested in the same session.
- **F-7 → fixed.** `core/config/app.php` `loadEnvFile()` no longer overrides the real
  environment; `.env` is now defaults.
- Deployed this session (3 files): `ConfigReadRouter.php`, `ServerBuilder.php`, `app.php`.

## LIVE IN PRODUCTION — know this first

`READ_FROM_ROWS=sample` was already in `.env` and was **inert** because nothing consumed it.
The router now consumes it, so **sample mode is live and P8 step 1's 72h clock starts from
this deploy.** Safe by construction: sample runs both sides, logs divergences to
`reports/shadow/read-<Ymd>.jsonl`, and returns the **legacy** answer unconditionally.
Rollback = `READ_FROM_ROWS=off` via the SFTP panel (never `curl --append`/FTP `APPE`).

Other flags unchanged: `DUAL_WRITE=on`, `STATE_MACHINE=shadow`, `ENGINE_MODE=shadow`,
`COMMAND_LAYER=shadow`. Engine in shadow **cannot block**.

## Blocking owner action (unchanged, still #1)

Apply `database/seeders/2026_07_28_001_backfill-missing-status-v2.sql`. It is the whole of
P2's remaining precondition and P3's shadow soak cannot start until it lands. Confirm the
F-21/F-22 code is live first (ordering constraint is in the seeder header).

## Verified state at handoff

Local sweep **47/47** · `run_all --quick` all GREEN · gate **P2 exit 0** ·
`fleet_parity_sweep` GREEN (77 replays / 12 configs / identical=58 / expected=19 /
**unexplained=0**). All against a replica of the 2026-07-27 dump with the seeder applied.

## Two traps that cost time — do not repeat

1. **The sweep DESTROYS the database it runs against** (drops `config_components`,
   `motherboardinventory`, the `server_name` column). A first sweep read 42/47 with **three
   failures that were not real** — later tests dying on a substrate earlier tests demolished.
   **Reload the substrate from a dump before EVERY test file.** Never point `GOLDEN_DB_NAME`
   at a replica you still need.
2. **Reports can now be targeted by env var** (this is F-7's payoff — no more cloning the tree
   and editing the clone's `.env`):
   `DB_HOST/DB_USER/DB_PASS/DB_NAME=... php scripts/verify/inventory_report.php`.
   Test harnesses use the `GOLDEN_DB_*` prefix; some use `SM_TEST_DB_*`. Export all three
   prefixes. Substrate name must match `/scratch|golden|test/i` or
   `serial_less_unit_identity_test.php` refuses to run.

Tooling: `php` is not on PATH → `C:\xampp\php\php.exe`. MariaDB via
`C:\xampp\mysql\bin\mysql.exe --protocol=TCP`. Credentials are in the previous session's
scratchpad `.dbenv` — never point tests at the real `ims-ftp/.env`.

## What is NOT startable, and why (do not "just build" these)

- **U-X.2** — operational: ≥72h sample with an empty divergence log → `=on` → 14 days of
  archived battery runs → signed signoff. Every step is an owner action.
  **Named prerequisite this session surfaced:** nothing reads `read-*.jsonl`, so the 72h
  criterion is a manual count. Count only rows where `sapi != "cli"` (local harness runs write
  to the same file; 6 `cli` rows are already there from the test control).
- **U-D.1–U-D.4** — deletion units. They delete legacy validators/authorities that are **still
  authoritative** (engine + command layer are at `shadow`). `deadcode_report.php` does not
  exist (`available:false` in `run_all.php`, lands in U-D.1). U-D.3 also needs P8 signoff
  ≥30 days old, 30 consecutive archived GREEN equivalence runs, and a restore-tested backup —
  it is POINT OF NO RETURN (drops the JSON columns).
- **U-P.1 / U-P.2** — formalize a cron U-X.2 installs and document an as-built system that is
  not built yet.

## Open items carried forward

- Independent verify pass over **U-X.1**, and still over **U-V.2 / U-R.5** (from F-24).
- `missing_caddy` blocking error — error vs warning; and the one-caddy-vs-all-drives pair
  check (F-19).
- Real `storage` blocks for SA5212H5 / S5B-MB 1U / S5B-MB 2U (F-18).
- Run `scripts/backfill/repair-onboard-nic-rows.php` — `nicinventory` 254 still carries the
  old model-keyed onboard identity with no `ParentInventoryID`.
- F-5 `parent_id` before enforce.
- Whether `reports/shadow/` stays in the auto-uploaded tree — more pressing now that
  `read-*.jsonl` is a third stream landing there.

## Recurring bug classes in this codebase (worth re-reading before any fix)

- **Model vs unit**: a component UUID names a *model*; AssetTag/inventory id is the *unit*.
  F-1, F-22 both were this.
- **Fail-open spec gates**: an EMPTY derived list read as agreement. F-11, F-18, F-21.
- **Wrong denominator, right verdicts**: F-8 (duplicate rows), F-23 (local harness rows
  counted as production traffic).
- **Comments lie; read the code that runs.** F-20 (`bay_capacity`) and F-24
  (`interface_path`) were both rules ported from a comment rather than the executing path.
