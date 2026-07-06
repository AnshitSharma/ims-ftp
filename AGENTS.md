# AGENTS.md

> **Token Optimization**: Reference docs linked below contain detailed information. Only read them when needed for specific tasks.

## Quick Reference Links

- [API_REFERENCE.md](docs/api/API_REFERENCE.md) - Complete endpoint catalog with parameters
- [ARCHITECTURE.md](docs/architecture/ARCHITECTURE.md) - System design and request flow
- [FOLDER_STRUCTURE.md](docs/architecture/FOLDER_STRUCTURE.md) - Directory layout
- [DEVELOPMENT_GUIDELINES.md](docs/development/DEVELOPMENT_GUIDELINES.md) - Coding standards
- [DATABASE_SCHEMA.md](docs/architecture/DATABASE_SCHEMA.md) - Complete database structure

## Project Identity

**BDC Inventory Management System (IMS)** - PHP REST API for server hardware inventory with JSON-driven compatibility validation.

**Stack**: PHP 7.4+ | MySQL/MariaDB via PDO | JWT Auth (HS256) | ACL Authorization

**Purpose**: Track hardware inventory and build validated server configurations through component compatibility engine.

## Quick Start

```bash
php -v                        # Check version (7.4+ required)
php -S localhost:8000 -t .    # Local dev server

# Test authentication
curl -X POST http://localhost:8000/api/api.php \
  -d "action=auth-login" \
  -d "username=superadmin" \
  -d "password=password"
```

**Production API**: `https://ims.bdcms.bharatdatacenter.com/Ims_backend/api/api.php`

**Setup**:
1. Import `database/schema.sql` to MySQL/MariaDB
2. Configure `.env` with DB credentials, JWT secret, URLs
3. Set `IMS_DATA_PATH` in `.env` or ensure `../ims-data/` is accessible
4. Ensure PHP can read `ims-data/` and write to `logs/`

---

## Core Concepts

### 1. Action-Based Routing
```
Format: {module}-{operation}
Examples: cpu-list, server-add-component, auth-login, ticket-create
Flow: HTTP → api/api.php → JWT Auth → ACL Check → Handler → JSON Response
```

### 2. Component Types (10 Total)
`cpu` `ram` `storage` `motherboard` `nic` `caddy` `chassis` `pciecard` `hbacard` `sfp`

**Critical Rule**: All component UUIDs **must exist** in `ims-data/{type}/*.json` before inventory insertion.

### 3. UUID Validation Chain
```
{component}-add → BaseFunctions::addComponent()
              → ComponentDataService::validateComponentUuid()
              → ComponentSpecPaths → Search JSON files → BLOCK if not found
```

### 4. Compatibility System Architecture

**Orchestrator**: `core/models/compatibility/ComponentCompatibility.php`

**Specialized Validators** (all in `core/models/compatibility/`):
- `ComponentCompatibility.php` - **Main orchestrator** + CPU-Motherboard, RAM-Motherboard pair checks
- `StorageConnectionValidator.php` - Storage/HBA/backplane/interface validation
- `UnifiedSlotTracker.php` - PCIe slot allocation and tracking
- `NICPortTracker.php` - NIC port tracking and allocation
- `SFPPortTracker.php` - SFP port tracking
- `SFPCompatibilityResolver.php` - SFP module compatibility resolution
- `OnboardNICHandler.php` - Onboard NIC handling

**Data Layer** (all in `core/models/components/`):
- `ComponentDataService.php` - JSON spec loader with request-level caching
- `ComponentSpecPaths.php` - Intelligent path resolution for ims-data directory
- `ComponentDataLoader.php` - JSON file loading
- `ComponentDataExtractor.php` - Spec extraction from JSON
- `ComponentCacheManager.php` - Cache management
- `ComponentQueryBuilder.php` - DB query builder for components
- `ComponentValidator.php` - Component validation logic
- `ComponentSpecificationAdapter.php` - Spec adaptation layer

**Chassis**: `core/models/chassis/ChassisManager.php` - Chassis-specific JSON handler

**Server State** (all in `core/models/server/`):
- `ServerBuilder.php` - Configuration state manager
- `ServerConfiguration.php` - Database persistence

**Tickets** (all in `core/models/tickets/`):
- `TicketManager.php` - Core ticket management
- `TicketValidator.php` - Ticket validation
- `TicketItemService.php` - Ticket item management
- `TicketHistoryService.php` - Ticket history tracking

**Shared Utilities** (all in `core/models/shared/`):
- `DataExtractionUtilities.php` - Spec extraction utilities
- `DataNormalizationUtils.php` - Data normalization

---

## File Structure

```
api/
├── api.php                    → Thin router: bootstrap, JWT auth, ACL gate, module dispatch
├── permission_map.php         → Central action → ACL permission map (no fallback; unmapped ops are rejected)
└── handlers/
    ├── auth/                  → auth_api.php (login, logout, refresh, register, forgot/reset password)
    ├── acl/                   → acl_api.php (user grants) + roles_api.php + permissions_api.php
    ├── server/                → Server config + compatibility checks
    ├── components/            → component_crud_api.php (all 10 types incl. chassis)
    ├── dashboard/             → dashboard_api.php (counts, activity logs)
    ├── search/                → search_api.php (global inventory search)
    ├── users/                 → users_api.php (user CRUD)
    ├── vendors/               → vendor_api.php (vendor CRUD, admin-role gated)
    └── tickets/               → Ticket CRUD (create, get, list, update, delete)

core/
├── config/
│   ├── app.php                → App init, .env loading, PDO connection
│   └── WorkflowConfig.php     → Workflow configuration
├── auth/
│   ├── JWTHelper.php          → JWT generation/verification (HS256)
│   ├── JWTAuthFunctions.php   → Auth helper functions
│   └── ACL.php                → Permission/role-based access control
├── helpers/
│   ├── BaseFunctions.php      → UUID gen, JSON responses, component management
│   ├── RequestHelper.php      → Request utilities
│   ├── EmailHelper.php        → Email sending
│   ├── CleanupHelper.php      → Cleanup utilities
│   └── RateLimiter.php        → Auth rate limiting (per-IP per-endpoint + per-username failed-login throttle)
├── models/
│   ├── compatibility/         → 7 validator files (see above)
│   ├── components/            → 8 component data files (see above)
│   ├── server/                → ServerBuilder + ServerConfiguration
│   ├── chassis/               → ChassisManager
│   ├── tickets/               → 4 ticket model files (see above)
│   └── shared/                → DataExtractionUtilities, DataNormalizationUtils
├── cache/                     → (reserved for future use)
└── services/                  → (reserved for future use)

database/
└── seeders/                   → Self-contained SQL change files (YYYY_MM_DD_NNN_*.sql)
    └── 2026_06_11_001_jwt-revocation-schema-and-token-hashing-cleanup.sql

docs/
├── api/API_REFERENCE.md
├── architecture/
│   ├── ARCHITECTURE.md
│   ├── DATABASE_SCHEMA.md
│   └── FOLDER_STRUCTURE.md
├── development/DEVELOPMENT_GUIDELINES.md
└── planning/                  → Modernization plans, deprecated file audits

documentation/                 → Implementation guides & fix summaries
resources/templates/           → Email/notification templates
tests/                         → Unit + integration test structure
logs/                          → Application logs
```

## Essential File Map

| Purpose | Path |
|---------|------|
| Main Router | `api/api.php` |
| App Config / DB | `core/config/app.php` + `.env` |
| JWT Auth | `core/auth/JWTHelper.php` |
| ACL System | `core/auth/ACL.php` |
| Base Functions | `core/helpers/BaseFunctions.php` |
| Compatibility Orchestrator | `core/models/compatibility/ComponentCompatibility.php` |
| Component Spec Paths | `core/models/components/ComponentSpecPaths.php` |
| Component Data Service | `core/models/components/ComponentDataService.php` |
| Server Builder | `core/models/server/ServerBuilder.php` |
| Ticket Manager | `core/models/tickets/TicketManager.php` |
| Component Specs | `ims-data/{type}/*.json` (external, via `IMS_DATA_PATH` or `../ims-data/`) |
| DB Export | `../imsbdcmsbharatda_Ims_Production.sql` (root of repo) |
| Postman Collection | `IMS API Development.postman_collection.json` |

## API Modules

| Module | Actions | Handler Path |
|--------|---------|-------------|
| `auth` | login, logout, refresh, register, forgot_password, reset_password | `api/handlers/auth/auth_api.php` |
| `server` | create-start, add-component, remove-component, get-config, list-configs, validate-config, finalize-config | `api/handlers/server/server_api.php` |
| `compatibility` | check, statistics | `api/handlers/server/compatibility_api.php` |
| `{component}` | list, get, add, update, delete | `api/handlers/components/component_crud_api.php` |
| `dashboard` | get_data, get-logs | `api/handlers/dashboard/dashboard_api.php` |
| `search` | global | `api/handlers/search/search_api.php` |
| `users` | list, get, create, update, delete | `api/handlers/users/users_api.php` |
| `vendor` | list, get, add, update, delete, components | `api/handlers/vendors/vendor_api.php` |
| `ticket` | create, get, list, update, delete | `api/handlers/tickets/` |
| `acl` | get_user_permissions, assign/revoke permission & role, get_all_roles/permissions | `api/handlers/acl/acl_api.php` |
| `roles` | list, create, update, delete | `api/handlers/acl/roles_api.php` |
| `permissions` | list, get-all | `api/handlers/acl/permissions_api.php` |

**Permission gating**: `server`, `compatibility`, and `{component}` actions are gated by `api/permission_map.php` (strict — add new actions there). Other modules check permissions inside their handlers. ACL reads/writes use the `permissions` table only (`acl_permissions` was dropped in seeder `2026_06_11_002`).

## Standard Response Format

```json
{
  "success": true,
  "authenticated": true,
  "code": 200,
  "message": "Human-readable message",
  "timestamp": "2025-11-05 12:34:56",
  "data": {}
}
```

**Helper**: `send_json_response($success, $authenticated, $code, $message, $data)`

## Environment Configuration (.env)

```
JWT_SECRET=...              # HS256 secret (256+ bits)
JWT_EXPIRY_HOURS=24         # Token expiry
JWT_ISSUER=bdc-ims-api
JWT_AUDIENCE=bdc-ims-client
DB_HOST=localhost
DB_NAME=imsbdcmsbharatda_Ims_Production
DB_USER=...
DB_PASS=...
TIMEZONE=UTC
MAIN_SITE_URL=...
FRONTEND_URL=...
MAIL_FROM_ADDRESS=...
MAIL_FROM_NAME=...
IMS_DATA_PATH=              # Optional - path to ims-data directory
```

---

## Common Development Tasks

### Adding New Component Type
1. Create table: `{type}inventory` (follow existing pattern)
2. Add JSON specs: `ims-data/{type}/{type}-level-3.json`
3. Register in: `ComponentSpecPaths.php` path mapping
4. Map table in: `api.php::getComponentTableName()`
5. Add ACL perms: `{type}.view`, `{type}.create`, `{type}.edit`, `{type}.delete`

### Modifying Compatibility Logic
- **Storage validation** → `core/models/compatibility/StorageConnectionValidator.php`
- **PCIe/slot tracking** → `core/models/compatibility/UnifiedSlotTracker.php`
- **Component pairs** → `core/models/compatibility/ComponentCompatibility.php`
- **NIC ports** → `core/models/compatibility/NICPortTracker.php`
- **SFP modules** → `core/models/compatibility/SFPCompatibilityResolver.php`
- **Always** load specs via `ComponentDataService` (cached)
- **Never** hardcode specifications

### Server Configuration Workflow
```
1. server-create-start    → Create config, select chassis
2. server-add-component   → Add components (validated each time)
3. server-get-compatible  → Query compatible options
4. server-validate-config → Full validation check
5. server-finalize-config → Lock config, mark components in_use
```

---

## Security & Permissions

**JWT Authentication**: Required for all endpoints except `auth-*`

**ACL Permission Format**: `{module}.{action}` (109 permissions across 30+ roles)

**Examples**: `server.create`, `cpu.view`, `ram.delete`, `acl.manage`, `dashboard.view`

**Error Handling**:
- Log all exceptions via `error_log()`
- Use proper HTTP codes: 400, 401, 403, 404, 500
- **Never** expose credentials/paths in responses

## Common Pitfalls

1. **Table Names**: Types are lowercase (`cpu`) but tables have suffix (`cpuinventory`)
2. **UUID Validation**: Always required - don't bypass `validateComponentUuid()`
3. **Status Values**: `0`=failed, `1`=available, `2`=in_use
4. **JWT Expiry**: Default 24h via `JWT_EXPIRY_HOURS` in `.env`
5. **CORS**: Configured in `api.php` - adjust for production
6. **File Perms**: PHP must read `ims-data/` and write `logs/`
7. **JSON Path Resolution**: `ComponentSpecPaths.php` auto-discovers `ims-data/` via relative paths — watch `../` depth
8. **Chassis filename typo**: `chasis-level-3.json` (missing 's') — do not rename, code depends on it

---

## Database Changes — Seeder Files (MANDATORY)

Every database change (table, column, index, data migration, ACL permission rows) MUST be delivered as a new self-contained SQL seeder file in `database/seeders/`, named `YYYY_MM_DD_NNN_short-description.sql`, runnable against the server database in one go. Never edit an existing seeder — always create a new file. Show the SQL to the user after writing it. See `database/seeders/README.md` for the template and full rules.

## Development Guidelines

**Core Principles**:
- Do what is asked; nothing more, nothing less
- **Always** prefer editing existing files over creating new ones
- **Never** create documentation unless explicitly requested
- Read reference docs **only when needed** for specific tasks

**Conventions**:
| Category | Pattern | Example |
|----------|---------|---------|
| Classes | PascalCase | `ServerBuilder`, `ComponentDataService` |
| Functions | camelCase | `authenticateWithJWT()`, `validateComponentUuid()` |
| DB tables | snake_case | `server_configurations`, `acl_permissions` |
| API actions | kebab-case | `server-add-component` |
| ACL perms | dot notation | `server.create` |

## Task Execution Workflow

**MANDATORY PROCESS** - Follow these steps for EVERY task:

1. **Plan First** → Read relevant files, write plan to `tasks/todo.md`
2. **Get Approval** → Verify plan with user before starting
3. **Execute Incrementally** → One todo item at a time, mark complete as you go
4. **Review & Document** → Add summary to `tasks/todo.md`

**NON-NEGOTIABLE RULES:**
- **NO LAZINESS** → Find root causes, no temporary fixes, no shortcuts
- **SENIOR-LEVEL WORK** → Every fix must be thorough and production-ready
- **MAXIMUM SIMPLICITY** → Change only what's necessary
- **ZERO BUG TOLERANCE** → Every change must avoid introducing bugs
- **SURGICAL PRECISION** → Touch only code relevant to the task

**Complexity Principle**: The right solution is the simplest one that fully solves the problem.
