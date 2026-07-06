# FLAGS.md — the only flags this migration may create (INV-12)

Read pattern (copy exactly, one static method per flag, in the class that consumes it):
see PcieLaneBudgetValidator::currentMode() core/models/compatibility/PcieLaneBudgetValidator.php:50-65
(getenv → $_ENV fallback → default → whitelist).

| Flag | Values | Default | Introduced | Consumed by | Deleted in |
|---|---|---|---|---|---|
| DUAL_WRITE_ENABLED | off, on | off | U-1.5 | ConfigComponentWriter | U-D.4 |
| STATE_MACHINE_ENABLED | off, shadow, enforce | off | U-SM.4 | StateGuard | U-D.4 |
| ENGINE_MODE | off, shadow, enforce | off | U-V.3 | ValidationEngine | U-D.4 |
| COMMAND_LAYER_ENABLED | off, shadow, enforce | off | U-C.2 | server_api dispatch | U-D.4 |
| READ_FROM_ROWS | off, sample, on | off | U-X.1 | ConfigReadRouter | U-D.4 |

Semantics:
- off: new code path not executed (legacy behavior byte-identical).
- shadow: new path executes, result LOGGED/COMPARED, legacy result returned. Shadow must never
  write user-visible state except its own log/report tables/files.
- on/enforce: new path is authoritative.
Progression per flag: off → shadow (soak ≥ the period in the phase README) → enforce. Never skip shadow.
Legacy flags PCIE_LANE_CHECK_ENABLED, VALIDATION_PIPELINE_ENABLED, SLOT_AUTHORITY_ENABLED,
STORAGE_CONNECTION_AUTHORITY_ENABLED remain untouched until U-D.4 deletes them.
