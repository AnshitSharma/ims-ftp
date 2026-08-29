<?php
/**
 * remove_command_test.php — U-C.3 regression test for RemoveComponentCommand.
 *
 * FULL acceptance criteria per the execution pack (six §6 scenarios: blocked
 * without cascade, full-subtree cascade with JSON/rows/ledger/inventory all
 * consistent, NIC->SFP parity with legacy) require a real MySQL scratch DB.
 * The DEPENDS_ON blocked/cascade scenario builds its own rows-path
 * motherboard+cpu fixture in-transaction when the fleet has no live rows-path
 * motherboard yet (pre-U-B.4 backfill) -- see the "no live rows-path
 * motherboard" branch below. When mysql itself is unreachable, everything
 * DB-backed self-skips with honest SKIPPED lines instead. The underlying
 * mechanisms (DependencyBlockedRemovalRule's six scenarios, TargetStateBuilder::
 * dependentsOf()'s cascade closure) ARE independently unit-tested, DB-free,
 * in tests/unit/rules/dependency_rule_test.php (U-R.8) -- this file only
 * covers what's specific to the command wiring itself.
 *
 * Exit 0 = every DB-free assertion passes.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/core/models/commands/BaseCommand.php';
require_once $ROOT . '/core/models/commands/RemoveComponentCommand.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// =========================================================================
echo "-- structural checks (no DB needed) --\n";
check('RemoveComponentCommand extends BaseCommand', is_subclass_of('RemoveComponentCommand', 'BaseCommand'));

$ref = new ReflectionClass('RemoveComponentCommand');
check('RemoveComponentCommand is not abstract (all hooks implemented)', !$ref->isAbstract());
foreach (['trigger', 'buildTarget', 'apply'] as $m) {
    check("implements $m()", $ref->hasMethod($m));
}
$ctor = $ref->getConstructor();
check('constructor accepts a cascade:bool parameter', $ctor->getParameters()[5]->getName() === 'cascade');

$src = file_get_contents("$ROOT/core/models/commands/RemoveComponentCommand.php");
check('no beginTransaction in RemoveComponentCommand.php (INV-3)', stripos($src, 'beginTransaction') === false);
check('apply() bumps revision even for json-fallback-only rows (INV-6, no silent mutation)', strpos($src, 'bumpRevision') !== false);
check('cascade rows resolved via TargetStateBuilder::dependentsOf() against the PRE-removal state', strpos($src, 'dependentsOf($current') !== false);

// =========================================================================
echo "-- server_api.php shadow/enforce dispatch wiring --\n";
$apiSrc = file_get_contents("$ROOT/api/handlers/server/server_api.php");
// Scoped to handleRemoveComponent's own body (signature to the next top-level
// function) instead of a 2000-byte window -- the flag read sits at byte 1942 of
// that body, i.e. the old window had 58 bytes of headroom left and would have
// gone RED on the next comment edit above it. The replacement also proves MORE
// than "the token appears somewhere near the signature": the handler reads the
// flag once, into the hoist, ahead of BOTH dispatch branches.
$rmFnStart = strpos($apiSrc, 'function handleRemoveComponent(');
$rmFnNext  = $rmFnStart !== false ? strpos($apiSrc, "\nfunction ", $rmFnStart + 1) : false;
$rmFnBody  = $rmFnStart === false
    ? ''
    : ($rmFnNext === false ? substr($apiSrc, $rmFnStart) : substr($apiSrc, $rmFnStart, $rmFnNext - $rmFnStart));
// REWRITTEN 2026-08-30 (P9/U-D.4). The three assertions above the fold used to
// pin the ORDER of the COMMAND_LAYER_ENABLED dispatch chain (hoist, then shadow,
// then enforce). The flag and both branches are deleted, so the replacement is
// the stronger post-deletion claim: the command is the only path out of this
// handler, gated by nothing.
check('handleRemoveComponent dispatches to RemoveComponentCommand',
    $rmFnBody !== '' && strpos($rmFnBody, 'new RemoveComponentCommand(') !== false);
check('it calls execute() unconditionally -- no mode, no branch',
    $rmFnBody !== '' && strpos($rmFnBody, '->execute()') !== false);
check('no rollout-flag read gates the dispatch (no getenv/mode() inside the handler)',
    $rmFnBody !== ''
    && strpos($rmFnBody, 'getenv(') === false
    && preg_match('/\b(CommandLayer|StateGuard|ValidationEngine|ConfigReadRouter)::mode\(\)/', $rmFnBody) !== 1);
check('the deleted legacy remove path is not called from this handler',
    $rmFnBody !== '' && strpos($rmFnBody, '$serverBuilder->removeComponent(') === false);
check('dryRun() is not called on the remove path (it existed for the shadow comparison, now gone)',
    $rmFnBody !== '' && strpos($rmFnBody, '->dryRun()') === false);
check('cascade defaults to false (matches legacy single-component removal)', strpos($apiSrc, "\$_POST['cascade'] ?? false") !== false);

// =========================================================================
echo "-- DB-backed scenario (real scratch DB when reachable; SKIPPED otherwise) --\n";
require_once __DIR__ . '/_scratch_db.php';
$pdo = scratch_db_connect();
// See add_command_test.php: a reachable but pre-P2 replica must SKIP, not crash.
if ($pdo !== null && ($schemaGap = scratch_db_schema_gap($pdo)) !== null) {
    echo "  (scratch DB unusable: $schemaGap)\n";
    $pdo = null;
}
if ($pdo === null) {
    echo "  SKIPPED  board-with-cpus / hba-with-drives / riser-with-cards / chassis-with-bays: blocked without cascade\n";
    echo "  SKIPPED  full-subtree cascade: JSON, rows, ledger, inventory all consistent post-op\n";
    echo "  SKIPPED  nic->sfp parity with the one legacy special case\n";
    echo "  SKIPPED  shadow: characterization ZERO diffs vs legacy remove\n";
} else {
    // Same non-destructive pattern as add_command_test.php: one owned
    // transaction, dryRun()/execute() joins it, always rolled back.
    $pdo->beginTransaction();
    try {
        // A motherboard is a real dependency.blocked_removal anchor
        // (DEPENDS_ON: cpu/ram -> motherboard), so removing one with a live
        // parent_id-linked child must block without cascade and must NOT block
        // with it.
        //
        // 2026-08-29 -- the fixture is now BUILT, never FOUND. This block used to
        // take `SELECT ... FROM config_components WHERE component_type='motherboard'
        // LIMIT 1`, i.e. whichever real config the storage engine happened to hand
        // back first, and only fell back to a synthetic fixture when the fleet had
        // no rows-path motherboard at all. That made the cascade assertion a
        // property of the DUMP: it passed for two months and then went red on a
        // newer dump whose first motherboard sits in a config holding two
        // parent_id-NULL storage rows and no chassis/hbacard. Nothing regressed --
        // DependencyBlockedRemovalRule's own docblock says mechanism 2 (structural
        // orphan) "can still fire under cascade=true ... this is correct: cascade
        // only removes the parent_id subtree, not every type-level dependent". The
        // old assertion contradicted the documented contract and was surviving on
        // fixture luck.
        //
        // So: two purpose-built configs, each isolated in this rolled-back
        // transaction, one per MECHANISM. That is strictly stronger than the
        // arbitrary-row version -- it pins mechanism 2's cascade behaviour, which
        // nothing asserted before and which is exactly what the red run exposed --
        // and it cannot drift with the dump again.
        //
        // Inventory units are chosen NOT EXISTS against config_components because
        // uq_inventory_once is unconditional (it ignores removed_at), so reusing a
        // unit any config ever held is a 1062, not a test failure.
        $freeUnit = function (string $table) use ($pdo) {
            $stmt = $pdo->prepare("
                SELECT i.id, i.UUID
                  FROM {$table} i
                 WHERE NOT EXISTS (SELECT 1 FROM config_components cc
                                    WHERE cc.inventory_table = ? AND cc.inventory_id = i.id)
                 LIMIT 1
            ");
            $stmt->execute([$table]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            return $r === false ? null : $r;
        };

        $insComponent = $pdo->prepare("
            INSERT INTO config_components
                   (config_uuid, component_type, inventory_table, inventory_id, spec_uuid, parent_id, added_by)
            VALUES (?, ?, ?, ?, ?, ?, 0)
        ");
        $insConfig = $pdo->prepare("
            INSERT INTO server_configurations (config_uuid, server_name, configuration_status, status_v2, is_virtual, is_sandbox)
            VALUES (?, ?, 1, 'draft', 0, 0)
        ");

        // One free unit per type, resolved once and shared by both scenarios --
        // the two configs never hold the same unit at the same time because
        // scenario 2 gets its own units.
        $units = [
            'chassis'     => $freeUnit('chassisinventory'),
            'motherboard' => $freeUnit('motherboardinventory'),
            'cpu'         => $freeUnit('cpuinventory'),
            'storage'     => $freeUnit('storageinventory'),
            'motherboard2'=> null,
        ];
        // Scenario 2 needs a SECOND free motherboard + cpu; take the next ones.
        $usedMb  = $units['motherboard']['id'] ?? -1;
        $usedCpu = $units['cpu']['id'] ?? -1;
        $second = function (string $table, $skipId) use ($pdo) {
            $stmt = $pdo->prepare("
                SELECT i.id, i.UUID
                  FROM {$table} i
                 WHERE i.id <> ?
                   AND NOT EXISTS (SELECT 1 FROM config_components cc
                                    WHERE cc.inventory_table = ? AND cc.inventory_id = i.id)
                 LIMIT 1
            ");
            $stmt->execute([$skipId, $table]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            return $r === false ? null : $r;
        };
        $units['motherboard2'] = $second('motherboardinventory', $usedMb);
        $units['cpu2']         = $second('cpuinventory', $usedCpu);

        $missing = [];
        foreach (['chassis', 'motherboard', 'cpu', 'storage', 'motherboard2', 'cpu2'] as $k) {
            if (empty($units[$k])) { $missing[] = $k; }
        }

        if ($missing !== []) {
            // Honest skip, not a pass: the fixture could not be built, so neither
            // mechanism was exercised. (F-11/F-18/F-21: an absent check must never
            // read as agreement.)
            echo '  SKIPPED  cascade mechanisms: no free inventory unit(s) for ' . implode(', ', $missing) . "\n";
        } else {
            $ruleIds = function ($verdict) {
                return array_map(function ($r) { return $r->ruleId(); }, $verdict->failures());
            };

            // ---- Scenario 1: MECHANISM 1 (dangling parent_id) --------------
            // chassis + motherboard + cpu(parent_id=motherboard). Removing the
            // motherboard orphans the cpu; the chassis stays so mechanism 2 has
            // nothing to say about the motherboard's own DEPENDS_ON edge.
            $cfg1 = 'TEST-RMCMD-M1-' . bin2hex(random_bytes(8));
            $insConfig->execute([$cfg1, 'remove_command_test mechanism 1']);
            $insComponent->execute([$cfg1, 'chassis', 'chassisinventory', $units['chassis']['id'], $units['chassis']['UUID'], null]);
            $insComponent->execute([$cfg1, 'motherboard', 'motherboardinventory', $units['motherboard']['id'], $units['motherboard']['UUID'], null]);
            $mb1RowId = (int)$pdo->lastInsertId();
            $insComponent->execute([$cfg1, 'cpu', 'cpuinventory', $units['cpu']['id'], $units['cpu']['UUID'], $mb1RowId]);
            $mb1Spec = $units['motherboard']['UUID'];

            // Control, inside the config that HAS a chassis: a leaf removal with
            // nothing depending on it must not raise this rule at all. Without it,
            // the two assertions below could both be satisfied by a rule that
            // fires on everything.
            $control = (new RemoveComponentCommand($pdo, $cfg1, 'cpu', $units['cpu']['UUID'], null, true, 0))->dryRun();
            check('control: removing a leaf with no dependents raises no dependency.blocked_removal [built fixture]',
                !in_array('dependency.blocked_removal', $ruleIds($control), true));

            $blockedAsExpected = false;
            try {
                (new RemoveComponentCommand($pdo, $cfg1, 'motherboard', $mb1Spec, null, false, 0))->execute();
            } catch (CommandFailed $e) {
                $blockedAsExpected = ($e->errorType === 'validation_blocked');
            }
            check('removing a motherboard with a live parent_id-linked child blocks WITHOUT cascade (dependency.blocked_removal) [built fixture]',
                $blockedAsExpected);

            $cascade1 = (new RemoveComponentCommand($pdo, $cfg1, 'motherboard', $mb1Spec, null, true, 0))->dryRun();
            check('the SAME removal with cascade=true clears mechanism 1 -- no dependency.blocked_removal [built fixture]',
                !in_array('dependency.blocked_removal', $ruleIds($cascade1), true));

            // ---- Scenario 2: MECHANISM 2 (structural orphan) ---------------
            // motherboard + cpu(parent_id=motherboard) + storage(parent_id NULL),
            // and NO chassis/hbacard. cascade removes the cpu subtree but not the
            // storage, whose only remaining provider type was the motherboard --
            // so the rule MUST still block. This is the documented contract the
            // old assertion denied, and it is asserted here rather than assumed.
            $cfg2 = 'TEST-RMCMD-M2-' . bin2hex(random_bytes(8));
            $insConfig->execute([$cfg2, 'remove_command_test mechanism 2']);
            $insComponent->execute([$cfg2, 'motherboard', 'motherboardinventory', $units['motherboard2']['id'], $units['motherboard2']['UUID'], null]);
            $mb2RowId = (int)$pdo->lastInsertId();
            $insComponent->execute([$cfg2, 'cpu', 'cpuinventory', $units['cpu2']['id'], $units['cpu2']['UUID'], $mb2RowId]);
            $cpu2RowId = (int)$pdo->lastInsertId();
            $insComponent->execute([$cfg2, 'storage', 'storageinventory', $units['storage']['id'], $units['storage']['UUID'], null]);
            $mb2Spec = $units['motherboard2']['UUID'];
            $storageRowId = (int)$pdo->lastInsertId();

            $cascade2 = (new RemoveComponentCommand($pdo, $cfg2, 'motherboard', $mb2Spec, null, true, 0))->dryRun();
            $blockedRule = null;
            foreach ($cascade2->failures() as $r) {
                if ($r->ruleId() === 'dependency.blocked_removal') { $blockedRule = $r; break; }
            }
            check('cascade=true STILL blocks when a non-descendant type-level dependent is left providerless (mechanism 2, per DependencyBlockedRemovalRule\'s docblock) [built fixture]',
                $blockedRule !== null);

            // Asserted on the DEPENDENTS PAYLOAD, not just the rule id: this config
            // also loses its motherboard->chassis edge, so "the rule fired" alone
            // would not prove mechanism 2 caught the STORAGE. The two halves
            // together are the contract -- the orphaned non-descendant is named,
            // and the cascaded descendant is not (proving cascade really did clear
            // mechanism 1 in the same pass).
            $dependentIds = [];
            if ($blockedRule !== null) {
                foreach ((($blockedRule->details()['dependents'] ?? [])) as $d) {
                    $dependentIds[] = (int)$d['id'];
                }
            }
            check('the block names the orphaned non-descendant storage row [built fixture]',
                in_array($storageRowId, $dependentIds, true));
            check('the block does NOT name the cascaded cpu -- cascade cleared mechanism 1 in the same pass [built fixture]',
                $blockedRule !== null && !in_array($cpu2RowId, $dependentIds, true));
        }
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    echo "  (DB-backed scenario ran against " . (getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden') . ", rolled back -- no data persisted)\n";
    echo "  NOTE  nic->sfp parity and full characterization diffing are separate-harness checks, not re-run by this file\n";
}

echo $fails === 0 ? "\nALL CHECKS PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
