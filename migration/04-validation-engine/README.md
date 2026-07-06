# 04-validation-engine — Phases P4 (engine, U-V.*) and P5 (rule migration, U-R.*)
Objective: single ValidationEngine evaluating TargetState with one Rule interface; run in shadow
against legacy verdicts with diff reports; then migrate every rule family, one unit each.
Prerequisites: P3 gate open (P4); P4 gate open (P5).
Affected files: core/models/validation/** (new), ServerBuilder shadow hook (1 site),
scripts/verify/parity_report.php. Affected DB tables: none (shadow logs are JSONL files).
Order: U-V.1→U-V.4 then U-R.1→U-R.8 (strict; parity re-run after EACH R unit).
Rollback: ENGINE_MODE=off is total rollback at any time.
Verification: parity report per RULE_MAP.md; engine exception count must be 0 in shadow.
Risks: verdict-shape mismatch between engines — the shadow comparator canonicalizes both to
{blocked: bool, error_class: string} only; message-text diffs are NOT diffs.
Duration: 12 sessions + soaks.
See RULE_MAP.md in this folder for the complete legacy→new mapping (Phase 5 requirement).
Handoff after U-R.8: next U-C.1. Context ~30k.
