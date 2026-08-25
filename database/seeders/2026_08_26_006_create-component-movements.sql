-- =============================================================================
-- Date:     2026-08-26
-- Purpose:  Record every physical relocation of an individual COMPONENT between
--           sites: where it was, where it went, who carried it, why, and which
--           Request authorised it.
--
--           server_movements (2026_08_26_004) covers a machine and everything
--           inside it. It cannot describe the other half of the problem: a
--           loose SSD sitting in Noida that has to reach a server racked in
--           Jaipur before it can be fitted. That handover is a real physical
--           event with a custodian, and until now it left no trace at all --
--           an admin would simply edit the Location text and the fact that a
--           named person carried the part across the country was lost.
--
-- Tables:   component_movements (CREATE)
-- Feature:  Location-aware Requests + Hardware Handover, part 1 of 3
-- Requires: 2026_08_26_001 and _003 (the locations the from/to uuids point at,
--           and the inventory location_uuid / StoreLocation columns the move
--           writes). This file will apply without them; the uuid columns would
--           simply never be populated until the rest of the set is run.
--
-- WHY THIS IS A SEPARATE TABLE FROM server_movements
--   The two records answer different questions and have different shapes. A
--   server move has racks and U positions and a component count; a component
--   move has a serial number, a shelf and a custodian. Forcing both into one
--   table would mean a dozen columns that are always NULL on one side, and
--   every reader filtering on a discriminator to avoid reading nonsense. The
--   server History modal and the component History view are separate screens
--   asking separate questions.
--
-- WHY NAMES ARE SNAPSHOTTED ALONGSIDE THE UUIDs
--   Identical reasoning to 2026_08_26_004: a movement record is HISTORY. It has
--   to still read correctly after the location is renamed, after the component
--   model is superseded, and after the location row is deleted outright. The
--   uuids are kept too, so a live location can still be linked when it exists.
--
-- WHY handover_user_id IS SEPARATE FROM moved_by
--   moved_by is who caused the row to be written -- the admin who approved the
--   Hardware Handover request. handover_user_id is who physically carried the
--   hardware and signed for it. They are almost never the same person, and the
--   custodian is the whole point of the record.
--
-- Notes:    - No FOREIGN KEYs, matching server_movements and the pipeline
--             tables -- logical FKs by house convention. Load-bearing here:
--             history must outlive the rows it names.
--           - inventory_id identifies the PHYSICAL UNIT (a row in
--             {type}inventory). component_uuid identifies the MODEL and is
--             recorded alongside it because the unit row may later be deleted.
--           - ticket_id is set when the move was performed by an approved
--             Hardware Handover Request, NULL when an admin did it directly.
--           - from_store_location / to_store_location are the shelf/bin text
--             (StoreLocation), which is what actually tells someone where to
--             go and pick the part up once they are at the site.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS. Re-running is a no-op.
-- Rollback:   rollback/2026_08_26_006_create-component-movements_rollback.sql
-- =============================================================================

CREATE TABLE IF NOT EXISTS `component_movements` (
  `id`                   BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,

  `component_type`       VARCHAR(20)  NOT NULL COMMENT 'cpu | ram | storage | motherboard | nic | caddy | chassis | pciecard | risercard | hbacard | sfp | serverplatform',
  `inventory_id`         INT(11)      NOT NULL COMMENT 'Logical FK -> {component_type}inventory.ID -- the physical unit',
  `component_uuid`       CHAR(36)     DEFAULT NULL COMMENT 'Logical FK -> the ims-data model UUID',
  `component_name`       VARCHAR(255) DEFAULT NULL COMMENT 'Snapshot: model name at the time of the move',
  `serial_number`        VARCHAR(100) DEFAULT NULL COMMENT 'Snapshot: the unit''s serial at the time of the move',
  `asset_tag`            VARCHAR(100) DEFAULT NULL COMMENT 'Snapshot',

  `from_location_uuid`   CHAR(36)     DEFAULT NULL COMMENT 'Logical FK -> locations.location_uuid',
  `from_location_name`   VARCHAR(100) DEFAULT NULL COMMENT 'Snapshot: survives a rename or delete',
  `from_store_location`  VARCHAR(100) DEFAULT NULL COMMENT 'Snapshot of the shelf / bin it left',

  `to_location_uuid`     CHAR(36)     DEFAULT NULL COMMENT 'Logical FK -> locations.location_uuid',
  `to_location_name`     VARCHAR(100) DEFAULT NULL COMMENT 'Snapshot',
  `to_store_location`    VARCHAR(100) DEFAULT NULL COMMENT 'Snapshot of the shelf / bin it arrived at',

  `reason`               VARCHAR(255) DEFAULT NULL COMMENT 'Free text from the mover',
  `ticket_id`            INT(10) UNSIGNED DEFAULT NULL COMMENT 'Logical FK -> tickets.id when moved by an approved Request',
  `handover_user_id`     INT(6) UNSIGNED  DEFAULT NULL COMMENT 'Logical FK -> users.id -- WHO PHYSICALLY CARRIED IT',
  `moved_by`             INT(6) UNSIGNED  DEFAULT NULL COMMENT 'Logical FK -> users.id -- who authorised / performed the update',
  `moved_at`             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_component_movements_unit` (`component_type`, `inventory_id`, `moved_at`),
  KEY `idx_component_movements_to_location` (`to_location_uuid`),
  KEY `idx_component_movements_ticket` (`ticket_id`),
  KEY `idx_component_movements_handover` (`handover_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Physical relocation history for individual inventory units';

-- =============================================================================
-- Verification
-- =============================================================================

-- 1. The table exists with all 19 columns.
SHOW COLUMNS FROM `component_movements`;

-- 2. All four secondary indexes present. idx_component_movements_unit is the
--    one a component History view reads.
SHOW INDEX FROM `component_movements`;

-- 3. Empty on a fresh install -- history starts from the first handover made
--    after this file is applied. MUST return 0.
SELECT COUNT(*) AS movement_rows FROM `component_movements`;
