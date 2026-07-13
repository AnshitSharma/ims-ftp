# U-D.2 plan (planning only — no deletions of any kind)

Date: 2026-07-13. Scope: produce an implementation plan for U-D.2 (delete
legacy validators + read-time warnings + superseded authorities, per
`10-cleanup/execution-packs/U-D.2.md`) by reading that pack against the
CURRENT state of `ServerBuilder.php`, `server_api.php`, and the five
authority classes it names. **Nothing in this file was implemented or
executed.** No file under `core/`, `api/`, or `database/` was created,
modified, or deleted for this unit; no `deadcode_report.php` run (P9 is not
open — a deadcode report proves nothing durable against a gate that isn't
open yet, same reasoning U-D.1's plan already gave).

## Gate status (unchanged by this session)

Same upstream chain U-D.1's plan already documented, one link further
downstream: P9 needs P8 open ≥14 days; P8 needs P7 open; P7 is `closed`
today (`U-A.2` stays `implemented` — its remaining acceptance criteria were
exercised for the first time this session via a scratch-only HTTP harness,
still not independently `verified`). **U-D.2 cannot begin.**
`phase-status.json`'s P9 block (`U-D.1`-`U-D.4`) all remain `not_started`.

U-D.2 additionally has its own STRUCTURAL precondition beyond the gate: the
pack itself says "pins baseline: yes (verdict surface now 100% engine)" —
meaning this unit assumes `ENGINE_MODE=enforce` is the sole live path
everywhere, which needs `U-C.6` (the enforce soak) to read `verified`
first — the SAME precondition gap U-D.1's plan already flagged for its own
scope (the `ENGINE_MODE` dispatch block), now confirmed to apply here too,
even more directly: U-D.2 wants to delete `validateConfiguration()` and its
siblings — the LEGACY validation path itself — which is still production's
only live path while `ENGINE_MODE` stays off/unset.

## Pack-vs-reality drift found this session

Spot-checked the pack's most load-bearing citations (not all ~15 symbols —
the pack groups several under "checkPower\*/checkFormFactor\* privates"
without individual line numbers, so those weren't independently re-derived
this session; re-derive all of them at implementation time regardless of
what's below, per U-D.1's plan's own standing advice that this file's line
numbers keep moving):

| Symbol | Pack cites | Actual (2026-07-13) | Drift |
|---|---|---|---|
| `validateConfiguration()` | 3166 | **3266** | +100 |
| `validateConfigurationEnhanced()` | 3275 | **3375** | +100 |
| `getConfigurationWarnings()` | 1875 | **1974** | +99 |
| `validateConfigurationComprehensive()` | 6414 | **6681** | +267 |
| `getConfigurationWarnings()` API call site (`server_api.php`) | 817 | **1146** | +329 |
| `handleValidateConfiguration()` (`server_api.php`) | 1348 | **1688** | +340 |

Same failure mode U-X.1's and U-D.1's plans already documented for this
file family: a shared upstream insertion (most likely U-R.9's onboard-NIC
work, per U-X.1's plan's own theory) explains the consistent +99/+100
offset for the three symbols above line ~3400; the larger +267 for
`validateConfigurationComprehensive()` (which sits much further down, at
6681) implies roughly +11 MORE lines were inserted somewhere between
`getConfigComponents()` (5106, confirmed unchanged by U-X.1's 2026-07-13
re-verification) and 6681 — a second, smaller insertion, not yet
attributed to a specific unit. `server_api.php`'s two citations show even
larger drift (+329/+340), consistent with U-A.2's new handlers
(`handleReplaceComponent`/`handleTransitionStatus`) having landed in that
file since the pack was written. **None of this blocks planning — it's a
re-derive-at-implementation-time flag, same posture as every prior plan in
this migration.**

**Confirmed still present, no drift to report** (files, not lines, so
stable regardless of internal edits): `MemoryAuthority.php`,
`SlotAuthority.php`, `StorageConnectionAuthority.php`,
`ValidationPipeline.php`, `PcieLaneBudgetValidator.php` — all still at
`core/models/compatibility/`. `OnboardNICHandler::replaceOnboardNIC()`
confirmed present at `core/models/compatibility/OnboardNICHandler.php:407`
(pack cites this only as a symbol name, no line number, so no drift to
measure — noting the confirmed line for the implementing session's
convenience).

**One new finding, not in the pack's own text**: `OnboardNICHandler.php`
is named in this pack ONLY for its `replaceOnboardNIC()` method, but this
session's earlier work (RV-4, seventh session) confirmed `OnboardNICHandler`
is also the class `ResourceCatalog`/`TargetState`'s onboard-NIC handling
(U-R.9, the ENGINE-side parity path) was modeled AFTER, not a caller of.
Deleting `replaceOnboardNIC()` specifically (not the whole class) should be
safe in isolation, but the implementing session should re-confirm this
method has no OTHER live callers beyond what U-D.2's own deadcode run
proves — not re-verified this session (no code changed, nothing to prove
against; flagging only as a specific thing to double check given how much
onboard-NIC-adjacent work has landed in this migration since the pack was
written).

## Revised scope for implementation (once P9 opens AND U-C.6 is verified)

Unchanged from the pack's own text, since nothing here found a reason to
deviate from it — only line-number re-derivation is needed:

- **SPLIT MANDATE stands**: the pack itself already calls for four
  sub-sessions (U-D.2a full-config validators + endpoint wire, U-D.2b
  add-path per-type validators, U-D.2c authority classes + pipeline + their
  tests, U-D.2d warnings + score family) — this plan does not attempt to
  collapse that split; re-affirmed as still necessary given the box-size
  reasoning in the pack ("this exceeds the 5-file box") is unrelated to
  line-number drift and hasn't changed.
- **Create** `core/models/validation/ValidateConfigService.php`, wire
  `handleValidateConfiguration()` (now at 1688, re-derive again by
  implementation time) to call `ValidationEngine.evaluate(VALIDATE)` via it.
- **Delete** (per sub-session, only after each symbol's own deadcode GREEN):
  `validateConfiguration` (3266), `validateConfigurationEnhanced` (3375),
  `validateConfigurationComprehensive` (6681) + its private tracker family,
  `getConfigurationWarnings` (1974) + its API call site (1146),
  `calculateHardwareCompatibilityScore` (5783, confirmed this session,
  no line cited in the pack) + `checkPower*`/`checkFormFactor*` privates,
  `assignComponentSlot` + `extractPCIeSlotSize`, `validateCPUAddition`,
  `validateRAMAddition`, `validateComponentQuantity`, the five authority
  classes + `ValidationPipeline`, `OnboardNICHandler::replaceOnboardNIC`
  (407), legacy authority unit tests.
- **Leave `phase-status.json`'s `U-D.2` entry `not_started`** until actual
  deletion lands.

## Tests required before this unit can be marked `implemented`

Unchanged from the pack: per-symbol `deadcode_report.php` GREEN (each
sub-session, before its own deletions); `php -l` across the touched tree;
`tests/characterize_compatibility.php` ZERO diffs (this session's own full
sweep, see the accompanying handoff section, confirms the CURRENT baseline
is current and zero-drift as of today — the pin point is valid, but the
deletion itself still needs its own zero-diff proof once made);
`scripts/verify/run_all.php --gate P9` GREEN. None of this was run this
session — no code changed to prove against.

## Explicit non-actions this session

No flag was set. No file under `core/`, `api/`, or `database/` was created,
modified, or deleted for U-D.2. No `deadcode_report.php` run.
`phase-status.json`'s P9/`U-D.2` entry is NOT changed by this session
(stays `not_started`). This document is the only artifact.
