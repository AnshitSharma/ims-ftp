# U-SM.1 — status_v2 columns
Concept: state substrate. Pins baseline: no. Invariants: INV-9, INV-11.

## Inputs
database/seeders/2026_06_15_001_add-faildate-column-to-all-inventory-tables.sql (the multi-table
ALTER precedent — copy its structure); 11-verification §schema_report.

## Database Changes (1 seeder + rollback)
2026_07_10_001_add-status-v2-columns.sql:
ALTER server_configurations ADD status_v2 ENUM('draft','building','validating','validated',
'finalized','deployed','maintenance','retired') NULL;
ALTER each of: cpuinventory, raminventory, storageinventory, motherboardinventory, chassisinventory,
nicinventory, caddyinventory, pciecardinventory, hbacardinventory, sfpinventory
ADD status_v2 ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NULL;
Then backfill UPDATEs from legacy ints: config 0→'draft', 3→'finalized' (1,2 unreachable per audit:
map 1→'validated', 2→'building' defensively); inventory 0→'failed', 1→'available', 2→'installed'.
NULL allowed during window; U-SM.3 keeps them synced. Rollback drops the columns.

## Files Modified (1)
scripts/verify/expected_schema.json.

## Tests
Apply+report GREEN+rollback+re-apply on scratch; characterization ZERO diffs; spot SQL: counts per
mapping equal counts per legacy int.

## Checklist
- [ ] All ten tables altered - [ ] Mapping UPDATEs in the same seeder - [ ] Enum values exactly as listed
