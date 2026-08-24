# REMOVED 2026-08-24 — `OnboardNICHandler::replaceOnboardNIC()`

**Unit:** Commit 0 (U-D.2 cleanup pack)
**File:** `ims-ftp/core/models/compatibility/OnboardNICHandler.php`, lines 449–575 (127 lines incl. docblock)
**Gate evidence:** `server-debug-deadcode` against the DEPLOYED tree reported this symbol
GREEN — `blocking_callers: 0`, `internal_callers: 0`. Independently re-confirmed by a
tree-wide grep at deletion time: the only surviving occurrences were three explanatory
comments (`:105`, `:390`, `:410`) and documentation; zero invocation sites in any `.php`
or `.js` file in the monorepo.

**Why it went:** superseded by `ReplaceComponent` (U-C.4). This was the codebase's only
replace path and it performed **zero compatibility validation** (audit finding RP-2);
`ReplaceComponent` reimplements it as a first-class command with one TargetState and one
commit, and does not inherit the validation-free path.

**Why this archive exists:** the monorepo is not under version control, and `ims-ftp/`
auto-uploads to production on save, so the delete would otherwise be unrecoverable.
`*.md` is in the SFTP ignore list, so this file never deploys. To restore, paste the
block below back in ahead of the class-closing brace.

The two `BUGFIX` comments below (A-L7 model-vs-unit conflation, TP-4C onboard-NIC
disable-instead-of-delete) record real production defects. Their invariants live on in
the `nicinventory` handling elsewhere in this class and in `ReplaceComponent`; they are
preserved here so the reasoning is not lost with the code.

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
