# Handoff — <UNIT-ID> — <YYYY-MM-DD>

## Current State
<one paragraph: what the codebase looks like NOW for this unit's concern>

## Completed Work
- <file>: <what changed, 1 line each>

## Remaining Work
- <empty if unit complete; otherwise exact next actions>

## Known Risks
- <anything the next session must not be surprised by>

## Invariant Check Results
| Invariant | Result |
|---|---|
| INV-x | PASS/FAIL |

## Acceptance Test Results
<command → exit code / summary, one per line>

## Next Prompt To Use
"Continue the IMS migration. Read migration/00-overview/README.md, migration/ARCHITECTURAL_INVARIANTS.md,
then execute unit <NEXT-UNIT> using migration/<folder>/execution-packs/<NEXT-UNIT>.md. Follow
migration/00-overview/SESSION_PROTOCOL.md exactly."

## Files To Load Into Context (next session)
- migration/00-overview/README.md (~90 lines)
- migration/ARCHITECTURAL_INVARIANTS.md (~120 lines)
- migration/<folder>/execution-packs/<NEXT-UNIT>.md
- <files+ranges that pack lists>

## Expected Context Size
~<N>k tokens (sum of above; keep under 60k)
