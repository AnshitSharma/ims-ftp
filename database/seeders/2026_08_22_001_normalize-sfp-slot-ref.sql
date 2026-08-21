-- =============================================================================
-- 2026_08_22_001_normalize-sfp-slot-ref.sql
--
-- Date:     2026-08-22
-- Purpose:  Repair config_components.slot_ref for SFP rows that the dual-write
--           hook stored in the WRONG SHAPE (a bare port number, e.g. "1")
--           instead of the canonical "port_{N}".
-- Tables:   config_components
-- Feature:  Migration P8 / U-X.1 -- closes the standing READ_FROM_ROWS
--           divergence that kept the sample-mode soak red from 2026-08-10
--           through 2026-08-21.
--
-- =============================================================================
-- WHY
--
--   api/handlers/server/server_api.php:455 accepts `slot_position` as a
--   backward-compatible alias for `port_index`, so an SFP add arrives carrying
--   BOTH keys. ServerBuilder's dual-write hook passed the raw
--   $options['slot_position'] straight through, so the rows store recorded "1"
--   while three canonical sites all produce/expect "port_1":
--
--     * ConfigReadRouter::portIndexFromSlotRef()   (parses "port_{N}")
--     * TargetStateBuilder json-fallback sfp rows  (writes "port_{N}")
--     * ServerBuilder::validateComponentAddition() (writes "port_{N}")
--
--   portIndexFromSlotRef() cannot read a bare "1", so it returned NULL for the
--   rows side while the JSON side returned the real port. Every read comparison
--   on an SFP-bearing config therefore logged kind="divergence" -- the reason
--   the 72h clean-window gate was never met. The code defect is fixed in
--   ServerBuilder::addComponent (the hook now derives "port_{N}" from
--   $options['port_index']); this seeder repairs the rows already written.
--
-- IDEMPOTENT
--
--   Naturally so: after the UPDATE the repaired rows no longer match the
--   `^[0-9]+$` predicate, so a re-run affects 0 rows.
--
-- NOT COVERED (deliberate)
--
--   SFP rows whose slot_ref is NULL cannot be repaired from this table alone --
--   the port index is only recoverable from server_configurations.sfp_configuration
--   JSON. No such row exists as of 2026-08-22 (the one config that had one,
--   a3254d20, has since been deleted), so no JSON-joining UPDATE is written
--   here. If the verification query below ever reports NULLs, handle them in a
--   follow-up seeder rather than editing this one.
--
--   No information_schema guards are used anywhere in this file: the application
--   DB user has no access to information_schema on this host (see the
--   2026_08_18_001 post-mortem).
-- =============================================================================

-- 1) Before: what is about to change.
SELECT
    'BEFORE' AS phase,
    id,
    config_uuid,
    spec_uuid,
    slot_ref
FROM config_components
WHERE component_type = 'sfp'
  AND slot_ref REGEXP '^[0-9]+$'
ORDER BY id;

-- 2) The repair.
UPDATE config_components
SET slot_ref = CONCAT('port_', slot_ref)
WHERE component_type = 'sfp'
  AND slot_ref REGEXP '^[0-9]+$';

-- 3) Verification: both counts MUST be 0.
SELECT
    'VERIFY' AS phase,
    SUM(slot_ref REGEXP '^[0-9]+$')                     AS still_bare_numeric,
    SUM(slot_ref IS NULL)                               AS null_slot_ref_needs_followup,
    SUM(slot_ref LIKE 'port\_%')                        AS canonical_port_rows,
    COUNT(*)                                            AS total_sfp_rows
FROM config_components
WHERE component_type = 'sfp';
