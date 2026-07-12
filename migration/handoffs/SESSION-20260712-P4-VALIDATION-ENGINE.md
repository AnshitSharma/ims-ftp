# SESSION 2026-07-12 — P4 validation engine skeleton (U-V.1 – U-V.4)

**Owner authorization on record**: the user explicitly authorized starting P4/P5 implementation
out of order this session, acknowledging that P2's gate (U-B.4 sign-off) and P3's gate (7-day
STATE_MACHINE_ENABLED=shadow soak) are closed only on human, time-based preconditions, not on
missing code, and that P4's own gate cannot open until P2/P3 open regardless of P4/P5 code
completeness. `ENGINE_MODE` and every other flag stay untouched/off in production this session —
nothing here changes production behavior; it is inert scaffolding until a human flips a flag.

**Status for all 4 units below: implemented, not verified** (per explicit instruction). Every unit
test listed was run locally against the `ims_compat_golden` scratch DB / pure PHP and passed —
that is unit-level self-verification, not the independent-review "verified" status this repo's
convention reserves for a *separate* session's sign-off.

---

## U-V.1 — Value objects + Rule interface

**Files created**: `core/models/validation/Severity.php`, `Trigger.php`, `RuleResult.php`,
`Verdict.php`, `RuleInterface.php`. `tests/unit/verdict_test.php`.

**Plan deviation PD-2**: the execution pack specifies "PHP 8 enums; final classes". `ims-ftp/CLAUDE.md`
pins the stack at PHP 7.4+, and nothing anywhere in `core/` uses PHP 8-only syntax. Once U-V.3 wires
the hook, `ValidationEngine.php` (which requires these files) is `require_once`'d unconditionally
from `ServerBuilder.php` on every request that calls `validateComponentAddition()` — a parse error
on PHP 7.x would take down that path regardless of `ENGINE_MODE`. Implemented `Severity`/`Trigger`
as final classes of string constants instead of native enums; `RuleResult` emulates `readonly` via
private properties + getters instead of PHP 8.1 readonly properties. Same vocabulary, zero syntax
risk if production is in fact still on 7.4. **Flag for a human**: if production is confirmed PHP
8.1+, this deviation can be reverted in a follow-up unit — not urgent, costs nothing to leave as-is.

**Current state**: no callers anywhere yet outside `ValidationEngine`/`ShadowRunner`/`ServerBuilder`'s
hook (U-V.3). Dead weight if deleted.

**Invariant check**: INV-7 (rules never read env — N/A yet, no rules exist), INV-11 (shadow-safe —
N/A, no DB/IO in these files).

**Acceptance test results**: `tests/unit/verdict_test.php` — 30/30 PASS (blocking matrix for
every Severity×Trigger combination, immutability via reflection, RuleInterface scope constants).
`php -l` clean on all 5 files.

**Known risks**: none beyond PD-2 above.

---

## U-V.2 — TargetStateBuilder

**Files created**: `core/models/validation/TargetState.php`, `TargetStateBuilder.php`.
`tests/unit/target_state_test.php`.

**Design note beyond the pack's own text**: `TargetState::resources()` is ALWAYS recomputed from
`components()` via `ResourceCatalog` — never read from the `config_resources` ledger table. This
matches the pack's "resource deltas recomputed via catalog" line, and it's what makes the rows-path
and JSON-fallback-path give identical resource answers for the same component set: resource math
never depends on which source produced the component list. It also happens to be the only workable
design today, since `config_resources` has 0 rows in production (DUAL_WRITE_ENABLED is off).

**Known gap (documented in the file, not a defect)**: the JSON fallback path cannot recover
`slot_ref` for pciecard/hbacard (legacy JSON never stored it — confirmed by reading
`ServerBuilder::extractComponentsFromJson()` lines 61-256 in full) or parent linkage for anything
but sfp→nic (via `parent_nic_uuid`). Rules needing `slot_ref` (U-R.3/U-R.6) will treat json-source
pciecard/hbacard rows as "unplaced" — this is not a regression, since no live slot bookkeeping
exists pre-backfill either. It self-resolves once U-B.4 backfills (every config takes the rows path
from then on).

**Reused rather than reimplemented**: the JSON fallback calls
`ServerBuilder::extractComponentsFromJson()` directly (constructing a throwaway `ServerBuilder`
instance) rather than duplicating its per-column JSON parsing — this was a deliberate choice to
avoid the exact "three-impl drift" class of bug RULE_MAP.md warns about elsewhere (e.g. memory
slot-count's three implementations, storage's H9 stale-class). One parser, reused.

**Acceptance test results**: `tests/unit/target_state_test.php` — 40/40 PASS, including the pack's
explicit acceptance criterion "fromCurrent on dual-written fixture == JSON fallback on same fixture
(tuple-equal)": ran `fromCurrent()` against real fixture config `06ea5abb-ddb0-4945-ba88-7eba61ba3905`
(config_components empty → JSON path, 12 components incl. a real add-on NIC), then inside a rolled-
-back transaction inserted matching `config_components` rows via `ConfigComponentRepository::insert()`
and re-ran `fromCurrent()` (rows path) — same count, same `type:spec_uuid` multiset. `withReplace`
atomicity and `withRemove(cascade)` parent_id-subtree pull both verified with pure in-memory
fixtures (no DB). Transaction rollback confirmed: `config_components` row count is 0 after the test
run, same as before. `php -l` clean.

**Known risks**: none beyond the documented json-fallback slot_ref/parent-linkage gap above.

---

## U-V.3 — Engine + registry + shadow runner

**Files created**: `core/models/validation/ValidationEngine.php`, `ShadowRunner.php`.
**Files modified**: `core/models/server/ServerBuilder.php` (the one hook site).
`tests/unit/engine_shadow_test.php`.

**How the hook site was actually implemented (reads differently from the pack's literal words —
explain why)**: the pack says "MODIFY... FIRST lines... shadow ⇒ record + continue legacy". Read
literally, that would mean logging a shadow row using only the engine's verdict, before legacy
validation has run — but `parity_report.php` (U-V.4) needs BOTH sides' actual outcome for the SAME
operation to compute a diff, and `validateComponentAddition()` (`core/models/server/ServerBuilder.php`,
was lines 4142-4396) has roughly a dozen internal `return` statements scattered through ~250 lines,
so capturing legacy's real result without touching every one of them isn't possible from "first
lines" alone. Chose the lowest-risk implementation that still satisfies "one hook site total":
renamed the entire original method body, byte-for-byte, to a new private method
`legacyValidateComponentAddition()`; the public `validateComponentAddition()` is now a ~35-line
wrapper that, when `ENGINE_MODE=off` (the production default), does nothing but call straight
through to the renamed method — one extra call frame, zero behavior change, confirmed by
`tests/unit/engine_shadow_test.php`'s "off mode returns a legacy-shaped result" + "shadow mode
returns the SAME legacy result as off mode" assertions (both modes were driven against the same
legacy body in the same test run and produced byte-identical results). In shadow/enforce mode the
wrapper builds a `TargetState` (`fromCurrent` + `withAdd`), evaluates the (currently empty) engine
registry, THEN calls the legacy body, THEN logs both outcomes together via `ShadowRunner::record()`.
15 existing call sites of `validateComponentAddition()` (`server_api.php`,
`ValidationPipeline.php`, `SlotAuthority.php`, `StorageConnectionAuthority.php`, test scripts) were
NOT touched — the public method's name and signature are unchanged, so none of them needed to
change. This is recorded as a documented interpretation of the pack, not silently deviating from it.

**Fail-closed (INV-5) confirmed**: `ValidationEngine::evaluate()` wraps every rule call in
try/catch; an exception is synthesized into a failed `ERROR` `RuleResult` named
`engine.rule_exception` (never swallowed) — verified with an induced-exception test-only rule
fixture (`tests/unit/engine_shadow_test.php`, uses a `TestEngineWithThrowingRule` subclass since
`RULES` is a class const per the pack's own design, which is also why `ValidationEngine` was left
non-`final` unlike the U-V.1 value objects — noted inline in the class docblock).

**Shadow-write-only confirmed**: `ShadowRunner::record()` only ever appends to
`reports/shadow/engine-<Ymd>.jsonl`; it has no other side effect.

**Acceptance test results**: `tests/unit/engine_shadow_test.php` — 13/13 PASS: off-mode passthrough
byte-identical to legacy, off-mode writes zero shadow rows, shadow-mode returns the same legacy
result AND appends exactly one shadow row shaped `{ts, config_uuid, op, trigger, legacy:{blocked,
error_class}, engine:{blocked, error_class}, results[]}`, induced rule exception fails closed as an
ERROR that blocks. `php -l` clean on all 3 files (2 new + ServerBuilder.php).

**Local test artifact note**: the test run left one real file, `reports/shadow/engine-20260711.jsonl`
(date reads 0711 due to this sandbox's system-clock offset from the stated session date), containing
one synthetic row from the CLI test process (`ENGINE_MODE` was only ever set via `putenv()` inside
that one-off PHP CLI invocation — never touched in any web-server-facing file). Left in place rather
than deleted (auto-mode declined the deletion as an unreviewed file removal); harmless either way
since `reports/` is exactly what this file is for, and production never writes here while
`ENGINE_MODE` stays off.

**Known risks**: the shadow hook adds one `TargetStateBuilder::fromCurrent()` DB read (a `SELECT *
FROM server_configurations WHERE config_uuid = ?` in the JSON-fallback branch, since
`config_components` is empty everywhere in production today) per `validateComponentAddition()` call
**only when `ENGINE_MODE` is shadow or enforce** — zero added queries while the flag stays off. No
performance report was run against this (P4's own gate needs `performance` per `phase-status.json`,
not exercised this session since `ENGINE_MODE` never left `off`).

---

## U-V.4 — parity_report.php

**Files created**: `scripts/verify/parity_report.php`, `scripts/verify/expected_diffs.json`
(seeded with an empty `entries` array — U-R.* units append their own expected-diff entries as they
introduce intentional divergences, per each pack's "Tests" section).
**Files modified**: `scripts/verify/run_all.php` (`parity`'s registry entry flipped from
`'available' => false, 'lands_in' => 'U-V.4'` to `'available' => true`).

**Diff semantics implemented**: a diff = `legacy.blocked !== engine.blocked` for the same shadow
row (both sides already canonicalized to `{blocked, error_class}` by `ShadowRunner::record()`, so
this report does no further canonicalization — matches the README's "message-text differences never
count as diffs" instruction, since only the boolean is compared, never `message`). A diff is
EXPECTED iff it matches an `expected_diffs.json` entry on `(legacy_blocked, engine_blocked,
engine_error_class)`; every entry requires a `rule_id` and `audit_finding` field (enforced by
`loadExpectedDiffs()`, which throws if either is missing — satisfies the checklist's "expected_diffs.json
requires audit-finding id per entry").

**Acceptance test results**: `--self-test` — synthetic 3-row JSONL (1 identical, 1 expected-diff
matched against a seeded synthetic entry, 1 unexplained) correctly classifies all three and exits 1
(pack requirement: "self-test: synthetic JSONL with one unexplained diff ⇒ exit 1" — confirmed).
Empty-window run (`--file` pointed at a nonexistent path) exits 0 and prints the loud
`WARNING operations compared: 0` line (pack requirement confirmed verbatim). A real-window run
against the leftover `reports/shadow/engine-20260711.jsonl` from U-V.3's test exits 0 GREEN (1
operation compared, identical verdict — expected, since the engine registry is still empty at this
point in the unit sequence). `run_all.php`'s `parity` entry is now `available: true` and appears
under `--gate P4`/`--gate P5`/`--gate P6`/`--gate P7` per `GATE_REPORTS`. `php -l` clean.

**Known risk / pre-existing environment issue (not introduced this session)**: `run_all.php --gate P4`
printed `parity: GREEN (no report line found in child output)` instead of parroting the report's own
file path — direct invocation (`php scripts/verify/parity_report.php`) works correctly and prints
the expected `parity_report: GREEN <path>` line. Reproduced the identical symptom on the pre-existing
`schema` report (`--gate P1`) with zero changes from this session, so this is a `proc_open`/Windows
PATH quirk in `run_all.php`'s child-process output capture on this local box, not a defect in
`parity_report.php`. Not investigated further (out of scope for U-V.4; flagged for whoever verifies
`run_all.php` itself).

---

## Overall invariant/acceptance summary (all 4 units)

| Unit | php -l | Unit tests | Fail-closed (INV-5) | No env read in rules (INV-7) | Shadow-safe (INV-11) |
|---|---|---|---|---|---|
| U-V.1 | clean | 30/30 PASS | N/A (no rules yet) | N/A | N/A |
| U-V.2 | clean | 40/40 PASS | N/A | N/A | confirmed (no writes) |
| U-V.3 | clean | 13/13 PASS | confirmed (induced-exception test) | N/A (no rules yet) | confirmed (JSONL-only) |
| U-V.4 | clean | self-test PASS (exit 1 as required) + empty-window + real-window | N/A | N/A | confirmed (read-only + own report file) |

INV-12 (only FLAGS.md-listed flags): `ENGINE_MODE` was the only flag touched/read, exactly as
FLAGS.md specifies (introduced U-V.3, consumed by `ValidationEngine`).

## Human decisions needed

None from this batch specifically — all 4 units are shadow-scaffolding with `ENGINE_MODE` left off.
Carried forward from the prior session (still open, unrelated to this batch): the ambiguous
pciecard inventory violation (config `9dbc63fa-900c-4c4d-bd28-7bde703b34ad`) and whether/when to run
`audit-orphans.php --fix` on production for the 12 real orphans.

## Next prompt to use

"Continue the IMS migration. U-V.1–U-V.4 are implemented (see
migration/handoffs/SESSION-20260712-P4-VALIDATION-ENGINE.md), not yet independently verified. Do
U-R.1 through U-R.6 per their execution packs (migration/04-validation-engine/execution-packs/),
each registering its rules in ValidationEngine::RULES and adding expected_diffs.json entries for
every intentional divergence its own pack's RULE_MAP row calls out. ENGINE_MODE stays off in
production throughout. One consolidated handoff for the whole U-R.1–U-R.6 batch."

## Files to load next session

`migration/04-validation-engine/RULE_MAP.md`, `migration/04-validation-engine/execution-packs/U-R.*.md`,
`core/models/validation/*.php` (this session's output), `scripts/verify/expected_diffs.json`.

## Expected context size

~35k tokens (RULE_MAP + 6 execution packs + the 4 files above + legacy source excerpts per rule
family read on demand).

---

## Independent verify record — 2026-07-12 (Claude Fable, separate session from implementer)

**Verdict: U-V.1, U-V.2, U-V.3, U-V.4 all VERIFIED.** phase-status.json updated.

- Files were implemented in the MAIN working copy (not scratch); synced all new/changed files to
  `C:\tmp\ims-ftp-scratch` and byte-compared — identical. All verification below ran on scratch
  against `ims_compat_golden`. NOTE for the record: main-copy saves auto-deploy to production —
  safe here only because ENGINE_MODE is unset/off; confirmed the off-path is a pure passthrough.
- **U-V.3 hook proven minimal via git diff** (scratch HEAD predates this session):
  `ServerBuilder.php`'s only new hunk is the wrapper + rename — the legacy validation body has
  ZERO modified lines (rename to `legacyValidateComponentAddition()` only). Off mode returns the
  legacy call unconditionally before any engine code loads.
- Code review: ValidationEngine::mode() matches the FLAGS.md read pattern (getenv → $_ENV → 'off'
  → whitelist); evaluate() fail-closed try/catch confirmed (INV-5); ShadowRunner writes only its
  JSONL; Verdict blocking matrix matches RULE_MAP legend; parity diff = blocked-boolean only,
  expected_diffs entries require rule_id + audit_finding (throws otherwise).
- Suites re-run on scratch: verdict_test 30/30, target_state_test 40/40, engine_shadow_test 13/13
  (needs `IMS_DATA_PATH` set or rules throw on spec resolution — environmental, not a defect),
  parity --self-test exit 1 as required, empty-window exit 0 with the loud zero-sample WARNING,
  run_all --gate PL still GREEN, characterization exit 0 (12 configs / 93 replays, baseline
  git-restored), full regression sweep (state_guard, dual_write, finalized_immutability,
  nested_transaction, config_component_repository, extractor, ledger_*) ALL PASS.
- PD-2 (constants classes instead of PHP 8 enums): endorsed — matches this repo's 7.4+ pin.

**Observation (non-blocking, for U-C.2 or a small follow-up)**: the shadow hook always passes
`parent_id => null` into `withAdd()`, so an SFP added WITH a parent_nic_uuid is evaluated by the
engine as "staged" (TP-4A pass) while legacy checks the real parent — a shadow-fidelity gap that
can only hide diffs, never create false blocks. Fine for now; must be closed before enforce is
ever considered.
