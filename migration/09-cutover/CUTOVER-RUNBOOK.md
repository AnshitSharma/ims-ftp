# Cutover runbook — flip-and-verify (compressed-soak variant)

Date written: 2026-07-22. Author: migration working branch.

This is the **mechanical execution sequence** for turning the new engine on in
production. It exists so that whoever performs the cutover does it in a fixed,
verifiable order with an instant escape hatch at every step — **not** as
authorization to skip the soaks. Read this together with:

- `migration/00-overview/FLAGS.md` — flag semantics + the `off → shadow → enforce` law.
- `migration/rollback-playbook.md` — R-FLAG is the primary rollback (minutes, lossless).
- `migration/09-cutover/README.md` — P8 read-path cutover (READ_FROM_ROWS).

## The two ways to run this

| Variant | What you accept | When it's OK |
|---|---|---|
| **A — Full soak (recommended)** | Each flag sits in `shadow` for the phase README's soak window (P3: 7 days; P8: 14 days at `=on`) before `enforce`/`on`. Real production traffic accumulates the comparison evidence. | Default. Lowest risk. |
| **B — Compressed soak (accept risk)** | You replace *soak-over-time* with *verify-at-the-moment*: flip to `shadow`, generate/replay traffic, run the report battery, and if green promote to `enforce` the same session. You lose the diversity of a week of real traffic — that is the risk being taken. | Only with explicit sign-off from whoever owns the risk. Never skip the `shadow` step or the reports. |

**Both variants use the identical step sequence below. Variant B just shortens
the wait between "flip to shadow" and "flip to enforce" — it never deletes a step.**

## Preconditions (must all be true before step 1)

- [ ] `DUAL_WRITE_ENABLED=on` and confirmed continuously on (rows are fresh). If it
      was ever turned off, re-run `scripts/backfill/backfill.php --resume` first.
- [ ] P2 gate reports green **against a production dump taken _after_ seeder
      `2026_07_22_004`** — `php scripts/verify/run_all.php --gate P2` exit 0.
- [ ] Seeder `2026_07_21_002` (component-NIC parent_id alignment, F-5) applied to
      production. Verify: the coverage query at the bottom of that seeder returns
      zero board-hosted rows with a NULL parent in a config that has a motherboard.
- [ ] F-5 code divergence closed (this branch: `Extractor.php` now parents
      component NICs to the motherboard, matching the live path). If you re-run the
      backfill, it will no longer re-introduce the divergence.
- [ ] A logical backup of `server_configurations` taken today and retained.

## The flip sequence (forward order = reverse of the rollback order)

`DUAL_WRITE` (already on) → **STATE_MACHINE → ENGINE_MODE → COMMAND_LAYER → READ_FROM_ROWS**.
Do them **one at a time**, fully (`off → shadow → enforce/on`), never two in flight at once.

For **each** flag, run this identical gate:

1. **Flip to `shadow`** in the production `.env` (VS Code SFTP save — the only write
   method proven to stick on this host; curl/FTP APPE reverts within 1–2 min).
   Confirm the live process actually reads it: `action=server-debug-migration-flags`.
2. **Generate signal.** Variant A: let real traffic run for the soak window. Variant
   B: exercise the affected paths now (add/remove/finalize on a throwaway config) or
   replay via the scratch tools, so the shadow log has real comparisons.
3. **Run the battery** and read it:
   ```
   php scripts/verify/run_all.php --gate P2      # equivalence, orphan, ledger, inventory
   php scripts/verify/parity_report.php --since <today>   # rule-level engine-vs-legacy
   ```
   - **All green → promote:** flip the same flag to `enforce` (or `on` for
     READ_FROM_ROWS), then **re-run the battery immediately**.
   - **Any red → STOP.** Flip the flag back to `off` (instant, lossless — R-FLAG).
     Diagnose with `equivalence_report.php --config <uuid>` / read the parity diff.
     Fix in a unit on the branch, redeploy, restart at step 1 for this flag.
4. Only after this flag is green in `enforce` do you move to the next flag.

Per-flag specifics:

- **STATE_MACHINE_ENABLED** (`StateGuard`): P3's soak is **7 days** in variant A.
  The clock has **not started** — this flag still reads `off` in production. The one
  uploaded `stateguard.jsonl` sample was a *divergence* (new allowed, legacy blocked
  on a `maintenance`/status-3 config); confirm that class of case is understood
  before promoting.
- **ENGINE_MODE** (`ValidationEngine`): the shadow logs from 2026-07-11/12/13 are on
  a dev replay, are 9+ days stale, predate the U-R.9 / socket / memory fixes, and
  showed ~45% divergence including 6 cases where the **new engine allowed a build the
  legacy engine blocked** (config `9dbc63fa`). Do **not** treat those logs as soak
  evidence — regenerate shadow comparisons on current code before promoting.
- **COMMAND_LAYER_ENABLED** (`server_api` dispatch): cannot start until STATE_MACHINE
  is at least `shadow` and stable (sequential, per the migration plan). Enforce also
  activates cascade removal — the exact path F-5 hardens.
- **READ_FROM_ROWS** (`ConfigReadRouter`, P8): `off → sample → on`. `sample` compares
  read shapes per-request in production; hold at `on` for 14 days of green battery
  before any P9 cleanup.

## Rollback (memorize this)

**Your primary rollback is not the database backup — it is `FLAG=off`.** Per
`FLAGS.md`, `off` = legacy path runs, byte-identical. It is instant and loses nothing.
The backup is only for the case where `enforce` already wrote damage before you caught
it — which is exactly why the battery runs *immediately* after every promotion, not a
week later. If you turn `DUAL_WRITE_ENABLED` off during a rollback, you must re-run the
backfill before re-enabling anything above it (row freshness pauses).

## What must NOT happen yet

Cleanup (P9 / `U-D.1`–`U-D.4`) **deletes the legacy engine and the flags themselves.**
Production runs entirely on the legacy path until READ_FROM_ROWS has held at `on`.
Running cleanup before then is not "finishing the migration" — it removes the code
production is actively serving from and takes the site down. Cleanup is the last phase,
gated on the P8 soak, never a shortcut.
