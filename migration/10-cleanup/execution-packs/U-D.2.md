# U-D.2 — Delete legacy validators + read-time warnings + superseded authorities
Pins baseline: yes (verdict surface now 100% engine). Invariants: INV-2, INV-10, INV-11.

## Targets (delete after per-symbol deadcode GREEN)
validateConfiguration 3166 / validateConfigurationEnhanced 3275 / validateConfigurationComprehensive
6414 + its private tracker family / getConfigurationWarnings 1875 + API call site 817 /
calculateHardwareCompatibilityScore + checkPower*/checkFormFactor* privates / assignComponentSlot +
extractPCIeSlotSize / validateCPUAddition / validateRAMAddition / validateComponentQuantity /
MemoryAuthority, SlotAuthority, StorageConnectionAuthority, ValidationPipeline, PcieLaneBudgetValidator,
OnboardNICHandler::replaceOnboardNIC / legacy authority unit tests (their cases live in rules tests since U-R.*).
validate-config endpoint now calls ValidationEngine.evaluate(VALIDATE) via a thin service — CREATE
core/models/validation/ValidateConfigService.php + wire handleValidateConfiguration 1348.
SPLIT MANDATE: this exceeds the 5-file box ⇒ execute as FOUR sub-sessions U-D.2a (full-config
validators + endpoint wire), U-D.2b (add-path per-type validators), U-D.2c (authority classes +
pipeline + their tests), U-D.2d (warnings + score family). Each sub-session follows this pack +
its own deadcode runs. Recorded as PD-2.

## Tests (each sub-session)
deadcode GREEN per symbol; php -l tree; characterization ZERO diffs; run_all --gate P9 GREEN.

## Rollback / Checklist
git revert per sub-session. - [ ] validate-config responses shimmed (VerdictShim) - [ ] No rules tests lost coverage (line-count of ported cases ≥ legacy)
