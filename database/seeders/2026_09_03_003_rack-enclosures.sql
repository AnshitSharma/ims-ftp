-- =============================================================================
-- Date:     2026-09-03
-- Purpose:  Let one racked enclosure hold several servers, so a Dell PowerEdge
--           FX2s (2U, four half-width FC630 sleds) can be recorded as what it
--           is: ONE thing occupying U-space, holding FOUR servers.
--
--           Until now `rack_servers` addressed a server as a VERTICAL RANGE
--           only -- (rack_uuid, start_u, u_height) -- and
--           ServerRelocation::validateRackTarget() rejected any range that
--           intersected another. There was no horizontal axis and no object
--           between "rack" and "server", so the second sled at U20 was refused
--           with a 409 and the FX2s itself had nowhere to live.
--
--           After this, a placement is one of two shapes:
--
--             DIRECT   enclosure_uuid IS NULL, slot_index IS NULL
--                      -> occupies its own U range in the rack (today's shape)
--
--             SLOTTED  enclosure_uuid + slot_index set
--                      -> occupies a slot in an enclosure; the enclosure owns
--                         the U range
--
--           Rack U-overlap is then checked over DIRECT servers + ENCLOSURES.
--           Slotted rows are excluded from it entirely -- their U belongs to
--           the enclosure, and counting it again is what would make four sleds
--           read as 8U of a 48U rack.
--
-- Tables:   rack_enclosures (NEW)
--           rack_servers    (2 new columns + 1 unique index)
-- Feature:  Blade enclosures / Rack View (tasks/blade-enclosure-fx2s.md)
--
-- Notes:    - SLOTTED ROWS STILL CARRY start_u AND u_height, MIRRORED FROM
--             THEIR ENCLOSURE. This is deliberate and is the one thing to
--             understand before touching this table. LocationResolver reads
--             rs.start_u / rs.u_height to stamp the rack address onto the
--             server AND onto every component installed in it (~14 inventory
--             rows per build); RackPlacement::syncPositionText, rack-get and
--             the servers list read them too. Mirroring keeps all of that
--             correct with no change. Leaving them NULL would silently blank
--             the location on every part inside every blade.
--
--             The cost is that moving an enclosure must re-stamp its sleds:
--             RackEnclosure::restampSleds() does that, and is the only writer
--             of the mirror.
--
--           - NO BACKFILL IS NEEDED AND NONE IS DONE. Both new columns are
--             NULLable with no default, so every existing rack_servers row is
--             already a valid DIRECT placement. MariaDB permits any number of
--             rows to share NULL under a unique index, so the slot index below
--             constrains only rows that are actually in an enclosure -- the
--             same property seeder 2026_09_03_001 relies on for serial_number.
--
--           - NO NEW ACL ROWS AND NO NEW PERMISSIONS. An enclosure is rack
--             furniture: adding, moving and removing one is `rack.edit`, and
--             slotting a server into it is `rack.assign`. Both already exist
--             (seeder 2026_06_17_001) and are already granted to the roles that
--             hold the matching server.* permissions. The three new API actions
--             (rack-enclosure-add / -update / -remove) map onto them in
--             api/permission_map.php.
--
--           - Idempotent via native CREATE TABLE / ADD COLUMN / ADD INDEX
--             IF NOT EXISTS (MariaDB 10.0.2+). Deliberately NOT the
--             metadata-schema guard pattern: the application DB user has no
--             grant for that schema on this host, so such seeders die at
--             PREPARE before any ALTER runs -- and the guard then fails open,
--             reporting success while changing nothing. Verify with SHOW
--             COLUMNS / SHOW INDEX instead.
--
--           - NO FOREIGN KEYS, matching racks/rack_servers, which are joined
--             logically on uuid. Referential integrity is enforced in the
--             application layer: RackEnclosure::remove() refuses while any
--             sled is still slotted, and handleRackDelete() refuses while the
--             rack still holds servers or enclosures.
--
--           - COLLATION IS PINNED TO utf8mb4_general_ci to match `racks` and
--             `rack_servers`. Seeder 2026_06_17_002 exists solely to repair a
--             collation mismatch on rack_servers.config_uuid that broke the
--             join to server_configurations; the same mistake here would break
--             the join from rack_servers.enclosure_uuid.
--
--           - Deploy ordering: PHP reaches production ~20s after save, this
--             seeder is applied by hand afterwards. Every read of
--             rack_enclosures is behind SchemaHelper::hasTable() and every read
--             of the two new columns behind SchemaHelper::hasColumn(), so the
--             window before this file is run is harmless -- Rack View behaves
--             exactly as it does today and no enclosure can be created. The
--             feature switches on when this lands.
--
--           - The FX2s spec itself lives in ims-data/chassis/chasis-level-3.json
--             (uuid b022a51f-3fc4-47f8-a279-3511dab61897, u_size 2, enclosure
--             block with slot_rows 2 / slot_cols 2). ims-data has NO deploy
--             watcher -- that file must be uploaded by hand to /ims-data/ on the
--             web root, or no enclosure can be created for want of a geometry.
--
--           - Rollback: rollback/2026_09_03_003_rack-enclosures_rollback.sql
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. rack_enclosures -- one physical enclosure occupying a U range in a rack
--
--    slot_rows x slot_cols is the bay grid, snapshotted from the chassis spec's
--    `enclosure` block at creation time for the same reason rack_servers.u_height
--    is snapshotted: the elevation must keep rendering correctly even if the
--    spec file is later edited or the model retired.
--
--    Row-major numbering, 1-based: for the FX2s (2x2) slot 1 is top-left,
--    2 top-right, 3 bottom-left, 4 bottom-right -- matching Dell's own bay
--    labelling. RackEnclosure::slotGeometry() is the single implementation.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rack_enclosures` (
  `id`             INT(11)      NOT NULL AUTO_INCREMENT,
  `enclosure_uuid` VARCHAR(36)  NOT NULL                COMMENT 'Stable public identifier',
  `rack_uuid`      VARCHAR(36)  NOT NULL                COMMENT 'FK (logical) -> racks.rack_uuid',
  `name`           VARCHAR(100) NOT NULL                COMMENT 'Display name, e.g. FX2S-01',
  `chassis_uuid`   VARCHAR(36)  DEFAULT NULL            COMMENT 'FK (logical) -> ims-data chassis spec uuid (the enclosure model)',
  `model`          VARCHAR(100) DEFAULT NULL            COMMENT 'Snapshot of the spec model name, e.g. PowerEdge FX2s',
  `serial_number`  VARCHAR(50)  DEFAULT NULL            COMMENT 'Service tag of the enclosure itself, operator-entered',
  `start_u`        INT(11)      NOT NULL                COMMENT 'Lowest U occupied (1-based)',
  `u_height`       INT(11)      NOT NULL DEFAULT 1      COMMENT 'Number of U occupied (>=1), from the spec u_size',
  `slot_rows`      TINYINT(4)   NOT NULL DEFAULT 1      COMMENT 'Bay grid rows (FX2s = 2)',
  `slot_cols`      TINYINT(4)   NOT NULL DEFAULT 1      COMMENT 'Bay grid columns (FX2s = 2)',
  `notes`          TEXT         DEFAULT NULL,
  `created_by`     INT(6) UNSIGNED DEFAULT NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rack_enclosures_uuid` (`enclosure_uuid`),
  KEY `idx_rack_enclosures_rack` (`rack_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Blade/modular enclosures placed in racks; servers slot into these';

-- -----------------------------------------------------------------------------
-- 2. rack_servers -- the horizontal axis
--
--    NULL enclosure_uuid = a direct placement, which is every row that exists
--    today. Nothing is backfilled and nothing changes meaning.
-- -----------------------------------------------------------------------------
ALTER TABLE `rack_servers`
    ADD COLUMN IF NOT EXISTS `enclosure_uuid` VARCHAR(36) DEFAULT NULL
        COMMENT 'FK (logical) -> rack_enclosures.enclosure_uuid. NULL = placed directly in the rack.'
        AFTER `rack_uuid`;

ALTER TABLE `rack_servers`
    ADD COLUMN IF NOT EXISTS `slot_index` TINYINT(4) DEFAULT NULL
        COMMENT 'Bay number within the enclosure, 1-based row-major. NULL when enclosure_uuid is NULL.'
        AFTER `enclosure_uuid`;

-- One sled per bay. NULLs repeat freely under a MariaDB unique index, so this
-- constrains slotted rows only and leaves every direct placement untouched.
ALTER TABLE `rack_servers`
    ADD UNIQUE INDEX IF NOT EXISTS `uq_rack_servers_slot` (`enclosure_uuid`, `slot_index`);

-- =============================================================================
-- Verification (run after the seeder):
--
--   Expected: rack_enclosures exists and is empty; rack_servers has the two new
--   columns, both NULL on all 21 existing rows; the slot index is UNIQUE.
-- =============================================================================
SHOW TABLES LIKE 'rack_enclosures';

SHOW COLUMNS FROM `rack_servers` LIKE 'enclosure_uuid';
SHOW COLUMNS FROM `rack_servers` LIKE 'slot_index';

SHOW INDEX FROM `rack_servers` WHERE `Key_name` = 'uq_rack_servers_slot';

SELECT COUNT(*)                    AS placements_total,
       COUNT(`enclosure_uuid`)     AS placements_slotted,
       COUNT(*) - COUNT(`enclosure_uuid`) AS placements_direct
  FROM `rack_servers`;
