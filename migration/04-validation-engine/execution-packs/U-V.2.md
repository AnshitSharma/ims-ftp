# U-V.2 — TargetStateBuilder
Concept: rules evaluate proposed states, never live DB. Pins baseline: no. Invariants: INV-11.

## Inputs
core/models/config/ConfigComponentRepository.php (liveRows); core/models/config/ResourceCatalog.php;
core/models/server/ServerBuilder.php 61–120 (JSON extraction OUTPUT SHAPE, fallback path only).

## Files Created (2)
core/models/validation/TargetState.php — immutable: components(): rows (canonical tuple + parent_id +
slot_ref), resources(): ledger view, byType(), find(); constructed ONLY via builder.
core/models/validation/TargetStateBuilder.php —
fromCurrent(PDO,$configUuid) [rows primary; if zero rows AND legacy JSON non-empty ⇒ JSON fallback
via a private mirror of the canonical tuple mapping, flagged 'source'=>'json' so parity can segment],
withAdd(row), withRemove(componentRowId, cascade: bool=false → uses parent_id closure),
withReplace(oldId, newRow). Pure array math; NO DB writes; resource deltas recomputed via catalog.

## Tests
tests/unit/target_state_test.php: fromCurrent on dual-written fixture == JSON fallback on same
fixture (tuple-equal); withReplace produces state where old absent+new present atomically;
withRemove(cascade) pulls the parent_id subtree.

## Rollback / Checklist
Delete. - [ ] Builder never writes - [ ] Fallback tagged by source - [ ] Replace = single state (no intermediate)
