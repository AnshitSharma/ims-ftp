-- ============================================================================
-- Date:     2026-09-21
-- Purpose:  Index the hottest table in the build path, and the append-only log
--           the server detail page pages through.
-- Tables:   config_components, inventory_log
-- Feature:  Backend & database audit 2026-09-21, §8.2 #2 and #7 (Phase H.1).
-- ============================================================================
--
-- config_components (config_uuid, removed_at)
--   config_components is what a configuration CONTAINS — the nine legacy JSON
--   columns were dropped in 2026-08-30's seeders and this table is the only
--   store left. There are 22 distinct `FROM config_components` sites, and
--   ConfigComponentRepository::liveRows() — `WHERE config_uuid = ? AND
--   removed_at IS NULL` — runs on every read of every build, through
--   ConfigReadRouter. Both columns in one key so the live-rows filter is a
--   single index range rather than a scan plus a filter.
--
--   Note fk_cc_parent already exists on parent_id (the code names it), and
--   InnoDB indexes a foreign key's columns automatically, so parent_id is
--   covered. config_uuid is probably NOT a foreign key — ServerBuilder deletes
--   config rows and child rows in a hand-ordered sequence to work around
--   RESTRICT behaviour, which implies only some of those relationships are
--   enforced. Hence this key.
--
-- inventory_log (component_type, component_id, created_at)
--   server-get-logs filters `component_type = 'server' AND component_id = ?`
--   and orders by created_at; the count query that drives its pagination
--   repeats the same predicate. Two equalities then the sort, so the column
--   order matches. This is the most-written table touched by this seeder —
--   every logged action inserts — so it is the one index here with a real write
--   cost. It is worth it: the table is append-only and only grows, so the read
--   side degrades forever without it while the write side pays a constant.
--
-- Idempotent via MariaDB's native ADD KEY IF NOT EXISTS. Safe to paste twice.
-- Verify with SHOW INDEX FROM config_components; and SHOW INDEX FROM inventory_log;

ALTER TABLE config_components
    ADD KEY IF NOT EXISTS ix_cc_config_removed (config_uuid, removed_at);

ALTER TABLE inventory_log
    ADD KEY IF NOT EXISTS ix_invlog_type_id_created (component_type, component_id, created_at);
