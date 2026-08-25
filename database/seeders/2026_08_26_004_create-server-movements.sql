-- =============================================================================
-- Date:     2026-08-26
-- Purpose:  Record every physical relocation of a server: where it was, where
--           it went, how many components travelled with it, who moved it and
--           why.
--
--           Until now a move left no trace beyond an activity-log line. The
--           rack elevation showed the CURRENT position and nothing else, so
--           "when did this leave Noida, and who signed off?" was unanswerable
--           -- which is the question that matters when hardware goes missing.
--
-- Tables:   server_movements (CREATE)
-- Feature:  Location hierarchy + server relocation, part 4 of 5
-- Requires: 2026_08_26_001 and _003 (the locations the from/to uuids point at).
--           This file will apply without them; the uuid columns would simply
--           never be populated until the rest of the set is run.
--
-- WHY NAMES ARE SNAPSHOTTED ALONGSIDE THE UUIDs
--   from_location_name / to_location_name / from_rack_name / to_rack_name are
--   deliberate duplicates of data reachable by join. A movement record is
--   HISTORY: it has to still read correctly after the location is renamed,
--   after the rack is decommissioned, and after the location row is deleted
--   outright. A join-only design would silently rewrite the past every time
--   somebody tidied a name, and blank it entirely on a delete. The uuids are
--   kept too, so a live location can still be linked when it does exist.
--
-- WHY components_moved IS STORED
--   It is the count of inventory rows this move actually re-stamped, captured
--   at the time. Recomputing it later gives the count of what is in the server
--   NOW, which is a different number as soon as one part is swapped. The stored
--   value is the record of what this move did.
--
-- WHY NULL IS A VALID to_rack_uuid
--   Pulling a server out of a rack but leaving it at the site is a real move
--   (it is now on a bench in the same building). from_*/to_* rack columns are
--   nullable so an unrack, a rerack and a site transfer are all one shape.
--
-- Notes:    - No FOREIGN KEY on config_uuid or the location/rack uuids, matching
--             rack_servers and the pipeline tables -- logical FKs by house
--             convention (see 2026_06_18_001). Here it is load-bearing rather
--             than stylistic: history must outlive the rows it names.
--           - ticket_id is set when the move was performed by an approved
--             Request (RequestActionExecutor action `server.relocate`), NULL
--             when an admin did it directly from the server card.
--           - reason is optional free text from the mover.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS. Re-running is a no-op.
-- Rollback:   rollback/2026_08_26_004_create-server-movements_rollback.sql
-- =============================================================================

CREATE TABLE IF NOT EXISTS `server_movements` (
  `id`                 BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `config_uuid`        CHAR(36)     NOT NULL COMMENT 'Logical FK -> server_configurations.config_uuid',

  `from_location_uuid` CHAR(36)     DEFAULT NULL COMMENT 'Logical FK -> locations.location_uuid',
  `from_location_name` VARCHAR(100) DEFAULT NULL COMMENT 'Snapshot: survives a rename or delete',
  `from_rack_uuid`     CHAR(36)     DEFAULT NULL COMMENT 'Logical FK -> racks.rack_uuid; NULL when it was unracked',
  `from_rack_name`     VARCHAR(100) DEFAULT NULL COMMENT 'Snapshot',
  `from_floor`         VARCHAR(50)  DEFAULT NULL COMMENT 'Snapshot of racks.floor',
  `from_start_u`       INT(11)      DEFAULT NULL COMMENT 'Lowest U occupied before the move',
  `from_u_height`      INT(11)      DEFAULT NULL COMMENT 'U count before the move',

  `to_location_uuid`   CHAR(36)     DEFAULT NULL COMMENT 'Logical FK -> locations.location_uuid',
  `to_location_name`   VARCHAR(100) DEFAULT NULL COMMENT 'Snapshot',
  `to_rack_uuid`       CHAR(36)     DEFAULT NULL COMMENT 'NULL when the server was pulled out of the rack',
  `to_rack_name`       VARCHAR(100) DEFAULT NULL COMMENT 'Snapshot',
  `to_floor`           VARCHAR(50)  DEFAULT NULL COMMENT 'Snapshot of racks.floor',
  `to_start_u`         INT(11)      DEFAULT NULL COMMENT 'Lowest U occupied after the move',
  `to_u_height`        INT(11)      DEFAULT NULL COMMENT 'U count after the move',

  `components_moved`   INT(11)      NOT NULL DEFAULT 0 COMMENT 'Inventory rows re-stamped by this move',
  `reason`             VARCHAR(255) DEFAULT NULL COMMENT 'Free text from the mover',
  `ticket_id`          INT(10) UNSIGNED DEFAULT NULL COMMENT 'Logical FK -> tickets.id when moved by an approved Request',
  `moved_by`           INT(6) UNSIGNED  DEFAULT NULL COMMENT 'Logical FK -> users.id',
  `moved_at`           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_server_movements_config` (`config_uuid`, `moved_at`),
  KEY `idx_server_movements_to_location` (`to_location_uuid`),
  KEY `idx_server_movements_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Physical relocation history for server configurations';

-- =============================================================================
-- Verification
-- =============================================================================

-- 1. The table exists with all 22 columns.
SHOW COLUMNS FROM `server_movements`;

-- 2. All three secondary indexes present. idx_server_movements_config is the
--    one the server History modal reads.
SHOW INDEX FROM `server_movements`;

-- 3. Empty on a fresh install -- history starts from the first move made after
--    this file is applied. MUST return 0.
SELECT COUNT(*) AS movement_rows FROM `server_movements`;
