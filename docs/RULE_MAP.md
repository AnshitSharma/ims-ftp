# RULE_MAP — every business rule: legacy location → new rule → verification
Severity: E=ERROR, VF=VALIDATION_FAILURE, W=WARNING. Verification strategy for ALL rows:
shadow parity on scratch fixture scenarios (tests/fixture_scenarios_real.php) + targeted unit test
per rule + explained-diff mapping when the new rule intentionally differs (audit finding cited).

| Unit | New rule id (sev) | Legacy location(s) | Intentional diffs |
|---|---|---|---|
| U-R.1 | cpu.socket_match (E) | ServerBuilder::validateCPUAddition 3792-3798 + ComponentValidator::validateCPUSocketCompatibility 265 | none |
| U-R.1 | cpu.generation_match (E) | none — socket fit was the only CPU↔board gate | NEW rule (2026-09-02); inert until a board declares `cpu_support`, see note below |
| U-R.1 | cpu.socket_count (E) | validateCPUAddition 3764-3789 (entry count) | counts ROWS ⇒ quantity bypass closes (A-2) |
| U-R.1 | cpu.mixed_models (E/W) | ComponentValidator::validateMixedCPUCompatibility 377 (ORPHANED — never called) → CpuIdentityMatcher | new firing = expected diffs; see note below |
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

## Note — cpu.generation_match, and why socket fit was not enough (2026-09-02)

Socket type does not identify a generation. Four sockets in the catalog are shared, so
`cpu.socket_match` passed pairings that will not POST:

| Socket | Generations sharing it | Boards |
|---|---|---|
| LGA2011-3 / FCLGA2011-3 | Xeon E5 v3 (Haswell-EP) + v4 (Broadwell-EP) | 5 loose + 5 platform |
| LGA3647 / FCLGA3647 | Skylake-SP; Cascade Lake would join it | 7 loose + 5 platform |
| SP3 | EPYC 7002 (Zen 2) + 7003 (Zen 3) | 3 loose + 3 platform |
| AM4 | Zen, Zen 2, Zen 3 | 2 loose |

The board declares an optional `cpu_support` block in `ims-data`
(`{generations, series}` — documented in `ims-data/CLAUDE.md`); every matching decision
lives in `core/models/compatibility/CpuGenerationResolver.php`.

**Two axes, because neither vendor's data alone covers both.** `generations` is Intel's
discriminator, a closed vocabulary, so board entries resolve through an alias table —
`Broadwell-EP` and `Xeon E5 v4` are one canonical id — matched against the CPU's
`architecture`, with `series`/`family` as fallback. `series` is AMD's: `architecture`
cannot express it, since EPYC 7742 and Ryzen 5 3600 both say `Zen 2`, and the
product-line fact lives in the CPU's `family` (`EPYC 7002`, `Ryzen 9 5000`). That is an
open vocabulary, so the series axis matches by token subset — a board saying
`Ryzen 5000` matches family `Ryzen 9 5000`, while `EPYC 7003` does not match `EPYC 7002`.

Each axis is independently optional and declared axes are **ANDed**. A board declaring
neither is unconstrained, which is why code and `ims-data` may deploy in either order:
the rule is inert until data appears, and the old code ignores the key as unknown.

Fail-closed detail: a board that declares a constraint plus a CPU with no
`architecture`/`series`/`family` at all is refused rather than waved through, matching
`CpuIdentityMatcher`'s posture on an unparseable model name. Unreachable with current
`ims-data` — all 32 CPU models carry `architecture`.

Boundaries: `cpu.socket_match` owns the physical connector, `cpu.mixed_models` owns
CPU↔CPU pairing, `cpu.requires_board` owns the no-board case (which this rule passes on),
and an absent board spec is left for `cpu.socket_match` to report so one root cause does
not produce two blocking results. Platform compute boards need no special case:
`getMotherboardByUUID` resolves a platform's embedded `system_board` through
`PlatformSpecIndex` under type `motherboard`, so the same code reads the same field paths
for a platform build and a loose spare — covered by a test on both copies of the DL325
Gen10 Plus v2 board.

## Note — cpu.mixed_models scope change (2026-08-14)

The rule originally compared **socket types only**, which made it a duplicate of
`cpu.socket_match` and meant it passed any two CPUs sharing a socket. Xeon Gold 6338 and
6342 are both LGA4189 Ice Lake-SP and cannot run together: multi-socket operation requires
the same SKU, and only the vendor's processor line suffix may differ.

`cpu.socket_match` owns CPU↔board socket agreement. `cpu.mixed_models` now owns CPU↔CPU
pairing and delegates to `core/models/compatibility/CpuIdentityMatcher.php` — the same
authority the live legacy path (`ServerBuilder::validateCPUAddition`) calls, so the two
engines cannot disagree about what "pairable" means.

Three verdicts, two severities from one rule (`RuleResult` carries its own severity;
`Verdict::blocking()` reads that, not `severity()`):

| Verdict | Example | Severity |
|---|---|---|
| IDENTICAL | 6338 + 6338 | passes |
| VARIANT | 6338 + 6338N | W — never blocks |
| MISMATCH | 6338 + 6342 | **E** — blocks under every trigger |

Two deliberate changes from the row above:
- **Severity W → E/W.** MISMATCH must be ERROR, not VALIDATION_FAILURE: the legacy path
  hard-blocks at add-time, and VF does not block under `Trigger::ADD`.
- **Triggers VALIDATE → ADD + VALIDATE**, for the same reason.

Expected-diff class: any configuration mixing CPU SKUs that legacy silently allowed now
fails. Suffix variants are deliberately NOT blocked — they legitimately differ on memory
speed (6338 is DDR4-3200, 6338N DDR4-2666) and core count (6338T is 24C), so blocking on
those would collapse the warning tier into the mismatch tier.

Unit test: `tests/unit/compatibility/cpu_identity_matcher_test.php` (DB-free, 39 checks).
