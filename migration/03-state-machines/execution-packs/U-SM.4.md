# U-SM.4 — StateGuard wiring (shadow → enforce)
Concept: mutability as a function of state (replaces TEMP-GUARD U-0.2 at enforce).
Pins baseline: yes (shadow = zero diffs). Invariants: INV-4, INV-12, INV-10, INV-11.

## Inputs
00-overview/FLAGS.md; U-SM.3 service; ServerBuilder guard sites (search marker `TEMP-GUARD(U-0.2)`);
finalize path 3527–3600.

## Files Created (1) / Modified (1)
core/models/state/StateGuard.php — mode() per flag pattern; `checkMutation(PDO,$lockedRow): ?array`
returns null (allowed) or failure array; rule: status_v2 ∈ {draft,building,maintenance} allows
mutation; NULL status_v2 falls back to legacy int rule (≠3). shadow: evaluate, log divergence vs
TEMP-GUARD verdict to reports/shadow/state-guard.jsonl, return null always. enforce: return verdict.
MODIFY ServerBuilder: at both TEMP-GUARD sites call StateGuard FIRST; when mode=enforce the
TEMP-GUARD block is skipped (wrap it in `if (StateGuard::mode() !== 'enforce')`) — physical deletion
of TEMP-GUARD happens in U-D.4, keeping this unit ≤ its box. Finalize: when enforce,
assertConfigTransition(validated→finalized… ) replaces the raw status check and the
`requires_validation=full` path calls validateConfigurationComprehensive UNDER THE LOCK (audit V-1
closes here; note the legacy weak validateConfiguration call remains until U-C.5 — do not remove it,
ADD the comprehensive gate beside it).

## Tests
shadow: characterization ZERO diffs + shadow log populated on scratch runs;
enforce (scratch only): finalized_immutability_test still PASS via new guard;
NEW tests/regression/state_guard_test.php: maintenance allows mutation, deployed blocks, NULL falls back.

## Rollback
Flag to off; git revert.

## Checklist
- [ ] Shadow provably side-effect-free - [ ] V-1 comprehensive-under-lock added at enforce
- [ ] TEMP-GUARD skipped, not deleted
