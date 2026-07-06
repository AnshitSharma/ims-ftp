# U-P.1 — CI invariant enforcement
Invariants: all (that's the point). 

## Files Created (2)
.github/workflows/invariants.yml (or the repo's CI equivalent — detect: `ls .github .gitlab-ci.yml`;
none exists ⇒ create scripts/ci/invariants.sh callable from any CI): runs every INV CHECK block from
ARCHITECTURAL_INVARIANTS.md + run_all.php --quick against scratch DB built from
tests/golden/setup_scratch_db.sql + all tests/regression/ + tests/unit/rules/.
scripts/ci/nightly.sh: full battery vs replica + report archiving (this is the cron U-X.2 installed;
formalize + alert-on-red hook stub).

## Tests
Run both scripts locally: GREEN. Intentionally violate INV-5 in a scratch branch: CI RED (proof).

## Checklist
- [ ] Every INV check runs verbatim from the invariants file (no paraphrase drift)
