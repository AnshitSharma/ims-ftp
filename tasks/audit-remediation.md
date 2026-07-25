# Audit remediation — code review / system optimization

Branch: `claude/code-review-system-optimization-f0tkoh`

Source: deep-dive audit of the server build + compatibility engine.
Numbering matches the audit report (L#, E#, P#).

---

## High Priority — incorrect results / data corruption

- [x] H1 (L1) `lockAndCheckComponent()` picks FAILED units first — `ORDER BY Status ASC`
      → locked `WHERE Status = 1 ... LIMIT 1 FOR UPDATE`. Also closes E1 (unlocked
      serial pre-resolution TOCTOU) and the model-wide lock amplification.
- [x] H2 (L7) `replaceOnboardNIC()` marks every unit of a NIC model in-use;
      unconditional beginTransaction/rollBack.
- [x] H3 (L4) Removal filters delete ALL same-model entries (ram/storage/cpu).
- [x] H4 (L3) `quantity > 1` writes N to JSON but reserves ONE physical unit.
- [x] H5 (L5) Second unit of a serial-less model can never be added.
- [x] H6 (L6) Post-commit side effects mutate config outside txn + stale cache.
- [x] H7 (L2) `updateConfigurationCalculatedFields()` arity mismatch; dead
      `CompatibilityEngine` gate — compatibility_score never persisted.

## Medium Priority — harden & optimize

- [x] M8  (P1) Thread `$lockedConfigRow` through `addComponent()` — kill ~10 redundant reads.
- [x] M9  (L9) `assignComponentSlot()` catch → fail closed.
- [x] M10 (L8) Sum `quantity` not `count()`; fix `$existingRam` string interpolation.
- [x] M11 (L10) Unify slot-size derivation on `extractPCIeSlotSize()`; drop duplicate fallbacks.
- [x] M12 (P3) `ValidationPipeline::run()` — return null in shadow BEFORE invoking authorities.
- [x] M13 (P2) `getCompatibleComponents()`: batch N+1, fix failed-first order,
      gate `$debugInfo`, scope `inventory_summary`.
- [x] M14 (E3) `register_shutdown_function` for fatals; widen catch to `\Throwable`.
- [x] M15 (E2) Hard-fail on malformed JSON in mutation/finalize paths.
- [x] M16 (P4) Bulk-UPDATE release in `deleteConfiguration()`.
- [x] M17 (E6) Roll back motherboard removal when onboard-NIC detach fails.
- [x] M18 (L15) Scope the `auth_tokens` write to the presented token.
- [x] M19 (L14/E7) `random_bytes()` UUID; clamp `$quantity` upper bound.

## Low Priority — clean & refactor

- [x] L21 (L12) Remove wall-clock `added_at` stamping (5 branches).
- [x] L22 Dead code: ternaries at :536/:542, `$componentType` :4181,
      `$locationInfo`/`$serialInfo` :5791, discarded `$responseData` :1705.
- [x] L23 (L11) `updateCpuConfiguration()` accumulate not overwrite.
- [x] L24 (L13) Pass `$componentType` into `isDuplicateComponent()`.
- [x] L25 (P6) Gate per-request `error_log` on APP_ENV.

## Deferred (needs infrastructure this session does not have)

- L20 — Wire `ServerState` into the write path. Per CLAUDE.md, any refactor of
  the compatibility engine must be proven at parity against the golden baseline
  (`tests/golden/compatibility_baseline.json`), which requires the scratch DB
  seeded via `tests/golden/setup_scratch_db.sql`. M8 delivers most of the
  query-count win without that risk. Recommend as a separate, soaked change.
- L26 — Consolidating `update{Ram,Storage,Caddy}Configuration` into one writer.
  H3 removes the duplicated bug from all three; the consolidation itself is
  cosmetic and carries production regression risk disproportionate to the gain.

## Notes

- No schema changes required by any item above → no new seeder needed.
- Keep syntax PHP 7.4-compatible.
- `php -l` every touched file.
- (Note superseded: no ConstraintState hook exists in the code — see "Stale documentation found".)


---

## Status: all planned items complete (2026-07-25)

Verified: `php -l` clean across the tree; all 11 DB-free test suites pass;
new `tests/unit/component_entry_identity_test.php` (23 checks) pins the
identity/removal/quantity logic.

### Additional findings fixed while implementing (not in the original report)

- **A-L1 also present in the command layer.** `AddComponentCommand::lockAndCheckComponent()`
  and `ReplaceComponentCommand::lockAndCheckComponent()` are independent copies of the
  ServerBuilder helper and carried the same `ORDER BY Status ASC` defect. Both fixed.
- **quantity>1 for slotted types was incoherent.** `assignComponentSlot()` allocates ONE
  PCIe slot per call, so N nic/pciecard/hbacard units would share a single slot id.
  Now rejected explicitly with `error_type=invalid_quantity`.
- **`auth_tokens.last_used_at` had one writer and zero readers.** Moved from the
  per-request access-token path to `JWTHelper::verifyRefreshToken()`, where it is
  scoped to the single token actually presented.

### Stale documentation found

- `tasks/todo.md` (Phase 1) states the ConstraintState shadow-read hook is wired into
  `ServerBuilder::getCompatibleComponents()`. No `ConstraintState*` reference exists
  anywhere in `core/` or `api/`. Per CLAUDE.md the code wins — that checklist is stale.

### NOT VERIFIED (no database in this environment)

No MySQL/MariaDB binary is available here, so every DB-backed suite was skipped:
`characterize_compatibility.php` (golden master), `serverstate_equivalence.php`, the
authority unit tests, and the `_scratch_db`-dependent regression tests. These changes
touch the add/remove/finalize write paths and MUST be run against the scratch DB
before deploy. See "Before deploying" in the handoff.
