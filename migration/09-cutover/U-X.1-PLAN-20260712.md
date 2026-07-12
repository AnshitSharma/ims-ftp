# U-X.1 plan (planning only — no cutover actions taken)

Date: 2026-07-12. Scope: produce an implementation plan for U-X.1 (ConfigReadRouter,
`READ_FROM_ROWS` off|sample|on) by reading `09-cutover/README.md` +
`execution-packs/U-X.1.md`/`U-X.2.md` against the CURRENT state of `ServerBuilder.php`.
**Nothing in this file was implemented or executed.** No flag was touched, no file
under `core/` was created or modified for this unit.

## Gate status (unchanged by this session)

P8's own stated prerequisite is "P7 gate open" (`09-cutover/README.md:4`). Per
`phase-status.json`, **P7 is `closed`** (U-A.1/U-A.3 verified, U-A.2 stays
`implemented` — its own acceptance scenarios need a live HTTP context with
`CommandLayer::mode() !== 'off'`, which production rules forbid). P2 (`closed`,
U-B.4 backfill blocked) and P3 (`closed`, 7-day `STATE_MACHINE_ENABLED=shadow` soak)
are further upstream and also closed. **U-X.1 cannot begin — let alone U-X.2's
cutover runbook — until P7 opens, which itself needs P2/P3's soak/backfill
preconditions to clear first.** This is a human/time gate, not a code gate; nothing
below changes that. Per standing owner authorization (recorded in the P6/P7
handoffs), this plan is prepared ahead of time so implementation can start the
session P7 actually opens, without re-deriving this analysis from scratch.

## Pack-vs-reality drift found this session (flag before anyone implements from the pack)

1. **Line references are stale.** The pack cites `ServerBuilder.php` 61–260
   (`extractComponentsFromJson`) — still accurate, confirmed at `62`–`~256`. But
   `getConfigurationDetails` is cited at `2149`; it is actually at **`2248`** (drift
   +99). `getConfigComponents` is cited (README) at `4850`; it is actually at
   **`5106`** (drift +256). Anyone implementing from the pack's line numbers alone
   will edit the wrong code. Re-derive line numbers at implementation time, don't
   trust the pack's citations.

2. **`getConfigComponents()` is NOT a caller of `extractComponentsFromJson()` — it is
   a second, independent, hand-rolled JSON-extraction implementation.** Confirmed by
   reading both functions in full this session:
   - Output shape differs: `getConfigComponents()` emits `'uuid'`; `extractComponentsFromJson()`
     emits `'component_uuid'` (per `TargetStateBuilder.php:31`'s own comment on the
     canonical contract). A router built to reproduce `extractComponentsFromJson()`'s
     shape (per the pack's "enrichment reproduced" checklist item) will NOT match
     `getConfigComponents()`'s callers without a second mapping.
   - No name enrichment (`getComponentNameFromSpec()` is never called in
     `getConfigComponents()`).
   - **Silently drops onboard NICs** (`if ($nic['source_type'] === 'component')` — the
     onboard branch is skipped entirely, no equivalent of U-R.9's onboard handling).
   - Never reads `sfp_configuration` at all — SFPs are absent from its output.
   - Only one call site exists fleet-wide: `server_api.php:1344`, inside the
     **virtual-config-import mutation flow** ("Step 2: Get all components from virtual
     config"), not a user-facing read endpoint. It reads more like a mutation-path
     helper that happens to be named like a read entrypoint.

   **Recommendation for the implementer:** treat `getConfigComponents()` as OUT OF
   SCOPE for U-X.1's router (it's not a genuine read entrypoint — importing a virtual
   config's components for cloning is a mutation-adjacent operation, matching the
   pack's own carve-out for "mutation-path callers stay direct until U-D.3"). If the
   owner disagrees and wants it routed too, it needs its OWN shape-reproduction logic
   in `ConfigReadRouter` (a second output contract, not a reuse of the
   `getConfigurationDetails` path) plus a decision on whether "on" mode should now
   surface onboard NICs / SFPs that legacy silently dropped (a behavior change, not
   parity) — flag to the owner before touching this function.
   `getConfigurationDetails()` (2248, confirmed calling `extractComponentsFromJson()`
   at 2276) remains the one clean, pack-matching entrypoint to route.

3. **`ConfigComponentRepository::liveRows()` confirmed present** (`ConfigComponentRepository.php:51`)
   — the pack's "reuse!" instruction for the rows-path source is valid, no drift there.

## Revised file/scope list for implementation (once P7 opens)

- **Create** `core/models/config/ConfigReadRouter.php`: `components($configUuid)` —
  `off` → call `ServerBuilder::extractComponentsFromJson()` on the config row (legacy,
  byte-identical); `sample` → run BOTH `extractComponentsFromJson()` and
  `ConfigComponentRepository::liveRows()`, canonicalize (reuse the equivalence
  canonicalization consts per the pack — `scripts/verify/equivalence_report.php`
  already has this logic, confirmed present), diff, log any divergence to
  `reports/shadow/read-<Ymd>.jsonl`, return the LEGACY result unconditionally; `on` →
  map `liveRows()` rows to `extractComponentsFromJson()`'s exact output shape
  (`component_uuid`, enrichment via `getComponentNameFromSpec()`, onboard-NIC handling
  per U-R.9's resolved semantics) and return that instead.
- **Modify** `ServerBuilder.php` line 2276 only (inside `getConfigurationDetails()`,
  confirmed line) to call `ConfigReadRouter::components()` instead of
  `extractComponentsFromJson()` directly. **No other of the 15 confirmed call sites
  of `extractComponentsFromJson()` in `ServerBuilder.php` change** — they're all
  mutation-path (add/remove/replace/finalize component, validation) and stay direct
  per the pack's own instruction; list them in a code comment at the router's call
  site as the pack requires.
- **Create** `tests/regression/read_router_test.php`: three modes against a
  dual-written fixture (needs `DUAL_WRITE_ENABLED` history on that fixture config, or
  a fixture built the same in-transaction way this session's chassis-bay fixture was
  — no live dual-written config confirmed to exist in the scratch DB yet, worth
  checking at implementation time), shape equality field-by-field in `on` vs the
  legacy snapshot.

## Tests required before this unit can be marked `implemented`

- `off` + `sample`: full `characterize_compatibility.php` run must show ZERO diffs
  (current checked-in baseline, freshly re-captured this session, is the pin).
  Sample-mode divergence log must be empty against a healthy fixture and non-empty
  against a deliberately corrupted one (self-test, per the pack).
- `on`: `read_router_test.php` shape-equality PASS + `scripts/verify/run_all.php
  --quick` GREEN.
- None of this was run this session — there is no code to run yet. This section
  records what the implementing session must prove.

## Explicit non-actions this session

No flag was set. No file under `core/`, `api/`, or `database/` was created or
modified for U-X.1. `phase-status.json`'s P8/U-X.1 entry is NOT changed by this
session (stays `not_started` — a plan is not an implementation). This document is
the only artifact.
