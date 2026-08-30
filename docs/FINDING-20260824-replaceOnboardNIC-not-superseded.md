# FINDING 2026-08-24 — `OnboardNICHandler::replaceOnboardNIC()` is NOT dead, and NOT superseded

**Verdict: DO NOT DELETE. Commit 0's premise is wrong for this symbol.**

**Unit:** Commit 0 (U-D.2 cleanup pack) · **Symbol:** `replaceOnboardNIC()`,
`core/models/compatibility/OnboardNICHandler.php:449-575` (127 lines incl. docblock)

## What the gate said

`server-debug-deadcode` against the deployed tree reports this symbol **GREEN** —
`blocking_callers: 0`, `internal_callers: 0`. A tree-wide grep confirms it independently:
the only surviving occurrences are three explanatory comments (`:105`, `:390`, `:410`)
and documentation. Zero invocation sites in any `.php` or `.js` file in the monorepo.

That is all true, and it is not sufficient.

## What the gate cannot see

The scanner asks one question: *does any file name this symbol?* It cannot ask the
question that decides whether deletion is safe: **does anything still depend on the state
this code is the only producer of?**

Here it does. `replaceOnboardNIC()` contains the sole `Flag = 'replaced'` write in the
entire codebase (`OnboardNICHandler.php:530`). Three surviving branches *read* that flag
and exist only to honour it:

| site | what it does | becomes if the writer is deleted |
|---|---|---|
| `OnboardNICHandler.php:108` | skips a `'replaced'` port when a motherboard is re-added, so replacing a port is not silently undone | permanently false |
| `OnboardNICHandler.php:420` | `Status = CASE WHEN Flag='replaced' THEN Status ELSE 1 END` on detach | `ELSE` branch always |
| `OnboardNICHandler.php:421` | same for `status_v2` | `ELSE` branch always |

Deleting the producer does not make those branches dead in a way the gate would ever
report — they stay syntactically reachable and semantically unreachable forever. The gate
would then rate them GREEN too, on the same reasoning, and the TP-4C invariant would be
deleted in pieces, each piece individually justified.

## Why the supersession claim is false

`IMS_TARGET_ARCHITECTURE.md:226` states that `replaceOnboardNIC` *"is reimplemented as a
ReplaceComponent specialization and loses its validation-free path"*. That reimplementation
**did not happen**. `ReplaceComponentCommand.php:105-107` reads:

```php
if (in_array($this->componentType, ['nic','pciecard','hbacard'], true)
    && strpos($this->newComponentUuid, 'onboard-') !== 0
) {
```

Onboard components are explicitly **excluded**. `ReplaceComponentCommand` cannot replace an
onboard NIC; `onboard` appears nowhere else in that file. So the capability was never
ported — and because nothing calls `replaceOnboardNIC()` either, **the capability is
already unreachable from the API today.**

That is the actual finding. This is not dead code awaiting cleanup; it is a **regression
that already shipped**, and this function is the last surviving specification of the
behaviour. Deleting it would erase the evidence and the reference implementation in the
same stroke, converting a recoverable gap into an invisible one.

## Current data exposure

Checked the 2026-08-24 production dump: **zero** `nicinventory` rows carry
`Flag = 'replaced'`. So no live row currently depends on the consumers above. The exposure
is capability loss, not data corruption — which is why this is a finding and not an incident.

## Recommended disposition (owner decision)

1. **Keep the code.** Mark the manifest entry `retain: true` with this file as the reason,
   so the gate stops proposing it and the next reader learns why in one hop.
2. **File the real unit**: either extend `ReplaceComponentCommand` to handle `onboard-`
   NICs (the genuine U-C.4 completion), or record an explicit product decision that
   onboard-NIC replacement is withdrawn — in which case the three consumer branches and
   the `Flag='replaced'` vocabulary come out *together*, as one reviewed change.
3. **Harden the gate.** A symbol that is the only writer of a persisted state other code
   reads is not deletable on a name-reference count. Sole-writer detection belongs in
   `deadcode_scan.php`, or at minimum in the manifest as a declared hazard.

Point 3 is the durable lesson: this is the same fail-open family as the rest of the
migration — a check that returned a verdict because it could not see the thing that mattered.

## The source, preserved

Kept verbatim below so this finding stands alone even if the code later moves. The two
`BUGFIX` comments record real production defects (A-L7 model-vs-unit conflation, TP-4C
disable-instead-of-delete).
```php
    /**
     * Replace an onboard NIC with a component NIC
     *
     * @param string $configUuid
     * @param string $onboardNICUuid
     * @param string $componentNICUuid
     * @return array Result of replacement operation
     */
    public function replaceOnboardNIC($configUuid, $onboardNICUuid, $componentNICUuid) {
        // BUGFIX (A-L7): beginTransaction() was unconditional, so any caller that
        // already held a transaction got "There is already an active transaction" --
        // and the catch below then called rollBack() unconditionally, which throws
        // "no active transaction" OUT of the catch block. Honour an ambient
        // transaction the way every other mutation in this codebase does.
        $ownTransaction = false;

        try {
            $ownTransaction = !$this->pdo->inTransaction();
            if ($ownTransaction) {
                $this->pdo->beginTransaction();
            }

            // Verify onboard NIC exists and belongs to this config
            $stmt = $this->pdo->prepare("
                SELECT SourceType, ParentComponentUUID, OnboardNICIndex
                FROM nicinventory
                WHERE UUID = ? AND ServerUUID = ? AND SourceType = 'onboard'
            ");
            $stmt->execute([$onboardNICUuid, $configUuid]);
            $onboardNIC = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$onboardNIC) {
                throw new Exception("Onboard NIC not found or doesn't belong to this configuration");
            }

            // Resolve and LOCK exactly ONE available physical unit of the replacement NIC.
            //
            // BUGFIX (A-L7): this used to SELECT without a lock and then
            // `UPDATE ... WHERE UUID = ?`. UUID is the MODEL identifier, shared by every
            // physical unit of that NIC, so a single replacement flipped EVERY unit of the
            // model to Status=2 and stamped this config's ServerUUID on all of them --
            // including units installed in other servers. Same model-vs-unit conflation
            // fixed in deleteConfiguration() and removeComponent(); this call site was
            // missed. The unlocked read was also a TOCTOU window between the Status check
            // and the write.
            $stmt = $this->pdo->prepare("
                SELECT ID, UUID, Status, SerialNumber
                FROM nicinventory
                WHERE UUID = ? AND SourceType = 'component' AND Status = 1
                ORDER BY ID ASC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$componentNICUuid]);
            $componentNIC = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$componentNIC) {
                // Distinguish "no such model" from "all units already in use" so the
                // caller gets an actionable message.
                $probe = $this->pdo->prepare("
                    SELECT COUNT(*) FROM nicinventory WHERE UUID = ? AND SourceType = 'component'
                ");
                $probe->execute([$componentNICUuid]);
                if ((int)$probe->fetchColumn() === 0) {
                    throw new Exception("Component NIC not found in inventory");
                }
                throw new Exception("No available unit of component NIC $componentNICUuid (all units are in use or failed)");
            }

            // Note: No need to delete from server_configuration_components as it's deprecated

            // Mark the onboard NIC as DISABLED instead of deleting it.
            // BUGFIX (TP-4C): onboard NICs are physically part of the motherboard and
            // cannot be removed from inventory. Deleting the row lost the
            // disabled/replaced state (and the onboard linkage), so if the motherboard
            // was later removed and re-added, the synthetic onboard NIC was regenerated
            // as if it had never been replaced. We keep the row, preserving SourceType /
            // ParentComponentUUID / OnboardNICIndex, and flag it disabled (Status=0).
            $stmt = $this->pdo->prepare("
                UPDATE nicinventory
                SET Status = 0,
                    Flag = 'replaced',
                    Notes = CONCAT(COALESCE(Notes, ''), ' | Disabled: replaced by component NIC ', ?),
                    UpdatedAt = NOW()
                WHERE UUID = ?
            ");
            $stmt->execute([$componentNICUuid, $onboardNICUuid]);

            // Note: No need to add to server_configuration_components as it's deprecated
            // The nic_config JSON will be updated to reflect the replacement

            // Update the ONE locked component NIC unit to In Use. Keyed on the row's
            // primary key, never on the model UUID (A-L7).
            $stmt = $this->pdo->prepare("
                UPDATE nicinventory
                SET Status = 2, ServerUUID = ?, UpdatedAt = NOW()
                WHERE ID = ?
            ");
            $stmt->execute([$configUuid, (int)$componentNIC['ID']]);

            // Update nic_config JSON
            $this->updateNICConfigJSON($configUuid);

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return [
                'success' => true,
                'message' => 'Onboard NIC successfully replaced with component NIC',
                'replaced_onboard_nic' => $onboardNICUuid,
                'new_component_nic' => $componentNICUuid,
                'new_component_nic_inventory_id' => (int)$componentNIC['ID'],
                'new_component_nic_serial' => $componentNIC['SerialNumber']
            ];

        } catch (Exception $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Error in replaceOnboardNIC: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
```
