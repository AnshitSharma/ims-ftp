# Handoff — U-SM.1 — status_v2 columns — 2026-07-08

## What shipped
- `database/seeders/2026_07_10_001_add-status-v2-columns.sql` (+ paired rollback): adds
  `status_v2` to `server_configurations` and all ten `*inventory` tables, idempotent
  (`ADD COLUMN IF NOT EXISTS`), backfilled from legacy ints in the same seeder
  (config 0→draft, 1→validated (defensive), 2→building (defensive), 3→finalized;
  inventory 0→failed, 1→available, 2→installed). Columns are `NULL`-able during the
  dual-write window; U-SM.3 keeps them synced going forward.
- `scripts/verify/expected_schema.json` updated: `status_v2` added to
  `server_configurations`'s existing entry, plus a new minimal entry per inventory table.

**Not yet applied to production** — per CLAUDE.md, `.sql` files are not auto-deployed.
Human must run this seeder against production manually (see Verification below for the
exact commands already proven safe on scratch).

## Verification performed (this container, throwaway MariaDB — no production DB access here)
Installed `mariadb-server` locally (root), built a scratch DB `ims_scratch_sm1` with
`server_configurations` + all ten inventory tables (minimal columns: `Status`/
`configuration_status` + PK), seeded rows spanning every legacy status value.
1. **Apply** → exit 0. Mapping counts verified column-for-column: e.g.
   `server_configurations` configuration_status {0,1,2,3} → status_v2
   {draft,validated,building,finalized} counts matched 1:1 (2/1/1/2 rows respectively);
   same exact-match verified independently on all 10 inventory tables (Status 0/1/2 →
   failed/available/installed).
2. **Re-apply** (idempotency) → exit 0, no duplicate-column errors.
3. **Rollback** → exit 0; confirmed `status_v2` gone from both `server_configurations`
   and `cpuinventory` via `SHOW COLUMNS`.
4. **Re-apply after rollback** → exit 0; mapping counts identical to step 1.
5. Enum definitions confirmed byte-exact against the pack via `SHOW COLUMNS`:
   `server_configurations.status_v2` = `enum('draft','building','validating','validated','finalized','deployed','maintenance','retired')`;
   `cpuinventory.status_v2` = `enum('available','reserved','allocated','installed','active','maintenance','failed','retired')`.
6. `expected_schema.json` validated as parseable JSON; scripted check confirms
   `status_v2` present under `columns` for all 11 tables.
Scratch DB dropped and mariadbd left running only for this session's use; no residue.

## Characterization suite (INV-10) — not run, and not applicable
`tests/characterize_compatibility.php` requires the full `ims_compat_golden` DB seeded
from a real production dump, which isn't available in this sandbox. This is fine here:
**zero PHP files were touched** by this unit (`git status` shows only the new seeder +
its rollback + a JSON schema-expectations file) — no verdict-producing path changed, so
there is nothing for the characterization suite to re-pin. Confirm this at production
apply time with a plain `git diff --stat` before/after if you want the belt-and-braces
check.

## Invariant Check Results
| Invariant | Result |
|---|---|
| INV-9 (paired rollback) | PASS — mechanical check run, no seeder missing its rollback |
| INV-11 (unit box) | PASS — 3 files changed (1 new seeder, 1 new rollback, 1 edited JSON), ~89 LOC in the seeder pair, 1 seeder, 1 concept |
| INV-10 (pin before change) | N/A — no verdict-producing code touched (see above) |

## Human follow-up
Apply `database/seeders/2026_07_10_001_add-status-v2-columns.sql` to production
manually (not auto-deployed), then confirm with:
```sql
SELECT configuration_status, status_v2, COUNT(*) FROM server_configurations GROUP BY 1,2;
SELECT Status, status_v2, COUNT(*) FROM cpuinventory GROUP BY 1,2;
```
(repeat the second query per inventory table if you want full coverage).

## ⚠️ Unrelated finding surfaced while reading precedent for this unit
While locating the "multi-table ALTER precedent" this pack references
(`2026_06_15_001_add-faildate-column-to-all-inventory-tables.sql`), I found it — and 17
other previously-applied production seeders spanning 2026-06-11 through 2026-06-22 —
were **deleted from the repo** in commit `75a04bc` ("Migration", authored on `main`,
2026-07-05), which otherwise only added `phase-status.json` and a regression test. That
commit is already merged into `main` and into this branch's history. This looks like an
accidental deletion of real, already-applied migration history (violates the spirit of
"every DB change ships as a seeder" — the history should stay, even if superseded). I did
not restore them (out of scope for this unit and a decision with history/rollback-pairing
implications), but recovered their content via `git show 408cdc6:<path>` etc. if needed.
Flagging for a human call on whether to restore those 18 files in a small cleanup commit.

## Next Prompt To Use
U-SM.1 is `implemented`, not yet `verified` (verification here was on an ad hoc scratch
DB in this container, not the project's real golden/scratch harness against a production
dump — a human or a follow-up "verify" session should confirm against real data before
flipping to `verified`, same pattern as UB1-VERIFY / UB2-UB3-VERIFY). Once verified:
"Continue the IMS migration. Read migration/00-overview/README.md,
migration/ARCHITECTURAL_INVARIANTS.md, then execute unit U-SM.2 using
migration/03-state-machines/execution-packs/U-SM.2.md. ONE unit only; leave 'verified' to
a separate session." Also recall: U-B.4 (fleet backfill) is still pending on the human's
own timeline — P2's gate needs it before P3 can be considered fully unblocked per plan
order, though U-SM.1 itself didn't depend on rows existing.

## Files To Load Into Context (next session)
- migration/00-overview/README.md
- migration/ARCHITECTURAL_INVARIANTS.md
- migration/handoffs/U-SM.1-20260708.md
- migration/03-state-machines/execution-packs/U-SM.2.md

## Expected Context Size
~20k tokens
