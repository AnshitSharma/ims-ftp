-- ============================================================================
-- Date:     2026-09-17
-- Purpose:  Index the one column every {type}inventory list/count query filters on exactly.
--           getComponentCountByType()/getComponentsByType() (core/helpers/BaseFunctions.php)
--           run `WHERE ... Status = ?` (plus optional location_uuid) on every paginated
--           component list and its matching COUNT(*), twice per request, over tables with
--           zero secondary indexes today.
-- Tables:   cpuinventory, raminventory, storageinventory, motherboardinventory,
--           nicinventory, caddyinventory, chassisinventory, pciecardinventory,
--           risercardinventory, hbacardinventory, sfpinventory, serverplatforminventory
-- Feature:  Simplification & architecture audit 2026-09-16, Phase 4 / P3 finding #3.
-- ============================================================================
--
-- SCOPE NOTE -- why this seeder does NOT index the free-text search columns.
--   buildComponentSearchWhere() also matches AssetTag/SerialNumber/UUID/Notes/Location/
--   RackPosition with `LIKE '%term%'` (leading wildcard, both sides). A normal BTREE index
--   cannot serve a leading-wildcard LIKE at all -- MariaDB has no range to scan -- so an index
--   on those columns would add write cost for zero read benefit. A FULLTEXT index would be
--   read but changes MATCH semantics (word-boundary tokens, a minimum token length, no
--   substring-within-a-token matching), which would silently change what a saved search
--   finds against serial numbers and asset tags. That is a correctness change, not a perf
--   one, and is out of scope here.
--
-- SCOPE NOTE -- why this seeder does NOT index location_uuid.
--   location_uuid is guarded at the call site (SchemaHelper::hasColumn(), BaseFunctions.php
--   buildComponentSearchWhere()) because this environment has no way to confirm, without a
--   schema-introspection query the production DB user is denied (root CLAUDE.md: such a guard
--   fails open), which of the 12 tables have that column today and which are still waiting on
--   the seeder that adds it. Indexing a column that does not exist yet on some tables would
--   abort this whole script on the first missing one. Once location_uuid is confirmed present
--   on all twelve tables (SHOW COLUMNS, run by hand), add its index in a follow-up seeder.
--
-- `Status` has been a plain int column on every {type}inventory table since before this
-- seeder system existed, so no existence guard is needed for it. Idempotent via MariaDB's
-- native `ADD KEY IF NOT EXISTS` -- safe to paste twice.

ALTER TABLE cpuinventory ADD KEY IF NOT EXISTS ix_cpuinventory_status (Status);
ALTER TABLE raminventory ADD KEY IF NOT EXISTS ix_raminventory_status (Status);
ALTER TABLE storageinventory ADD KEY IF NOT EXISTS ix_storageinventory_status (Status);
ALTER TABLE motherboardinventory ADD KEY IF NOT EXISTS ix_motherboardinventory_status (Status);
ALTER TABLE nicinventory ADD KEY IF NOT EXISTS ix_nicinventory_status (Status);
ALTER TABLE caddyinventory ADD KEY IF NOT EXISTS ix_caddyinventory_status (Status);
ALTER TABLE chassisinventory ADD KEY IF NOT EXISTS ix_chassisinventory_status (Status);
ALTER TABLE pciecardinventory ADD KEY IF NOT EXISTS ix_pciecardinventory_status (Status);
ALTER TABLE risercardinventory ADD KEY IF NOT EXISTS ix_risercardinventory_status (Status);
ALTER TABLE hbacardinventory ADD KEY IF NOT EXISTS ix_hbacardinventory_status (Status);
ALTER TABLE sfpinventory ADD KEY IF NOT EXISTS ix_sfpinventory_status (Status);
ALTER TABLE serverplatforminventory ADD KEY IF NOT EXISTS ix_serverplatforminventory_status (Status);
