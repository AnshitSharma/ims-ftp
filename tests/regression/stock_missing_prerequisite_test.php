<?php
/**
 * stock_missing_prerequisite_test.php — regression test for the
 * missing-stock → inventory-add-prerequisite flow
 * (tasks: "Fit CPU" refused outright when no unit of the model exists).
 *
 * THE BEHAVIOUR UNDER TEST. Raising a request that fits a part into a server
 * used to be refused at create time whenever no unit of that model existed in
 * inventory — a dead end, because the Model dropdown lists every model in the
 * ims-data catalogue whether it is in stock or not. It now CREATES the request
 * and reports the gap, so the requester can raise the inventory record as a
 * prerequisite; approval stays the real boundary and still fails and rolls back
 * whole if the stock never arrives.
 *
 * The seam this protects is narrow and easy to widen by accident: only
 * 'inventory_component_not_found' may soften. 'component_not_found' (the part is
 * not in that CONFIGURATION) and 'component_unavailable' (the unit exists but is
 * failed or already in use) must stay hard refusals — more stock does not make
 * either request possible, and softening the second would let an approval be
 * spent on a part somebody else is holding.
 *
 * Structural checks (no DB needed), same convention as
 * location_aware_requests_test.php. Exit 0 = every assertion passes.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

function src($path) {
    global $ROOT;
    $full = "$ROOT/$path";
    return is_file($full) ? file_get_contents($full) : null;
}

// The frontend lives in a sibling checkout, not under ims-ftp.
function frontendSrc($path) {
    global $ROOT;
    $full = dirname($ROOT) . "/IMS-Frontend/$path";
    return is_file($full) ? file_get_contents($full) : null;
}

// =============================================================================
echo "-- the errorType separates 'no stock' from 'not in this configuration' --\n";
// =============================================================================
$add     = src('core/models/commands/AddComponentCommand.php');
$replace = src('core/models/commands/ReplaceComponentCommand.php');
$remove  = src('core/models/commands/RemoveComponentCommand.php');

check('AddComponentCommand exists', $add !== null);
check('ReplaceComponentCommand exists', $replace !== null);
check('RemoveComponentCommand exists', $remove !== null);

check("add's inventory miss throws inventory_component_not_found",
    $add !== null && strpos($add, "CommandFailed('inventory_component_not_found'") !== false);
check("add no longer throws the ambiguous component_not_found",
    $add !== null && strpos($add, "CommandFailed('component_not_found'") === false);

check("replace's REPLACEMENT miss throws inventory_component_not_found",
    $replace !== null
    && preg_match("/CommandFailed\('inventory_component_not_found',\s*\"Replacement component/", $replace) === 1);
check("replace's OLD-row miss still throws component_not_found (no stock fixes it)",
    $replace !== null
    && preg_match("/CommandFailed\('component_not_found',\s*\"Component to replace not found in configuration/", $replace) === 1);
check("remove's miss still throws component_not_found",
    $remove !== null
    && preg_match("/CommandFailed\('component_not_found',\s*\"Component not found in configuration/", $remove) === 1);

// The availability gate is the one most likely to be softened by mistake: it
// reads like "the part is not usable", which sounds adjacent to "not in stock".
$base = src('core/models/commands/BaseCommand.php');
check("assertInventoryAvailability still throws component_unavailable",
    $base !== null && strpos($base, "CommandFailed('component_unavailable'") !== false);

// =============================================================================
echo "-- preflight() defers on that one type and nothing else --\n";
// =============================================================================
$executor = src('core/models/pipelines/RequestActionExecutor.php');
check('RequestActionExecutor exists', $executor !== null);

check("preflight() special-cases inventory_component_not_found",
    $executor !== null && strpos($executor, "\$e->errorType === 'inventory_component_not_found'") !== false);
check('the deferred branch returns VALID, not an error',
    $executor !== null
    && strpos($executor, "return ['valid' => true, 'errors' => [], 'deferred' => \$gap];") !== false);
check('every other CommandFailed is still invalid',
    $executor !== null
    && preg_match("/return \['valid' => false, 'errors' => \[\\\$e->getMessage\(\)\]\];/", $executor) === 1);
check('a uuid the catalogue never heard of is still REFUSED',
    $executor !== null && strpos($executor, '!$this->isCataloguedModel(') !== false);
check('the catalogue check is the same one inventory insertion runs',
    $executor !== null && strpos($executor, "\$service->validateComponentUuid(\$componentType, \$uuid) === true") !== false);
check('the catalogue check fails CLOSED',
    $executor !== null
    && preg_match('/isCataloguedModel error.*\n\s*return false;/', $executor) === 1);
check('ComponentDataService is required rather than relied on transitively',
    $executor !== null && strpos($executor, "require_once(__DIR__ . '/../components/ComponentDataService.php');") !== false);
check('the gap is described by stockGap()',
    $executor !== null && strpos($executor, 'public static function stockGap(') !== false);
check("stockGap() reads new_component_uuid for a replace",
    $executor !== null && strpos($executor, "'server.component.replace' ? 'new_component_uuid' : 'component_uuid'") !== false);
check("stockGap() carries the serial number through",
    $executor !== null && preg_match("/'serial_number'\s*=> isset\(\\\$payload\['serial_number'\]\)/", $executor) === 1);

// Exactly one caller — a second one would inherit the soften without knowing.
check('preflight() still has exactly one caller (PipelineManager)',
    substr_count((string)src('core/models/pipelines/PipelineManager.php'), '->preflight(') === 1);

// =============================================================================
echo "-- createPipeline() reports the gap instead of refusing --\n";
// =============================================================================
$mgr = src('core/models/pipelines/PipelineManager.php');
check('PipelineManager exists', $mgr !== null);

check('deferred gaps are collected, not pushed into $errors',
    $mgr !== null && strpos($mgr, "if (!empty(\$check['deferred'])) {") !== false
    && strpos($mgr, "\$stockMissing[] = ['position' => \$index + 1] + \$check['deferred'];") !== false);
check('a hard refusal still skips the deferred branch',
    $mgr !== null && preg_match("/foreach \(\\\$check\['errors'\] as \\\$message\) \{[^}]*\}\s*continue;/s", $mgr) === 1);
check("the create result carries stock_missing",
    $mgr !== null && preg_match("/'stock_missing' => \\\$stockMissing,/", $mgr) === 1);
check('the gap is also written to the request timeline',
    $mgr !== null && strpos($mgr, "'stock_pending'") !== false);

// =============================================================================
echo "-- the standing flag is DERIVED on every read, never stored --\n";
// =============================================================================
check('stockMissingActions() exists',
    $mgr !== null && strpos($mgr, 'private function stockMissingActions(array $actions)') !== false);
check('getPipeline() derives stock_missing from the actions it just read',
    $mgr !== null && strpos($mgr, "\$pipeline['stock_missing'] = \$this->stockMissingActions(\$pipeline['actions']);") !== false);
check('only PENDING actions are probed',
    $mgr !== null && preg_match("/if \(\(\\\$action\['status'\] \?\? ''\) !== 'pending'\)/", $mgr) === 1);
check('the table name comes from ServerBuilder, not a hand-built string',
    $mgr !== null && strpos($mgr, '$sb->getComponentInventoryTable($type)') !== false
    && strpos($mgr, '$sb->isValidComponentType($type)') !== false);
check('a named serial narrows the probe, as lockAndCheckComponent() does',
    $mgr !== null && strpos($mgr, 'WHERE UUID = ? AND SerialNumber = ? LIMIT 1') !== false);
check('onboard pseudo-components are not treated as a gap',
    $mgr !== null && strpos($mgr, "strpos(\$gap['component_uuid'], 'onboard-') === 0") !== false);
check('it fails OPEN — a read must not break over a courtesy notice',
    $mgr !== null
    && preg_match("/stockMissingActions error[^\n]*\n\s*return \[\];/", $mgr) === 1);
check('ServerBuilder is required rather than relied on transitively',
    $mgr !== null && strpos($mgr, "require_once(__DIR__ . '/../server/ServerBuilder.php');") !== false);
check('nothing is written to the tickets table for it (no new column)',
    $mgr !== null && strpos($mgr, 'stock_missing =') === false);

// =============================================================================
echo "-- the handler passes it to the client --\n";
// =============================================================================
$handler = src('api/handlers/pipelines/pipeline-create.php');
check('pipeline-create.php exists', $handler !== null);
check('the 201 payload includes stock_missing',
    $handler !== null && strpos($handler, "'stock_missing' => \$result['stock_missing'] ?? []") !== false);
check('a created request is still reported as SUCCESS',
    $handler !== null && strpos($handler, 'send_json_response(true, true, 201') !== false);

// =============================================================================
echo "-- no seeder: the request type that performs the fix already ships --\n";
// =============================================================================
$typesSeeder = src('database/seeders/2026_08_23_004_action-request-types.sql');
check('2026_08_23_004 exists', $typesSeeder !== null);
check("a type whose ceiling is inventory.component.add is seeded",
    $typesSeeder !== null && strpos($typesSeeder, '{"action_types":["inventory.component.add"]}') !== false);

// =============================================================================
echo "-- the browser side: offer, prefill, standing notice --\n";
// =============================================================================
$js = frontendSrc('assets/js/requests/requests.js');
check('requests.js is readable', $js !== null);

check('the offer exists', $js !== null && strpos($js, 'async offerStockAdd(created)') !== false);
check('it is reached from a SUCCESSFUL create',
    $js !== null && strpos($js, "Array.isArray(result.data?.stock_missing) ? result.data.stock_missing : []") !== false);
check('missing stock is checked BEFORE the wrong-site handover offer',
    $js !== null && strpos($js, 'this.offerStockAdd(') < strpos($js, 'this.offerHandover('));
check('the child goes through the existing prerequisite path',
    $js !== null && preg_match("/plStockNow[\s\S]{0,900}this\.showCreate\(parentSummary\)/", $js) === 1);
check('"Later" leaves the request in place and opens it',
    $js !== null && preg_match("/plStockLater[\s\S]{0,400}this\.openDetail\(parentSummary\.id\)/", $js) === 1);

check('the prefill exists', $js !== null && strpos($js, 'async applyStockPrefill()') !== false);
check('showCreate() applies it', $js !== null && strpos($js, 'if (this.stockPrefill) this.applyStockPrefill();') !== false);
check('the child type is chosen by CAPABILITY, not by name',
    $js !== null
    && preg_match("/this\.typeActionCeiling\(t\)\.some\(\(a\) => a\.action_type === 'inventory\.component\.add'\)/", $js) === 1);
check('the mounted Add Component form is given the component type',
    $js !== null && strpos($js, 'initializeAddComponentForm(wantType, { embedded: true })') !== false);
check('the wanted type is captured before mountInventoryForm() awaits anything',
    $js !== null
    && preg_match("/async mountInventoryForm\(\)[\s\S]{0,400}const wantType = this\.stockPrefill/", $js) === 1);
check('a model mismatch warns but does not block',
    $js !== null
    && preg_match("/wanted\.component_uuid\) \{\s*this\.toast\([^\n]*'warning'\);\s*\}/", $js) === 1);

check('the standing notice exists', $js !== null && strpos($js, 'stockMissingBlock(p)') !== false);
check('it is rendered in the detail panel',
    $js !== null && strpos($js, '${this.stockMissingBlock(p)}') !== false);
check('its button reuses the same prefill route',
    $js !== null && preg_match("/plRaiseStockRecord[\s\S]{0,900}this\.showCreate\(p\)/", $js) === 1);
check('the notice names the model from the ims-data catalogue',
    $js !== null && strpos($js, 'modelLabel(type, uuid)') !== false);
// Widened 2026-08-29: the detail now names models for a SECOND gap as well
// (location_gap), and both blocks need the catalogue. The point of the check is
// unchanged — eleven static fetches must not be paid for by someone merely
// reading a request that has no gap at all.
check('the catalogue is loaded only when there is a gap to name',
    $js !== null
    && preg_match("/stock_missing\.length\)\s*\|\|\s*\(Array\.isArray\(this\.currentDetail\.location_gap\) && this\.currentDetail\.location_gap\.length\)\) \{\s*await this\.loadComponentData\(\);/", $js) === 1);

// The compiled Tailwind is hand-built, so a class absent from it renders as
// nothing — mt-2.5 in particular is NOT emitted.
$css = frontendSrc('assets/css/tailwind.css');
check('tailwind.css is readable', $css !== null);
foreach (['.bg-warning\/10', '.border-warning\/30', '.text-warning', '.mt-3'] as $sel) {
    check("compiled CSS has $sel", $css !== null && strpos($css, $sel) !== false);
}
check('no mt-2.5 in the new markup (it is not compiled)',
    $js !== null && strpos($js, 'class="mt-2.5') === false);

// =============================================================================
echo "\n" . ($fails === 0 ? "ALL PASS\n" : "$fails FAILED\n");
exit($fails === 0 ? 0 : 1);
