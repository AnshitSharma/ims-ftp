# U-SM.3 — StateMachine service + legacy sync
Concept: one transition owner. Pins baseline: yes (zero diffs; service has no enforcing callers yet).
Invariants: INV-2(spirit), INV-11.

## Inputs
U-SM.1/U-SM.2 packs; core/models/server/ServerBuilder.php 3527–3600 (finalize status write site);
core/models/server/ServerBuilder.php 5130–5215 (updateComponentStatusAndServerUuid — inventory write site);
core/auth/ACL.php 1–60 (hasPermission signature).

## Files Created (2) / Modified (2)
core/models/state/StateMachine.php — `assertConfigTransition(PDO,$configUuid,$to,$userId): array{allowed,requires_validation,reason}`
(reads current status_v2 FOR UPDATE-compatible: caller holds the lock; method itself never locks),
`applyConfigTransition(...)` writes status_v2 AND legacy int via REVERSE map
(draft→0, building→2, validating→2, validated→1, finalized→3, deployed→3, maintenance→3, retired→3 —
legacy vocabulary is smaller; document lossy reverse map in docblock), appends config_events('transition');
same pair for inventory (`assertInventoryTransition`, `applyInventoryTransition`).
core/models/state/StatusMap.php — both maps as consts (single source).
MODIFY ServerBuilder: finalizeConfiguration's UPDATE → applyConfigTransition(finalized);
updateComponentStatusAndServerUuid's Status writes ALSO write the mapped status_v2 (sync only, no
assertion yet — enforcement is U-SM.4). MODIFY inventory_report.php: add mapping-agreement check.

## Tests
unit test for both machines (legal/illegal transitions, failed→available rejected);
characterization ZERO diffs; inventory_report GREEN incl. new check on scratch.

## Checklist
- [ ] Service never opens transactions - [ ] Lossy reverse map documented - [ ] Sync writes in same statements/tx as legacy writes
