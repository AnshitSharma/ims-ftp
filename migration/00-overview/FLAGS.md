# FLAGS.md — all nine flags are GONE (U-D.4, 2026-08-30)

**There are no migration flags in this codebase any more.** INV-12 is satisfied by the
strongest possible means: the things it constrained do not exist. `grep -rn "getenv(" api/ core/`
returns only infrastructure config (DB, JWT, mail, `IMS_DATA_PATH`) — nothing behavioural.

Do not reintroduce one to make a change "safe to roll back". Rollback for this codebase is a
git revert of a single-purpose commit, not a runtime branch that doubles every path's state
space. That was the whole cost this migration paid to get here.

## What was deleted, and what its terminal value became

| Flag | Terminal value | Consumed by | What replaced the branch |
|---|---|---|---|
| `DUAL_WRITE_ENABLED` | `on` | `ConfigComponentWriter` | Writes are unconditional. `config_components` is not a mirror any more — it is the store. |
| `STATE_MACHINE_ENABLED` | `enforce` | `StateGuard` | `checkMutation()` is authoritative. `TEMP-GUARD(U-0.2)` deleted with the `off`/`shadow` branch it lived in. |
| `ENGINE_MODE` | `enforce` | `ValidationEngine` | The rule registry is the sole validation authority. |
| `COMMAND_LAYER_ENABLED` | `enforce` | `server_api` dispatch | The commands ARE the write path. The `CommandLayer` class is gone. |
| `READ_FROM_ROWS` | `on` | `ConfigReadRouter` | Rows answer every read. Legacy JSON extraction survives only for a config row with no uuid. |
| `PCIE_LANE_CHECK_ENABLED` | `warn` | `PcieLaneBudgetValidator` | `PcieLaneBudgetRule` owns add-time lane budgeting. |
| `VALIDATION_PIPELINE_ENABLED` | `off` | `ValidationPipeline` | File deleted. |
| `SLOT_AUTHORITY_ENABLED` | `off` | `SlotAuthority` | File deleted. |
| `STORAGE_CONNECTION_AUTHORITY_ENABLED` | `off` | `StorageConnectionAuthority` | File deleted. |

The three authority flags never left `off`. Their classes were built for a rollout that the
ValidationEngine overtook, so the flags were deleted along with the code they gated rather
than being flipped first — there was nothing to flip them *to*.

## The `.env` entries

The server's `.env` may still carry these keys. They are inert: nothing reads them. Removing
them is tidy-up, not a deploy step, and can happen whenever convenient.

## Historical note — the read pattern

Every flag used the same reader: `getenv` → `$_ENV` fallback → default → whitelist, one static
method on the class that consumed it. `PcieLaneBudgetValidator::currentMode()` was the
canonical example this file used to cite. It is deleted too; the pattern is recorded here only
so the shape is legible when reading the migration packs, which still describe it in the
present tense.
