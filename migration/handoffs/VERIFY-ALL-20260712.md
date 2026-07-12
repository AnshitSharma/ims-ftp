# VERIFY-ALL — full-migration independent audit (2026-07-12)

Scope: independent re-verification of every unit claimed implemented/verified to date
(P0 U-0.1–0.4, P1 U-1.1–1.6, PL U-L.1–L.6, P2 U-B.1–B.3, P3 U-SM.1–SM.5 — 24 units)
plus a cross-cutting CLAUDE.md hard-rules audit over the whole migration history.
Method: 6 parallel reviewers (one per phase + hard-rules), claim-by-claim against the
actual code, `php -l` on every PHP file changed since 150fbbf era (31 files, all clean),
plus local test execution where possible (no production dump available in this
environment, so golden-DB tests were reviewed, not re-executed).

Tests re-executed this session (local MariaDB / DB-less): `tests/state_machine_unit.php`
ALL PASS (run twice, independently), `tests/unit/resource_catalog_test.php` ALL PASS,
`tests/nic_sfp_authority_unit.php` OK.

## Verdict

All 24 claimed units are genuinely implemented and match their handoffs. No live
hard-rule violations in the current tree. phase-status.json is accurate, including
U-SM.5=verified (the checklist's "NOT yet verified" line was the stale artifact —
corrected this session). All production-affecting behavior remains flag-gated with
unset ⇒ off verified reader-by-reader (DUAL_WRITE_ENABLED, STATE_MACHINE_ENABLED,
all authority/pipeline flags).

Notable confirmations:
- U-0.1 fail-closed: all 4 addComponent catch paths reject+rollback; handler 500s fail-closed.
- U-0.2 immutability holds in every StateGuard mode (enforce blocks status 3 itself;
  off/shadow falls back to the TEMP-GUARD inside the FOR-UPDATE lock).
- U-1.1–1.3 seeders match expected_schema.json column-for-column; rollbacks present.
- U-1.5 dual-write: single flag reader, off ⇒ zero DB access on the hook; the old
  `$componentDetails['ID']` blocker genuinely fixed (ID added to both lock SELECTs).
- U-B.2 extractNics() slot_position fix present (Extractor.php:179), matching siblings.
- U-SM.2 seeded graph sane: no failed→available resurrection edge; 12 config + 17
  inventory edges as documented.
- Hard rules: no seeder ever edited in place; the 18 seeders deleted by 75a04bc were
  restored byte-identical by 98814a4; bulk-add routes every row through
  BaseFunctions::addComponent() (UUID validation + permission_map intact); no
  acl_permissions references; chasis-level-3.json mapping intact; rack/pipeline
  role gates intact; no secret/path leakage in new code.

## Open findings (carry forward — none block the current all-flags-off state)

1. **RV-4 dropped, now reachable (fix before DUAL_WRITE_ENABLED=on soak).**
   `ResourceCatalog::consumesStorage()` has no M.2 exclusion, but the legacy
   `PcieLaneBudgetValidator` deliberately excludes M.2 form factors from the shared
   lane budget. PL-GATE-20260707.md required RV-4 to be fixed alongside the CPU
   provider gap (U-L.4); U-L.4/L.5/L.6 never mention it. With dual-write on, any
   M.2 NVMe add writes a pcie_lane consumption row the legacy model doesn't count →
   ledger_report check 4 REDs (lane_model_mismatch). Fix: mirror the legacy
   form_factor M.2 check in consumesStorage().
2. **backfill.php has no DB-selection guard.** It boots core/config/app.php, i.e. the
   production `.env` (DB_NAME even defaults to the production DB name when unset).
   Dry-run-by-default protects a bare run, but `--execute` on the deployed copy
   (scripts/ IS deployed) writes straight to production. Before U-B.4, add an explicit
   guard (require --dsn/GOLDEN_DB_* or an APP_ENV check + confirmation).
3. **status_v2 drift writers outside ServerBuilder.** OnboardNICHandler writes legacy
   `Status` directly (3 sites) and the generic component-edit path
   (BaseFunctions::updateComponent 'status' field) can too — neither syncs status_v2.
   inventory_report.php Check 3 detects the mismatch but nothing prevents it.
4. **Transition audit actor = 0.** ServerBuilder.php:3706 calls applyConfigTransition
   without the actor param, so config_events 'transition' rows log actor 0 even after
   U-SM.5 (U-SM.5 fixed the permission path only).
5. **Self-test exit-code inversion vs header docs** in equivalence_report.php and
   schema_report.php (--self-test exits 1 on PASS by design; headers say the opposite).
   One-line doc fix; run_all.php is unaffected.
6. **Stale docs**: CLAUDE.md names STORAGE_BAY_AUTHORITY_ENABLED but the implemented
   flag is STORAGE_CONNECTION_AUTHORITY_ENABLED; U-B.3's handoff still describes the
   now-empty LEDGER_SKIP_* lists; U-1.4's "dual-write only callers" superseded by
   U-SM.3 (finalize now always bumps revision/writes config_events — intended).
   Minor: dead `$validationInfo = [];` at server_api.php:440.

## Unchanged constraints (unaffected by this audit)

P2 gate stays closed on U-B.4 (human sign-off: DUAL_WRITE_ENABLED=on + 24h soak,
12 real orphans + 1 quarantined pciecard decision). P3 gate stays closed on the 7-day
shadow soak (human sets STATE_MACHINE_ENABLED=shadow). No unit statuses changed by
this session — it verified, it did not implement.
