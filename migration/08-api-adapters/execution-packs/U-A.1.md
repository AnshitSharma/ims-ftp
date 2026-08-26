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

---

## PROPOSED AMENDMENT — UNAPPROVED, owner decision required (raised 2026-08-26)

Three of this pack's acceptance criteria are **unsatisfiable as written** at the current flag
state. They are recorded here rather than self-certified around. Do not mark U-A.1 `verified`
until the owner rules on this section.

### 1. "No SQL left in either handler" — unsatisfiable
Measured 2026-08-26 against the deployed source. SQL remaining:

| Site | What it is | Why it is still there |
|---|---|---|
| `api/handlers/server/server_api.php:1026` | `SELECT revision FROM server_configurations` | 409 `revision_mismatch` responder inside the `CommandFailed` catch. Post-dates this pack. |
| `api/handlers/server/server_api.php:1065,1092,1100` | SFP auto-assign: `SELECT sfp_configuration`, `UPDATE sfpinventory`, `UPDATE server_configurations` | Retained by the **owner's 2026-07-12 decision**. |
| `api/handlers/server/server_api.php:1377` | `SELECT revision FROM server_configurations` | Same 409 responder in `handleRemoveComponent`. |

The SFP half was superseded by owner decision. The two revision `SELECT`s did not exist when this
criterion was written and belong to the optimistic-concurrency work, not to command routing.

**Proposed replacement criterion:** *"No component-placement or inventory-mutating SQL remains in
either handler. The 409 `revision_mismatch` responder's `SELECT revision` is permitted in both
handlers, and the SFP auto-assign block is permitted per the 2026-07-12 owner decision — both are
named exemptions, and any SQL beyond them fails this criterion."*

### 2. "delete the API advisory validation block" — superseded, and unmeetable at the live flag state
Not deleted. `server_api.php:851-862` documents that U-A.1 was **redesigned per owner decision** to
gate the block on `CommandLayer::mode() === 'enforce'` rather than delete it, because at `off`/
`shadow` the legacy `addComponent()` path is still the enforcing/persisting one and dropping the
block would silently remove the only pre-transaction `validationWarnings` users receive.
`COMMAND_LAYER_ENABLED` is `shadow` in production, so the block legitimately still runs.

**Proposed replacement criterion:** *"The advisory validation block is skipped when
`CommandLayer::mode() === 'enforce'` and retained otherwise, mirroring U-C.5's precedent in
`handleFinalizeConfiguration`."* Verified present as written on 2026-08-26.

### 3. The shape test cannot currently supply evidence
`tests/api/add_remove_response_shape_test.php` reports `RAN NOTHING (declared)` without
`IMS_HTTP_HARNESS_URL`. This is now honest (the marker was added 2026-08-24), but it means this
pack has **no executed acceptance artifact**. Promotion additionally requires one run against a
scratch-only `php -S` harness.

### Criteria that ARE met (evidence, 2026-08-26)
- `X-IMS-Deprecation` header present in both handlers — `server_api.php:766` and `:1284`.
- `migration/08-api-adapters/DEPRECATION.md` exists.
- Warnings still surface from Verdict — `server_api.php:916,925,1131-1132`.
