# U-R.2 — Memory rule family
As U-R.1 pattern. RULE_MAP rows: memory.*. Invariants: INV-2, INV-7, INV-11 (+PD-1 exemption).

## Inputs
RULE_MAP memory rows; ServerBuilder 3815–3991 (validateRAMAddition full — the richest legacy source);
ComponentValidator 730–980 (type/formfactor/ecc/slots); MemoryAuthority.php (127 lines, full);
tests/memory_authority_unit.php (port its cases).

## Files Created
rules/{MemoryTypeRule, MemoryFormFactorRule, MemorySlotCountRule, MemoryEccRule, MemoryDownclockRule}.php
+ tests/unit/rules/memory_rules_test.php (6 files + registry per PD-1).
SlotCount: rows vs resources(dimm_slot) capacity. Downclock: W with effective-frequency detail
(preserve the response-enrichment data legacy exposed — Verdict details carry it; API shim U-A.3 maps it).

## Tests / Rollback / Checklist
As U-R.1; parity expected diffs: three-impl slot count unification (cite D4). Port every
memory_authority_unit case; that legacy test keeps passing untouched until U-D.2.
