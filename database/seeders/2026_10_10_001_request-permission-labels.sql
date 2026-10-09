-- =============================================================================
-- 2026_10_10_001_request-permission-labels.sql
--
-- Date:     2026-10-10
-- Purpose:  Permission NAMES shown in Access Control said "Pipeline" and
--           "Stage" (engine words) where the UI says Requests and Steps, and two
--           different permissions were both called "View System Logs".
--           Only display_name / description change. The permission keys
--           (pipeline.*, system.*) and every grant are untouched.
-- Tables:   permissions
-- Feature:  UI/UX audit fixes F06, F07 -- tasks/ui-ux-audit-fixes.md
--
-- ACL::initializeDefaultPermissions() uses INSERT IGNORE, so it never writes
-- these names back. Re-running is a no-op.
-- =============================================================================

UPDATE `permissions` SET `display_name` = 'View Request Types',
    `description` = 'View request type definitions and their steps'
    WHERE `name` = 'pipeline.template_view';

UPDATE `permissions` SET `display_name` = 'Manage Request Types',
    `description` = 'Create, edit and delete request types and their steps'
    WHERE `name` = 'pipeline.template_manage';

UPDATE `permissions` SET `display_name` = 'Create Requests',
    `description` = 'Start a new request from a request type'
    WHERE `name` = 'pipeline.create';

UPDATE `permissions` SET `display_name` = 'View Own Requests',
    `description` = 'View requests you created or are assigned (incl. via a team)'
    WHERE `name` = 'pipeline.view_own';

UPDATE `permissions` SET `display_name` = 'View All Requests',
    `description` = 'View every request in the system'
    WHERE `name` = 'pipeline.view_all';

UPDATE `permissions` SET `display_name` = 'Claim Request Steps',
    `description` = 'Accept (claim) a step assigned to your team'
    WHERE `name` = 'pipeline.claim';

UPDATE `permissions` SET `display_name` = 'Act on Request Steps',
    `description` = 'Complete / advance the step you own'
    WHERE `name` = 'pipeline.act';

UPDATE `permissions` SET `display_name` = 'Reassign Request Steps',
    `description` = 'Change the owner (person or team) of a step'
    WHERE `name` = 'pipeline.reassign';

UPDATE `permissions` SET `display_name` = 'Cancel Requests',
    `description` = 'Cancel a request at any step'
    WHERE `name` = 'pipeline.cancel';

UPDATE `permissions` SET `display_name` = 'Manage All Requests',
    `description` = 'Bypass all request restrictions (superuser)'
    WHERE `name` = 'pipeline.manage';

-- system.view_logs (id 42) keeps its name. system.logs (id 134) had the same
-- name and no description. Neither key was checked by the API on 2026-10-10;
-- this only stops them reading as one permission.
UPDATE `permissions` SET `display_name` = 'View System Logs (Duplicate)',
    `description` = 'Duplicate of system.view_logs. Neither key was checked by the API as of 2026-10-10.'
    WHERE `name` = 'system.logs';
