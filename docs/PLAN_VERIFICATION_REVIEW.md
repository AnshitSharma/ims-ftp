# PLAN VERIFICATION REVIEW — independent audit of this migration plan
Role: verification agent, adversarial pass over the blueprint before any implementation session runs.
Every finding is either FIXED (patched into the packs) or ACCEPTED (documented residual risk with owner).

## Findings

**F-1 — Line-number drift (FIXED by convention).** Packs cite line numbers from commit-of-audit; earlier
units shift later units' targets. Mitigation baked into SESSION_PROTOCOL Step 2.3: every pack pairs
line ranges with a textual description and, where a later unit must find a site created earlier, a
grep-able marker (`TEMP-GUARD(U-0.2)`, `TODO_UB2`, flag names). If range and description disagree ⇒
blocked, never guess. Weak-model safe.

**F-2 — Folder numbering ≠ execution order (FIXED).** The mandated folder list puts resource-ledger
(06) and component-migration (07) after command-layer (05), but the ledger must exist before backfill
and backfill before state-machine/engine work (rules read ledger rows; guards read rows). The
execution-order table in 00-overview/README.md and phase-status.json are declared authoritative;
folders are namespaces. Hidden dependency eliminated by making order explicit in two machine-checkable
places.

**F-3 — Dual-write remove resolution (VERIFIED OK).** U-1.5's afterLegacyRemove must find the row to
tombstone without inventory id in scope; resolved via repository findLive(config,type,spec,serial) —
matches removeComponent's own serial-extraction, no new assumption.

**F-4 — Evidence gap days 15–30 before U-D.3 (FIXED).** U-X.2's battery covered 14 days; U-D.3
demands 30 days of daily equivalence. Patched: U-X.2 installs the daily cron; U-D.3 cites it.

**F-5 — Virtual configs (FIXED).** Virtual configs bypass duplicate/JSON/inventory checks by design
(audit E-5); backfilling them would violate INV-1 (fake inventory ids) and poison equivalence.
Patched: backfill and equivalence exclude is_virtual=1. Residual: virtual flows stay legacy-only
until a sandbox design lands (BACKLOG via U-P.2). ACCEPTED, owner: backlog.

**F-6 — No hard FK to ten inventory tables (ACCEPTED).** config_components carries
(inventory_table, inventory_id) with orphan_report as the soft guard. Hard FK requires inventory
unification — deliberately out of scope (it would double the plan). Backlog item with explicit
risk note: orphan_report is a detection control, not a prevention control, for this one edge.

**F-7 — Unit-box exemptions (DOCUMENTED as plan deviations).**
PD-1: U-R.* units touch ValidationEngine.php's RULES const (+1 file over box). Registry-line-only
change; exempted.
PD-2: U-D.2 split into a/b/c/d sub-sessions (deletion surface too big for one box).
PD-3: U-D.3 split into a/b. Also INV-9 satisfied by a restore PROCEDURE (not SQL) for the column
drop — the only unit where a mechanical SQL rollback is impossible by nature.

**F-8 — Context-window audit (VERIFIED).** Per-pack budget = protocol files (~4k tokens) + pack
(~1–2k) + listed reads. Largest read sets: U-B.2 ≈ 2.6k lines ≈ 33k tokens → ~38k total; U-C.2 ≈ 30k;
all others ≤ 30k. Everything fits a Sonnet session with ≥ 2× headroom for work/output. Rule enforced
structurally: no pack lists ServerBuilder whole; all reads are ranged.

**F-9 — Rollback risk concentration (ACCEPTED, mitigated).** Only U-D.3 is irreversible-by-SQL.
Mitigations: 30-day green evidence, restore-tested backup, 90-day retention, split into two
sub-sessions, human sign-off line. Everything earlier rolls back by flag (minutes) or revert.

**F-10 — Unstated assumptions (NOW STATED).**
A-1: scratch DB is constructible from tests/golden/setup_scratch_db.sql on every dev/CI host.
A-2: ims-data spec directory is reachable from the app path in every environment where flags ≥ shadow.
A-3: virtual configs are sandbox data (see F-5).
A-4: multiple PHP-FPM nodes share one MySQL; row locks are the concurrency truth. The file-based
configCache is per-node — afterCommit invalidation fixes same-node staleness; cross-node staleness
is a pre-existing condition, unchanged by this migration, backlog candidate.
A-5: permission strings in U-SM.2/U-A.2 must exist in the ACL vocabulary; packs instruct checking
api/permission_map.php when in doubt.
A-6: production replica exists for U-B.4 and nightly batteries.

**F-11 — quantity>1 during the window (VERIFIED OK).** U-A.2 maps N>1 to N nested command dispatches
under one outer transaction — legal because BaseCommand is nestable (U-C.1) and INV-3 counts
BaseCommand as the sole owner regardless of nesting depth.

**F-12 — Zero-sample parity green-washing (FIXED in contract).** parity_report prints a loud warning
at 0 ops; gate discipline addition: any parity-gated phase must show ops ≥ the fixture-scenario
count from tests/fixture_scenarios_real.php replayed on scratch. Added here as a gate rule; U-V.4's
warning is the runtime tell.

**F-13 — Phase-size review (VERIFIED).** All units ≤ 5 files / ≤ 500 LOC / ≤ 1 seeder / 1 concept
after PD-1..3. U-B.2 is the knowledge-heaviest unit and was checked twice: it stays one concept
(extraction canonicalization) with ten declarative rules — splitting it would smear one concept
across sessions, the worse drift risk.

**F-14 — Cross-session drift control (VERIFIED).** The three drift vectors are covered:
(1) vocabulary drift → value objects land first (U-V.1) and rules import them;
(2) verdict drift → parity after every U-R.* with expected_diffs.json requiring audit-finding ids;
(3) protocol drift → phase-status.json single writer rule + handoff files + CI invariants (U-P.1).

## Verdict
With F-1..F-14 resolved or accepted as documented, the plan is executable by low-capability sessions:
every step has bounded context, mechanical checks, a rollback, and no unstated knowledge on the
critical path. Implementation may begin at U-0.1.
