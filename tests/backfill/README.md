# tests/backfill — intentionally empty

The JSON→rows backfill is finished and its subjects are gone. U-D.3c dropped the nine
legacy JSON columns from `server_configurations` on 2026-08-30, so every suite that lived
here was testing code that can no longer run:

| Deleted | Its subject |
|---|---|
| `extractor_test.php` | `scripts/backfill/Extractor.php` — decoded the dropped columns |
| `ledger_backfill_test.php` | `scripts/backfill/backfill.php` — the one-time JSON→rows copy |
| `_ud3b_reader_parity.php` | U-D.3b's gate: every reader's answer vs the columns' own. It passed, then the columns went. |

All three are in git history if the reasoning is ever needed.

**This directory stays, and stays listed in `run_tests.php`'s `SUITE_DIRS`, on purpose.**
That list was once a hand-maintained set of names and `tests/backfill/` was missing from it
for months, so suites here were discovered by nothing and proved nothing (see
`tests/MANIFEST.md`). Discovery is a glob now; a glob over an empty directory is correct and
silent, whereas removing the entry would put the same drift back. Drop a new suite in here
and it runs.
