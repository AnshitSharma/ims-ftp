# CLAUDE.md

**Context-Aware Instructions for Claude Code**

> **Token Optimization Strategy**: This file uses reference links to separate detailed documentation. Only read linked files when needed for specific tasks to minimize token usage.

## Quick Reference Links

- 📋 **[API_REFERENCE.md](claude/API_REFERENCE.md)** - Complete endpoint catalog with parameters & testing quick reference
- 🏗️ **[ARCHITECTURE.md](claude/ARCHITECTURE.md)** - System design and request flow
- 📁 **[FOLDER_STRUCTURE.md](claude/FOLDER_STRUCTURE.md)** - Directory layout and file organization
- 💻 **[DEVELOPMENT_GUIDELINES.md](claude/DEVELOPMENT_GUIDELINES.md)** - Coding standards and best practices
- 🗄️ **[DATABASE_SCHEMA.md](claude/DATABASE_SCHEMA.md)** - Complete database structure

## Project Identity

**BDC Inventory Management System (IMS)** - PHP REST API for server hardware inventory with JSON-driven compatibility validation.

**Stack**: PHP 7.4+ • MySQL/PDO • JWT Auth • ACL Authorization

**Purpose**: Track hardware inventory and build validated server configurations through component compatibility engine.

## Core Concepts (Essential Knowledge)

### 1. Action-Based Routing
```
Format: {module}-{operation}
Examples: cpu-list, server-add-component, auth-login
Flow: HTTP → api/api.php → Auth → ACL → Module Handler → JSON Response
```

### 2. Component Types (9 Total)
`cpu` `ram` `storage` `motherboard` `nic` `caddy` `chassis` `pciecard` `hbacard`

**Critical Rule**: All component UUIDs **must exist** in `All-JSON/{type}-jsons/*.json` before inventory insertion.

### 3. UUID Validation Chain
```
{component}-add → BaseFunctions::addComponent()
              → ComponentDataService::validateComponentUuid()
              → Search JSON files → BLOCK if not found
```

### 4. Compatibility System Architecture

**Main Orchestrator**: `FlexibleCompatibilityValidator.php`

**Specialized Validators**:
- `StorageConnectionValidator.php` - Storage/HBA/backplane/interface validation
- `PCIeSlotTracker.php` - PCIe slot allocation and tracking
- `ComponentCompatibility.php` - CPU-Motherboard, RAM-Motherboard pair checks

**Data Layer**:
- `ComponentDataService.php` - JSON spec loader with caching
- `ChassisManager.php` - Chassis-specific JSON handler
- `DataExtractionUtilities.php` - Spec extraction utilities

**State Management**:
- `ServerBuilder.php` - Configuration state manager
- `ServerConfiguration.php` - Database persistence

## Essential File Map

| Purpose | Path |
|---------|------|
| Main Router | [api/api.php](api/api.php) |
| Auth/JWT | [includes/JWTHelper.php](includes/JWTHelper.php) |
| ACL System | [includes/ACL.php](includes/ACL.php) |
| Base Functions | [includes/BaseFunctions.php](includes/BaseFunctions.php) |
| DB Config | [includes/db_config.php](includes/db_config.php) |
| Component Specs | `All-JSON/{type}-jsons/*.json` |
| Compatibility | [includes/models/FlexibleCompatibilityValidator.php](includes/models/FlexibleCompatibilityValidator.php) |

## Quick Start Development

```bash
# Check PHP version (7.4+ required)
php -v

# Start local dev server
php -S localhost:8000 -t .

# Test authentication
curl -X POST http://localhost:8000/api/api.php \
  -d "action=auth-login" \
  -d "username=admin" \
  -d "password=yourpassword"
```

## Testing Credentials

**Staging Server**: `https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php`

**Default Admin Credentials**:
- Username: `superadmin`
- Password: `password`

**Usage**:
```bash
# Login to staging
curl -s -X POST "https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php" \
  -d "action=auth-login" \
  -d "username=superadmin" \
  -d "password=password" | jq -r '.data.tokens.access_token'
```

**Setup Requirements**:
1. Import `shubhams_bdc_ims main.sql` to MySQL
2. Configure `.env` and `includes/db_config.php` with DB credentials
3. Ensure PHP can read `All-JSON/` and write logs

## Standard Response Format

All endpoints return:
```json
{
  "success": true|false,
  "authenticated": true|false,
  "code": 200|400|401|403|404|500,
  "message": "Human-readable message",
  "timestamp": "2025-11-05 12:34:56",
  "data": {}
}
```

**Helper**: `send_json_response($success, $authenticated, $code, $message, $data)`

## Common Development Tasks

### Adding New Component Type

1. Create table: `{type}inventory` (follow existing pattern)
2. Add JSON: `All-JSON/{type}-jsons/{type}-level-3.json`
3. Register in: `ComponentDataService::$componentJsonPaths`
4. Map table in: `api.php::getComponentTableName()`
5. Add ACL perms: `{type}.view`, `{type}.create`, `{type}.edit`, `{type}.delete`

### Modifying Compatibility Logic

- **Storage validation** → Edit `StorageConnectionValidator.php`
- **PCIe devices** → Use `PCIeSlotTracker.php`
- **Component pairs** → Edit `ComponentCompatibility.php`
- **Always** load specs via `ComponentDataService` (cached)
- **Never** hardcode specifications

### Server Configuration Workflow

```
1. server-initialize     → Create config, select chassis
2. server-add-component  → Add components (validated each time)
3. server-get-compatible → Query compatible options
4. server-validate-config → Full validation check
5. server-finalize-config → Lock config, mark components in_use
```

## Security & Permissions

**JWT Authentication**: Required for all endpoints except `auth-*`

**ACL Permission Format**: `{module}.{action}`

**Examples**: `server.create`, `cpu.view`, `ram.delete`, `acl.manage`, `dashboard.view`

**Error Handling**:
- Log all exceptions via `error_log()`
- Use proper HTTP codes: 400 (bad request), 401 (unauth), 403 (forbidden), 404 (not found), 500 (error)
- **Never** expose credentials/paths in responses

## Common Pitfalls ⚠️

1. **Table Names**: Types are lowercase (`cpu`) but tables have suffix (`cpuinventory`)
2. **UUID Validation**: Always required - don't bypass `validateComponentUuid()`
3. **Status Values**: `0`=failed, `1`=available, `2`=in_use
4. **JWT Expiry**: Default 24h via `JWT_EXPIRY_HOURS` in `.env`
5. **CORS**: Configured in `api.php` - adjust for production
6. **File Perms**: PHP must read `All-JSON/` and write logs

## Version Info

- **Current**: v1.0 (Stable, 2025-09-18)
- **Changelog**: See `STABLE_VERSION_1.md`

## Development Guidelines

**Core Principles**:
- Do what is asked; nothing more, nothing less
- **Always** prefer editing existing files over creating new ones
- **Never** create documentation unless explicitly requested
- Read reference docs (API_REFERENCE.md, etc.) **only when needed** for specific tasks

**For detailed information**, see:
- Complete API catalog → [API_REFERENCE.md](API_REFERENCE.md)
- Architecture details → [ARCHITECTURE.md](ARCHITECTURE.md)
- Coding standards → [DEVELOPMENT_GUIDELINES.md](DEVELOPMENT_GUIDELINES.md)
- Folder structure → [FOLDER_STRUCTURE.md](FOLDER_STRUCTURE.md)
- Database schema → [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md)

## Documentation Standards ⚡

**CRITICAL: Create ONE concise documentation file per task**

### Folder Organization (MANDATORY)
```
documentation/
├── {folder-name}/              # Use task/chat name
│   └── README.md              # Single file: findings + implementation + status
└── {another-task}/
    └── README.md
```

### Single File Structure (`README.md`)
Each documentation file must include (in order):

1. **📊 Key Findings** (CRITICAL first)
   - Bold critical items with effort estimates
   - Max 10-15 bullet points total
   - Format: `- **Critical:** Finding (impact, effort)`

2. **🛠️ Implementation** (if applicable)
   - Numbered step-by-step approach
   - Code snippets (before/after) only if needed
   - Brief technical notes

3. **📈 Status/Results** (if applicable)
   - Use tables for metrics
   - Concise summary of outcomes
   - No redundant details

### Content Guidelines
✅ **DO**:
- Use bold for critical/high-priority items
- Use bullet points for lists
- Use tables for data/metrics
- Keep sentences short and direct
- Skip unnecessary details

❌ **DON'T**:
- Create multiple files per task
- Write verbose paragraphs
- Repeat information across sections
- Add files in root folder
- Create without folder structure

### Example Single-File Documentation

```markdown
# Task: Validation Audit

## 📊 Key Findings

**CRITICAL:**
- **Invalid UUID validation** in StorageConnectionValidator (4h to fix)
- **Missing PCIe slot tracking** (3h to fix)

**HIGH:**
- Incomplete error messages in API (2h to fix)

**MEDIUM:**
- Inconsistent naming conventions (1h to fix)

## 🛠️ Implementation

1. **Fix UUID validation**
   - Location: includes/models/StorageConnectionValidator.php:142
   - Change: Add ComponentDataService call before validation

2. **Add PCIe tracking**
   - Create new tracking array in ServerBuilder.php
   - Update compatibility checks

3. **Update error messages**
   - Use send_json_response() with detailed messages
   - Test all endpoints

## 📈 Status

| Issue | Status | Effort |
|-------|--------|--------|
| UUID Validation | ✅ Fixed | 4h |
| PCIe Tracking | ✅ Fixed | 3h |
| Error Messages | ✅ Fixed | 2h |
| Naming Conventions | ⏳ Pending | 1h |

**Total: 80% Complete (9h of 10h)**
```

### When Creating Documentation
1. **Create folder:** `documentation/{task-name}/`
2. **Create single README.md** with all sections
3. **Use clear headers** and keep formatting consistent
4. **Review for conciseness** - every line should add value

### Examples of Good Folder Names
- `documentation/validation-audit/`
- `documentation/api-endpoints/`
- `documentation/database-schema/`
- `documentation/compatibility-engine/`
- `documentation/authentication-system/`
