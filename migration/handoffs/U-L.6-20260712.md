# Handoff — U-L.6 — extractLaneCount() legacy-mirror fix — 2026-07-12

## Current State
Implemented per `migration/06-resource-ledger/execution-packs/U-L.6.md` (pack written this same
session, since this is a fix unit discovered by the U-L.4/U-L.5 verify pass, not a pre-planned
unit). Status: **implemented, not verified** — per SESSION_PROTOCOL.md, an independent session
must verify. **PL gate set back to `closed`** in `phase-status.json` (it had just been reopened by
this session's U-L.4/U-L.5 verify pass) — it reopens once U-L.6 is also independently verified.

## What shipped
Closes Finding 1 from `migration/handoffs/U-L.4-U-L.5-VERIFY-20260712.md`: `ResourceCatalog::
extractLaneCount()` (`core/models/config/ResourceCatalog.php`) did not actually mirror
`PcieLaneBudgetValidator::extractLaneCount()` (`core/models/compatibility/
PcieLaneBudgetValidator.php:346-358`) exactly, despite the docblock's explicit claim. Legacy
returns 0 immediately when the `interface`/`pcie_interface`/`bus_interface` candidate is absent or
an empty string — its numeric `pcie_lanes` fallback is reachable ONLY via a non-empty candidate
string that fails the `/x(\d+)/i` regex. The prior `ResourceCatalog` version reached the
`pcie_lanes` fallback even when no interface field existed at all.

- `core/models/config/ResourceCatalog.php`: `extractLaneCount()` now checks
  `if (!is_string($candidate) || $candidate === '') return 0;` BEFORE attempting the regex —
  identical structure and order to the legacy method, not folded into the regex's `&&` condition
  as before. Method docblock updated to explain why this guard exists and to warn future readers
  not to "improve" it.
- `tests/unit/resource_catalog_test.php`: the one assertion pair that exercised the (now-removed)
  incorrect fallback — a pciecard fixture with no interface field but a numeric `pcie_lanes=8` —
  updated to expect `[]` instead of a 1-row result (this now matches legacy exactly). A NEW
  fixture (`$pciecardUuidUnparseableInterfaceFallback`, `interface: "PCIe Gen4"` — a non-empty
  string that fails the regex — plus `pcie_lanes: 8`) was added so the REAL fallback path (the one
  legacy actually exercises) stays covered by a passing test instead of being deleted along with
  the incorrect one.

## Why this was unreachable on current data (and why it still mattered)
Grep confirmed `pcie_lanes` only appears on cpu/chassis/motherboard specs in the current
`ims-data`, never on a nic/hbacard/pciecard spec — so on real data today, the old and new
implementations always agreed (the fallback branch was never reached with data that would expose
the divergence). This is a **latent** correctness fix, not a live-data regression fix: a future
nic/hbacard/pciecard spec that carries a numeric `pcie_lanes` field without also carrying a
parseable `interface` string would have caused `ResourceCatalog` to count lanes that
`PcieLaneBudgetValidator`'s own compatibility check doesn't count — silent equivalence drift
between the ledger and the legacy validator. Fixed now, while the codebase is small and the
divergence is fresh in a handoff, rather than left for a future session to rediscover from a
production diff.

## Verification performed (scratch env `C:\tmp\ims-ftp-scratch`, DB `ims_compat_golden`)
- `php -l` on `ResourceCatalog.php` and `resource_catalog_test.php` — clean.
- `tests/unit/resource_catalog_test.php` — **ALL PASS** (46 checks — same total as before, since
  this fix replaced one assertion pair and added a new one for the real fallback path, net checks
  roughly flat), including:
  - the corrected assertion: absent-interface + numeric `pcie_lanes=8` pciecard now returns `[]`
  - the new fallback-path proof: `interface="PCIe Gen4"` (unparseable) + `pcie_lanes=8` still
    returns exactly 1 row with `amount=8` — proving the fallback ISN'T dead code, just correctly
    gated behind a non-empty candidate.
- `tests/backfill/ledger_backfill_test.php`, `tests/regression/ledger_dual_write_test.php` —
  **ALL PASS**, unaffected (their hbacard/pciecard fixtures use a real parseable `interface`
  string or no lane fields at all, neither of which touches the changed branch).
- `php tests/characterize_compatibility.php` — exit 0, 15 configurations, 100 add-time replays,
  same pre-existing `Undefined array key "model"` warning noise as every run this migration. Zero
  diffs, as expected: this method lives in `ResourceCatalog` (ledger bookkeeping), never in
  `PcieLaneBudgetValidator` itself (the actual verdict-producing legacy path), so no compatibility
  verdict this baseline captures could possibly change.
- `grep -n "mirrors.*exactly" core/models/config/ResourceCatalog.php`: docblock now makes an
  accurate claim — code review confirms the two methods are now structurally identical
  (guard-then-regex-then-fallback-then-0), not just similarly worded.

## Invariant Check Results
| Invariant | Result |
|---|---|
| INV-5 (fail-closed, spirit) | PASS — the one deliberate fail-open exception in this file now actually matches the legacy behavior its docblock claims to mirror |
| INV-10 (pin before change) | PASS — characterization run to completion, exit 0, zero diffs (verdict-neutral by construction: `ResourceCatalog` is never in `PcieLaneBudgetValidator`'s call path) |
| INV-11 (unit box) | PASS — 2 files changed (1 prod, 1 test), well under 500 LOC, 1 concept |

## Known Risks / Discoveries
None new. Same local-environment gotchas as prior sessions; scratch environment still running and
current through this unit's changes.

## Next Prompt To Use
"Continue the IMS migration. Read migration/00-overview/README.md,
migration/ARCHITECTURAL_INVARIANTS.md, migration/handoffs/U-L.6-20260712.md (this file), then run
the independent verify pass for U-L.6 per SESSION_PROTOCOL.md. Once verified, phase-status.json's
PL gate can reopen (all 6 PL units verified) and DUAL_WRITE_ENABLED remains eligible for its
production soak decision per reports/backfill-signoff-DRAFT.md."

## Files To Load Into Context (next session)
- migration/00-overview/README.md
- migration/ARCHITECTURAL_INVARIANTS.md
- migration/handoffs/U-L.4-U-L.5-VERIFY-20260712.md
- migration/handoffs/U-L.6-20260712.md (this file)
- core/models/config/ResourceCatalog.php
- core/models/compatibility/PcieLaneBudgetValidator.php:346-358 (the mirror source)

## Expected Context Size
~15k tokens
