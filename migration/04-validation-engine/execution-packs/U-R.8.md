# U-R.8 — Dependency resolver rule
RULE_MAP: dependency.blocked_removal. Invariants: INV-1, INV-2, INV-7, INV-11, PD-1.

## Inputs
target-design DEPENDS_ON map (quoted below — complete, no other source needed):
cpu→motherboard; ram→motherboard; riser→motherboard; sfp→nic; pciecard|hbacard|nic→motherboard|riser;
caddy→chassis; storage→hbacard|backplane|motherboard(m2,u2); motherboard→chassis(form_factor).
ConfigComponentRepository (parent_id closure query); U-V.2 TargetStateBuilder.withRemove.

## Files Created (2) / Modified (2: registry + TargetStateBuilder)
rules/DependencyBlockedRemovalRule.php — trigger REMOVE(+REPLACE for the outgoing side): if
withRemove(cascade=false) leaves rows whose parent_id chain or consumed resources pointed at the
removed subtree ⇒ E listing dependents (uuid+type each). With cascade=true the builder already
removed the subtree ⇒ rule passes and the CASCADED state is what all other rules evaluate.
TargetStateBuilder: add dependentsOf(rowId) (recursive closure over parent_id + resource consumer
links — a PHP loop over the in-memory state, NOT SQL CTE, keeping the builder pure).
tests/unit/rules/dependency_rule_test.php — every §6 scenario from the audit: board-with-cpus,
cpu-with-ram, hba-with-drives, riser-with-cards, chassis-with-bays, nic-with-sfps (parity with the
one legacy check).

## Tests / Checklist
All six scenarios block without cascade, pass with cascade + downstream rules re-evaluated.
Parity: REMOVE ops have no legacy engine hook yet (arrives U-C.3) — unit tests are the gate here;
note this in the handoff. - [ ] Resolver pure - [ ] Dependents list in RuleResult details (API shows it)
