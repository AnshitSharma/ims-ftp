# 09-cutover — Phase P8: Read path cutover
Objective: reads served from rows (READ_FROM_ROWS off→sample→on) with per-request comparison in
sample mode; then the cutover runbook + full battery.
Prerequisites: P7 gate open. Affected files: core/models/config/ConfigReadRouter.php (new),
ServerBuilder read entrypoints (getConfigurationDetails 2149, getConfigComponents 4850,
extractComponentsFromJson callers stay — router sits above them). Affected DB tables: none.
Order: U-X.1 → U-X.2. Rollback: flag to previous value; JSON still dual-written (nothing rots).
Verification: full seven-report battery green 14 days at =on.
Risks: read-shape drift (JSON extraction carries name-enrichment) — router reproduces enrichment
from spec service; sample mode catches any residue in production before =on.
Duration: 2 sessions + 14-day soak. Handoff: next U-D.1. Context ~30k.
