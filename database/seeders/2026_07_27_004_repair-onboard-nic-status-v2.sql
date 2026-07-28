-- =============================================================================
-- 2026_07_27_003_repair-onboard-nic-status-v2.sql
--
-- Date:     2026-07-27
-- Purpose:  Repair onboard-NIC rows whose status_v2 disagrees with legacy Status.
-- Tables:   nicinventory  (SourceType = 'onboard' rows only)
-- Feature:  F-14 (command-layer / validation-engine migration)
--
-- WHY
--   OnboardNICHandler wrote legacy `Status` with raw UPDATEs and never touched
--   `status_v2`, bypassing StateMachine (which is the component that keeps the two
--   columns paired). Attach set Status=2 and left status_v2 stale; detach set
--   Status=1 and left status_v2='installed'; the INSERT never set status_v2 at all.
--   inventory_report flagged these as `status_v2_legacy_mismatch`, which is what
--   held the P2 gate RED on the 2026-07-26 production dump:
--
--     onboard-4c8f5e1b-49-1  status_v2='available'  but Status=2
--     onboard-c1d2e3f4-39-1  status_v2='installed'  but Status=1
--     onboard-4c8f5e1b-53-1  status_v2='installed'  but Status=1
--     onboard-e3f4a5b6-45-1  status_v2=NULL         (never initialised)
--
--   The code paths are fixed (F-14: status_v2 now rides in the same statement as
--   Status, in both the INSERT and both UPDATEs). This seeder repairs the rows
--   that drifted before that fix. Without it the invariant stays violated for
--   these historical rows no matter how correct the code is going forward.
--
-- APPROACH
--   Set-based and derived from the invariant, NOT from hardcoded row ids: repair
--   only rows that are provably inconsistent with StatusMap::INVENTORY_V2_TO_LEGACY
--   (available=>1, installed/reserved/allocated/active/maintenance=>2,
--   failed/retired=>0), and derive the corrected value from legacy Status, which
--   is the column the application has always maintained correctly.
--
--   Rows with Flag='replaced' are DELIBERATELY EXCLUDED. Those are ports a user
--   replaced with a discrete NIC; they hold Status=0 on purpose and the right
--   status_v2 for them ('failed' vs 'retired' vs a new value) is a product
--   decision, not a mechanical repair. If any exist, the final SELECT lists them
--   for a human decision.
--
-- IDEMPOTENT: re-running matches nothing once the rows are consistent.
-- =============================================================================

-- Attached and in use: legacy 2 => 'installed'.
UPDATE `nicinventory`
SET `status_v2` = 'installed',
    `UpdatedAt` = NOW()
WHERE `SourceType` = 'onboard'
  AND (`Flag` IS NULL OR `Flag` <> 'replaced')
  AND `Status` = 2
  AND (`status_v2` IS NULL
       OR `status_v2` NOT IN ('reserved', 'allocated', 'installed', 'active', 'maintenance'));

-- Detached and available again: legacy 1 => 'available'.
UPDATE `nicinventory`
SET `status_v2` = 'available',
    `UpdatedAt` = NOW()
WHERE `SourceType` = 'onboard'
  AND (`Flag` IS NULL OR `Flag` <> 'replaced')
  AND `Status` = 1
  AND (`status_v2` IS NULL OR `status_v2` <> 'available');

-- -----------------------------------------------------------------------------
-- Verification 1 -- expect ZERO rows (no onboard row disagrees with StatusMap):
-- -----------------------------------------------------------------------------
SELECT `ID`, `UUID`, `SerialNumber`, `Status`, `status_v2`, `Flag`
FROM `nicinventory`
WHERE `SourceType` = 'onboard'
  AND (`Flag` IS NULL OR `Flag` <> 'replaced')
  AND (
        `status_v2` IS NULL
     OR (`status_v2` = 'available' AND `Status` <> 1)
     OR (`status_v2` IN ('reserved','allocated','installed','active','maintenance') AND `Status` <> 2)
     OR (`status_v2` IN ('failed','retired') AND `Status` <> 0)
  );

-- -----------------------------------------------------------------------------
-- Verification 2 -- 'replaced' ports left untouched on purpose; review if listed:
-- -----------------------------------------------------------------------------
SELECT `ID`, `UUID`, `SerialNumber`, `Status`, `status_v2`, `Flag`
FROM `nicinventory`
WHERE `SourceType` = 'onboard'
  AND `Flag` = 'replaced';
