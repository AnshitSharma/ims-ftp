<?php
/**
 * require_paths_test.php — every `require __DIR__ . '...'` must resolve.
 *
 * Written after a production 500 on server-finalize-config / server-validate-config:
 * ServerBuilder::validateStorageConnections() required
 * __DIR__ . '/StorageConnectionValidator.php', but that class lives in
 * core/models/compatibility/, not core/models/server/. A failed require is a PHP
 * \Error, not an Exception, so it blew straight past the `catch (Exception)` in
 * validateConfigurationComprehensive() AND the one in the API handler, surfacing
 * as api.php's generic "Internal server error".
 *
 * It stayed invisible because the require sits BELOW an early return that fires
 * when the config has no storage — so it only ever executed for a config that
 * actually had a storage component. A static path check costs nothing and does
 * not depend on hitting the right branch at runtime.
 *
 * Exit 0 = every require path resolves.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
$SCAN = ['core', 'api', 'scripts'];

$broken = [];
$scanned = 0;

foreach ($SCAN as $sub) {
    $dir = "$ROOT/$sub";
    if (!is_dir($dir)) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') { continue; }
        $path = $file->getRealPath();
        $base = dirname($path);
        foreach (file($path) as $i => $line) {
            // only __DIR__-relative requires are statically checkable; variable
            // paths (require $x) are out of scope by design
            if (preg_match('/require(_once)?\s*\(?\s*__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]/', $line, $m)) {
                $scanned++;
                if (!file_exists($base . $m[2])) {
                    $rel = str_replace($ROOT . DIRECTORY_SEPARATOR, '', $path);
                    $broken[] = sprintf('%s:%d -> %s', $rel, $i + 1, $m[2]);
                }
            }
        }
    }
}

echo "-- __DIR__-relative require paths --\n";
echo "  scanned: $scanned\n";

if ($broken) {
    foreach ($broken as $b) { echo "  FAIL  unresolvable require: $b\n"; }
    echo "\n" . count($broken) . " FAILURE(S)\n";
    exit(1);
}

echo "  PASS  all $scanned require paths resolve\n";
echo "\nALL CHECKS PASS\n";
exit(0);
