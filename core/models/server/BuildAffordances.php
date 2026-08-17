<?php
/**
 * BuildAffordances — which component types a server build can currently accept.
 *
 * The builder UI used to offer all 11 component types unconditionally, so it
 * would invite an SFP module onto a copper-only build, or a PCIe card onto a
 * board with no PCIe slots, and only find out at add time when the engine said
 * no. This class answers that question up front.
 *
 * It holds NO hardware knowledge of its own. Every rule delegates to the
 * authority that already owns it:
 *
 *   PCIe slots (board + riser-provided, minus socket-gated)  UnifiedSlotTracker
 *   Riser bays                                               UnifiedSlotTracker
 *   Which NIC cages accept an SFP                            NICPortTracker
 *   Storage form factors                                     DataExtractionUtilities
 *
 * The slot/network structures are passed IN rather than recomputed: the only
 * caller (handleGetConfiguration) already has them, and this runs on every
 * server-get-config.
 *
 * TWO INDEPENDENT FACTS PER TYPE — collapsing them gets the UI wrong either way:
 *
 *   available : this build has capacity of that kind AT ALL   -> render the row
 *   can_add   : that capacity has a FREE unit right now       -> render the add button
 *
 * A board with three PCIe slots, all occupied, is available && !can_add: the row
 * stays with all three cards listed and removable, and only the add button is
 * withheld. A board with zero PCIe slots is !available: no row at all, until a
 * riser card provides some.
 */

require_once __DIR__ . '/../compatibility/NICPortTracker.php';
require_once __DIR__ . '/../shared/DataExtractionUtilities.php';

class BuildAffordances {

    /**
     * Types always offered, regardless of build state. These are the parts a
     * server is built OUT OF rather than parts that plug INTO something else,
     * so nothing can gate them.
     */
    const BASE_TYPES = ['cpu', 'motherboard', 'ram', 'chassis', 'storage'];

    /** Types that consume a PCIe slot (board-provided or riser-provided). */
    const PCIE_CONSUMERS = ['pciecard', 'nic', 'hbacard'];

    /** Drive form factors that need a caddy to reach a bay. */
    const CADDY_FORM_FACTORS = ['2.5', '3.5'];

    private $pdo;
    private $dataUtils;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->dataUtils = new DataExtractionUtilities($pdo);
    }

    /**
     * @param string $configUuid
     * @param array  $components    $details['components'], keyed by type
     * @param array  $slotTracking  ServerBuilder::getSlotTracking() output
     * @param array  $networkConfig ServerBuilder::getNetworkConfiguration() output
     * @return array<string, array{available:bool, can_add:bool, gate:string,
     *                             capacity:?array, reason:?string}>
     */
    public function forConfiguration($configUuid, array $components, array $slotTracking, array $networkConfig) {
        $options = [];

        foreach (self::BASE_TYPES as $type) {
            $options[$type] = $this->option(true, true, 'base');
        }

        $hasMotherboard = !empty($components['motherboard']);

        $options['risercard'] = $this->slotOption(
            $slotTracking['riser'] ?? [],
            $hasMotherboard,
            'riser_slot',
            'riser bay'
        );

        $pcieOption = $this->slotOption(
            $slotTracking['pcie'] ?? [],
            $hasMotherboard,
            'pcie_slot',
            'PCIe slot'
        );
        foreach (self::PCIE_CONSUMERS as $type) {
            $options[$type] = $pcieOption;
        }

        $options['sfp'] = $this->sfpOption($networkConfig);
        $options['caddy'] = $this->caddyOption($components);

        return $options;
    }

    /**
     * A tracker failure is not one thing, and the three cases want three answers.
     *
     *   "no board"        the normal empty build -> hide, nothing to plug into
     *   "no slots on it"  a legitimate ZERO, not a fault (7 of 23 boards in
     *                     ims-data declare no pcie_slots at all — the riser-only
     *                     designs) -> hide, which is the whole point of this class
     *   anything else     a real fault: specs unreadable, exception -> FAIL OPEN.
     *                     The type stays offered and add-time validation remains
     *                     the real gate, because a builder that silently loses
     *                     half its options is a worse failure than an add that
     *                     comes back with a clear message.
     *
     * The zero case is matched on the tracker's own error text. That is a seam,
     * not a preference: UnifiedSlotTracker reports "no slots defined" as
     * success:false, so there is currently no other way to distinguish it. It
     * collapses into the ordinary total_count === 0 path the moment the tracker
     * reports that case as success:true — see the engine finding in
     * tasks/dynamic-component-affordances.md.
     */
    const NO_SLOTS_DEFINED = 'No PCIe slots defined';

    private function slotOption(array $slotBlock, $hasMotherboard, $gate, $slotNoun) {
        $succeeded = !empty($slotBlock['success']);

        if (!$succeeded) {
            $error = (string)($slotBlock['error'] ?? '');
            $isLegitimateZero = stripos($error, self::NO_SLOTS_DEFINED) !== false;

            if ($hasMotherboard && !$isLegitimateZero) {
                return $this->option(true, true, $gate, null, null, 'unknown');
            }
            return $this->option(false, false, $gate,
                ['total' => 0, 'used' => 0, 'available' => 0]);
        }

        $total = (int)($slotBlock['total_count'] ?? 0);
        $used = (int)($slotBlock['used_count'] ?? 0);
        $free = (int)($slotBlock['available_count'] ?? 0);

        $capacity = ['total' => $total, 'used' => $used, 'available' => $free];

        if ($total <= 0) {
            return $this->option(false, false, $gate, $capacity);
        }
        if ($free <= 0) {
            return $this->option(true, false, $gate, $capacity,
                sprintf('%d/%d %ss in use', $used, $total, $slotNoun));
        }

        return $this->option(true, true, $gate, $capacity);
    }

    /**
     * SFP modules need a NIC with an optical cage. NICPortTracker owns the cage
     * matrix, and returns an empty list for RJ45 — so "does any installed NIC
     * accept an SFP at all" is exactly "is that list non-empty for some NIC".
     *
     * Delegating rather than pattern-matching the connector string keeps this
     * gate consistent with what the engine will actually allow at add time. It
     * inherits the matrix's exact-match behaviour, including its treatment of
     * combo connectors like "SFP28 / RJ45" (see the note in the task file) —
     * which is the point: the UI must never be more permissive than the engine.
     *
     * Onboard NICs carry the cage in `connector` (sourced from the motherboard
     * spec); add-in NICs carry it in `port_type`.
     */
    private function sfpOption(array $networkConfig) {
        $nics = $networkConfig['nics'] ?? [];

        $cageCount = 0;
        $freePorts = 0;
        $totalPorts = 0;

        foreach ($nics as $nic) {
            $specs = $nic['specifications'] ?? [];
            $cage = $specs['port_type'] ?? $specs['connector'] ?? '';

            if (empty(NICPortTracker::getCompatibleSfpTypes($cage))) {
                continue;
            }

            $cageCount++;
            $ports = (int)($specs['ports'] ?? 0);
            $totalPorts += $ports;

            // port_mapping is added by getNetworkConfiguration(); absent means
            // nothing is assigned yet, so every port is free.
            $mapping = $nic['port_mapping'] ?? [];
            $occupied = 0;
            foreach ($mapping as $port) {
                if (($port['status'] ?? '') === 'occupied') {
                    $occupied++;
                }
            }
            $freePorts += max(0, $ports - $occupied);
        }

        if ($cageCount === 0) {
            return $this->option(false, false, 'nic_cage');
        }

        $capacity = [
            'total' => $totalPorts,
            'used' => $totalPorts - $freePorts,
            'available' => $freePorts
        ];

        if ($freePorts <= 0) {
            return $this->option(true, false, 'nic_cage', $capacity,
                sprintf('%d/%d SFP ports in use', $capacity['used'], $totalPorts));
        }

        return $this->option(true, true, 'nic_cage', $capacity);
    }

    /**
     * Caddies carry a drive into a bay, so the option appears once a drive that
     * needs carrying is installed. M.2 / add-in-card storage never needs one.
     *
     * Always addable once visible: caddy count versus drive count is a warning
     * (`caddy_shortage` in getConfigurationWarnings), not a hard capacity, so it
     * must not masquerade as one by disabling the button.
     */
    private function caddyOption(array $components) {
        $bayDrives = 0;

        foreach ($components['storage'] ?? [] as $storage) {
            $uuid = $storage['uuid'] ?? null;
            if (!$uuid) {
                continue;
            }

            $specs = $this->dataUtils->getStorageByUUID($uuid);
            if (!$specs) {
                continue;
            }

            $formFactor = strtolower($specs['form_factor'] ?? '');
            foreach (self::CADDY_FORM_FACTORS as $needle) {
                if (strpos($formFactor, $needle) !== false) {
                    $bayDrives++;
                    break;
                }
            }
        }

        if ($bayDrives === 0) {
            return $this->option(false, false, 'storage_form_factor');
        }

        $installed = count($components['caddy'] ?? []);

        return $this->option(true, true, 'storage_form_factor', [
            'total' => $bayDrives,
            'used' => $installed,
            'available' => max(0, $bayDrives - $installed)
        ]);
    }

    private function option($available, $canAdd, $gate, $capacity = null, $reason = null, $state = null) {
        return [
            'available' => (bool)$available,
            'can_add' => (bool)$canAdd,
            'gate' => $gate,
            'capacity' => $capacity,
            'reason' => $reason,
            'state' => $state // 'unknown' when a tracker failed and we failed open
        ];
    }
}
