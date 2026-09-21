-- ============================================================================
-- Date:     2026-09-21
-- Purpose:  Index the two inventory columns the build and location paths look
--           units up by. Neither is indexed today on any of the twelve tables
--           (seeder 2026_09_17_001's header records that these tables had zero
--           secondary indexes before it added Status).
-- Tables:   cpuinventory, raminventory, storageinventory, motherboardinventory,
--           nicinventory, caddyinventory, chassisinventory, pciecardinventory,
--           risercardinventory, hbacardinventory, sfpinventory,
--           serverplatforminventory
-- Feature:  Backend & database audit 2026-09-21, §8.2 #1 and #3 (Phase H.1).
-- ============================================================================
--
-- ServerUUID
--   LocationResolver::syncComponentRows() runs UPDATE ... WHERE ServerUUID = ?
--   against ALL TWELVE tables on every server move; countComponents() counts on
--   it twelve times to produce one integer; ServerBuilder::releaseAllComponents()
--   and searchBySerial() both filter on it. Unindexed, each of those is a full
--   table scan, so one rack move is twelve of them — and RackEnclosure::
--   restampSleds() repeats the whole thing per sled.
--
-- UUID
--   This one is a CONCURRENCY fix as much as a performance one.
--   AddComponentCommand::lockAndCheckComponent() does
--       SELECT ... WHERE UUID = ? ... FOR UPDATE
--   and InnoDB locks the rows a statement SCANS, not the rows it matches. With
--   no index on UUID that is a full scan, so the lock covers the whole table and
--   two adds of different components into different builds serialise against
--   each other. getCompatibleComponents() also does WHERE UUID IN (…) and
--   GROUP BY UUID.
--
-- BEFORE YOU RUN THIS — check whether UUID is already indexed.
--   core/helpers/Inventory.php:259 states in passing that these tables carry
--   UNIQUE indexes on SerialNumber and AssetTag, and addComponent() has a
--   load-bearing '' -> NULL conversion that only makes sense if SerialNumber
--   really is UNIQUE. Seeder 2026_09_17_001 asserts the opposite: zero secondary
--   indexes. Both cannot be right, and this could not be settled from outside
--   the server.
--
--       SHOW INDEX FROM cpuinventory;
--
--   settles it in one line. If UUID already carries a key under another name,
--   skip the second block below — ADD KEY IF NOT EXISTS matches on the key NAME,
--   so it would happily add a redundant duplicate. The ServerUUID block is
--   unaffected either way.
--
-- Idempotent via MariaDB's native ADD KEY IF NOT EXISTS. Safe to paste twice.

-- ---- ServerUUID -----------------------------------------------------------

ALTER TABLE cpuinventory             ADD KEY IF NOT EXISTS ix_cpuinventory_serveruuid (ServerUUID);
ALTER TABLE raminventory             ADD KEY IF NOT EXISTS ix_raminventory_serveruuid (ServerUUID);
ALTER TABLE storageinventory         ADD KEY IF NOT EXISTS ix_storageinventory_serveruuid (ServerUUID);
ALTER TABLE motherboardinventory     ADD KEY IF NOT EXISTS ix_motherboardinventory_serveruuid (ServerUUID);
ALTER TABLE nicinventory             ADD KEY IF NOT EXISTS ix_nicinventory_serveruuid (ServerUUID);
ALTER TABLE caddyinventory           ADD KEY IF NOT EXISTS ix_caddyinventory_serveruuid (ServerUUID);
ALTER TABLE chassisinventory         ADD KEY IF NOT EXISTS ix_chassisinventory_serveruuid (ServerUUID);
ALTER TABLE pciecardinventory        ADD KEY IF NOT EXISTS ix_pciecardinventory_serveruuid (ServerUUID);
ALTER TABLE risercardinventory       ADD KEY IF NOT EXISTS ix_risercardinventory_serveruuid (ServerUUID);
ALTER TABLE hbacardinventory         ADD KEY IF NOT EXISTS ix_hbacardinventory_serveruuid (ServerUUID);
ALTER TABLE sfpinventory             ADD KEY IF NOT EXISTS ix_sfpinventory_serveruuid (ServerUUID);
ALTER TABLE serverplatforminventory  ADD KEY IF NOT EXISTS ix_serverplatforminventory_serveruuid (ServerUUID);

-- ---- UUID (skip this block if SHOW INDEX says UUID is already keyed) -------

ALTER TABLE cpuinventory             ADD KEY IF NOT EXISTS ix_cpuinventory_uuid (UUID);
ALTER TABLE raminventory             ADD KEY IF NOT EXISTS ix_raminventory_uuid (UUID);
ALTER TABLE storageinventory         ADD KEY IF NOT EXISTS ix_storageinventory_uuid (UUID);
ALTER TABLE motherboardinventory     ADD KEY IF NOT EXISTS ix_motherboardinventory_uuid (UUID);
ALTER TABLE nicinventory             ADD KEY IF NOT EXISTS ix_nicinventory_uuid (UUID);
ALTER TABLE caddyinventory           ADD KEY IF NOT EXISTS ix_caddyinventory_uuid (UUID);
ALTER TABLE chassisinventory         ADD KEY IF NOT EXISTS ix_chassisinventory_uuid (UUID);
ALTER TABLE pciecardinventory        ADD KEY IF NOT EXISTS ix_pciecardinventory_uuid (UUID);
ALTER TABLE risercardinventory       ADD KEY IF NOT EXISTS ix_risercardinventory_uuid (UUID);
ALTER TABLE hbacardinventory         ADD KEY IF NOT EXISTS ix_hbacardinventory_uuid (UUID);
ALTER TABLE sfpinventory             ADD KEY IF NOT EXISTS ix_sfpinventory_uuid (UUID);
ALTER TABLE serverplatforminventory  ADD KEY IF NOT EXISTS ix_serverplatforminventory_uuid (UUID);
