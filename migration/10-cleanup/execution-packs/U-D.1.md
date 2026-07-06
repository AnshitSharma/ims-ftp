# U-D.1 — Delete duplicate validation paths
Pins baseline: yes (ZERO diffs — paths already unreachable at enforce). Invariants: INV-2, INV-10, INV-11.

## Inputs
deadcode contract 11-verification §deadcode; targets:
ServerBuilder::validateComponentCompatibility 4631 (Phase 1.5 pairwise) + its call site ~515;
ComponentCompatibility::checkComponentPairCompatibility callers (verify compatibility_api check_pair
still uses it — if yes it STAYS, only ServerBuilder's loop goes); the U-C.2/C.3 shadow dispatch
blocks (enforce is permanent now).

## Files Created (1) / Modified (≤3)
scripts/verify/deadcode_report.php (contract per 11-verification) then delete: Phase 1.5 block +
method (if zero other callers), shadow branches. 

## Tests
deadcode GREEN pre-delete for each symbol; post: php -l tree, characterization ZERO diffs, run_all --quick GREEN.

## Rollback / Checklist
git revert. - [ ] check_pair API preserved if externally used - [ ] Diff is purely deletions + dispatch simplification
