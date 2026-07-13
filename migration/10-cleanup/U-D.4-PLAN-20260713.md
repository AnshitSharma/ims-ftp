# U-D.4 plan (planning only — no flags touched, no deletions)

Date: 2026-07-13. Scope: produce an implementation plan for U-D.4 (delete
the five migration flags + `TEMP-GUARD` blocks + the four legacy
authority-era env flags, per `10-cleanup/execution-packs/U-D.4.md`) by
reading that pack against the current state of the codebase. **Nothing in
this file was implemented or executed.** No flag was touched, no file
under `core/`, `api/`, or `database/` was created, modified, or deleted for
this unit.

## Gate status (unchanged by this session, last in strict order)

Same chain as U-D.2/U-D.3: P9 needs P8 open ≥14 days; P8 needs P7 open; P7
is `closed` today. The README's own strict ordering ("Order:
U-D.1→U-D.4 strictly") additionally means U-D.4 needs U-D.1, U-D.2, AND
U-D.3 (the point-of-no-return unit) to have landed first — U-D.4 is
therefore gated behind everything else in this phase, not just the P8/P9
gate chain. **U-D.4 cannot begin.** `phase-status.json`'s `U-D.4` entry
stays `not_started`.

U-D.4's own pack states its precondition plainly: "pins baseline: yes (all
flags at terminal values ⇒ deletion is identity)" — meaning this unit is
only safe once EVERY flag in `FLAGS.md` has already reached its terminal
value in production (`DUAL_WRITE_ENABLED=on`, `STATE_MACHINE_ENABLED=enforce`,
`ENGINE_MODE=enforce`, `COMMAND_LAYER_ENABLED=enforce`, `READ_FROM_ROWS=on`)
— none of which is true today; all five flags stay off/unset in production
per this migration's standing rule, unchanged by this session.

## Pack-vs-reality drift found this session

**`TEMP-GUARD` markers**: confirmed present in exactly 2 real production
files — `core/models/server/ServerBuilder.php` and
`core/models/state/StateGuard.php` (the other 14 grep hits are test files,
migration handoffs, and execution packs referencing the marker, not
instances of it needing deletion). This matches the pack's own framing
("search marker") — no drift, just confirmation of scope: U-D.4's own grep
should target exactly these 2 files' worth of blocks, not the whole 16-file
hit list.

**Legacy env flags** (`PCIE_LANE_CHECK_ENABLED`, `VALIDATION_PIPELINE_ENABLED`,
`SLOT_AUTHORITY_ENABLED`, `STORAGE_CONNECTION_AUTHORITY_ENABLED`): all four
confirmed still referenced, across 7 files in `core/`
(`ServerBuilder.php`, `PcieLaneBudgetRule.php`, `ValidationPipeline.php`,
`MemoryAuthority.php`, `StorageConnectionAuthority.php`,
`SlotAuthority.php`, `PcieLaneBudgetValidator.php`) — consistent with the
pack's own note that "their consumer classes died in U-D.2" (i.e., these
references are expected to already be GONE by the time U-D.4 actually
runs, because U-D.2 deletes the classes that read them — `MemoryAuthority`,
`SlotAuthority`, `StorageConnectionAuthority`, `ValidationPipeline`,
`PcieLaneBudgetValidator` are ALL on U-D.2's own deletion list). **This is
not new drift** — it's exactly the state the pack predicts for "before
U-D.2 has run," which is where the codebase still is today (U-D.2 is
itself only a plan, per this same session's other new document). The
implementing session should re-run this same grep AFTER U-D.2 actually
lands and expect near-zero hits (the pack calls this unit's job "greps
residue" — i.e., mopping up whatever U-D.2 didn't already remove, not doing
the primary removal itself).

**`FLAGS.md`**: re-read in full this session — still lists exactly the same
5 migration flags this pack targets (`DUAL_WRITE_ENABLED`,
`STATE_MACHINE_ENABLED`, `ENGINE_MODE`, `COMMAND_LAYER_ENABLED`,
`READ_FROM_ROWS`), all still showing `Deleted in: U-D.4` in its own table —
no drift, this document already correctly points at this unit as the one
responsible for deleting them.

**Legacy int status columns** (`configuration_status` on
`server_configurations`, `Status` on every inventory table): pack
explicitly says these are RETAINED here, demoted to generated columns in a
FOLLOW-UP seeder tracked in the `12-post-cutover` backlog, NOT this unit —
re-confirmed this session that `12-post-cutover/` exists as a folder
(`P10`, `U-P.1`/`U-P.2`, both `not_started`) so the backlog destination the
pack points to is real, not a dangling reference. No action taken on it
here, matching the pack's own explicit scope exclusion.

## Revised scope for implementation (once P9 opens AND U-D.1/U-D.2/U-D.3 have all landed)

Unchanged from the pack's own text:

- Convert the five flag readers to hard-coded constants at their terminal
  values (`DUAL_WRITE_ENABLED` reader disappears entirely — U-D.3 already
  deleted `ConfigComponentWriter`, the dual-write mirror, by this point),
  then inline the now-constant values away.
- Delete the 2 confirmed `TEMP-GUARD` blocks (`ServerBuilder.php`,
  `StateGuard.php`).
- Grep-and-clean the 4 legacy authority-era flags' residue (expected to be
  near-empty by this point, per U-D.2 having already deleted their
  consumer classes — re-verify at implementation time, don't assume zero
  without checking).
- Do NOT touch `configuration_status`/inventory `Status` columns — out of
  scope, tracked separately in `12-post-cutover`.
- **Leave `phase-status.json`'s `U-D.4` entry `not_started`** until actual
  deletion lands.

## Tests required before this unit can be marked `implemented`

Unchanged from the pack: grep per `FLAGS.md`'s table returns only
`FLAGS.md`'s own history (i.e., the table itself, now marked deleted, not
live code); grep `TEMP-GUARD` returns empty; `tests/characterize_compatibility.php`
ZERO diffs; full battery GREEN. None of this was run this session — no
code changed to prove against, and U-D.1/U-D.2/U-D.3 haven't landed yet
for this unit to even meaningfully follow.

## Explicit non-actions this session

No flag touched (all 5 stay off/unset in production, unchanged). No
`TEMP-GUARD` block removed. No file under `core/`, `api/`, or `database/`
created, modified, or deleted for U-D.4. `phase-status.json`'s P9/`U-D.4`
entry is NOT changed by this session (stays `not_started`). This document
is the only artifact.
