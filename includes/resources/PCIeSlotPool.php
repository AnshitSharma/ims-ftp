<?php
require_once __DIR__ . '/ResourcePoolInterface.php';

/**
 * PCIe Slot Pool
 *
 * Tracks PCIe slot allocation across motherboard and riser cards.
 * Handles backward compatibility: x8 card can fit in x16 slot.
 *
 * Slot Structure:
 * - Motherboard PCIe slots (e.g., 4x x16 slots)
 * - Riser card slots (e.g., 2x risers with 2x x16 slots each)
 *
 * Sizing:
 * - x1: 1 lane (low-profile devices)
 * - x4: 4 lanes (NVMe adapters, some NICs)
 * - x8: 8 lanes (some storage controllers, NICs)
 * - x16: 16 lanes (GPU, HBA, full-speed NIC)
 *
 * Backward Compatibility:
 * - x8 card fits in x16 slot (8 lanes used, 8 lanes wasted)
 * - x4 card fits in x8 or x16 slot
 * - x1 card fits in any slot
 *
 * Preference Logic:
 * 1. Motherboard slots preferred over riser slots
 * 2. Preferred location (if specified)
 * 3. Smallest compatible slot size (best fit)
 * 4. Alphabetical order (deterministic)
 */
class PCIeSlotPool implements ResourcePoolInterface {

    /** @var array Available slots [slot_id => [size, location, available]] */
    private array $slots = [];

    /** @var array Allocations [allocation_id => [slot_id, component_uuid, ...]] */
    private array $allocations = [];

    /** @var int Next allocation ID */
    private int $nextAllocationId = 1;

    /** @var array Slot size hierarchy (for backward compatibility) */
    private const SLOT_SIZES = ['x1' => 1, 'x4' => 4, 'x8' => 8, 'x16' => 16];

    /**
     * Constructor
     *
     * @param array $slots Slot definitions [[id, size, location], ...]
     */
    public function __construct(array $slots = []) {
        foreach ($slots as $slot) {
            $this->addSlot($slot['id'], $slot['size'], $slot['location'] ?? 'motherboard');
        }
    }

    /**
     * Add slot to pool
     *
     * @param string $slotId Unique slot ID (e.g., "pcie1", "riser1_slot2")
     * @param string $size Slot size (x1, x4, x8, x16)
     * @param string $location Location (motherboard, riser1, etc.)
     * @return void
     */
    public function addSlot(string $slotId, string $size, string $location = 'motherboard'): void {
        $this->slots[$slotId] = [
            'size' => $size,
            'location' => $location,
            'available' => true
        ];
    }

    /**
     * Factory: Create from configuration
     *
     * LOGIC:
     * 1. Extract PCIe slots from motherboard spec
     * 2. Extract PCIe slots from riser cards
     * 3. Build slot pool
     *
     * @param array $configuration Server configuration
     * @return self PCIe slot pool instance
     */
    public static function createFromConfiguration(array $configuration): self {
        $slots = [];

        // Extract motherboard PCIe slots
        if (isset($configuration['motherboard'][0]['expansion_slots']['pcie_slots'])) {
            $pcieSlots = $configuration['motherboard'][0]['expansion_slots']['pcie_slots'];

            foreach ($pcieSlots as $index => $size) {
                $slots[] = [
                    'id' => "mb_pcie_{$index}",
                    'size' => $size,
                    'location' => 'motherboard'
                ];
            }
        }

        // Extract riser card slots
        if (isset($configuration['chassis'][0]['riser_slots'])) {
            foreach ($configuration['chassis'][0]['riser_slots'] as $riserIndex => $riserSlots) {
                foreach ($riserSlots as $slotIndex => $size) {
                    $slots[] = [
                        'id' => "riser{$riserIndex}_slot{$slotIndex}",
                        'size' => $size,
                        'location' => "riser{$riserIndex}"
                    ];
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
        // For slots, quantity = number of slots needed (usually 1)
        return $this->getAvailableCapacity() >= $quantity;
    }

    /**
     * Find best slot for component
     *
     * LOGIC:
     * 1. Get component required slot size
     * 2. Find all compatible slots (size >= required, backward compatible)
     * 3. Sort by: size (smallest first), location (motherboard first)
     * 4. Return first available slot
     *
     * @param string $requiredSize Required slot size (x1, x4, x8, x16)
     * @param string|null $preferredLocation Preferred location (optional)
     * @return string|null Slot ID or null if none available
     */
    public function findBestSlot(string $requiredSize, ?string $preferredLocation = null): ?string {
        $requiredLanes = self::SLOT_SIZES[$requiredSize];
        $compatibleSlots = [];

        // Find compatible slots
        foreach ($this->slots as $slotId => $slot) {
            if (!$slot['available']) {
                continue; // Already allocated
            }

            $slotLanes = self::SLOT_SIZES[$slot['size']];

            // Check backward compatibility (slot size >= required size)
            if ($slotLanes >= $requiredLanes) {
                $compatibleSlots[] = [
                    'id' => $slotId,
                    'size' => $slot['size'],
                    'lanes' => $slotLanes,
                    'location' => $slot['location']
                ];
            }
        }

        if (empty($compatibleSlots)) {
            return null;
        }

        // Sort by: preferred location first, then smallest size, then slot ID
        usort($compatibleSlots, function($a, $b) use ($preferredLocation) {
            // Preferred location first
            if ($preferredLocation !== null) {
                if ($a['location'] === $preferredLocation && $b['location'] !== $preferredLocation) {
                    return -1;
                }
                if ($b['location'] === $preferredLocation && $a['location'] !== $preferredLocation) {
                    return 1;
                }
            }

            // Motherboard slots preferred over riser slots
            if ($a['location'] === 'motherboard' && $b['location'] !== 'motherboard') {
                return -1;
            }
            if ($b['location'] === 'motherboard' && $a['location'] !== 'motherboard') {
                return 1;
            }

            // Smaller slot size preferred (better fit)
            if ($a['lanes'] !== $b['lanes']) {
                return $a['lanes'] - $b['lanes'];
            }

            // Lexicographic order on slot ID
            return strcmp($a['id'], $b['id']);
        });

        return $compatibleSlots[0]['id'];
    }

    public function allocate(int $quantity, string $componentUuid, array $metadata = []): string {
        // Extract required slot size from metadata
        $requiredSize = $metadata['required_size'] ?? 'x16';
        $preferredLocation = $metadata['preferred_location'] ?? null;

        // Find best slot
        $slotId = $this->findBestSlot($requiredSize, $preferredLocation);

        if ($slotId === null) {
            throw new ResourceExhaustedException(
                $quantity,
                0,
                "PCIe slots (size {$requiredSize})"
            );
        }

        // Mark slot as unavailable
        $this->slots[$slotId]['available'] = false;

        // Create allocation
        $allocationId = 'slot_' . $this->nextAllocationId++;

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

        // Mark slot as available
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
        $componentAllocations = [];

        foreach ($this->allocations as $allocationId => $allocation) {
            if ($allocation['component_uuid'] === $componentUuid) {
                $componentAllocations[$allocationId] = $allocation;
            }
        }

        return $componentAllocations;
    }

    public function reset(): void {
        // Mark all slots as available
        foreach ($this->slots as $slotId => $slot) {
            $this->slots[$slotId]['available'] = true;
        }

        $this->allocations = [];
        $this->nextAllocationId = 1;
    }

    public function getStats(): array {
        $total = $this->getTotalCapacity();
        $used = $this->getUsedCapacity();

        // Count by size
        $bySize = [];
        foreach ($this->slots as $slot) {
            $size = $slot['size'];
            if (!isset($bySize[$size])) {
                $bySize[$size] = ['total' => 0, 'used' => 0];
            }
            $bySize[$size]['total']++;
            if (!$slot['available']) {
                $bySize[$size]['used']++;
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
            'by_size' => $bySize,
            'by_location' => $byLocation
        ];
    }
}
