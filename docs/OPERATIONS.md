# IMS backend — operations

**Unit:** U-P.2 (`migration/12-post-cutover`). **Written:** 2026-08-24.

How to run, gate, and deploy this system. Companion to `docs/ARCHITECTURE.md` (what the
system *is*) and `../BACKLOG.md` (what is still open).

Two rules govern everything below, and both are counter-intuitive enough to state first:

1. **"We did not check" is never "it passed."** Every runner here distinguishes GREEN / RED /
   could-not-run with a distinct exit code, because the single most expensive recurring bug in
   this codebase is a check that reported green after it stopped being able to see the thing
   it checked (findings F-10, F-11, F-18, F-21, F-23, F-24).
2. **Deployment is one-directional and partial.** Saving a file uploads it; nothing ever
   deletes; and whole directories never upload at all. §1 is not optional reading.

---

## 1. The deploy model

`ims-ftp` auto-uploads on VS Code save (`uploadOnSave`, ~8 s debounce + transfer ≈ **20 s**).
There is no staging environment and no local dev server. Test against production:
`https://ims.bdcms.bharatdatacenter.com/Ims_backend/api/api.php`.

**Never uploaded** (SFTP ignore list): `*.sql`, `*.md`, `*.txt`, `tests/`, `docs/`, `tasks/`,
`logs/`. In practice that also covers `reports/` and `migration/`, which are `.md`/`.json`
artefacts under ignored or non-deployed paths. Consequences:

- The files in this `docs/` directory, and `BACKLOG.md`, are **local-only**. Editing them
  cannot break production.
- **Seeders never deploy.** Writing the `.sql` file changes nothing (§6).
- **Tests never deploy.** They are CLI-only and need a local PHP + scratch DB (§5).

**FTP uploads but never deletes.** `autoDelete` is off and there is no delete-on-remove hook,
so every PHP file ever deployed and later removed locally is still on the server. Measured
2026-08-24: **146** PHP files locally under the deadcode scan roots vs **162** on production
— a **+16 skew**. Full write-up: `reports/deploy-skew-20260824.md`.

Why this is an operational hazard rather than clutter: the dead-code gate is a *deletion
authority* and it runs against the **deployed** tree, so its corpus is a superset of the
source of truth. An orphan that cites a manifest symbol produces a permanent unexplained RED;
worse, a stale *copy* of a file keeps showing a caller that has already been removed. Both
were checked on 2026-08-24 and are currently benign — across all 23 symbols in the deployed
manifest, all cited files exist locally, production-only files cited: 0. Benign today is not
benign by construction; the durable fix is a `deploy_skew` check in
`scripts/verify/run_all.php` (`../BACKLOG.md` §B).

Two related known asymmetries:

- `IMS-Frontend` does **not** auto-upload (`uploadOnSave:false`, `autoUpload:false`) — it
  needs an explicit upload, and a multi-pass edit there can strand a broken partial copy.
- A test artefact once reached production this way:
  `core/models/server/_ServerBuilder_unpatched_probe.php`, written for seconds by
  `tests/regression/run_serial_less_check.php`, uploaded by the watcher and never deleted. It
  made 11 of 17 deadcode symbols falsely RED. It is now in the ignore list. **Always re-run
  `server-debug-deadcode` immediately before any deletion; a local GREEN is not sufficient
  evidence.**

---

## 2. The five migration flags

Values live in `.env` on the server. Terminal values, confirmed live 2026-08-22 (see
`reports/cutover-signoff-20260822.md` §1):

| Flag | Terminal value | Date reached |
|---|---|---|
| `DUAL_WRITE_ENABLED` | `on` | not recorded |
| `STATE_MACHINE_ENABLED` | `enforce` | not recorded |
| `ENGINE_MODE` | `enforce` | not recorded |
| `COMMAND_LAYER_ENABLED` | `enforce` | 2026-08-21 |
| `READ_FROM_ROWS` | `on` | 2026-08-21 |

Three of the five promotion dates are unrecoverable from current records — a real audit gap,
not an omission of that document (`../BACKLOG.md` §B).

**Reading the live values:** `action=server-debug-migration-flags` (role-gated
admin/super_admin, mapped in `api/permission_map.php:65`). The handler reads all five with an
explicit whitelist (`api/handlers/server/server_api.php:2118-2122`). Do this before believing
any document, including this one.

**Rolling back:** set the flag down (`enforce → shadow → off`, `on → sample → off`). Instant
and lossless for behaviour; data already written by the new path stays written. Two caveats:

- Rolling `COMMAND_LAYER_ENABLED` or `ENGINE_MODE` back re-activates
  `ServerBuilder::legacyValidateComponentAddition()`, which is why U-D.2 (deleting it) and
  U-D.4 (deleting the flags) are **coupled** — deleting the legacy body forecloses the
  rollback.
- Rolling `READ_FROM_ROWS` down to `sample` is safe; `off` returns reads to the legacy JSON,
  which dual-write still maintains.

Full playbook: `migration/rollback-playbook.md` (R-UNIT, R-MIXED, R-SCHEMA).

---

## 3. The verification gate — `scripts/verify/run_all.php`

```
php scripts/verify/run_all.php --quick        # schema + inventory + orphan + equivalence
php scripts/verify/run_all.php --gate P9      # exactly the reports that phase's gate lists
```

Exit: **0** iff every *available* selected report is GREEN, **1** if any is RED, **2** on
usage/setup error (`run_all.php:24`).

### 3.1 REGISTRY

`const REGISTRY` (`run_all.php:41-111`) maps a short gate name to a report script plus an
`available` flag. Currently available: `inventory`, `orphan`, `performance`, `schema`,
`ledger`, `slot`, `equivalence`, `parity`, `command_parity`, `read`, `deadcode`,
`partial_rows`, `regression`.

Two entries carry load-bearing history:

- **`regression`** (`:110`) points at `tests/run_tests.php`. Until 2026-08-24 it was
  `available => false` while nine gate lists required it, so every one of those gates printed
  `regression: SKIPPED` and could still exit 0. The header's old claim that "a SKIPPED report
  cannot green-wash a gate that still requires it" was **false** and has been replaced with a
  dated correction (`run_all.php:9-17`). The loop `continue`s past an unavailable entry
  *without touching `$overallExit`* (`:207-210`) — that is the mechanism. **Leave an entry
  unavailable only when there is genuinely nothing to invoke.**
- **`baseline`** (`:92`) is still `available => false`, deliberately.
  `tests/characterize_compatibility.php` is a **capture** tool, not a comparison: it rewrites
  `tests/golden/compatibility_baseline.json` in place and exits 0 unconditionally. Wiring it
  would be a *worse* fail-open than SKIPPED — the gate would overwrite the baseline it is
  meant to check against and then always report GREEN. It needs a `--diff`/`--check` mode
  first (`../BACKLOG.md` §B).

Three reports are always invoked with `--since PARITY_SINCE_DEFAULT` (`:216-220`): `parity`,
`command_parity`, `read`. The shadow logs are append-only and never rotated, so without a
cutoff pre-fix rows trip the gate forever. Default is `2026-07-29` (`:174`), overridable with
`PARITY_SINCE_CUTOFF`. Running a report directly with no `--since` still scans every row —
the cutoff is `run_all.php`-only.

Child **stderr goes to a temp file, not a pipe** (`:222-232`). It used to be an unread pipe
closed after stdout was drained, so any report writing more than one pipe buffer (~4 KB) to
stderr blocked on its own write and `run_all.php` **hung with no output instead of failing**.
`deadcode_report.php` emits ~3.9 KB of stderr and `--gate P9` deadlocked on it live. A CI job
with a timeout reads a hang as infrastructure flake, not a red gate. `stream_select()` is not
a fix — it does not work on `proc_open` pipes on Windows.

Report path capture uses `.+`, not `\S+` (`:254`): this repo's own working directory contains
a space ("Github IMS").

### 3.2 GATE_REPORTS and `phase-status.json`

`const GATE_REPORTS` (`run_all.php:114-135`) is copied verbatim from each phase's
`gate_reports` array in `migration/phase-status.json`. **If you change one, change both.**
Current P9 gate: `[deadcode, partial_rows, equivalence, regression]`. `--gate P10` is
`['all']`, which expands to every registry key (`:188-190`).

`migration/phase-status.json` is the migration's state file. Its own schema line says every
session must update it as its **last** action and never edit fields of units it did not work
on. Its legend rule: a phase gate may be `open` only when all its units are `verified` **and**
all its gate reports exited 0. Unit statuses are
`not_started | in_progress | blocked | implemented | verified`.

The file is also read *by code*: `scripts/ci/inv_extract.php` resolves phase-conditional
invariant checks against it (§4.1). Treat it as an interface, not a diary.

Known inconsistency: **P1's gate reads `open` while `U-1.5` reads `implemented`** — a direct
violation of the file's own legend rule. The gate was opened before the 2026-07-27 demotion
and never re-closed (`../BACKLOG.md` §B).

### 3.3 Report output

Reports write `reports/<report>-<YYYYMMDD-HHMMSS>.json` and print a final
`<name>: GREEN|RED <path>` line, which is what `run_all.php` greps. `reports/` is not served
over HTTP (there is an `.htaccess` deny), and `scripts/.htaccess` closes the same hole for
`scripts/verify/*.json` — confirmed 403 on both the `Ims_backend` path and the domain root.

---

## 4. CI — `scripts/ci/`

There is no `.github/` and no `.gitlab-ci.yml` in this repo, so CI is two POSIX `sh` scripts
any runner (GitHub Actions, GitLab, cPanel cron, a laptop) can call in one line.

### 4.1 `invariants.sh` + `inv_extract.php`

```
sh ims-ftp/scripts/ci/invariants.sh                 # use an existing provisioned fixture
sh ims-ftp/scripts/ci/invariants.sh --rebuild-db    # DESTRUCTIVE: drop + reload the scratch DB
```

Exit: **0** GREEN, **1** RED, **2** could-not-run (bad environment, unparseable invariants
document) — never confused with 0 (`invariants.sh:20-26`).

Three sections:

1. **Every INV CHECK, extracted verbatim** from `migration/ARCHITECTURAL_INVARIANTS.md` at run
   time by `scripts/ci/inv_extract.php`. The command text is **never stored** in the CI script.
   Editing the invariants document changes what CI enforces immediately, with no code change;
   an edit into a shape the parser cannot execute turns the run **RED** rather than silently
   dropping the check. The parser is structural — no per-invariant special case, no allow-list
   of IDs (`inv_extract.php:5-20`). It handles three documented shapes: fenced `CHECK:` blocks
   with `mysql:` and shell dialects, one-line prose `CHECK: … exits 0`, and no-CHECK
   human-only invariants (`inv_extract.php:22-42`).
   - Assertions come from the document's own vocabulary — "must return nothing" / "must print
     nothing" / "must return 0 rows" → assert `empty`; anything else → reported **UNENFORCED**
     rather than quietly passed (`inv_extract.php:44-54`).
   - **Phase-conditional checks** ("after U-C.6", "after U-1.3") resolve the named unit against
     `phase-status.json`: `verified` → the check **gates**; anything else → it still runs and
     its output is shown, but as **informational** (`inv_extract.php:56-68`).
   - Exactly **one** interpolation is performed and it is stated in the header: a bare `*.php`
     path is prefixed with the PHP binary, because the document names the test, not how to
     launch it (`inv_extract.php:70-77`). Related: when `PHP_BIN` points off `PATH`, the script
     puts its directory *on* `PATH` rather than rewriting INV-8's literal `php …` command
     (`invariants.sh:82-92`) — changing the environment to suit the check is legitimate,
     changing the check to suit the environment is the drift this unit exists to prevent.
   - Manifest is `0x1F`-separated, not tab-separated, because tab is IFS whitespace and `read`
     would swallow empty fields.
2. **`run_all.php --quick`** against the scratch DB, run as a plain command not a pipeline, so
   the runner cannot mistake the formatter's success for the battery's (`invariants.sh:262-266`).
3. **Every suite in `tests/regression/` and `tests/unit/rules/`**, applying the same exclusions
   `run_tests.php` does (`_*.php`, `run_serial_less_check.php`). "Exited 0 having run nothing"
   is its own outcome and is **not** a pass (`invariants.sh:296-301`). Discovering zero suites
   exits 2 rather than printing green.

**Safety rails.** The DB must be reachable *before* anything runs, else exit 2
(`invariants.sh:157-165`). `run_all.php`'s children connect through `core/config/app.php`,
which reads `DB_*` — a *different* variable set from the suites' `GOLDEN_DB_*` — so the script
derives `DB_*` from `GOLDEN_DB_*` and **refuses to run** if `DB_NAME` does not look like a
scratch database (`*golden*`/`*scratch*`/`*test*`), unless `IMS_CI_ALLOW_NONSCRATCH=1`
(`invariants.sh:104-120`). Without that, an unset `DB_NAME` would fall back to the on-disk
`.env`, which points at **production**. The password is passed via `MYSQL_PWD` so it stays out
of `argv` and CI logs (`invariants.sh:121-125`).

Environment: `PHP_BIN`, `MYSQL_BIN`, `GOLDEN_DB_HOST/NAME/USER/PASS`, `GOLDEN_DB_PASS_FILE`,
`IMS_DATA_PATH`, `IMS_CI_REBUILD_DB`, `IMS_CI_ALLOW_NONSCRATCH` (`invariants.sh:28-45`). The
script exports `GOLDEN_DB_PASS` from `GOLDEN_DB_PASS_FILE` when the former is unset
(`invariants.sh:97-101`), which the mysql client needs. Its comment calls that "a workaround at
the CI boundary, not the fix"; that comment is now stale — the root fix landed in
`tests/regression/_scratch_db.php` on 2026-08-24 and the PHP suites no longer need it (§5.3).

### 4.2 `nightly.sh`

```
sh scripts/ci/nightly.sh            # --quick, the daily run
sh scripts/ci/nightly.sh --gate P8  # a fuller weekly battery
```

Writes **one** JSON record per UTC day to `reports/archive/battery-<YYYYMMDD>.json` plus the
invariant runner's full output at `reports/archive/invariants-<YYYYMMDD>.log`. A day that
already has a record is **overwritten**, not appended: `soak_status.php` counts *days*, and two
records for one day would inflate a streak the way F-8's duplicate rows inflated parity's
denominator.

The record carries `run_all_status` and `invariants_status` **separately**, and `"status"` is
GREEN only when both are. Before U-P.1, `"status"` meant only "the verification reports
passed", so a tree could accumulate a 30-day GREEN streak while violating INV-5.
`scripts/verify/soak_status.php` needed no change — it reads the field it always read, which
now means what it always appeared to mean.

Exit: 0 GREEN / 1 RED / 2 could-not-run. Alert-on-red: set `IMS_ALERT_HOOK` to a command; it
is invoked as `"$IMS_ALERT_HOOK" <status> <record-path>` with a one-line summary on stdin, at
most once per run, **never on a GREEN day**, and its own failure is logged without changing the
verdict. Unset, the summary goes to stderr so a plain cron entry mails it.

This is the cron U-X.2 requires, and the same archive is the 30-day evidence U-D.3 requires.
Read the accumulated archive with `php scripts/verify/soak_status.php`.

---

## 5. The test harness

### 5.1 Discovery, not lists

`tests/run_tests.php` discovers suites by globbing, never from a hand-typed list — because on
2026-07-29 the hand-typed list was found to contain two files that do not exist and omit six
that do, all six of which were exiting 255 in every environment while the sweep reported green
(`run_tests.php:4-14`).

```
php tests/run_tests.php            # every directory in SUITE_DIRS
php tests/run_tests.php --verbose  # also echo each suite's own output
```

`SUITE_DIRS` = `api`, `backfill`, `regression`, `unit` (`run_tests.php:39-44`); recursive;
`_`-prefixed helpers and `run_serial_less_check.php` are excluded (`:47`, `:59-61`).
`api` and `backfill` were added on 2026-08-24 — they had been missing since the file was
written, which made the runner commit the exact drift its own header warns about: a glob cannot
drift from the directory it globs, but it can drift from directories it was never pointed at.

**Exit contract** (`run_tests.php:135-140`): **0** iff every discovered suite ran and passed;
**1** if any failed; **3** if none failed but one or more exited 0 without executing a single
check; **2** if nothing was discovered. The final line is emitted in the
`<name>: GREEN|RED <detail>` shape `run_all.php` greps. "Ran nothing" became a non-zero exit on
2026-08-24 — until then the runner printed the warning and exited 0, so the one consumer that
reads only the exit code read "no scratch DB was reachable, nothing was proved" as GREEN.

### 5.2 Current state: 46 discovered, 45 passing, 1 deliberately RED

Discovery count **verified by glob 2026-08-24**: 46 `*_test.php` files — `regression/` 16,
`unit/` 11 + `unit/rules/` 8 + `unit/compatibility/` 3 + `unit/validation/` 4 = 26, `api/` 2,
`backfill/` 2.

The one RED is **`tests/backfill/ledger_backfill_test.php`**, and it is red on purpose. Its
last two failing assertions are:

```
fixture A: ledger_report GREEN
fixture A: slot_report GREEN
```

Both are labelled "fixture A" but each runs its report as a **full scan of the whole database**,
so they fail on unrelated *production* data:

| Report | Violation | Config |
|---|---|---|
| `ledger_report` | `lane_model_mismatch` — ledger_used **8**, legacy_used **0** (budgets agree at 40) | `4dee234b-d4ab-447a-95cd-e321313b1af8` (id 235) |
| `slot_report` | `slotless_card` — nic `component_id` **10291** has no `slot_ref` | same config |

The last recorded green runs of both reports were `ledger-20260730-112054.json` and
`slot-20260730-190430.json`. **This is drift in production data since 2026-07-30 that nothing
was watching**, because these two suites had never been discovered — and `slotless_card` is
exactly the class U-B.4's slot backfill was meant to close.

Scoping the assertions to fixture A would turn the suite green in one edit. That was **not**
done: it would retire the only check that caught the drift, and narrowing a test until it
passes is the failure family already logged four times (F-11, F-18, F-21, F-24). Two separate
defects are reported instead of reconciled away — (1) the production data defect, (2) the
assertion label/scope mismatch. Both are in `../BACKLOG.md`.

**Caveats you must carry when quoting any number here.**

- `tests/MANIFEST.md` says 45 in seven directories. It is **stale by one**: it omits
  `tests/regression/transaction_ownership_test.php`, which exists and passes. Regenerate that
  table from a real run, never from memory — the manifest's own instruction.
- The 41-suite GREEN record in `reports/regression-green-20260824.md` predates the `api`/
  `backfill` widening; the addendum records `45 discovered — 44 passed, 1 failed`.
- **"45 passed" does not mean every assertion ran.** One assertion still self-skips (`no (open
  config, available RAM) pair pre-checks blocked in this scratch DB`) — a fixture data-shape
  precondition, not a masked defect. And `tests/api/*` print per-criterion `SKIPPED` lines then
  `ALL CHECKS PASS` and exit 0 without `IMS_HTTP_HARNESS_URL`; they never emit the
  `SKIPPED: 0 check(s) run` marker the runner greps, so they count as **pass**. Their offline
  structural checks are real, but a pass on those lines **never** implies their HTTP criteria
  ran. U-A.1 must not be promoted on that signal.
- The most recent archived invariants run (`reports/archive/invariants-20260824.log`,
  2026-08-24T08:14Z) ran the narrower `regression + unit/rules` subset — 24 suites, **22
  passed, 2 failed**: `read_router_test.php` (`=on preserves component IDENTITY on all 3
  configs (2 matched)`) and `remove_command_test.php` (`the SAME removal with cascade=true does
  not block on dependency.blocked_removal`). Both failures are DB-backed assertions and both
  suites are recorded as passing in the earlier 41-green run. **That is unreconciled** — see
  `../BACKLOG.md` §C.

### 5.3 Provisioning the fixture (and the trap in the documented instruction)

Reproducible environment, per `reports/regression-green-20260824.md`:

- PHP **8.0.30** CLI (XAMPP is fine).
- MariaDB **10.4.32** on 3306, datadir built **pristine in a scratchpad**. **Never reuse
  XAMPP's own datadir** — it was already in crash-recovery on 2026-08-23, loading the dump there
  crashed mysqld mid-`ALTER` and orphaned `#sql-*.ibd` files sharing InnoDB space IDs with real
  tables, and `DROP DATABASE` on that state killed the server again. A fresh datadir loads the
  repo-root dump in ~7 s.
- `tests/golden/setup_scratch_db.sql` drops and recreates the database; the tables and data come
  separately from the repo-root production dump. Rebuilding is therefore destructive, which is
  why `invariants.sh --rebuild-db` is opt-in.
- `GOLDEN_DB_HOST=127.0.0.1`, `GOLDEN_DB_NAME=ims_compat_golden`, `GOLDEN_DB_USER=root`,
  `IMS_DATA_PATH` → the repo's `ims-data`.

**Credentials: one resolver, three env-var families — and this is newer than most documents
about it.** `tests/regression/_scratch_db.php:78-92` defines `test_db_password(string $prefix)`,
which applies one documented precedence to every family: `{PREFIX}_PASS` when set and non-empty
→ else the trimmed contents of a readable, non-blank `{PREFIX}_PASS_FILE` → else `''`, returned
never thrown, so the caller decides whether passwordless is legitimate.
`scratch_db_password()` (`:93-96`) is the `GOLDEN_DB` binding; `state_machine_unit.php:29` uses
`SM_TEST_DB` and `fixture_scenarios_real.php:34` uses `PROBE_DB`. **20 test files call it**, i.e.
every DB-backed suite.

This closes the trap that several other documents still warn about, and it closes it at the root
rather than at the CI boundary. The history is worth knowing because the failure mode was
invisible: until 2026-08-24 exactly one suite honoured `GOLDEN_DB_PASS_FILE`, and every other
suite carried a copy-pasted `getenv('GOLDEN_DB_PASS') ?: ''`. Following this project's *own*
documented fixture instruction — "put the scratch password in a file, point
`GOLDEN_DB_PASS_FILE` at it" — therefore handed nine suites an empty password, each of which
self-skipped as "scratch DB unreachable". Nine suites reported "the credential resolver stopped
looking" in the vocabulary of "there is no database".

Practical guidance today: setting **either** variable works, for all three families. Exporting
both is harmless. Note that `serial_less_unit_identity_test.php` still refuses to connect
passwordless — that refusal is the suite's own policy and the resolver deliberately does not
impose it on suites which legitimately run against a passwordless local scratch box.

Stale claims to ignore: `scripts/ci/invariants.sh:36-42` ("only
`serial_less_unit_identity_test.php` reads the file form itself"),
`migration/phase-status.json`'s `root_cause_NOT_fixed`, and
`reports/regression-green-20260824.md`'s "it is NOT done here" — all three predate the fix.

### 5.4 What is *not* in the sweep

Per `tests/MANIFEST.md`:

- **8 named legacy scripts** directly under `tests/` (`lane_authority_unit.php`,
  `memory_authority_unit.php`, `nic_sfp_authority_unit.php`, `slot_storage_authority_unit.php`,
  `storage_bay_authority_unit.php`, `serverstate_equivalence.php`,
  `getDashboardDataShapeTest.php`, `fixture_scenarios_real.php`). `tests/` is not in
  `SUITE_DIRS`; run these by hand and report the count **separately**.
- `tests/state_machine_unit.php` — DB-backed, and **must never be run against
  `ims_compat_golden`**; it targets a purpose-built state-machine fixture DB.
- `tests/characterize_compatibility.php` — the golden-master capture/diff tool, reported as
  "byte-identical to baseline" / "N drift", never as a pass/fail alongside the suites.
- `scripts/verify/fleet_parity_sweep.php` and `scripts/verify/*_report.php` — tools and gate
  reports, reported under their own conventions.

**Reporting convention.** Quote `run_tests.php`'s own final line verbatim: *"N discovered / P
passed / F failed / R ran nothing"*, plus the legacy-8 count separately if those were run.
Never a bare "N/M" without saying which list M is, and never fold "ran nothing" into "passed".

---

## 6. Seeder policy

Every DB change ships as a **new** file `database/seeders/YYYY_MM_DD_NNN_short-description.sql`:

- Never edit an existing seeder — always a new file, one file per change.
- Runnable in one go against the server DB (single paste / single `mysql` source).
- Idempotent where possible (`CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`, guarded `ALTER`s).
- Header comment: date, purpose, affected tables, related feature.
- After writing it, **show the SQL to the user**.
- INV-9: any seeder created by this migration must ship a paired
  `database/seeders/rollback/<name>_rollback.sql` in the same unit.

**Seeders do not auto-deploy and are not applied by writing them.** `*.sql` is on the SFTP
ignore list; the owner runs them manually against the server DB. So a code change that
references a new column can go live ~20 s after save while its seeder is still unapplied —
**gate every new-column reference behind a `SHOW COLUMNS` probe.**

Currently written and **not run** against production:

- `2026_08_24_001_ticket-items-component-type-enum.sql` — widens
  `ticket_items.component_type` to the canonical 11 and repairs **two live untyped rows**
  (an SFP and a riser card, both 2026-08-22). `TicketValidator.php:486-488` classifies them
  correctly; the ENUM was missing both values and MariaDB (not in STRICT mode) silently coerced
  to `''`, emitting only a Code 1265 warning nothing was reading. Tested twice against a
  restored copy of production.
- `2026_08_24_002_backfill-nic-slot-ref-config-235.sql` — repairs the one `slotless_card` row
  (§5.2), writing `pcie_3_x8`, the ledger-namespace value `SlotPlanner::plan()` produces for
  that card today. Its header documents the code defect that caused it and explicitly does
  **not** fix it.
- `2026_07_28_001_backfill-missing-status-v2.sql` — still unapplied, and the reason
  `inventory_report` is RED.

**INV-9 is currently failing.** Run verbatim, its CHECK prints **35 MISSING lines** — every
`2026_0[7-9]*` seeder from `2026_07_12_001` onward lacks a rollback file, including both
2026-08-24 seeders. `../BACKLOG.md` §B.

---

## 7. Current status snapshot

Evidence: `reports/archive/battery-20260824.json` and
`reports/archive/invariants-20260824.log`, the nightly run of 2026-08-24T08:14Z. Reproduce with
`sh scripts/ci/nightly.sh`; do not trust this section past that date.

**`invariants.sh` RESULT: RED.** 12 invariants, 14 checks extracted.

| Check | Verdict | Detail |
|---|---|---|
| INV-1/1 | **FAIL (gating)** | 3 `'quantity'` hits: `ConfigReadRouter.php:68` (a comment), `:454` (`'quantity' => 1` in the legacy output shape), `TargetStateBuilder.php:295` (reads legacy JSON quantity in the fallback path) |
| INV-1/2 | PASS | SQL: no duplicate `(inventory_type, inventory_id)` |
| INV-2/1 | MANUAL | unresolved `<base>` placeholder — not runnable verbatim |
| INV-3/1 | info-RED | conditioned on U-C.6 (`in_progress`); 16 files still contain `beginTransaction` |
| INV-4/1, INV-5/1, INV-5/2, INV-6/1, INV-7/1 | PASS | |
| INV-8/1 | **FAIL (gating)** | `equivalence_report --all` RED |
| INV-9/1 | **FAIL (gating)** | 35 seeders with no paired rollback |
| INV-10, INV-11, INV-12 | MANUAL | no CHECK block — human rules |

**`run_all.php --quick`: RED.** `schema` GREEN, `orphan` GREEN, `inventory` **RED**,
`equivalence` **RED**.

Live report contents, same batch:

- `equivalence` — 3 configs scanned, **1 diff**: chassis `4981e5a2…` present only in JSON for
  config `2c7f2dfb-4cc3-4ba9-ae6d-341b5577b556`.
- `ledger` — **1 violation**, `lane_model_mismatch` on config 235 (see §5.2).
- `slot` — **1 violation**, `slotless_card` on config 235.
- `inventory` — **33 violations**: 28 `status_v2_legacy_mismatch`, 4 `installed_without_server`
  (sfp `b6ab72fc…`, four serials, Status=2 with NULL ServerUUID), 1 `referenced_while_available`
  (the same chassis as the equivalence diff).
- `partial_rows` — GREEN. 4 configs seen, 3 measured, all COMPLETE (220, 221, 235); 239 excluded
  (virtual + sandbox).
- `deadcode` — RED. 26 manifest symbols, **14 RED**, 145 PHP files scanned locally, lint clean.

Interpretation: none of the four REDs is a code regression introduced by the verification work.
`inventory` is RED because seeder `2026_07_28_001` was never run; `ledger`/`slot` are RED on one
real production data defect; `equivalence` is RED on one chassis row that exists in JSON and not
in rows. All four are owner actions in `../BACKLOG.md` §B.

---

## 8. Runbooks

### 8.1 Run a phase gate

```
export GOLDEN_DB_HOST=127.0.0.1 GOLDEN_DB_NAME=ims_compat_golden GOLDEN_DB_USER=root
export GOLDEN_DB_PASS=... GOLDEN_DB_PASS_FILE=/path/to/pass   # BOTH — see §5.3
export IMS_DATA_PATH=/path/to/ims-data
php scripts/verify/run_all.php --gate P9 ; echo "exit=$?"
```

Read the exit code, not the prose. `2` means the gate did not run.

### 8.2 Full local battery

```
sh scripts/ci/nightly.sh                       # writes reports/archive/battery-<day>.json
php scripts/verify/soak_status.php             # accumulated GREEN-day streak
```

### 8.3 Before any code deletion

1. Re-run `server-debug-deadcode` against the **deployed** tree (role-gated action). A local
   GREEN is not sufficient evidence — production carries 16 PHP files the local tree does not.
2. Confirm the manifest you are gating against is the manifest that is deployed. As of
   2026-08-24 the local manifest has 26 symbols and the deployed scan reported 23; the repaired
   manifest had not uploaded. The four newly-GREEN subgraph symbols therefore cannot be
   gate-confirmed production-side.
3. A **GREEN cascade symbol is not deletable on its own**: `extractPCIeSlotSize` reads GREEN
   only because its sole same-file caller (`assignComponentSlot`) is itself a manifest target —
   and `assignComponentSlot` is RED, because live `addComponent()` calls it. Never delete a
   GREEN cascade symbol without confirming its parent target is also GREEN.
4. A symbol that is the **only writer** of persisted state other code reads is not deletable on
   a name-reference count. The gate cannot see that; see
   `migration/10-cleanup/FINDING-20260824-replaceOnboardNIC-not-superseded.md` for the worked
   example.

### 8.4 Maintenance-mode hardware swap

The target design's sanctioned path (`deployed → maintenance → deployed`, with re-validation on
exit) is **not operable today**. `StateGuard` already admits `maintenance` as a mutable status
(`core/models/state/StateGuard.php:27`) and `StatusMap` maps it (`:53`), but
`TransitionStatusCommand` is scoped to finalize-only transitions
(`TransitionStatusCommand.php:14-27`), there is no API action that requests an arbitrary
transition target other than `server-transition-status`, and the legacy message the guard emits
says so out loud: *"Move it to maintenance (not yet available) or unfinalize via an
administrator"* (`StateGuard.php:100-101`).

Until a unit lands: a hardware swap on a finalized configuration is an **owner/DBA action**, not
a supported API flow. Do not work around the guard by editing `configuration_status` directly —
that desynchronises `status_v2`, `revision` and `config_events`, and INV-6's check will report
it. Tracked in `../BACKLOG.md` §C.

### 8.5 Rolling back

`migration/rollback-playbook.md`. Order of preference: flag down (§2, instant) → `git revert`
(R-UNIT; note R-MIXED for commit `2c8ab2f`, which mixes migration and non-migration hunks in
shared files) → restore from backup (R-SCHEMA, last resort). Post-U-D.3 there is no rollback,
only roll-forward.
