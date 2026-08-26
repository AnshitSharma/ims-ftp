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
`rules/`, with `TargetStateBuilder`, `ShadowRunner` and `SlotPlanner`. **`ENGINE_MODE` is
`enforce` in production** even though the code default reads `off`, so editing a rule is a live
behavioural change on deploy — read the flags before predicting blast radius.

The older one is `core/models/compatibility/`: `ComponentCompatibility` orchestrating pair
checks, with authority classes (`SlotAuthority`, `StorageConnectionAuthority`, `MemoryAuthority`),
plus `UnifiedSlotTracker`, `PcieLaneBudgetValidator`, `NICPortTracker`, `SFPCompatibilityResolver`,
`OnboardNICHandler` and the shared `ServerState`.

`COMMAND_LAYER_ENABLED=shadow` means `ServerBuilder::addComponent` is still the real mutation
path, so a compatibility change usually has to land in both the legacy path and the rule. Flags
(`ENGINE_MODE`, `COMMAND_LAYER_ENABLED`, `STATE_MACHINE_ENABLED`, `DUAL_WRITE_ENABLED`,
`READ_FROM_ROWS`, and the older `*_AUTHORITY_ENABLED` set) use `off` / `shadow` / `enforce`.
The `ValidationPipeline.php` file header carries the migration plan.

## Server build

create-start -> add-component (validated per add) -> get-compatible -> validate-config ->
finalize-config (locks the config, marks components in_use). `ServerBuilder.php` holds state,
`ServerConfiguration.php` persistence, `ConfigurationReverseLookup.php` component->config lookups.

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

- `php tests/run_tests.php` — full suite (~41), needs real MariaDB on a pristine datadir.
- `tests/*_authority_unit.php` — per-authority units; `tests/serverstate_equivalence.php`.
- `tests/characterize_compatibility.php` — golden master over real `server_configurations` rows
  into `tests/golden/`. A compatibility-engine refactor should be shown at parity against that
  baseline, or its diffs explicitly reviewed.
- `scripts/audit-orphans.php` — orphaned-record audit.

## Deeper reference (local-only, never deployed)

`docs/ARCHITECTURE.md` and `docs/OPERATIONS.md` (written 2026-08-24, every claim `file:line`
cited), `BACKLOG.md`, `migration/` (the target design — several packs describe intent that was
never built), and `database/seeders/*.sql` as schema history. Where a doc contradicts the code,
the code wins; flag the stale doc.
