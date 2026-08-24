# U-D.4 — Delete flags + temp guards + legacy env flags
Pins baseline: yes (all flags at terminal values ⇒ deletion is identity). Invariants: INV-12, INV-10, INV-11.

## Targets
The five migration flags (readers become constants: dual-write gone with U-D.3, others 'enforce'/'on'
hard-coded then inlined away); TEMP-GUARD(U-0.2) blocks (search marker); legacy flags
PCIE_LANE_CHECK_ENABLED, VALIDATION_PIPELINE_ENABLED, SLOT_AUTHORITY_ENABLED,
STORAGE_CONNECTION_AUTHORITY_ENABLED (their consumer classes died in U-D.2 — this unit greps residue);
legacy int status columns configuration_status + inventory Status? RETAINED (external consumers
unknown) — demoted to generated columns mirroring status_v2 in a FOLLOW-UP seeder listed in
12-post-cutover backlog, NOT here.

## Tests
grep per FLAGS.md table = only FLAGS.md history; grep TEMP-GUARD = empty; characterization ZERO diffs;
full battery GREEN.

## Rollback / Checklist
git revert. - [ ] No getenv in core/models/{validation,commands,config,state} - [ ] FLAGS.md updated: all rows marked deleted with commit sha

## Appendix — marker inventory (migrated 2026-08-24, informational)
Migrated verbatim from the orphaned `scripts/verify/deadcode_targets.json`, which was read by no code
and has been deleted. **This appendix adds no scope and changes no acceptance criterion** — it only
names the markers this pack's existing Targets/Tests sections already refer to collectively, so the
grep sweep has a checklist instead of a memory. `kind: marker` = a literal string that must not
survive; every occurrence outside FLAGS.md history is residue. The deadcode scan CANNOT check these:
`deadcodeReferencePattern()` in `scripts/verify/deadcode_scan.php` understands only `class` and
`method`, which is why markers never lived in `deadcode_manifest.json` and are grepped by hand here.

The five migration flags ("readers become constants") are:
- `DUAL_WRITE_ENABLED` — reader becomes a constant once U-D.3 drops the JSON columns.
- `STATE_MACHINE_ENABLED` — at terminal value 'enforce'; deletion is identity.
- `ENGINE_MODE` — at terminal value 'enforce'; deletion is identity.
- `COMMAND_LAYER_ENABLED` — terminal value NOT yet reached (shadow as of 2026-07-30). Re-check before deleting.
- `READ_FROM_ROWS` — terminal value NOT yet reached (sample as of 2026-07-30). Re-check before deleting.

Legacy flags whose consumer classes die in U-D.2c — this unit greps the residue:
- `PCIE_LANE_CHECK_ENABLED` (consumer: PcieLaneBudgetValidator)
- `VALIDATION_PIPELINE_ENABLED` (consumer: ValidationPipeline)
- `SLOT_AUTHORITY_ENABLED` (consumer: SlotAuthority)
- `STORAGE_CONNECTION_AUTHORITY_ENABLED` (consumer: StorageConnectionAuthority)
- `MEMORY_AUTHORITY_ENABLED` (consumer: MemoryAuthority) — carried by deadcode_targets.json but absent
  from the Targets list above; recorded here so it is not lost. Live residue as of 2026-08-24:
  `core/models/compatibility/MemoryAuthority.php`.
- `STORAGE_BAY_AUTHORITY_ENABLED` — likewise absent from the Targets list above. Live residue as of
  2026-08-24: `core/models/compatibility/StorageConnectionAuthority.php` AND
  `core/models/validation/rules/StorageInterfacePathRule.php`. The rule-file hit is a LIVE
  replacement-side reader, not residue — resolve it by hand before treating this marker as clearable.

Guard marker:
- `TEMP-GUARD` — the U-0.2 temporary guards. Every occurrence is residue; the grep must come back empty.
