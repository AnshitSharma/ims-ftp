# F-27 — the read shadow log had no denominator · 2026-07-29

**Session scope:** root-cause `READ_FROM_ROWS=sample`'s zero production rows.
**Flags: UNCHANGED.** `DUAL_WRITE=on` · `STATE_MACHINE=enforce` · `ENGINE_MODE=enforce` ·
`COMMAND_LAYER=shadow` · `READ_FROM_ROWS=sample`.
**No DB change → no seeder.** Legacy code untouched. Production verified healthy after deploy.

---

## 1. The finding

`ConfigReadRouter` sample mode logged **divergences only**. So the artifact produced by
*"every read agreed"* and the artifact produced by *"no read ever reached the router"*
were identical — an absent file.

U-X.2's acceptance criterion was written against that artifact, verbatim:

> READ_FROM_ROWS=sample, ≥72h, divergence log must stay empty (any row ⇒ fix unit, restart clock).

**A router that never executes satisfies it perfectly.** The criterion could not fail.

This is the third instance of one shape in this migration:

| Finding | The thing that could not fail |
|---|---|
| F-10 | gate reports exited 0 having run nothing |
| F-8 / F-23 | a ratio whose denominator was never established |
| **F-27** | a log that records only problems |

### Fix

Every outcome now carries a `kind`:

| kind | meaning |
|---|---|
| `compared` | both sides ran and agreed — **the denominator** |
| `divergence` | the stores disagree about configuration membership — always gate-RED |
| `skipped_virtual` | virtual config, legitimately no rows; proves the router ran, is *not* a comparison |
| *(absent)* | pre-2026-07-29 row → **read as `divergence`**, never as a comparison |

That last rule matters: history must not manufacture a denominator it never measured.
Same principle as `dry_run_error` in F-26.

### Proof — first production rows the stream has ever held

```
2026-07-28T22:21:17+00:00  sapi=litespeed  kind=compared  1e76c9ef…  legacy_count=7  rows_count=7
2026-07-28T22:21:17+00:00  sapi=litespeed  kind=compared  2c7f2dfb…  legacy_count=7  rows_count=7
```

The two stores agree on both live configs. **That fact was previously unrecordable.**
Count stood at 4 production `compared` rows by session end.

---

## 2. `scripts/verify/read_report.php` (NEW)

Gates `reports/shadow/read-*.jsonl`. Registered in `run_all.php` REGISTRY and added to
**P8's `gate_reports`** (mirrored into `phase-status.json`). Takes the same standing
`--since` cutoff as the other two shadow reports.

GREEN requires **all four**:
- 0 divergences
- 0 unrecognised kinds *(a writer newer than the reader — do not interpret that window)*
- **> 0 production comparisons**
- observed window ≥ `--min-hours` (default **72**, U-X.2's soak length)

`--self-test`: 13 checks, PASS (exits 1 by design, like its two siblings).

**No expected-diffs file, deliberately.** `parity_report` and `command_parity_report` read one
because legacy and the engine are entitled to disagree about a *verdict* where an audit finding
says legacy is wrong. Two stores disagreeing about *which components a configuration contains*
is never that — one of them is simply wrong about the hardware. The three shape differences `=on`
cannot reproduce (storage `connection`, scalar-column `added_at`, aggregated `quantity`) are
excluded at the source by `canonicalTuple()`, which is what makes a clean window meaningful
about **identity** and silent about shape.

---

## 3. Root cause of the contaminated production log

`reports/` was **not in the SFTP ignore list**.

The production `read-20260728.jsonl` held 6 rows — every one `sapi=cli`, every one stamped
**`+02:00`** while production runs **UTC**. They were written by a local tree and carried up
on save. Under U-X.2's original wording *those rows alone would have restarted the 72h clock.*

Fixed: `reports` + `reports/**` added to `ims-ftp/.vscode/sftp.json`. That file is itself inside
`.vscode/**` (already ignored), so editing it deploys nothing.

> **Diagnostic:** local PHP CLI is `Europe/Berlin`; production is UTC. **A non-UTC offset in a
> shadow row means it is not production traffic** — independent of the `sapi` field.

---

## 4. The test suite was red and the sweep could not see it

**Every prior session's "all N tests pass" was measured against a hand-typed list of 11 names —
two of which named files that do not exist.** `tests/` holds **37** suites. Ten were exiting 255
with uncaught `PDOException`s.

Two opposite defects, both fixed:

1. **Reachable but stale replica** — sailed past every connection guard, then died mid-fixture
   (`Unknown column 'server_name'`, `Table config_components doesn't exist`). Exit 255, every
   result printed above it lost, and any gate shelling these out reads RED for a reason that has
   nothing to do with the code.
2. **Absent DB** — four suites printed `ALL CHECKS PASS` and exited 0 having executed no DB
   assertion at all. *Cannot-run* and *ran-and-agreed* produced the same output.

**Fix:** `scratch_db_schema_gap()` + `scratch_db_or_skip()` in `tests/regression/_scratch_db.php`,
wired into all 12 DB-backed suites. A suite that proves nothing now prints
`SKIPPED: 0 check(s) run — this suite proved NOTHING in this environment`.

`serial_less_unit_identity_test`'s passwordless-connection **refusal is unchanged** — only its
exit code is, so a deliberate safety guard is no longer indistinguishable from a failure.

### `tests/run_tests.php` (NEW) — discovery, not a list

```
php tests/run_tests.php            # regression + unit
php tests/run_tests.php --verbose
```

Counts **passed / failed / ran-nothing** separately and warns loudly on the third. A glob cannot
drift from the directory it globs; a typed list can, and did.

> This was the wrong-denominator error committed **inside the verification step itself** — the
> same week it was being found everywhere else.

---

## 5. All three shadow readers silently dropped undecodable lines

A copy of the production log pulled through PowerShell 5.1 picked up a UTF-8 BOM;
`json_decode` returned null on line 1; **every reader skipped it without a word** and the row
count went 6 → 5 with nothing said.

Now in `parity_report`, `command_parity_report` and `read_report`: undecodable lines are
**counted and warned about**, and a leading BOM is recovered. Verified against a fixture with
both a BOM'd line (recovered) and a genuinely broken line (counted).

> When pulling logs, write BOM-free:
> `[System.IO.File]::WriteAllLines($path, $lines, (New-Object System.Text.UTF8Encoding($false)))`
> — `Set-Content -Encoding utf8` emits a BOM on PS 5.1.

---

## 6. Files changed

**Core (deployed)**
- `core/models/config/ConfigReadRouter.php` — `kind` on every row; agreement and virtual skips recorded

**Verification**
- `scripts/verify/read_report.php` — NEW
- `scripts/verify/run_all.php` — `read` registered; P8 gate; `--since` passthrough
- `scripts/verify/parity_report.php`, `scripts/verify/command_parity_report.php` — undecodable-line counting + BOM recovery

**Tests (never deployed)**
- `tests/run_tests.php` — NEW, discovery-based runner
- `tests/regression/_scratch_db.php` — `scratch_db_schema_gap()`, `scratch_db_or_skip()`
- `tests/regression/read_router_test.php` — F-27 structural + end-to-end pins; kind-aware counting; fatal → skip
- `tests/regression/{add,remove,replace,finalize}_command_test.php`, `{dual_write,fail_closed,finalized_immutability,ledger_dual_write,nested_transaction,state_guard,serial_less_unit_identity}_test.php`
- `tests/unit/{config_component_repository,engine_shadow,target_state}_test.php`

**Docs / config**
- `migration/09-cutover/execution-packs/U-X.2.md` — criterion 1 amended
- `migration/phase-status.json` — P8 `gate_reports` gains `read`; session block added
- `.vscode/sftp.json` — `reports` + `reports/**` ignored

---

## 7. Verification performed

| Check | Result |
|---|---|
| `php tests/run_tests.php` (scratch DB **live**) | 37 discovered · **28 passed · 0 failed** · 9 ran nothing |
| `php tests/run_tests.php` (scratch DB **unreachable**) | 37 discovered · 27 passed · **0 failed** · 10 ran nothing |
| `parity_report --self-test` | PASS |
| `command_parity_report --self-test` | PASS (13 row classes) |
| `read_report --self-test` | PASS (13 checks) |
| PHP lint, all changed files | clean |
| JSON validity (4 files) | OK |
| Production `server-get-config` × 2 after deploy | `success=true` |
| Production flags | unchanged (re-read from `server-debug-migration-flags`) |

The identical result with the DB reachable and unreachable **is the point** — that equivalence
is what was broken.

---

## 8. State for the next session

### Blocked on the owner — highest leverage
**Seeder `database/seeders/2026_07_28_001_backfill-missing-status-v2.sql` still awaits manual
application.** It is the whole of P2's remaining precondition, and **P3's shadow soak cannot
start until it lands**. On the last measured dump, 8 of 12 configurations had `status_v2 IS NULL`
— *including all 5 physical ones* — and `StateMachine::assertConfigTransition()` fails closed on
NULL. Idempotent; its code preconditions (F-21, F-22) are live; it ends with verification queries
that should all return 0.

### Open work
1. **`READ_FROM_ROWS` soak has started** (4 production comparisons). Needs ≥72h + >0 comparisons;
   `read_report.php` can now certify it. Re-run `php scripts/verify/run_all.php --gate P8`.
2. **Rebuild `ims_compat_golden`** — it predates P2 (10 tables, no `config_components`), so 9
   suites can only skip. This is the single highest-value local unblock. Credentials + mysqld
   start procedure below.
3. **U-C.6 unchanged** — `COMMAND_LAYER=enforce` still needs post-cutoff finalize traffic. Note
   the standing cutoff `2026-07-29` is **UTC**, and the server clock was still `2026-07-28T22:26Z`
   at session end; the local machine is IST, ~5h30m ahead.
4. **`server-debug-shadow-log` is TEMPORARY** — retire with the other `debug-*` soak actions.

### Environment notes
- PHP: `C:\xampp\php\php.exe` (8.2; production is 7.4+ — version-sensitive results don't transfer).
- **mysqld was NOT left running** (background task stopped at session end). Restart with the
  **Bash tool's background mode** — PowerShell `Start-Process` did not survive the turn:
  `"/c/xampp/mysql/bin/mysqld.exe" --standalone` · then `GOLDEN_DB_PASS=<see U-B.3-VERIFY-20260711.md §risk 3>`.
- Never point tests at `ims-ftp/.env`.
