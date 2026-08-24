# U-D.2 — Delete legacy validators + read-time warnings + superseded authorities
Pins baseline: yes (verdict surface now 100% engine). Invariants: INV-2, INV-10, INV-11.

## Targets (delete after per-symbol deadcode GREEN)
validateConfiguration 3932 / validateConfigurationEnhanced 4032 / validateConfigurationComprehensive
7732 + its private tracker family (calculateFinalCompatibilityScore 9042) / getConfigurationWarnings 2679 +
API call site server_api.php 1331 / calculateHardwareCompatibilityScore (ALREADY REMOVED 2026-08-23) +
checkPower*/checkFormFactor* privates 7016-7205 / assignComponentSlot 5720 +
extractPCIeSlotSize 5878 / validateCPUAddition 4858 / validateRAMAddition 4954 / validateComponentQuantity 9248 /
MemoryAuthority, SlotAuthority, StorageConnectionAuthority, ValidationPipeline, PcieLaneBudgetValidator,
OnboardNICHandler::replaceOnboardNIC / legacy authority unit tests (their cases live in rules tests since U-R.*).
validate-config endpoint now calls ValidationEngine.evaluate(VALIDATE) via a thin service — CREATE
core/models/validation/ValidateConfigService.php + wire handleValidateConfiguration (server_api.php 2386).
SPLIT MANDATE: this exceeds the 5-file box ⇒ execute as FOUR sub-sessions U-D.2a (full-config
validators + endpoint wire), U-D.2b (add-path per-type validators), U-D.2c (authority classes +
pipeline + their tests), U-D.2d (warnings + score family). Each sub-session follows this pack +
its own deadcode runs. Recorded as PD-2.

## Tests (each sub-session)
deadcode GREEN per symbol; php -l tree; characterization ZERO diffs; run_all --gate P9 GREEN.

## Rollback / Checklist
git revert per sub-session. - [ ] validate-config responses shimmed (VerdictShim) - [ ] No rules tests lost coverage (line-count of ported cases ≥ legacy)

## Cascade note (census 2026-08-24, informational — no scope change)
Three private helpers are reachable ONLY from targets already listed above, i.e. they die with them.
They were missing from `scripts/verify/deadcode_manifest.json` and have been added there, so the gate
now covers them; they are named here so the diff is expected rather than surprising. They are what
this pack's phrase "its private tracker family" was pointing at:
- `calculateJSONExistenceScore` 4248 — sole caller tree-wide is `validateConfigurationEnhanced` (4201). U-D.2a.
- `calculateCompatibilityMatrixScore` 4266 — sole caller tree-wide is `validateConfigurationEnhanced` (4202). U-D.2a.
- `calculateFinalCompatibilityScore` 9042 — sole caller tree-wide is `validateConfigurationComprehensive` (7833). U-D.2a.

Harness call sites that BREAK when these targets go (allow-listed in the manifest so they do not block
the gate, but they are still code that must be edited IN THE SAME COMMIT):
- `scripts/verify/performance_report.php` 174-175 — calls `validateConfiguration` and
  `validateConfigurationEnhanced`. It is the ONLY remaining caller of `validateConfigurationEnhanced`
  anywhere in the tree.
- `scripts/verify/ledger_report.php` 57, 166, 226 — `PcieLaneBudgetValidator` type hint + two `new`.
