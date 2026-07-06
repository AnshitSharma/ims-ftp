# U-B.2 — Extractors: ten JSON shapes → rows
Concept: legacy-quirk canonicalization (the highest-knowledge unit of the migration; go slowly).
Pins baseline: no. Invariants: INV-1, INV-11.

## Inputs (Files To Read — largest read set in the plan; ~2.6k lines)
- core/models/server/ServerBuilder.php 61–260 (extractComponentsFromJson — the authoritative shape decoder; MIRROR it, do not import it: backfill must not depend on ServerBuilder)
- core/models/server/ServerBuilder.php 2447–2560 (cpu entry shape incl. quantity + serial rules)
- core/models/server/ServerBuilder.php 2920–2985 (hba triple-format incl. legacy scalar)
- core/models/compatibility/OnboardNICHandler.php 1–100 (onboard NIC uuid scheme 'onboard-…', SourceType)
- scripts/verify/equivalence_report.php (the canonical tuple you must satisfy; flip TODO_UB2=true here)

## Files Created (1) / Modified (2)
scripts/backfill/Extractor.php — per JSON column, rules (each returns rows or Quarantine):
cpu/ram entries with quantity Q>1 ⇒ expand to Q rows ONLY when Q distinct inventory serials with that
spec_uuid and ServerUUID=this config exist; else quarantine reason 'quantity-without-serials' (audit
A-2/E-4 data — humans resolve). serial resolution: entry serial → inventory row by (UUID, SerialNumber);
serial-less entry → unique inventory row by (UUID, ServerUUID=config) else quarantine 'ambiguous-serial'.
pciecard subtype Riser Card OR uuid prefix riser- ⇒ component_type 'riser'. hbacard: array > single-object
> scalar fallback order exactly as updateHbaCardConfiguration. onboard NICs ⇒ rows with parent_id =
motherboard row, inventory pointer to nicinventory SourceType='onboard'. sfp ⇒ parent_id = parent_nic
row via parent_nic_uuid, slot_ref 'port_'.port_index. storage: slot_ref bay from connection JSON if
present else NULL (ledger consumer link then skipped, reason logged not quarantined). parent_id edges:
cpu/ram/riser→motherboard row; cards→riser row when slot_ref is riser-provided else motherboard.
Modified: backfill.php (swap stub for Extractor), equivalence_report.php (TODO_UB2 flip).

## Tests
```
php tests/backfill/extractor_test.php    # NEW (counts in box): fixture JSON per quirk → expected rows/quarantines (≥10 cases incl. every quirk above)
php scripts/backfill/backfill.php --execute --config <full-fixture> && php scripts/verify/equivalence_report.php --config <full-fixture>   # exit 0
```

## Rollback / Checklist
--rollback-run; git revert. - [ ] No guessing: every unmatched shape quarantines with a distinct reason
- [ ] Quantity expansion rule exactly as specified - [ ] Riser retyping consistent with equivalence consts
