# Server Config Constraint State — Rollout Tracking

Goal: eliminate preview/insert compatibility drift by accumulating constraints on a single object per server configuration.

Full design: `../.claude/plans/server-config-constraint-state.md`

## Phase 0 — Scaffolding (DONE, production-safe)

Nothing in the live request path is changed. Safe to deploy immediately.

- [x] SQL migration `database/migrations/2026-04-21_constraint_state.sql`
- [x] `core/models/server/ConstraintDecision.php`
- [x] `core/models/server/ServerConfigConstraintState.php`
- [x] `core/models/server/ConstraintStateRepository.php`

## Phase 1 — Shadow reads (DONE, dormant — activates only when env flag is set)

- [x] `core/models/compatibility/ConstraintStateCompatibilityAdapter.php`
      (evaluateCandidate, logDualRun, applyAfterLegacy, removeAfterLegacy, invalidate)
- [x] `ServerBuilder::getCompatibleComponents()` — shadow-read hook after the legacy
      verdict. Logs `action='preview'` rows to `compatibility_dualrun_log` when the
      env flag is on. Legacy verdict still wins unless read cutover is flipped.
- [x] `require_once` for the adapter added to ServerBuilder top.

**Behaviour when flags are off (default): identical to pre-Phase-1. No DB writes,
no adapter instantiation, no latency cost.**

- [ ] **Observation step (user action):** after deploy, set `COMPATIBILITY_DUALRUN_LOG=true`
      in `.env`, wait 3–5 days, then query:
      ```sql
      SELECT component_type,
             COUNT(*)                          AS total,
             SUM(CASE WHEN matched=0 THEN 1 END) AS mismatches
        FROM compatibility_dualrun_log
       WHERE action='preview'
       GROUP BY component_type;
      ```
      Target: 0 mismatches across all types before proceeding.

## Phase 2 — Shadow writes (DONE, dormant)

- [x] `ServerBuilder::addComponent()` — precheck dual-run around `validateComponentCompatibility`.
      Logs `action='add_precheck'` rows. Also post-commit write-through: once legacy
      persists successfully, the adapter's `applyAfterLegacy()` brings the blob up to date.
      If the caller owns the outer transaction, we instead invalidate (next read rebuilds).
- [x] `ServerBuilder::removeComponent()` — post-commit mirror via `removeAfterLegacy()`.

- [ ] **Observation step:** enable `COMPATIBILITY_DUALRUN_WRITE=true`, wait 3–5 days,
      then query:
      ```sql
      SELECT action, component_type, COUNT(*) AS mismatches
        FROM compatibility_dualrun_log
       WHERE matched=0 AND action IN ('preview','add_precheck')
       GROUP BY action, component_type;
      ```
      Target: 0 mismatches. Also spot-check 20 random configs:
      ```sql
      -- Compare rebuildFromLineItems output to current stored blob
      SELECT config_uuid, LENGTH(constraint_state), constraint_state_updated_at
        FROM server_configurations
       WHERE constraint_state IS NOT NULL
       ORDER BY RAND() LIMIT 20;
      ```

## Phase 3 — Read cutover (CODE IN PLACE, awaits env flip)

Flip `COMPATIBILITY_READS=constraint_state` to route preview verdicts through
the adapter. The shadow log keeps running alongside so you can flip back at any
time by unsetting the flag. No redeploy required.

When set, `getCompatibleComponents()` replaces each candidate's legacy verdict
with the adapter's verdict (and replaces `compatibility_reason` with the
adapter's reasons). Latency on configs with many components drops materially
(N JSON reads per candidate → one blob hydrate per request).

- [ ] Flip flag on a single test config first, observe UI.
- [ ] Flip on production. Monitor `error_log` for `Shadow-read hook failed` entries.

## Phase 4 — Write cutover (CODE IN PLACE, awaits env flip)

Flip `COMPATIBILITY_WRITES=constraint_state`. In `addComponent()`, if the adapter
denies an add, the request is rejected BEFORE the legacy path runs its
side-effects (JSON update, status flip, slot assignment). Legacy still executes
on the approve branch to perform persistence. After Phases 1–2 show 0
mismatches, the adapter-deny path will only fire for cases legacy would also
reject — so UX is unchanged.

- [ ] Flip flag. Monitor ticket queue for add failures with
      `constraint_state_issues` populated in the error response.

## Phase 5 — Cleanup (separate PR, 2+ weeks after Phase 4)

- [ ] Delete the legacy branches in `getCompatibleComponents()` /
      `validateComponentCompatibility()` once the constraint-state path is the
      sole authority.
- [ ] Remove the shadow logging writes (keep the table until disk reclaim).
- [ ] `DROP TABLE compatibility_dualrun_log`.
- [ ] Remove dead helpers: `analyzeExistingCPUForRAM`,
      `analyzeExistingMotherboardForRAM`, `analyzeExistingPCIeCardForPCIe`.

## Env flags cheat-sheet

| Flag                          | Default | Effect                                                          |
|-------------------------------|---------|-----------------------------------------------------------------|
| `COMPATIBILITY_DUALRUN_LOG`   | off     | Log preview + add_precheck comparisons to the audit table       |
| `COMPATIBILITY_DUALRUN_WRITE` | off     | Write-through to `constraint_state` on every add/remove commit  |
| `COMPATIBILITY_READS`         | legacy  | `constraint_state` ⇒ preview uses adapter verdict                |
| `COMPATIBILITY_WRITES`        | legacy  | `constraint_state` ⇒ addComponent rejected if adapter denies     |

## Rollback

Every flag flip is reversible by clearing the env var and refreshing PHP-FPM.
Phase 0 rollback SQL is in a comment at the bottom of the migration file.

## Safety invariants baked in

1. Adapter methods never throw out of their callers — every `Throwable` is caught
   and either logged or results in blob invalidation (triggering rebuild on next read).
2. Shadow hooks are gated by env flags; when off, zero adapter code runs on the
   hot path — not even the class load (static flag check uses class autoload).
3. When shadow-write fails post-commit, the row's `constraint_state` is set to
   NULL so the next read rebuilds from the authoritative line-item columns.
4. Write cutover gate runs AFTER the legacy precheck has computed its verdict,
   so the shadow log captures both verdicts on the same request.
