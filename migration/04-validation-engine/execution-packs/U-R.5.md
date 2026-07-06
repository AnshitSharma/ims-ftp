# U-R.5 — Storage rule family
RULE_MAP: storage.*. Invariants: INV-2, INV-7, INV-11, PD-1. LARGEST legacy surface — logic only,
not code: port decisions, discard plumbing.

## Inputs
StorageConnectionAuthority.php (139 lines, full); StorageConnectionValidator.php 1–200 ONLY
(entry contract; its 2009 lines of path plumbing are replaced by ledger lookups);
ComponentCompatibility 3076–3260 (chassis bay/form-factor decisions);
ServerBuilder 7833–7873 (bay quantity) + 1875–1935 (M.2 read-time warning — audit A-10);
tests/storage_bay_authority_unit.php (port cases).

## Files Created
rules/{StorageInterfacePathRule, StorageBayCapacityRule, StorageM2CapacityRule, StorageCaddyPairingRule}.php
+ tests/unit/rules/storage_rules_test.php.
InterfacePath: a live path exists in TargetState resources (drive consumes drive_bay_*/m2_slot/u2_slot
or an sfp-less HBA-connected bay chain) matching the drive's interface via catalog; no stored path —
derived, so the H9 stale class is structurally out. M2Capacity: rows vs m2_slot capacity, severity E
(A-10 expected diff). CaddyPairing: VF per RULE_MAP.

## Tests / Checklist
Port authority unit cases + A-10 fixture (M.2 over-population blocks at ADD). Parity diffs cite
A-10/H9-class. - [ ] No stored connection strings read or written - [ ] 2.5/3.5 strict matching preserved exactly (both bay_type spellings, see CC:3195)
