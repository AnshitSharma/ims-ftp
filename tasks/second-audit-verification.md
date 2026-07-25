# Second Audit — Verification & Remediation Plan

Verification of an independent audit (findings A–U + roadmap items 1–15) against
the code at HEAD (`3749154`).

**Key framing:** the auditor read `be76de0`, i.e. the commit *before* the first
remediation pass. Their line numbers (8412, 8417, 8451, 8485, 811-812) match
`be76de0` exactly and no longer resolve at HEAD. Several findings are therefore
already closed. Each is marked below with the evidence.

---

## Verdict table

| # | Finding | Verdict | Where it stands |
|---|---|---|---|
| A | `validateComponentQuantity()` under-counts multi-quantity entries | **REAL — already fixed** | `sumEntryQuantities()` at 8880/8931/8969 (A-L8) |
| B | Array-to-string in RAM slot message | **REAL — already fixed** | `$usedSlots` interpolated, 8896 |
| C | Multi-quantity slotted cards collapse into one slot | **REAL — mostly fixed** | quantity>1 rejected for nic/pciecard/hbacard at 996. Residual: no `nic`/`hbacard` case in the switch — but backstopped, see below |
| D | Riser-provided PCIe slots never counted as occupied | **REAL — OPEN, highest severity** | Confirmed live. See analysis below |
| E | No riser/motherboard removal cascade | **REAL — OPEN** | `validateRiserRemoval()` has zero callers fleet-wide |
| F | Onboard-NIC creation non-atomic with motherboard add | **REAL — already fixed** | side effects moved above `commit()` at 1117–1175 (A-L6) |
| G | Finalize validation weaker than add-time | **REAL — OPEN (by design, in-migration)** | Code self-labels it "legacy weak check" at 4079 |
| H | ACL wildcard over-grants `users.*` / `roles.*` | **REAL — OPEN** | `'*.create'` → `LIKE '%.create'` matches `users.create` |
| I | RateLimiter TOCTOU | **REAL — OPEN** | No lock across read-modify-write; truncation → silent counter reset |
| J | Password-reset token consumption non-atomic | **REAL — OPEN** | No transaction, check-then-act on `used_at` |
| K | Revocation bypass in `getUserIdFromToken()`/`refreshToken()` | **OVERSTATED — dead code** | See analysis below |
| L | `base64UrlDecode()` padding inert | **REAL — OPEN (latent)** | `str_pad($d, strlen($d) % 4, ...)` — target ≤ current, no-op |
| M | `getTokenFromHeader()` case-sensitive | **REAL — OPEN (latent)** | `$headers['Authorization']` exact-key |
| N | `overall_score` can go negative | **REAL — OPEN (cosmetic)** | `-= 0.2` at 3800, only `min()` at 3919, no floor |
| O | addComponent reloads config ~5–6× | **REAL — partially fixed** | 882-883 now use `$lockedConfigRow`; re-SELECTs remain at 832, 8851 |
| P | `getSlotAvailability()` loads config 4× | **REAL — OPEN** | 4 × `loadByUuid` confirmed by call-graph count |
| Q | Duplicate full compatibility passes per add | **REAL — OPEN** | |
| R | `extractComponentsFromJson()` re-decodes every call | **REAL — OPEN** | |
| S | `getComponentTypeByUuid()` probes every inventory table | **REAL — OPEN (overstated)** | Probes **3** tables (pciecard/hbacard/nic), not all 10 |
| T | Compatibility scores computed twice | **REAL — OPEN (negligible)** | 3909/3912 and 3910/3913 — pure in-memory walks |
| U | Per-request `last_used_at` write | **REAL — already fixed** | Moved to `verifyRefreshToken()`, token-scoped (A-L15) |
| 15 | Duplicated `$serverRackPosition` assignment | **REAL — already fixed** | Existed at `be76de0`:811-812, gone at HEAD (L22) |

Net: **8 already fixed**, **1 overstated**, **13 genuinely open**.

---

## Analysis of the two findings whose severity I disagree with

### D is the real headline — and it is worse than the auditor stated

Three slot-ID namespaces exist, and only two are handled consistently:

1. Motherboard PCIe slots — `pcie_x16_slot_1` (minted ~1055/1092 region)
2. Motherboard riser **bays** — `riser_x16_slot_1` (1055, 1092)
3. Riser-**provided** PCIe slots — `riser_{riserUuid}_pcie_x16_slot_1` (1540)

`getSlotAvailability()` merges namespace 3 into `$mergedSlots` (lines 76–90), so
`assignSlot()` *will* hand out a namespace-3 slot ID. But:

- `getUsedPCIeSlots()` filters on `strpos($pos, 'pcie_') === 0` at **1148, 1164,
  1184, 1200** → namespace-3 occupancy is invisible.
- `calculateAvailableSlots()` does `array_diff($slotIds, array_keys($usedSlots))`
  → riser-provided slots are **always** reported free.
- `getUsedRiserSlots()` filters on `strpos($pos, 'riser_') === 0` at **1253**,
  which matches namespace 3 **and** namespace 2 → the same card is counted as
  consuming a motherboard riser bay it does not occupy.

**The compounding fact the auditor missed:** the C4 fix makes
`assignComponentSlot()` *blocking* on slot exhaustion (5417–5425) — that is the
sole guard preventing PCIe over-subscription, because `validateComponentQuantity()`
has no `nic`/`hbacard` case. D defeats exactly that guard for riser-provided
slots, since availability there is computed from the broken `$usedSlots`. So D is
not merely "wrong accounting" — it is an **unbounded over-allocation hole for any
config containing a riser**, and it silently disarms the C4 protection.

This also reframes C's residual: the missing switch cases are redundant
defence-in-depth *only while D is fixed*. Fix D first.

### K is dead code, not a live bypass

The auditor's impact claim — "any authz decision routed through
`getUserIdFromToken()` ... honors revoked tokens" — does not hold, because there
are no such call sites:

- `JWTHelper::refreshToken()` ← `refreshJWTToken()` (JWTAuthFunctions.php:203) ← **zero callers**
- `JWTHelper::getUserIdFromToken()` ← `getUserIdFromJWT()` (:194) ← `logoutJWT()` (:218) ← **zero callers**

The reachable refresh endpoint, `handleTokenRefresh()` (auth_api.php:217), uses
`JWTHelper::verifyRefreshToken($pdo, ...)` — DB-backed, enforces expiry and
`u.status = 'active'`. It never touches `JWTHelper::refreshToken()`.

Correct remedy is **deletion of the dead chain**, not threading `$pdo` through it.
Adding `$pdo` would preserve three unreachable functions and imply they are
supported. If they must be kept, they should throw rather than silently skip
revocation.

---

## Remediation plan

Ordered so that each phase is independently shippable and independently
revertable. Phases 1–2 are the only ones that touch the compatibility engine.

### Phase 1 — D: riser-provided slot accounting (compatibility engine) — **DONE**

**New finding D2, discovered while implementing Phase 1 (neither audit caught it):**
`validateRiserSlotIntegrity()` gated its "does this card reference a real riser?"
check on the bare `riser_` prefix, then applied the namespace-3 regex
`/^riser_([a-z0-9-]+)_pcie_/`. A motherboard riser bay (`riser_x16_slot_1`)
matches the prefix but can never match the regex — it carries `_slot_` where the
pattern requires `_pcie_` — so it fell to the `else` and reported
**"invalid riser slot format" for every riser card sitting exactly where it
belongs.** Riser-slot integrity validation failed on *correct* configurations.
Fixed by gating on `isRiserProvidedPcieSlot()`, with a separate positive
format check (`/^riser_x\d+_slot_\d+$/i`) preserving genuine malformed-id
detection.

Implemented: three private static discriminators on `UnifiedSlotTracker`
(`isRiserProvidedPcieSlot` / `isPcieSlotPosition` / `isRiserBaySlot`);
converted `getUsedPCIeSlots()` (4 sites), `getUsedRiserSlots()`, the riser
count in `validateAllSlots()`, `getRiserCardsInConfig()`, and
`validateRiserSlotIntegrity()`. Covered by `tests/unit/slot_namespace_test.php`
(25 checks, DB-free, all pass).

**Deliberately left unchanged:** the `pcie_`-prefix test in `validateAllSlots()`
Check 5 ("verify no risers in PCIe slots"). Extending it to namespace 3 would
newly flag riser-on-riser nesting — a validation *expansion* that could raise
errors on existing configs, which is a separate judgment call, not part of
fixing D.

Add three private discriminators to `UnifiedSlotTracker` and route every
slot-namespace test through them:

```php
private static function isRiserProvidedPcieSlot($slotId) {
    return (bool)preg_match('/^riser_[a-z0-9-]+_pcie_/i', (string)$slotId);
}
private static function isPcieSlotPosition($slotId) {
    return strpos((string)$slotId, 'pcie_') === 0
        || self::isRiserProvidedPcieSlot($slotId);
}
private static function isRiserBaySlot($slotId) {
    return strpos((string)$slotId, 'riser_') === 0
        && !self::isRiserProvidedPcieSlot($slotId);
}
```

Namespace 2 (`riser_x16_slot_1`) cannot false-positive against the
riser-provided regex: it has `_slot_` where the pattern requires `_pcie_`.
UUIDs are hex+hyphen so they never contain `_`. The discriminator is total.

Call sites to convert:
- `getUsedPCIeSlots()` → `isPcieSlotPosition()` at 1148, 1164, 1184, 1200
- `getUsedRiserSlots()` → `isRiserBaySlot()` at 1253
- Audit and convert 531, 612, 1475, 2028 for the same confusion (2028 already
  uses the correct regex — align it to the shared helper rather than duplicating)

**Behavior change this intentionally causes:** configs with risers will report
fewer available PCIe slots (correct) and more available riser bays (correct).
Adds into an already-full riser will begin to be rejected. Existing
over-allocated configs become visible. This is the point of the fix, but it is a
user-visible change to `get-compatible` output and must be called out in the PR.

### Phase 2 — C residual: nic/hbacard capacity cases

Add `nic` and `hbacard` cases to the `validateComponentQuantity()` switch, both
budgeting against total PCIe slots via `sumEntryQuantities()`, mirroring the
existing `pciecard` case. Strictly defence-in-depth behind the C4 block —
sequence it *after* Phase 1 so it budgets against corrected totals.

### Phase 3 — E: removal dependency cascade

- Call the existing `validateRiserRemoval()` in `removeComponent()` before
  removing a `pciecard` whose subtype is `Riser Card`; block on failure.
- For `motherboard` removal, block when socket/slot-dependent components remain
  (cpu / ram / pciecard / nic / hbacard), rather than silently orphaning them.
  Blocking, not cascading — cascade would mass-release inventory from a single
  call, which is the class of defect the first pass spent its time removing.

### Phase 4 — auth hardening (independent of the engine)

- **I** — `RateLimiter::attempt()` / `hit()`: hold one `flock(LOCK_EX)` across
  open → read → filter → append → `ftruncate` → write → unlock. `loadAttempts()`
  takes `LOCK_SH`. Fixes both the throttle bypass and the truncation-induced
  counter reset.
- **J** — `handleResetPassword()`: wrap in a transaction; consume via
  `UPDATE password_resets SET used_at = NOW() WHERE token = ? AND used_at IS NULL`
  and proceed only when `rowCount() === 1`.
- **H** — replace `'*.create'` / `'*.edit'` for `manager` with explicit component
  permissions, or exclude the `users.` / `roles.` namespaces from the LIKE
  expansion. Verify production seeders do not reproduce the pattern before
  changing bootstrap-only code — if prod roles were seeded by SQL, this fix
  changes nothing live and a corrective seeder is required instead (hard rule #2).
- **K** — delete `refreshJWTToken()`, `getUserIdFromJWT()`, `logoutJWT()`,
  `JWTHelper::refreshToken()`, `JWTHelper::getUserIdFromToken()`. Confirm zero
  callers at deletion time.
- **L** — `str_pad($data, strlen($data) + (4 - strlen($data) % 4) % 4, '=', STR_PAD_RIGHT)`
- **M** — `array_change_key_case($headers)` plus `$_SERVER['HTTP_AUTHORIZATION']`
  and `REDIRECT_HTTP_AUTHORIZATION` fallbacks.

### Phase 5 — correctness cleanup

- **N** — clamp with `max(0.0, min(1.0, ...))` at 3919.
- **T** — hoist the two duplicated score calls into locals at 3909–3913.

### Phase 6 — performance (no behavior change)

**O**, **P**, **R**, **Q**, **S** — thread the already-locked `$lockedConfigRow`
into the validators; load the config once at the top of `getSlotAvailability()`
and pass it down; memoize `extractComponentsFromJson()` per config+revision for
the request; collapse the duplicate legacy compatibility passes; resolve
component type from the config JSON's `component_type` instead of table-probing.

Sequence last: these are pure refactors whose only risk is introducing a stale
read, and they are far easier to prove safe once the correctness fixes above are
already baselined.

---

## Does this make compatibility worse?

**Phases 1–3 touch the compatibility engine and cannot ship on assertion alone.**
Per CLAUDE.md, any refactor of the compatibility engine must be proven at parity
against `tests/golden/compatibility_baseline.json`, with intended diffs
explicitly reviewed.

Phase 1 is *designed* to produce golden diffs — every diff should be a config
containing a riser, and every one should move in the direction of "fewer PCIe
slots free / more riser bays free". Any diff on a riser-less config is a
regression and blocks the phase.

Requirements before merge:
1. Capture a fresh baseline on the scratch DB at current HEAD.
2. Re-run after each of phases 1, 2, 3 separately — not as one batch — so diffs
   attribute to a single change.
3. Phases 4–6 must show **zero** golden diff.

**I still cannot run these here.** There is no MySQL/MariaDB binary in this
environment, so every DB-backed suite — including the golden master — is
unrunnable. That constraint carried over from the first pass and is unchanged.
The DB-free suites (including `tests/unit/component_entry_identity_test.php`)
remain runnable and must stay green.

## Risk notes

- Phase 1 changes `get-compatible` responses for riser configs. Coordinate with
  whoever owns the frontend before deploy.
- Phase 3 will start refusing motherboard removals that previously succeeded.
  Confirm no operational workflow depends on the current permissive behavior.
- Phase 4/H may require a seeder rather than a code change; determine which
  before writing either.
- Every save auto-deploys to production ~20s later. These phases must land as
  discrete, individually-complete commits — never a half-applied namespace
  conversion in Phase 1.
