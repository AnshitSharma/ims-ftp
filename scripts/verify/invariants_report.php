<?php
/**
 * invariants_report.php — U-P.1. The architectural invariants as a GATE REPORT.
 *
 * WHY THIS EXISTS ALONGSIDE scripts/ci/invariants.sh
 * --------------------------------------------------
 * `scripts/ci/invariants.sh` (U-P.1, 2026-08-24) is the CI entry point: it runs
 * the invariant CHECK blocks, then `run_all.php --quick`, then every suite in
 * tests/regression/ and tests/unit/rules/. That is the right shape for a CI job
 * and the wrong shape for `run_all.php`'s REGISTRY, for two reasons:
 *
 *   1. run_all.php launches its children as `php <script>`. A POSIX `sh` script
 *      cannot be a registry entry at all, so until this file existed the
 *      invariants were enforced by whoever remembered to call invariants.sh —
 *      and by NO gate. `--gate P10` reads `['all']`, which expands to the
 *      REGISTRY keys, and the invariants were not among them. P10 is the phase
 *      whose entire objective (12-post-cutover/README.md) is "make the
 *      invariants permanent", and its gate did not check one of them.
 *   2. invariants.sh calls run_all.php. Registering the .sh would have made the
 *      gate call the battery that calls the gate.
 *
 * So this file is section 1 of invariants.sh — the CHECK blocks, and only those
 * — in the form the registry can consume: a PHP script, one report file, the
 * `<name>: GREEN|RED <path>` last line, exit 0/1/2. Sections 2 and 3 of
 * invariants.sh are already registry entries in their own right (`--quick` is
 * QUICK_SET; the suites are `regression` → tests/run_tests.php), so nothing is
 * duplicated and nothing runs twice inside one gate.
 *
 * NO CHECK TEXT IS STORED HERE. Exactly as invariants.sh does it, the command
 * text is produced at run time by `scripts/ci/inv_extract.php`, which parses
 * docs/ARCHITECTURAL_INVARIANTS.md. Editing the document changes what this
 * gate enforces with no code change here; an edit into a shape the parser cannot
 * execute exits 2 rather than silently dropping a check. U-P.1's only checklist
 * item — "every INV check runs verbatim from the invariants file (no paraphrase
 * drift)" — is satisfied by that indirection, not by this file's good behaviour.
 *
 * WHAT GATES, AND WHAT DOES NOT
 * -----------------------------
 *   gate    — a check the document asserts unconditionally, or whose "after
 *             U-N.N" condition names a unit that phase-status.json reads
 *             `verified`. A failure here is RED.
 *   info    — the same, but its named unit is not yet verified (today: INV-3,
 *             conditioned on U-C.6 `in_progress`). It RUNS and its output is
 *             printed and recorded; it does not gate. It starts gating the day
 *             the unit is promoted, with no edit here.
 *   manual  — the document defines no executable check (INV-10, INV-11, INV-12)
 *             or defines one that is not runnable verbatim (INV-2 carries an
 *             unresolved `<base>` placeholder). These are COUNTED and NAMED in
 *             both the output and the report file. They are not passes and this
 *             file never reports them as such.
 *
 * A RUN THAT ENFORCED NOTHING IS NOT GREEN. If zero gating checks executed —
 * no shell, no DB, an unparseable document — the exit is 2, never 0. "We did not
 * check" and "it passed" are the same string in this repo's history five times
 * over (F-10, F-11, F-18, F-21, F-24); they are not the same string here.
 *
 * PROVING THE GATE CAN FAIL
 * -------------------------
 * A gate that cannot reject anything is worse than no gate, because it also
 * consumes the attention that would have gone to a real one. `--self-test` runs
 * every mechanically-enforced check against a DELIBERATELY BROKEN tree and
 * requires it to reject that tree, plus a clean control tree it must accept:
 *
 *     php scripts/verify/invariants_report.php --self-test
 *
 * The mutants are built in a temp directory; the real tree is never written to.
 * Each recipe is registered in MUTANTS below against an invariant id. A gating
 * check with NO registered mutant is reported UNPROVEN and --self-test exits 1,
 * so adding INV-13 to the document forces someone to say how it can fail.
 *
 * The `sql` mutants need a reachable database (they create and drop a throwaway
 * table prefixed `_inv_mutant_`). Without one they report UNPROVEN and the
 * self-test exits 3 — incomplete, not passed.
 *
 * Usage:
 *   php scripts/verify/invariants_report.php              # gate run
 *   php scripts/verify/invariants_report.php --self-test  # prove each check rejects a mutant
 *   php scripts/verify/invariants_report.php --list-mutants
 *
 * Exit: 0 GREEN · 1 RED · 2 could-not-run (never confused with 0) ·
 *       3 self-test incomplete (some mutant unproven).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    // Defence in depth, mirroring deadcode_report.php. This host 403s every .php
    // outside api/api.php, but a report that enumerates internal paths should
    // refuse to run over HTTP on its own account too.
    http_response_code(403);
    exit(1);
}

$ROOT = dirname(__DIR__, 2);
$EXTRACTOR = $ROOT . '/scripts/ci/inv_extract.php';
$REPORT_DIR = $ROOT . '/reports';

// Several invariant CHECKs are literal shell one-liners that start with a bare
// `php`, so `php` has to resolve inside the shell. When this script was launched
// by an absolute interpreter path that is not on PATH — XAMPP's php.exe, or
// run_all.php's own child launch — it does not, and the check fails with
// "php: command not found": a RED produced by the environment rather than by the
// code, which is exactly as corrosive as a false green. Put the interpreter's
// directory ON the PATH; never rewrite the document's command to suit the host.
// Same reasoning, same fix, as scripts/ci/invariants.sh. (INV-8 was the original
// reason for this; it closed on 2026-08-30 with U-D.3c, but the hazard is generic
// and outlives it.)
$phpDir = dirname(PHP_BINARY);
if ($phpDir !== '' && strpos((string)getenv('PATH'), $phpDir) === false) {
    putenv('PATH=' . $phpDir . PATH_SEPARATOR . (string)getenv('PATH'));
}

$selfTest = in_array('--self-test', $argv, true);
$listMutants = in_array('--list-mutants', $argv, true);
$probeDb = in_array('--probe-db', $argv, true);

// ---------------------------------------------------------------------------
// Mutant recipes. One per mechanically-enforced check, keyed "INV-N/SEQ".
//
// Two kinds, labelled differently in the output because they prove different
// things and conflating them would overstate the evidence:
//
//   'tree'   — a TARGET mutant. A throwaway source tree is built containing a
//              real violation of the invariant, and the document's own command
//              is run against it verbatim, from that tree. This proves the
//              CHECK detects the thing the invariant forbids.
//   'runner' — a RUNNER mutant. The check delegates to another script
//              (INV-4, INV-5/1), so what is proven here is that a non-zero exit
//              from that script is classified FAIL — not that the delegate
//              itself detects anything. The delegates carry their own mutation
//              evidence: the regression suites were mutation-probed on
//              2026-08-24 (25 of 25 matchers rejected their mutant). Stated
//              plainly so nobody reads a green line here as coverage of those.
//   'sql'    — a TARGET mutant needing a database: the document's statement is
//              run against a throwaway table holding a violating row.
//
// 'clean' is the negative control for every 'tree' recipe: the same skeleton
// WITHOUT the violation. A check that fails on the clean tree is a check that
// always fails, which is as useless as one that never fails.
// ---------------------------------------------------------------------------
const MUTANTS = [
    'INV-1/1' => [
        'kind'  => 'tree',
        'why'   => "new code carrying quantity semantics",
        'clean' => ['core/models/config/Clean.php' => "<?php\nclass Clean { public function n(): int { return 1; } }\n"],
        'dirty' => ['core/models/config/Clean.php' => "<?php\nclass Clean { public function n(): array { return ['quantity' => 2]; } }\n"],
        'dirs'  => ['core/models/config', 'core/models/commands', 'core/models/validation'],
    ],
    'INV-3/1' => [
        'kind'  => 'tree',
        'why'   => "a transaction owner outside BaseCommand",
        'clean' => ['core/models/commands/BaseCommand.php' => "<?php\n\$pdo->beginTransaction();\n"],
        'dirty' => [
            'core/models/commands/BaseCommand.php' => "<?php\n\$pdo->beginTransaction();\n",
            'api/handlers/thing/thing_api.php'     => "<?php\n\$pdo->beginTransaction();\n",
        ],
        'dirs'  => ['core/models/commands', 'api/handlers/thing', 'scripts'],
    ],
    'INV-5/2' => [
        'kind'  => 'tree',
        'why'   => "a fail-open comment in a mutation path",
        'clean' => ['core/models/server/Clean.php' => "<?php\n// validation failure aborts the add\n"],
        'dirty' => ['core/models/server/Clean.php' => "<?php\n// Continue without validation\n"],
        'dirs'  => ['core/models/server', 'api'],
    ],
    'INV-7/1' => [
        'kind'  => 'tree',
        'why'   => "a rule reading its severity from the environment",
        'clean' => ['core/models/validation/rules/CleanRule.php' => "<?php\nclass CleanRule { public function severity(): string { return 'ERROR'; } }\n"],
        'dirty' => ['core/models/validation/rules/CleanRule.php' => "<?php\nclass CleanRule { public function severity(): string { return getenv('X') ?: 'ERROR'; } }\n"],
        'dirs'  => ['core/models/validation/rules'],
    ],
    'INV-9/1' => [
        'kind'  => 'tree',
        'why'   => "a seeder shipped without its paired rollback",
        'clean' => [
            'database/seeders/2026_07_99_001_mutant.sql'                   => "-- noop\n",
            'database/seeders/rollback/2026_07_99_001_mutant_rollback.sql' => "-- noop\n",
        ],
        'dirty' => ['database/seeders/2026_07_99_001_mutant.sql' => "-- noop\n"],
        'dirs'  => ['database/seeders/rollback'],
    ],
    'INV-1/2' => [
        'kind' => 'sql',
        'why'  => "one physical unit appearing in two configurations",
        'ddl'  => 'CREATE TABLE %T (inventory_type VARCHAR(32), inventory_id INT)',
        'dirty' => ["INSERT INTO %T VALUES ('cpu', 7)", "INSERT INTO %T VALUES ('cpu', 7)"],
        'clean' => ["INSERT INTO %T VALUES ('cpu', 7)", "INSERT INTO %T VALUES ('cpu', 8)"],
        'table' => 'config_components',
    ],
    'INV-6/1' => [
        'kind' => 'sql',
        'why'  => "a mutation that did not bump the revision",
        'ddl'  => null, // two tables — see sqlMutantTables()
        'table' => null,
    ],
    'INV-4/1' => ['kind' => 'runner', 'why' => "finalized_immutability_test.php exiting non-zero"],
    'INV-5/1' => ['kind' => 'runner', 'why' => "fail_closed_test.php exiting non-zero"],
    // INV-8/1 removed 2026-08-30 (U-D.3c). Its delegate, equivalence_report.php,
    // is deleted along with the invariant: with the legacy JSON columns dropped
    // there is no second store for a dual-write window to fork from. A recipe kept
    // here would run a script that no longer exists and report FAIL forever.
];

// ---------------------------------------------------------------------------
// Environment resolution. Every one of these exits 2 on failure rather than
// degrading the run: a missing shell must not turn eight checks into eight
// silent passes.
// ---------------------------------------------------------------------------

/** Locate a POSIX shell. The document's checks are sh, and they run verbatim. */
function resolveShell(): string
{
    static $sh = null;
    if ($sh !== null) {
        return $sh;
    }
    $explicit = getenv('INV_SH_BIN');
    if (is_string($explicit) && $explicit !== '' && is_file($explicit)) {
        return $sh = $explicit;
    }
    $candidates = ['sh'];
    if (DIRECTORY_SEPARATOR === '\\') {
        $candidates = [
            'C:\\Program Files\\Git\\usr\\bin\\sh.exe',
            'C:\\Program Files\\Git\\bin\\sh.exe',
            'C:\\Program Files (x86)\\Git\\usr\\bin\\sh.exe',
            'sh',
        ];
    }
    foreach ($candidates as $c) {
        if ($c !== 'sh' && is_file($c)) {
            return $sh = $c;
        }
        if ($c === 'sh') {
            $probe = runProcess([$c, '-c', 'echo ok'], sys_get_temp_dir());
            if ($probe['code'] === 0 && trim($probe['out']) === 'ok') {
                return $sh = $c;
            }
        }
    }
    fwrite(STDERR, "invariants_report: no POSIX shell found. The invariant CHECK blocks are sh "
        . "commands and are run verbatim, so one is required. Set INV_SH_BIN to its full path.\n");
    exit(2);
}

/**
 * Run a command, capture stdout and stderr separately, return exit code.
 * stderr goes to a temp FILE, never a second unread pipe — run_all.php's
 * 2026-08-24 deadlock (a child that wrote more than one pipe buffer to stderr
 * blocked forever and the gate HUNG instead of failing) came from exactly that,
 * and stream_select() does not work on proc_open pipes on Windows.
 */
function runProcess(array $cmd, string $cwd): array
{
    $errFile = tempnam(sys_get_temp_dir(), 'invrep_');
    $desc = [
        1 => ['pipe', 'w'],
        2 => ['file', $errFile ?: (DIRECTORY_SEPARATOR === '\\' ? 'nul' : '/dev/null'), 'w'],
    ];
    $proc = @proc_open($cmd, $desc, $pipes, $cwd);
    if (!is_resource($proc)) {
        if ($errFile) { @unlink($errFile); }
        return ['out' => '', 'err' => 'could not launch', 'code' => 127];
    }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code = proc_close($proc);
    $err = $errFile && is_file($errFile) ? (string)file_get_contents($errFile) : '';
    if ($errFile) { @unlink($errFile); }
    return ['out' => (string)$out, 'err' => $err, 'code' => $code];
}

/** Extract the manifest. A parse failure is exit 2, never an empty green run. */
function extractManifest(string $root, string $extractor, ?string $doc = null): array
{
    $dir = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'inv_' . bin2hex(random_bytes(4));
    if (!@mkdir($dir, 0777, true)) {
        fwrite(STDERR, "invariants_report: cannot create a work directory under " . sys_get_temp_dir() . "\n");
        exit(2);
    }
    $cmd = [PHP_BINARY, $extractor, '--outdir', $dir];
    if ($doc !== null) { $cmd[] = '--doc'; $cmd[] = $doc; }
    $r = runProcess($cmd, $root);
    $manifest = $dir . DIRECTORY_SEPARATOR . 'manifest.tsv';
    if ($r['code'] !== 0 || !is_file($manifest)) {
        fwrite(STDERR, "invariants_report: inv_extract.php could not turn the invariants document into an "
            . "executable manifest (exit {$r['code']}). Nothing is enforced, so this run is exit 2, not green.\n"
            . rtrim($r['err']) . "\n");
        exit(2);
    }
    $records = [];
    foreach (preg_split('/\R/', (string)file_get_contents($manifest)) as $line) {
        if (trim($line) === '') { continue; }
        $f = explode("\x1f", $line);
        if (count($f) < 7) { continue; }
        $records[] = [
            'inv' => $f[0], 'seq' => $f[1], 'kind' => $f[2], 'assert' => $f[3],
            'gating' => $f[4], 'note' => $f[5],
            'cmd' => $f[6] !== '' && is_file($f[6]) ? rtrim((string)file_get_contents($f[6]), "\n") : '',
        ];
    }
    if ($records === []) {
        fwrite(STDERR, "invariants_report: the manifest parsed to zero checks. Exit 2.\n");
        exit(2);
    }
    return $records;
}

/**
 * Bootstrap the app only when an SQL check actually needs it — and PROBE FIRST,
 * in a child process.
 *
 * core/config/app.php does not throw on a failed connection: it prints a JSON
 * error envelope and calls exit(). Requiring it in-process to "try" a
 * connection therefore kills this report mid-run, with the API's error body as
 * its entire output and no report file — which is a gate that vanishes rather
 * than one that fails. So a throwaway child requires it and reports back;
 * only a child that answers PDO_OK earns an in-process require, which is then
 * known to succeed.
 */
function pdoOrNull(string $root): ?PDO
{
    static $resolved = false;
    static $pdoRef = null;
    if ($resolved) { return $pdoRef; }
    $resolved = true;
    $bootstrap = $root . '/core/config/app.php';
    if (!is_file($bootstrap)) { return $pdoRef = null; }

    $probe = tempnam(sys_get_temp_dir(), 'invprobe_');
    if ($probe === false) { return $pdoRef = null; }
    $probePhp = $probe . '.php';
    file_put_contents($probePhp,
        "<?php\nrequire " . var_export($bootstrap, true) . ";\n"
        . "global \$pdo;\nfwrite(STDOUT, (\$pdo instanceof PDO) ? 'PDO_OK' : 'PDO_NO');\n");
    $r = runProcess([PHP_BINARY, $probePhp], $root);
    @unlink($probePhp);
    @unlink($probe);
    probeDiagnostic('out=' . trim($r['out']) . ' | exit=' . $r['code'] . ' | err=' . trim($r['err']));
    if (strpos($r['out'], 'PDO_OK') === false) {
        return $pdoRef = null;
    }
    // `global $pdo` MUST come before the require. core/config/app.php is written
    // for top-level inclusion — it does a bare `$pdo = new PDO(...)`. Required
    // from inside a function without this declaration, that assignment lands in
    // THIS function's local scope and the global stays unset, so the report
    // would report "no database reachable" while holding a live connection and
    // silently downgrade both SQL invariants. Binding the name first makes
    // app.php's own assignment write the global, exactly as at top level.
    global $pdo;
    require_once $bootstrap;
    return $pdoRef = ($pdo instanceof PDO) ? $pdo : null;
}

/**
 * Execute one manifest record verbatim and return its raw result.
 * `exit0` records name an artifact, not a launch line — a bare *.php path is
 * prefixed with this interpreter. That single interpolation is inherited from
 * inv_extract.php's header and is the only text added to any command anywhere.
 */
function executeCheck(array $rec, string $root, ?PDO $pdo): array
{
    switch ($rec['kind']) {
        case 'sh':
            $r = runProcess([resolveShell(), '-c', $rec['cmd']], $root);
            return ['out' => $r['out'], 'err' => $r['err'], 'code' => $r['code'], 'ran' => true];
        case 'exit0':
            $cmd = $rec['cmd'];
            if (preg_match('/^\S+\.php\b/', $cmd)) { $cmd = escapeshellarg(PHP_BINARY) . ' ' . $cmd; }
            $r = runProcess([resolveShell(), '-c', $cmd], $root);
            return ['out' => $r['out'], 'err' => $r['err'], 'code' => $r['code'], 'ran' => true];
        case 'sql':
            if (!$pdo instanceof PDO) {
                return ['out' => '', 'err' => 'no database connection', 'code' => -1, 'ran' => false];
            }
            try {
                $stmt = $pdo->query($rec['cmd']);
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_NUM) : [];
                $lines = [];
                foreach ($rows as $row) { $lines[] = implode("\t", array_map('strval', $row)); }
                return ['out' => implode("\n", $lines), 'err' => '', 'code' => 0, 'ran' => true];
            } catch (Throwable $e) {
                return ['out' => '', 'err' => $e->getMessage(), 'code' => 1, 'ran' => false];
            }
        default:
            return ['out' => '', 'err' => '', 'code' => 0, 'ran' => false];
    }
}

/** Apply the document's own assertion vocabulary to a raw result. */
function verdictFor(array $rec, array $res): string
{
    if (!$res['ran']) { return 'COULD-NOT-RUN'; }
    switch ($rec['assert']) {
        case 'empty': return trim($res['out']) === '' ? 'PASS' : 'FAIL';
        case 'exit0': return $res['code'] === 0 ? 'PASS' : 'FAIL';
        default:      return 'UNENFORCED';
    }
}

// ===========================================================================
// --probe-db: say plainly whether the SQL invariants can run, and why not.
// Exists because "2 unproven (need a database)" is the single most common
// self-test outcome and the reason is never in the output otherwise.
// ===========================================================================
if ($probeDb) {
    $pdo = pdoOrNull($ROOT);
    if ($pdo instanceof PDO) {
        $name = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        echo "invariants_report --probe-db: connected. schema=" . ($name !== '' ? $name : '(none selected)') . "\n";
        exit(0);
    }
    echo "invariants_report --probe-db: NOT connected. core/config/app.php did not produce a PDO in a child "
        . "process. The SQL invariants (INV-1/2, INV-6/1) cannot run and will be reported COULD-NOT-RUN, "
        . "which is RED — not a pass.\n";
    echo "  last probe stdout: " . trim(probeDiagnostic()) . "\n";
    exit(2);
}

// ===========================================================================
// --list-mutants
// ===========================================================================
if ($listMutants) {
    foreach (MUTANTS as $key => $m) {
        printf("  %-10s %-7s %s\n", $key, $m['kind'], $m['why']);
    }
    echo "\n" . count(MUTANTS) . " mutant recipe(s). Every mechanically-enforced check needs one;\n"
        . "a gating check without one makes --self-test exit 1.\n";
    exit(0);
}

// ===========================================================================
// --self-test: prove every check rejects a broken tree and accepts a clean one
// ===========================================================================
if ($selfTest) {
    $records = extractManifest($ROOT, $EXTRACTOR);
    $pdo = pdoOrNull($ROOT);
    $proved = 0; $unproven = 0; $broken = 0; $needDb = 0;

    echo "invariants_report --self-test\n";
    echo str_repeat('=', 72) . "\n";

    foreach ($records as $rec) {
        $key = $rec['inv'] . '/' . $rec['seq'];
        if ($rec['gating'] === 'manual' || $rec['kind'] === 'none') {
            printf("  %-10s %-12s %s\n", $key, 'MANUAL', 'no executable check in the document — nothing to mutate');
            continue;
        }
        if (!isset(MUTANTS[$key])) {
            printf("  %-10s %-12s %s\n", $key, 'UNPROVEN', 'no mutant recipe registered — this check has never been shown to fail');
            $unproven++;
            continue;
        }
        $m = MUTANTS[$key];

        if ($m['kind'] === 'tree') {
            $clean = buildTree($m['dirs'], $m['clean']);
            $dirty = buildTree($m['dirs'], $m['dirty']);
            $rc = runProcess([resolveShell(), '-c', $rec['cmd']], $clean);
            $rd = runProcess([resolveShell(), '-c', $rec['cmd']], $dirty);
            $cleanVerdict = verdictFor($rec, ['out' => $rc['out'], 'err' => '', 'code' => $rc['code'], 'ran' => true]);
            $dirtyVerdict = verdictFor($rec, ['out' => $rd['out'], 'err' => '', 'code' => $rd['code'], 'ran' => true]);
            rrmdir($clean); rrmdir($dirty);
            if ($dirtyVerdict === 'FAIL' && $cleanVerdict === 'PASS') {
                printf("  %-10s %-12s rejects: %s\n", $key, 'PROVED', $m['why']);
                $proved++;
            } else {
                printf("  %-10s %-12s mutant verdict=%s (want FAIL), control verdict=%s (want PASS) — %s\n",
                    $key, 'BROKEN', $dirtyVerdict, $cleanVerdict, $m['why']);
                $broken++;
            }
            continue;
        }

        if ($m['kind'] === 'sql') {
            if (!$pdo instanceof PDO) {
                printf("  %-10s %-12s %s (no database reachable)\n", $key, 'UNPROVEN', $m['why']);
                $needDb++;
                continue;
            }
            $r = proveSqlMutant($pdo, $rec, $m);
            if ($r === true) {
                printf("  %-10s %-12s rejects: %s\n", $key, 'PROVED', $m['why']);
                $proved++;
            } else {
                printf("  %-10s %-12s %s — %s\n", $key, 'BROKEN', $m['why'], (string)$r);
                $broken++;
            }
            continue;
        }

        // runner mutant: a stub that exits 1 must be classified FAIL, and a stub
        // that exits 0 must be classified PASS. This proves the assertion
        // wiring, NOT the delegate. Labelled as such.
        $dir = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'invstub_' . bin2hex(random_bytes(4));
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/red.php', "<?php exit(1);\n");
        file_put_contents($dir . '/green.php', "<?php exit(0);\n");
        $red = runProcess([PHP_BINARY, $dir . '/red.php'], $dir);
        $green = runProcess([PHP_BINARY, $dir . '/green.php'], $dir);
        $vRed = verdictFor(['assert' => 'exit0'], ['out' => '', 'err' => '', 'code' => $red['code'], 'ran' => true]);
        $vGreen = verdictFor(['assert' => 'exit0'], ['out' => '', 'err' => '', 'code' => $green['code'], 'ran' => true]);
        rrmdir($dir);
        if ($vRed === 'FAIL' && $vGreen === 'PASS') {
            printf("  %-10s %-12s rejects: %s  [runner-assertion only — the delegate carries its own evidence]\n",
                $key, 'PROVED', $m['why']);
            $proved++;
        } else {
            printf("  %-10s %-12s runner assertion did not classify a non-zero exit as FAIL\n", $key, 'BROKEN');
            $broken++;
        }
    }

    echo str_repeat('=', 72) . "\n";
    echo "  $proved proved · $broken broken · " . ($unproven + $needDb) . " unproven"
        . ($needDb > 0 ? " ($needDb need a database)" : '') . "\n";
    if ($broken > 0) {
        echo "  RESULT: BROKEN — a check that does not reject its own mutant is not a gate.\n";
        exit(1);
    }
    if ($unproven > 0) {
        echo "  RESULT: INCOMPLETE — a mechanically-enforced check has no mutant recipe.\n";
        exit(1);
    }
    if ($needDb > 0) {
        echo "  RESULT: INCOMPLETE — the SQL mutants need a reachable database. Not a pass.\n";
        exit(3);
    }
    echo "  RESULT: every mechanically-enforced check rejects its mutant and accepts its control.\n";
    exit(0);
}

// ===========================================================================
// Gate run
// ===========================================================================
$records = extractManifest($ROOT, $EXTRACTOR);
$needsDb = false;
foreach ($records as $rec) { if ($rec['kind'] === 'sql') { $needsDb = true; } }
$pdo = $needsDb ? pdoOrNull($ROOT) : null;

$results = [];
$red = 0; $infoRed = 0; $manual = []; $gatingRan = 0; $couldNotRun = 0;

echo "invariants_report: docs/ARCHITECTURAL_INVARIANTS.md, extracted verbatim\n";
foreach ($records as $rec) {
    $key = $rec['inv'] . '/' . $rec['seq'];

    if ($rec['gating'] === 'manual') {
        $manual[] = $rec['inv'] . '  ' . $rec['note'];
        printf("  %-10s %-14s %s\n", $key, 'MANUAL', $rec['note']);
        $results[] = ['check' => $key, 'gating' => 'manual', 'verdict' => 'MANUAL', 'note' => $rec['note']];
        continue;
    }

    $res = executeCheck($rec, $ROOT, $pdo);
    $verdict = verdictFor($rec, $res);

    if ($verdict === 'COULD-NOT-RUN') {
        // Fail-closed, and loudly. An unreachable database does not turn INV-1/2
        // and INV-6 into passes; it makes the run unable to certify anything.
        $couldNotRun++;
        if ($rec['gating'] === 'gate') { $red = 1; }
        printf("  %-10s %-14s (gating) %s\n", $key, 'COULD-NOT-RUN', trim($res['err']));
        $results[] = ['check' => $key, 'gating' => $rec['gating'], 'verdict' => 'COULD-NOT-RUN',
                      'detail' => trim($res['err']), 'note' => $rec['note']];
        continue;
    }

    if ($rec['gating'] === 'gate') { $gatingRan++; }

    $label = $rec['gating'] === 'info' ? '(informational) ' : '';
    if ($verdict === 'PASS') {
        printf("  %-10s %-14s %s%s\n", $key, 'PASS', $label, $rec['note']);
    } elseif ($verdict === 'UNENFORCED') {
        printf("  %-10s %-14s %s%s\n", $key, 'UNENFORCED', $label, $rec['note']);
    } else {
        if ($rec['gating'] === 'info') {
            $infoRed++;
            printf("  %-10s %-14s %s\n", $key, 'info-RED', $rec['note']);
        } else {
            $red = 1;
            printf("  %-10s %-14s (gating) %s\n", $key, 'FAIL', $rec['note']);
        }
        echo "      command: " . $rec['cmd'] . "\n";
        foreach (array_slice(preg_split('/\R/', trim($res['out'])), 0, 40) as $l) {
            if ($l !== '') { echo "      | $l\n"; }
        }
        if (trim($res['err']) !== '') {
            foreach (array_slice(preg_split('/\R/', trim($res['err'])), 0, 10) as $l) {
                if ($l !== '') { echo "      ! $l\n"; }
            }
        }
    }
    $results[] = [
        'check'   => $key,
        'kind'    => $rec['kind'],
        'assert'  => $rec['assert'],
        'gating'  => $rec['gating'],
        'verdict' => $verdict,
        'note'    => $rec['note'],
        'command' => $rec['cmd'],
        'output'  => $verdict === 'PASS' ? '' : substr(trim($res['out']), 0, 8000),
    ];
}

// A run that gated nothing is not a green run.
if ($gatingRan === 0) {
    fwrite(STDERR, "invariants_report: zero gating checks executed — nothing was enforced, so this cannot "
        . "be reported as GREEN. Exit 2.\n");
    exit(2);
}

if (!is_dir($REPORT_DIR) && !@mkdir($REPORT_DIR, 0777, true)) {
    fwrite(STDERR, "invariants_report: cannot create reports/\n");
    exit(2);
}
$file = $REPORT_DIR . '/invariants-' . date('Ymd-His') . '.json';
@file_put_contents($file, json_encode([
    'report'            => 'invariants',
    'generated_at'      => gmdate('c'),
    'source_document'   => 'docs/ARCHITECTURAL_INVARIANTS.md',
    'extracted_by'      => 'scripts/ci/inv_extract.php (verbatim; no check text is stored in the report script)',
    'gating_executed'   => $gatingRan,
    'could_not_run'     => $couldNotRun,
    'informational_red' => $infoRed,
    'not_mechanically_enforced' => $manual,
    'checks'            => $results,
    'verdict'           => $red === 0 ? 'GREEN' : 'RED',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

if ($manual !== []) {
    echo "invariants_report: " . count($manual) . " invariant(s) are NOT mechanically enforced and still need a "
        . "human: " . implode('; ', $manual) . "\n";
}
if ($infoRed > 0) {
    echo "invariants_report: $infoRed informational check(s) RED. They do not gate because the document "
        . "conditions them on a unit phase-status.json does not yet read 'verified'; they gate the moment it does.\n";
}
if ($couldNotRun > 0) {
    echo "invariants_report: $couldNotRun check(s) COULD NOT RUN. Counted as RED, never as passes.\n";
}

$status = $red === 0 ? 'GREEN' : 'RED';
echo "invariants_report: $status $file\n";
exit($red);

// ---------------------------------------------------------------------------
// Self-test helpers. Defined after the gate path exits so a gate run never
// touches them; PHP hoists function declarations, so order is presentation.
// ---------------------------------------------------------------------------

/** Remember (and read back) what the bootstrap probe actually said. */
function probeDiagnostic(?string $set = null): string
{
    static $last = '(probe not run)';
    if ($set !== null) { $last = $set; }
    return $last;
}

/** Build a throwaway tree of directories plus files. Returns its root. */
function buildTree(array $dirs, array $files): string
{
    $root = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'invtree_' . bin2hex(random_bytes(5));
    foreach ($dirs as $d) { @mkdir($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $d), 0777, true); }
    foreach ($files as $rel => $content) {
        $p = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        @mkdir(dirname($p), 0777, true);
        file_put_contents($p, $content);
    }
    return $root;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) { return; }
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') { continue; }
        $p = $dir . DIRECTORY_SEPARATOR . $e;
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

/**
 * Prove an SQL check by running the DOCUMENT'S OWN statement against throwaway
 * tables named exactly what the statement names — inside a schema created for
 * the purpose and dropped afterwards, so the real tables are never touched and
 * the statement never has to be rewritten. If the throwaway schema cannot be
 * created, that is reported as BROKEN rather than skipped: rewriting the
 * document's SQL to fit the fixture would be the paraphrase drift this whole
 * unit exists to prevent.
 */
function proveSqlMutant(PDO $pdo, array $rec, array $m)
{
    $schema = '_inv_mutant_' . bin2hex(random_bytes(4));
    try {
        $pdo->exec("CREATE DATABASE `$schema`");
    } catch (Throwable $e) {
        return "cannot create a throwaway schema (" . $e->getMessage() . ")";
    }
    $restore = null;
    try {
        $restore = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $pdo->exec("USE `$schema`");

        if ($rec['inv'] === 'INV-1') {
            $pdo->exec('CREATE TABLE config_components (inventory_type VARCHAR(32), inventory_id INT)');
            $pdo->exec("INSERT INTO config_components VALUES ('cpu', 7)");
            $clean = $pdo->query($rec['cmd'])->fetchAll(PDO::FETCH_NUM);
            $pdo->exec("INSERT INTO config_components VALUES ('cpu', 7)");
            $dirty = $pdo->query($rec['cmd'])->fetchAll(PDO::FETCH_NUM);
        } elseif ($rec['inv'] === 'INV-6') {
            $pdo->exec('CREATE TABLE server_configurations (config_uuid VARCHAR(64), revision INT)');
            $pdo->exec('CREATE TABLE config_events (config_uuid VARCHAR(64), revision INT)');
            $pdo->exec("INSERT INTO server_configurations VALUES ('c1', 2)");
            $pdo->exec("INSERT INTO config_events VALUES ('c1', 1), ('c1', 2)");
            $clean = $pdo->query($rec['cmd'])->fetchAll(PDO::FETCH_NUM);
            $pdo->exec("UPDATE server_configurations SET revision = 5 WHERE config_uuid = 'c1'");
            $dirty = $pdo->query($rec['cmd'])->fetchAll(PDO::FETCH_NUM);
        } else {
            return "no SQL fixture registered for {$rec['inv']}";
        }
    } catch (Throwable $e) {
        $r = "the document's statement did not execute against the fixture (" . $e->getMessage() . ")";
        try { if ($restore) { $pdo->exec("USE `$restore`"); } $pdo->exec("DROP DATABASE IF EXISTS `$schema`"); } catch (Throwable $x) {}
        return $r;
    }
    try { if ($restore) { $pdo->exec("USE `$restore`"); } $pdo->exec("DROP DATABASE IF EXISTS `$schema`"); } catch (Throwable $x) {}

    if (count($dirty) > 0 && count($clean) === 0) { return true; }
    return "mutant returned " . count($dirty) . " row(s) (want >0), control returned " . count($clean) . " row(s) (want 0)";
}
