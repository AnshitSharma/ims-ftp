-- ============================================================
-- Seeder : 2026_08_29_001_repair-cpu-configuration-divergence
-- Date   : 2026-08-29
-- Purpose: Restore the one `cpu_configuration` value the legacy JSON column lost
--          to the command layer's missing unit identity.
--
--          MECHANISM. `ServerBuilder::addComponent()` stamps the physical unit's
--          row id into every JSON entry (`$options['inventory_id']`, added under
--          A-L5), because `componentEntryMatches()` otherwise falls back to the
--          model UUID and cannot tell two units of one model apart. The three
--          command-layer callers that replaced that path at
--          COMMAND_LAYER_ENABLED=enforce -- AddComponentCommand,
--          RemoveComponentCommand, ReplaceComponentCommand -- did not pass it.
--          So on config 1f61541b two CPU units of model 561bff6c merged into a
--          single entry with quantity 2, and removing one unit removed the whole
--          entry, nulling the column while `config_components` and `cpuinventory`
--          both still (correctly) held the surviving unit.
--
--          The writer is fixed in code (2026-08-29). That stops new divergence
--          but cannot reconstruct what was already discarded; this seeder does
--          that, for the single affected row.
--
--          WHY IT MATTERS AT ALL, given nothing reads this column at
--          READ_FROM_ROWS=on: rollback fidelity is the column's only remaining
--          purpose until U-D.3 drops it. A rollback to =off with this row NULL
--          would show a CPU-less server that physically has a CPU.
--
--          SCOPE. Measured against the production dump: exactly one config
--          diverges (rows=1, json=0); every other config agrees. The UPDATE is
--          therefore pinned to that config_uuid and additionally guarded on the
--          column still being NULL and the rows side still holding exactly the
--          one expected unit -- so it is a no-op on re-run, a no-op if the row
--          was already repaired by hand, and a no-op if the configuration has
--          since changed. It never overwrites a non-NULL value.
--
--          The entry it writes is byte-shaped like what the FIXED writer now
--          produces for a serial-less unit: uuid, quantity 1, added_at, and
--          inventory_id. `added_at` is taken from the config_components row so
--          the repair does not invent a timestamp.
--
-- Tables : server_configurations (one row, column cpu_configuration)
-- Feature: migration U-X.2 / read-path parity (hold-condition (b))
-- ============================================================

UPDATE server_configurations sc
JOIN config_components cc
  ON  cc.config_uuid     = sc.config_uuid
  AND cc.component_type  = 'cpu'
  AND cc.removed_at IS NULL
SET sc.cpu_configuration = JSON_ARRAY_APPEND(
        JSON_OBJECT('cpus', JSON_ARRAY()),
        '$.cpus',
        JSON_OBJECT(
            'uuid',         cc.spec_uuid,
            'quantity',     1,
            'added_at',     DATE_FORMAT(cc.added_at, '%Y-%m-%d %H:%i:%s'),
            'inventory_id', cc.inventory_id
        )
    ),
    sc.updated_at = sc.updated_at
WHERE sc.config_uuid = '1f61541b-db3e-4541-83eb-da0c78ffa1d8'
  AND sc.cpu_configuration IS NULL
  AND cc.inventory_id = 127
  AND cc.spec_uuid    = '561bff6c-3431-4295-8678-1653ad00cd53'
  AND (SELECT COUNT(*) FROM config_components c2
       WHERE c2.config_uuid    = sc.config_uuid
         AND c2.component_type = 'cpu'
         AND c2.removed_at IS NULL) = 1;

-- Verification (expect exactly one row, cpu_configuration non-NULL naming unit 127):
--   SELECT config_uuid, cpu_configuration FROM server_configurations
--   WHERE config_uuid = '1f61541b-db3e-4541-83eb-da0c78ffa1d8';
