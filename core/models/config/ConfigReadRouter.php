<?php

require_once __DIR__ . '/ConfigComponentRepository.php';
require_once __DIR__ . '/../components/ComponentSpecPaths.php';

/**
 * ConfigReadRouter — U-X.1, completed by U-D.4. The single read entrypoint for
 * "what components does this config contain?".
 *
 * config_components rows ARE the answer, mapped back to the legacy output shape.
 * READ_FROM_ROWS and its off/sample modes are gone; the legacy JSON extraction
 * survives in exactly one place, for a config row carrying no uuid to look rows
 * up by. sample() and the whole json-vs-rows comparison apparatus it drove went
 * with the flag: with nothing left to compare against, a comparator can only
 * report that rows equal themselves. scripts/verify/read_report.php still reads
 * the historical reports/shadow/read-*.jsonl those runs produced.
 *
 * The configuration cache in ServerBuilder::getConfigurationDetails() sits ABOVE
 * this router (it short-circuits before the row is even fetched), so a cached
 * read never reaches here and no mode can poison a cache entry -- U-X.1
 * checklist item 3, satisfied structurally rather than by convention.
 *
 * ── PACK-VS-REALITY corrections (read before implementing from U-X.1.md) ──
 *
 * 1. The pack's signature is `components($configUuid)`. Taking a uuid would mean
 *    re-SELECTing a server_configurations row the only caller has *already*
 *    fetched, on a hot read path. This takes the fetched row instead and reads
 *    $configRow['config_uuid'] for the rows-side lookup. Same information, one
 *    query fewer.
 * 2. The pack lists "name enrichment via getComponentNameFromSpec" as part of
 *    the contract this router must reproduce. It is NOT: enrichment happens in
 *    getConfigurationDetails() (at the 'component_name' key it builds per
 *    component), which is ABOVE this router and runs identically whichever mode
 *    is active. extractComponentsFromJson() itself never enriches anything.
 *    Enrichment parity is therefore automatic, not something =on must re-derive.
 *    (Consistent with U-X.1-PLAN-20260712.md, which already found this pack's
 *    line citations stale by +99 and +256 lines.)
 * 3. The pack says to reuse equivalence_report.php's canonicalization consts.
 *    That file is a CLI report with its own bootstrap and top-level exit() calls
 *    -- it cannot be required from core/ without running it. The tuple rules are
 *    reimplemented in canonicalTuple() below, deliberately field-for-field
 *    identical to equivalence_report.php:97-131. THIS IS A DUPLICATE and the two
 *    must be changed together; extracting a shared canonicalizer class is left to
 *    U-D.3, which retires the JSON side of that report anyway.
 *
 * ── What =on structurally CANNOT reproduce (must be signed off before flipping) ──
 *
 * config_components stores one row per physical unit, with no column for these
 * three legacy JSON artifacts. Each is a real, observable difference at =on,
 * listed here rather than papered over:
 *   a. storage 'connection'. The legacy JSON carries the whole computed
 *      connection blob inline; rows do not. =on omits the key, which routes the
 *      caller into the recompute branch it ALREADY uses today whenever the
 *      stored blob is missing or reads 'not_connected' (the lazy-migration path
 *      in getConfigurationDetails). Same function, same inputs -- but it now
 *      runs for every drive, so a config whose stored blob disagreed with a
 *      fresh computation will read differently at =on than at =off.
 *   b. 'added_at' for motherboard/chassis (and the hbacard scalar fallback).
 *      Legacy hardcodes null -- those come from scalar columns with no timestamp.
 *      Rows always have a real added_at. =on surfaces a timestamp where legacy
 *      showed null. Strictly more information, still a shape difference.
 *   c. aggregated 'quantity'. A legacy JSON entry may carry quantity > 1 for one
 *      model; rows are per unit and always quantity 1. Component TOTALS agree
 *      (n rows of 1 == 1 entry of n) but the per-entry shape does not.
 * The sample-mode comparison never covered any of the three -- it compared
 * IDENTITY (who is in the config) and was deliberately silent about these. They
 * are documented here because they describe what the rows path returns TODAY,
 * which is now the only thing it returns.
 * tests/regression/read_router_test.php pins all of it.
 */
final class ConfigReadRouter
{
    /**
     * Legacy emission order, from the branch order of
     * extractComponentsFromJson(). =on reproduces it because downstream code is
     * order-sensitive: getConfigurationDetails() numbers storage bays by
     * position ($bayNumber = count(...) + 1) and de-duplicates serials in
     * iteration order.
     */
    /*
     * 'risercard' sits immediately before 'pciecard': legacy emits both from the
     * SINGLE pciecard_configurations array in array order, so the rows side cannot
     * reproduce an interleaving exactly once the two are separate types. Riser-first
     * is the closest match — it is the order Extractor::extractPciecards() writes
     * (risers resolve first so plain cards can parent to them), and the order adds
     * naturally happen in (a riser must exist before a card can sit on it).
     * A config that genuinely interleaves them cannot be reproduced exactly; that
     * is a consequence of the type split, not a data problem.
     */
    private const LEGACY_TYPE_ORDER = [
        'cpu', 'ram', 'storage', 'caddy', 'nic', 'hbacard',
        'motherboard', 'chassis', 'risercard', 'pciecard', 'sfp',
    ];

    /** Legacy hardcodes added_at = null for components that come from scalar columns. */
    private const SCALAR_COLUMN_TYPES = ['motherboard', 'chassis'];

    /*
     * U-D.4: the READ_FROM_ROWS reader lived here. config_components IS the read
     * model now; the legacy JSON extraction survives only as the answer for a
     * config that has no uuid to look rows up by.
     */

    /**
     * The routed read.
     *
     * @param ServerBuilder $builder     the caller, for the legacy extraction
     * @param array         $configRow   an already-fetched server_configurations row
     * @param bool          $minimalOutput passed through to the legacy extractor
     * @return array components in extractComponentsFromJson()'s output shape
     */
    public static function components(ServerBuilder $builder, PDO $pdo, array $configRow, bool $minimalOutput = false): array
    {
        $configUuid = (string)($configRow['config_uuid'] ?? '');
        if ($configUuid === '') {
            // No uuid means no rows to look up. U-D.3a deleted the legacy extraction
            // that used to answer here, along with the columns it read, so an empty
            // list is now the only honest answer -- and it is RETURNED rather than
            // thrown, because a caller handing over a row with no uuid is asking about
            // a configuration that does not exist yet, not reporting a fault.
            return [];
        }

        // A throw here is NOT swallowed: the rows side IS the answer, and silently
        // serving legacy instead would be a lie about which store is authoritative
        // -- exactly the "fail open, look green" class this migration has now hit
        // four times (F-11, F-18, F-21, F-24). Let it surface.
        $rows = (new ConfigComponentRepository($pdo))->liveRows($configUuid);
        return self::rowsToLegacyShape($pdo, $rows, $configRow, $minimalOutput);
    }



    // ------------------------------------------------------------------
    // Canonicalization. DUPLICATE of equivalence_report.php:97-131 -- see the
    // class docblock, correction 3. Change both or neither.
    // ------------------------------------------------------------------






    // ------------------------------------------------------------------
    // =on: rows mapped back into extractComponentsFromJson()'s output shape.
    // ------------------------------------------------------------------

    /**
     * @param array[] $rows  config_components live rows
     * @param array   $configRow the server_configurations row (for the sfp
     *                unassigned/assigned split's parent resolution only)
     */
    private static function rowsToLegacyShape(PDO $pdo, array $rows, array $configRow, bool $minimalOutput): array
    {
        // parent_id points at another config_components row; the legacy shape wants
        // the PARENT'S SPEC UUID (sfp's 'parent_nic_uuid'). Resolve from the rows we
        // already have -- a parent is always in the same config, so no extra query.
        $specById = [];
        foreach ($rows as $row) {
            $specById[(int)$row['id']] = $row['spec_uuid'];
        }

        $ordered = self::sortLikeLegacy($rows);

        $components = [];
        foreach ($ordered as $row) {
            $type = (string)$row['component_type'];

            $component = [
                'component_type' => $type,
                'component_uuid' => $row['spec_uuid'],
                // Rows are one-per-physical-unit, so quantity is always 1. See the
                // class docblock (c): totals agree with legacy, per-entry shape
                // need not.
                'quantity'       => 1,
                'added_at'       => in_array($type, self::SCALAR_COLUMN_TYPES, true)
                    // Legacy has no timestamp for scalar-column components and
                    // hardcodes null. Reproduced so =on does not invent one.
                    ? null
                    : ($row['added_at'] ?? null),
            ];

            if ($row['serial_number'] !== null) {
                $component['serial_number'] = $row['serial_number'];
            }
            // U-D.3b: the slot this unit occupies. extractComponentsFromJson() never
            // carried it, so every slot-aware consumer (the UnifiedSlotTracker family,
            // getNetworkConfiguration, migrateNICSlotPositions) read the raw JSON column
            // instead and could not be routed. Emitted under the LEGACY key name
            // 'slot_position' -- that is what those consumers already index by, so they
            // move onto rows without changing shape. SFP keeps 'port_index' below as
            // well; its slot_ref is its NIC port, and both spellings have live readers.
            if (($row['slot_ref'] ?? null) !== null && $row['slot_ref'] !== '') {
                $component['slot_position'] = $row['slot_ref'];
            }
            if ($type === 'nic') {
                // Legacy nic_config entries carry source_type; rows do not, but the
                // synthetic "onboard-" spec_uuid IS the marker -- the same test
                // RemoveComponentCommand:138, PcieLaneBudgetValidator:101,
                // UnifiedSlotTracker:463 and ResourceCatalog::isOnboard() all already use.
                $component['source_type'] = strpos((string)$row['spec_uuid'], 'onboard-') === 0
                    ? 'onboard'
                    : 'component';
            }
            // A-L5: the physical unit's inventory row id. Rows always have it, which
            // is the one place =on carries STRICTLY MORE identity than legacy JSON
            // (legacy only has it where a writer happened to store it).
            if ($row['inventory_id'] !== null) {
                $component['inventory_id'] = (int)$row['inventory_id'];
            }

            if ($type === 'sfp') {
                $parentId = $row['parent_id'] === null ? null : (int)$row['parent_id'];
                $component['parent_nic_uuid'] = $parentId !== null && isset($specById[$parentId])
                    ? $specById[$parentId]
                    : null;
                $component['port_index'] = self::portIndexFromSlotRef($row['slot_ref'] ?? null);
                if ($component['parent_nic_uuid'] === null) {
                    // Legacy tags the unassigned_sfps branch this way; a SFP with no
                    // parent row is the same state.
                    $component['status'] = 'unassigned';
                }
            }

            // NOTE (docblock item a): storage 'connection' is deliberately ABSENT.
            // config_components has no column for the computed blob, and omitting the
            // key routes getConfigurationDetails() into the recompute branch it
            // already uses whenever the stored blob is missing or 'not_connected'.

            $components[] = $component;
        }

        if ($minimalOutput) {
            $components = array_map(fn($c) => [
                'component_type' => $c['component_type'],
                'component_uuid' => $c['component_uuid'],
            ], $components);
            $components = array_values(array_filter($components, fn($c) => !empty($c['component_uuid'])));
        }

        return $components;
    }

    /**
     * Legacy JSON order: types in extractComponentsFromJson()'s branch order,
     * and within a type the array's append order. added_at then id reproduces
     * append order (id breaks ties for the same-second adds a JSON array would
     * still have kept apart).
     *
     * @param array[] $rows
     * @return array[]
     */
    private static function sortLikeLegacy(array $rows): array
    {
        $rank = array_flip(self::LEGACY_TYPE_ORDER);
        usort($rows, function ($a, $b) use ($rank) {
            $ra = $rank[$a['component_type']] ?? count($rank);
            $rb = $rank[$b['component_type']] ?? count($rank);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            $aAdded = (string)($a['added_at'] ?? '');
            $bAdded = (string)($b['added_at'] ?? '');
            if ($aAdded !== $bAdded) {
                return strcmp($aAdded, $bAdded);
            }
            return (int)$a['id'] <=> (int)$b['id'];
        });
        return $rows;
    }

    /** 'port_3' => 3; anything else => null. Inverse of the writer's slot_ref format. */
    private static function portIndexFromSlotRef(?string $slotRef): ?int
    {
        if ($slotRef === null || strpos($slotRef, 'port_') !== 0) {
            return null;
        }
        $index = substr($slotRef, 5);
        return $index === '' || !ctype_digit($index) ? null : (int)$index;
    }
}
