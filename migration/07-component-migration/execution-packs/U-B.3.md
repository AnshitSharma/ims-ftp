# U-B.3 — Ledger backfill
Concept: capacities/links for historical rows. Pins baseline: no. Invariants: INV-8, INV-11.

## Inputs
scripts/backfill/backfill.php + Extractor.php; core/models/config/ResourceCatalog.php;
06-resource-ledger/execution-packs/U-L.2.md (provider/consumer semantics incl. RV-1).

## Files Modified (1) / Created (1)
backfill.php: after component rows per config, second pass same tx: providers via catalog for every
provider-type row; consumer links where slot_ref known; scalar lane consumption via consumes().
CatalogException ⇒ config state 'error' (resumable), NOT quarantine (spec fixable). RV-1 stays open
(dimm links absent) — ledger_report already tolerates NULL-consumer dimm rows.
CREATE tests/backfill/ledger_backfill_test.php: fixture config → expected provider/consumer/scalar rows.

## Tests
fixture test PASS; ledger_report + slot_report GREEN on fixture; resume after induced error works.

## Rollback / Checklist
--rollback-run cascades (providers via component delete). - [ ] error≠quarantine distinction respected
