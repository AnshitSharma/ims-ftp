# ims-ftp — BDC IMS backend

PHP REST API for hardware inventory with JSON-driven compatibility validation. PHP 7.4+ ·
MariaDB via PDO · JWT HS256 · custom ACL · no framework, no Composer — plain `require`.
The root CLAUDE.md holds the API contract, the data contract and the seeder rules.

Config lives in `.env` on the server (DB creds, `JWT_SECRET`, `IMS_DATA_PATH`, rate limits,
engine flags). Never print its values.

## Request flow

`api/api.php` (single entrypoint) -> JWT auth -> ACL gate -> `api/handlers/{module}/` ->
`send_json_response()`. Modules: `auth`, `server`, `compatibility`, `rack`, `pipelines`,
`location`, the 12 component types, `dashboard`, `search`, `users`, `vendors`, `acl`.

- `api/permission_map.php` is strict for `server`, `compatibility`, `rack` and every component
  action (components share one `component` template with `{module}` substitution). An unmapped
  operation is rejected as "Unknown operation".
- Pipeline handlers are one file per operation and check permissions inside the file (via the
  `$acl` / `$user_id` globals) rather than through the map.
- `rack` and `pipeline` carry a role gate to admin / super_admin in code on top of ACL. Keep
  both gates.
- ACL reads the `permissions` table only. `acl_permissions` was dropped in seeder
  `2026_06_11_002` — never reference it.

## Compatibility validation — two generations, both live

The newer one is `core/models/validation/`: `ValidationEngine` dispatching ~20 rules from
`rules/`, with `TargetStateBuilder`, `ValidateConfigService` and `SlotPlanner`. (`ShadowRunner`
is gone — P9 deleted it with the shadow-mode machinery.) Editing a rule is a live behavioural
change on deploy, unconditionally; see the flag note below.

The older one is `core/models/compatibility/`: `ComponentCompatibility` orchestrating pair
checks, plus `UnifiedSlotTracker`, `PcieLaneBudgetValidator`, `NICPortTracker`,
`SFPCompatibilityResolver`, `OnboardNICHandler`, `StorageConnectionValidator`,
`CpuIdentityMatcher` and the shared `ServerState`. That is the whole directory.

This paragraph used to name three "authority classes" — `SlotAuthority`,
`StorageConnectionAuthority`, `MemoryAuthority`. **None of them exists anywhere under `core/`**
(checked 2026-08-30, `grep -rn 'class SlotAuthority'` and friends return nothing). The two
root-level test files that `require`d them are deleted (BACKLOG §B-17, closed).

**The migration flags are gone.** `ENGINE_MODE`, `COMMAND_LAYER_ENABLED`,
`STATE_MACHINE_ENABLED`, `DUAL_WRITE_ENABLED`, `READ_FROM_ROWS` and the older
`*_AUTHORITY_ENABLED` set were deleted with the legacy chain on 2026-08-30, so there is no
`off` / `shadow` branch left anywhere and no flag to read before predicting blast radius. A
rule change is unconditionally live on deploy.

`ServerBuilder::addComponent()` is deleted too. The mutation path is the command layer —
`AddComponentCommand` / `RemoveComponentCommand` / `ReplaceComponentCommand` — so a
compatibility change lands in the rule alone; there is no second legacy path to mirror it into.
The `ValidationPipeline.php` file header carries the migration plan.

## Server build

create-start -> add-component (validated per add) -> get-compatible -> validate-config ->
finalize-config (locks the config, marks components in_use). `ServerBuilder.php` holds state,
`ServerConfiguration.php` persistence. `ConfigurationReverseLookup.php` was deleted 2026-08-30
having never had a single caller; the reverse lookup it was meant to provide now lives, in the
one place that needed it, inside `deleteComponent()` — see below.

**Deleting an inventory unit is guarded, and the guard is load-bearing.**
`BaseFunctions::deleteComponent()` (the single choke point for both `{type}-delete` and
`{type}-bulk-delete`) refuses with a 409 naming the configuration when a live
`config_components` row claims the unit, and fail-closed if that query cannot be answered.
Before 2026-08-30 it was a bare `DELETE` — which is how configuration `1f61541b` came to
display an SFP whose inventory row no longer exists (BACKLOG §B-16). The claim is matched on
`(inventory_table, inventory_id)`, never on `component_type`. Deliberately not keyed on
`Status`/`ServerUUID`: those drift (§B-9), a live row does not. Pinned by
`tests/regression/component_delete_guard_test.php` (no DB needed).

Deleting a whole configuration was already safe — `ServerBuilder::deleteConfiguration()`
refuses while components are installed, releases bound units, then purges the rows.

## What a configuration contains lives in `config_components`, and nowhere else

One row per physical unit. U-D.3 (2026-08-30) removed the nine legacy JSON columns
(`cpu_configuration` `ram_configuration` `storage_configuration` `caddy_configuration`
`nic_config` `sfp_configuration` `pciecard_configurations` `hbacard_config` `hbacard_uuid`)
from every reader and writer, then **dropped them from the database** — seeders
`2026_08_30_001/002/003` ran against production on 2026-08-30. They are gone, not deprecated;
their last values are frozen in `server_configurations_json_archive`. `motherboard_uuid` and
`chassis_uuid` survive as plain scalars and are still written by
`ServerBuilder::updateServerConfigurationTable()`.

- Read through **`ConfigReadRouter::components($builder, $pdo, $configRow)`** — the single
  authority. It maps rows into the legacy output shape and additionally carries
  `slot_position` (from `slot_ref`) and, for NICs, `source_type`. Never decode a column.
- A row names its own `inventory_table`, and it is not always the one the component type
  suggests: a serverplatform-provisioned build records BOTH its motherboard and its chassis
  against `serverplatforminventory`, because one platform unit supplies both. Read the table
  off the row; never infer it from `component_type`.
- Write through `ConfigComponentRepository` / `ConfigComponentWriter`, inside the caller's
  transaction (fail-closed, INV-5).
- **Virtual builds (`is_virtual = 1`) are excluded by design.** They reserve no stock, so there
  is no inventory unit for a row's NOT NULL `inventory_id` to point at, and
  `ConfigComponentWriter::afterLegacyAdd()` refuses them. A pre-2026-08-21 virtual config
  therefore reads as having no components at all. That is not a bug to fix by backfilling —
  it cannot be backfilled. Their old JSON is preserved in `server_configurations_json_archive`.
- `uq_slot_occupancy (config_uuid, slot_ref, removed_at)` does **not** prevent two live rows
  sharing a slot: every live row has `removed_at` NULL and MariaDB treats NULLs as distinct in a
  unique key, so the index only ever constrains tombstones sharing a timestamp. Probed
  2026-08-30. Code that needs slot exclusivity has to check for itself.

## Specs are not in the DB

Hardware specs are JSON in `ims-data/`; the DB holds inventory rows. Load them through
`ComponentDataService` (request-level cache) — never hardcode a specification.
`ComponentSpecPaths.php` resolves paths via `IMS_DATA_PATH`, else by walking relative paths to
`../ims-data/`, so watch `../` depth if files move.

## Gotchas

- Types are lowercase (`cpu`); tables carry the suffix (`cpuinventory`).
- `risercard` split out of `pciecard` on 2026-08-14 — risers occupy riser bays and *provide*
  pcie_slots; plain PCIe cards consume them.
- `serverplatform` became the 12th type on 2026-08-25. The version UUID, not the platform UUID,
  is the stocked SKU — see `ims-data/CLAUDE.md`.
- Tickets are retired as an engine: `core/models/tickets/*` (`TicketValidator`, `TicketItemService`,
  `TicketHistoryService`) now serve `PipelineManager`. There is no `TicketManager.php`.
- Errors: `error_log()` the exception, return a proper HTTP code, leak no paths or secrets.

## Tests (local CLI, never deployed)

`php` isn't on PATH; XAMPP's is at `/c/xampp/php/php.exe`. Lint changed files with `php -l`
before they auto-upload — a syntax error here is a live 500.

- `php tests/run_tests.php` — full suite (55 discovered, including `tests/` root files as of
  2026-08-30 — BACKLOG §B-17), needs real MariaDB on a pristine datadir. 52 passed / 0 failed /
  3 ran nothing as of 2026-08-30, against a database with the nine columns already dropped —
  which is the shape production is in now.
- `tests/regression/component_delete_guard_test.php` — pins the `deleteComponent()` in-use guard
  (BACKLOG §B-16). Needs **no** database; it drives recording PDO fakes.
- `tests/fixture_scenarios_real.php` — **DISABLED**, exits 2 (`NOT_A_SUITE`, not swept). Both its
  subjects are gone: P9 deleted the three validate* methods it drives, and U-D.3c dropped the
  columns its fixtures insert. Its R1–R10 scenario table is kept because the rule unit tests
  cite its UUIDs.
- `tests/lane_authority_unit.php`, `tests/nic_sfp_authority_unit.php`,
  `tests/storage_bay_authority_unit.php`, `tests/state_machine_unit.php`,
  `tests/getDashboardDataShapeTest.php` — the `tests/` root suites `run_tests.php` now discovers
  (label `root`). `state_machine_unit.php` refuses to run unless its DB name contains `scratch`
  and none of `golden`/`compat`/`prod` — it unconditionally DROPs whatever database it is given.
- `tests/characterize_compatibility.php` — golden master over real `server_configurations` rows
  into `tests/golden/`. **Permanently unusable as a parity gate, not just currently**: P9/U-D.3a
  deleted all four methods it characterises (`validateConfiguration`,
  `validateConfigurationEnhanced`, `extractComponentsFromJson`, `validateComponentAddition`), and
  this is independent of data source — confirmed 2026-08-30 that even a from-scratch local clone
  with every seeder replayed still records "Call to undefined method" for every configuration
  (BACKLOG B-4, closed SUPERSEDED). The checked-in `compatibility_baseline.json` is a pre-P9
  artefact (12 configs, not 18) and **must not be regenerated** — a `DO NOT RUN` banner sits at
  the top of the file. Rewriting it against `ValidationEngine` is logged as new work (BACKLOG
  B-18), out of migration scope.
- `scripts/audit-orphans.php` — orphaned-record audit, and the only thing that checks a
  configuration's claims against real inventory rows. Reads `config_components` since
  U-D.3c, so it resolves each claim to ONE unit by `inventory_id` instead of sampling any
  unit of the model — which is how it found config `1f61541b` claiming `sfpinventory` ID 99,
  a row that no longer exists. `tests/backfill/` is empty on purpose; see its README.

## Deeper reference (local-only, never deployed)

`docs/ARCHITECTURE.md` and `docs/OPERATIONS.md` (written 2026-08-24, every claim `file:line`
cited), `BACKLOG.md`, `migration/` (the target design — several packs describe intent that was
never built), and `database/seeders/*.sql` as schema history. Where a doc contradicts the code,
the code wins; flag the stale doc.
