# Validation Parity Report — <date> — commit <sha>
Mode: ENGINE_MODE=<shadow|enforce> · Sample: <N ops / M configs> · Window: <from>–<to>

## Summary
| Metric | Value |
|---|---|
| Operations compared | |
| Identical verdicts | |
| Diffs — expected (mapped to audit finding) | |
| Diffs — UNEXPLAINED (blocking) | |
| Engine exceptions (must be 0) | |

## Expected diffs (allowed only with a mapping)
| Op | Config | Legacy verdict | Engine verdict | Rule | Audit finding | Approved by |
|---|---|---|---|---|---|---|

## Unexplained diffs (any row here ⇒ gate stays closed)
| Op | Config | Legacy verdict | Engine verdict | Repro command |
|---|---|---|---|---|

## Rule coverage
| Rule id | Fired count | Legacy counterpart exercised | Notes |
|---|---|---|---|

Verdict: PASS / FAIL — gate <id> may/may not open.
