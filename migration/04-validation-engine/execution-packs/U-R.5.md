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

---

## PROPOSED AMENDMENT — UNAPPROVED, owner decision required (raised 2026-08-26)

### "Parity diffs cite A-10/H9-class" — structurally unobtainable at `ENGINE_MODE=enforce`
This pack's remaining acceptance criterion asks for engine-vs-legacy parity diffs citing the A-10
and H9 classes. Those diffs are read by `scripts/verify/parity_report.php` out of
`reports/shadow/engine-<Ymd>.jsonl`. **Nothing writes that stream at `enforce`.**

Evidence, read 2026-08-26 in the deployed source:

- `core/models/server/ServerBuilder.php:5645` — the `ShadowRunner::record(...)` call at `:5650`
  sits inside `if ($mode === 'shadow')`. The `enforce` path falls through to `:5659`
  `return ShadowRunner::mapVerdictToLegacyResult($verdict);` and records nothing.
- `ServerBuilder.php:5638-5644` states the consequence outright: *"no shadow comparison row at
  `ENGINE_MODE=enforce` ... A real loss, but at enforce the engine is already the sole authority
  ... No synthetic row is written -- recording legacy as 'allowed' when it was never evaluated
  would fabricate divergences against an engine block and corrupt the parity denominator."*
- `ENGINE_MODE` is `enforce` in production (`ims-ftp/CLAUDE.md`; flag state recorded from the
  2026-08-19 session onward).

So the criterion cannot be satisfied without flipping a production flag back to `shadow` and
soaking real traffic. Refusing to write a synthetic row is **correct** engineering — the criterion,
not the code, is what is out of date.

**Proposed resolution — owner picks one:**
1. **Retire the criterion.** Accept the A-10/H9 classes as already-registered expected diffs from
   the pre-`enforce` parity window and mark U-R.5's parity obligation discharged by that historical
   evidence, naming the specific `reports/parity-*.json` relied on.
2. **Re-open a shadow window.** Flip `ENGINE_MODE=shadow` for a defined soak, collect
   `engine-<Ymd>.jsonl`, run `parity_report.php`, then restore `enforce`. This is a live
   behavioural change on a production validation path and is an owner action, not an agent action.

Option 1 is recommended; option 2 buys a fresh denominator at real risk.

### Criterion that IS met (evidence, 2026-08-26)
- `tests/unit/rules/storage_rules_test.php` — **exit 0, ALL PASS**, run with `IMS_DATA_PATH` set.
  Covers the F-11 SAS-path cases, the F-24 add-time-vs-validate-time split, all four fixture
  guards, and the INV-1 no-`quantity` pin on all four rule files. This **discharges** the
  2026-08-19 `HONEST_LIMIT` ("RUN storage_rules_test.php IN AN ENVIRONMENT WITH ims-data BEFORE
  TRUSTING THE ENGINE HALF"), independently reproducing the 2026-08-24 result.

U-R.5 therefore stays `implemented` on the parity criterion alone.
