# RULE_MAP — every business rule: legacy location → new rule → verification
Severity: E=ERROR, VF=VALIDATION_FAILURE, W=WARNING. Verification strategy for ALL rows:
shadow parity on scratch fixture scenarios (tests/fixture_scenarios_real.php) + targeted unit test
per rule + explained-diff mapping when the new rule intentionally differs (audit finding cited).

| Unit | New rule id (sev) | Legacy location(s) | Intentional diffs |
|---|---|---|---|
| U-R.1 | cpu.socket_match (E) | ServerBuilder::validateCPUAddition 3792-3798 + ComponentValidator::validateCPUSocketCompatibility 265 | none |
| U-R.1 | cpu.socket_count (E) | validateCPUAddition 3764-3789 (entry count) | counts ROWS ⇒ quantity bypass closes (A-2) |
| U-R.1 | cpu.mixed_models (W) | ComponentValidator::validateMixedCPUCompatibility 377 (ORPHANED — never called) | new firing = expected diffs |
| U-R.1 | cpu.requires_board (VF) | validateCPUAddition 3754-3760 hard block | E→VF (A-12): adds allowed in draft |
| U-R.2 | memory.type (E) | validateMemoryTypeCompatibility 730 | none |
| U-R.2 | memory.form_factor (E) | validateMemoryFormFactor 824 | none |
| U-R.2 | memory.slot_count (E) | validateMemorySlotAvailability 938 + validateComponentQuantity[ram] 7806 + MemoryAuthority | three impls → one; row-count based |
| U-R.2 | memory.ecc (W) | validateECCCompatibility 861 | none |
| U-R.2 | memory.downclock (W) | analyzeMemoryFrequency (ComponentCompatibility) | none |
| U-R.3 | pcie.slot_placement (E) | assignComponentSlot 4433 + UnifiedSlotTracker + SlotAuthority | manual slot honored (A-7); unknown width blocks (A-8) |
| U-R.4 | pcie.lane_budget (E) | PcieLaneBudgetValidator + trackPCIeLaneAvailability 6752 | warn-default → E (A-9); ledger-based single model |
| U-R.5 | storage.interface_path (E) | StorageConnectionValidator + StorageConnectionAuthority + checkStorageDecentralizedCompatibility | none |
| U-R.5 | storage.bay_capacity (E) | validateComponentQuantity[storage] 7833 + chassis check CC:3076 | none |
| U-R.5 | storage.m2_capacity (E) | getConfigurationWarnings 1875 (read-time W!) | W-at-read → E (A-10) |
| U-R.5 | storage.caddy_pairing (VF) | getConfigurationWarnings caddy section | read-time → VF |
| U-R.6 | net.sfp_port (E) | validateSFPAddition 4251 + NICPortTracker | none |
| U-R.6 | net.nic_slot (E) | folded into pcie.slot_placement; NIC-specific checks from checkPCIeDecentralizedCompatibility | none |
| U-R.7 | system.required_set (VF) | THREE lists: validateConfiguration 3184, validateRequiredComponents 6541, getConfigurationWarnings | one list = comprehensive's (chassis..nic) |
| U-R.7 | system.singleton (E) | four sites (audit A-5/D3) | one impl |
| U-R.7 | system.psu_capacity (E) | checkPowerCompatibilityDetailed 5706 (scoring only!) | scoring → E (V-4) |
| U-R.7 | system.inventory_state (E) | validateConfiguration 3232 (non-blocking issues!) | non-blocking → E (V-2) |
| U-R.8 | dependency.blocked_removal (E) | ONLY NIC→SFP existed (removeComponent 987) | every edge now enforced (R-1) — large expected-diff set, all mapped |
