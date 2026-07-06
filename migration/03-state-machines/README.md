# 03-state-machines — Phase P3
Objective: introduce config + inventory lifecycle state machines (status_v2), dual-written with
legacy int statuses, guard enforcement behind STATE_MACHINE_ENABLED (off→shadow→enforce).
Prerequisites: P2 gate open (rows exist; guards will consult them later).
Affected files: database/seeders/*, core/models/state/* (new), ServerBuilder guard sites,
scripts/verify/inventory_report.php. Affected DB tables: server_configurations (+status_v2),
ALL TEN *inventory tables (+status_v2) [single seeder, mirrors house precedent 2026_06_15_001 which
altered all inventory tables in one file], NEW config_status_transitions, inventory_status_transitions.
Order: U-SM.1 → U-SM.2 → U-SM.3 → U-SM.4. Rollback: drop columns/tables while flag off/shadow;
legacy ints authoritative throughout P3.
Verification: inventory report extended (mapping agreement); 7-day shadow soak with zero guard
violations before enforce. Risks: ALTERs on large inventory tables — seeder uses ALGORITHM=INPLACE
where supported; run off-peak. Duration: 4 sessions + soak.
Handoff: next U-V.1. Context ~30k.
