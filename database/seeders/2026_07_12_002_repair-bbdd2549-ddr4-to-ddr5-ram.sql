-- Date: 2026-07-12
-- Purpose: CONFIG-REPAIR seeder (owner decision, per migration handoff
--   SESSION-20260712-FINDINGS-ABC.md, fourth-session §"Owner decision on the
--   bbdd2549 memory.type residue"). Config bbdd2549-5938-4e4c-9882-f1fe171477a8
--   ("Testing Template", is_virtual=1, status_v2=draft) carries 2x DDR4 UDIMM
--   non-ECC RAM (spec 897472c6-7b40-411b-80ef-31a6ca3156ea, G.Skill Ripjaws V)
--   on a DDR5-ECC-only Supermicro X13DRG-H board (spec
--   8c5f2b87-1e5b-4e8c-a1d2-0b1a5e3f4d6c: memory.type=DDR5,
--   module_types=[RDIMM,LRDIMM], ecc_support=true). This trips memory.type,
--   memory.ecc and memory.downclock in the validation engine and is the last
--   fleet_parity_sweep RED (unexplained=4, all on this config). Replaces the
--   RAM with a spec-compatible DDR5 ECC RDIMM part
--   (a1b2c3d4-e5f6-7890-1234-567890abcdef, Samsung "Server Premier" 64GB
--   RDIMM, ecc_support=true — confirmed present in ims-data/ram/ram_detail.json).
-- Affected tables: server_configurations (JSON column ram_configuration —
--   this config is json-fallback-only, zero config_components rows exist for
--   it, confirmed against the scratch mirror); raminventory (defensive —
--   handles the case where production, unlike the scratch fixture, DOES carry
--   rows-path inventory linkage for this config's old RAM units).
-- Related feature: IMS command-layer migration, P5 validation-engine parity
--   gate (fleet_parity_sweep.php). This config is virtual test-fleet data
--   (is_virtual=1), so the repair has no physical-inventory consequence.
--
-- NOT auto-deployed. NOT run by this session. Review, then run manually
-- against the production DB. Idempotent: safe to re-run (each statement is
-- guarded by a WHERE clause tied to the pre-repair state; a second run is a
-- no-op).

START TRANSACTION;

-- Capture pre-repair state once, so steps 2-3 stay idempotent even though
-- step 1 mutates the JSON column they'd otherwise need to re-check.
SET @old_rows_assigned := (
  SELECT COUNT(*) FROM raminventory
  WHERE ServerUUID = 'bbdd2549-5938-4e4c-9882-f1fe171477a8'
    AND UUID = '897472c6-7b40-411b-80ef-31a6ca3156ea'
);
SET @already_repaired := (
  SELECT COUNT(*) FROM raminventory
  WHERE ServerUUID = 'bbdd2549-5938-4e4c-9882-f1fe171477a8'
    AND UUID = 'a1b2c3d4-e5f6-7890-1234-567890abcdef'
);

-- 1) Swap the RAM spec referenced in the config's JSON blob (the only place
--    this config's RAM is recorded — no config_components rows exist for it).
--    Preserves the original 2-entry/qty-1-each shape and each entry's
--    original added_at timestamp; only the uuid changes.
UPDATE server_configurations
SET ram_configuration = JSON_ARRAY(
      JSON_OBJECT('uuid', 'a1b2c3d4-e5f6-7890-1234-567890abcdef', 'quantity', 1, 'added_at', '2026-03-21 10:51:14'),
      JSON_OBJECT('uuid', 'a1b2c3d4-e5f6-7890-1234-567890abcdef', 'quantity', 1, 'added_at', '2026-03-21 10:51:16')
    )
WHERE config_uuid = 'bbdd2549-5938-4e4c-9882-f1fe171477a8'
  AND JSON_CONTAINS(ram_configuration, '"897472c6-7b40-411b-80ef-31a6ca3156ea"', '$[0].uuid');

-- 2) Defensive rows-path handling: if production carries raminventory rows
--    actually assigned to this config for the old DDR4 spec (the scratch
--    mirror used to write/verify this seeder does not — bbdd2549 there is
--    json-fallback-only), release them back to available stock.
UPDATE raminventory
SET Status = 1, ServerUUID = NULL
WHERE ServerUUID = 'bbdd2549-5938-4e4c-9882-f1fe171477a8'
  AND UUID = '897472c6-7b40-411b-80ef-31a6ca3156ea';

-- 3) Defensive rows-path handling: assign up to 2 currently-available DDR5
--    ECC units of the replacement spec to this config, mirroring step 2's
--    release. Runs only if step 2 actually released rows-path units and
--    step 3 hasn't already run (idempotent re-run guard); no-op in the
--    confirmed scratch scenario (json-fallback-only, @old_rows_assigned=0).
UPDATE raminventory r
JOIN (
  SELECT ID
  FROM raminventory
  WHERE UUID = 'a1b2c3d4-e5f6-7890-1234-567890abcdef'
    AND Status = 1
  ORDER BY ID
  LIMIT 2
) pick ON pick.ID = r.ID
SET r.Status = 2, r.ServerUUID = 'bbdd2549-5938-4e4c-9882-f1fe171477a8'
WHERE @old_rows_assigned > 0
  AND @already_repaired = 0;

COMMIT;

-- Verify after running:
--   SELECT ram_configuration FROM server_configurations
--     WHERE config_uuid = 'bbdd2549-5938-4e4c-9882-f1fe171477a8';
--   -- should show 2x a1b2c3d4-e5f6-7890-1234-567890abcdef, 0x 897472c6-...
