# ARCHITECTURAL INVARIANTS

These rules may NEVER be violated by any implementation unit, in any phase, by any AI session.
Every execution pack requires you to re-check the invariants it touches before marking a unit complete.
If an instruction in a pack appears to conflict with an invariant: STOP. Do not implement. Record the
conflict in the handoff file and end the session. A human resolves it.

Each invariant has an ID, a statement, and a MECHANICAL CHECK (a command a weak model can run
without judgment). If the check fails, the invariant is violated.

---

## INV-1 — One physical unit = one row
A physical component (identified by inventory row / serial) appears at most once, in at most one
configuration, ever. Quantity fields must not exist in new code.

CHECK:
```
grep -rn "'quantity'" core/models/config/ core/models/commands/ core/models/validation/ ; # must return nothing
mysql: SELECT inventory_type, inventory_id, COUNT(*) c FROM config_components GROUP BY 1,2 HAVING c > 1; # must return 0 rows
```

## INV-2 — Validation has exactly one owner
Every business rule exists in exactly one Rule class under `core/models/validation/rules/`.
No new validation logic may be added to ServerBuilder, API handlers, or ComponentCompatibility.

CHECK:
```
git diff --stat <base>..HEAD -- core/models/server/ServerBuilder.php api/handlers/ core/models/compatibility/ComponentCompatibility.php
# During Phases 4-9 these files may only SHRINK or gain delegation calls, never gain new if/else validation logic.
```

## INV-3 — Commands are the only transaction owners (target state)
After Phase 6 cutover, `beginTransaction` may appear only in `core/models/commands/BaseCommand.php`,
the backfill script, and test bootstrap.

CHECK:
```
grep -rln "beginTransaction" core/ api/ scripts/ | grep -v -e commands/BaseCommand.php -e scripts/backfill -e tests/
# After U-C.6: must return nothing. Before U-C.6: must not return any FILE CREATED by this migration other than BaseCommand.
```

## INV-4 — Finalized/deployed configurations are immutable
No mutation path may write to a configuration whose status is finalized (legacy int 3) or, after
Phase 3, whose status_v2 ∉ {draft, building, maintenance}. The guard lives INSIDE the locked
transaction, never only at the API layer.

CHECK: run `tests/regression/finalized_immutability_test.php` (created in U-0.2). Must pass in every phase.

## INV-5 — All validation is fail-closed
A thrown exception inside any validation path aborts (rolls back) the operation.
The strings "Continue without" / "advisory" / "don't block on validation" may not exist in mutation paths.

CHECK:
```
grep -rn "Continue without\|Continue with addition\|don't block on validation" core/ api/  # must return nothing after U-0.1
```
Plus `tests/regression/fail_closed_test.php` must pass in every phase.

## INV-6 — All mutations bump the revision
Every write to config_components / config_resources / server_configurations component state
increments `server_configurations.revision` in the same transaction and appends one `config_events` row.

CHECK:
```
mysql: SELECT c.config_uuid FROM server_configurations c
       LEFT JOIN (SELECT config_uuid, MAX(revision) r FROM config_events GROUP BY 1) e USING (config_uuid)
       WHERE c.revision <> COALESCE(e.r, 0);   # must return 0 rows (after U-1.3)
```

## INV-7 — Severity is a property of the rule
Each Rule class declares exactly one severity (ERROR | VALIDATION_FAILURE | WARNING).
No call site may reinterpret, downgrade, or upgrade severity. No env var may change an ERROR rule's behavior.

CHECK:
```
grep -rn "getenv\|_ENV" core/models/validation/rules/   # must return nothing
```

## INV-8 — Dual-write windows never fork silently
While legacy JSON and new rows coexist, the equivalence checker must report zero diffs before any
phase gate. A diff is a blocking defect, never a "known issue".

CHECK: `php scripts/verify/equivalence_report.php --all` exits 0.

## INV-9 — Migrations are paired
Every seeder `database/seeders/<name>.sql` created by this migration ships a working
`database/seeders/rollback/<name>_rollback.sql` in the same unit. No exceptions.

CHECK:
```
for f in database/seeders/2026_0[7-9]*_*.sql; do test -f "database/seeders/rollback/$(basename ${f%.sql})_rollback.sql" || echo "MISSING: $f"; done
# must print nothing
```

## INV-10 — Legacy behavior is pinned before it is changed
No unit may modify a legacy verdict-producing path unless the characterization suite
(`tests/characterize_compatibility.php` against `tests/golden/compatibility_baseline.json`) passed
on the commit immediately before the change. Intentional verdict changes require a baseline update
committed WITH a `BASELINE-CHANGE:` note in the commit message explaining which audit finding it closes.

## INV-11 — No unit exceeds its box
Max per unit: 5 files changed, 500 new/modified LOC, 1 seeder, 1 architectural concept.
If you discover mid-unit that the box will be exceeded: STOP, split the unit in the handoff notes,
do not "finish quickly".

## INV-12 — Flags are rollout scaffolding, not architecture
New flags allowed: DUAL_WRITE_ENABLED, STATE_MACHINE_ENABLED, ENGINE_MODE, COMMAND_LAYER_ENABLED,
READ_FROM_ROWS. Each has exactly the values listed in `00-overview/FLAGS.md`, defaults OFF, and a
scheduled deletion unit in `10-cleanup/`. Creating any other flag is an invariant violation.

---

## How every session validates invariants
1. Before starting: read this file completely (it is short by design).
2. After implementing: run the CHECK block of every invariant listed in your pack's
   "Invariants touched" section.
3. Record PASS/FAIL per invariant in your handoff file.
4. Any FAIL ⇒ revert your changes using the pack's rollback section, record the failure, end session.
