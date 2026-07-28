-- =============================================================================
-- Seeder:  2026_07_27_001_revert-019eca1d-partial-application.sql
--
-- Date:    2026-07-27
-- Purpose: Undo the two UPDATEs that committed before seeder
--          2026_07_22_004_settle-019eca1d-motherboard.sql aborted, and release
--          board 49 / NIC 232 from a config that no longer exists.
-- Tables:  motherboardinventory, nicinventory
-- Feature: P2 remediation follow-up (supersedes 2026_07_22_004, which is now
--          OBSOLETE and must NOT be run again — see below)
--
-- =============================================================================
-- WHAT HAPPENED
--
--   Seeder 2026_07_22_004 repairs server configuration
--   019eca1d-069b-4ebe-91ba-6f856b1c99ef. That configuration was DELETED from
--   production some time after 2026-07-22 — it is absent from the 2026-07-24
--   dump, and its absence is what makes section 3 fail:
--
--     #1452 Cannot add or update a child row: a foreign key constraint fails
--     (config_components, CONSTRAINT fk_cc_config
--      FOREIGN KEY (config_uuid) REFERENCES server_configurations (config_uuid))
--
--   The FK is doing its job: there is no parent row to attach to.
--
--   But sections 1 and 2 are plain UPDATEs with no transaction around them, so
--   under autocommit they had already landed when section 3 aborted. They bound
--   motherboardinventory #49 and nicinventory #232 (its onboard NIC) to the
--   deleted config, leaving both stuck at Status = 2 / 'installed' with a
--   ServerUUID that resolves to nothing — the same orphan shape that seeder
--   2026_07_21_004 was written to clear. Sections 4, 5 and 6 are no-ops: 4 and 5
--   key off the motherboard row that was never inserted, and 6 matches a
--   server_configurations row that no longer exists.
--
--   2026_07_22_004 IS OBSOLETE. Its subject no longer exists, so there is
--   nothing left for it to repair and it can never complete. Do not re-run it.
--
-- SCOPE: exactly the two units 2026_07_22_004 touched, and only while they are
--   still bound to that specific dead config. If either has since been
--   legitimately re-bound to a live configuration, this seeder leaves it alone.
--
-- IDEMPOTENT: after one run ServerUUID is NULL, so a second run matches nothing.
--
-- COLLATIONS: motherboardinventory.ServerUUID is utf8mb4_general_ci and
--   nicinventory.ServerUUID is utf8mb4_unicode_ci; both are compared here to a
--   string literal only, so no cross-column comparison and no #1267 risk.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 0. INSPECT FIRST (read-only).
--    Expect: the config query returns 0 rows, and both units read
--            Status 2 / 'installed' / ServerUUID 019eca1d-…
-- -----------------------------------------------------------------------------
--   SELECT config_uuid, server_name FROM server_configurations
--    WHERE config_uuid = '019eca1d-069b-4ebe-91ba-6f856b1c99ef';
--
--   SELECT 'mb' AS t, ID, SerialNumber, Status, status_v2, ServerUUID
--     FROM motherboardinventory WHERE ID = 49
--   UNION ALL
--   SELECT 'nic', ID, SerialNumber, Status, status_v2, ServerUUID
--     FROM nicinventory WHERE ID = 232;


-- -----------------------------------------------------------------------------
-- 1. Release board 49. Status 1 = available; status_v2 mirrors it per
--    StatusMap::INVENTORY_LEGACY_TO_V2 (1 => 'available'). InstallationDate is
--    cleared: section 1 of 2026_07_22_004 deliberately left it NULL, so there is
--    nothing to restore, and a released unit must not carry one.
-- -----------------------------------------------------------------------------
UPDATE `motherboardinventory`
   SET Status           = 1,
       status_v2        = 'available',
       ServerUUID       = NULL,
       InstallationDate = NULL,
       UpdatedAt        = NOW()
 WHERE ID = 49
   AND ServerUUID = '019eca1d-069b-4ebe-91ba-6f856b1c99ef'
   AND NOT EXISTS (SELECT 1 FROM (SELECT config_uuid FROM `server_configurations`) sc
                    WHERE sc.config_uuid = '019eca1d-069b-4ebe-91ba-6f856b1c99ef');

-- -----------------------------------------------------------------------------
-- 2. Release its onboard NIC. It follows the board by construction.
--    Note this also settles a pre-existing inconsistency visible in the
--    2026-07-24 dump, where NIC 232 read Status 1 but status_v2 'installed';
--    both now agree on available.
-- -----------------------------------------------------------------------------
UPDATE `nicinventory`
   SET Status           = 1,
       status_v2        = 'available',
       ServerUUID       = NULL,
       InstallationDate = NULL,
       UpdatedAt        = NOW()
 WHERE ID = 232
   AND ParentInventoryID = 49
   AND ServerUUID = '019eca1d-069b-4ebe-91ba-6f856b1c99ef'
   AND NOT EXISTS (SELECT 1 FROM (SELECT config_uuid FROM `server_configurations`) sc
                    WHERE sc.config_uuid = '019eca1d-069b-4ebe-91ba-6f856b1c99ef');


-- =============================================================================
-- VERIFY
--
--   -- (a) both units free again
--   SELECT 'mb' AS t, ID, Status, status_v2, ServerUUID, InstallationDate
--     FROM motherboardinventory WHERE ID = 49
--   UNION ALL
--   SELECT 'nic', ID, Status, status_v2, ServerUUID, InstallationDate
--     FROM nicinventory WHERE ID = 232;
--       -- expect 1 / available / NULL / NULL for both
--
--   -- (b) nothing anywhere still points at the deleted config (expect 0 rows)
--   SELECT 'motherboard' AS t, ID FROM motherboardinventory
--    WHERE ServerUUID = '019eca1d-069b-4ebe-91ba-6f856b1c99ef'
--   UNION ALL SELECT 'nic', ID FROM nicinventory
--    WHERE ServerUUID = '019eca1d-069b-4ebe-91ba-6f856b1c99ef';
--
--   -- (c) no config_components row was created (expect 0)
--   SELECT COUNT(*) FROM config_components
--    WHERE config_uuid = '019eca1d-069b-4ebe-91ba-6f856b1c99ef';
-- =============================================================================
