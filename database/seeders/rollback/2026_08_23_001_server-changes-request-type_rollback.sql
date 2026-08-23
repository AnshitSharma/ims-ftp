-- =============================================================================
-- Rollback for: 2026_08_23_001_server-changes-request-type.sql
-- Date:         2026-08-23
-- Tables:       pipeline_templates
--
-- Archives "Server Changes" and puts the pre-2026_08_23 Add Hardware description
-- back, so it once again points people at "Any server" for a new build.
--
-- The type is ARCHIVED, not deleted: requests may already have been raised from
-- it and `tickets` rows point at it via pipeline_template_id. Archiving takes it
-- out of the create-request dropdown while leaving that history readable, and
-- leaves its step and ceiling intact for a re-run of the forward seeder.
--
-- Nothing else needs undoing: the forward seeder revoked no grants, touched no
-- tickets, changed no other ceiling, and added no permission row.
--
-- NOTE: this does NOT undo the API-side narrowing (a request-granted holder
-- needs the matching {type}.create / {type}.edit to add, remove or replace a
-- component). That lives in api/api.php + core/helpers/BaseFunctions.php and is
-- reverted by code, not by SQL. With this rollback applied and that code still
-- deployed, whole-server work has no request type to ask through.
-- =============================================================================

START TRANSACTION;

UPDATE `pipeline_templates`
   SET `is_active`  = 0,
       `is_system`  = 0,
       `updated_at` = NOW()
 WHERE `name` = 'Server Changes';

UPDATE `pipeline_templates`
   SET `description` = 'Ask for temporary permission to add hardware. Choose where it goes: into a server configuration (pick which server, or "Any server" if you are starting a new build), or into the component inventory. An admin approves, the access lasts 24 hours and then expires by itself. Anything you add during the window stays.',
       `updated_at`  = NOW()
 WHERE `name` = 'Add Hardware';

COMMIT;

-- Verification -- Server Changes archived, three hardware types still live.
SELECT `id`, `name`, `is_active`, `is_system`, `updated_at`
FROM `pipeline_templates`
WHERE `name` IN ('Add Hardware', 'Edit Hardware', 'Remove Hardware', 'Server Changes')
ORDER BY `is_active` DESC, `name`;
