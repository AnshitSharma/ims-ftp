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
meant to replace `ComponentDataService`, `DataExtractionUtilities`, `ComponentDataLoader` and
`ChassisManager` (`PlatformSpecIndex` stays a first-class dependency of it, not something it
replaces). `find()`/`allOfType()`/`exists()` read `component_models` when spec_build has run,
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

`BACKLOG.md`, `database/seeders/*.sql` as schema history, and `docs/`:

| File | What it is |
|---|---|
| `ARCHITECTURE.md`, `OPERATIONS.md` | written 2026-08-24, every claim `file:line` cited |
| `ARCHITECTURAL_INVARIANTS.md` | INV-1..INV-12. **Executable** — `scripts/ci/inv_extract.php` parses its CHECK blocks verbatim, so editing it changes what the gate enforces with no code change |
| `RULE_MAP.md` | which validation rule implements which unit; cited by ~30 files, including every rule class |
| `API-DEPRECATION.md` | the documented add/remove deviation; read at run time by `tests/api/add_remove_response_shape_test.php` |
| `PERF-BASELINE-REBLESS.md` | the quiet-machine capture procedure `performance_report.php --rebless` points the operator at |
| `PLAN_VERIFICATION_REVIEW.md` | F-5 (virtual configs excluded) and friends, cited by `audit-orphans.php` and `inventory_report.php` |
| `FINDING-20260824-replaceOnboardNIC-not-superseded.md` | the whole content of BACKLOG C-5, which is still OPEN |

Where a doc contradicts the code, the code wins; flag the stale doc.

**`migration/` is gone** — 140 execution packs, handoffs, phase plans and `phase-status.json`,
deleted 2026-08-31 once the migration completed. The six files above were pulled out of it
first, because live code reads or cites them. Comments elsewhere in the tree still say things
like "see `migration/handoffs/U-1.1-20260706.md` for the full reasoning": those pointers are
dead paths, not missing files — the content is in git (`git show HEAD:<path>` while the
deletion is still uncommitted; afterwards `git log --diff-filter=D --name-only -- migration/`
finds the deleting commit and `git show <sha>^:<path>` prints the file). Do not go hunting the
filesystem for them, and do not treat a dead pointer as evidence the code is wrong.

## The verification gates, after the 2026-08-31 cleanup

`scripts/verify/run_all.php` is the gate runner (`--quick`, or `--gate P<N>`). What survives is
what can still measure something: `schema`, `inventory`, `orphan`, `slot`, `ledger`,
`performance`, `invariants`, `regression`. `scripts/ci/nightly.sh` is the daily battery on top
of it, with an alert-on-red hook.

Deleted 2026-08-31, with the migration they gated: `parity`, `command_parity` and `read` (their
only input was `reports/shadow/*.jsonl`, which nothing has written since P9 deleted
`ShadowRunner`), `deadcode` and `deploy_skew` (P9's deletion authority and the check on its
corpus — both discharged, and both dependent on the `server-debug-deadcode` endpoint removed the
same day), `prune_shadow_log` and `soak_status` (shadow-log maintenance and soak-streak counting
for soaks the owner waived). The three `server-debug-*` diagnostics and the orphan
`debug-motherboard-nics` permission-map entry went with them. `reports/` keeps
`perf-baseline.json`, `archive/` and the signoff documents; its ~425 generated run artifacts are
gone. **None of these deletions reached production** — but not for the reason BACKLOG.md B-3 gives.
`watcher.autoDelete` is on and live (proven 2026-09-16), so a local delete does remove the
remote file. These particular files survived because they are under `scripts/`, `reports/` and
`tests/`, which the watcher never uploads in the first place. A deletion anywhere it *does*
upload will be followed in production, so renames must be additive.
