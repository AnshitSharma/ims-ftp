# 01-foundation — Phase P0: Stop-the-bleeding
Objective: make validation fail-closed and finalized configs immutable NOW, with temporary guards
that the target architecture later replaces (U-SM.4 supersedes U-0.2; U-C.1 supersedes U-0.3's
pattern); plus bootstrap the verification harness every later gate depends on.
Prerequisites: none. This is the first phase.
Affected files: core/models/server/ServerBuilder.php, api/handlers/server/server_api.php,
tests/regression/* (new), scripts/verify/* (new).
Affected DB tables: none (P0 ships zero seeders).
Migration order: U-0.1 → U-0.2 → U-0.3 → U-0.4 (0.4 last: it baselines post-guard behavior).
Rollback strategy: pure git revert per unit (R-UNIT); no schema, no flags.
Verification: characterization suite green with EXPECTED baseline changes documented in each pack;
new regression tests green; orphan report executed and archived.
Expected risks: baseline updates are intentional here (fail-open verdicts become blocking) — the
packs enumerate exactly which golden entries may change; any OTHER change is a defect.
Expected duration: 4 sessions, ~0.5 day each.

## Session handoff (phase-close template — fill when last unit done)
Current State: P0 guards live; harness operational; baselines captured.
Completed Work: U-0.1..U-0.4. Remaining Work: none in P0.
Known Risks: guards are temporary; do not extend them — the state machine replaces them.
Next Prompt To Use: see HANDOFF_TEMPLATE with NEXT-UNIT=U-1.1.
Files To Load Into Context: 00-overview/README.md, ARCHITECTURAL_INVARIANTS.md,
02-schema/execution-packs/U-1.1.md. Expected Context Size: ~25k tokens.
