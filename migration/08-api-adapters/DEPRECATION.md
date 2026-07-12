# API deprecation timetable — legacy add/remove/finalize actions

Written for U-A.1 (redesigned flag-gated per owner decision, 2026-07-12 — see
`migration/handoffs/SESSION-20260712-P6-COMMAND-LAYER.md` and this session's
follow-up handoff for the full reasoning).

## Deviation from U-A.1's original pack

The pack's literal text: delete the API-layer advisory validation block in
`handleAddComponent` and the handler-level SFP auto-assign SQL
UNCONDITIONALLY, replacing both handlers with a single `parse → ACL →
dispatch command → shim` path with no flag gate.

That was judged too risky to implement as written, for two independent
reasons:

1. **No flag gate at all** would mean every add/remove call runs through
   the command layer regardless of `COMMAND_LAYER_ENABLED`'s value — a real,
   irreversible-without-a-code-revert behavior change on a command layer
   that has never run in `enforce` mode anywhere. Every other unit this
   migration has shipped (U-C.1–U-C.5, U-V.*, U-SM.*) is flag-gated with a
   verified zero-behavior-change `off` mode; U-A.1 as written would have
   been the first exception.
2. **AddComponentCommand does not yet replicate SFP auto-assignment.** The
   handler-level SFP auto-assign SQL (`handleAddComponent`'s "AUTO-ASSIGNMENT
   TRIGGER" block, runs after a NIC add) has no equivalent in
   `AddComponentCommand::apply()`. Deleting it unconditionally, as the pack
   specifies, would silently break auto-SFP-assignment for every fleet add —
   not a scaffolding risk, a real feature regression with no replacement.

## What actually shipped instead

- `handleAddComponent`'s pre-transaction advisory validation block (the one
  that calls `ServerBuilder::validateComponentAddition()` against an unlocked
  snapshot and surfaces `validationWarnings`) is now skipped **only** when
  `CommandLayer::mode() === 'enforce'`. At `off`/`shadow` it behaves exactly
  as before this session — mirrors U-C.5's identical precedent in
  `handleFinalizeConfiguration` (drop the unlocked pre-check only at
  enforce, since only then is the command's own locked evaluate() the sole
  authority).
- The handler-level SFP auto-assign SQL is **NOT deleted** — flagged as a
  known gap instead (see below). It still runs unconditionally after every
  successful NIC add, at every `COMMAND_LAYER_ENABLED` mode, exactly as
  today.
- An `X-IMS-Deprecation` response header was added to both
  `handleAddComponent` and `handleRemoveComponent` unconditionally (purely
  informational, zero behavior change) per the pack's own text.
- `tests/api/add_remove_response_shape_test.php` (structural, DB-free) was
  added, checking the header, the enforce-only advisory-block skip, and that
  the SFP auto-assign block is still present (not silently deleted).

## Known gap carried forward

**SFP auto-assignment has no command-layer equivalent.** Before U-A.1's
unconditional-deletion approach can be attempted (or before
`COMMAND_LAYER_ENABLED` is ever soaked at `enforce` in production for real),
a follow-up unit needs to either:
(a) move the auto-assign logic into `AddComponentCommand::apply()` (matching
    onboard-NIC materialization's own precedent — "moved pre-commit"), or
(b) explicitly document that SFP auto-assignment is a legacy-only feature
    that stops working once `COMMAND_LAYER_ENABLED=enforce` is flipped, and
    get an owner sign-off on that tradeoff.
Not chosen here — flagged for the next command-layer session.

## Timetable

No firm dates — deprecation is gated behind the same human decisions as the
rest of the command layer's rollout (see `migration/phase-status.json`'s
`last_session` note): `COMMAND_LAYER_ENABLED` cannot flip to `shadow` in
production until the U-B.4 backfill runs and a fleet-wide parity sweep
(`scripts/verify/fleet_parity_sweep.php`, added this session) comes back
clean; it cannot flip to `enforce` until a shadow soak is reviewed; U-C.6's
own transaction-ownership consolidation cannot start until `enforce` has run
7 days. The advisory block / SFP auto-assign SQL bodies stay in the codebase,
inert at `off`, until that chain completes.

## Replacement actions

`server-add-component` / `server-remove-component` remain the only actions
today (U-A.2 is what would add `server-replace-component` /
`server-transition-status` as new, additive actions — see that unit).
