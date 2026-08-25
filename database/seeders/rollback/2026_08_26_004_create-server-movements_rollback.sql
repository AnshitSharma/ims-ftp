-- =============================================================================
-- ROLLBACK for 2026_08_26_004_create-server-movements.sql
-- Date:     2026-08-26
-- Purpose:  Drop the server_movements table.
--
-- Tables:   server_movements (DROPPED)
--
-- ============================ READ THIS FIRST ================================
--
-- THIS DESTROYS THE ENTIRE RELOCATION HISTORY, AND IT IS THE ONLY COPY.
--   Every record of which server went where, when, why and who authorised it
--   lives in this table. The activity log carries a one-line summary of each
--   move and nothing structured -- no from/to, no U positions, no component
--   count. Nothing else can reconstruct this.
--
--   EXPORT IT FIRST. Not a SELECT you skim -- a file you keep:
--     SELECT * FROM `server_movements` ORDER BY `moved_at`;
--
-- THE CODE SURVIVES THE DROP, BY DESIGN. ServerRelocation::move() writes the
--   movement row behind a table probe, so with the table gone a move still
--   happens, still propagates to every component, and simply records nothing.
--   The server History modal shows no movement section. Nothing 500s, and the
--   PHP does not have to be reverted first.
--
-- SECTION 1 IS THE SAFE STOP -- rename rather than drop, so the history is
--   parked out of the way and can be renamed back. Prefer it. Section 2 is the
--   irreversible half and is deliberately separate.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state. KEEP THIS OUTPUT. This is your only copy.
-- ---------------------------------------------------------------------------
SELECT COUNT(*)      AS movements_recorded,
       MIN(`moved_at`) AS first_move,
       MAX(`moved_at`) AS last_move,
       SUM(`components_moved`) AS components_moved_in_total
  FROM `server_movements`;

SELECT * FROM `server_movements` ORDER BY `moved_at`;

-- ---------------------------------------------------------------------------
-- 1. SAFE STOP -- park the table instead of destroying it. The code's probe
--    sees no `server_movements` and stops recording; the data is still there
--    under the new name, and RENAME back restores it whole.
-- ---------------------------------------------------------------------------
-- RENAME TABLE `server_movements` TO `server_movements_rolled_back_20260826`;

-- ---------------------------------------------------------------------------
-- 2. IRREVERSIBLE -- drop the table and every movement record in it.
--    Uncomment only when the export in section 0 is saved somewhere real.
-- ---------------------------------------------------------------------------
-- DROP TABLE IF EXISTS `server_movements`;

-- =============================================================================
-- Verification
-- =============================================================================

-- 1. After section 1: the parked copy exists and the live name does not.
SHOW TABLES LIKE 'server_movements%';

-- 2. After section 2: nothing matches at all.
SHOW TABLES LIKE 'server_movements';
