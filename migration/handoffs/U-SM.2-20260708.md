# Handoff — U-SM.2 — Transition tables + seed rows — 2026-07-08

## What shipped
- `database/seeders/2026_07_10_002_create-status-transitions.sql` (+ paired rollback):
  creates `config_status_transitions` (from_status, to_status, required_permission,
  requires_validation; PK on from/to) seeded with 12 rows, and
  `inventory_status_transitions` (from_status, to_status; PK on from/to) seeded with 17
  rows. Both idempotent (`CREATE TABLE IF NOT EXISTS` + `INSERT IGNORE`).
- `scripts/verify/expected_schema.json` extended with both new tables.
- Nothing reads these tables yet — pure data, no enforcement wired (that's U-SM.3/U-SM.4).

**Not yet applied to production** — human must run this seeder manually.

## Design decisions worth a human glance
- Gave `from_status`/`to_status` the same ENUM literal list as the corresponding
  `status_v2` column from U-SM.1, rather than a generic VARCHAR — keeps the state graph
  self-validating (MySQL rejects an insert with a state that doesn't exist in the
  lifecycle) at the cost of needing an `ALTER...MODIFY ENUM` if a state is ever added.
  The pack didn't specify a type for these two columns, only for
  `required_permission`/`requires_validation`, so this was a judgment call — flag if you
  wanted VARCHAR instead.
- `inventory_status_transitions` carries no permission/validation columns, per pack (only
  `config_status_transitions` does) — inventory-level lifecycle enforcement is expected to
  live in whatever command moves the physical row (add/remove/replace), not gated by ACL
  permission per transition the way config transitions are.
- `SYSTEM` is a literal sentinel value in `required_permission` (not a real ACL
  permission) for the two `validating→*` rows — those are system/engine-triggered
  transitions, never a direct user action.
- Included exactly the inventory transitions enumerated in the pack (17 rows) and no
  more — e.g. no `failed→available`, no other unlisted edges — per the "never guess"
  principle already established for the extractor work in P2.

## Verification performed (this container, throwaway MariaDB — no production DB access here)
Same scratch-DB pattern as U-SM.1 (`ims_scratch_sm2`, dropped after use):
1. **Apply** → exit 0. `config_status_transitions` = 12 rows, `inventory_status_transitions`
   = 17 rows (matches pack counts exactly).
2. `failed→available` absent — confirmed empty result set (checklist item 1, PASS).
3. `validated→finalized` row confirmed `required_permission='server.finalize'`,
   `requires_validation='full'` (checklist item 2, PASS).
4. Every one of the 8 config-lifecycle enum values and every one of the 8
   inventory-lifecycle enum values appears in at least one row (from OR to) — confirmed
   via anti-join query, both empty (per pack's test requirement "every enum value appears
   in ≥1 row").
5. **Re-apply** (idempotency) → exit 0, counts unchanged (12/17, no duplicates from
   `INSERT IGNORE` against the from/to primary key).
6. **Rollback** → exit 0; both tables gone (`SHOW TABLES LIKE '%status_transitions%'` empty).
7. **Re-apply after rollback** → exit 0; counts back to 12/17.
8. `expected_schema.json` re-validated as parseable JSON.

## Checklist item 3 (permission vocabulary)
Per the pack: "read api/permission_map.php ONLY if a name seems off; else trust list." I
did check it (already loaded from the prior U-SM.1 session's context) — existing entries
follow `{module}.{action}` dot notation (`server.edit`, `server.create`, `server.view`,
etc.). The pack's new strings (`server.finalize`, `server.deploy`, `server.unfinalize`,
`server.maintain`, `server.retire`) follow the identical pattern. Note: `finalize-config`
currently maps to `server.create` in permission_map.php today, not a dedicated
`server.finalize` — this transition table anticipates a *future* dedicated permission
that doesn't exist in the ACL `permissions` table yet. That's expected and out of scope
here (these are inert data rows; wiring real permission checks against them is a later
unit, likely alongside U-SM.4 or the command layer). Flagging so nobody is surprised when
`server.finalize` doesn't resolve to anything yet if queried against production ACL data.

## Invariant Check Results
| Invariant | Result |
|---|---|
| INV-9 (paired rollback) | PASS — mechanical check run, no seeder missing its rollback |
| INV-11 (unit box) | PASS — 4 files changed (1 new seeder, 1 new rollback, 1 edited JSON, 1 edited phase-status.json), ~79 LOC in the seeder pair, 1 seeder, 1 concept |
| INV-10 (pin before change) | N/A — no PHP files touched |

## Next Prompt To Use
U-SM.2 is `implemented`, not yet `verified` (same caveat as U-SM.1 — verification was on
an ad hoc scratch DB in this container, not the project's golden/production-mirrored
harness). Once verified and the seeder applied to production:
"Continue the IMS migration. Read migration/00-overview/README.md,
migration/ARCHITECTURAL_INVARIANTS.md, then execute unit U-SM.3 using
migration/03-state-machines/execution-packs/U-SM.3.md. ONE unit only; leave 'verified' to
a separate session." U-SM.3 builds the actual StateMachine service + legacy-mapping
dual-write on top of these tables — expect it to touch real PHP code (unlike U-SM.1/U-SM.2
which were schema-only), so INV-10 characterization-suite obligations may become
relevant again depending on what it touches.

## Files To Load Into Context (next session)
- migration/00-overview/README.md
- migration/ARCHITECTURAL_INVARIANTS.md
- migration/handoffs/U-SM.2-20260708.md
- migration/03-state-machines/execution-packs/U-SM.3.md

## Expected Context Size
~20k tokens
