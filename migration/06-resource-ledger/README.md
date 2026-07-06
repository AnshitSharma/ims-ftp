# 06-resource-ledger — Phase PL (executes between P1 and P2)
Objective: materialize provided capacities (sockets, slots, lanes, bays, ports, watts) from ims-data
specs into config_resources at dual-write time, and consumption links for placed components.
Prerequisites: P1 gate open. Affected files: core/models/config/ResourceCatalog.php,
ConfigComponentWriter.php, scripts/verify/{slot_report,ledger_report}.php.
Affected DB tables: config_resources (writes when DUAL_WRITE_ENABLED=on).
Order: U-L.1 → U-L.2 → U-L.3. Rollback: R-UNIT; ledger rows cascade-delete with providers.
Verification: ledger + slot reports green on scratch fixtures.
Risks: spec heterogeneity (m2_slots nested per-type; lanes per CPU model) — the catalog isolates ALL
spec parsing so later rules never touch raw JSON shapes. Duration: 3 sessions.
Handoff: Next unit U-B.1 (07-component-migration). Context ~30k.
