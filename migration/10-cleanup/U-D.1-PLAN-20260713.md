# U-D.1 plan (planning only — no deletions taken)

Date: 2026-07-13. Scope: produce an implementation plan for U-D.1 (delete the
`ServerBuilder::validateComponentCompatibility` Phase 1.5 pairwise loop + its
call site, iff `ComponentCompatibility::checkComponentPairCompatibility` has
no other live callers, iff the U-C.2/U-C.3 shadow-dispatch branches are dead)
by reading `10-cleanup/README.md` + `execution-packs/U-D.1.md` against the
CURRENT state of `ServerBuilder.php` and the `ENGINE_MODE` dispatch site.
**Nothing in this file was implemented or executed.** No file under `core/`
was created, modified, or deleted for this unit; no deadcode report was run
(P9 is not open, so there is nothing yet to prove dead against a moving
target).

## Gate status (unchanged by this session)

P9's own stated prerequisite is "P8 gate open ≥14 days" (`10-cleanup/README.md:5`).
Per `phase-status.json`, **P8 is `closed`** (`U-X.1`/`U-X.2` both `not_started`
— confirmed still true this session, matching the 2026-07-12 `U-X.1` plan's
finding). P8 itself needs P7 open, which needs P2/P3's soak/backfill
preconditions to clear first — **the exact same upstream gate chain the
U-X.1 plan already documented, one link further downstream.** U-D.1 cannot
begin — let alone U-D.2 through U-D.4, and U-D.3 is explicitly the "point of
no return" per the pack — until P8 opens and then holds for 14 days. This is
a human/time gate, not a code gate; nothing below changes that. This plan is
prepared ahead of time, same rationale and standing authorization as the
U-X.1 plan, so implementation can start the session P9 actually opens without
re-deriving this analysis from scratch.

`phase-status.json`'s P9 unit block is unchanged by this session: `U-D.1`
through `U-D.4` all remain `not_started`.

## Pack-vs-reality drift found this session (flag before anyone implements from the pack)

1. **File path is stale.** The pack's "Inputs" section cites
   `ServerBuilder::validateComponentCompatibility`. The class lives at
   `core/models/server/ServerBuilder.php` (not `core/models/ServerBuilder.php`,
   the path pattern several other packs/handoffs use loosely) — confirmed at
   source. Anyone grepping the wrong directory will conclude the method is
   missing.

2. **Line references are stale**, same failure mode the U-X.1 plan already
   flagged for this file (`getConfigurationDetails`/`getConfigComponents`,
   +99/+256 drift): the pack cites `validateComponentCompatibility` at
   **4631**; it is actually at **4887** (+256 drift — the same +256 offset
   found for `getConfigComponents` in the U-X.1 plan, consistent with a
   single earlier insertion upstream of both, most likely U-R.9's onboard-NIC
   work landing between them). The pack cites the call site at **~515**; it
   is actually at **534** (+19 drift, smaller because U-R.9's insertion sits
   below this call site). Re-derive both at implementation time — do not
   trust the pack's citations.

3. **`checkComponentPairCompatibility` confirmed to have other live callers —
   the pack's own contingency applies, it STAYS.** Grepped every call site
   fleet-wide:
   - `api/handlers/server/compatibility_api.php` — **6** call sites, including
     the `check_pair` action's core logic (`compatibility` module actions use
     underscores, per this repo's own convention) and the bulk-pair-check
     path. Confirmed genuinely reachable in production today (not
     flag-gated).
   - `core/models/server/ServerBuilder.php:1476` — a **second, independent**
     pairwise-compatibility loop inside a different method (the
     get-compatible/available-components flow), textually similar to the
     Phase 1.5 loop at :4976 but a distinct call site in a distinct function.
     This one is **not** part of U-D.1's target and must not be touched by
     it.
   - `core/models/tickets/TicketValidator.php:329` — still calls it directly.
   - `core/models/compatibility/ComponentCompatibility.php:1082` — an
     internal caller (batch/pairwise helper within the same class).
   Only the Phase 1.5 loop's own call to `checkComponentPairCompatibility`
   (`ServerBuilder.php:4976`, inside `validateComponentCompatibility` itself)
   goes away when the enclosing method is deleted; the method
   `checkComponentPairCompatibility` itself is not touched by U-D.1, matching
   the pack's own written contingency exactly. No drift here, just
   confirmation.

4. **The U-C.2/U-C.3 shadow-dispatch blocks are NOT dead code yet — the
   pack's stated precondition ("enforce is permanent now") does not hold in
   production today.** The pack's Inputs line lists "the U-C.2/U-C.3 shadow
   dispatch blocks (enforce is permanent now)" as a deletion target alongside
   the Phase 1.5 loop. Read at source
   (`ServerBuilder.php:4178-4238`, the `ENGINE_MODE` hook around
   `validateComponentAddition`): the dispatch still has **three live
   branches** — `off` (passthrough to `legacyValidateComponentAddition()`),
   `shadow` (record + return legacy), `enforce` (record + return engine) —
   and `ValidationEngine::mode()` reads `ENGINE_MODE` from the environment at
   call time, unconditionally, all three branches reachable depending on the
   flag. Per `phase-status.json`, **`U-C.6` (the enforce soak) is `blocked`**,
   not `verified` — i.e. `ENGINE_MODE=enforce` has not yet run as a soaked,
   signed-off production state. Production today runs `ENGINE_MODE=off`
   (unset ⇒ off, per this migration's standing invariant). Deleting the
   `off`/`shadow` branches now, on the pack's stated premise, would be
   deleting the ONLY path production actually executes — the opposite of
   dead-code removal. **Recommendation for the implementer: do not touch the
   `ENGINE_MODE` dispatch block as part of U-D.1 until `U-C.6` reads
   `verified` in `phase-status.json` and the pack's premise is re-checked
   against that status at implementation time.** This is a precondition gap
   in the pack, not a line-number typo — flag it to the owner rather than
   implementing past it.

## Revised scope for implementation (once P9 opens AND U-C.6 is verified)

- **Confirm zero other callers** of `ServerBuilder::validateComponentCompatibility`
  itself (private method — only the one call site at :534 found this session;
  re-grep at implementation time since this file's line numbers keep moving)
  via `scripts/verify/deadcode_report.php` per the pack's own contract
  (11-verification §deadcode) — this session did NOT run that report, since
  P9 isn't open and a report run against a closed gate proves nothing durable.
- **Delete**: the `validateComponentCompatibility()` private method
  (currently :4887-onward) and its call site (currently :534, inside the
  Phase 1.5 comment block) — *only* if both the deadcode report and the
  callers-list in item 3 above are re-confirmed unchanged at implementation
  time.
- **Do NOT delete**: `checkComponentPairCompatibility()` itself (item 3);
  the `ENGINE_MODE` dispatch block (item 4, separate precondition — `U-C.6`
  must read `verified` first, independent of the P8/P9 gate chain).
- **Leave `phase-status.json`'s `U-D.1` entry `not_started`** until the
  actual deletion lands — this plan is not that.

## Tests required before this unit can be marked `implemented`

- Per-symbol `deadcode_report.php` GREEN for `validateComponentCompatibility`
  specifically (not run this session — no code changed to prove against).
- `php -l` across the touched tree.
- `tests/characterize_compatibility.php` against the checked-in baseline:
  **ZERO diffs** (the pack's own pin — this session's independent full-sweep
  characterization run, see the accompanying verify-session handoff, already
  confirms the CURRENT baseline is 12/12 byte-identical to a fresh capture,
  so the pin point is valid and current as of today; the deletion itself
  still needs its own zero-diff proof once made).
- `scripts/verify/run_all.php --quick` GREEN.
- None of this was run this session for the deletion itself — there is no
  code change yet to run it against. This section records what the
  implementing session must prove, same posture as the U-X.1 plan.

## Explicit non-actions this session

No flag was set. No file under `core/`, `api/`, or `database/` was created,
modified, or deleted for U-D.1. No `deadcode_report.php` run (P9 closed —
nothing to prove against yet). `phase-status.json`'s P9/`U-D.1` entry is NOT
changed by this session (stays `not_started` — a plan is not an
implementation). This document is the only artifact.
