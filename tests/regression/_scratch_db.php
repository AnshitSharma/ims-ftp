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
function scratch_db_connect(): ?PDO
{
    $host = getenv('GOLDEN_DB_HOST') ?: '127.0.0.1';
    $name = getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden';
    $user = getenv('GOLDEN_DB_USER') ?: 'root';
    $pass = getenv('GOLDEN_DB_PASS');
    $pass = is_string($pass) ? $pass : '';

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
