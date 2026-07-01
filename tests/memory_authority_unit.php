<?php
/**
 * memory_authority_unit.php — focused unit test for the Phase-3 MemoryAuthority.
 *
 * The finalize-time path (validateConfigurationEnhanced) previously only called
 * validateRAMTypeCompatibility (motherboard-type check only).  MemoryAuthority makes
 * the finalize path call the SAME checkRAMDecentralizedCompatibility used by add-time,
 * adding form-factor, module-type mixing (RDIMM/UDIMM), and DDR-generation
 * intersection across ALL CPUs + motherboard.
 *
 * Tests here verify:
 *   1. MemoryAuthority::mode() env-flag reader (off/shadow/enforce/unknown → off)
 *   2. The comprehensive check is MORE STRICT than the legacy check in key cases:
 *      a) RDIMM existing + UDIMM candidate → authority=incompatible, legacy=compatible
 *      b) LRDIMM existing + RDIMM candidate → authority=incompatible (mixed buffer types)
 *   3. Legacy check misses module-type issues (confirms the gap being closed)
 *   4. Fail-open: unknown UUID → compatible=true (no fabricated rejection)
 *   5. Empty existing components → compatible regardless of type
 *   6. checkRAMDecentralizedCompatibility does NOT check slot count (separate concern)
 *
 * Uses the scratch DB (ims_compat_golden) for real JSON spec resolution.
 * All known UUIDs are from ims-data/ram/ram_detail.json.
 *
 * Exit 0 = all pass; exit 1 = a failure.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT   = dirname(__DIR__);
$dbHost = getenv('GOLDEN_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden';
$dbUser = getenv('GOLDEN_DB_USER') ?: 'root';
$dbPass = getenv('GOLDEN_DB_PASS');
if ($dbPass === false) { $dbPass = ''; }

putenv("DB_HOST=$dbHost"); putenv("DB_NAME=$dbName");
putenv("DB_USER=$dbUser"); putenv("DB_PASS=$dbPass");

$pdo = new PDO(
    "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
    $dbUser, $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require_once $ROOT . '/core/models/compatibility/ComponentCompatibility.php';
require_once $ROOT . '/core/models/compatibility/MemoryAuthority.php';

$compatibility = new ComponentCompatibility($pdo);

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// --- Known UUIDs from ims-data/ram/ram_detail.json ---
// (all form_factor = "DIMM (288-pin)", so form-factor checks pass for all)
$DDR4_RDIMM  = 'f1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c';  // DDR4, RDIMM,  32 GB
$DDR5_RDIMM  = 'a1b2c3d4-e5f6-7890-1234-567890abcdef';  // DDR5, RDIMM,  64 GB
$DDR5_LRDIMM = 'e5f6a7b8-c9d0-1234-5678-90abcdef1234';  // DDR5, LRDIMM, 128 GB
$DDR4_RDIMM2 = 'a2b3c4d5-e6f7-4a8b-9c0d-1e2f3a4b5c6d'; // DDR4, RDIMM,  32 GB
$DDR5_UDIMM  = 'd4e5f6a7-b8c9-0123-4567-890abcdef123';  // DDR5, UDIMM,  16 GB
$DDR4_UDIMM  = 'debda7e8-b44a-4633-97d2-38ad264dec7b';  // DDR4, UDIMM,  32 GB

// =====================================================================
// 1. Mode reader — MemoryAuthority::mode() (public static; no reflection needed)
// =====================================================================
echo "--- MemoryAuthority::mode() ---\n";

putenv('MEMORY_AUTHORITY_ENABLED=');
check("unset env → 'off'", MemoryAuthority::mode() === 'off');

putenv('MEMORY_AUTHORITY_ENABLED=off');
check("off → 'off'",     MemoryAuthority::mode() === 'off');

putenv('MEMORY_AUTHORITY_ENABLED=shadow');
check("shadow → 'shadow'", MemoryAuthority::mode() === 'shadow');

putenv('MEMORY_AUTHORITY_ENABLED=ENFORCE');
check("ENFORCE (upper) → 'enforce'", MemoryAuthority::mode() === 'enforce');

putenv('MEMORY_AUTHORITY_ENABLED=invalid');
check("invalid value → 'off'", MemoryAuthority::mode() === 'off');

// Reset flag
putenv('MEMORY_AUTHORITY_ENABLED=off');

// =====================================================================
// 2. Authority stricter than legacy: module-type mismatch
//    Legacy (validateRAMTypeCompatibility) only checks memory TYPE — it would
//    accept RDIMM + UDIMM of the same DDR generation. The authority catches it.
// =====================================================================
echo "\n--- Module-type mismatch (RDIMM existing + UDIMM candidate) ---\n";

// Existing = DDR4 RDIMM; candidate = DDR4 UDIMM
// Legacy: both are DDR4 → compatible (legacy misses module type)
// Authority: RDIMM ≠ UDIMM → incompatible
$existingDdr4Rdimm = [['type' => 'ram', 'uuid' => $DDR4_RDIMM]];
$r = $compatibility->checkRAMDecentralizedCompatibility(
    ['type' => 'ram', 'uuid' => $DDR4_UDIMM],
    $existingDdr4Rdimm
);
check("RDIMM+UDIMM mix: authority reports incompatible", $r['compatible'] === false);
check("RDIMM+UDIMM mix: issue mentions module type",
    !empty(array_filter($r['issues'], fn($i) => stripos($i, 'module') !== false ||
                                                  stripos($i, 'udimm') !== false ||
                                                  stripos($i, 'rdimm') !== false))
);

// =====================================================================
// 3. LRDIMM existing + RDIMM candidate → incompatible
// =====================================================================
echo "\n--- Module-type mismatch (LRDIMM existing + RDIMM candidate) ---\n";

$existingLrdimm = [['type' => 'ram', 'uuid' => $DDR5_LRDIMM]];
$r = $compatibility->checkRAMDecentralizedCompatibility(
    ['type' => 'ram', 'uuid' => $DDR5_RDIMM],
    $existingLrdimm
);
check("LRDIMM+RDIMM mix: authority reports incompatible", $r['compatible'] === false);

// =====================================================================
// 4. Matching module types → compatible
// =====================================================================
echo "\n--- Same module type (RDIMM+RDIMM, same DDR gen) ---\n";

$existingRdimm = [['type' => 'ram', 'uuid' => $DDR4_RDIMM]];
$r = $compatibility->checkRAMDecentralizedCompatibility(
    ['type' => 'ram', 'uuid' => $DDR4_RDIMM2],
    $existingRdimm
);
check("DDR4 RDIMM + DDR4 RDIMM: authority compatible", $r['compatible'] === true);

// =====================================================================
// 5. Fail-open: unknown UUID → compatible=true (no fabricated rejection)
//    Pass a real existing component so the early-return for empty-existing
//    doesn't fire before the UUID lookup that produces the warning.
// =====================================================================
echo "\n--- Fail-open: unknown UUID ---\n";

$r = $compatibility->checkRAMDecentralizedCompatibility(
    ['type' => 'ram', 'uuid' => 'not-a-real-uuid-at-all'],
    [['type' => 'ram', 'uuid' => $DDR4_RDIMM]]  // non-empty existing triggers the lookup
);
check("Unknown UUID → compatible=true (fail-open)", $r['compatible'] === true);
check("Unknown UUID → has warning (not silent)", !empty($r['warnings']));

// =====================================================================
// 6. Empty existing → always compatible
// =====================================================================
echo "\n--- Empty existing components ---\n";

$r = $compatibility->checkRAMDecentralizedCompatibility(
    ['type' => 'ram', 'uuid' => $DDR4_RDIMM],
    []
);
check("No existing components → compatible", $r['compatible'] === true);

// =====================================================================
// 7. Authority does NOT check slot count (slot check is separate concern)
//    Regardless of how many RAMs exist, the authority only checks type/form/module
// =====================================================================
echo "\n--- Slot count is a separate concern (authority ignores it) ---\n";

// Pack 50 identical DDR4 RDIMM "existing" entries — authority should still say
// compatible (slot count is validated by validateRAMSlotAvailability, not here)
$manyRams = array_fill(0, 50, ['type' => 'ram', 'uuid' => $DDR4_RDIMM]);
$r = $compatibility->checkRAMDecentralizedCompatibility(
    ['type' => 'ram', 'uuid' => $DDR4_RDIMM2],
    $manyRams
);
check("50 existing RAMs: authority still compatible (slot check is separate)", $r['compatible'] === true);

// =====================================================================
// 8. Legacy check misses module-type issue (confirms the gap being closed)
// =====================================================================
echo "\n--- Legacy check does NOT catch module-type mismatch ---\n";

// Simulate what the legacy finalise path does: only check type against MB's supported_types
// This is exactly validateRAMTypeCompatibility's behaviour
$fakeMbLimits = ['memory' => ['supported_types' => ['DDR4'], 'slots' => 8, 'max_slots' => 8]];
$legacyResult = $compatibility->validateRAMTypeCompatibility($DDR4_UDIMM, $fakeMbLimits);
check("Legacy type check: DDR4 UDIMM vs DDR4 MB → compatible (misses module type)",
    $legacyResult['compatible'] === true
);
// Authority DOES catch the mismatch when an RDIMM is already installed
$r = $compatibility->checkRAMDecentralizedCompatibility(
    ['type' => 'ram', 'uuid' => $DDR4_UDIMM],
    [['type' => 'ram', 'uuid' => $DDR4_RDIMM]]
);
check("Authority: DDR4 UDIMM + DDR4 RDIMM existing → incompatible (gap confirmed)",
    $r['compatible'] === false
);

echo "\n";
if ($fails === 0) {
    echo "OK: MemoryAuthority — comprehensive RAM check verified, legacy gap confirmed.\n";
    exit(0);
}
echo "FAILED: $fails assertion(s).\n";
exit(1);
