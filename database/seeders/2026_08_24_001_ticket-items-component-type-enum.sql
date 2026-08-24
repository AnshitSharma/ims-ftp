-- =============================================================================
-- 2026_08_24_001_ticket-items-component-type-enum.sql
-- =============================================================================
-- Date            : 2026-08-24
-- Purpose         : Widen `ticket_items`.`component_type` to the canonical 11
--                   component types and repair the rows that silently truncated
--                   to '' before the widening.
-- Affected tables : ticket_items  (column widen + data repair)
-- Related feature  : Requests (pipeline) item capture — TicketItemService /
--                   TicketValidator. Also unblocks the riser/pciecard split of
--                   2026-08-14 and the SFP component type.
--
-- WHY -------------------------------------------------------------------------
-- `TicketValidator::…` classifies expansion cards as 'risercard' vs 'pciecard'
-- (TicketValidator.php:486-488) and SFP items as 'sfp'. TicketItemService writes
-- that value straight into `component_type`, which was declared as a 9-value
-- ENUM missing BOTH 'risercard' and 'sfp':
--
--     enum('cpu','ram','storage','motherboard','nic','caddy','chassis',
--          'pciecard','hbacard')
--
-- MariaDB is not in STRICT mode here, so an out-of-range ENUM value is not an
-- error -- it is silently coerced to the empty string. Every SFP or riser-card
-- item ever attached to a Request was therefore stored with NO component type,
-- with only a warning (Code 1265) that nothing was reading. Found 2026-08-24 by
-- restore-testing the production dump: two live rows are already affected.
--
-- `config_components`.`component_type` already carries the full canonical 11, so
-- this brings `ticket_items` back into line with the data contract rather than
-- inventing a new one.
--
-- IDEMPOTENT: the ALTER is a full column redefinition (re-running it is a no-op)
-- and each UPDATE is constrained to component_uuid AND component_type='' , so a
-- second run matches zero rows. Both statements are safe to re-run.
-- =============================================================================

-- 1. Widen the ENUM to the canonical 11 component types ----------------------
--    Order matches ims-data / config_components for readability.
ALTER TABLE `ticket_items`
    MODIFY COLUMN `component_type` ENUM(
        'chassis',
        'motherboard',
        'cpu',
        'ram',
        'storage',
        'nic',
        'hbacard',
        'pciecard',
        'risercard',
        'caddy',
        'sfp'
    ) NOT NULL;

-- 2. Repair the rows that were coerced to '' ---------------------------------
--    Typed by component_uuid, which is intact -- the UUID identifies the spec
--    file the component came from, so the correct type is recoverable.
--    118ea705-3cc4-49d3-9151-352af2675699 = Generic SFP-1G-T          -> sfp
--    446ca54a-a7d3-475b-bddb-5538ee2ce9cf = ASUS HYPER DUAL Riser     -> risercard

UPDATE `ticket_items`
   SET `component_type` = 'sfp'
 WHERE `component_type` = ''
   AND `component_uuid` = '118ea705-3cc4-49d3-9151-352af2675699';

UPDATE `ticket_items`
   SET `component_type` = 'risercard'
 WHERE `component_type` = ''
   AND `component_uuid` = '446ca54a-a7d3-475b-bddb-5538ee2ce9cf';

-- 3. Verification ------------------------------------------------------------
--    Expect: zero rows. Any row listed here is an untyped item whose UUID was
--    not covered by the repairs above -- report it, do not guess its type.
SELECT `id`, `ticket_id`, `component_uuid`, `component_name`
  FROM `ticket_items`
 WHERE `component_type` = '';
