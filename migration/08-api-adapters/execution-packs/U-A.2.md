# U-A.2 — New actions: replace-component, transition-status; serial+revision params
Pins baseline: yes (additive). Invariants: INV-6, INV-11.

## Inputs
server_api.php 40–120 (action switch) + 698–760 (remove handler); api/permission_map.php (grant style);
ReplaceComponentCommand/TransitionStatusCommand signatures.

## Files Modified (2) / Created (1)
server_api.php: cases server-replace-component (old_component_uuid/old_serial/new_component_uuid/
new_serial/cascade), server-transition-status (to_status); handleRemoveComponent: accept
serial_number (closes R-3) and cascade; ALL mutation handlers: optional expected_revision param →
command's revision check → 409 with current revision on mismatch; quantity param: reject <1 with 400
(closes E-3) — quantity remains accepted =N only as N sequential single adds? NO: reject >1 with 400
"quantity is no longer supported; issue one request per physical unit" AFTER U-D.3 — during window
map N>1 to N command dispatches in one loop, all-or-nothing via one outer command tx (document).
permission_map.php: server.replace, server.transition grants mirroring server.edit patterns.
CREATE tests/api/new_actions_test.php (replace happy/blocked, transition legal/illegal, 409 path,
qty=0 → 400, serial-targeted remove).

## Tests / Rollback / Checklist
New tests PASS; existing shape test PASS. - [ ] 409 carries current revision - [ ] cascade default false - [ ] permission names registered
