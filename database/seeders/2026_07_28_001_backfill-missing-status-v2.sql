-- =============================================================================
-- 2026_07_28_001_backfill-missing-status-v2.sql
--
-- Date:     2026-07-28
-- Purpose:  Populate status_v2 on the rows that were CREATED with it NULL, on
--           server_configurations and all 10 {type}inventory tables.
-- Tables:   server_configurations, cpuinventory, raminventory, storageinventory,
--           motherboardinventory, nicinventory, caddyinventory, chassisinventory,
--           pciecardinventory, hbacardinventory, sfpinventory
-- Feature:  F-21 (state-machine migration, P3 / U-SM.3)
--
-- =============================================================================
-- WHY
--
--   Seeder 2026_07_10_001 added the status_v2 columns and backfilled every row
--   that existed at that moment. Nothing kept doing it afterwards: the three
--   INSERT paths that create configurations and inventory units never named the
--   column, so every row created since 2026-07-10 was born with status_v2 NULL
--   while its legacy counterpart (Status / configuration_status) took a default.
--
--   Measured on the production dump exported 2026-07-27 22:24 UTC:
--
--     server_configurations   8 of 12 rows NULL -- INCLUDING ALL 5 PHYSICAL ONES
--     cpuinventory           21 rows NULL (all Status=1)
--     pciecardinventory       1 row  NULL (Status=1)
--
--   This was invisible to the gate reports on purpose: inventory_report's
--   status_v2/Status agreement check inspects only rows WHERE status_v2 IS NOT
--   NULL, so an unmigrated row was excused rather than flagged, and the P2 gate
--   reported GREEN over it.
--
--   It is not cosmetic. StateMachine::assertConfigTransition() and
--   assertInventoryTransition() both fail closed on NULL ("status_v2 not yet
--   populated"), which was verified against a replica of this dump: 8 of 12
--   configurations -- every physical one -- could not transition at all. P3's
--   shadow soak and any enforce cutover would have run against a fleet the state
--   machine refuses to move.
--
-- ORDER OF OPERATIONS (matters)
--
--   Apply this seeder only AFTER the accompanying code changes are live:
--
--     core/helpers/BaseFunctions.php            (F-21, inventory INSERT)
--     core/models/server/ServerBuilder.php      (F-21, createConfiguration)
--     api/handlers/server/server_api.php        (F-21, virtual->real import)
--     core/models/state/StateMachine.php        (F-22, see below)
--     core/models/commands/TransitionStatusCommand.php (F-22)
--
--   The first three stop new NULL rows, so the backfill stays done. The last two
--   are a hard precondition, not a nicety: a NULL status_v2 was the only thing
--   preventing TransitionStatusCommand from reaching
--   StateMachine::applyInventoryTransition(), whose UPDATE addressed a component
--   UUID (a MODEL) with no LIMIT, so transitioning one serial-less unit rewrote
--   status_v2 + Status on EVERY unit of that model -- 15 model UUIDs covering 83
--   serial-less units in this dump (71 ram, 9 pciecard, 3 storage). Populating
--   status_v2 removes that accidental shield. F-22 fixes the primitive to resolve
--   exactly one unit or refuse.
--
-- IDEMPOTENT
--   Every statement is guarded by `status_v2 IS NULL`, so a second run touches
--   0 rows. Rows whose legacy value is outside the documented map are left alone
--   rather than guessed at, and reported by the verification queries at the end.
--
-- MAPPINGS (verbatim from core/models/state/StatusMap.php -- do not improvise)
--   CONFIG_LEGACY_TO_V2:     0=>draft  1=>validated  2=>building  3=>finalized
--   INVENTORY_LEGACY_TO_V2:  0=>failed 1=>available  2=>installed
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. server_configurations
-- -----------------------------------------------------------------------------
UPDATE server_configurations
SET status_v2 = CASE configuration_status
                    WHEN 0 THEN 'draft'
                    WHEN 1 THEN 'validated'
                    WHEN 2 THEN 'building'
                    WHEN 3 THEN 'finalized'
                END
WHERE status_v2 IS NULL
  AND configuration_status IN (0, 1, 2, 3);

-- -----------------------------------------------------------------------------
-- 2. The 10 inventory tables
-- -----------------------------------------------------------------------------
UPDATE cpuinventory
SET status_v2 = CASE Status WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END
WHERE status_v2 IS NULL AND Status IN (0, 1, 2);

UPDATE raminventory
SET status_v2 = CASE Status WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END
WHERE status_v2 IS NULL AND Status IN (0, 1, 2);

UPDATE storageinventory
SET status_v2 = CASE Status WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END
WHERE status_v2 IS NULL AND Status IN (0, 1, 2);

UPDATE motherboardinventory
SET status_v2 = CASE Status WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END
WHERE status_v2 IS NULL AND Status IN (0, 1, 2);

UPDATE nicinventory
SET status_v2 = CASE Status WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END
WHERE status_v2 IS NULL AND Status IN (0, 1, 2);

UPDATE caddyinventory
SET status_v2 = CASE Status WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END
WHERE status_v2 IS NULL AND Status IN (0, 1, 2);

UPDATE chassisinventory
SET status_v2 = CASE Status WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END
WHERE status_v2 IS NULL AND Status IN (0, 1, 2);

UPDATE pciecardinventory
SET status_v2 = CASE Status WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END
WHERE status_v2 IS NULL AND Status IN (0, 1, 2);

UPDATE hbacardinventory
SET status_v2 = CASE Status WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END
WHERE status_v2 IS NULL AND Status IN (0, 1, 2);

UPDATE sfpinventory
SET status_v2 = CASE Status WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END
WHERE status_v2 IS NULL AND Status IN (0, 1, 2);

COMMIT;

-- =============================================================================
-- VERIFICATION -- every row below should report 0. A non-zero count means a
-- legacy value outside the documented map; investigate that row, do not widen
-- the CASE.
-- =============================================================================
SELECT 'server_configurations' AS tbl, COUNT(*) AS still_null FROM server_configurations WHERE status_v2 IS NULL
UNION ALL SELECT 'cpuinventory',         COUNT(*) FROM cpuinventory         WHERE status_v2 IS NULL
UNION ALL SELECT 'raminventory',         COUNT(*) FROM raminventory         WHERE status_v2 IS NULL
UNION ALL SELECT 'storageinventory',     COUNT(*) FROM storageinventory     WHERE status_v2 IS NULL
UNION ALL SELECT 'motherboardinventory', COUNT(*) FROM motherboardinventory WHERE status_v2 IS NULL
UNION ALL SELECT 'nicinventory',         COUNT(*) FROM nicinventory         WHERE status_v2 IS NULL
UNION ALL SELECT 'caddyinventory',       COUNT(*) FROM caddyinventory       WHERE status_v2 IS NULL
UNION ALL SELECT 'chassisinventory',     COUNT(*) FROM chassisinventory     WHERE status_v2 IS NULL
UNION ALL SELECT 'pciecardinventory',    COUNT(*) FROM pciecardinventory    WHERE status_v2 IS NULL
UNION ALL SELECT 'hbacardinventory',     COUNT(*) FROM hbacardinventory     WHERE status_v2 IS NULL
UNION ALL SELECT 'sfpinventory',         COUNT(*) FROM sfpinventory         WHERE status_v2 IS NULL;

-- Pairing check: status_v2 must now agree with legacy Status everywhere
-- (this is inventory_report's check 3, expressed in SQL). Expect 0 rows.
SELECT 'cpuinventory' AS tbl, ID, Status, status_v2 FROM cpuinventory
WHERE status_v2 IS NOT NULL AND Status <> CASE status_v2
    WHEN 'available' THEN 1 WHEN 'failed' THEN 0 WHEN 'retired' THEN 0 ELSE 2 END
UNION ALL
SELECT 'raminventory', ID, Status, status_v2 FROM raminventory
WHERE status_v2 IS NOT NULL AND Status <> CASE status_v2
    WHEN 'available' THEN 1 WHEN 'failed' THEN 0 WHEN 'retired' THEN 0 ELSE 2 END
UNION ALL
SELECT 'pciecardinventory', ID, Status, status_v2 FROM pciecardinventory
WHERE status_v2 IS NOT NULL AND Status <> CASE status_v2
    WHEN 'available' THEN 1 WHEN 'failed' THEN 0 WHEN 'retired' THEN 0 ELSE 2 END;
