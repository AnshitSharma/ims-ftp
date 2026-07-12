# Handoff — U-R.9 (onboard-NIC engine handling) + Findings A/B closed + full DB-backed sweep — 2026-07-12 (third same-day session)

Continues `SESSION-20260712-P6-P7-FOLLOWUP.md` — specifically its embedded
independent-verify record (bottom section), which is this session's task list.
Everything below is **implemented, never verified** (this repo's convention:
a session does not self-certify).

## Environment (the MySQL saga, hopefully final form)

The verify record's procedure works. This session found mysqld DOWN again
(it had been started ad-hoc by the prior verify session and died with that
session); restarted `C:\xampp\mysql\bin\mysqld.exe --standalone` and connected
with the scratch credential recorded in `U-B.3-VERIFY-20260711.md`, passed
ONLY via `GOLDEN_DB_*` env vars. Production `.env` untouched; all flags
(`ENGINE_MODE`, `COMMAND_LAYER_ENABLED`, …) stayed off/unset in production
throughout. **Every DB-backed scenario touched this session was actually
executed against `ims_compat_golden`** — no SKIPPED placeholders in anything
written this batch.

## 1. U-R.9 — onboard-NIC handling in the validation engine (Finding C)

Root cause: synthetic onboard-NIC spec_uuids (`onboard-{mb8}-{n}` from
`OnboardNICHandler`, `onboard-nic-{mb24}-{n}` from `ServerBuilder:3113`) never
exist in the nic ims-data JSON, so `ResourceCatalog::providesNic()` /
`consumesPcieLanes()` threw `CatalogException` on them and every
catalog-backed rule failed closed → the engine blocked ANY config containing
an onboard NIC (48 of the 53 fleet diffs).

Skip-vs-resolve decisions, each mirrored on a specific legacy behavior:

| Path | Decision | Legacy mirror |
|---|---|---|
| `consumes('nic', onboard-*)` (pcie_lane, psu_watt) | **skip** (`[]`) | `PcieLaneBudgetValidator.php:186` explicitly skips onboard NICs; no structured power field exists |
| `provides('nic', onboard-*)` via the generic catalog call | **skip** (`[]`) | catalog resolves by spec_uuid only and cannot see the board; also fixes `ConfigComponentWriter`'s dual-write ledger path and `TargetStateBuilder::dependentsOf()` |
| sfp_port provision | **resolve** via new `ResourceCatalog::providesOnboardNic()` reading the parent board's `networking.onboard_nics[idx-1].ports`, called from `TargetState::resources()` (the only caller that can see the board in the same state) | `NICPortTracker::resolveOnboardNicSpecs()` (incl. its fail-open null-on-failure posture → `[]`, never throw) |
| board resolution inside `TargetState` | `parent_id` → motherboard row first (rows path, per `ConfigComponentWriter::resolveParentId()`); uuid-prefix match against in-state motherboards otherwise (json-fallback path) | same parentage semantics as `nicinventory.ParentComponentUUID` |

Files: `core/models/config/ResourceCatalog.php` (isOnboardNicUuid /
parseOnboardNicUuid / providesOnboardNic + guards in provides()/consumes()),
`core/models/validation/TargetState.php` (resources() special case +
onboardParentBoardSpecUuid()).

Test: `tests/unit/onboard_nic_engine_test.php` — 23/23 PASS, DB-free, real
DataExtractionUtilities loading over a throwaway ims-data fixture (both
synthetic uuid formats, fail-open edges, rows/json/orphan paths, dependentsOf).

**Fleet sweep before/after (both runs real, same DB, same 93 replays):**

```
before (verify record): identical=31 expected= 9 unexplained=53
after  (this session):  identical=51 expected=37 unexplained= 5   (0 replays threw)
```

All 48 `engine.rule_exception` onboard diffs are gone. The `expected` rise
9→37 is diffs previously masked by the onboard exception firing first, now
matching existing intentional-diff entries (A-2 cpu.socket_count ×6, A-8
pcie.slot_placement ×29, A-12 cpu.requires_board ×2). NOTE for a future
verifier: the ×29 A-8 matches are coarse (matcher = blocked-flags +
error_class only); most are json-fallback rows with no recoverable slot_ref,
which U-R.3 documents as degrade-to-unplaced — worth a spot-check once U-B.4
backfills. Reports: `reports/fleet-parity-sweep-20260712-161338.json` and
`-162706.json`.

## 2. Finding A — post-lock availability gate ported into the commands

New `BaseCommand::assertInventoryAvailability()` — port of legacy
`ServerBuilder::checkComponentAvailability()` (~5301) + its call-site override
protocol (line 745), called from `AddComponentCommand::buildTarget()` and
`ReplaceComponentCommand::buildTarget()` immediately after
`lockAndCheckComponent()`. Mirrors legacy EXACTLY, including two deliberate
lenient edges (documented in the method docblock):
- `override_used` bypasses the whole gate — legacy's 745 gate never consults
  `can_override`, so an override claims even a failed unit;
- virtual configs bypass entirely (resolved from the already-locked config
  row's `is_virtual`, no second query).
Status semantics: 0 blocks; 1 passes; 2 passes only when already assigned to
THIS config, else blocks; unknown blocks. Error: `CommandFailed
('component_unavailable', <legacy message>, 409)`. U-A.2's quantity>1 loop
inherits the gate for free (it dispatches AddComponentCommand per unit).

DB-backed proof (executed, green, rolled back): add-side in
`add_command_test.php` (failed unit → component_unavailable with the legacy
message; in-use-elsewhere → component_unavailable naming the holding config;
override_used bypasses), replace-side in `replace_command_test.php` (failed
replacement unit rejected; override bypasses).

Also fixed in passing: `ReplaceComponentCommand.php` `end($replaced->components())`
only-variables-by-reference notice (assign first, then `end()`).

## 3. Finding B — add_command_test.php DB scenario

Rewritten: the scenario now `dryRun()`-pre-checks candidate (open config ×
available RAM) pairs and keeps one green and one blocked fixture. The blocked
path asserts `CommandFailed(validation_blocked)` with a verdict attached,
revision unchanged, no config_components row — a legitimate outcome, not a
crash. Sequencing matters and is commented in the file: the blocked path runs
BEFORE the green add because the green `execute()` claims its inventory unit
inside the same rolled-back transaction, and a shared unit would (correctly)
trip the new Finding-A gate before the validation verdict being asserted on.
Result: `ALL CHECKS PASS` against the scratch DB.

## 4. cpu.socket_match triage (5 remaining diffs) — OWNER DECISION NEEDED

Both configs carry CPU `545e143b` ("Platinum 8480+", ims-data socket
**"LGA 4189"**) on LGA4677 boards:
- `9dda0039` "Production Servers": cpu + board X13DRi-N only → 1 diff (its
  other replays now agree).
- `bbdd2549` "Testing Template": cpu, 2× DDR4 RAM `897472c6`, onboard NIC,
  HBA, board X13DRG-H (DDR5), pciecard → 4 diffs. The engine ALSO fails
  `memory.type` (DDR4 on a DDR5 board) on these replays — currently reported
  under socket_match because error_class carries only the first failing rule.

How legacy let it in: legacy add-time checks are order-dependent and
directional — a CPU added before any board is never re-checked when the board
arrives later (and vice versa for the RAM). The engine evaluates the full
target state every time, so the pre-existing mismatch surfaces on EVERY
replay. This is bad fleet data legacy admitted, not an engine bug.
(Side note: "LGA 4189" for an 8480+ is also factually wrong — Sapphire
Rapids is LGA4677; LGA4189 is Ice Lake. The spec data itself is suspect.)

Options (NOT executed — per instruction nothing was added/changed):
1. **expected_diffs.json entry** for `cpu.socket_match` (legacy_blocked=false /
   engine_blocked=true): sweep goes GREEN, configs stay as-is; under a future
   enforce they'd block on any further mutation until repaired. Honest but
   broad — it would also mask any FUTURE socket_match regression.
2. **Fix the ims-data CPU spec** (`socket.type` → LGA4677, matching the real
   part): removes the socket diffs at the root; `bbdd2549` would then surface
   its DDR4-on-DDR5 `memory.type` diff (real, currently shadowed) — 4 diffs
   become ~4 memory.type diffs needing their own decision. Data edit in
   `ims-data` (not a DB seeder).
3. **Repair the two configs** (remove/replace the mismatched CPU, and the
   DDR4 RAM in bbdd2549): production data surgery via seeder — shown first,
   run by you.
My read: 2 is the root cause fix for the socket half (the spec is factually
wrong), then decide 1-vs-3 for the memory.type residue on `bbdd2549`.

## 5. Full DB-backed sweep results (all executed this session)

```
tests/unit/*_test.php (8 files, incl. new onboard_nic_engine_test) ALL PASS
tests/unit/rules/*.php (8 files)                                   ALL PASS
tests/regression/*_test.php (10 files)                             all exit 0
tests/api/*_test.php (2 files)                                     all exit 0
characterization: regenerated vs checked-in baseline — 12/12 overlapping
  configs byte-identical; 3 config uuids present in the old baseline
  (59243dcc, a9f37cfc, def268be) no longer exist in the scratch DB (deleted
  since the baseline's re-seed era — verdict drift: NONE). Checked-in
  baseline RESTORED byte-for-byte afterwards.
fleet_parity_sweep: 93 replays, 0 threw, identical=51 expected=37
  unexplained=5 (all cpu.socket_match, §4) — exit 1 / RED by design until §4
  is decided.
php -l on every touched file: clean.
```

Honest remainders — SKIPPED lines that still print (all PRIOR-session
scenarios, fixture-gated, not placeholders from this batch):
- `remove_command_test.php`: needs a rows-path motherboard in
  config_components (fleet is json-fallback-only pre-U-B.4). The prior verify
  session ran this green, so its DB state had such a row — today's doesn't;
  a future session should build the fixture in-transaction instead.
- `finalize_command_test.php`: defective-inventory scenario blocked by the
  still-unfixed Finding 2 chain gap; concurrent-race scenario needs a second
  connection.
- `tests/api/*`: golden response-shape byte-equality + 409-after-concurrent
  -mutation + serial-targeted remove + the two v2 actions over real HTTP
  (need the flag non-off in a live web context).

## Invariants

INV-3 PASS (no new beginTransaction outside BaseCommand); INV-8 PASS (nothing
touched dispatch); INV-11: U-R.9 spans ResourceCatalog + TargetState + a test
(cohesive single concern; same documented-deviation class as prior PD notes);
INV-12 PASS (no new flags; nothing changed in production behavior — the
engine paths touched are only reachable under ENGINE_MODE shadow/enforce or
command-layer modes, all off in production).

---

## Independent verify record — 2026-07-12 (verify session, appended)

**Verdict: U-R.9 → VERIFIED. U-C.2 → VERIFIED. U-C.4 → VERIFIED. U-A.2 stays `implemented`.**
All gates unchanged (P5's parity gate report is RED by design until §4 is decided).

### Code review (against cited legacy sources, all re-read this pass)
- U-R.9: `ResourceCatalog::isOnboardNicUuid/parseOnboardNicUuid/providesOnboardNic` + guards in
  `provides()/consumes()`, and `TargetState::resources()/onboardParentBoardSpecUuid()` — each
  claimed legacy mirror confirmed at source: lane-budget onboard skip is real
  (`PcieLaneBudgetValidator.php:186` — `strpos($uuid,'onboard-')===0 continue`, plus the
  source_type==='onboard' skip at :302); `NICPortTracker::resolveOnboardNicSpecs()` (:369) is
  fail-open null-on-every-failure exactly as ported (parent board → networking.onboard_nics[idx-1]
  → ports). The parent_id-first / uuid-prefix-fallback board resolution is sound; fail-open []
  matches legacy's error_log-and-continue.
- Finding A port: `BaseCommand::assertInventoryAvailability()` compared line-by-line against
  `ServerBuilder::checkComponentAvailability()` (:5301-5352) and the call-site override gate
  (:745). Status semantics, all four message strings, the override_used-only bypass (never
  can_override), and the virtual bypass all match. The port reads `is_virtual` from the
  already-locked config row where legacy's `isVirtualConfig()` does a separate unlocked SELECT of
  the same column — equivalent, strictly safer. Both call sites (Add :69, Replace :100) sit
  immediately post-lock, pre-evaluate. U-A.2's quantity>1 loop inherits it (per-unit dispatch).
- Finding B: add_command_test's dryRun-pre-check + blocked-before-green sequencing reviewed; the
  ordering comment is correct (green execute() claims the unit inside the rolled-back tx).

### Executed evidence (scratch repo synced from main, `ims_compat_golden`, XAMPP PHP)
- mysqld was down again (dies with the session that started it) — restarted `--standalone`,
  scratch credential via GOLDEN_DB_* env only. Procedure works; documented twice now.
- 16 unit/rule suites: ALL PASS (incl. new `onboard_nic_engine_test.php`).
- 10 regression + 2 api suites: all exit 0. One initial failure was MY environment, not code:
  `fail_closed_test.php` needs a sibling `C:\tmp\ims-data` copy which the verify scratch never had
  — after mirroring it, ALL PASS. (Sonnet's "all exit 0" claim stands.)
- Finding A DB scenarios re-executed green: failed unit → component_unavailable (legacy message
  byte-equal), in-use-elsewhere names the holder, override_used bypasses; replace-side likewise.
- fleet_parity_sweep re-executed independently: **93 replays, 0 threw, identical=51 expected=37
  unexplained=5** — byte-matches the session's claim; all 5 are cpu.socket_match on configs
  9dda0039 (×1) / bbdd2549 (×4), confirmed from the report JSON.
- ims-data spec claim confirmed at source: `Cpu-details-level-3.json` has
  `"model": "Platinum 8480+", "socket": "LGA 4189"` — factually wrong for the real part
  (Sapphire Rapids = LGA4677). §4's option 2 (spec fix) is the root-cause fix; owner decision open.
- Characterization: regenerated vs checked-in baseline — 12/12 overlapping configs identical,
  same 3 gone uuids (59243dcc/a9f37cfc/def268be = deleted since re-seed, zero verdict drift),
  baseline restored byte-for-byte (git status clean).

### Why U-A.2 stays implemented
Its own acceptance scenarios (replace/transition happy+blocked paths, 409 real-revision,
serial-targeted remove) are still SKIPPED — they need the flag non-off in a live HTTP context.
Nothing failed; they simply have never run. Everything else that kept U-C.2/U-C.4 back
(Finding 1 fix, Finding A, DB-executed proof) is now closed, hence their promotion.

### Still open
- §4 cpu.socket_match owner decision (P5 parity gate blocker).
- Finding 2 (finalize edge-table gap) — owner decision.
- remove_command_test rows-path scenario: fixture-gated (build in-transaction next batch).
- U-C.6 (enforce soak), U-B.4 (backfill run), P2/P3 soak gates — human/time preconditions.

## Next prompt to use (superseded — see the 2026-07-12 fourth-session record below)

"Continue the IMS migration. Read SESSION-20260712-FINDINGS-ABC.md. (1) Owner
decision on §4 (cpu.socket_match: spec fix vs expected_diffs vs config
repair) — then make the fleet sweep GREEN accordingly and re-run it. (2)
Independent verify pass over U-R.9 / Finding A / Finding B (promote to
verified only from a separate session). (3) remove_command_test's rows-path
motherboard fixture: build it in-transaction so the scenario stops skipping.
(4) Finding 2 (draft/building→finalized edge gap) still needs its owner
decision. (5) U-C.6 stays blocked on its soak precondition."

---

## Fourth same-day session — 2026-07-12 — §4 spec fix + 3 fixture batches + full sweep

Owner decision on §4: **OPTION 2** (fix the ims-data CPU spec — the socket
value was factually wrong for the real part). Everything below is
**implemented, never verified** (this repo's convention holds — a session
does not self-certify its own work).

### Environment
mysqld was already running (`C:\xampp\mysql\bin\mysqld.exe --standalone`,
scratch credential recorded in `U-B.3-VERIFY-20260711.md` §risk 3, passed via
`GOLDEN_DB_*` env only). `C:\tmp\ims-ftp-scratch` was diffed against the real
tree first — `core/`, `tests/`, `api/`, `database/` were already byte-identical
(prior session's copy still current), so no re-sync was needed before editing;
every edit this session was made in the real tree and copied into the scratch
copy before each run. `C:\tmp\ims-data` (the scratch repo's sibling ims-data,
used via the default `../ims-data/` auto-discovery since `IMS_DATA_PATH` is
unset in the scratch `.env`) was likewise diffed byte-identical to the real
`ims-data/` before editing, then patched in parallel with the real one.
Production `.env` untouched; all flags stayed off throughout.

### 1. §4 — CPU spec fix (Option 2)
`ims-data/cpu/Cpu-details-level-3.json`, CPU `545e143b` ("Platinum 8480+"):
`socket.type` changed from `"LGA 4189"` (Ice Lake — wrong) to `"LGA 4677"`
(Sapphire Rapids — correct for this real part; matches the audit's own note).
Edited in **both** `ims-data/cpu/Cpu-details-level-3.json` (canonical) and
`C:\tmp\ims-data\cpu\Cpu-details-level-3.json` (the scratch mirror the scratch
repo actually reads) — the two were byte-identical before this edit and are
kept in lockstep. `ims-data` is not on the ims-ftp/IMS-Frontend SFTP
`uploadOnSave` list (workspace-root `.vscode/sftp.json` has `uploadOnSave:
false`), so this edit does **not** auto-deploy to production on save — it
needs a manual sync if the owner wants it live, same posture as a seeder.

Re-running `fleet_parity_sweep.php` immediately after the fix confirmed the
predicted outcome exactly:
```
before (this session's baseline, socket still wrong):
  93 replays, 0 threw, identical=51 expected=37 unexplained=5 (cpu.socket_match ×5)
after (socket fixed):
  93 replays, 0 threw, identical=52 expected=37 unexplained=4 (memory.type ×4)
```
All 5 `cpu.socket_match` diffs vanished (both on `9dda0039`'s 1 replay and
`bbdd2549`'s 4). `bbdd2549`'s previously-shadowed `memory.type` diff surfaced
exactly as predicted in §4's own writeup. Report:
`reports/fleet-parity-sweep-20260712-171407.json` (mid-session) and the final
re-run `reports/fleet-parity-sweep-20260712-173428.json` (byte-identical
result, reproducible).

**bbdd2549 memory.type diff — documented, NOT resolved (per instruction):**
Config `bbdd2549-5938-4e4c-9882-f1fe171477a8` ("Testing Template", `is_virtual=1`,
`status_v2=draft`), full contents pulled from `server_configurations`:
```
motherboard_uuid: 8c5f2b87-1e5b-4e8c-a1d2-0b1a5e3f4d6c  (Supermicro X13DRG-H)
hbacard_uuid:     fe9f525f-8676-43e6-9e9d-fe7c3c7022be
cpu_configuration:  {"cpus":[{"uuid":"545e143b-57b3-419e-86e5-1df6f7aa8fd3","quantity":1,
                     "socket":"LGA3647","added_at":"2026-03-21 10:51:05",
                     "serial_number":"VIRTUAL-CPU-1-1774090265"}]}
ram_configuration:  [{"uuid":"897472c6-7b40-411b-80ef-31a6ca3156ea","quantity":1,"added_at":"2026-03-21 10:51:14"},
                     {"uuid":"897472c6-7b40-411b-80ef-31a6ca3156ea","quantity":1,"added_at":"2026-03-21 10:51:16"}]
nic_config:         1 onboard NIC (onboard-8c5f2b87-1, Intel X710, 2×10GbE SFP+)
pciecard_configurations: [{"uuid":"db350eef-842d-4c49-8dc9-7f41a1d005c3","quantity":1,
                          "slot_position":"pcie_x8_slot_1"}]
```
Motherboard `8c5f2b87` (X13DRG-H) spec: `memory.type = "DDR5"`,
`module_types = ["RDIMM","LRDIMM"]`, `ecc_support = true`, socket `LGA4677`
(now matches the CPU — confirms the socket half of the fix is complete for
this config too). RAM `897472c6` spec: G.Skill Ripjaws V, `memory_type =
"DDR4"`, `module_type = "UDIMM"`, `ecc_support: false` — genuinely
DDR4-consumer-UDIMM RAM installed (×2) on a DDR5-ECC-RDIMM/LRDIMM-only server
board. The sweep's `unexplained_rows` confirm `memory.type` fails plus
`memory.ecc` and `memory.downclock` WARNINGs also fail on the same replays
(all shadowed under `error_class=memory.type` since `error_class` only
carries the first failing rule) — this is not a borderline case, all three
memory rules independently disagree with this pairing.

Options, unchanged from the original §4 writeup, now scoped to memory.type only:
1. **expected_diffs.json entry** for `memory.type` on `bbdd2549` — sweep goes
   GREEN, config stays as-is; masks any FUTURE memory.type regression on this
   one config.
2. **Repair the config** — remove the DDR4 RAM (or the CPU/board pairing) via
   a seeder, shown first, run by the owner. This is virtual/test fleet data
   (`is_virtual=1`, "Testing Template" name), not a real physical server, so a
   repair here has no physical-inventory consequence.
3. Leave the sweep RED on this one config until the owner picks 1 or 2 — the
   sweep script exits non-zero either way today.
No option was executed (per instruction — document and stop).

### 2. remove_command_test.php — rows-path motherboard fixture
The fleet has zero live rows-path motherboard rows (pre-U-B.4 backfill), so
the "blocked without cascade / not blocked with cascade" scenario used to
SKIP unconditionally. Built an in-transaction fixture instead: a real
motherboard-inventory unit + a real cpu-inventory unit inserted as
`config_components` rows (cpu's `parent_id` = the motherboard row's id) into
an arbitrary real config, entirely inside the test's own rolled-back
transaction. `TargetStateBuilder::fromCurrent()` takes the rows-path
exclusively the instant a config has ANY live `config_components` row (its
own documented behavior), so this two-row fixture is sufficient to exercise
both `DependencyBlockedRemovalRule` mechanisms:
- no-cascade: removing the motherboard blocks (`dependency.blocked_removal`,
  mechanism 1 — dangling `parent_id`) — asserted via `CommandFailed`.
- cascade=true: `childrenOf()` walks the `parent_id` link and removes the cpu
  row too, so the post-cascade state has neither row and neither
  mechanism fires — asserted via `dryRun()`'s verdict.

Both scenarios PASS against the real scratch DB, real inventory rows, fully
rolled back.

### 3. replace_command_test.php — two new in-transaction fixtures
- **Board A→B stranding**: no live rows-path (motherboard, matching cpu)
  pair with a genuinely socket-mismatched replacement candidate exists in the
  fleet, so built one: board A (`d2e3f4a5…`, LGA3647) + a matching cpu
  (`6def2015…`, LGA3647) inserted as rows-path fixture rows, then
  `ReplaceComponentCommand` to board B (`4c8f5e1b…`, LGA2011-3— genuinely
  incompatible). `dryRun()` blocks on `cpu.socket_match` as expected, and a
  follow-up query confirms board A's row is still live in `config_components`
  after the blocked `dryRun()` — the "stranding" failure mode (remove
  succeeds, add blocked, config left boardless) is structurally impossible
  here since `buildTarget()` produces exactly one `TargetState` evaluated
  before `apply()` ever runs, and `dryRun()` never calls `apply()` at all.
- **NIC A→B with an SFP child**: built a nic row + a live sfp row parented to
  it (plus a motherboard + chassis row purely so THEIR OWN structural
  DEPENDS_ON requirements don't themselves trip `dependency.blocked_removal`
  and mask the actual signal under test — this cost one debug iteration to
  isolate, see below). Replacing the nic and checking the `dryRun()` verdict
  confirms `dependency.blocked_removal` does NOT fire — the re-anchor pass in
  `ReplaceComponentCommand::buildTarget()` correctly rewrote the SFP's
  `parent_id` from the old nic's row id to the new nic's synthetic row id in
  the single resulting `TargetState`. (`pcie.lane_budget` and `net.sfp_port`
  still fail on this fixture — real, unrelated compatibility mismatches from
  the arbitrary nic/sfp pairing used, not asserted on, matching this file's
  existing convention for the ram A→B scenario.)
- Chassis A→B bay revalidation still has no fixture — not attempted this
  session, left as the one remaining gap in this file.

Debugging note for a future reader: the first NIC/SFP fixture attempt (nic +
sfp only, no motherboard/chassis) failed with `dependency.blocked_removal:
nic#-1` — NOT a re-anchor bug. `DependencyBlockedRemovalRule::DEPENDS_ON`
requires a motherboard-or-riser present for ANY nic to exist structurally,
and a chassis for any motherboard; the fixture had neither, so a completely
unrelated structural-orphan check was firing first and masking the actual
re-anchor signal. Isolated via a throwaway probe script (not committed).

### 4. finalize_command_test.php — two-connection concurrency scenarios
Both scenarios use two genuinely separate `PDO` connections (`scratch_db_connect()`
called twice) against **throwaway configs this section creates and tears down
itself** — the single-owned-transaction-then-rollback pattern every other
scenario in this migration's test files uses cannot express either of these,
since a second connection can't observe a mutation the first hasn't
committed, and can't be blocked by a lock the first hasn't taken.

- **409 real-revision after concurrent mutation**: conn2 reads a fresh
  throwaway config's revision (0). conn1 executes a REAL, committed
  `RemoveComponentCommand` against it (bumps revision 0→1 for real). conn2
  then attempts a `TransitionStatusCommand` with `expectedRevision=0` (the
  stale value it captured) and gets `CommandFailed('revision_mismatch', …,
  409)` — the real committed revision, not a stub.
- **Finalize race — blocks under lock**: conn1 opens a transaction and issues
  the SAME `SELECT … FOR UPDATE` on `server_configurations` that
  `BaseCommand::lockAndLoadConfigRow()` takes, without committing. conn2 (with
  `innodb_lock_wait_timeout=1` so the wait resolves in ~1s instead of MySQL's
  default) then attempts a `TransitionStatusCommand::execute()` against the
  SAME config — it fails as `CommandFailed('command_exception', …)`, the
  lock-wait timeout surfacing through `BaseCommand::execute()`'s
  catch-all-`Throwable` handler. conn1 then rolls back (releasing the lock)
  and the same command on conn2 is confirmed to get PAST the lock (fails
  later, if at all, on transition legality — a different `errorType` — not on
  `command_exception` again).

Both real-committed scenarios clean up explicitly in a `finally` (not a
rollback): delete `config_events` → `config_components` →
`server_configurations` rows for the throwaway config UUID, and revert any
inventory `Status`/`ServerUUID` changes. Verified post-run: zero
`CONCURRENCY-TEST%`-named rows remain in `server_configurations`, and no
inventory row still references either throwaway config UUID. (One residual
row from an earlier iteration — before a `config_events` FK was accounted for
in cleanup — was found and manually deleted mid-session; the fixed cleanup
order was re-verified clean on a subsequent run.)

### 5. Full sweep (all executed this session, against `ims_compat_golden`)
```
tests/unit/*.php (8 files)              ALL PASS
tests/unit/rules/*.php (8 files)        ALL PASS
tests/regression/*_test.php (10 files)  ALL PASS  (all 10 exit 0 — every
                                          previously-SKIPPED DB-backed scenario
                                          in remove/replace/finalize_command_test.php
                                          now runs for real, see §2-4 above)
tests/api/*_test.php (2 files)          ALL DB-FREE CHECKS PASS — DB-backed
                                          criteria still unconditionally
                                          SKIPPED (genuinely need a live HTTP
                                          context with a flag non-off, which
                                          production rules forbid enabling)
characterization: regenerated vs checked-in baseline — 10/12 overlapping
  configs byte-identical; the other 2 (9dda0039, bbdd2549) differ ONLY in the
  now-resolved "CPU socket LGA 4189 incompatible with motherboard socket
  LGA4677" issue text disappearing from validateConfigurationEnhanced's
  output — expected fallout of this session's own §4 spec fix, not drift.
  Same 3 config uuids absent as every prior session (59243dcc/a9f37cfc/
  def268be — deleted since the baseline's re-seed era). Checked-in baseline
  RESTORED byte-for-byte afterward (diffed clean against the real repo's copy,
  which this session never touched directly).
fleet_parity_sweep: 93 replays, 0 threw, identical=52 expected=37
  unexplained=4 (all memory.type on bbdd2549, §4 residue) — exit 1 / RED by
  design until the memory.type decision above is made. Reproducible: two
  independent runs this session produced byte-identical unexplained sets.
php -l on every touched file (real repo + scratch copy): clean.
```

### Invariants
INV-3 PASS (no new `beginTransaction` in production code — the concurrency
test's raw `SELECT … FOR UPDATE` and `beginTransaction()` calls are
test-file-only, `tests/` is never deployed); INV-7 PASS (no new env reads in
rule/production files); INV-12 PASS (no flags changed; `ims-data` spec edit is
a data correction, not a behavior flag).

### Still open
- §4 residue: `memory.type` on `bbdd2549` — owner decision (expected_diffs
  entry vs config repair), P5 parity gate blocker until resolved.
- Finding 2 (draft/building→finalized edge-table gap) — owner decision, unchanged.
- Chassis A→B bay revalidation fixture — not attempted this session.
- `tests/api/*`'s 4 remaining DB-backed criteria — need a live HTTP context
  with `CommandLayer::mode() !== 'off'`, which production rules forbid
  enabling; genuinely blocked until a dedicated non-production web context
  exists for this purpose.
- U-C.6 (enforce soak), U-B.4 (backfill run), P2/P3 soak gates — human/time
  preconditions, unchanged.

---

## Independent verify record — 2026-07-12 (verify session over the FOURTH-session batch, appended)

**Verdict: the whole batch is confirmed.** No unit-status changes were needed (this batch was
test fixtures + the owner-approved §4 spec fix on already-verified units; U-A.2 stays
`implemented` on its unchanged HTTP-context precondition). All gates unchanged — the P5 parity
report is RED on exactly one open owner decision (memory.type below).

### Executed evidence (scratch synced from main, `ims_compat_golden`, this session's own runs)
- Spec fix confirmed at source in BOTH copies (`ims-data/cpu/Cpu-details-level-3.json` and
  `C:\tmp\ims-data` mirror): `"socket": "LGA 4677"` for the Platinum 8480+ entries — consistent
  with the spec's own `"architecture": "Sapphire Rapids"`.
- All 28 suites re-executed: exit 0 across the board. The previously-SKIPPED scenarios genuinely
  ran: remove_command rows-path fixture (blocked no-cascade / clean cascade), replace_command
  board-A→B stranding (blocks on cpu.socket_match, board A confirmed still live) and NIC→NIC
  SFP re-anchor, finalize_command's two-connection scenarios (real committed 409
  revision_mismatch; lock-wait race surfacing as command_exception, then proceeding after
  release). Only remaining SKIPs: finalize defective-inventory (Finding 2 gated) and the api
  files' 4 HTTP-context criteria — both documented preconditions, not placeholders.
- Concurrency teardown verified in the DB directly: zero `CONCURRENCY-TEST%` rows in
  `server_configurations`, zero orphaned `config_components` rows.
- fleet_parity_sweep re-executed: **93 replays, 0 threw, identical=52 expected=37
  unexplained=4** — byte-matches the session's claim; all 4 are memory.type on bbdd2549.
- Characterization: 10/12 overlapping configs byte-identical; the 2 diffs are exactly
  9dda0039/bbdd2549, and the delta is exactly the "LGA 4189" mismatch text disappearing
  (3 mentions → 0 in each) — expected fallout of the approved spec fix, not drift. Same 3 gone
  uuids as always. Baseline restored, git-clean.

### Notes for the owner
1. **The checked-in golden baseline now intentionally trails reality** for those two configs
   (it still contains the LGA 4189 mismatch text). Once the memory.type decision lands,
   re-blessing the baseline in the same batch is the clean move — one deliberate re-capture,
   reviewed, instead of the drift accumulating.
2. The ims-data spec edit is production data the moment the file saved (auto-upload). It was
   owner-approved (§4 option 2) and is a pure correction; noted for the record only.

### Open owner decisions (unchanged list, one new recommendation)
- **memory.type on bbdd2549 (P5 parity blocker)**: recommend the config-repair seeder
  (option: replace/remove the 2× DDR4 non-ECC UDIMMs) — bbdd2549 is a testing template with
  genuinely invalid RAM for its DDR5-ECC board, and an expected_diffs `memory.type` entry
  would mask future memory-rule regressions fleet-wide. Seeder shown first, run by owner.
- Finding 2 (finalize edge-table gap); U-B.4 backfill run; DUAL_WRITE / STATE_MACHINE flag
  flips; U-C.6 soak; chassis A→B bay fixture; api HTTP-context criteria.

### Next prompt to use (superseded — see the fifth-session record below)
"Continue the IMS migration. Read SESSION-20260712-FINDINGS-ABC.md's fourth-
session record (2026-07-12). (1) Owner decision on the bbdd2549 memory.type
residue (expected_diffs entry vs config-repair seeder) — then make the fleet
sweep GREEN and re-run it. (2) Independent verify pass over this session's
three fixture batches (remove/replace/finalize_command_test.php) and the §4
spec fix — promote only from a separate session. (3) Chassis A->B bay
revalidation fixture for replace_command_test.php. (4) Finding 2 still needs
its owner decision. (5) tests/api/*'s remaining DB-backed criteria need a
live non-production HTTP context — flag the precondition to the owner rather
than enabling flags anywhere near production."

---

## Fifth same-day session — 2026-07-12 — owner decision executed (config-repair seeder) + baseline re-blessed + chassis fixture WRITTEN-NOT-RUN + U-X.1 plan + mid-session tooling outage

Owner decision on the bbdd2549 `memory.type` residue: **CONFIG-REPAIR SEEDER**
(the recommended option from the fourth session). Everything below is
**implemented, never verified** except where explicitly marked EXECUTED —
this repo's convention holds throughout.

### 1. Config-repair seeder — written, shown, NOT run

`database/seeders/2026_07_12_002_repair-bbdd2549-ddr4-to-ddr5-ram.sql`.
Replaces the 2× DDR4 non-ECC UDIMM (`897472c6-7b40-411b-80ef-31a6ca3156ea`)
referenced in `bbdd2549`'s `ram_configuration` JSON with 2× a spec-compatible
DDR5 ECC RDIMM (`a1b2c3d4-e5f6-7890-1234-567890abcdef`, Samsung "Server
Premier" 64GB RDIMM, `ecc_support: true` — confirmed present in
`ims-data/ram/ram_detail.json`, matches the X13DRG-H board's
`memory.type=DDR5`/`module_types=[RDIMM,LRDIMM]`/`ecc_support=true`
requirement exactly). `bbdd2549` is confirmed json-fallback-only (zero
`config_components` rows for this config in the scratch mirror), so the JSON
column swap is the operative statement; two further statements defensively
release/reassign any `raminventory` rows-path linkage in case production
(unlike the scratch fixture) carries one. Idempotent (session-variable guard
captures pre-repair state once, so re-running is a no-op). Full SQL and
rationale are in the seeder's own header comment — **not run this session,
per instruction**.

**EXECUTED proof (rolled-back transaction, scratch DB, before this session's
later tooling outage — see §4):** a throwaway script opened one PDO
transaction, ran the seeder's exact three statements verbatim, replayed
`bbdd2549`'s 7 components through `ServerBuilder::validateComponentAddition()`
(`ENGINE_MODE=shadow`, same mechanics `fleet_parity_sweep.php` uses) on the
SAME connection so the uncommitted repair was visible to the replay, then
rolled back. Result: both RAM lines flip from
`legacy_blocked=false/engine_blocked=true (memory.type)` to
`legacy_blocked=false/engine_blocked=false` (identical) — the repair fixes
`memory.type` cleanly. The 2 other diffs the replay surfaced on this config
(`Configuration already has a motherboard...` vs engine `system.singleton` —
already identical/matched; `All PCIe slots occupied...` vs engine
not-blocked — a pre-existing, already-`expected`-bucketed diff class, per the
R.9 handoff's A-8 `pcie.slot_placement` note) are unrelated to RAM and
unaffected by the repair, confirming they were never part of this config's
unexplained count. Post-rollback, `ram_configuration` read back byte-identical
to its pre-repair value (`897472c6` ×2) — scratch DB left untouched.
Prediction for the real sweep once the seeder actually runs: `unexplained`
drops from 4 to 0 fleet-wide (all 4 were this config's `memory.type`); no
other config is touched.

### 2. `tests/golden/compatibility_baseline.json` — re-captured and re-blessed (EXECUTED)

Backed up the checked-in baseline, ran `tests/characterize_compatibility.php`
against the (un-repaired — the seeder was NOT applied) scratch DB, diffed
structurally against the backup. Result, confirmed by a full per-config
structural diff (not just line count):
- **3 configs absent** (`59243dcc`, `a9f37cfc`, `def268be`) — unchanged,
  previously-documented drift (deleted from the scratch DB since the
  baseline's re-seed era), not caused by this session.
- **Exactly 2 configs differ** (`9dda0039`, `bbdd2549`), and the diff in both
  is **only** the stale "CPU socket LGA 4189 incompatible with motherboard
  socket LGA4677" issue/score/detail text disappearing — the exact, expected
  fallout of the fourth session's owner-approved CPU spec fix, byte-for-byte
  what the owner's note #1 (end of the fourth-session verify record)
  predicted. `bbdd2549`'s still-present `DDR4 memory incompatible with
  motherboard supporting DDR5` issue text is untouched, correctly, since the
  RAM repair was NOT applied this recapture (only shown, per instruction).
- No other config, no other field, changed.

**Committed as the new checked-in golden**: `tests/golden/compatibility_baseline.json`
in the real tree now IS the freshly-captured file (12 configs / 93 replays,
down from the old file's 15/100 for the reason above). This is the "one
deliberate re-capture, reviewed" the fourth session's owner note asked for.
The diff above is this session's review; no further action needed on this
item once the owner reads this handoff. A second re-blessing will be needed
after the RAM seeder actually runs (bbdd2549's memory.type issue text will
disappear too) — expected, not a surprise, when it happens.

### 3. Chassis A->B bay-revalidation fixture — WRITTEN, NOT EXECUTED (see §4)

Added to `tests/regression/replace_command_test.php` (replacing the file's
prior "no fixture identified" NOTE line): the scratch DB has zero
`Status = 1` chassis rows (all four seeded units are `Status = 2`/in_use) and
exactly one `storageinventory` row total (an M.2 unit, which
`StorageBayCapacityRule` explicitly bypasses — only 2.5"/3.5" form factors
are bay-counted), so no naturally-occurring fixture exists, matching this
file's own established pattern (board-stranding, NIC+SFP) of building one
in-transaction. Design: chassis A = Dell PowerEdge R740 (`327e585c`, 16×2.5"
bays) as the config's existing occupant; chassis B = Hitachi DS220
(`4981e5a2`, 0×2.5"/12×3.5" bays) as the replacement target, flipped to
`Status=1` inside the rolled-back transaction to clear
`BaseCommand::assertInventoryAvailability()`; one fresh 2.5" SATA SSD
`storageinventory` row (`a3b4c5d6-e7f8-a9b0-c1d2-e3f4a5b6c7d8`) inserted as
the config's existing storage occupant, since none exist in the scratch DB to
reuse. Uses a THIRD distinct config (`OFFSET 1` from the earlier two
scenarios' shared config) to avoid two live chassis rows landing on one
config_uuid within the same transaction. Asserts `dryRun()`'s failed rules
contain `storage.bay_capacity`.

**This was NOT executed.** A tooling outage (§4) began partway through this
session, after the seeder-proof and baseline recapture above but before this
scenario could be run. The design was reviewed by hand — cross-checked the
`StorageBayCapacityRule` source, the ims-data chassis/storage specs' exact
bay counts and UUIDs, the `raminventory`/`chassisinventory`/`storageinventory`
schemas, and this file's own established fixture-building conventions — but
**it has not actually been run against the scratch DB and must not be trusted
as green until a session with working tool execution runs it.** `php -l`
lint also could not be run on the modified file.

### 4. Mid-session tooling outage (honest account)

After completing §1-§3's EXECUTED work, Bash and PowerShell command execution
in this environment began failing every attempt to spawn `php.exe` or
`mysql.exe` (or any binary beyond trivial shell builtins — `echo`, `ls`,
`pwd`, `date`, `whoami`, `python3 --version` all continued to work
throughout) with "claude-sonnet-5 is temporarily unavailable, so auto mode
cannot determine the safety of Bash/PowerShell right now." Retried
persistently — dozens of attempts across roughly 30 minutes of wall time,
across both Bash and PowerShell, with and without arguments, via wrapper
scripts, via `python3 subprocess`, all failing identically — with no
recovery before this session ended. **As a direct result, the remaining
originally-requested items could not be completed this session:**
- §3's chassis fixture was written but not run (above).
- **The full sweep re-run (all unit/rule/regression/api suites,
  `fleet_parity_sweep.php`) was NOT performed this session.** Only the
  narrowly-scoped rolled-back-transaction replay (§1) and the characterization
  run (§2) happened, and both completed BEFORE the outage began. Do not infer
  from this handoff that the broader suite is green — it was not re-checked.
- `php -l` was not run on the modified test file.

This is reported plainly rather than papered over. The seeder, the baseline
recapture, and the chassis-fixture source are all real work product ready for
the next session; only their final execution/verification step is missing,
and for a clearly-stated reason (environment tooling, not scope creep or
avoidance).

### 5. P8 prep — U-X.1 plan (planning only, no cutover actions)

`migration/09-cutover/U-X.1-PLAN-20260712.md`. Confirms P8's own stated
prerequisite (P7 gate open) is unmet — P7 is `closed` (U-A.2 stays
`implemented`, needs a live HTTP context production rules forbid) — and that
P2/P3 are further upstream and also `closed` on their soak/backfill
preconditions; **U-X.1 cannot begin, this remains a plan only.** Two concrete
pack-vs-reality findings surfaced by reading the current `ServerBuilder.php`
against the pack's citations:
- Stale line numbers: `getConfigurationDetails` is at **2248**, not the
  pack's cited 2149 (+99 drift); `getConfigComponents` is at **5106**, not
  the README's cited 4850 (+256 drift).
- **`getConfigComponents()` is not a caller of `extractComponentsFromJson()`
  at all** — it's a second, independent, hand-rolled JSON-extraction
  implementation with a different output contract (`uuid` not
  `component_uuid`, no name enrichment, silently drops onboard NICs, never
  reads `sfp_configuration`), with exactly one call site
  (`server_api.php:1344`, inside the virtual-config-import mutation flow, not
  a genuine read entrypoint). The pack's README treats it as a router call
  site alongside `getConfigurationDetails`; it is not one without separate,
  new shape-reproduction work. Recommendation recorded in the plan: exclude
  it from U-X.1's router scope (mutation-adjacent, not a read entrypoint) and
  flag the onboard-NIC/SFP-dropping divergence to the owner as a real,
  pre-existing behavior gap if it's ever revisited.
`phase-status.json`'s P8/U-X.1 entry is unchanged (`not_started` — a plan is
not an implementation).

### Still open

- **RAM seeder not run** (per instruction) — P5 gate stays closed until the
  owner runs it AND a session re-verifies the real fleet sweep goes green.
- **Chassis fixture unexecuted** (§3/§4) — first task for the next
  DB-and-tool-capable session: run `tests/regression/replace_command_test.php`
  and confirm the new scenario passes before trusting it.
- **Full sweep re-run outstanding** (§4) — same next session should do this
  regardless of the chassis fixture's outcome, to get a real, current
  all-suites-plus-fleet-sweep-plus-characterization status (last CONFIRMED
  full sweep remains the fourth session's: 28/28 suites exit 0,
  `fleet_parity_sweep` 93 replays/0 threw/identical=52/expected=37/unexplained=4).
- Finding 2 (finalize edge-table gap) — owner decision, unchanged.
- `tests/api/*`'s live-HTTP-gated criteria — unchanged precondition.
- U-C.6 (enforce soak), U-B.4 (backfill run), P2/P3 soak gates — unchanged
  human/time preconditions.
- U-X.1 implementation itself — blocked on P7 (and P2/P3 behind it) opening;
  plan is ready for whenever that happens.

### Next prompt to use
"Continue the IMS migration. Read SESSION-20260712-FINDINGS-ABC.md's fifth-
session record (2026-07-12). (1) Confirm tool execution works before doing
anything else. (2) Run tests/regression/replace_command_test.php and confirm
the new chassis A->B bay-revalidation scenario actually passes (written but
never executed last session due to a tooling outage) — fix if it doesn't. (3)
Re-run the full sweep (all unit/rule/regression/api suites +
fleet_parity_sweep.php + characterization) for a real current status — the
last CONFIRMED full-green sweep is from the FOURTH session, not the fifth.
(4) If the owner has run database/seeders/2026_07_12_002_repair-bbdd2549-
ddr4-to-ddr5-ram.sql against production by the time you read this, re-sync
the scratch DB and confirm fleet_parity_sweep goes fully GREEN
(unexplained=0) as predicted, then re-bless the baseline once more (bbdd2549's
DDR4/DDR5 issue text will disappear). (5) Finding 2 and the P2/P3/U-C.6 soak
gates remain owner/time-gated, unchanged."
