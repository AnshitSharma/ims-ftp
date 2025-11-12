<?php
require_once __DIR__ . '/ResourcePoolInterface.php';

/**
 * RAM Slot Pool
 *
 * Tracks RAM slot allocation across memory channels.
 *
 * Slot Structure:
 * - Total slots = total number of DIMM slots
 * - Slots organized by channel (for NUMA architectures)
 * - Slot types: DIMM, SO-DIMM, RDIMM, LRDIMM
 *
 * Channels:
 * - Single-channel: 1 channel (simpler systems)
 * - Dual-channel: 2 channels (typical DDR4/DDR5)
 * - Quad-channel: 4 channels (high-end servers)
 *
 * Slot Allocation:
 * - Simple round-robin within channels
 * - No capacity checking (binary slot allocation)
 * - Tracks slot location and channel for debugging
 */
class RAMSlotPool implements ResourcePoolInterface {

    /** @var int Total RAM slots */
    private int $totalSlots;

    /** @var array Slot definitions [slot_id => [type, channel, available]] */
    private array $slots = [];

    /** @var array Allocations [allocation_id => [slot_id, component_uuid, ...]] */
    private array $allocations = [];

    /** @var int Next allocation ID */
    private int $nextAllocationId = 1;

    /**
     * Constructor
     *
     * @param int $totalSlots Total number of RAM slots
     * @param string $slotType Slot type (DIMM, SO-DIMM, etc.)
     * @param int $channels Number of memory channels
     */
    public function __construct(int $totalSlots, string $slotType = 'DIMM', int $channels = 1) {
        $this->totalSlots = $totalSlots;

        // Initialize slots
        $slotsPerChannel = (int)ceil($totalSlots / $channels);
        $slotIndex = 0;

        for ($channel = 0; $channel < $channels; $channel++) {
            for ($slot = 0; $slot < $slotsPerChannel && $slotIndex < $totalSlots; $slot++) {
                $slotId = "ch{$channel}_slot{$slot}";
                $this->slots[$slotId] = [
                    'type' => $slotType,
                    'channel' => $channel,
                    'available' => true
                ];
                $slotIndex++;
            }
        }
    }

    /**
     * Factory: Create from configuration
     *
     * LOGIC:
     * 1. Extract total slot count from motherboard memory spec
     * 2. Extract slot type (DIMM, SO-DIMM, etc.)
     * 3. Extract number of channels
     * 4. Create pool
     *
     * @param array $configuration Server configuration
     * @return self RAM slot pool
     */
    public static function createFromConfiguration(array $configuration): self {
        $totalSlots = 0;
        $slotType = 'DIMM';
        $channels = 1;

        if (isset($configuration['motherboard'][0]['memory'])) {
            $memory = $configuration['motherboard'][0]['memory'];
            $totalSlots = (int)($memory['slot_count'] ?? 0);
            $slotType = $memory['slot_type'] ?? 'DIMM';
            $channels = (int)($memory['channels'] ?? 1);
        }

        return new self($totalSlots, $slotType, $channels);
    }

    public function getTotalCapacity(): int {
        return $this->totalSlots;
    }

    public function getUsedCapacity(): int {
        return count($this->allocations);
    }

    public function getAvailableCapacity(): int {
        return $this->totalSlots - count($this->allocations);
    }

    public function isAvailable(int $quantity): bool {
        return $this->getAvailableCapacity() >= $quantity;
    }

    /**
     * Find next available slot
     *
     * LOGIC:
     * 1. Iterate through slots by channel (round-robin)
     * 2. Return first available slot
     *
     * @return string|null Slot ID or null
     */
    public function findNextAvailableSlot(): ?string {
        foreach ($this->slots as $slotId => $slot) {
            if ($slot['available']) {
                return $slotId;
            }
        }
        return null;
    }

    public function allocate(int $quantity, string $componentUuid, array $metadata = []): string {
        $slotId = $this->findNextAvailableSlot();

        if ($slotId === null) {
            throw new ResourceExhaustedException(
                $quantity,
                0,
                'RAM slots'
            );
        }

        // Mark slot as unavailable
        $this->slots[$slotId]['available'] = false;

        // Create allocation
        $allocationId = 'ram_slot_' . $this->nextAllocationId++;

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
        return [
            'total' => $this->getTotalCapacity(),
            'used' => $this->getUsedCapacity(),
            'available' => $this->getAvailableCapacity(),
            'utilization_percent' => $this->totalSlots > 0
                ? ($this->getUsedCapacity() / $this->totalSlots) * 100
                : 0
        ];
    }
}
