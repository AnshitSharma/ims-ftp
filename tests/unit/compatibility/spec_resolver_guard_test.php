<?php
/**
 * spec_resolver_guard_test.php
 *
 * This bug class has now been fixed FOUR times, once per resolver: a new place that reads
 * motherboard-level-3.json or chasis-level-3.json directly, does not consult
 * PlatformSpecIndex, and therefore returns null for any board or chassis that came inside
 * a compute platform. Each fix was correct and each left the next resolver free to
 * reintroduce it.
 *
 * So this test does not check behaviour -- it checks the SHAPE of the code: every file
 * that resolves a 'motherboard' or 'chassis' spec path must also reference
 * PlatformSpecIndex. A fifth resolver fails here, at the moment it is written.
 *
 * Static analysis only. No DB, no ims-data. Exit 0 = all pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 3);

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

/** Every .php under core/ and api/, excluding the index itself and the tests. */
function phpFiles($root) {
    $out = [];
    foreach (['core', 'api'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));
        foreach ($it as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $out[] = $file->getPathname();
            }
        }
    }
    sort($out);
    return $out;
}

$files = phpFiles($ROOT);
check('found PHP sources to scan (a zero-file scan would pass vacuously)', count($files) > 20);

// A file "resolves board/chassis specs" if it asks ComponentSpecPaths for a path that
// can BE the board or chassis catalog -- that is the single door onto those files:
//   - getPath('motherboard') / getPath('chassis')  : literally those catalogs
//   - getPath($type)                               : a dynamic resolver, so possibly them
//   - getAll()                                     : the whole map, so certainly them
// getPath('caddy') and friends are literal non-board/chassis lookups and are exempt --
// they cannot return a platform-owned spec path, so they cannot reintroduce the bug.
$pattern = "/ComponentSpecPaths::(getAll|getPath\\s*\\(\\s*(\\\$|['\\\"](motherboard|chassis)['\\\"]))/";

$resolvers = [];
foreach ($files as $file) {
    $src = file_get_contents($file);
    if ($src === false) {
        continue;
    }
    if (preg_match($pattern, $src)) {
        $resolvers[$file] = strpos($src, 'PlatformSpecIndex') !== false;
    }
}

// PlatformSpecIndex is the index itself; it must not be asked to consult itself.
unset($resolvers[$ROOT . '/core/models/components/PlatformSpecIndex.php']);
$resolvers = array_filter($resolvers, function ($k) {
    return basename($k) !== 'PlatformSpecIndex.php';
}, ARRAY_FILTER_USE_KEY);

echo "\n-- Files resolving motherboard/chassis spec paths --\n";
$blind = [];
foreach ($resolvers as $file => $awareOfPlatform) {
    $rel = str_replace('\\', '/', substr($file, strlen($ROOT) + 1));
    echo '  ' . ($awareOfPlatform ? '[platform-aware]' : '[BLIND]        ') . '  ' . $rel . "\n";
    if (!$awareOfPlatform) {
        $blind[] = $rel;
    }
}

check('at least the four known resolvers were detected (the scan is actually finding them)',
    count($resolvers) >= 4);

check('no spec resolver is blind to PlatformSpecIndex'
    . (empty($blind) ? '' : ' -- BLIND: ' . implode(', ', $blind)),
    empty($blind));

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
