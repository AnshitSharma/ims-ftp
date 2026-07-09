-- ============================================================
-- Seeder : 2026_07_10_002_create-status-transitions
-- Unit   : U-SM.2 (migration/03-state-machines)
-- Purpose: Transition tables define the legal state graph as DATA, not code.
--          config_status_transitions additionally carries the ACL permission
--          required to make the jump and whether it must pass full validation
--          first. StateGuard (U-SM.4) consults these tables; nothing enforces
--          them yet (STATE_MACHINE_ENABLED stays off through this unit).
-- Tables : config_status_transitions, inventory_status_transitions (new)
-- Notes  : Idempotent (CREATE TABLE IF NOT EXISTS + INSERT IGNORE). Safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS `config_status_transitions` (
  `from_status` ENUM('draft','building','validating','validated','finalized','deployed','maintenance','retired') NOT NULL,
  `to_status`   ENUM('draft','building','validating','validated','finalized','deployed','maintenance','retired') NOT NULL,
  `required_permission` VARCHAR(64) NOT NULL,
  `requires_validation` ENUM('none','full') NOT NULL,
  PRIMARY KEY (`from_status`, `to_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `inventory_status_transitions` (
  `from_status` ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NOT NULL,
  `to_status`   ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NOT NULL,
  PRIMARY KEY (`from_status`, `to_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `config_status_transitions` (`from_status`, `to_status`, `required_permission`, `requires_validation`) VALUES
  ('draft',     'building',   'server.edit',       'none'),
  ('building',  'validating', 'server.edit',       'none'),
  ('validating','validated',  'SYSTEM',            'full'),
  ('validating','building',  'SYSTEM',            'none'),
  ('validated', 'building',   'server.edit',       'none'),
  ('validated', 'finalized',  'server.finalize',   'full'),
  ('finalized', 'deployed',   'server.deploy',     'none'),
  ('finalized', 'building',   'server.unfinalize', 'none'),
  ('deployed',  'maintenance','server.maintain',   'none'),
  ('maintenance','deployed',  'server.maintain',   'full'),
  ('deployed',  'retired',    'server.retire',     'none'),
  ('maintenance','retired',   'server.retire',     'none');

-- Sequential lifecycle (available -> reserved -> allocated -> installed -> active)
INSERT IGNORE INTO `inventory_status_transitions` (`from_status`, `to_status`) VALUES
  ('available', 'reserved'),
  ('reserved',  'allocated'),
  ('allocated', 'installed'),
  ('installed', 'active'),
-- Every state can enter maintenance except retired (terminal)
  ('available',  'maintenance'),
  ('reserved',   'maintenance'),
  ('allocated',  'maintenance'),
  ('installed',  'maintenance'),
  ('active',     'maintenance'),
  ('failed',     'maintenance'),
-- Release / removal paths back to available
  ('reserved',  'available'),
  ('allocated', 'available'),
  ('installed', 'available'),
  ('active',    'available'),
-- Maintenance resolves to available (repaired) or failed (diagnosed dead)
  ('maintenance', 'available'),
  ('maintenance', 'failed'),
-- failed -> retired only (NOT failed -> available: illegal resurrection)
  ('failed', 'retired');

-- ============================================================
-- Verification (optional, run after the seeder):
--
--   SELECT COUNT(*) FROM config_status_transitions;    -- expect 12
--   SELECT COUNT(*) FROM inventory_status_transitions;  -- expect 17
--   SELECT * FROM inventory_status_transitions WHERE from_status = 'failed' AND to_status = 'available'; -- expect 0 rows
-- ============================================================
