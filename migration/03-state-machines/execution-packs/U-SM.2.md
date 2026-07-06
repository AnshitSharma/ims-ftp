# U-SM.2 — Transition tables + seed rows
Concept: transitions as data. Pins baseline: no. Invariants: INV-9, INV-11.

## Database Changes (1 seeder + rollback)
2026_07_10_002_create-status-transitions.sql:
config_status_transitions(from_status, to_status, required_permission VARCHAR(64),
requires_validation ENUM('none','full'), PRIMARY KEY(from_status,to_status)) seeded:
draft→building(server.edit,none), building→validating(server.edit,none),
validating→validated(SYSTEM,full), validating→building(SYSTEM,none),
validated→building(server.edit,none)  [auto-demote on mutation],
validated→finalized(server.finalize,full), finalized→deployed(server.deploy,none),
finalized→building(server.unfinalize,none), deployed→maintenance(server.maintain,none),
maintenance→deployed(server.maintain,full), deployed→retired(server.retire,none),
maintenance→retired(server.retire,none).
inventory_status_transitions(from,to,PRIMARY KEY) seeded per the target design §3.2 diagram,
EXPLICITLY EXCLUDING failed→available (illegal resurrection) and including
failed→retired, maintenance→available, maintenance→failed, available→reserved→allocated→installed→active,
each state→maintenance except retired, installed→available + active→available (removal paths),
reserved→available, allocated→available (release paths).
Rollback drops both tables.

## Inputs
target design quoted above is complete — no other files needed besides U-SM.1 pack + house DDL style file.

## Files Modified (1) expected_schema.json.

## Tests
U-1.1 pattern; plus SQL asserting failed→available absent and every enum value appears in ≥1 row.

## Checklist
- [ ] failed→available NOT present - [ ] validated→finalized requires 'full' - [ ] permissions strings match api/permission_map.php vocabulary (read it ONLY if a name seems off; else trust list)
