# U-L.2 — Ledger dual-writer
Concept: ledger stays consistent with rows. Pins baseline: yes (flag off ⇒ zero diffs).
Invariants: INV-5, INV-8, INV-11.

## Inputs (Files To Read)
- core/models/config/ConfigComponentWriter.php (extend it)
- core/models/config/ResourceCatalog.php
- 02-schema/execution-packs/U-1.2.md (ledger semantics: providers consumer_id NULL; scalar consumption rows)

## Files Modified (2) / Created (1)
ConfigComponentWriter.php: inside afterLegacyAdd (same tx): if the type PROVIDES (catalog non-empty)
insert provider rows (provider_id = new component row id). If the type CONSUMES a discrete resource
and slot_ref known (card slot, dimm implicit? NO — dimm slot_ref unknown legacy-side, leave NULL,
recorded as review item RV-1 in ledger_report) link consumer_id on the matching provider row
(UPDATE ... SET consumer_id=? WHERE config_uuid=? AND slot_ref=? AND consumer_id IS NULL).
Scalar consumption (pcie_lane): insert consumption row capacity=lanes from spec via catalog
`consumes()` (ADD this method: returns [['resource','amount']] for cpu-lane consumers nic/hba/pciecard/nvme).
afterLegacyRemove: tombstone consumer links (consumer_id back to NULL / delete consumption rows);
provider rows die via ON DELETE CASCADE only at hard delete — during tombstone window, explicitly
delete provider rows of the tombstoned component (document why: FK cascades don't fire on soft delete).
CatalogException ⇒ propagate (fail-closed) ONLY when flag=on.
CREATE tests/regression/ledger_dual_write_test.php: add board→provider rows appear; add nic with
slot→consumer link + lane consumption; remove nic→link cleared; induced catalog failure rolls back all.

## Tests
flag-off characterization zero diffs; flag-on regression test PASS; ledger sums sane on fixture.

## Rollback / Checklist
git revert. - [ ] All writes same tx - [ ] Soft-delete provider cleanup handled explicitly - [ ] RV-1 (dimm consumer links deferred to backfill) noted in code comment
