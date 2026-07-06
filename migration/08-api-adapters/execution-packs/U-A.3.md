# U-A.3 — Response shims
Pins baseline: yes. Invariants: INV-11.

## Inputs
server_api.php 470–560 (RAM enrichment block) + 640–680 (error_type switch — the top-10 message
classes to preserve: socket_mismatch, cpu_limit_exceeded, duplicate_component, config_finalized,
motherboard_required, compatibility_failure, json_not_found, no_pcie_slots_available,
validation_exception, dependency_blocked [new]); Verdict/RuleResult shapes.

## Files Created (1) / Modified (1)
api/handlers/server/VerdictShim.php — Verdict → legacy envelope: success flag, message (first
blocking result's message), error_type (rule id → legacy class map as const), warnings (all W
results), details/recommendations; RAM enrichment mapped from MemoryDownclockRule details.
MODIFY server_api.php: all three mutation handlers use the shim (delete inline mapping remnants).

## Tests
shape test PASS; per-class fixture: each of the 10 error classes produces its legacy error_type.

## Rollback / Checklist
git revert. - [ ] rule→error_type map is a const with per-row comment - [ ] Unknown rule ids fall back to 'compatibility_failure' (never 500)
