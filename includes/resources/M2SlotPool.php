<?php
require_once __DIR__ . '/ResourcePoolInterface.php';

/**
 * M.2 Slot Pool
 *
 * Tracks M.2 storage slot allocation across motherboard and adapter cards.
 *
 * Slot Structure:
 * - Motherboard M.2 slots (e.g., 2-4 slots on motherboard)
 * - Adapter card M.2 slots (e.g., PCIe NVMe adapters with 4+ slots)
 *
 * M.2 Exemption Rules:
 * - Motherboard M.2 slots do NOT consume PCIe expansion lanes
 * - M.2 via PCIe adapter DOES consume PCIe lanes
 * - Tracked via metadata['exemption'] = 'motherboard_m2'
 *
 * Slot Types:
 * - 2280: Standard form factor (22mm x 80mm)
 * - 22110: Extended form factor (22mm x 110mm)
 * - 2260, 2242: Smaller form factors
 *
 * Interface:
 * - PCIe NVMe (most common)
 * - SATA (older systems)
 *
 * Location Tracking:
 * - motherboard: Direct on motherboard
 * - {adapter_uuid}: On specific PCIe adapter card
 */
class M2SlotPool implements ResourcePoolInterface {

    /** @var array Slots [slot_id => [type, interface, location, available]] */
    private array $slots = [];

    /** @var array Allocations [allocation_id => [slot_id, component_uuid, ...]] */
    private array $allocations = [];

    /** @var int Next allocation ID */
    private int $nextAllocationId = 1;

    /** @var string Default slot type */
    private const DEFAULT_SLOT_TYPE = '2280';

    /**
     * Constructor
     *
     * @param array $slots Slot definitions
     */
    public function __construct(array $slots = []) {
        foreach ($slots as $slot) {
            $this->addSlot(
                $slot['id'],
                $slot['type'] ?? self::DEFAULT_SLOT_TYPE,
                $slot['interface'] ?? 'pcie',
                $slot['location'] ?? 'motherboard',
                $slot['exemption'] ?? null
            );
        }
    }

    /**
     * Add M.2 slot to pool
     *
     * @param string $slotId Unique slot ID (e.g., "m2_1", "adapter_uuid_m2_0")
     * @param string $type Slot type (2280, 22110, etc.)
     * @param string $interface Interface (pcie, sata)
     * @param string $location Location (motherboard, adapter_{uuid}, etc.)
     * @param string|null $exemption Exemption flag (motherboard_m2, adapter_m2, none)
     * @return void
     */
    public function addSlot(
        string $slotId,
        string $type = '2280',
        string $interface = 'pcie',
        string $location = 'motherboard',
        ?string $exemption = null
    ): void {
        $this->slots[$slotId] = [
            'type' => $type,
            'interface' => $interface,
            'location' => $location,
            'exemption' => $exemption,
            'available' => true
        ];
    }

    /**
     * Factory: Create from configuration
     *
     * LOGIC:
     * 1. Extract M.2 slots from motherboard (EXEMPT from lane counting)
     * 2. Extract M.2 slots from PCIe adapter cards (CONSUME lanes)
     * 3. Build slot pool with exemption metadata
     *
     * @param array $configuration Server configuration
     * @return self M.2 slot pool instance
     */
    public static function createFromConfiguration(array $configuration): self {
        $slots = [];

        // Extract motherboard M.2 slots (EXEMPT from PCIe lane counting)
        if (isset($configuration['motherboard'][0]['m2_slots'])) {
            $m2Slots = $configuration['motherboard'][0]['m2_slots'];

            foreach ($m2Slots as $index => $slotData) {
                $slots[] = [
                    'id' => "mb_m2_{$index}",
                    'type' => $slotData['type'] ?? '2280',
                    'interface' => $slotData['interface'] ?? 'pcie',
                    'location' => 'motherboard',
                    'exemption' => 'motherboard_m2'  // Exempt from lane counting
                ];
            }
        }

        // Extract M.2 slots from PCIe adapter cards (CONSUME lanes)
        if (isset($configuration['pciecard'])) {
            foreach ($configuration['pciecard'] as $adapterIndex => $adapter) {
                if (isset($adapter['m2_slots'])) {
                    foreach ($adapter['m2_slots'] as $slotIndex => $slotData) {
                        $adapterId = $adapter['uuid'] ?? "adapter_{$adapterIndex}";
                        $slots[] = [
                            'id' => "adapter_{$adapterId}_m2_{$slotIndex}",
                            'type' => $slotData['type'] ?? '2280',
                            'interface' => $slotData['interface'] ?? 'pcie',
                            'location' => "adapter_{$adapterId}",
                            'exemption' => 'adapter_m2'  // Consumes PCIe lanes
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
     * 1. Prefer motherboard slots (EXEMPT)
     * 2. Then adapter slots
     * 3. Return first available slot
     *
     * @param string|null $preferredLocation Preferred location (optional)
     * @return string|null Slot ID or null if none available
     */
    public function findNextAvailableSlot(?string $preferredLocation = null): ?string {
        $available = [];

        // First pass: check preferred location
        if ($preferredLocation !== null) {
            foreach ($this->slots as $slotId => $slot) {
                if ($slot['available'] && $slot['location'] === $preferredLocation) {
                    return $slotId;
                }
            }
        }

        // Second pass: prefer motherboard slots (exempt)
        foreach ($this->slots as $slotId => $slot) {
            if ($slot['available'] && $slot['location'] === 'motherboard') {
                return $slotId;
            }
        }

        // Third pass: any available slot
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
                'M.2 slots'
            );
        }

        // Mark slot as unavailable
        $this->slots[$slotId]['available'] = false;

        // Create allocation
        $allocationId = 'm2_slot_' . $this->nextAllocationId++;

        // Carry exemption to allocation
        $exemption = $this->slots[$slotId]['exemption'];

        $this->allocations[$allocationId] = [
            'slot_id' => $slotId,
            'component_uuid' => $componentUuid,
            'exemption' => $exemption,
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

        // Count exemptions
        $exempt = 0;
        foreach ($this->allocations as $allocation) {
            if ($allocation['exemption'] === 'motherboard_m2') {
                $exempt++;
            }
        }

        return [
            'total' => $total,
            'used' => $used,
            'available' => $total - $used,
            'utilization_percent' => $total > 0 ? ($used / $total) * 100 : 0,
            'exempt_count' => $exempt,
            'by_location' => $byLocation
        ];
    }

    /**
     * Get allocations requiring PCIe lanes
     *
     * HELPER METHOD - Used by PCIeLanePool for lane allocation
     *
     * @return array Allocations that consume PCIe lanes (adapter M.2 only)
     */
    public function getAllocationsConsumingLanes(): array {
        $consuming = [];

        foreach ($this->allocations as $allocationId => $allocation) {
            if ($allocation['exemption'] !== 'motherboard_m2') {
                $consuming[$allocationId] = $allocation;
            }
        }

        return $consuming;
    }
}
