# U-D.3 plan (planning only — NO schema changes, NO backups taken, NO deletions)

Date: 2026-07-13. Scope: produce an implementation plan for U-D.3 (drop the
legacy JSON columns from `server_configurations` — the pack's own title:
"POINT OF NO RETURN" — per `10-cleanup/execution-packs/U-D.3.md`) by reading
that pack against the current schema and `ServerBuilder.php`. **Nothing in
this file was implemented or executed.** No seeder was written (there is
nothing yet to seed — see "why no seeder" below), no backup was taken, no
file under `core/`, `api/`, or `database/` was created, modified, or
deleted for this unit.

## Gate status (by far the most unmet of any unit in this migration)

U-D.3's own PRECONDITIONS block (pack lines 3-7) is unusually explicit and
stacks on TOP of the ordinary P8→P9 gate chain every other 10-cleanup unit
needs:

1. **P8 signoff ≥30 days old.** P8 hasn't happened once yet — `U-X.1`/
   `U-X.2` are both `not_started`, confirmed unchanged this session (see
   the U-X.1 plan's own 2026-07-13 re-verification, same session). A
   "signoff 30 days old" is meaningless until there is a signoff at all.
2. **`equivalence --all` GREEN daily for the last 30 days**, per a cron the
   pack says U-X.2 installs (per `PLAN_VERIFICATION_REVIEW` finding F-4,
   quoted in the pack itself). That cron doesn't exist yet — U-X.2 hasn't
   run.
3. **A verified logical backup of `server_configurations`, same-day,
   retention 90 days, restore-tested on scratch.** Not attempted this
   session — see "why no backup was taken" below.

**U-D.3 is, structurally, the single furthest-out unit in this entire
migration's remaining scope.** Every other planning document this
migration has produced (U-X.1, U-D.1, U-D.2, this one) documents "P9 needs
P8 open ≥14 days" as the blocker; U-D.3 needs P8 CLOSED-AND-SIGNED-OFF for
30 days on top of that, plus a cron that itself only starts existing once
U-X.2 (not yet started) ships it, plus U-D.1/U-D.2 already landing first
(strict order per the README: "Order: U-D.1→U-D.4 strictly"). There is no
scenario in which U-D.3 is next after this session, or the one after that.

## Why no backup was taken this session

The pack's own checklist explicitly requires the backup be "restore-tested
on scratch" — that part IS something a DB-capable session could technically
attempt in isolation, ahead of the gate, the same way U-X.1's and U-D.1's
plans were prepared ahead of their own gates opening. This session
deliberately did NOT attempt it, for a concrete reason distinct from "the
gate is closed": **a backup taken now, 30+ days before P8 even opens once,
would be stale and useless by the time U-D.3 can legally run** — the
pack's own precondition is a SAME-DAY backup, not an early one. Practicing
the restore procedure in isolation (on scratch, against today's schema)
would still be a reasonable, low-risk dry run for a FUTURE session to do
once P8 is closer — but it produces no artifact worth keeping today, so
this session did not spend the DB-capable window on it. Flagging this as a
recommendation for whichever session is active when P8 signoff is ~20-25
days old: dry-run the restore procedure THEN, not now, so the muscle memory
exists but the artifact stays fresh.

## Why no seeder was written this session

Every other DB-affecting plan/unit in this migration (RV-4, Finding 2, the
ghost-config cleanup) produced a seeder file even when not run, per this
repo's own hard rule ("every DB change ships as a NEW seeder... shown to
the user"). This unit deliberately does NOT get one yet: the pack names the
target file `2026_08_XX_001_drop-legacy-json-columns.sql` with a literal
placeholder date, because writing the real seeder now would require
re-deriving the exact current column list and any schema drift between now
and whenever U-D.3 actually runs — the same class of staleness risk as the
backup above. **Pack-vs-reality check performed instead** (see below):
confirming today that every column the pack names still exists, so a
future session doesn't have to wonder whether the pack's column list itself
has gone stale, even though the seeder text itself isn't worth writing yet.

## Pack-vs-reality drift found this session

**Schema-level check (not line-number drift — column existence, which is
what actually matters for a `DROP COLUMN` seeder)**: every column the pack
names was re-confirmed present in `server_configurations` this session,
reusing DESCRIBE output already gathered earlier in this session's own
scratch-DB work (ghost-config cleanup, RV-4 fixture-building): `cpu_configuration`,
`ram_configuration`, `storage_configuration`, `caddy_configuration`,
`nic_config`, `hbacard_config`, `pciecard_configurations`,
`sfp_configuration`, `motherboard_uuid`, `chassis_uuid` — all confirmed
present. `hbacard_uuid` (also named in the pack's DROP list) was NOT
independently re-confirmed this session via a fresh DESCRIBE (only found a
comment-level reference to the name elsewhere in the repo, not a schema
check) — the implementing session should confirm it directly before
writing the real seeder, rather than trusting this plan's incomplete check.

**One clarification already resolved IN the pack itself, restated here so
a skimming future session doesn't miss it**: the pack's own text asks and
answers its own question about `motherboard_uuid`/`chassis_uuid` —
"RETAINED as denormalized fast-path? NO — dropped too; reads come from
rows." This is not new drift, just flagging that the pack already
resolved this ambiguity in its own text; no further owner decision needed
on that specific point.

**`ConfigComponentWriter`, `equivalence_report.php` still present and
still doing what the pack describes**: `ConfigComponentWriter` (the
dual-write mirror this unit deletes) confirmed still at
`core/models/config/ConfigComponentWriter.php`, unchanged. `equivalence_report.php`
confirmed still at `scripts/verify/equivalence_report.php`, still comparing
rows-path vs JSON-fallback (the comparison this unit retires, per the
pack's own "retire JSON side → becomes rows-vs-inventory consistency
check" note) — no drift, just confirmation both files are where the pack
expects.

## Revised scope for implementation (once ALL of §"Gate status" clears — likely months out)

Unchanged from the pack's own text:

- **Sub-session split stands** (pack's own SPLIT MANDATE, PD-3): U-D.3a
  (seeder + backup + writer deletion), U-D.3b (reader deletion + router
  simplification).
- Seeder `2026_XX_XX_NNN_drop-legacy-json-columns.sql` (real date, written
  fresh at implementation time — not reused from this plan) drops the
  confirmed column list above (re-confirm `hbacard_uuid` first).
- Delete `ServerBuilder`'s `update{Cpu,Ram,Storage,Nic,Caddy,Sfp,
  PcieCard,HbaCard}Configuration` family + `updateServerConfigurationTable`
  + `extractComponentsFromJson` (the router goes to `on` everywhere by
  this point).
- Rollback is a PROCEDURE document (backup restore + `config_events`
  replay), not a reverse seeder — per the pack, this is the one unit in
  this migration allowed to satisfy INV-9's pairing that way.

## Tests required before this unit can be marked `implemented`

Unchanged from the pack: full battery GREEN, every regression suite PASS,
`grep` for every dropped column name across the whole repo returns empty.
None of this was run this session — there is no code change yet to run it
against, and running the full battery today would prove nothing durable
against preconditions that are months away from clearing.

## Explicit non-actions this session

No flag touched. No seeder written (see above — deliberate, not an
oversight). No backup taken (see above — deliberate). No file under
`core/`, `api/`, or `database/` created, modified, or deleted for U-D.3.
`phase-status.json`'s P9/`U-D.3` entry is NOT changed by this session
(stays `not_started`). This document is the only artifact.
