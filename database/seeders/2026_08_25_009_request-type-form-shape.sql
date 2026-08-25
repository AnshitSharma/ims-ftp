-- =============================================================================
-- Date:     2026-08-25
-- Purpose:  Let a Request Type say which questions its create form should ask,
--           so a request to be let through a DOOR stops asking which server it
--           is about and which components it concerns.
--
--           Reported: raising "Inventory Room Access" still shows "Server this
--           request is about" and a Components list. The inventory room is a
--           room of shelves -- there is no server, and there is no parts list.
--
-- Tables:   pipeline_templates (2 columns ADDED, 3 rows UPDATEd)
-- Feature:  Request Types / create-form shape
-- Requires: nothing. Independent of 2026_08_25_007/008, though the two types it
--           configures were seeded by 008.
--
-- ============================ READ THIS FIRST ================================
--
-- NO information_schema ANYWHERE IN THIS FILE, DELIBERATELY.
--   The production DB user has no grant on it -- see the long note in
--   2026_08_25_007 for why the guarded-DDL pattern used by older seeders both
--   fails outright AND fails open. This server is MariaDB 10.11: native
--   ALTER TABLE ... ADD COLUMN IF NOT EXISTS is idempotent and needs no grant
--   beyond ALTER on this table.
--
-- WHY TWO FLAGS AND NOT ONE SETTING
--   "Does this type name a server" and "does this type name components" are
--   different questions, and one type answers them differently: Server Room
--   Access still wants the server (which machine must you reach?) but not the
--   parts list. A single enum could not express that row.
--
-- WHY BOTH DEFAULT TO 1
--   1/1 is exactly what the form does today for a type with no action. Every
--   existing type therefore keeps its current behaviour untouched, and the
--   window between the PHP deploying (~20s after save) and this seeder being
--   run by hand is a no-op rather than a change nobody asked for. Only the
--   three rows updated below behave differently, and only after this runs.
--
-- WHAT THESE FLAGS DO NOT DO
--   They shape the form ONLY for a type whose approval step performs no action.
--   A type that DOES perform one keeps deciding from the action itself -- an
--   action knows whether it names a server (server.component.add does,
--   inventory.component.add does not), and it carries its own component fields.
--   That is the stronger signal and it is already correct; these flags never
--   override it. See applyRequestType() in requests.js.
--
--   They are presentation, not authorization. Nothing is enforced server-side
--   by them: target_server_uuid remains optional on every request, exactly as
--   before. Hiding a question the requester has no use for is the whole scope.
--
-- WHY UPDATE BY NAME IS SAFE HERE, WHEN THE CODE MUST NEVER MATCH ON NAME
--   A seeder is a one-off statement against known rows; the running code is
--   not. pipeline_templates.name is UNIQUE, so each UPDATE below touches at
--   most one row, and an admin who later renames a type keeps the flags they
--   were given -- which is the point of storing them as data. Nothing in the
--   PHP or the JS looks a type up by name.
--
-- Idempotent: native IF NOT EXISTS on the DDL; the UPDATEs are absolute (they
--             SET a value rather than toggling one), so re-running restores the
--             intended state rather than compounding.
-- Rollback:   rollback/2026_08_25_009_request-type-form-shape_rollback.sql
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state. Keep this output.
--    Expect: 0 rows from each SHOW if this has not been applied yet.
-- ---------------------------------------------------------------------------
SHOW COLUMNS FROM `pipeline_templates` LIKE 'asks_for_server';
SHOW COLUMNS FROM `pipeline_templates` LIKE 'asks_for_components';

SELECT `id`, `name`, `is_active`, `is_system`
  FROM `pipeline_templates`
 WHERE `name` IN ('Inventory Room Access', 'Server Room Access', 'Inventory Access')
 ORDER BY `name`;

-- ---------------------------------------------------------------------------
-- 1. The two flags. Both default to 1 = ask, which is today's behaviour.
-- ---------------------------------------------------------------------------
ALTER TABLE `pipeline_templates`
  ADD COLUMN IF NOT EXISTS `asks_for_server` TINYINT(1) NOT NULL DEFAULT 1
      COMMENT '1 = the create form asks which server this request is about (optional context). 0 = do not ask. Applies only to types whose approval step performs no action.'
      AFTER `is_system`;

ALTER TABLE `pipeline_templates`
  ADD COLUMN IF NOT EXISTS `asks_for_components` TINYINT(1) NOT NULL DEFAULT 1
      COMMENT '1 = the create form offers a Components list. 0 = do not offer it. Applies only to types whose approval step performs no action.'
      AFTER `asks_for_server`;

-- ---------------------------------------------------------------------------
-- 2. The three access types that should stop asking.
--
--    Inventory Room Access / Inventory Access -- neither is about a machine.
--    One asks for a door, the other for an ACL grant. Neither is about a parts
--    list either: what is needed goes in the description.
-- ---------------------------------------------------------------------------
UPDATE `pipeline_templates`
   SET `asks_for_server`     = 0,
       `asks_for_components` = 0,
       `updated_at`          = NOW()
 WHERE `name` IN ('Inventory Room Access', 'Inventory Access');

-- ---------------------------------------------------------------------------
--    Server Room Access KEEPS the server question, deliberately.
--
--    It stays OPTIONAL rather than becoming required: a tech may need the aisle
--    generally rather than one machine, and the type's own description already
--    asks them to name the server or rack. Naming it gives the approver the one
--    thing they need to badge someone into the right place.
--
--    The Components list still goes -- the request is for the room, and the
--    hardware change itself is a separate request that carries its own fields.
-- ---------------------------------------------------------------------------
UPDATE `pipeline_templates`
   SET `asks_for_server`     = 1,
       `asks_for_components` = 0,
       `updated_at`          = NOW()
 WHERE `name` = 'Server Room Access';

-- ---------------------------------------------------------------------------
-- 3. After-state. THIS IS THE CHECK THAT MATTERS -- do not skip it.
--
--    Expected:
--      * one row from each SHOW COLUMNS: Type=tinyint(1), Null=NO, Default=1
--      * Inventory Room Access  -> 0 / 0
--        Inventory Access       -> 0 / 0
--        Server Room Access     -> 1 / 0
--      * every OTHER type -> 1 / 1, i.e. asking=<total types> on the last query.
--        If any type you did not expect shows a 0, STOP -- something else wrote
--        to this table.
-- ---------------------------------------------------------------------------
SHOW COLUMNS FROM `pipeline_templates` LIKE 'asks_for_server';

SHOW COLUMNS FROM `pipeline_templates` LIKE 'asks_for_components';

SELECT `id`, `name`, `is_active`, `asks_for_server`, `asks_for_components`
  FROM `pipeline_templates`
 ORDER BY `asks_for_server` ASC, `asks_for_components` ASC, `name` ASC;

SELECT COUNT(*)                                                      AS total_types,
       SUM(CASE WHEN `asks_for_server`     = 1 THEN 1 ELSE 0 END)    AS asking_server,
       SUM(CASE WHEN `asks_for_components` = 1 THEN 1 ELSE 0 END)    AS asking_components
  FROM `pipeline_templates`;
