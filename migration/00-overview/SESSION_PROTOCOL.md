# SESSION PROTOCOL — follow literally, in order

You are one AI session implementing exactly one unit. You have no memory of previous sessions.
Everything you need is in files. Do not improvise. Do not "improve" things outside your unit.

## Step 1 — Orient (≈2k tokens)
1. Read `migration/00-overview/README.md`.
2. Read `migration/ARCHITECTURAL_INVARIANTS.md`.
3. Open `migration/phase-status.json`. Find the FIRST unit whose status is `not_started` or
   `in_progress` following the execution-order table, OR the unit the human named. Confirm its
   phase gate dependencies: every earlier phase in the order table must have `gate: "open"`.
   If not ⇒ STOP, report which gate is closed.
4. Set your unit to `in_progress` in phase-status.json (edit only that field).

## Step 2 — Load context (bounded)
1. Read your execution pack: `migration/<folder>/execution-packs/<UNIT>.md`.
2. Read ONLY the files and line ranges under its "Files To Read" section, in the order listed.
   Use view with line ranges. NEVER read a file the pack does not list. If you believe you need
   another file, that is a "blocked" condition — stop and write it in the handoff.
3. If a listed file/range does not match the pack's description (drift from earlier units),
   STOP ⇒ handoff status `blocked`, describe the mismatch.

## Step 3 — Pin behavior (INV-10)
If your pack marks `Pins baseline: yes`, run:
`php tests/characterize_compatibility.php` — must pass BEFORE you change anything.
If it fails before your change ⇒ blocked; the previous unit broke the build.

## Step 4 — Implement
- Stay inside "Files To Modify" / "Files To Create". Touching any other file is a violation.
- Copy code stubs from the pack verbatim where given; fill only marked gaps.
- New seeders: pair with rollback file (INV-9), test both directions against the scratch DB
  (`tests/golden/setup_scratch_db.sql` builds it).

## Step 5 — Verify
1. Run every command in the pack's "Acceptance Tests" section. All must pass.
2. Run the CHECK block of every invariant in the pack's "Invariants touched" list.
3. Run `php scripts/verify/run_all.php --quick` (exists after U-0.4; skip only in U-0.1..U-0.4).

## Step 6 — Close out (mandatory even if blocked)
1. Update phase-status.json: your unit ⇒ `implemented` (a later human/verify session sets `verified`),
   or `blocked` with nothing else changed.
2. Write `migration/handoffs/<UNIT>-<date>.md` from HANDOFF_TEMPLATE.md. Every field. No blanks.
3. Print the handoff file content as your final message.

## Forbidden at all times
- Starting a second unit "since I'm here".
- Committing with failing tests "to be fixed later".
- Adding TODO comments in place of specified behavior.
- Editing golden baselines without a `BASELINE-CHANGE:` justification quoted from your pack.
- Creating flags not in FLAGS.md (INV-12).
