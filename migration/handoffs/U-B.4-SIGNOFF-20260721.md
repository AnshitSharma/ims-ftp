# U-B.4 signoff — dual-write soak — 2026-07-21

Closes the 24h `DUAL_WRITE_ENABLED=on` soak opened 2026-07-19 ~11:37 UTC
(sixteenth session). Verdict: **PASS — U-B.4 `blocked` → `implemented`.**

Status is `implemented`, not `verified`, per this repo's self-certification
convention: the same effort that fixed and deployed the dual-write path also
ran its test, so no independent party has blessed it.

## ⚠ P2 gate stays CLOSED — this signoff does not open it

`phase-status.json`'s own rule: *"a phase's gate may be set to `open` only when
all its units are `verified` AND all reports in its `gate_reports` list exited
0."* After this signoff P2 reads:

| Unit | Status |
|---|---|
| U-B.1 | `implemented` |
| U-B.2 | `implemented` |
| U-B.3 | `verified` |
| U-B.4 | `implemented` ← this session |

**Three of four are not `verified`, so the gate stays closed.** U-B.4 was the
last *blocked* unit in P2 — that is what changed, and it is worth having — but
"unblocked" is not "gate open". The remaining precondition is an **independent
verification pass over U-B.1, U-B.2 and U-B.4**, done by someone other than
whoever implemented them, per the convention that keeps self-certified work at
`implemented`. That is a real work item nobody has scheduled; it is not a
formality and it is not time-gated, so it can start immediately and in parallel
with the P3 soak below.

The four `gate_reports` (`equivalence`, `orphan`, `ledger`, `inventory`) were
last run GREEN against real production data on 2026-07-15 (`tasks/todo.md`
step 8). They should be re-run as part of that verification pass, since the
dual-write path has genuinely fired in production since then — the conditions
under which they were blessed no longer hold.

## Soak window

| | |
|---|---|
| Clock started | 2026-07-19T11:30:50Z (`.env` mtime at the manual SFTP save) |
| Verified at | 2026-07-21T04:51:45Z |
| Elapsed | **41h 21m** (requirement: ≥24h) |
| Reverts observed | **0** |

Probed live via `server-debug-migration-flags` (superadmin JWT, production):

```
DUAL_WRITE_ENABLED    value=on   raw_seen="on"
STATE_MACHINE_ENABLED value=off  raw_seen=null
ENGINE_MODE           value=off  raw_seen=null
COMMAND_LAYER_ENABLED value=off  raw_seen=null
READ_FROM_ROWS        value=off  raw_seen=null
env_file.found=true   mtime=2026-07-19T11:30:50+00:00   php_sapi=litespeed
```

`.env` mtime is **byte-identical to the moment of the save 41h earlier**. This
is the evidence the soak existed to produce: the 15th session's blind-FTP-append
attempt reverted within 1–2 minutes, and both the owner's 2026-07-14 and
2026-07-16 attempts silently failed the same way. The VS Code SFTP path holds.
The append method was the weak link, not an external reverting process —
confirmed over 41h rather than the ~2.5 min originally tested.

Note the other four flags read `off` with `raw_seen=null` — correct and
intended. U-B.4's scope was `DUAL_WRITE_ENABLED` only.

## Real-traffic evidence

The soak requirement was real traffic, not another synthetic probe. Config
`2d66d58f-64ec-4896-93cb-e48295bad69a` ("Anshit server") was exercised through
the **UI** on 2026-07-20 17:18 UTC — mid-window, unrelated to this migration
(it was the onboard-NIC unit-identity `nic_config` rebuild). Via
`server-debug-config-dualwrite`:

| id | revision | event | component_type | component_id | created_at |
|---|---|---|---|---|---|
| 10085 | 1 | add | motherboard | 10001 | 2026-07-20 17:18:20 |
| 10086 | 2 | remove | motherboard | 10001 | 2026-07-20 17:18:33 |
| 10087 | 3 | add | motherboard | 10001 | 2026-07-20 17:18:39 |
| 10088 | 4 | remove | motherboard | 10001 | 2026-07-20 17:18:46 |

`config_components`: 1 row (`motherboard` / `motherboardinventory` / id 49),
correctly tombstoned on removal rather than deleted.

Revisions increment 1→4 with no gaps, add/remove pair correctly, and the
tombstone semantics match U-1.5's contract. **Dual-write fired correctly under
genuine user traffic**, not just under a scripted add/remove.

## Anomalies checked and cleared

1. **Config `bc90e06e` (created 2026-07-20 06:34, in-window) has zero
   `config_events` and zero `config_components`.** Not a divergence: verified
   via `server-get-config` that it is an **empty config** — created, never had a
   component added. The dual-write hooks are on component add/remove
   (`ServerBuilder.php:885`, `:1134`), not on config creation, so zero rows is
   the correct outcome.

2. **`actor: 0` on all four events.** Not a gap: deliberate and documented at
   `ServerBuilder.php:894` — "ServerBuilder has no authenticated-user context
   today; config_events.actor defaults to 0 for exactly this case." Worth
   revisiting when the command layer (which does carry actor context) takes
   over the write path, but it is not a U-B.4 defect.

3. **Configs `2d66d58f` / `b01a5f51` show `updated_at` inside the window from
   the 2026-07-20 seeder work.** Direct SQL, not app writes — no dual-write rows
   expected from those, and their absence is not a miss.

No engine exceptions, no FK violations, no partial writes observed in-window.
The `ServerBuilder::deleteConfiguration()` FK bug found in the 15th session
remains fixed and did not recur.

## Deviations from the plan

**Both diagnostic actions stay deployed** — `server-debug-migration-flags` and
`server-debug-config-dualwrite`, in `api/handlers/server/server_api.php`,
`server.view` ACL + code-gated to admin/super_admin. Step 20 of `tasks/todo.md`
called for removing them once U-B.4 closes; that is deliberately **not** done
here.

Rationale: two flag flips remain ahead (`STATE_MACHINE_ENABLED=shadow` for P3,
`COMMAND_LAYER_ENABLED=shadow`→`enforce` for P6/U-C.6) on the same `.env` file
that consumed roughly three weeks and several sessions before a write finally
stuck. These two actions are the **only** observability into what the live
process actually reads; removing them now would mean re-deriving that capability
the next time a flip appears not to take. Both are read-only and neither reads
`.env` content (mode strings + mtime only — the hard rule is intact).

**Remove them after the last flag flip is confirmed held**, i.e. as part of
U-C.6 / P9 cleanup, not now. Their own docblocks should be updated to say so.

## What this unblocks, and what it does not

P2 has no blocked units left. **No gate opens.** Remaining critical path:

- **P2** — needs the independent verification pass over U-B.1/U-B.2/U-B.4
  described above, plus a re-run of its four gate reports. Not time-gated;
  start it now, in parallel with the P3 soak.

Then two sequential 7-day soaks:

- **P3** — needs `STATE_MACHINE_ENABLED=shadow` for 7 days. Flag currently
  `off`/`raw_seen=null`; **the clock has not started.**
- **P6 / U-C.6** — needs `COMMAND_LAYER_ENABLED=enforce` for 7 days, which
  cannot begin until shadow lands first. Sequential, not parallel.
- **P7** — all three units now read `verified` in `phase-status.json`. Note that
  `09-cutover/U-X.1-PLAN-20260712.md` still describes U-A.2 as `implemented`
  and treats it as a blocker; **that plan doc is stale on this point** and was
  the basis of at least one incorrect readout this session. Trust
  `phase-status.json`. P7's gate is nonetheless still `closed` — its
  `gate_reports` (`regression`, `parity`) need a run.
- **P8 / P9 / P10** — all `not_started`, gated behind P7. Note U-X.1's plan
  (`09-cutover/U-X.1-PLAN-20260712.md`) already flags stale line numbers in its
  execution pack; re-derive at implementation time.

Floor estimate to full cutover: **~3 weeks wall clock assuming zero findings.**

## Companion change this session

`scripts/verify/expected_diffs.json` — added `_note_onboard_nic_fail_closed`
recording the `AddComponentCommand` fail-closed onboard-NIC divergence, a
precondition for `COMMAND_LAYER_ENABLED=enforce`. Filed as a `_note_*` key, not
an `entries` row: `entries` is strictly the ENGINE_MODE rule-diff matcher keyed
on `rule_id` (see `parity_report.php:56`), and this divergence is a
command-layer side effect that emits no `rule_id` and lands in a different JSONL
stream — a fabricated row would never match. Same posture as
`_note_dependency_blocked_removal`.

## Reproduction

```bash
TOK=$(curl -s -X POST "$API" -d "action=auth-login" \
  -d "username=superadmin" -d "password=password" | jq -r .data.tokens.access_token)

curl -s -X POST "$API" -H "Authorization: Bearer $TOK" \
  -d "action=server-debug-migration-flags"

curl -s -X POST "$API" -H "Authorization: Bearer $TOK" \
  -d "action=server-debug-config-dualwrite" \
  -d "config_uuid=2d66d58f-64ec-4896-93cb-e48295bad69a"
```

Verdict: **PASS for U-B.4 (`blocked` → `implemented`). P2 gate stays CLOSED**
pending independent verification of U-B.1/U-B.2/U-B.4.
