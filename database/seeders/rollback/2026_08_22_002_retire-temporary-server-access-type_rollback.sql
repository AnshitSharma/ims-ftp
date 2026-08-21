-- =============================================================================
-- Rollback for: 2026_08_22_002_retire-temporary-server-access-type.sql
-- Date:         2026-08-22
-- Tables:       pipeline_templates
--
-- Brings the "Temporary Server Creation Access" type back: active again and
-- flagged built-in, exactly as 2026_08_20_004 left it. Its steps and its
-- 3-permission effect_config were never touched, so it works immediately.
--
-- Nothing else needs undoing -- the forward seeder revoked no grants and
-- modified no tickets.
--
-- Note: you can also just hit Restore on the Request Types page, which flips
-- is_active back to 1. This file additionally restores is_system = 1 (the API
-- cannot set that flag), so the type is protected from rename/archive again.
-- =============================================================================

START TRANSACTION;

UPDATE `pipeline_templates`
   SET `is_active`  = 1,
       `is_system`  = 1,
       `updated_at` = NOW()
 WHERE `name` = 'Temporary Server Creation Access';

COMMIT;

SELECT t.id, t.name, t.is_active, t.is_system, s.name AS step, s.effect_config
FROM `pipeline_templates` t
LEFT JOIN `pipeline_stages` s ON s.pipeline_template_id = t.id
WHERE t.`name` = 'Temporary Server Creation Access';
