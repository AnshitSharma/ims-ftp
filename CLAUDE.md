# ims-ftp — BDC IMS backend

PHP REST API for hardware inventory with JSON-driven compatibility validation. PHP 7.4+ ·
MariaDB via PDO · JWT HS256 · custom ACL · no framework, no Composer — plain `require`.
The root CLAUDE.md holds the API contract, the data contract and the seeder rules.

Config lives in `.env` on the server (DB creds, `JWT_SECRET`, `IMS_DATA_PATH`, rate limits,
engine flags). Never print its values.

## Request flow

`api/api.php` (single entrypoint) -> JWT auth -> ACL gate -> `api/handlers/{module}/` ->
`send_json_response()`. Modules: `auth`, `server`, `compatibility`, `rack`, `pipelines`,
`location`, the 13 component types, `dashboard`, `search`, `users`, `vendors`, `acl`.

- `api/permission_map.php` is strict for `server`, `compatibility`, `rack` and every component
  action (components share one `component` template with `{module}` substitution). An unmapped
  operation is rejected as "Unknown operation".
- Pipeline handlers are one file per operation and check permissions inside the file (via the
  `$acl` / `$user_id` globals) rather than through the map.
- `rack` and `pipeline` carry a role gate to admin / super_admin in code on top of ACL. Keep
  both gates.
- ACL reads the `permissions` table only. `acl_permissions` was dropped in seeder
  `2026_06_11_002` — never reference it.
- **`GrantPolicy`** (`core/helpers/`) is the privilege-escalation gate on top of `hasPermission()`
  — added 2026-09-13 because holding `users.edit`/`users.manage_roles` was enough to mint a
  super_admin, take over an admin account by changing its email, or strip a user's last role.
  `assertMayAssignRole()` and friends require the ACTOR to already hold what they are handing
  out (super_admin grantable only by super_admin; admin only by admin/super_admin) — a no-op for
  admin/super_admin themselves, since `hasPermission()` bypasses everything for them, so this
  bites only the roles that could otherwise escalate. Every user-write endpoint that touches a
  role or a security-sensitive field should call through it.

## Two ways in, one session

Password login and Microsoft (Entra ID) sign-in both end at
`auth_api.php::buildUserSession()`, which mints the JWT, stores the refresh token and builds
the response. Whatever proved the identity, what comes out is identical — so never issue a
token anywhere else, and never change one path's expiry or payload without the other.

`auth-microsoft_start` / `_callback` run an authorization-code + PKCE flow: `MicrosoftOAuth`
(`core/auth/`) talks to Microsoft and verifies the id_token in full (RS256 against the tenant
JWKS, plus `iss`/`aud`/`tid`/`nonce`/`exp`); `handlers/auth/microsoft_auth.php` owns the
single-use `oauth_login_states` row and the user lookup. **Link only** — a Microsoft account
must already match an active `users` row by `azure_oid`, or once by email, which then writes
`azure_oid`. Nothing auto-provisions. The whole feature is inert unless `MS_TENANT_ID`,
`MS_CLIENT_ID`, `MS_CLIENT_SECRET` and `MS_REDIRECT_URI` are all set in `.env`;
`auth-microsoft_status` is what the login page asks before showing its button.

## Compatibility validation — one engine

`core/models/validation/`: `ValidationEngine` dispatching ~20 rules from `rules/`, with
`TargetStateBuilder`, `ValidateConfigService` and `SlotPlanner`. (`ShadowRunner` is gone — P9
deleted it with the shadow-mode machinery.) Editing a rule is a live behavioural change on
deploy, unconditionally; see the flag note below. It judges the add, the compatible-parts
listing and — since 2026-09-22 — Request items, which `TicketValidator` asks through
`ServerBuilder::evaluateSpecCompatibility()` (add items only; remove/replace are judged by
their command when the step runs, serverplatform by set-platform, because the rules throw on
that type).

The legacy pairwise engine — `ComponentCompatibility`, `ComponentValidator`,
`ComponentDataExtractor`, `ComponentDataLoader` — was deleted 2026-09-22 (audit Phase F), after
a replay of both over 74 configs × 193 stocked models. Rule docblocks still cite its old line
numbers as provenance; those are history, not live code. What remains in
`core/models/compatibility/` are helpers the engine and commands still use: `UnifiedSlotTracker`,
`PcieLaneBudgetValidator`, `NICPortTracker`, `SFPCompatibilityResolver`, `OnboardNICHandler`,
`StorageConnectionValidator`, `CpuIdentityMatcher`, `CpuGenerationResolver`, `ServerState`.

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
(`ValidationPipeline.php` used to carry the migration plan in its file header; the file itself
was deleted by P9, so that plan now lives only in `tasks/migration-completion.md`.)

## Server build

create-start -> add-component (validated per add) -> get-compatible -> validate-config ->
finalize-config (locks the config, marks components in_use). `ServerBuilder.php` holds state,
`ServerConfiguration.php` persistence. `ConfigurationReverseLookup.php` was deleted 2026-08-30
having never had a single caller; the reverse lookup it was meant to provide now lives, in the
one place that needed it, inside `deleteComponent()` — see below.

**`ServerCreationService`** (`core/models/server/`) is the single path all three creation
routes (direct Create Server, import-virtual, an approved `server.config.create` request) go
through — decided 2026-09-13 after the three disagreed about serial number, location and
placement. A physical build is refused without site, rack and U (a virtual build is exempt in
full); creation, location and placement commit in one transaction, and the destination rack is
locked `FOR UPDATE` before its occupancy is read.

**`core/models/state/` — `StateGuard` / `StateMachine` / `StatusMap`.** `status_v2` is a real,
deliberate state-machine lifecycle (`draft`/`building`/`validating`/`validated`/`finalized`/
`deployed`/`maintenance`/`retired`), not a stalled migration off the legacy `configuration_status`
int — `StatusMap::CONFIG_V2_TO_LEGACY` is an intentionally LOSSY many-to-one map back to that
column (e.g. `building`/`validating` both read as legacy `2`; `deployed`/`maintenance`/`retired`
all read as legacy `3`), kept for whatever still reads the legacy int. `StateGuard::checkMutation()`
is the mutation gate: a config may be added-to/removed-from only while `status_v2` is one of
`draft`/`building`/`maintenance`; NULL `status_v2` (not yet backfilled) falls back to the legacy
rule (blocked only at `configuration_status === 3`). Do not treat the legacy column as dead code
or a delete target without checking every reader first.

**Deleting an inventory unit is guarded, and the guard is load-bearing.**
`deleteComponent()` (`core/helpers/Inventory.php` since the 2026-09-17 `BaseFunctions.php`
split — same global function, same call sites, just relocated) is the single choke point for
both `{type}-delete` and `{type}-bulk-delete`; it refuses with a 409 naming the configuration
when a live
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
- **Virtual builds (`is_virtual = 1`) reserve no stock, and since 2026-09-01 they get rows
  anyway.** `AddComponentCommand` / `ReplaceComponentCommand` branch on the locked row's
  `is_virtual`: they write the `config_components` row with `inventory_table` and
  `inventory_id` **NULL**, and skip the status flip to `in_use` and `LocationResolver::
  syncConfig()`. Before that they wrote the real unit's identity and moved it out of whatever
  real server held it (`uq_inventory_once` + `ON DUPLICATE KEY UPDATE config_uuid`), so a
  sandbox build silently stole production hardware. The NULLable columns come from seeder
  `2026_09_01_001`; the command probes `SHOW COLUMNS` first and refuses a virtual add with a
  503 until it has run, rather than falling back to stealing stock. `ConfigComponentWriter::
  afterLegacyAdd()` still refuses virtual configs, so the onboard-NIC/platform path is
  unchanged. A pre-2026-08-21 virtual config still reads as having no components at all;
  that cannot be backfilled, and its old JSON is in `server_configurations_json_archive`.
- `uq_slot_occupancy (config_uuid, slot_ref, removed_at)` does **not** prevent two live rows
  sharing a slot: every live row has `removed_at` NULL and MariaDB treats NULLs as distinct in a
  unique key, so the index only ever constrains tombstones sharing a timestamp. Probed
  2026-08-30. Code that needs slot exclusivity has to check for itself.

## Specs are not in the DB

Hardware specs are JSON in `ims-data/`; the DB holds inventory rows. Load them through
`ComponentDataService` (request-level cache) — never hardcode a specification.
`ComponentSpecPaths.php` resolves paths via `IMS_DATA_PATH`, else by walking relative paths to
`../ims-data/`, so watch `../` depth if files move.

**`database/spec_build.php`** projects every `ims-data/*.json` model into `component_models` —
the table that gives the catalogue an index SQL can use. Its own header says **run it after
every `ims-data` upload**; nothing else in the tree enforces that, so a spec change that isn't
followed by this script leaves `component_models` stale (readers fall back to the files, which
are still correct — see `SpecRepository` below — but any *SQL* consumer of `component_models`
sees the old data until the script runs).

**`SpecRepository`** (`core/models/components/`) is a landed-but-not-yet-wired single resolver
meant to replace `ComponentDataService`, `DataExtractionUtilities` and `ChassisManager`
(`ComponentDataLoader` was its one wired adapter, deleted with the legacy engine 2026-09-22;
`PlatformSpecIndex` stays a first-class dependency of it, not something it replaces).
**Not repointing `ComponentDataService` was a deliberate call on 2026-09-22 (audit F.4):** the
equivalence test passes against the files, but production's `spec-check` reported the table
STALE — 7 models missing, 2 changed — because nobody had run spec_build after an `ims-data`
upload. `ComponentDataService` feeds every validation rule and reads the files, which are
always current; repointing it would make every rule's answer depend on that unenforced step. `find()`/`allOfType()`/`exists()` read `component_models` when spec_build has run,
falling back to the files otherwise — never hard-fails on the table's absence. Re-pointing an
existing resolver at it is a **separate, single-purpose change per adapter**, gated on an
equivalence test proving zero shape difference first (`tests/spec_repository_equivalence.php`
for `ComponentDataService`; write the analogous test for whichever resolver is next) — the
docblock is explicit that landing the repository and moving a live call site onto it must never
be the same deploy, because there is no safe intermediate state once a resolver is repointed.

## Gotchas

- `core/helpers/BaseFunctions.php` is a thin shim as of 2026-09-17 — it only runs the one-time
  JWT/permission-cache/`VALID_COMPONENT_TYPES` init, then `require_once`s `Acl.php`,
  `Response.php`, `Dashboard.php`, `Inventory.php`, `Users.php`, `ActivityLog.php` (same
  directory). Every function that used to live in one 2,677-line file is unchanged and still
  global — no call site anywhere needed to change — but if you're hunting for a function's
  *definition*, it's in one of the six, not in `BaseFunctions.php` itself.
- Types are lowercase (`cpu`); tables carry the suffix (`cpuinventory`).
- `risercard` split out of `pciecard` on 2026-08-14 — risers occupy riser bays and *provide*
  pcie_slots; plain PCIe cards consume them.
- `serverplatform` became the 12th type on 2026-08-25. The version UUID, not the platform UUID,
  is the stocked SKU — see `ims-data/CLAUDE.md`.
- `networkdevice` (routers, switches, MUXes) became the 13th on 2026-09-29. It is stocked like any
  component but **racked, never built into a server**: `getBuildableComponentTables()` excludes it, so
  `AddComponentCommand` and the `server.component.add` Request action refuse it. Its placement lives in
  `rack_network_devices`, written only by `RackNetworkDevice::place()` / `unrack()` (`core/models/rack/`),
  which is the one door for `rack-device-assign`, `rack-device-unassign` and the Request actions
  `inventory.device.rack` / `.unrack`. Racked = Status 2 with the rack's site stamped on the unit;
  `updateComponent()` and `deleteComponent()` refuse to change or destroy a racked one, and
  `RackPlacement::occupancy()` counts devices, so a switch and a server cannot share a U.
- Tickets are retired as an engine: `core/models/tickets/*` (`TicketValidator`, `TicketItemService`,
  `TicketHistoryService`) now serve `PipelineManager`. There is no `TicketManager.php`.
- Errors: `error_log()` the exception, return a proper HTTP code, leak no paths or secrets.

## Tests (local CLI, never deployed)

`php` isn't on PATH; XAMPP's is at `/c/xampp/php/php.exe`. Lint changed files with `php -l`
before they auto-upload — a syntax error here is a live 500.

Four tests are left; none needs a database. There is no suite runner — run each directly.

- `tests/catalogue_uuid_gate.php` — every catalogue UUID is unique (or allow-listed) and all
  13 spec files were read; exit 1 otherwise.
- `tests/nic_port_layout_unit.php` — `NICPortTracker` per-port cages for mixed SFP/RJ45 cards.
- `tests/cable_catalogue_check.php [path]` — the cable catalogue. `ims-data/cable/` doesn't
  exist yet, so it exits 2 today.
- `tests/spec_repository_equivalence.php` — `SpecRepository` returns what
  `ComponentDataService` returns; exit 1 on any difference.

## Deleted tooling (history only)

`docs/`, `scripts/`, `reports/`, `BACKLOG.md` and every other suite under `tests/` were deleted
on 2026-09-08 (commit 09551e7, "cleaning"): the architecture/operations docs, `RULE_MAP.md`,
the INV-n invariants and their gate, `scripts/verify/run_all.php` and the nightly battery,
`audit-orphans.php`, `tests/run_tests.php` and the unit, regression and golden suites. Schema
history lives in `database/seeders/*.sql`. `git show 09551e7^:<path>` prints any deleted file.
Code comments still cite them (`BACKLOG §B-16`, `RULE_MAP.md`, INV-n): dead pointers, not
evidence the code is wrong. Where an old doc contradicts the code, the code wins.

**`migration/` is gone** — 140 execution packs, handoffs, phase plans and `phase-status.json`,
deleted 2026-08-31 once the migration completed. Comments elsewhere in the tree still say things
like "see `migration/handoffs/U-1.1-20260706.md` for the full reasoning": those pointers are
dead paths, not missing files — the content is in git (`git show HEAD:<path>` while the
deletion is still uncommitted; afterwards `git log --diff-filter=D --name-only -- migration/`
finds the deleting commit and `git show <sha>^:<path>` prints the file). Do not go hunting the
filesystem for them, and do not treat a dead pointer as evidence the code is wrong.
