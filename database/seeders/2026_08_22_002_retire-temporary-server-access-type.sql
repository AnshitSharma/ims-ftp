-- =============================================================================
-- Date:     2026-08-22
-- Purpose:  Retire the built-in "Temporary Server Creation Access" Request Type.
--           It is now redundant: 2026_08_21_003 ("Temporary Access Request")
--           carries a 27-permission ceiling and a per-request access picker, so
--           ticking only the three server boxes there produces a byte-identical
--           grant. Two near-identically named types for one job only confuses
--           requesters and approvers.
--
-- Tables:   pipeline_templates (1 row UPDATE)
-- Feature:  Temporary approval-gated access (Requests module), consolidation
--
-- WHAT THIS DOES / DOES NOT DO
--   - ARCHIVES (is_active = 0). It does NOT delete: `tickets` rows point at this
--     template via pipeline_template_id, and deleting would orphan that history.
--     Its steps are left intact for the same reason.
--   - Also clears is_system, turning it into an ordinary archived type. This is
--     deliberate: PipelineTemplateManager::updateTemplate() L246 refuses to
--     archive an is_system type, so leaving the flag set would create a type
--     that the UI can restore but never re-archive. Cleared, the Archive/Restore
--     toggle on the Request Types page works both ways.
--   - Existing requests are UNAFFECTED. is_active is only checked when a request
--     is created (PipelineManager::createPipeline L61); in-flight requests of
--     this type still advance, and their approval step still grants access.
--   - Grants already issued are UNAFFECTED and still lapse on their own 24h
--     schedule. Nothing is revoked here.
--   - After this runs the type disappears from the create-request dropdown
--     (which asks for active types only) and shows greyed with an "Archived"
--     badge on the Request Types page for admins.
--
-- Idempotent: re-running is a no-op UPDATE.
-- Rollback:  rollback/2026_08_22_002_retire-temporary-server-access-type_rollback.sql
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state -- how many requests were ever raised from it, and how many
--    are still open. Open ones keep working; this is for your awareness only.
-- ---------------------------------------------------------------------------
SELECT
    t.id          AS template_id,
    t.is_active   AS is_active_before,
    t.is_system   AS is_system_before,
    (SELECT COUNT(*) FROM `tickets` k
      WHERE k.`pipeline_template_id` = t.id)                       AS requests_total,
    (SELECT COUNT(*) FROM `tickets` k
      WHERE k.`pipeline_template_id` = t.id
        AND k.`status` IN ('draft','in_progress'))                 AS requests_still_open
FROM `pipeline_templates` t
WHERE t.`name` = 'Temporary Server Creation Access';

-- ---------------------------------------------------------------------------
-- 1. Archive it.
-- ---------------------------------------------------------------------------
START TRANSACTION;

UPDATE `pipeline_templates`
   SET `is_active`  = 0,
       `is_system`  = 0,
       `updated_at` = NOW()
 WHERE `name` = 'Temporary Server Creation Access';

COMMIT;

-- ---------------------------------------------------------------------------
-- 2. Verification -- expect the retired type inactive, and exactly one ACTIVE
--    temporary-access type left standing ("Temporary Access Request").
-- ---------------------------------------------------------------------------
SELECT t.id, t.name, t.is_active, t.is_system, s.effect_type,
       JSON_LENGTH(JSON_EXTRACT(s.effect_config, '$.permissions')) AS ceiling_size
FROM `pipeline_templates` t
LEFT JOIN `pipeline_stages` s
       ON s.pipeline_template_id = t.id
      AND s.effect_type = 'grant_temporary_permission'
WHERE t.`name` IN ('Temporary Server Creation Access', 'Temporary Access Request')
ORDER BY t.is_active DESC, t.name;
