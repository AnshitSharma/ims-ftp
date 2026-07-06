# U-A.1 — Route add/remove through commands + deprecation
Pins baseline: yes (response-shape golden fixtures). Invariants: INV-2, INV-11.

## Inputs
server_api.php 325–700 (handleAddComponent/handleRemoveComponent full);
Add/Remove command dispatch blocks (U-C.2/U-C.3 modified sites — you are consolidating them);
tests/getDashboardDataShapeTest.php (house response-shape test style).

## Files Modified (1) / Created (2)
server_api.php: delete the API advisory validation block (audit A-4's third run — commands validate
authoritatively now; the block's only residue, validationWarnings, comes from Verdict via shim);
delete handler-level SFP auto-assign SQL (A-11 second half — U-C.2's command owns SFP placement);
both handlers: parse → ACL → dispatch command → shim(response). Add header
`X-IMS-Deprecation: action superseded by v2 commands, see migration/08-api-adapters/DEPRECATION.md`.
CREATE tests/api/add_remove_response_shape_test.php (golden fixtures captured pre-change from scratch)
+ 08-api-adapters/DEPRECATION.md (timetable, replacement actions).

## Tests
shape test PASS (documented-stable fields byte-equal); characterization ZERO verdict diffs;
grep: advisory block + SFP SQL gone from handler.

## Rollback / Checklist
git revert. - [ ] No SQL left in either handler - [ ] Warnings still surface (from Verdict) - [ ] Header present
