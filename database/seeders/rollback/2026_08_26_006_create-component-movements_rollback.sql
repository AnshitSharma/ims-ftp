-- =============================================================================
-- ROLLBACK for 2026_08_26_006_create-component-movements.sql
-- Date:     2026-08-26
-- Purpose:  Drop the component_movements table.
--
-- Tables:   component_movements (DROPPED)
--
-- ============================ READ THIS FIRST ================================
--
-- THIS DESTROYS THE ENTIRE HANDOVER HISTORY, AND IT IS THE ONLY COPY.
--   Every record of which part went where, when, why, who authorised it and --
--   crucially -- WHO PHYSICALLY CARRIED IT lives in this table. The activity
--   log carries a one-line summary and nothing structured: no from/to, no
--   serial, no custodian. Nothing else can reconstruct this, and the custodian
--   is exactly the field you will want when a part cannot be found.
--
--   EXPORT IT FIRST. Not a SELECT you skim -- a file you keep:
--     SELECT * FROM `component_movements` ORDER BY `moved_at`;
--
-- THE CODE SURVIVES THE DROP, BY DESIGN. ComponentRelocation::move() writes the
--   movement row behind a table probe, so with the table gone a handover still
--   happens, the part's location still changes, and it simply records nothing.
--   Nothing 500s, and the PHP does not have to be reverted first.
--
-- SECTION 1 IS THE SAFE STOP -- rename rather than drop, so the history is
--   parked out of the way and can be renamed back. Prefer it. Section 2 is the
--   irreversible half and is deliberately separate.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state. KEEP THIS OUTPUT. This is your only copy.
-- ---------------------------------------------------------------------------
SELECT COUNT(*)        AS movements_recorded,
       MIN(`moved_at`) AS first_move,
       MAX(`moved_at`) AS last_move,
       COUNT(DISTINCT `handover_user_id`) AS distinct_custodians
  FROM `component_movements`;

SELECT * FROM `component_movements` ORDER BY `moved_at`;

-- ---------------------------------------------------------------------------
-- 1. SAFE STOP -- park the table instead of destroying it. The code's probe
--    sees no `component_movements` and stops recording; the data is still
--    there under the new name, and RENAME back restores it whole.
-- ---------------------------------------------------------------------------
-- RENAME TABLE `component_movements` TO `component_movements_rolled_back_20260826`;

-- ---------------------------------------------------------------------------
-- 2. IRREVERSIBLE -- drop the table and every handover record in it.
--    Uncomment only when the export in section 0 is saved somewhere real.
-- ---------------------------------------------------------------------------
-- DROP TABLE IF EXISTS `component_movements`;

-- =============================================================================
-- Verification
-- =============================================================================

-- 1. After section 1: the parked copy exists and the live name does not.
SHOW TABLES LIKE 'component_movements%';

-- 2. After section 2: nothing matches at all.
SHOW TABLES LIKE 'component_movements';
