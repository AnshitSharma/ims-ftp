# U-C.1 — BaseCommand (the transaction owner)
Concept: INV-3 substrate + INV-6 enforcement point. Pins baseline: no (no callers).
Invariants: INV-3, INV-4, INV-5, INV-6, INV-11.

## Inputs
SESSION skeleton from target design §6 (quoted): BEGIN → lock config → state guard → revision match
→ TargetState → evaluate → blocking? rollback : apply → revision+event → COMMIT → afterCommit hooks.
core/models/state/StateGuard.php; core/models/validation/{ValidationEngine,TargetStateBuilder}.php;
ConfigComponentRepository; ServerBuilder 425–440 (lockAndLoadConfigRow to replicate — commands get
their own copy; they must not depend on ServerBuilder).

## Files Created (2)
core/models/commands/BaseCommand.php — final skeleton method execute(): exactly the sequence above;
abstract hooks: trigger(): Trigger; buildTarget(TargetStateBuilder,$lockedRow): TargetState;
apply(PDO,TargetState): void; afterCommit(): void (cache invalidation ONLY — the single site, closes E-1).
Revision check: optional expectedRevision (null = skip, legacy adapters pass null; U-A.2 exposes If-Match).
ownTransaction pattern (nestable). ANY throwable ⇒ rollback + rethrow as CommandFailed (fail-closed).
tests/unit/base_command_test.php — fake command: happy path writes event+revision; blocking verdict
rolls back everything; exception rolls back; revision mismatch ⇒ 409-class failure.

## Tests
unit PASS; grep INV-3 pre-state: `grep -rln beginTransaction core/models/commands/` → only BaseCommand.

## Rollback / Checklist
Delete. - [ ] Sequence order exactly as quoted - [ ] afterCommit is the only cache site - [ ] No SQL outside repository+lock helper
