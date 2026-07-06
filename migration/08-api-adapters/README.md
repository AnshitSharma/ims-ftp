# 08-api-adapters — Phase P7
Objective: legacy endpoints become thin adapters over commands (unchanged response shapes via shims,
deprecation headers), plus the NEW actions (replace-component, transition-status, serial-aware remove).
Prerequisites: P6 gate open (COMMAND_LAYER_ENABLED=enforce).
Affected files: api/handlers/server/server_api.php, api/permission_map.php, api/handlers docs.
Affected DB tables: none. Order: U-A.1→U-A.3. Rollback: git revert (commands still run underneath).
Verification: golden API-response fixtures; parity of HTTP codes/messages where documented stable.
Risks: undocumented client dependence on message TEXT — shims preserve message strings for the
top-10 error classes (list in U-A.3). Duration: 3 sessions.
Handoff: next U-X.1. Context ~30k.
