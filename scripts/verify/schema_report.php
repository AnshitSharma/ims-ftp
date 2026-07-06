<?php
/**
 * schema_report.php — 11-verification/README.md #1.
 *
 * Green iff every table/column/index in scripts/verify/expected_schema.json exists with
 * the expected definition (information_schema comparison), and every column with a
 * "collation_matches" pointer has a collation exactly equal to the referenced column's
 * (the bug class already hit once: seeder 2026_06_17_002, "Illegal mix of collations" #1267).
 *
 * Usage:
 *   php scripts/verify/schema_report.php              # writes reports/schema-<ts>.json
 *   php scripts/verify/schema_report.php --self-test   # checks against a deliberately wrong
 *                                                       # expectation (wrong collation), proving
 *                                                       # this report's own logic detects it.
 *
 * Exit: 0 = green, 1 = red (or self-test failed to detect its induced defect).
 */

declare(strict_types=1);

$bootstrap = __DIR__ . '/../../core/config/app.php';
if (!file_exists($bootstrap)) {
    fwrite(STDERR, "Cannot locate core/config/app.php from " . __DIR__ . "\n");
    exit(2);
}
require_once $bootstrap;

global $pdo;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "PDO connection not available after bootstrap.\n");
    exit(2);
}

function loadExpectedSchema(string $path): array {
    if (!is_file($path)) {
        fwrite(STDERR, "Cannot locate expected_schema.json at $path\n");
        exit(2);
    }
    $decoded = json_decode(file_get_contents($path), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "expected_schema.json is not valid JSON\n");
        exit(2);
    }
    return $decoded;
}

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
    return (bool)$stmt->fetch();
}

function getColumns(PDO $pdo, string $table): array {
    $stmt = $pdo->prepare('SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLLATION_NAME
                            FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$row['COLUMN_NAME']] = $row;
    }
    return $out;
}

/**
 * MariaDB stores JSON columns as `longtext` + a `json_valid(col)` CHECK constraint (JSON is an
 * alias there, not a native type); MySQL 5.7+ reports DATA_TYPE = 'json' directly. Treat both as
 * satisfying an expected data_type of "json".
 */
function isJsonValidColumn(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT cc.CHECK_CLAUSE
                            FROM information_schema.CHECK_CONSTRAINTS cc
                            JOIN information_schema.TABLE_CONSTRAINTS tc
                              ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
                            WHERE cc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = ?");
    $stmt->execute([$table]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (stripos($row['CHECK_CLAUSE'], 'json_valid') !== false && stripos($row['CHECK_CLAUSE'], "`$column`") !== false) {
            return true;
        }
    }
    return false;
}

/** @return array<string, array{columns: string[], unique: bool}> keyed by index name */
function getIndexes(PDO $pdo, string $table): array {
    $stmt = $pdo->prepare('SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX
                            FROM information_schema.STATISTICS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                            ORDER BY INDEX_NAME, SEQ_IN_INDEX');
    $stmt->execute([$table]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = $row['INDEX_NAME'];
        if (!isset($out[$name])) {
            $out[$name] = ['columns' => [], 'unique' => ((int)$row['NON_UNIQUE']) === 0];
        }
        $out[$name]['columns'][] = $row['COLUMN_NAME'];
    }
    return $out;
}

/**
 * @return array violations found comparing $expected tables against the live DB.
 */
function runChecks(PDO $pdo, array $expected): array {
    $violations = [];

    foreach ($expected['tables'] as $tableName => $tableSpec) {
        if (!tableExists($pdo, $tableName)) {
            $violations[] = ['table' => $tableName, 'issue' => 'table_missing'];
            continue;
        }

        $liveColumns = getColumns($pdo, $tableName);
        foreach ($tableSpec['columns'] ?? [] as $colName => $colSpec) {
            if (!isset($liveColumns[$colName])) {
                $violations[] = ['table' => $tableName, 'column' => $colName, 'issue' => 'column_missing'];
                continue;
            }
            $live = $liveColumns[$colName];

            if (isset($colSpec['data_type']) && strtolower($live['DATA_TYPE']) !== strtolower($colSpec['data_type'])) {
                $isMariaDbJsonAlias = strtolower($colSpec['data_type']) === 'json'
                    && strtolower($live['DATA_TYPE']) === 'longtext'
                    && isJsonValidColumn($pdo, $tableName, $colName);
                if (!$isMariaDbJsonAlias) {
                    $violations[] = [
                        'table' => $tableName, 'column' => $colName, 'issue' => 'data_type_mismatch',
                        'expected' => $colSpec['data_type'], 'actual' => $live['DATA_TYPE'],
                    ];
                }
            }

            if (isset($colSpec['nullable'])) {
                $liveNullable = $live['IS_NULLABLE'] === 'YES';
                if ($liveNullable !== $colSpec['nullable']) {
                    $violations[] = [
                        'table' => $tableName, 'column' => $colName, 'issue' => 'nullability_mismatch',
                        'expected' => $colSpec['nullable'], 'actual' => $liveNullable,
                    ];
                }
            }

            if (isset($colSpec['collation_matches'])) {
                [$refTable, $refColumn] = explode('.', $colSpec['collation_matches'], 2);
                $refColumns = getColumns($pdo, $refTable);
                $refCollation = $refColumns[$refColumn]['COLLATION_NAME'] ?? null;
                if ($refCollation === null) {
                    $violations[] = [
                        'table' => $tableName, 'column' => $colName, 'issue' => 'collation_reference_missing',
                        'reference' => $colSpec['collation_matches'],
                    ];
                } elseif ($live['COLLATION_NAME'] !== $refCollation) {
                    $violations[] = [
                        'table' => $tableName, 'column' => $colName, 'issue' => 'collation_mismatch',
                        'expected' => $refCollation, 'actual' => $live['COLLATION_NAME'],
                        'reference' => $colSpec['collation_matches'],
                    ];
                }
            }
        }

        $liveIndexes = getIndexes($pdo, $tableName);
        foreach ($tableSpec['indexes'] ?? [] as $idxName => $idxSpec) {
            if (!isset($liveIndexes[$idxName])) {
                $violations[] = ['table' => $tableName, 'index' => $idxName, 'issue' => 'index_missing'];
                continue;
            }
            $live = $liveIndexes[$idxName];
            if ($live['columns'] !== $idxSpec['columns']) {
                $violations[] = [
                    'table' => $tableName, 'index' => $idxName, 'issue' => 'index_columns_mismatch',
                    'expected' => $idxSpec['columns'], 'actual' => $live['columns'],
                ];
            }
            if (isset($idxSpec['unique']) && $live['unique'] !== $idxSpec['unique']) {
                $violations[] = [
                    'table' => $tableName, 'index' => $idxName, 'issue' => 'index_uniqueness_mismatch',
                    'expected' => $idxSpec['unique'], 'actual' => $live['unique'],
                ];
            }
        }
    }

    return $violations;
}

function writeReport(array $violations, bool $selfTest): string {
    $reportsDir = __DIR__ . '/../../reports';
    if (!is_dir($reportsDir)) { mkdir($reportsDir, 0755, true); }
    $file = $reportsDir . '/schema-' . date('Ymd-His') . ($selfTest ? '-selftest' : '') . '.json';
    file_put_contents($file, json_encode([
        'report' => 'schema_report',
        'generated_at' => date('c'),
        'self_test' => $selfTest,
        'violation_count' => count($violations),
        'violations' => $violations,
        'status' => empty($violations) ? 'GREEN' : 'RED',
    ], JSON_PRETTY_PRINT));
    return $file;
}

$expectedPath = __DIR__ . '/expected_schema.json';
$expected = loadExpectedSchema($expectedPath);

if (in_array('--self-test', $argv, true)) {
    // Induce a defect purely in-memory (never touches expected_schema.json on disk):
    // claim config_components.config_uuid must match a collation it can't possibly have.
    $badExpected = $expected;
    if (isset($badExpected['tables']['config_components']['columns']['config_uuid'])) {
        unset($badExpected['tables']['config_components']['columns']['config_uuid']['collation_matches']);
        $badExpected['tables']['config_components']['columns']['config_uuid']['data_type'] = 'this_type_does_not_exist';
    }
    $violations = runChecks($pdo, $badExpected);
    writeReport($violations, true);

    $caught = false;
    foreach ($violations as $v) {
        if (($v['table'] ?? null) === 'config_components' && ($v['column'] ?? null) === 'config_uuid' && ($v['issue'] ?? null) === 'data_type_mismatch') {
            $caught = true;
            break;
        }
    }

    if ($caught) {
        echo "schema_report --self-test: PASS (induced defect correctly flagged)\n";
        exit(1); // intentional: proves detection
    }
    echo "schema_report --self-test: FAIL (induced defect NOT flagged — checker is broken)\n";
    exit(0);
}

$violations = runChecks($pdo, $expected);
$file = writeReport($violations, false);
$status = empty($violations) ? 'GREEN' : 'RED';
echo "schema_report: $status $file\n";
exit(empty($violations) ? 0 : 1);
