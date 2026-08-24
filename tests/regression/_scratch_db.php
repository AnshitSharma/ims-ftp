<?php
/**
 * _scratch_db.php — shared scratch-DB connect helper for the command-layer
 * regression tests (add/remove/replace/finalize_command_test.php). Not a
 * production file (tests/ is never deployed, per CLAUDE.md) and not itself
 * a test — just the connection boilerplate every one of them needs, so a
 * DB-capable session doesn't have to duplicate/maintain it four times.
 *
 * Same GOLDEN_DB_* env override / default convention as
 * tests/characterize_compatibility.php (127.0.0.1 / ims_compat_golden / root / "").
 * Returns null (never throws) on any connection failure so callers can print
 * their own SKIPPED lines and exit 0 rather than fail the whole suite.
 */

/**
 * scratch_db_password() — THE single place the scratch credential is resolved.
 *
 * WHY (2026-08-24, the F-11/F-18/F-21/F-24 family again): until today exactly
 * ONE suite (serial_less_unit_identity_test.php) honoured GOLDEN_DB_PASS_FILE.
 * Every other DB-backed suite carried a copy-pasted
 * `getenv('GOLDEN_DB_PASS') ?: ''` and nothing else. So a session that followed
 * this project's OWN documented fixture instruction — "put the scratch password
 * in a file, point GOLDEN_DB_PASS_FILE at it" (run_serial_less_check.php's
 * header, migration/00-overview/SESSION_PROTOCOL.md) — handed all of them an
 * EMPTY password, which the server refuses, which each suite then reported as
 * "scratch DB unreachable" and self-skipped. Nine suites at once printed a
 * self-skip that meant "the credential resolver stopped looking", not "there is
 * no database" — a check reporting green because it stopped looking, which is
 * the defect class this repo has now logged five times.
 *
 * One resolver cannot drift from itself. Every DB-backed suite calls this.
 *
 * Resolution order (deliberately GOLDEN_DB_PASS first — these are exactly the
 * semantics lifted from serial_less_unit_identity_test.php, the one suite that
 * already had it right):
 *   1. GOLDEN_DB_PASS when set and non-empty;
 *   2. else the trimmed contents of GOLDEN_DB_PASS_FILE when it names a
 *      readable, non-blank file;
 *   3. else '' — no credential configured.
 *
 * Returns '' rather than throwing. Suites that must REFUSE to connect
 * passwordless (serial_less_unit_identity_test.php) test for '' themselves;
 * that refusal is theirs to keep and not this helper's to impose on suites which
 * legitimately run against a passwordless local scratch instance.
 */
function scratch_db_password(): string
{
    $pass = getenv('GOLDEN_DB_PASS');
    if (is_string($pass) && $pass !== '') {
        return $pass;
    }

    $passFile = getenv('GOLDEN_DB_PASS_FILE');
    if (is_string($passFile) && $passFile !== '' && is_readable($passFile)) {
        $contents = @file_get_contents($passFile);
        if (is_string($contents) && trim($contents) !== '') {
            return trim($contents);
        }
    }

    return '';
}

function scratch_db_connect(): ?PDO
{
    $host = getenv('GOLDEN_DB_HOST') ?: '127.0.0.1';
    $name = getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden';
    $user = getenv('GOLDEN_DB_USER') ?: 'root';
    $pass = scratch_db_password();

    try {
        return new PDO(
            "mysql:host=$host;dbname=$name;charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * The command-layer tests' fixture requirements, checked BEFORE any fixture
 * write. Returns null when the replica is usable, or a human-readable reason
 * when it is not.
 *
 * WHY (2026-07-29, F-27's neighbour): scratch_db_connect() guards the CONNECTION
 * only. A reachable replica that predates P2 sailed past it and then took the
 * test out with an uncaught PDOException mid-fixture — exit 255, every result
 * printed above it lost, and a gate that shells these out reading RED for a
 * reason that has nothing to do with the code under test.
 *
 * The inverse was worse and is the reason this is a shared helper rather than
 * four local try/catches: with mysqld simply not running, all four files print
 * "ALL CHECKS PASS" and exit 0 having executed no DB assertion at all. "Cannot
 * run" and "ran and agreed" must not produce the same output — that is F-10 at
 * the suite level. A SKIPPED line naming the missing table is the difference.
 *
 * @return string|null null = fixture usable
 */
/**
 * For the DB-backed suites that build their own PDO and then use it
 * unconditionally (dual_write, fail_closed, finalized_immutability,
 * ledger_dual_write, nested_transaction, state_guard). Returns a usable
 * connection or exits 0 having said, in one unmistakable line, that it ran
 * nothing.
 *
 * Those six exited 255 with an uncaught PDOException in EVERY environment
 * without a fully provisioned replica — no DB, or a stale one, made no
 * difference. That is not a test failure and must not be reported as one; nor
 * may it be reported as "ALL CHECKS PASS", which is what the four helper-using
 * suites next door did when the DB was merely absent.
 *
 * The output line is deliberately "SKIPPED SUITE", distinct from the per-check
 * "SKIPPED", so a runner can count suites that proved nothing apart from checks
 * that were individually skipped.
 */
function scratch_db_or_skip(?PDO $pdo, string $suiteLabel): PDO
{
    $reason = null;
    if ($pdo === null) {
        $reason = 'scratch DB unreachable';
    } elseif (($gap = scratch_db_schema_gap($pdo)) !== null) {
        $reason = $gap;
    }
    if ($reason === null) {
        return $pdo;
    }
    echo "  SKIPPED SUITE  $suiteLabel — $reason\n";
    echo "\nSKIPPED: 0 check(s) run — this suite proved NOTHING in this environment\n";
    exit(0);
}

function scratch_db_schema_gap(PDO $pdo): ?string
{
    $required = [
        'config_components'     => ['config_uuid', 'component_type', 'spec_uuid', 'removed_at'],
        'config_events'         => ['config_uuid'],
        'server_configurations' => ['config_uuid', 'server_name', 'configuration_status'],
        // The inventory side these tests build fixtures from. raminventory is
        // listed because finalize_command_test's two-connection concurrency
        // scenarios select an available RAM unit before anything else, and a
        // replica can carry the config tables without the inventory ones.
        'raminventory'          => ['UUID', 'Status'],
    ];

    foreach ($required as $table => $columns) {
        try {
            $stmt = $pdo->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute([$table]);
            $present = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            return "cannot inspect schema ({$e->getMessage()})";
        }
        if ($present === []) {
            return "table '$table' is absent — replica predates P2";
        }
        $missing = array_diff($columns, $present);
        if ($missing !== []) {
            return "table '$table' is missing column(s): " . implode(', ', $missing);
        }
    }
    return null;
}
