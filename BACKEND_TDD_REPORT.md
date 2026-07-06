# IMS Backend TDD Report

Version: 1.0
Date: 2026-04-10
Scope: `ims-ftp` backend
Interpretation: TDD in this document means Technical Design Document.

## 1. Purpose

This document describes the current backend design of `ims-ftp` as implemented in the active PHP source tree. It is intended to give developers, reviewers, and maintainers a working view of:

- request routing and execution flow
- module responsibilities
- persistence model
- security model
- external dependencies
- operational risks and recommended next steps

This report is based primarily on the live code under `api/` and `core/`.

## 2. Current Scope and Constraints

The backend currently implements these major domains:

- authentication and token lifecycle
- ACL and role/permission management
- inventory CRUD for component types
- server configuration and build orchestration
- compatibility validation
- chassis-specific utilities
- ticket workflow management
- dashboard/search/user management

Observed repository constraint on 2026-04-10:

- the active working tree does not currently contain `tests/`, `database/`, `docs/`, `resources/`, or `logs/`
- backend behavior therefore has to be understood from the PHP source and SQL used in code paths
- some deleted paths are still visible in git status, which indicates repo drift between current source and earlier structure

## 3. High-Level Architecture

### 3.1 Primary Execution Model

The intended primary entrypoint is `api/api.php`.

Request flow:

1. bootstrap config from `core/config/app.php`
2. bootstrap helpers from `core/helpers/BaseFunctions.php`
3. set CORS and error handling
4. initialize ACL system
5. parse `action` as `{module}-{operation}`
6. bypass auth only for `auth-*`
7. authenticate JWT for all other modules
8. enforce permission mapping
9. dispatch to module handler or inline operation block
10. emit standardized JSON response

### 3.2 Architectural Pattern

The codebase uses a mixed architecture:

- centralized front controller in `api/api.php`
- delegated handler files for some modules such as roles, permissions, tickets, server, and compatibility
- inline procedural logic in `api/api.php` for auth, dashboard, search, users, ACL, and component CRUD
- deep business logic in service/model classes under `core/models`

This means the backend is not fully layered. It is better described as a procedural API gateway on top of several large domain services.

## 4. Main Subsystems

### 4.1 Configuration and Bootstrap

Key file: `core/config/app.php`

Responsibilities:

- load `.env`
- define runtime constants
- initialize timezone
- initialize JWT configuration
- initialize PDO database connection
- set DB lock wait timeout

Critical environment dependencies:

- `JWT_SECRET`
- `DB_HOST`
- `DB_USER`
- `DB_PASS`
- `DB_NAME`
- `TIMEZONE`
- `CORS_ALLOWED_ORIGINS`
- `IMS_DATA_PATH` or sibling `ims-data` directory

Failure mode:

- missing JWT secret or DB credentials hard-fails the backend during bootstrap

### 4.2 Authentication

Key files:

- `api/api.php`
- `core/auth/JWTHelper.php`
- `core/helpers/BaseFunctions.php`
- `core/helpers/RateLimiter.php`
- `core/helpers/EmailHelper.php`

Implemented auth capabilities:

- login
- logout
- access token refresh
- token verification
- authenticated registration
- forgot password
- reset password

Design notes:

- access tokens are JWT-based
- refresh tokens are persisted in `auth_tokens`
- login and password reset flows are rate limited by client IP
- password reset depends on email infrastructure through `EmailHelper`

Observations:

- auth is implemented inside `api/api.php`, not as a dedicated controller class
- JWT helper functions exist in more than one place, which increases duplication risk

### 4.3 Authorization and ACL

Key files:

- `core/auth/ACL.php`
- `core/helpers/BaseFunctions.php`
- `api/handlers/acl/roles_api.php`
- `api/handlers/acl/permissions_api.php`

Responsibilities:

- initialize roles and permissions
- assign/revoke roles
- assign/revoke permissions
- resolve effective permissions through direct and role-based grants
- cache per-request authorization decisions

Design notes:

- `api/api.php` enforces coarse permission mapping before routing into sensitive operations
- ACL class owns most role and permission lifecycle behaviors
- helper layer exposes convenience functions such as `hasPermission()` and `getUserPermissions()`

Important risk:

- ACL code is inconsistent on table naming
- some paths use `roles` and `permissions`
- other helper paths still use `acl_permissions`

This suggests the ACL layer is mid-migration or partially consolidated.

### 4.4 Inventory and Component Management

Key files:

- `api/api.php`
- `api/handlers/components/components_api.php`
- `core/models/components/ComponentDataService.php`
- `core/models/components/ComponentValidator.php`
- `core/models/components/ComponentSpecificationAdapter.php`
- `core/models/components/ComponentSpecPaths.php`

Supported inventory types:

- `cpu`
- `motherboard`
- `ram`
- `storage`
- `nic`
- `caddy`
- `chassis`
- `pciecard`
- `hbacard`
- `sfp`

Responsibilities:

- CRUD over inventory tables
- status tracking: failed, available, in use
- model/spec lookup from JSON data
- validation against component spec catalogs
- inventory search/filter/sort/pagination

Data source model:

- physical inventory lives in MySQL
- canonical technical specifications live in JSON files resolved by `ComponentSpecPaths`
- `ComponentDataService` bridges database records and JSON specs

External dependency:

- the backend expects component JSON catalogs in `ims-data`

### 4.5 Server Configuration and Build Orchestration

Key files:

- `api/handlers/server/server_api.php`
- `core/models/server/ServerBuilder.php`
- `core/models/server/ServerConfiguration.php`

This is the most complex subsystem.

Core responsibilities:

- create draft server configurations
- add/remove inventory components
- track component assignment into configuration JSON fields
- compute slot, bay, and interface availability
- validate configuration completeness and compatibility
- finalize configurations
- import virtual configurations into real builds
- maintain configuration history

Key design characteristics:

- `ServerBuilder.php` is the orchestration center
- configuration state is stored in `server_configurations`
- component selections are stored as JSON fragments inside configuration columns
- finalization changes inventory state by marking components in use

Implementation note:

- `ServerBuilder.php` is approximately 6970 lines
- that file currently combines orchestration, validation, persistence updates, slot tracking, power calculation, and reporting logic

This is the main backend hotspot for maintenance risk.

### 4.6 Compatibility Engine

Key files:

- `api/handlers/server/compatibility_api.php`
- `core/models/compatibility/ComponentCompatibility.php`
- `core/models/compatibility/StorageConnectionValidator.php`
- `core/models/compatibility/UnifiedSlotTracker.php`
- `core/models/compatibility/NICPortTracker.php`
- `core/models/compatibility/SFPPortTracker.php`
- `core/models/compatibility/SFPCompatibilityResolver.php`
- `core/models/compatibility/OnboardNICHandler.php`

Responsibilities:

- pairwise compatibility checks
- whole-configuration compatibility analysis
- storage path validation
- slot and lane accounting
- onboard NIC handling
- SFP to NIC port compatibility

Design notes:

- compatibility is available both as a standalone module and as part of server build actions
- server add/validate operations rely heavily on compatibility services
- storage and network validation have dedicated sub-engines

### 4.7 Chassis Module

Key files:

- `api/handlers/chassis/chassis_api.php`
- `core/models/chassis/ChassisManager.php`

Responsibilities:

- chassis CRUD
- chassis JSON validation
- chassis bay lookup and availability checks

Important observation:

- `chassis_api.php` follows an older standalone endpoint style
- it does not match the same runtime pattern as `api/api.php`
- it references legacy-style usage such as `new BaseFunctions($pdo)`, even though `BaseFunctions.php` is currently a function library, not an instantiated service class

This handler should be considered legacy or suspect until aligned with the main router.

### 4.8 Ticketing Workflow

Key files:

- `api/handlers/tickets/*.php`
- `core/models/tickets/TicketManager.php`
- `core/models/tickets/TicketValidator.php`
- `core/models/tickets/TicketItemService.php`
- `core/models/tickets/TicketHistoryService.php`
- `core/config/WorkflowConfig.php`
- `core/helpers/RequestHelper.php`

Capabilities:

- create ticket with itemized requested changes
- list and filter tickets
- retrieve single ticket with optional history
- update fields and status
- assign to user or role
- delete softly or permanently

Workflow statuses:

- `draft`
- `pending`
- `approved`
- `in_progress`
- `deployed`
- `completed`
- `rejected`
- `cancelled`

Important business rules:

- separation of duties for approval
- permission-driven status transitions
- ticket items validated against component and server context
- history persisted separately from current state

This subsystem is comparatively well-structured because ticket CRUD and validation are concentrated into dedicated classes instead of one router file.

### 4.9 Dashboard, Search, and User Management

Implemented mainly in `api/api.php` and `core/helpers/BaseFunctions.php`.

Capabilities:

- dashboard metrics
- recent activity retrieval
- global search across inventory tables
- user CRUD

Design note:

- these modules are functional but still procedural
- they depend on helper functions rather than dedicated domain services

## 5. Data Model Summary

The current code implies these table groups.

### 5.1 Security and Identity

- `users`
- `auth_tokens`
- `roles`
- `permissions`
- `user_roles`
- `role_permissions`
- `user_permissions`
- `password_resets`

### 5.2 Inventory

- `cpuinventory`
- `motherboardinventory`
- `raminventory`
- `storageinventory`
- `nicinventory`
- `caddyinventory`
- `chassisinventory`
- `pciecardinventory`
- `hbacardinventory`
- `sfpinventory`

Common inventory fields inferred from code:

- `ID`
- `UUID`
- `SerialNumber`
- `Status`
- `ServerUUID`
- `Location`
- `RackPosition`
- `PurchaseDate`
- `InstallationDate`
- `WarrantyEndDate`
- `Flag`
- `Notes`
- timestamps

### 5.3 Configuration and Audit

- `server_configurations`
- `server_configuration_history`
- `inventory_log`

### 5.4 Ticketing

- `tickets`
- `ticket_items`
- `ticket_history`

## 6. Runtime Data Flow

### 6.1 Standard Authenticated Request

1. client sends `action`
2. router splits module and operation
3. JWT is read from `Authorization: Bearer ...`
4. user is loaded from DB
5. permission is resolved using role and direct grants
6. handler executes business logic
7. persistence updates run through PDO
8. JSON response is emitted via `send_json_response()`

### 6.2 Server Build Flow

1. create server configuration
2. select/add chassis, motherboard, CPU, RAM, storage, NIC, HBA, PCIe, SFP
3. validate each addition against availability and compatibility
4. update both configuration JSON fields and inventory status
5. run comprehensive validation
6. finalize configuration

### 6.3 Ticket Flow

1. create draft ticket with target server and item list
2. submit to pending
3. approve or reject
4. assign to user or role
5. move to in progress
6. deploy
7. complete or cancel
8. persist ticket history throughout transitions

## 7. Non-Functional Characteristics

### 7.1 Performance

Positive:

- request-level permission caching exists
- request-level JWT user caching exists in one auth helper
- dashboard query uses union-based aggregation
- component spec cache exists in `ComponentDataService`

Risks:

- `ServerBuilder` centralizes many expensive operations
- compatibility checks may involve repeated JSON parsing and multiple DB reads
- procedural router style makes shared middleware behavior harder to optimize consistently

### 7.2 Security

Positive:

- JWT auth across protected modules
- explicit permission checks
- prepared statements are widely used
- password reset and login are rate limited

Risks:

- duplicated auth helper implementations
- mixed old/new handlers may bypass the same security assumptions
- some error handling paths still expose internal exception text in handler responses

### 7.3 Maintainability

Main issues:

- very large files in critical paths
- mixed centralized and standalone handler styles
- duplicated helper responsibilities
- partial ACL naming migration
- business logic spread between router, helpers, and models

## 8. Current Risks and Gaps

### 8.1 Mixed Old/New API Surface

The backend currently contains both:

- a supported-looking front controller in `api/api.php`
- older standalone handlers like `components_api.php` and `chassis_api.php`

This creates ambiguity over the real supported entrypoints.

### 8.2 Legacy Dependency Drift

Examples observed in source:

- `components_api.php` calls `check_auth()` but no matching implementation is present in the active source tree
- `chassis_api.php` treats `BaseFunctions` like a class instance, while `BaseFunctions.php` is currently procedural

These files are likely stale or partially migrated.

### 8.3 ACL Table Naming Inconsistency

The codebase mixes:

- `permissions`
- `acl_permissions`

and similarly relies on consolidated `roles` tables while older naming is still referenced in helper functions.

This is a correctness risk for deployments and migrations.

### 8.4 Missing Test Assets in Working Tree

The active worktree currently has no checked-in `tests/` directory. That means:

- there is no executable test suite in the present checkout
- regression risk is high for router, ACL, and server build flows
- validation relies heavily on manual testing or external environments

### 8.5 Missing Checked-In Schema in Working Tree

The active worktree currently has no `database/` directory. That creates risk around:

- reproducible environment setup
- migration traceability
- schema and code divergence

## 9. Recommended Refactoring Priorities

### Priority 1

- standardize on `api/api.php` as the single supported HTTP entrypoint
- retire or repair stale standalone handlers
- remove duplicate auth/helper paths

### Priority 2

- finish ACL table-name consolidation
- standardize on one permission schema
- update all helper functions to the same table set

### Priority 3

- split `ServerBuilder.php` into smaller services
- recommended boundaries:
  - inventory assignment service
  - compatibility orchestration service
  - slot and lane tracking service
  - configuration persistence service
  - validation/reporting service

### Priority 4

- restore `database/` and `tests/` to the active repo
- add automated coverage for:
  - auth flows
  - permission enforcement
  - component CRUD
  - server add/remove/finalize
  - ticket workflow transitions

## 10. Proposed Test Strategy

If test assets are restored, the minimum target matrix should be:

- unit tests for `ACL`, `JWTHelper`, `TicketValidator`, `ComponentDataService`
- unit tests for slot/storage/network validators
- integration tests for `auth-*`, `server-*`, `ticket-*`
- regression tests for permission-denied behavior
- fixture-based tests against a disposable MySQL schema

Highest-value first tests:

1. login, refresh, verify token
2. permission matrix for server create/view/edit/delete
3. server configuration create -> add component -> validate -> finalize
4. ticket draft -> pending -> approved -> deployed -> completed
5. inventory status transitions when configurations are deleted or finalized

## 11. Conclusion

`ims-ftp` is a capable PHP backend centered around inventory management, hardware compatibility validation, server build orchestration, and operational ticketing. Its strongest functional area is the server-building domain, but that same domain is also its largest structural risk because the implementation is concentrated in a very large orchestration file.

The backend is functional in design but currently shows clear repository and architectural drift:

- missing tests and schema from the active worktree
- stale standalone handlers
- duplicated auth patterns
- inconsistent ACL table naming

The immediate technical goal should be consolidation, not feature expansion. The fastest path to a safer backend is to restore supporting assets, choose one routing model, normalize ACL persistence, and break the server builder into smaller services.
