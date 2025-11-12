<?php
require_once __DIR__ . '/ResourcePoolInterface.php';

/**
 * U.2 Slot Pool
 *
 * Tracks U.2 storage slot allocation.
 *
 * U.2 (SFF-8639) Form Factor:
 * - Large form factor for high-capacity/high-performance drives
 * - Typical sizes: 2.5" x 15mm thick
 * - Interface: PCIe NVMe primarily
 * - Often found in storage-focused servers
 *
 * Slot Structure:
 * - Motherboard U.2 slots (rare, usually 0-2)
 * - Expansion enclosures or specialized backplanes
 * - PCIe adapter cards with U.2 connectors
 *
 * Characteristics:
 * - Backward compatible with SATA drives (some systems)
 * - Primarily NVMe protocol
 * - Hot-swappable in many configurations
 * - Less common than M.2 in standard server configs
 */
class U2SlotPool implements ResourcePoolInterface {

    /** @var array Slots [slot_id => [interface, location, available]] */
    private array $slots = [];

    /** @var array Allocations [allocation_id => [slot_id, component_uuid, ...]] */
    private array $allocations = [];

    /** @var int Next allocation ID */
    private int $nextAllocationId = 1;

    /**
     * Constructor
     *
     * @param array $slots Slot definitions
     */
    public function __construct(array $slots = []) {
        foreach ($slots as $slot) {
            $this->addSlot(
                $slot['id'],
                $slot['interface'] ?? 'pcie',
                $slot['location'] ?? 'motherboard'
            );
        }
    }

    /**
     * Add U.2 slot to pool
     *
     * @param string $slotId Unique slot ID (e.g., "u2_0", "backplane_u2_1")
     * @param string $interface Interface (pcie, sata)
     * @param string $location Location (motherboard, backplane, adapter_{uuid}, etc.)
     * @return void
     */
    public function addSlot(string $slotId, string $interface = 'pcie', string $location = 'motherboard'): void {
        $this->slots[$slotId] = [
            'interface' => $interface,
            'location' => $location,
            'available' => true
        ];
    }

    /**
     * Factory: Create from configuration
     *
     * LOGIC:
     * 1. Extract U.2 slots from motherboard (if available)
     * 2. Extract U.2 slots from storage backplanes
     * 3. Extract U.2 slots from PCIe adapter cards
     * 4. Build slot pool
     *
     * @param array $configuration Server configuration
     * @return self U.2 slot pool instance
     */
    public static function createFromConfiguration(array $configuration): self {
        $slots = [];

        // Extract motherboard U.2 slots (rare)
        if (isset($configuration['motherboard'][0]['u2_slots'])) {
            $u2Slots = $configuration['motherboard'][0]['u2_slots'];

            foreach ($u2Slots as $index => $slotData) {
                $slots[] = [
                    'id' => "mb_u2_{$index}",
                    'interface' => $slotData['interface'] ?? 'pcie',
                    'location' => 'motherboard'
                ];
            }
        }

        // Extract U.2 slots from storage backplanes (in chassis)
        if (isset($configuration['chassis'][0]['u2_backplane'])) {
            $backplanes = $configuration['chassis'][0]['u2_backplane'];

            foreach ($backplanes as $bpIndex => $backplane) {
                if (isset($backplane['u2_slots'])) {
                    foreach ($backplane['u2_slots'] as $slotIndex => $slotData) {
                        $slots[] = [
                            'id' => "backplane{$bpIndex}_u2_{$slotIndex}",
                            'interface' => $slotData['interface'] ?? 'pcie',
                            'location' => "backplane{$bpIndex}"
                        ];
                    }
                }
            }
        }

        // Extract U.2 slots from PCIe adapter cards
        if (isset($configuration['pciecard'])) {
            foreach ($configuration['pciecard'] as $adapterIndex => $adapter) {
                if (isset($adapter['u2_slots'])) {
                    foreach ($adapter['u2_slots'] as $slotIndex => $slotData) {
                        $adapterId = $adapter['uuid'] ?? "adapter_{$adapterIndex}";
                        $slots[] = [
                            'id' => "adapter_{$adapterId}_u2_{$slotIndex}",
                            'interface' => $slotData['interface'] ?? 'pcie',
                            'location' => "adapter_{$adapterId}"
                        ];
                    }
                }
            }
        }

        return new self($slots);
    }

    public function getTotalCapacity(): int {
        return count($this->slots);
    }

    public function getUsedCapacity(): int {
        return count($this->allocations);
    }

    public function getAvailableCapacity(): int {
        return $this->getTotalCapacity() - $this->getUsedCapacity();
    }

    public function isAvailable(int $quantity): bool {
        return $this->getAvailableCapacity() >= $quantity;
    }

    /**
     * Find next available slot
     *
     * LOGIC:
     * 1. Prefer specified location
     * 2. Prefer motherboard slots
     * 3. Prefer backplane slots
     * 4. Return any available slot
     *
     * @param string|null $preferredLocation Preferred location (optional)
     * @return string|null Slot ID or null if none available
     */
    public function findNextAvailableSlot(?string $preferredLocation = null): ?string {
        // First pass: check preferred location
        if ($preferredLocation !== null) {
            foreach ($this->slots as $slotId => $slot) {
                if ($slot['available'] && $slot['location'] === $preferredLocation) {
                    return $slotId;
                }
            }
        }

        // Second pass: prefer motherboard slots
        foreach ($this->slots as $slotId => $slot) {
            if ($slot['available'] && $slot['location'] === 'motherboard') {
                return $slotId;
            }
        }

        // Third pass: prefer backplane slots
        foreach ($this->slots as $slotId => $slot) {
            if ($slot['available'] && strpos($slot['location'], 'backplane') === 0) {
                return $slotId;
            }
        }

        // Fourth pass: any available slot
        foreach ($this->slots as $slotId => $slot) {
            if ($slot['available']) {
                return $slotId;
            }
        }

        return null;
    }

    public function allocate(int $quantity, string $componentUuid, array $metadata = []): string {
        $preferredLocation = $metadata['preferred_location'] ?? null;
        $slotId = $this->findNextAvailableSlot($preferredLocation);

        if ($slotId === null) {
            throw new ResourceExhaustedException(
                $quantity,
                0,
                'U.2 slots'
            );
        }

        // Mark slot as unavailable
        $this->slots[$slotId]['available'] = false;

        // Create allocation
        $allocationId = 'u2_slot_' . $this->nextAllocationId++;

        $this->allocations[$allocationId] = [
            'slot_id' => $slotId,
            'component_uuid' => $componentUuid,
            'metadata' => $metadata,
            'allocated_at' => date('Y-m-d H:i:s')
        ];

        return $allocationId;
    }

    public function deallocate(string $allocationId): bool {
        if (!isset($this->allocations[$allocationId])) {
            return false;
        }

        $slotId = $this->allocations[$allocationId]['slot_id'];
        $this->slots[$slotId]['available'] = true;

        unset($this->allocations[$allocationId]);
        return true;
    }

    public function deallocateByComponent(string $componentUuid): int {
        $count = 0;

        foreach ($this->allocations as $allocationId => $allocation) {
            if ($allocation['component_uuid'] === $componentUuid) {
                $this->deallocate($allocationId);
                $count++;
            }
        }

        return $count;
    }

    public function getAllocations(): array {
        return $this->allocations;
    }

    public function getComponentAllocations(string $componentUuid): array {
        $result = [];
        foreach ($this->allocations as $allocationId => $allocation) {
            if ($allocation['component_uuid'] === $componentUuid) {
                $result[$allocationId] = $allocation;
            }
        }
        return $result;
    }

    public function reset(): void {
        foreach ($this->slots as $slotId => $slot) {
            $this->slots[$slotId]['available'] = true;
        }
        $this->allocations = [];
        $this->nextAllocationId = 1;
    }

    public function getStats(): array {
        $total = $this->getTotalCapacity();
        $used = $this->getUsedCapacity();

        // Count by interface
        $byInterface = [];
        foreach ($this->slots as $slot) {
            $interface = $slot['interface'];
            if (!isset($byInterface[$interface])) {
                $byInterface[$interface] = ['total' => 0, 'used' => 0];
            }
            $byInterface[$interface]['total']++;
            if (!$slot['available']) {
                $byInterface[$interface]['used']++;
            }
        }

        // Count by location
        $byLocation = [];
        foreach ($this->slots as $slot) {
            $location = $slot['location'];
            if (!isset($byLocation[$location])) {
                $byLocation[$location] = ['total' => 0, 'used' => 0];
            }
            $byLocation[$location]['total']++;
            if (!$slot['available']) {
                $byLocation[$location]['used']++;
            }
        }

        return [
            'total' => $total,
            'used' => $used,
            'available' => $total - $used,
            'utilization_percent' => $total > 0 ? ($used / $total) * 100 : 0,
            'by_interface' => $byInterface,
            'by_location' => $byLocation
        ];
    }
}
