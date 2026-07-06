# 10-cleanup — Phase P9: Legacy deletion
Objective: prove unused, then delete: duplicate validation, legacy validators, JSON columns, flags,
temp guards. Every deletion unit runs deadcode_report BEFORE deleting (prove) and the full
regression+equivalence battery AFTER (parity maintained).
Prerequisites: P8 gate open ≥14 days. Order: U-D.1→U-D.4 strictly (U-D.3 is the point of no return —
see rollback-playbook R-SCHEMA). Affected: large negative diffs across core/ and api/;
DB: DROP of ten JSON columns + hbacard_uuid (U-D.3).
Risks: hidden callers (cron, external scripts) — deadcode_report greps the WHOLE repo including
scripts/ and .codex/; U-D.3 requires the 90-day backup.
Duration: 4 sessions. Handoff: next U-P.1. Context ~25k.
