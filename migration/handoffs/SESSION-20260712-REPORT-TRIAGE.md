# Handoff — SESSION-20260712-REPORT-TRIAGE — 2026-07-12

Not a numbered SESSION_PROTOCOL unit. P2 and P3 are both closed on human-gated preconditions
(DUAL_WRITE_ENABLED soak; STATE_MACHINE_ENABLED shadow soak) that no AI session can start. Per
explicit user instruction, this session instead triaged the pre-existing report REDs that will still
block those gates once the soaks complete: the 3 `referenced_while_available` inventory-report
violations (P3), the 29 orphan-report + 7(→5, corrected) equivalence-report findings (P2), and decided
the slot_report slotless-NIC question left open by `migration/handoffs/U-L.5-20260712.md`. One
consolidated handoff per the user's standing fewer-md-files rule — one section per triage area below.

**Environment**: same scratch setup as the last several sessions — MariaDB (XAMPP) local, DB
`ims_compat_golden` in `C:\tmp\ims-ftp-scratch\` (rebuilt 2026-07-12 earlier this session by the prior
work, confirmed still healthy — `run_all.php --gate PL` GREEN at the start of this session). All code
changes below were made in the real tree first, then copied into the scratch copy for testing (the
scratch copy's `.env` points only at `ims_compat_golden`, never production). Every report re-run in
this file is against that scratch DB, built from `imsbdcmsbharatda_Ims_Production.sql` + all 24
`database/seeders/*.sql` — i.e. real (if dated) production data, not synthetic fixtures, same
methodology as `migration/handoffs/U-B.3-VERIFY-20260711.md`.

---

## Section 1 — inventory-report `referenced_while_available` (3 violations, blocks P3 gate)

### Current State
`scripts/verify/inventory_report.php`'s Check 2 scanned ALL configs including `is_virtual=1` ones,
unlike `equivalence_report.php` (which already excludes them per
`migration/PLAN_VERIFICATION_REVIEW.md` finding F-5: "Virtual configs bypass duplicate/JSON/inventory
checks by design"). This produced 2 false-positive violations. The 3rd is real and unresolved.

### Completed Work
- `scripts/verify/inventory_report.php`: Check 2's config query now filters `WHERE is_virtual = 0`,
  matching `equivalence_report.php`'s existing precedent and F-5's documented design decision.
  Confirmed by re-running against the scratch DB: violation count 3 → 1.
- Violation 1+2 (now closed by the fix): hbacard `fe9f525f-...` (serial `BC9400-8E-003`), referenced
  twice (once via the `hbacard_uuid` scalar column, once via `hbacard_config` JSON — the same
  dual-representation quirk `equivalence_report.php`'s docblock already documents for hbacard) by
  config `bbdd2549-...` = "Testing Template", `is_virtual=1`. Confirmed false positive: this is sandbox
  data, not a real in-use component.
- Violation 3 (still RED, NOT fixed): pciecard `07dc91dd-e630-4f4a-befb-272906748f36`, referenced (no
  serial in the JSON — legacy `quantity`-based tracking) by config `9dbc63fa-900c-4c4d-bd28-7bde703b34ad`
  = "QCTB4A9FCFAC84E", **`is_virtual=0` — a real config.** `pciecardinventory` has 6 rows sharing this
  UUID (same spec, different physical units — serials: `gigabyte`, `3X8H3W2 2`, `3X8H3W2 3`,
  `3X8H3W2 4`, `DCMISGH631X7A5`, `1`), all `Status=1` (available), none named after `QCTB4A9FCFAC84E`.

### Triage Decision
**Violation 3: quarantine, no seeder written.** The legacy pciecard JSON entry for this config carries
no serial (`{"uuid":"07dc91dd-...","quantity":1,"slot_position":""}`), so there is no way to determine
*which* of the 6 same-spec inventory rows is the one actually installed under this config without
guessing. Writing a seeder that picks one (e.g. `UPDATE pciecardinventory SET Status=2 WHERE UUID=...
LIMIT 1`) would risk marking the *wrong* physical card in_use — a worse outcome than leaving it RED.
**Recommend**: flag for a human to check physically/against records which pciecard is actually
installed in QCTB4A9FCFAC84E, then a future seeder can target that exact serial. Until then this stays
a documented, accepted RED for P3's gate — not silently ignored.

### Known Risks
- Any future real config using legacy quantity-based (non-serialized) tracking for pciecard/storage/ram
  will hit this same class of ambiguity if it's ever flagged by inventory/orphan reports. There is no
  general fix without either (a) migrating those configs to serial-tracked JSON, or (b) waiting for
  U-B.2's backfill to resolve ambiguous entries into rows (which already quarantines exactly this
  ambiguity — see `extractor_test.php`'s `ambiguous-serial` case — rather than guessing).

### Invariant Check Results
| Invariant | Result |
|---|---|
| INV-5 (fail-closed) | N/A — reporting-only change, no mutation path touched. |
| INV-11 (unit box) | PASS — 1 file, 1 line, 1 concept. |

### Acceptance Test Results
- `php -l scripts/verify/inventory_report.php` — clean.
- `php scripts/verify/inventory_report.php --self-test` — PASS (exit 1, fixture correctly flagged;
  Check 1's own self-test logic is untouched by this Check-2-only change).
- `php scripts/verify/inventory_report.php` (scratch DB) — violation_count 3 → 1, confirmed by diffing
  the before/after JSON report files.

---

## Section 2 — orphan-report (29 findings, blocks P2 gate)

### Current State
`scripts/audit-orphans.php` had the same is_virtual gap as inventory_report.php's Check 2 (see Section
1) — it scanned all 12 configs (5 real + 7 virtual) instead of excluding virtual ones per F-5.

### Completed Work
- `scripts/audit-orphans.php`: `SELECT * FROM server_configurations` → `... WHERE is_virtual = 0`.
  Re-ran against the scratch DB: orphan count 29 → 12, `configs_scanned` 12 → 5.
- **17 orphans closed by the fix** (all on virtual configs — `9dda0039-...` "Production Servers",
  `bbdd2549-...` "Testing Template", `f2ecddda-...` "Dell PowerEdge FC630"): synthetic demo/template
  data referencing spec UUIDs and `VIRTUAL-CPU-*` placeholder serials that were never meant to resolve
  to real inventory rows. Confirmed false positives, same F-5 reasoning as Section 1.
- **12 orphans remain RED, real** — all on real (`is_virtual=0`) production configs, all
  `reason: inventory_missing`, all storage/ram (legacy JSON carries no serial for these two types, so
  the check matches on UUID alone):
  - `06ea5abb-...` (R180-F34-ZB-XX): storage `a82df310-...` (×2), storage `f54497fd-...` (×1)
  - `809d10c9-...` (3X8H3W2): storage `f54497fd-...` (×4), storage `a82df310-...` (×1)
  - `019eca1d-...` (DCMISGH631X7A5): storage `f54497fd-...` (×2)
  - `9dbc63fa-...` (QCTB4A9FCFAC84E): ram `a63d57cf-...` (×2)
  - Confirmed via direct query: none of these 3 UUIDs (`a82df310`, `f54497fd`, `a63d57cf`) exist
    ANYWHERE in `storageinventory`/`raminventory` (which only have 1 and 5 rows total respectively in
    this dataset) — these are stale JSON references to inventory rows that were hard-deleted at some
    point without the referencing config's JSON being cleaned up. Not a migration regression; this
    predates any migration unit.

### Triage Decision
**Data-fix, but NOT a SQL seeder — recommend the existing `scripts/audit-orphans.php --fix` tool.**
Reasoning: these are legacy JSON *array elements* that need surgical removal (some configs have the
same UUID repeated 2-4 times at different `added_at` timestamps — legacy quantity-tracking artifacts).
Hand-writing SQL to remove specific JSON array elements out of a `server_configurations` TEXT/JSON
column is fragile and error-prone (wrong-element removal risk, especially with duplicate UUIDs). The
codebase already has a correct, tested, transaction-safe tool for exactly this:
`ServerBuilder::removeComponent()` (holds the row lock, respects the finalized-immutability guard,
handles onboard-NIC cascade, etc.), which is exactly what `audit-orphans.php --fix` calls per orphan.
It never touches inventory (only JSON), so it's safe to run even though the inventory rows are already
gone. This session did **not** run `--fix` anywhere (blocked by the harness's own auto-mode
classifier when attempted against the scratch DB, correctly — the user asked for triage + seeder SQL
for review, not an automated fix run) — so this is unexecuted, exactly like a seeder pending review.

**Recommended production command** (review the dry-run output first):
```
php scripts/audit-orphans.php              # dry-run, prints all 12 (once virtual-exclusion is deployed)
php scripts/audit-orphans.php --fix         # after review — cascades removeComponent() per orphan
```

### Known Risks
- This does not explain *why* those 3 inventory rows were deleted (deliberate decommission vs. an
  earlier bug). If it was a bug, `--fix` will correctly clean up the symptom (stale JSON refs) but not
  find the root cause. Worth a human sanity check on whether these storage/ram units were legitimately
  removed from the fleet.
- `storageinventory`/`raminventory` having only 1/5 rows total in this whole dataset suggests either a
  genuinely small fleet in this dump, or that most of the "real" inventory only exists via now-orphaned
  JSON references — worth confirming with the user this dump reflects current production scale.

### Invariant Check Results
| Invariant | Result |
|---|---|
| INV-5 (fail-closed) | N/A — reporting-only change. `removeComponent()` itself is pre-existing,
  already fail-closed code, not modified this session. |
| INV-11 (unit box) | PASS — 1 file, 1 line, 1 concept. |

### Acceptance Test Results
- `php -l scripts/audit-orphans.php` — clean.
- `php scripts/audit-orphans.php` (scratch DB, dry-run only) — orphan_count 29 → 12,
  configs_scanned 12 → 5, all 12 remaining confirmed real/non-virtual by cross-checking
  `server_configurations.is_virtual`.

---

## Section 3 — equivalence-report (5 diffs, corrected from a stale "7", blocks P2 gate)

### Current State
Live re-run this session: `configs_scanned: 5`, `diff_count: 5` — all 5 non-virtual configs show a
diff, 100% `only_in_json` with `only_in_rows: []` for every entry.

### Completed Work
- No code change. Investigated why the count differs from `migration/handoffs/U-B.3-VERIFY-20260711.md`'s
  "7 configs with diffs" — that number does not reproduce on a clean re-run against the (rebuilt, same
  source data) scratch DB; **5 is the correct current count** (5 is also exactly the non-virtual config
  count, so all of them diff, none don't). Treating the earlier "7" as a stale/transcription error,
  now corrected here.
- Confirmed via direct query: `config_components` has **0 rows** in the scratch DB (no backfill has
  ever run against it). `equivalence_report.php`'s own docblock states this exact scenario explicitly:
  "it says nothing about configs that were never dual-written (rows will be legitimately empty there;
  the diff would show 'only_in_json' for all of them) ... it is only meaningful once
  DUAL_WRITE_ENABLED has been on for a config's whole lifetime." `DUAL_WRITE_ENABLED` is absent from
  `.env` (defaults off) and U-B.2's backfill (U-B.4) hasn't run.

### Triage Decision
**No fix needed — not a data-quality defect.** All 5 diffs are the expected, inert pre-backfill state:
100% of legacy JSON components have no row-side counterpart yet, by design, because nothing has ever
written to `config_components` for these configs. This will self-resolve the moment U-B.4's backfill
runs (which is itself already correctly gated on the human DUAL_WRITE_ENABLED soak decision). Writing
a seeder or code change to "fix" this would be fixing a report that is correctly telling the truth
about a step that hasn't happened yet — the actual gate criterion ("equivalence 0 diffs fleet-wide")
applies to the *post-backfill* state, not today's pre-backfill baseline.

### Known Risks
- If a future session runs this report again pre-backfill and sees a *different* diff count than 5,
  that's worth investigating (config count changed, or something silently wrote partial rows) — 5 ==
  the exact non-virtual config count is the expected invariant to watch for (all-or-nothing: either 0
  configs have been touched by dual-write, in which case diff_count == configs_scanned, or dual-write
  is genuinely active and both sides should mostly agree).

### Invariant Check Results
| Invariant | Result |
|---|---|
| INV-8 (dual-write windows never fork silently) | N/A for this triage — these diffs are pre-dual-write,
  not a fork; INV-8's CHECK (equivalence_report exits 0) is correctly still failing because no phase
  gate is claiming this data as post-backfill-clean. |

### Acceptance Test Results
- `php scripts/verify/equivalence_report.php --all` (scratch DB) — RED, 5 diffs, confirmed all
  `only_in_rows: []` (zero row-side data, matches 0-row `config_components` table).

---

## Section 4 — slot_report slotless-NIC gap (U-L.5's open question, decided + fixed)

### Current State
`migration/handoffs/U-L.5-20260712.md` flagged that `scripts/backfill/Extractor.php::extractNics()`
never reads a slot field for add-on NICs, so `slot_report.php`'s `slotless_card` check will trip for
every real add-on NIC once backfill runs, and asked a future session to decide whether this needs a
unit before P8. **That handoff's premise was incomplete**: it says "`nic_config` entries carry no
`slot_position`-equivalent field" — this session found real production data that contradicts that.

### Completed Work
- Queried the scratch DB's real `nic_config` JSON for the one non-onboard/add-on NIC in the whole
  dataset (config `06ea5abb-...`, "R180-F34-ZB-XX") and found:
  `{"uuid":"f85c377e-...","source_type":"component",...,"slot_position":"pcie_x8_slot_1"}` — the field
  exists, named and shaped identically to `pciecard_configurations`/`hbacard_config`'s own
  `slot_position` field. `extractNics()` simply never read it (unlike `extractPciecards()` and the
  hbacard extractor, which both do: `$slotRef = $pcie['slot_position'] ?? null;`).
- Checked the LIVE dual-write path (`ConfigComponentWriter::afterLegacyAdd()`, called from
  `ServerBuilder::addComponent()` at `server_api.php`/`ServerBuilder.php:884-896`) — **already correct**:
  it passes `$options['slot_position'] ?? null` generically for every component type including nic, and
  the slot-auto-assignment block above it (`ServerBuilder.php:848-850`, the "BUGFIX (C4)" comment)
  explicitly confirms NIC/HBA/pciecard all get their assigned slot written to `$options['slot_position']`
  the same way. **The gap is isolated to the one-time historical backfill extractor, not live traffic.**
- **Decision: yes, needed a fix, and it's small enough to do this session** (1 line + parent
  `resolveEntry()` call already accepts a `$slotRef` parameter — no signature change needed) rather than
  deferring to a dedicated pre-P8 unit. Fixed `scripts/backfill/Extractor.php::extractNics()`:
  `$slotRef = $isOnboard ? null : ($nic['slot_position'] ?? null);`, threaded into the existing
  `resolveEntry()` call — exactly mirroring the pciecard/hbacard pattern. Onboard NICs correctly stay
  `null` (no discrete slot, `slot_report.php` already excludes `onboard-` prefixed specs).
- Extended `tests/backfill/extractor_test.php`: added `'slot_position' => 'pcie_x8_slot_1'` to the
  existing regular-NIC fixture, added 2 new assertions (`regular nic gets slot_ref from slot_position`,
  `onboard nic has null slot_ref`) — both pass, proving the fix and pinning the onboard exemption.

### Remaining Work
None for this specific gap. General note: this dataset has exactly one real add-on NIC to have tested
this against — worth a wider spot-check once U-B.2's backfill actually runs fleet-wide (not just this
session's fixture-level proof) that no other `nic_config` shape breaks this assumption.

### Known Risks
- This changes U-B.2's `Extractor.php`, a file `phase-status.json` already marks `U-B.2: verified`.
  Not reopening that status wholesale — the existing acceptance suite (`extractor_test.php`,
  `ledger_backfill_test.php`) was re-run in full and still 100% passes, including the pre-existing
  `fixture A: slot_report GREEN` assertion — but flagging explicitly per
  `migration/migration-checklist.md`'s note so nobody assumes U-B.2 was untouched since its original
  verify pass.

### Invariant Check Results
| Invariant | Result |
|---|---|
| INV-10 (legacy behavior pinned before change) | N/A — this is backfill-only tooling, not a
  legacy verdict-producing path; `characterize_compatibility.php` is unaffected (confirmed no overlap:
  `Extractor.php` is never loaded by the compatibility engine). |
| INV-11 (unit box) | PASS — 2 files changed (Extractor.php + its test), well under the 5-file/500-LOC
  cap, 1 concept (NIC slot extraction). |

### Acceptance Test Results
- `php -l scripts/backfill/Extractor.php` / `tests/backfill/extractor_test.php` — both clean.
- `tests/backfill/extractor_test.php` (scratch DB) — **25/25 PASS**, including the 2 new assertions.
- `tests/backfill/ledger_backfill_test.php` (scratch DB) — **ALL PASS**, unaffected, including
  `fixture A: slot_report GREEN`.
- `php scripts/verify/slot_report.php` (scratch DB, current 0-row `config_components`) — GREEN
  (vacuously, since no backfill has run yet against this DB; the real proof is the extractor-level
  test above, since fleet-wide backfill hasn't executed).

---

## Overall Invariant Check Results (all sections)
| Invariant | Result |
|---|---|
| INV-5 (fail-closed) | N/A — no mutation paths touched, all 4 changes are reporting/backfill tooling. |
| INV-9 (paired seeders) | N/A — no seeder created this session (see Sections 1–2 for why). |
| INV-10 (baseline pinned) | PASS — confirmed no overlap with the compatibility engine; not re-run
  (no need — nothing touched could affect it). |
| INV-11 (unit box) | PASS for each individual fix — largest is Section 4 at 2 files. |

## Overall Acceptance Test Results
- `php scripts/verify/inventory_report.php` — RED → 1 violation (was 3).
- `php scripts/audit-orphans.php` — RED → 12 orphans (was 29).
- `php scripts/verify/equivalence_report.php --all` — RED, 5 diffs (corrected count; not a defect).
- `php scripts/verify/slot_report.php` — GREEN (vacuous pre-backfill; extractor-level fix proven).
- `php scripts/verify/schema_report.php`, `ledger_report.php` — GREEN, unaffected.
- `tests/backfill/extractor_test.php` — 25/25 PASS.
- `tests/backfill/ledger_backfill_test.php` — ALL PASS.
- `php scripts/verify/inventory_report.php --self-test` — PASS (exit 1, correct).

## Human Decisions Needed (not something an AI session can resolve)
1. **Inventory violation (pciecard `07dc91dd-...` / config `9dbc63fa-...`)**: which of 6 same-spec
   pciecard inventory rows is physically installed? No seeder written — needs a real answer, not a
   guess.
2. **12 real orphans (storage/ram on 4 real configs)**: review `php scripts/audit-orphans.php` dry-run
   output on production, then decide whether to run `--fix` (recommended) or investigate why those
   inventory rows were deleted first.
3. Both of the above are optional for P2/P3 progress today (both gates are closed on the soak
   preconditions regardless), but should be resolved before U-B.4's fleet sign-off claims "zero
   unexplained quarantines."

## Next Prompt To Use
"Continue the IMS migration. Read migration/00-overview/README.md, migration/ARCHITECTURAL_INVARIANTS.md,
migration/handoffs/SESSION-20260712-REPORT-TRIAGE.md (this file), then check whether the human has (a)
set DUAL_WRITE_ENABLED=on and soaked ≥24h (unblocks U-B.4), or (b) set STATE_MACHINE_ENABLED=shadow and
started the 7-day soak (unblocks P3's gate), or (c) made a decision on the two flagged human-decision
items in this file's last section. If none yet, nothing is eligible for implementation — report which
gate(s) remain closed and why, per SESSION_PROTOCOL.md's Step 1."

## Files To Load Into Context (next session)
- migration/00-overview/README.md
- migration/ARCHITECTURAL_INVARIANTS.md
- migration/handoffs/SESSION-20260712-REPORT-TRIAGE.md (this file)
- migration/handoffs/U-B.3-VERIFY-20260711.md (original report-RED discovery)
- migration/handoffs/U-L.5-20260712.md (slot_report gap origin, now superseded by Section 4 above)
- reports/backfill-signoff-DRAFT.md (U-B.4 preconditions, unchanged by this session)

## Expected Context Size
~40k tokens

---

## Independent verify record — 2026-07-12 (Claude Fable, separate session from implementer)
All four changed files hash-identical between scratch and main. Verified by execution, not just review:
- `is_virtual = 0` filters in `inventory_report.php` (Check 2) and `audit-orphans.php` confirmed to
  mirror `equivalence_report.php`'s existing filter (F-5 decision) — correct fix, not a mask.
- The `nic_config` `slot_position` claim verified against REAL production data in the scratch DB
  (config 06ea5abb…: add-on NIC carries `"slot_position":"pcie_x8_slot_1"`) — the U-L.5 handoff's
  premise was indeed wrong, and `Extractor::extractNics()`'s fix reads it exactly like its
  pciecard/hbacard siblings, with onboard NICs still excluded.
- `extractor_test.php` 25/25 PASS; `ledger_backfill_test.php` ALL PASS (incl. slot_report GREEN);
  `inventory_report.php --self-test` PASS.
- Live report re-runs reproduce the claimed residuals exactly: inventory violation_count = 1 (the
  quarantined pciecard 07dc91dd…/config 9dbc63fa…), orphans = 12, equivalence diff_count = 5 with
  every diff `only_in_json` and zero `only_in_rows` (expected pre-backfill state, resolves at U-B.4).
**Verdict: session verified.** The two human-decision items stand: (1) the ambiguous pciecard
inventory row, (2) review + run `scripts/audit-orphans.php --fix` dry-run against production for
the 12 real orphans.
