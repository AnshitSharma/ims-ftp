<?php
/**
 * The one place a component's human-readable name is derived. (audit JSON-007)
 *
 * WHAT IT REPLACES
 *   The same priority chain -- model / name / model_name / product_name, then the RAM and
 *   storage composites -- existed three times: component_crud_api.php (the inventory list),
 *   ServerBuilder::getComponentNameFromSpec() (build contents), and requests.js in the
 *   frontend. Only the ServerBuilder copy knew that an onboard NIC's UUID is the synthetic
 *   'onboard-{board8}-{id}-{port}' composite and has no entry in the NIC catalogue.
 *
 *   The cost of that divergence was live and visible: nic-list returned
 *   "ModelName": null for 69 of 78 rows -- 88% of network cards showed a blank model in the
 *   inventory table -- because the CRUD copy looked the composite UUID up in the catalogue,
 *   found nothing, and fell through to a Notes regex that also missed. Every other component
 *   type resolved 100%, which is exactly what made it survive so long.
 *
 * THE DELIBERATE BEHAVIOURAL CHOICE
 *   The two backend copies disagreed in field ORDER. ServerBuilder returned the first of
 *   model/name/model_name/product_name and only used the RAM/storage composite when all four
 *   were absent; the CRUD copy resolved $model first and then branched on type, reaching the
 *   composite only when $model was null. Those agree for every record in the catalogue today
 *   -- a RAM record with a `model` key gets its model either way. ServerBuilder's order is
 *   the one kept here, because "a name the vendor gave it" beats "a name we assembled".
 *
 * FAILS SOFT, NOT CLOSED
 *   A name is decoration. Every path returns null (or 'Onboard NIC') rather than throwing:
 *   an unnameable component must still list, still validate and still install. This is the
 *   opposite posture from the validation rules on purpose.
 */

class ComponentNamer
{
    /**
     * The display name for a spec body, or null if nothing in it reads as a name.
     *
     * @param string     $componentType one of the twelve types
     * @param array|null $spec          the spec body as stored in ims-data (NOT the
     *                                  ['found'=>,'specifications'=>] envelope that
     *                                  DataExtractionUtilities returns -- unwrap first)
     */
    public static function fromSpec($componentType, $spec)
    {
        if (!is_array($spec) || $spec === []) {
            return null;
        }

        $brand = isset($spec['brand']) && $spec['brand'] !== '' ? $spec['brand'] : null;

        // A vendor-given name wins over anything we assemble.
        foreach (['model', 'name', 'model_name', 'product_name'] as $field) {
            if (!empty($spec[$field]) && is_scalar($spec[$field])) {
                $value = trim((string)$spec[$field]);
                if ($value !== '') {
                    return $brand ? trim($brand . ' ' . $value) : $value;
                }
            }
        }

        // RAM: "Brand Type CapacityGB Module"
        if ($componentType === 'ram') {
            $parts = array_filter([
                $brand,
                $spec['memory_type'] ?? null,
                isset($spec['capacity_GB']) ? $spec['capacity_GB'] . 'GB' : null,
                $spec['module_type'] ?? null,
            ]);
            return $parts ? implode(' ', $parts) : null;
        }

        // Storage: "Brand Type Capacity", switching to TB at 1000 GB as both copies did.
        if ($componentType === 'storage') {
            $capacity = null;
            if (isset($spec['capacity_GB']) && is_numeric($spec['capacity_GB'])) {
                $capacity = $spec['capacity_GB'] >= 1000
                    ? round($spec['capacity_GB'] / 1000, 1) . 'TB'
                    : $spec['capacity_GB'] . 'GB';
            }
            $parts = array_filter([$brand, $spec['storage_type'] ?? null, $capacity]);
            return $parts ? implode(' ', $parts) : null;
        }

        return null;
    }

    /** Is this the synthetic UUID of an onboard NIC rather than a catalogue key? */
    public static function isOnboardNicUuid($specUuid)
    {
        return is_string($specUuid) && strpos($specUuid, 'onboard-') === 0;
    }

    /**
     * The name of an onboard NIC, resolved through its parent board's spec.
     *
     * Onboard NICs are synthesized rows: the UUID column holds a composite that resolves to
     * nothing in the NIC catalogue, and the real description lives in the MOTHERBOARD spec
     * under networking.onboard_nics[], selected by the row's OnboardNICIndex.
     *
     * Always returns a usable string -- 'Onboard NIC' is a worse name than
     * 'BCM5720 2p 1GbE RJ45', but it is a far better cell than an empty one.
     *
     * @param PDO    $pdo
     * @param object $dataUtils a DataExtractionUtilities (duck-typed: needs
     *                          getMotherboardByUUID()); null skips the spec lookup.
     */
    public static function onboardNicName($pdo, $dataUtils, $specUuid)
    {
        try {
            if (!($pdo instanceof PDO)) {
                return 'Onboard NIC';
            }

            $stmt = $pdo->prepare(
                "SELECT ParentComponentUUID, OnboardNICIndex FROM nicinventory
                 WHERE UUID = ? AND SourceType = 'onboard'"
            );
            $stmt->execute([$specUuid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || empty($row['ParentComponentUUID']) || $dataUtils === null
                || !method_exists($dataUtils, 'getMotherboardByUUID')) {
                return 'Onboard NIC';
            }

            $board = $dataUtils->getMotherboardByUUID($row['ParentComponentUUID']);
            if (!$board || !isset($board['networking']['onboard_nics'])) {
                return 'Onboard NIC';
            }

            $index = ((int)($row['OnboardNICIndex'] ?? 1)) - 1;
            $nics = $board['networking']['onboard_nics'];
            if (!isset($nics[$index]) || !is_array($nics[$index])) {
                return 'Onboard NIC';
            }

            $nic = $nics[$index];
            $name = trim(sprintf(
                '%s %dp %s %s',
                $nic['controller'] ?? 'Onboard',
                (int)($nic['ports'] ?? 0),
                $nic['speed'] ?? '',
                $nic['connector'] ?? ''
            ));
            // Collapse the gaps left by absent speed/connector rather than shipping
            // 'BCM5720 2p  ' to the UI.
            $name = preg_replace('/\s+/', ' ', $name);

            return $name !== '' ? $name : 'Onboard NIC';
        } catch (Throwable $e) {
            return 'Onboard NIC';
        }
    }
}
