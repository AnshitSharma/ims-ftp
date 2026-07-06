# U-D.3 — Drop JSON columns (POINT OF NO RETURN)
Pins baseline: yes. Invariants: INV-8, INV-9(special), INV-11.
PRECONDITIONS (all evidenced in the signoff file): P8 signoff ≥30 days old; equivalence --all GREEN
daily for the last 30 days (cron archive exists from U-P.1? NO — U-P.1 comes later; U-X.2's daily
battery covers days 1-14; days 15-30 covered by a one-line cron installed in U-X.2? It is NOT — GAP
resolved in PLAN_VERIFICATION_REVIEW F-4: the cron installs in U-X.2); verified logical backup of
server_configurations same-day, retention 90 days, restore-tested on scratch.

## Inputs
rollback-playbook R-SCHEMA (post-U-D.3 = roll forward); ServerBuilder remaining JSON writers
(updateServerConfigurationTable family — deleted HERE, same unit, since columns go);
ConfigComponentWriter (dual-write mirror deleted; repository becomes sole writer);
equivalence_report (retire JSON side → becomes rows-vs-inventory consistency check).

## Database Changes (1 seeder + rollback-from-backup PROCEDURE not SQL)
2026_08_XX_001_drop-legacy-json-columns.sql: ALTER server_configurations DROP COLUMN
cpu_configuration, ram_configuration, storage_configuration, caddy_configuration, nic_config,
hbacard_config, hbacard_uuid, pciecard_configurations, sfp_configuration; (motherboard_uuid,
chassis_uuid RETAINED as denormalized fast-path? NO — dropped too; reads come from rows. Update
expected_schema.json.) rollback/ file contains the RESTORE PROCEDURE document (backup restore +
config_events replay steps), satisfying INV-9's pairing in procedure form — allowed only for this unit.

## Files Modified
ServerBuilder: delete update{Cpu,Ram,Storage,Nic,Caddy,Sfp,PcieCard,HbaCard}Configuration +
updateServerConfigurationTable + extractComponentsFromJson (router =on everywhere) — exceeds box ⇒
sub-sessions U-D.3a (seeder+backup+writer deletion) / U-D.3b (reader deletion + router simplification). PD-3.

## Tests
Full battery GREEN; every regression suite PASS; grep for dropped column names in code = empty.

## Checklist
- [ ] Backup restore-tested BEFORE seeder runs - [ ] 30-day evidence attached - [ ] Human sign-off line signed
