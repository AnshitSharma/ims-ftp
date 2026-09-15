-- ============================================================================
-- Date:     2026-09-16
-- Purpose:  Give server_configuration_history a real schema definition instead of
--           leaving it to whichever runtime code path happens to create it first.
-- Tables:   server_configuration_history (created if absent; reconciled if present)
-- Feature:  JSON & data-model audit, finding JSON-020 ("one table created at runtime")
-- ============================================================================
--
-- WHY THIS EXISTS
--   This table is the only one in the system with no seeder. It is created on demand by
--   TWO different helpers, and THEY DISAGREE:
--
--     api/handlers/server/server_api.php  createConfigurationHistoryTable()
--        ... has created_by int(11) DEFAULT NULL  and  KEY idx_action (action)
--
--     core/models/server/ServerBuilder.php  createHistoryTable()
--        ... has NEITHER.
--
--   Both run CREATE TABLE IF NOT EXISTS, so the shape of the table on any given
--   installation is decided by which code path ran first on that database -- and the
--   loser's definition is then silently ignored forever. An install that happened to
--   create it through ServerBuilder has no created_by column, so history rows there
--   cannot say who made the change.
--
--   This seeder defines the SUPERSET (the server_api.php shape, which is the richer of
--   the two) and reconciles an existing table up to it.
--
-- SAFE TO RUN REPEATEDLY. Creates nothing twice, drops nothing, rewrites no rows.
--
-- NOTE ON THE RUNTIME CREATORS: they are deliberately LEFT IN PLACE. Code reaches
-- production about twenty seconds after save and seeders are applied by hand afterwards,
-- so removing them would leave a window where the table may not exist and history
-- logging would start failing. They are idempotent; once this seeder has been applied
-- they simply never fire.
--
-- Guarded with MariaDB's own IF NOT EXISTS clauses only, and verified with SHOW
-- COLUMNS / SHOW INDEX, per the house rule for this directory.
-- ============================================================================

CREATE TABLE IF NOT EXISTS server_configuration_history (
    id int(11) NOT NULL AUTO_INCREMENT,
    config_uuid varchar(36) NOT NULL,
    action varchar(50) NOT NULL COMMENT 'created, updated, component_added, component_removed, validated, configuration_updated, etc.',
    component_type varchar(20) DEFAULT NULL,
    component_uuid varchar(36) DEFAULT NULL,
    metadata text DEFAULT NULL COMMENT 'JSON metadata for the action',
    created_by int(11) DEFAULT NULL,
    created_at timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    KEY idx_config_uuid (config_uuid),
    KEY idx_component_uuid (component_uuid),
    KEY idx_created_at (created_at),
    KEY idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reconcile a table that was created by the ServerBuilder path (no created_by, no
-- idx_action). MariaDB's IF NOT EXISTS on ADD COLUMN / ADD KEY makes both no-ops when
-- the richer definition is already in place.
ALTER TABLE server_configuration_history
    ADD COLUMN IF NOT EXISTS created_by int(11) DEFAULT NULL AFTER metadata;

ALTER TABLE server_configuration_history
    ADD KEY IF NOT EXISTS idx_action (action);

-- Verification (run by hand; both should return rows):
--   SHOW COLUMNS FROM server_configuration_history LIKE 'created_by';
--   SHOW INDEX  FROM server_configuration_history WHERE Key_name = 'idx_action';
