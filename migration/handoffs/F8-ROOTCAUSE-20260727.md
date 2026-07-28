# F-8 root cause + fix — session 20 (2026-07-27)

Scope: reporting integrity only. No production DB write, no seeder, no `.env` read or
written, no flag flipped. Live flags re-probed and unchanged (`DUAL_WRITE=on`,
`STATE_MACHINE_ENABLED`/`ENGINE_MODE`/`COMMAND_LAYER_ENABLED`=`shadow`,
`READ_FROM_ROWS=sample`, `.env` mtime `2026-07-22T15:40Z`, holding ~4d05h).

## What F-8 actually was

The nineteenth session filed it as "adjacent byte-identical duplicate rows in
`engine-*.jsonl`, cause suspected but unproven". It is now proven.

One `server-add-component` request evaluates `validateComponentAddition()` **twice, by
design**:

| # | Call site | State it sees | Role |
|---|---|---|---|
| 1 | `api/handlers/server/server_api.php` (pre-transaction block) | unlocked snapshot | advisory pre-check; exists to surface `validationWarnings` |
| 2 | `core/models/server/ServerBuilder.php:835`, inside `addComponent()` | after `lockAndLoadConfigRow()` → `SELECT ... FOR UPDATE` | authoritative; the verdict `enforce` would act on |

`ShadowRunner::record()` fired in both, and because the row carried no field
distinguishing them, the two serialized byte-identically.

### How it was proven (not inferred)

`reports/shadow/command-*.jsonl` is written **once per request** — its writer sits in
`server_api.php`'s `$commandLayerMode === 'shadow'` block, which runs a single time. That
makes it an independent operation counter.

Every timestamp in `command-20260723.jsonl` — `00:19:27, 00:19:52, 00:22:20, 00:23:21,
00:23:49, 00:24:08, 00:24:09, 00:24:14, 00:24:18` — maps to **exactly one byte-identical
pair** in `engine-20260723.jsonl`. 30 engine rows ≈ 15 real operations.

## Fix

Additive and deploy-order-safe, mirroring the nineteenth session's `$subject` posture:

- `ShadowRunner::record()` gains optional 7th param `string $phase = 'authoritative'`,
  written as a top-level `phase` key. The default is `'authoritative'` because the
  pre-existing 6-arg call site *is* the authoritative one, so this file can deploy ahead
  of its callers.
- `ServerBuilder::validateComponentAddition()` gains optional 9th param
  `$shadowPhase = 'authoritative'` and forwards it.
- `server_api.php`'s advisory call site passes `'advisory'`.
- `scripts/verify/parity_report.php` excludes `phase=advisory` rows from the comparison
  and reports `advisory_rows_excluded` + `unlabeled_rows`.

Both evaluations are still recorded — a divergence *between* them is exactly the TOCTOU
drift the lock exists to catch — they are merely distinguishable now.

**Pre-fix rows cannot be retroactively decomposed.** Rows written before 2026-07-26 have
no `phase` key; within that era a duplicate and a genuine second add are indistinguishable
by construction. They are still analyzed (dropping them would discard the whole pre-fix
soak) but counted as `unlabeled` with a loud warning. This is why F-8 closure requires
fresh post-deploy traffic.

## Second inflation source, found here, never previously filed

`reports/shadow/` holds re-download artifacts — `engine-20260711 (1).jsonl`, `(2)`,
`(1) (1)` — and `parity_report.php`'s default glob matched all of them. `md5sum` confirms
byte-identity:

| Day | Copies on disk |
|---|---|
| 20260711 | 4 |
| 20260712 | 4 |
| 20260713 | 4 |
| 20260722 | 2 |
| 20260723 | 2 |

A default run was counting **~2.4x the real rows on top of F-8's 2x**. `parity_report.php`
now de-duplicates inputs by **content hash** (not name parsing, so a genuinely different
suffixed file still counts) and prints what it skipped — the default run now reports
`11 duplicate input file(s) skipped`. The duplicate files were **left on disk**; deleting
them is the owner's call.

## F-8's severity should be downgraded

F-8 never threatened GREEN/RED correctness — only the denominator. A duplicated row cannot
create a diff where none exists, and a duplicated diff is still a diff, so pass/fail was
always right. What was wrong is the *sample-size* claim. Read the nineteenth session's
"affects gate validity" as metrics/observability, not correctness.

## Also worth knowing

`engine-20260725.jsonl` (120 KB, 86 rows, all carrying `subject`) is **local test output**,
not production traffic — `config_uuid` prefix `ENGINE-SHADOW-TEST-`, tz `+02:00` vs
production's `+00:00`. It pollutes any default-glob parity run. The real production soak is
**only** `engine-20260722.jsonl` (39 rows) + `engine-20260723.jsonl` (30 rows) ≈ 35 real
operations, all from one 70-minute burst.

## Verification performed

- `php -l` clean on all four touched files.
- `parity_report.php --self-test` still PASS / exit 1 → detection is not vacuous.
- Purpose-built fixture (1 advisory + 1 authoritative for the *same* op, + 1 unlabeled):
  `operations_compared=2`, `advisory_rows_excluded=1`, `unlabeled_rows=1` — 3 rows collapse
  to 2 correctly.
- Live API health re-probed *after* the three production-file edits:
  `server-list-configs` 200, `server-debug-migration-flags` 200, valid JSON, no fatal.

## Not done / not claimed

- **The fix's effect on real traffic is unproven.** Production has had zero config
  mutations since `2026-07-23 00:24`, so no post-fix row exists yet.
- **Upload of the three production files is unconfirmed** — these edits were made by tool,
  not by a VS Code save, so `uploadOnSave` may not have fired.
- `U-V.3` `verified` → `implemented` (owns `ShadowRunner.php` +
  `validateComponentAddition()`); `U-A.1` `verified` → `implemented` (owns
  `server_api.php`'s flag-gated advisory block). Self-certification rule. Verified units
  40/55, implemented 6. P4 and P7 gates were already closed and stay closed.

---

# Part 2 — command-layer parity gate built, plus F-9 and F-10

Continued in the same session after the F-8 work above.

## F-9 — the command shadow log was structurally incapable of proving parity

Not previously filed. Three separate defects in one log:

1. **No denominator.** Both writers logged only the interesting case — add when the two
   sides disagreed, remove when the command blocked — so "the command layer agreed on N
   operations" and "nothing was exercised" were the same observation. A soak cannot be
   certified from a log that records only failures.
2. **Remove rows carried no legacy side at all.** The writer sat *inside*
   `if ($commandVerdict->blocking())`, i.e. **before** `ServerBuilder::removeComponent()`
   ran, so `legacy_blocked` was not merely absent — it was unknowable at write time, and a
   row where legacy blocked too (agreement) was indistinguishable from a real divergence.
3. Two duplicated inline writers with drifting shapes and no shared contract.

**Fixed.** New single writer `core/models/commands/CommandShadowLog.php`; both call sites
now record **every** shadow evaluation with both sides. The remove path was restructured to
mirror the add path: dry-run first (it must — legacy mutates), then legacy, then write the
row once both outcomes are known. Row shape is an additive *extension* of the old flat keys
(`legacy_blocked` / `command_blocked` / `command_failures` keep their names), so the 11
pre-existing rows stay readable without normalisation.

Both call sites require the helper defensively (`is_file()` then `class_exists()`), so if
`server_api.php` lands on the server before `CommandShadowLog.php` does, the request
degrades to "no shadow row" instead of fataling on every add- and remove-component.

**Scope note, deliberate:** `replace-component` and `transition-status` are v2-only actions
with **no legacy counterpart** (`08-api-adapters/DEPRECATION.md`; RULE_MAP.md documents
replace as zero-diffs-by-construction). They have no legacy verdict to compare against, so
their absence from this log is correct, not a gap. Command-parity is add + remove only.

## The gate report

`scripts/verify/command_parity_report.php` + `scripts/verify/expected_command_diffs.json`,
registered in `run_all.php` as `command_parity` and added to **P6's** gate_reports (mirrored
into `phase-status.json`) — P6 owns U-C.6, the enforce-soak unit whose evidence this is.

Not a copy of `parity_report.php`, because the logs differ in kind: engine rows carry a full
per-rule `results[]` array, command rows carry only `command_failures[]`, so the
"an exemption must name the rule that earned it" check becomes "is the cited rule among this
row's failures". No phase filtering is needed — the command writer runs once per request,
which is exactly what made it the independent counter that proved F-8.

Three row classes are counted but **excluded from the comparison**, so they can never
green-wash the gate: `legacy_unknown` (every pre-fix remove row), `dry_run_failed` (counted
as gate-RED, mirroring engine exceptions), `out_of_scope` (ops other than add/remove).

`expected_command_diffs.json` seeds seven entries: `dependency.blocked_removal` (audit R-1 —
the deliberate fix for the owner's stranded-component bug) plus the six add-side classes
mirrored from `expected_diffs.json`, since AddComponentCommand evaluates the *same* rule
registry. `storage.bay_capacity` is deliberately **absent**, with a note: after the 07-25
correction any bay_capacity divergence is a regression and must surface as unexplained.

### First real result

```
operations_compared 10   identical 0   expected 9   unexplained 1   dry_run_failures 0
legacy_unknown 1         RED
unexplained: 2026-07-22T21:13:34 add storage legacy=false cmd=true
             fails=storage.bay_capacity,storage.caddy_pairing
```

The single unexplained row is the **pre-07-25 bay_capacity harshness** — so the command
stream independently corroborates what the engine stream found, from a different log written
by different code. Correctly RED for a fixed defect whose fix has no post-fix traffic yet.
With `--since 2026-07-26` the window is empty and the report says so loudly:
`WARNING operations compared: 0 -- a zero-sample GREEN proves nothing was exercised`.

## F-10 — gate reports exited 0 when the database was unreachable (false GREEN)

**The most serious finding of the session.** `core/config/app.php`'s PDO catch block ended in
a bare `exit;` — exit code **0**. Under the web SAPI that is harmless (the `http_response_code(500)`
above is what the client sees), but under CLI every `scripts/verify/*_report.php` exited 0 on
a mere connection failure, so `run_all.php` printed the report GREEN and **a whole gate could
pass having executed nothing**.

Caught live, not theorised: `run_all.php --gate P2` printed

```
equivalence: GREEN (no report line found in child output)
orphan:      GREEN
ledger:      GREEN (no report line found in child output)
inventory:   GREEN (no report line found in child output)
gate exit=0
```

against a replica the configured DB user had no rights on. The `(no report line found)`
parenthetical was the only symptom, and it reads as a cosmetic quirk. Running the same report
directly showed `Database connection failed ... exit=0`.

Fixed as `exit(PHP_SAPI === 'cli' ? 1 : 0)`. Production behavior is deliberately
byte-identical — `PHP_SAPI` is `litespeed` there, never `cli` — so this cannot alter any API
response. Verified both directions: the same broken-credential run now exits 1 per report and
the gate goes RED.

**Implication worth carrying: a `run_all.php` GREEN is only meaningful if the reports
actually connected.** Prior sessions' GREENs that cite real row counts (the 2026-07-22 P2
pass reports `config_components 76/76`) did connect and stand. A GREEN with
`(no report line found in child output)` on a DB-backed report should be treated as
unproven until re-run. No unit is downgraded for this fix: `core/config/app.php` is
pre-existing application bootstrap, not any migration unit's deliverable (checked — only
handoffs reference it, as F-7's site, never a unit's implementation scope).

## Verification performed (part 2)

Substrate: XAMPP MariaDB restarted this session; `ims_compat_golden` for the unit sweep;
disposable tree `C:\tmp\ims-p2-verify-20260727` with its own `.env` for the DB-backed gate
battery, so no edit could auto-upload to production.

| Check | Result |
|---|---|
| `php -l` on every touched file | clean |
| `command_parity_report --self-test` | PASS, all 7 row classes; exit 1 by design |
| Full test sweep (40 files) | **39 pass / 1 fail** |
| The 1 failure | `tests/backfill/ledger_backfill_test.php` — pre-existing **F-7**, same signature the 07-25 session recorded, not a regression |
| `fleet_parity_sweep` | **GREEN** — 84 replays / 9 configs / 0 threw / identical=47 / expected=37 / unexplained=0, **byte-matching every prior session** |
| `run_all.php --gate P2` on the post-seeder replica | **exit 0, all four GREEN**, connection verified first, bodies non-empty (5 configs, 76 refs, 0 diffs / 0 violations / 0 orphans) |

Note on the sweep: a first pass showed 17 failures, all `Access denied ... using password: NO`
— the credentials were simply not exported. `state_machine_unit.php` uses its own `SM_TEST_DB_*`
prefix, not `GOLDEN_DB_*`; both are needed for a full green sweep. Worth recording, since two
earlier sessions lost time to this same class of mistake.

## What P2's GREEN here does and does not mean

It confirms the 2026-07-22 result reproduces **and** that today's edits did not regress that
battery. It does **not** open the gate: U-B.4's criterion is "exit 0 on a production replica",
and this replica was built from a *pre-seeder* dump with the seeder applied locally. Only a
dump exported from production *after* seeder `2026_07_22_004` satisfies it. Owner action,
unchanged.

## Still open after this session

- **Traffic.** Zero production config mutations since 2026-07-23 00:24. Every remaining
  parity question — the 7 bay_capacity rows, the last unexplained engine row `a84cc492`,
  command-layer agreement volume, F-8's fix in situ — needs post-deploy traffic. This is the
  binding constraint on the whole migration, not the calendar.
- Upload of the five production files edited today is unconfirmed (tool edits, not VS Code
  saves): `ShadowRunner.php`, `ServerBuilder.php`, `server_api.php`,
  `CommandShadowLog.php` (new), `core/config/app.php`.
- F-5 `parent_id` owner decision; F-7 `loadEnvFile()` precedence; post-seeder dump for P2.
- U-C.6 enforce soak (downstream of all the above). P8/P9/P10 not started.
- No unit promoted to `verified` this session — see the note in `phase-status.json` for why
  each candidate was ineligible.

---

# Part 3 — first post-fix traffic, and four more defects (session 21, 2026-07-27)

The owner deployed the Part 1/Part 2 fixes, generated **33 real add operations**
(2026-07-26 21:56–21:59Z, two configs) and exported a fresh dump. This part is
what that traffic revealed.

## Read this first: the log file contains two streams

`engine-20260726.jsonl` has **149 rows from two different sources**:

| lines | tz | what |
|---|---|---|
| 1–86 | `+02:00` | LOCAL test output (fleet sweep + scenario probes) |
| 87–149 | `+00:00` | real production traffic |

Local rows reached the production file because `reports/shadow/` is **inside the
auto-uploaded tree**. The reports cannot distinguish them — split by timezone
offset before analysing anything. This is the second time a local/production log
mix-up has cost time (`engine-20260725.jsonl` was the first).

## The Part 1/Part 2 fixes are confirmed working in production

- **F-8**: 33 `advisory` + 30 `authoritative` rows, **0 unlabeled**, 29 exact
  advisory/authoritative pairs. `parity_report` excluded all 33 advisory rows.
- **F-9**: 33 command rows, every one carrying both sides, `legacy_unknown=0`,
  `dry_run_failed=0`. The stream can now actually prove parity.
- **`storage.bay_capacity`**: did **not** diverge on a 2.5" drive in a 3.5"-bay
  chassis. The 07-25 fix holds under real traffic.

## Cross-hook finding: A-2 and A-8 are not improvements, they are hook artefacts

For **5 ops the two hooks disagreed about what legacy did**:

```
21:56:25 cpu       engine_legacy=false  command_legacy=true   cpu.socket_count
21:57:32 hbacard   engine_legacy=false  command_legacy=true   pcie.slot_placement
21:57:36 hbacard   engine_legacy=false  command_legacy=true   pcie.slot_placement
21:58:39 cpu       engine_legacy=false  command_legacy=true   cpu.socket_count
21:59:24 caddy     engine_legacy=false  command_legacy=true   storage.interface_path
```

The engine hook wraps only `legacyValidateComponentAddition()`; the command hook
observes the **full `addComponent()` result**. Real legacy *did* block those. So
`cpu.socket_count` (A-2) and `pcie.slot_placement` (A-8) are **not behavioural
improvements — the engine already matches what legacy really does**, and they
show up as diffs only because of where the shadow hook sits. This is the same
caveat `expected_diffs.json` already recorded for `system.singleton`.

**The command stream is the better parity oracle for adds.** Their
`expected_diffs.json` matchers were deliberately **left in place** (removing them
would turn the engine gate RED for a non-problem) but they are now known to be
artefacts, not intended divergences.

## F-11 — storage.interface_path false positive (fixed)

The only unexplained divergence class in the whole run, in **both** streams.

`StorageInterfacePathRule` blocked a SAS drive whenever no `hbacard` was present
and **never looked at the chassis**. Production case: drive `138e1181`
("SAS 12Gb/s") into chassis `4981e5a2`, whose spec declares
`backplane.supports_sas = true`, `interface: SAS3`. Legacy allowed it because its
path search grants a chassis-bay path; the engine blocked it.

This is exactly the "KNOWN GAP" the rule own docblock and
`_note_storage_interface_path` had flagged since U-R.5 — **now closed rather than
documented**.

It also **cascaded**: the rule is config-scoped, so one stale violation blocked
*every later add to that config* (3 caddies, 1 pciecard) until an HBA arrived.
Under `ENGINE_MODE=enforce` the operator would have been locked out of the config,
including from adding the component that fixes it.

Both legacy paths are now ported — a SAS-capable HBA (`protocol` contains `sas`)
**or** a chassis with `backplane.supports_sas`, subject to legacy M.2 and
pure-U.2/U.3 bypasses. Field coverage was checked first: **25/25 chassis** carry a
`backplane` object (22 SAS-capable), **16/16 hbacard** models carry `protocol`.
So the fix can neither fail open nor fail closed on a missing field.

Deliberately **not** ported: legacy `hba_ports_exhausted` block. Different
legacy error; left for a follow-up so a real occurrence surfaces as a diff.

## F-13 — the dual-write never wrote onboard NICs (fixed + repaired)

`config_components` held **zero nic rows of any kind**. `ServerBuilder` mirrors
the motherboard at `:1062`; `autoAddOnboardNICs()` then creates `nicinventory`
rows and rewrites `nic_config` **without ever calling the writer**. Every config
with an onboard NIC was `equivalence_report` RED (`a84cc492`, `cbd00521`,
`e7e50504`).

Fixed by returning per-unit identity from the handler and mirroring each onboard
NIC through `ConfigComponentWriter::afterLegacyAdd` **inside the motherboard own
transaction**. Removal mirrors via `afterLegacyRemove` *before* the motherboard
own hook, because a soft tombstone does not cascade to `parent_id` children.

**Proven end-to-end** on a replica of the current dump with
`DUAL_WRITE_ENABLED=on` — row created, parented to the motherboard row, real unit
identity, tombstoned on motherboard removal. Not inferred from reading code.

Historical rows: `scripts/backfill/repair-onboard-nic-rows.php` (NEW). It reuses
the writer instead of hand-written SQL, because a nic also needs `config_resources`
ledger rows and duplicating that logic is the drift this migration exists to
remove. `backfill.php` could **not** be used — its idempotency is per-run-state,
not per-row, so it would have duplicated rows on configs that already have them.

## F-14 — status_v2 drift on onboard NICs (fixed + seeder)

`OnboardNICHandler` wrote legacy `Status` with raw UPDATEs and never touched
`status_v2`, bypassing `StateMachine`. Attach left it stale, detach left it
`installed` while `Status` went to 1, and the INSERT never set it at all. Four
rows had drifted. Now paired in the same statement across the INSERT and both
UPDATEs; seeder `2026_07_27_003` repairs history, set-based from the `StatusMap`
invariant rather than hardcoded ids. `Flag='replaced'` rows are excluded on
purpose — their correct `status_v2` is a product decision.

## F-17 — power_consumption silently stopped being written (fixed + seeder)

**A regression introduced by this migration own audit fix A-L2.** A-L2 made a
compatibility score always get computed — but `server_configurations` has **no
`compatibility_score` column** (absent in the production dump; it exists only on
`compatibility_log` / `component_compatibility`). So
`updateConfigurationCalculatedFields()` always took the branch naming that column,
failed with `1054`, and since `power_consumption` rides in the **same statement**
it stopped being written on every add and remove. Caught and logged, API still
returned success — hence silent.

Fixed both ways so order does not matter: a cached `SHOW COLUMNS` probe keeps
power correct immediately, and seeder `2026_07_27_002` adds the column to restore
what A-L2 intended. **Worth checking:** configs touched since A-L2 may hold a
stale `power_consumption`, and the PSU-capacity rule reads it.

## F-7 is no longer cosmetic

Because the local `.env` wins over env vars, `tests/backfill/ledger_backfill_test.php`
cannot redirect its subprocess to the golden DB — **all 22 assertions fail**.
Environmental, *not* a regression: the subprocess reaches a **local** database
that merely shares the production name (`DB_HOST` is localhost), not production.

Sweep accounting corrected: earlier "39/40" enumerated only 40 test files. The
real total is **46**; this session measured **45 pass / 1 fail** (that test).

## Gate status

| gate | result |
|---|---|
| **P2** on current dump | **GREEN, exit 0** — 5 configs, 44 refs, 10 tables, 0 diffs/violations/orphans. Connection proven first. |
| P2 before tonight fixes | RED — 3 equivalence diffs + 3 inventory violations |
| P6 `command_parity` | RED, 4 unexplained, **all** `storage.interface_path` (= F-11 only) |
| engine `parity_report` | 30 compared, 24 identical, 2 expected, 4 unexplained (same 4) |

**P2 GREEN is a replica result.** It depends on two owner actions that have *not*
happened in production: seeder `2026_07_27_003`, and a run of
`repair-onboard-nic-rows.php --execute`. Do not read replica GREEN as production
GREEN.

## Units

`U-1.5` and `U-B.4` **demoted verified → implemented** — this session changed what
the dual-write writes, and U-B.4 41-hour soak described the code as it was.
Verified count **40 → 38**: the headline percentage went *down* while correctness
went *up*. That is the honest accounting.

`U-R.5` stays `implemented`, but its blocking gap is now closed, so it is a real
candidate for a separate session verify pass once post-fix traffic exists.

## What the next session needs

1. Seeders `2026_07_27_002` + `2026_07_27_003` applied.
2. `repair-onboard-nic-rows.php --execute` run in production (or a motherboard
   remove/re-add per affected config through the UI — the fixed live path now does
   the same thing).
3. **A second round of traffic, including a SAS drive into a SAS-backplane
   chassis.** The current logs are pre-fix and cannot show F-11 cleared.
4. Decisions on F-7, and on whether `reports/shadow/` should keep living inside
   the auto-uploaded tree.
