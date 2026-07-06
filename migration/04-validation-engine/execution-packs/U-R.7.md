# U-R.7 — System/config rule family
RULE_MAP: system.*. Invariants: INV-2, INV-7, INV-11, PD-1.

## Inputs
ServerBuilder 6541–6608 (required set + singletons — the surviving list);
ServerBuilder 5698–5890 (checkPowerCompatibilityDetailed + getChassisPsuWattage — port math);
ServerBuilder 3225–3245 (inventory-status non-blocking site — audit V-2);
inventory status vocabulary: core/models/state/StatusMap.php.

## Files Created
rules/{SystemRequiredSetRule (VF), SystemSingletonRule (E), SystemPsuCapacityRule (E),
SystemInventoryStateRule (E)}.php + tests/unit/rules/system_rules_test.php.
PsuCapacity: Σ consumed psu_watt (catalog consumes for every powered type — extend consumes() here,
Files Modified: ResourceCatalog.php within box) vs chassis psu_watt capacity.
InventoryState: any TargetState row whose inventory status_v2 ∈ {failed, retired, maintenance} ⇒ E
(V-2 closes). Triggers: all four VALIDATE+FINALIZE; Singleton also ADD+REPLACE.

## Tests / Checklist
Fixtures: defective drive blocks FINALIZE; PSU over-draw blocks VALIDATE (expected diffs cite V-2, V-4).
- [ ] ONE required list (comprehensive's six) - [ ] Power math equals legacy checkPowerCompatibilityDetailed on fixtures
