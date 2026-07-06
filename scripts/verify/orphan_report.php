<?php
/**
 * orphan_report.php — 11-verification/README.md #3.
 * Green iff scripts/audit-orphans.php dry-run exits 0. (The post-P1 config_components FK check
 * is added once that table exists.) This report never invokes --fix — it only observes.
 *
 * Usage: php scripts/verify/orphan_report.php
 * Exit: 0 = green, 1 = red (orphans found).
 */

declare(strict_types=1);

$auditScript = __DIR__ . '/../audit-orphans.php';
if (!file_exists($auditScript)) {
    fwrite(STDERR, "Cannot locate scripts/audit-orphans.php\n");
    exit(2);
}

$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open(['php', $auditScript], $descriptors, $pipes, dirname($auditScript));
if (!is_resource($process)) {
    fwrite(STDERR, "Failed to launch audit-orphans.php\n");
    exit(2);
}

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

preg_match('/Scanned (\d+) configurations, (\d+) component references\./', $stdout, $scanMatch);
preg_match('/Orphans found: (\d+)/', $stdout, $countMatch);

$reportsDir = __DIR__ . '/../../reports';
if (!is_dir($reportsDir)) { mkdir($reportsDir, 0755, true); }
$file = $reportsDir . '/orphan-' . date('Ymd-His') . '.json';
file_put_contents($file, json_encode([
    'report' => 'orphan_report',
    'generated_at' => date('c'),
    'wrapped_script' => 'scripts/audit-orphans.php',
    'configs_scanned' => isset($scanMatch[1]) ? (int)$scanMatch[1] : null,
    'refs_scanned' => isset($scanMatch[2]) ? (int)$scanMatch[2] : null,
    'orphan_count' => isset($countMatch[1]) ? (int)$countMatch[1] : null,
    'exit_code' => $exitCode,
    'stdout' => $stdout,
    'stderr' => $stderr,
    'status' => $exitCode === 0 ? 'GREEN' : 'RED',
], JSON_PRETTY_PRINT));

$status = $exitCode === 0 ? 'GREEN' : 'RED';
echo "orphan_report: $status $file\n";
exit($exitCode === 0 ? 0 : 1);
