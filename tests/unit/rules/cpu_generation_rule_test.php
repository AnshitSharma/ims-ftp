<?php
/**
 * cpu_generation_rule_test.php — unit test for cpu.generation_match and the
 * CpuGenerationResolver behind it.
 *
 * Two halves. The resolver half uses synthetic board/CPU arrays so each axis
 * can be isolated (ims-data has no v4-only board to exercise a generation
 * refusal with). The rule half uses real ims-data UUIDs and hand-built
 * TargetStates, matching cpu_rules_test.php, so the specs resolve through
 * DataExtractionUtilities for real — including a platform-owned system_board,
 * which reaches the rule via PlatformSpecIndex. No DB. Exit 0 = all pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/core/models/config/ResourceCatalog.php';   // TargetState constructs one
require_once $ROOT . '/core/models/validation/TargetState.php';
require_once $ROOT . '/core/models/validation/rules/CpuGenerationMatchRule.php';
require_once $ROOT . '/core/models/compatibility/CpuGenerationResolver.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// Real ims-data UUIDs.
const MB_DL325_LOOSE    = 'c5e9b814-725d-4f1a-b6b8-3f8c8d8b13c1'; // SP3, cpu_support: Zen 3 / EPYC 7003
const MB_DL325_PLATFORM = '42f4d03f-a4b5-5b8c-b27e-cb2d4820524e'; // same board, platform-owned copy
const MB_R6525          = 'b6a2f3e8-193c-4d5b-a7e1-8c4d5f6b8a21'; // SP3, Zen 2 + Zen 3 / EPYC 7002 + 7003
const MB_ROMED8         = '4f8e6c3d-2b7a-4c9e-8d1b-5e6f7a3d9c8b'; // SP3, declares no cpu_support
const MB_B550M          = 'b2c3d4e5-f6a7-4b8c-9d0e-1f2a3b4c5d6e'; // AM4, Zen 2 + Zen 3 / Ryzen 3000 + 5000
const MB_MD90           = 'c7d8e9f0-a1b2-4c3d-ae5f-6a7b8c9d0e1f'; // LGA2011-3, Haswell-EP + Broadwell-EP

const CPU_EPYC_7742  = '194678d2-40d1-4960-a379-5ba0d35e1dc0'; // Zen 2, EPYC 7002 (Rome)
const CPU_EPYC_7763  = 'ee85893a-ecff-4db8-b8ac-5fb0f66c0390'; // Zen 3, EPYC 7003 (Milan)
const CPU_RYZEN_1700X = 'e8f9a0b1-c2d3-4e4f-9a5b-6c7d8e9f0a1b'; // Zen (1st gen)
const CPU_RYZEN_3600  = 'd7e8f9a0-b1c2-4d3e-8f4a-5b6c7d8e9f0a'; // Zen 2, Ryzen 5 3000
const CPU_E5_2680_V3  = '6dd00ede-b89e-42bd-a8c4-5a8b99ea5f6f'; // Haswell-EP
const CPU_E5_2680_V4  = '9993b16c-5d6c-4817-a1f4-b3968f48565e'; // Broadwell-EP

function mbRow($id, $uuid) {
    return ['id' => $id, 'component_type' => 'motherboard', 'spec_uuid' => $uuid, 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => null, 'slot_ref' => null, 'source' => 'rows'];
}
function cpuRow($id, $uuid) {
    return ['id' => $id, 'component_type' => 'cpu', 'spec_uuid' => $uuid, 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => null, 'slot_ref' => null, 'source' => 'rows'];
}
function verdictFor($mbUuid, array $cpuUuids) {
    $rows = [mbRow(1, $mbUuid)];
    $id = 2;
    foreach ($cpuUuids as $cpuUuid) { $rows[] = cpuRow($id++, $cpuUuid); }
    return (new CpuGenerationMatchRule())->evaluate(new TargetState($rows));
}

// =======================================================================
echo "-- CpuGenerationResolver: the generations axis --\n";

// ims-data has no v4-only board, so the refusal that motivates this whole
// rule can only be exercised synthetically.
$v4Only = ['model' => 'Fixture V4-only', 'socket' => ['type' => 'LGA2011-3'],
    'cpu_support' => ['generations' => ['Broadwell-EP']]];
$v3Cpu = ['model' => 'E5-2680 v3', 'socket' => 'LGA2011-3', 'architecture' => 'Haswell-EP', 'series' => 'Xeon E5 v3', 'family' => 'E5-2600 v3'];
$v4Cpu = ['model' => 'E5-2680 v4', 'socket' => 'LGA2011-3', 'architecture' => 'Broadwell-EP', 'series' => 'Xeon E5 v4', 'family' => 'E5-2600 v4'];

$r = CpuGenerationResolver::evaluate($v4Only, $v3Cpu);
check('v4-only board refuses a v3 CPU that fits the socket', $r['supported'] === false);
check('  and names the axis', $r['failed_axis'] === 'generations');
check('v4-only board accepts a v4 CPU', CpuGenerationResolver::evaluate($v4Only, $v4Cpu)['supported'] === true);

// The point of the alias table: ims-data may spell it either way.
$v4OnlyVendorTag = ['model' => 'Fixture V4-only', 'socket' => ['type' => 'LGA2011-3'],
    'cpu_support' => ['generations' => ['Xeon E5 v4']]];
check('"Xeon E5 v4" and "Broadwell-EP" refuse the same CPU',
    CpuGenerationResolver::evaluate($v4OnlyVendorTag, $v3Cpu)['supported'] === false);
check('"Xeon E5 v4" and "Broadwell-EP" accept the same CPU',
    CpuGenerationResolver::evaluate($v4OnlyVendorTag, $v4Cpu)['supported'] === true);
check('canonical id is shared across spellings',
    CpuGenerationResolver::canonicalGeneration('Broadwell-EP') === CpuGenerationResolver::canonicalGeneration('Xeon E5 v4'));
check('spelling is punctuation- and case-insensitive',
    CpuGenerationResolver::canonicalGeneration('broadwell ep') === CpuGenerationResolver::canonicalGeneration('Broadwell-EP'));
check('an unknown spelling resolves to null, not a wrong id',
    CpuGenerationResolver::canonicalGeneration('Whitley Lake') === null);

// A CPU's generation is readable from architecture, series or family alike.
check('architecture alone identifies the generation',
    CpuGenerationResolver::cpuGenerations(['architecture' => 'Broadwell-EP']) === ['xeon-e5-v4']);
check('series alone identifies the generation',
    CpuGenerationResolver::cpuGenerations(['series' => 'Xeon E5 v4']) === ['xeon-e5-v4']);
check('all three agreeing yields one id, not three',
    CpuGenerationResolver::cpuGenerations($v4Cpu) === ['xeon-e5-v4']);

// =======================================================================
echo "-- CpuGenerationResolver: the series axis (the AMD case) --\n";

// architecture cannot express this: EPYC 7742 and Ryzen 5 3600 both say "Zen 2".
check('EPYC 7742 and Ryzen 5 3600 share an architecture',
    CpuGenerationResolver::cpuGenerations(['architecture' => 'Zen 2'])
    === CpuGenerationResolver::cpuGenerations(['architecture' => 'Zen 2']));

$milanOnly = ['model' => 'Fixture Milan-only', 'socket' => ['type' => 'SP3'],
    'cpu_support' => ['series' => ['EPYC 7003']]];
$rome = ['model' => 'EPYC 7742', 'architecture' => 'Zen 2', 'series' => 'EPYC', 'family' => 'EPYC 7002'];
$milan = ['model' => 'EPYC 7763', 'architecture' => 'Zen 3', 'series' => 'EPYC', 'family' => 'EPYC 7003'];
check('EPYC 7003 series does not match family EPYC 7002', CpuGenerationResolver::evaluate($milanOnly, $rome)['supported'] === false);
check('  and names the series axis', CpuGenerationResolver::evaluate($milanOnly, $rome)['failed_axis'] === 'series');
check('EPYC 7003 series matches family EPYC 7003', CpuGenerationResolver::evaluate($milanOnly, $milan)['supported'] === true);

// Token-subset, so a board can name the series without the Ryzen tier digit.
$ryzen5000 = ['model' => 'Fixture', 'socket' => ['type' => 'AM4'], 'cpu_support' => ['series' => ['Ryzen 5000']]];
check('board "Ryzen 5000" matches family "Ryzen 9 5000"',
    CpuGenerationResolver::evaluate($ryzen5000, ['model' => 'Ryzen 9 5950X', 'architecture' => 'Zen 3', 'series' => 'Ryzen 9', 'family' => 'Ryzen 9 5000'])['supported'] === true);
check('board "Ryzen 5000" does not match family "Ryzen 5 3000"',
    CpuGenerationResolver::evaluate($ryzen5000, ['model' => 'Ryzen 5 3600', 'architecture' => 'Zen 2', 'series' => 'Ryzen 5', 'family' => 'Ryzen 5 3000'])['supported'] === false);
check('board "Xeon Scalable" matches series "Xeon Scalable"',
    CpuGenerationResolver::evaluate(
        ['model' => 'F', 'cpu_support' => ['series' => ['Xeon Scalable']]],
        ['model' => 'Gold 5120', 'architecture' => 'Skylake-SP', 'series' => 'Xeon Scalable', 'family' => 'Gold 5000'])['supported'] === true);
check('board "Xeon Scalable" does not match series "Xeon E5 v4"',
    CpuGenerationResolver::evaluate(
        ['model' => 'F', 'cpu_support' => ['series' => ['Xeon Scalable']]], $v4Cpu)['supported'] === false);

// =======================================================================
echo "-- CpuGenerationResolver: the two axes are independent and ANDed --\n";

$milanAnd7003 = ['model' => 'Fixture', 'socket' => ['type' => 'SP3'],
    'cpu_support' => ['generations' => ['Zen 3'], 'series' => ['EPYC 7003']]];
check('both axes satisfied passes', CpuGenerationResolver::evaluate($milanAnd7003, $milan)['supported'] === true);
$zen3Ryzen = ['model' => 'Ryzen 9 5950X', 'architecture' => 'Zen 3', 'series' => 'Ryzen 9', 'family' => 'Ryzen 9 5000'];
$r = CpuGenerationResolver::evaluate($milanAnd7003, $zen3Ryzen);
check('generation ok but series wrong still fails (AND, not OR)', $r['supported'] === false);
check('  and the failure is attributed to series', $r['failed_axis'] === 'series');
check('generation wrong fails before series is consulted',
    CpuGenerationResolver::evaluate($milanAnd7003, $rome)['failed_axis'] === 'generations');

echo "-- CpuGenerationResolver: absent, empty and unusable declarations --\n";
check('no cpu_support block: unconstrained',
    CpuGenerationResolver::evaluate(['model' => 'F', 'socket' => ['type' => 'SP3']], $rome)['constrained'] === false);
check('  and therefore supported', CpuGenerationResolver::evaluate(['model' => 'F'], $rome)['supported'] === true);
check('empty lists count as no constraint',
    CpuGenerationResolver::evaluate(['model' => 'F', 'cpu_support' => ['generations' => [], 'series' => []]], $rome)['constrained'] === false);
check('a bare string is accepted where a list is expected',
    CpuGenerationResolver::evaluate(['model' => 'F', 'cpu_support' => ['series' => 'EPYC 7003']], $milan)['supported'] === true);
check('an unknown generation tag falls back to token matching rather than never matching',
    CpuGenerationResolver::evaluate(['model' => 'F', 'cpu_support' => ['generations' => ['EPYC 7003']]], $milan)['supported'] === true);
$r = CpuGenerationResolver::evaluate($milanOnly, ['model' => '']);
check('a constrained board plus an unidentifiable CPU fails closed', $r['supported'] === false);
check('  and says so', $r['failed_axis'] === 'unidentifiable');

// =======================================================================
echo "-- cpu.generation_match over real ims-data --\n";
$rule = new CpuGenerationMatchRule();
check('id', $rule->id() === 'cpu.generation_match');
check('severity is ERROR, so it blocks an ADD', $rule->severity() === Severity::ERROR);
check('triggers on ADD, REPLACE and VALIDATE',
    $rule->triggers() === [Trigger::ADD, Trigger::REPLACE, Trigger::VALIDATE]);

$r = verdictFor(MB_DL325_LOOSE, [CPU_EPYC_7742]);
check('DL325 Gen10 Plus v2 (Milan-only) refuses EPYC 7742 (Rome)', $r->passed() === false);
check('  message names the CPU', strpos($r->message(), 'EPYC 7742') !== false);
check('  message names what the board does support', strpos($r->message(), 'Zen 3') !== false);
check('DL325 Gen10 Plus v2 accepts EPYC 7763 (Milan)', verdictFor(MB_DL325_LOOSE, [CPU_EPYC_7763])->passed() === true);

// The platform-owned copy of that same board, resolved via PlatformSpecIndex.
check('the platform-owned DL325 system board refuses EPYC 7742 identically',
    verdictFor(MB_DL325_PLATFORM, [CPU_EPYC_7742])->passed() === false);
check('the platform-owned DL325 system board accepts EPYC 7763',
    verdictFor(MB_DL325_PLATFORM, [CPU_EPYC_7763])->passed() === true);

check('R6525 (Rome + Milan) accepts EPYC 7742', verdictFor(MB_R6525, [CPU_EPYC_7742])->passed() === true);
check('R6525 accepts EPYC 7763 too', verdictFor(MB_R6525, [CPU_EPYC_7763])->passed() === true);
check('R6525 accepts a mixed pair', verdictFor(MB_R6525, [CPU_EPYC_7742, CPU_EPYC_7763])->passed() === true);

check('ROMED8-9001 declares nothing, so it still accepts Rome',
    verdictFor(MB_ROMED8, [CPU_EPYC_7742])->passed() === true);
check('  and Milan', verdictFor(MB_ROMED8, [CPU_EPYC_7763])->passed() === true);

check('B550M refuses Ryzen 7 1700X (Zen 1)', verdictFor(MB_B550M, [CPU_RYZEN_1700X])->passed() === false);
check('B550M accepts Ryzen 5 3600 (Zen 2)', verdictFor(MB_B550M, [CPU_RYZEN_3600])->passed() === true);

// The socket collision this rule exists for -- these boards genuinely take both.
check('MD90-FS0 (v3+v4) accepts E5-2680 v3', verdictFor(MB_MD90, [CPU_E5_2680_V3])->passed() === true);
check('MD90-FS0 accepts E5-2680 v4', verdictFor(MB_MD90, [CPU_E5_2680_V4])->passed() === true);
check('MD90-FS0 accepts both at once', verdictFor(MB_MD90, [CPU_E5_2680_V3, CPU_E5_2680_V4])->passed() === true);

// One bad CPU among several must not be masked by the good ones.
check('a refused CPU alongside an accepted one still blocks',
    verdictFor(MB_DL325_LOOSE, [CPU_EPYC_7763, CPU_EPYC_7742])->passed() === false);

echo "-- cpu.generation_match: cases it deliberately passes on --\n";
check('no motherboard: passes (cpu.requires_board owns that case)',
    (new CpuGenerationMatchRule())->evaluate(new TargetState([cpuRow(1, CPU_EPYC_7742)]))->passed() === true);
check('no CPU: passes',
    (new CpuGenerationMatchRule())->evaluate(new TargetState([mbRow(1, MB_DL325_LOOSE)]))->passed() === true);
check('empty config: passes',
    (new CpuGenerationMatchRule())->evaluate(new TargetState([]))->passed() === true);

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILED") . "\n";
exit($fails === 0 ? 0 : 1);
