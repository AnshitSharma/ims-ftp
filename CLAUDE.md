# CLAUDE.md — BDC Inventory Management System (IMS)

PHP REST API for server hardware inventory with JSON-driven component compatibility validation.
**Stack**: PHP 7.4+ · MySQL/MariaDB (PDO) · JWT auth (HS256) · ACL authorization · No framework, no Composer — plain `require` structure.

## Deployment & testing (production-only)

There is NO local dev server. Saving a file in VS Code auto-uploads it to production via SFTP (`uploadOnSave`, ~8s delay + transfer ≈ 20s total).

- **After any code change: wait ~20 seconds, then test against production directly.**
- Production API: `https://ims.bdcms.bharatdatacenter.com/Ims_backend/api/api.php`

```bash
# Smoke-test auth (all requests go through this one endpoint, action={module}-{operation}):
curl -X POST https://ims.bdcms.bharatdatacenter.com/Ims_backend/api/api.php \
  -d "action=auth-login" -d "username=superadmin" -d "password=password"
```

- **NOT auto-deployed** (per SFTP ignore list): `*.sql`, `*.md`, `*.txt`, `tests/`, `docs/`, `tasks/`, `logs/`. Seeder SQL must therefore be applied to the production DB manually — writing the file alone changes nothing.
- Config is in `.env` on the server (DB creds, `JWT_SECRET`, `JWT_EXPIRY_HOURS=24`, `IMS_DATA_PATH`, `APP_ENV`, rate-limit settings). Never print or expose `.env` values.

## Architecture (mental model — read code for detail)

- **Routing**: single entrypoint `api/api.php`. Actions are `{module}-{operation}` kebab-case (`cpu-list`, `server-add-component`). Flow: HTTP → `api/api.php` → JWT auth → ACL gate → `api/handlers/{module}/` → `send_json_response()`.
- **Modules**: `auth`, `server`, `compatibility`, `rack`, `pipeline`, the 10 component types, `dashboard`, `search`, `users`, `vendor`, `acl`, `roles`, `permissions`.
- **10 component types**: cpu, ram, storage, motherboard, nic, caddy, chassis, pciecard, hbacard, sfp. Inventory tables are `{type}inventory`.
- **Specs live outside the DB**: canonical hardware specs are JSON in `ims-data/{type}/*.json`, resolved by `core/models/components/ComponentSpecPaths.php` (`IMS_DATA_PATH` or `../ims-data/`). DB = inventory rows; JSON = what the hardware *is*.
- **Compatibility engine** (`core/models/compatibility/`): `ComponentCompatibility.php` orchestrates pair checks; add-time validation runs through **authority classes** — `SlotAuthority` (PCIe slots), `StorageConnectionAuthority`, `MemoryAuthority` (finalize-time RAM), plus `UnifiedSlotTracker`, `StorageConnectionValidator`, `PcieLaneBudgetValidator`, `NICPortTracker`, `SFPCompatibilityResolver`, `OnboardNICHandler`, and shared `ServerState`. `ValidationPipeline.php` is the coordination shell being phased in.
- **Feature flags** (env: `SLOT_AUTHORITY_ENABLED`, `STORAGE_BAY_AUTHORITY_ENABLED`, `MEMORY_AUTHORITY_ENABLED`, `VALIDATION_PIPELINE_ENABLED`) use `off` / `shadow` / `enforce` modes. Read the file header of `ValidationPipeline.php` before touching validation flow — the migration plan lives there.
- **Pipelines replaced tickets**: the legacy `ticket` module was retired; tickets are now pipeline instances (`tickets.pipeline_template_id`, per-stage progress in `ticket_stage_progress`). Handlers: `api/handlers/pipelines/pipeline-*.php` (one file per operation); models: `core/models/pipelines/`; config: `core/config/PipelineConfig.php`. Remaining `core/models/tickets/*` files serve the pipeline engine — there is no `TicketManager.php`.
- **Server build workflow**: create-start → add-component (validated per add) → get-compatible → validate-config → finalize-config (locks config, marks components in_use). `ServerBuilder.php` = state, `ServerConfiguration.php` = persistence, `ConfigurationReverseLookup.php` = component→config lookups.

## Hard rules (violating any of these is a bug)

1. **UUID validation is never bypassed.** Every component UUID must exist in `ims-data/{type}/*.json` before inventory insertion (`BaseFunctions::addComponent()` → `ComponentDataService::validateComponentUuid()`).
2. **Every DB change ships as a NEW seeder** in `database/seeders/`, named `YYYY_MM_DD_NNN_short-description.sql`, self-contained. Never edit an existing seeder. Show the SQL to the user after writing it — seeders are not auto-deployed and must be run on the server manually.
3. **Never hardcode hardware specifications.** Load specs via `ComponentDataService` (request-level cache).
4. **`api/permission_map.php` is strict** for `server`, `compatibility`, `rack`, and all component actions (components share the `component` template with `{module}` substitution). Unmapped operations are rejected with "Unknown operation" — new actions MUST be added there.
5. **`rack` and `pipeline` are role-gated in code** to `admin` / `super_admin` on top of ACL — keep both gates intact.
6. **ACL uses the `permissions` table only.** `acl_permissions` was dropped (seeder `2026_06_11_002`) — never reference it.
7. **Do NOT rename `ims-data/chassis/chasis-level-3.json`.** The typo is load-bearing (`ComponentSpecPaths.php` maps to it).
8. **Never expose credentials, secrets, or filesystem paths in API responses.** `error_log()` for exceptions; proper HTTP codes (400/401/403/404/500).

## Gotchas

- Component types are lowercase (`cpu`) but tables carry the suffix (`cpuinventory`).
- Inventory status values: `0` = failed, `1` = available, `2` = in_use. `faildate` column exists on all inventory tables (seeder `2026_06_15_001`).
- `compatibility` operations use **underscores** (`check_pair`), unlike everything else (kebab-case) — they match handler switch cases.
- Pipeline handlers are one-file-per-operation and check permissions inside each file (via `$acl` / `$user_id` globals), not in the map.
- `ComponentSpecPaths.php` auto-discovers `ims-data/` via relative paths — watch `../` depth when moving files.
- JWT required for everything except `auth-*`. Rate limiting is per-IP per-endpoint + per-username failed-login throttle (`RateLimiter.php`).

## Tests (local CLI only — never deployed)

- `tests/characterize_compatibility.php` — golden-master harness: captures current engine behaviour per real `server_configurations` row into `tests/golden/compatibility_baseline.json`. Run against an isolated scratch DB seeded via `tests/golden/setup_scratch_db.sql` — it never touches production data.
- `tests/*_authority_unit.php` — per-authority unit tests (slot/storage, memory, lane, nic_sfp, storage_bay); `tests/serverstate_equivalence.php`.
- **Any refactor of the compatibility engine must be proven at parity against the golden baseline** (or intended diffs explicitly reviewed).
- `scripts/audit-orphans.php` — orphaned-record audit utility.

## Adding a new component type (checklist)

1. Table `{type}inventory` via a new seeder (mirror an existing table)
2. JSON specs at `ims-data/{type}/{type}-level-3.json`
3. Register path in `ComponentSpecPaths.php`
4. Add the type to `VALID_COMPONENT_TYPES` and the dispatch cases in `api/api.php`
5. Seeder adding ACL perms: `{type}.view/.create/.edit/.delete`

## Conventions

| Category | Pattern | Example |
|---|---|---|
| Classes | PascalCase | `ServerBuilder` |
| Functions | camelCase | `validateComponentUuid()` |
| DB tables | snake_case | `server_configurations` |
| API actions | kebab-case | `server-add-component` |
| ACL perms | dot notation | `server.create` |

Standard response shape: `{success, authenticated, code, message, timestamp, data}` via `send_json_response()`.

## Deep reference (local-only — gitignored & not deployed; read only when the task needs it)

- `docs/api/API_REFERENCE.md` — full endpoint/parameter catalog
- `docs/architecture/DATABASE_SCHEMA.md` — full schema
- `docs/architecture/ARCHITECTURE.md` — request-flow detail
- `docs/development/DEVELOPMENT_GUIDELINES.md` — extended coding standards
- `IMS API Development.postman_collection.json` — request examples
- In-repo, always current: file headers of `ValidationPipeline.php` and `tests/characterize_compatibility.php` (migration plan + test methodology), and `database/seeders/*.sql` (schema change history).

If a doc contradicts the code, the code wins — flag the stale doc to the user.

## Workflow

- For non-trivial tasks: write a plan to `tasks/todo.md`, get approval, execute one item at a time, mark complete as you go, finish with a short summary.
- Fix root causes, not symptoms. No temporary patches.
- Prefer editing existing files over creating new ones. Never create documentation files unless explicitly asked.
- Change only what the task requires — the right solution is the simplest one that fully solves the problem.
- Remember every save deploys to production ~20s later: keep edits atomic and never save a file in a half-broken state.