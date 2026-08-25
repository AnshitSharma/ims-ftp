-- =============================================================================
-- ROLLBACK for 2026_08_25_001_inventory-access-request-type.sql
-- Date:     2026-08-25
-- Purpose:  Withdraw the "Inventory Access" Request Type.
--
-- Tables:   pipeline_templates (archived, or deleted if never used)
--           pipeline_stages    (deleted only when the type is deleted)
--
-- ARCHIVE FIRST, DELETE ONLY IF UNUSED.
--   tickets.pipeline_template_id points at the type. Deleting a type that has
--   requests raised against it leaves those requests without a type to resolve
--   -- the same reasoning as 2026_08_22_002, 2026_08_22_004 and 2026_08_23_004.
--   Section 1 archives unconditionally, which is enough to take the type out of
--   circulation: an inactive type is not offered by the create form and cannot
--   be used for a new request. Section 2 is OPTIONAL and guarded.
--
--   This rollback grants and revokes NOTHING. Any access an admin assigned by
--   hand while acting on one of these requests is real, permanent, role- or
--   permission-based access, and it is unaffected here. Reverse it on the ACL
--   page if that is what you meant to do.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state. Note requests_raised -- it decides whether section 2 runs.
-- ---------------------------------------------------------------------------
SELECT t.`id`, t.`name`, t.`is_active`,
       (SELECT COUNT(*) FROM `tickets` k WHERE k.`pipeline_template_id` = t.`id`) AS requests_raised
  FROM `pipeline_templates` t
 WHERE t.`name` = 'Inventory Access';

-- ---------------------------------------------------------------------------
-- 1. Archive. Always safe.
-- ---------------------------------------------------------------------------
UPDATE `pipeline_templates`
   SET `is_active`  = 0,
       `is_system`  = 0,
       `updated_at` = NOW()
 WHERE `name` = 'Inventory Access';

-- ---------------------------------------------------------------------------
-- 2. OPTIONAL hard delete -- only fires when no request was ever raised from
--    this type. Both statements are self-guarding, so running them when
--    requests DO exist changes nothing.
-- ---------------------------------------------------------------------------
DELETE s FROM `pipeline_stages` s
  JOIN `pipeline_templates` t ON t.`id` = s.`pipeline_template_id`
 WHERE t.`name` = 'Inventory Access'
   AND NOT EXISTS (SELECT 1 FROM `tickets` k WHERE k.`pipeline_template_id` = t.`id`);

DELETE t FROM `pipeline_templates` t
 WHERE t.`name` = 'Inventory Access'
   AND NOT EXISTS (SELECT 1 FROM `tickets` k WHERE k.`pipeline_template_id` = t.`id`);

-- ---------------------------------------------------------------------------
-- 3. After-state. Expect either one row with is_active = 0, or no rows.
-- ---------------------------------------------------------------------------
SELECT t.`id`, t.`name`, t.`is_active`,
       (SELECT COUNT(*) FROM `pipeline_stages` s WHERE s.`pipeline_template_id` = t.`id`) AS stages
  FROM `pipeline_templates` t
 WHERE t.`name` = 'Inventory Access';
