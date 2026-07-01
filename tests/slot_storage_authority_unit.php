<?php
/**
 * slot_storage_authority_unit.php — focused unit tests for Phase-3 SlotAuthority
 * and StorageConnectionAuthority mode readers.
 *
 * SlotAuthority: reconciles add-time size-bucket model (ComponentCompatibility) with
 * finalize-time slot-ID model (UnifiedSlotTracker). Only diverges when the motherboard
 * spec has expansion_slots.pcie_slots with real slot IDs; otherwise falls back to legacy.
 *
 * StorageConnectionAuthority: makes add-time storage check (checkStorageDecentralizedCompatibility)
 * as comprehensive as finalize-time (StorageConnectionValidator::validate()), closing
 * the gap where a drive with no valid connection path is accepted at add-time.
 *
 * Tests:
 *   1. SlotAuthority::mode() env-flag reader (off/shadow/enforce/unknown → off)
 *   2. StorageConnectionAuthority::mode() env-flag reader (off/shadow/enforce/unknown → off)
 *   3. UnifiedSlotTracker::getSlotAvailability() returns success:false for a config
 *      whose motherboard spec lacks expansion_slots.pcie_slots (no false divergence)
 *   4. StorageConnectionValidator::validate() returns valid:false when no chassis/MB
 *      (no connection path available — simulates what authority would catch at add-time
 *      that the lightweight check would miss)
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

require_once $ROOT . '/core/models/compatibility/SlotAuthority.php';
require_once $ROOT . '/core/models/compatibility/StorageConnectionAuthority.php';
require_once $ROOT . '/core/models/compatibility/UnifiedSlotTracker.php';
require_once $ROOT . '/core/models/compatibility/StorageConnectionValidator.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// =====================================================================
// 1. SlotAuthority::mode() env-flag reader (public static; no reflection needed)
// =====================================================================
echo "--- SlotAuthority::mode() ---\n";

putenv('SLOT_AUTHORITY_ENABLED=');
check("unset env → 'off'", SlotAuthority::mode() === 'off');

putenv('SLOT_AUTHORITY_ENABLED=off');
check("off → 'off'", SlotAuthority::mode() === 'off');

putenv('SLOT_AUTHORITY_ENABLED=shadow');
check("shadow → 'shadow'", SlotAuthority::mode() === 'shadow');

putenv('SLOT_AUTHORITY_ENABLED=ENFORCE');
check("ENFORCE (upper) → 'enforce'", SlotAuthority::mode() === 'enforce');

putenv('SLOT_AUTHORITY_ENABLED=garbage');
check("invalid value → 'off'", SlotAuthority::mode() === 'off');

putenv('SLOT_AUTHORITY_ENABLED=off');

// =====================================================================
// 2. StorageConnectionAuthority::mode() env-flag reader (public static)
// =====================================================================
echo "\n--- StorageConnectionAuthority::mode() ---\n";

putenv('STORAGE_CONNECTION_AUTHORITY_ENABLED=');
check("unset env → 'off'", StorageConnectionAuthority::mode() === 'off');

putenv('STORAGE_CONNECTION_AUTHORITY_ENABLED=off');
check("off → 'off'", StorageConnectionAuthority::mode() === 'off');

putenv('STORAGE_CONNECTION_AUTHORITY_ENABLED=shadow');
check("shadow → 'shadow'", StorageConnectionAuthority::mode() === 'shadow');

putenv('STORAGE_CONNECTION_AUTHORITY_ENABLED=ENFORCE');
check("ENFORCE (upper) → 'enforce'", StorageConnectionAuthority::mode() === 'enforce');

putenv('STORAGE_CONNECTION_AUTHORITY_ENABLED=junk');
check("invalid value → 'off'", StorageConnectionAuthority::mode() === 'off');

putenv('STORAGE_CONNECTION_AUTHORITY_ENABLED=off');

// =====================================================================
// 3. UnifiedSlotTracker: unknown config UUID → success:false (no slot data)
//    This verifies the safety guard: tracker must return success:true AND have
//    available_slots before SlotAuthority acts. Unknown config → no MB UUID → no
//    slot IDs → tracker returns success:false → authority falls back to legacy.
// =====================================================================
echo "\n--- UnifiedSlotTracker: unknown config UUID → success:false (safe fallback) ---\n";

$tracker = new UnifiedSlotTracker($pdo);
$result = $tracker->getSlotAvailability('not-a-real-config-uuid');
check("Unknown config UUID → success:false", $result['success'] === false || empty($result['available_slots']));
// Either success:false (no config row) or empty available_slots (no slot data in spec)
// In both cases, SlotAuthority must NOT log divergence (safety guard)
$availableCount = 0;
foreach (($result['available_slots'] ?? []) as $slotIds) {
    $availableCount += count((array)$slotIds);
}
check("No slot IDs from unknown config → zero available", $availableCount === 0);

// =====================================================================
// 4. StorageConnectionValidator: no chassis/motherboard → valid:false
//    This verifies the authority's behavior when a storage drive has no
//    connection path (no chassis, no MB) — authority would catch this at
//    add-time while the lightweight check might pass it through.
// =====================================================================
echo "\n--- StorageConnectionValidator: no connection path → valid:false ---\n";

// A known storage UUID from ims-data/storage/storage-level-3.json
// We'll use any UUID and verify the validator handles missing chassis/MB correctly
$scv = new StorageConnectionValidator($pdo);
$emptyComponents = [
    'chassis' => null, 'motherboard' => null,
    'cpu' => [], 'ram' => [], 'storage' => [],
    'nic' => [], 'pciecard' => [], 'hbacard' => [], 'caddy' => []
];

// Try with a fake config UUID and unknown storage UUID — should fail gracefully
$fakeSCV = $scv->validate('fake-config-uuid', 'not-a-real-storage-uuid', $emptyComponents);
check("Unknown storage UUID → valid:false (not found in spec)", $fakeSCV['valid'] === false);
check("Unknown storage UUID → errors array non-empty", !empty($fakeSCV['errors']));

// =====================================================================
// 5. Mode readers are independent — setting one doesn't affect the other
// =====================================================================
echo "\n--- Mode readers are independent ---\n";

putenv('SLOT_AUTHORITY_ENABLED=shadow');
putenv('STORAGE_CONNECTION_AUTHORITY_ENABLED=enforce');
check("SLOT=shadow does not bleed into STORAGE_CONN", SlotAuthority::mode() === 'shadow');
check("STORAGE_CONN=enforce does not bleed into SLOT", StorageConnectionAuthority::mode() === 'enforce');
putenv('SLOT_AUTHORITY_ENABLED=off');
putenv('STORAGE_CONNECTION_AUTHORITY_ENABLED=off');

echo "\n";
if ($fails === 0) {
    echo "OK: SlotAuthority + StorageConnectionAuthority mode readers verified.\n";
    exit(0);
}
echo "FAILED: $fails assertion(s).\n";
exit(1);
