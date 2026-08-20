-- =============================================================================
-- Date:     2026-08-21
-- Purpose:  Let the REQUESTER say which access they need, instead of the Request
--           Type deciding it for them.
--
--           Phase 1 put the granted permission list on the approval STEP
--           (pipeline_stages.effect_config), so one request type meant one fixed
--           bundle -- a separate type for every combination. This column flips
--           that around: effect_config becomes the CEILING of what the type may
--           ever grant, and requested_access is the subset this particular
--           request is asking for. The approval grants the intersection of the
--           two, further intersected with the hard whitelist in code.
--
-- Tables:   tickets (1 new column)
-- Feature:  Temporary approval-gated access (Requests module), phase 2
--
-- Notes:    - JSON array of permission names, e.g. ["server.edit","cpu.create"].
--             TEXT rather than a JSON column, matching how the rest of this
--             schema stores structured blobs (ticket_items.component_specs).
--           - NULL / empty means "no specific subset asked for", in which case
--             the approval grants the step's whole effect_config. That is exactly
--             the Phase 1 behaviour, so the existing "Temporary Server Creation
--             Access" type keeps working untouched and every existing ticket is
--             unaffected.
--           - It is a REQUEST, never an authorisation. Nothing here is trusted:
--             the list is validated on submit, intersected with the step ceiling
--             at approval, and intersected again with
--             TemporaryAccessManager::GRANTABLE_PERMISSIONS before any row is
--             written. Editing it cannot widen a grant.
--           - The TARGET of a scoped request reuses the existing
--             tickets.target_server_uuid column -- no new concept, and the create
--             modal already has that field.
--           - No index: read only via the ticket's primary key during approval.
--           - Idempotent via native ADD COLUMN IF NOT EXISTS (MariaDB 10.0.2+).
--           - Rollback: rollback/2026_08_21_002_request-requested-access_rollback.sql
-- =============================================================================

ALTER TABLE `tickets`
    ADD COLUMN IF NOT EXISTS `requested_access` TEXT NULL DEFAULT NULL
        COMMENT 'JSON array of permission names the requester asked for; NULL = the whole step ceiling'
        AFTER `target_server_uuid`;

-- ---------------------------------------------------------------------------
-- Verification
-- ---------------------------------------------------------------------------
SHOW COLUMNS FROM `tickets` LIKE 'requested\_access';
