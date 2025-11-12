<?php
require_once __DIR__ . '/ResourcePoolInterface.php';

/**
 * PCIe Lane Pool
 *
 * Tracks PCIe lane allocation across CPU and motherboard chipset.
 *
 * Lane Sources:
 * 1. CPU PCIe lanes (e.g., 64 lanes from CPU)
 * 2. Motherboard chipset lanes (e.g., 24 lanes from chipset)
 *
 * Special Rule: M.2 Exemption
 * - M.2 slots directly on motherboard do NOT consume expansion PCIe lanes
 * - M.2 via PCIe adapter DOES consume expansion lanes
 * - This is tracked via metadata['exemption'] = 'motherboard_m2'
 *
 * Total Available Lanes = CPU Lanes + Chipset Lanes
 * Expansion Lanes = Total - Motherboard Native Allocations (SATA, USB, etc.)
 */
class PCIeLanePool implements ResourcePoolInterface {

    /** @var int Total PCIe lanes available */
    private int $totalLanes;

    /** @var int CPU PCIe lanes */
    private int $cpuLanes;

    /** @var int Chipset PCIe lanes */
    private int $chipsetLanes;

    /** @var int Reserved lanes (for motherboard features) */
    private int $reservedLanes;

    /** @var array Allocations [allocation_id => [...]] */
    private array $allocations = [];

    /** @var int Next allocation ID */
    private int $nextAllocationId = 1;

    /**
     * Constructor
     *
     * @param int $cpuLanes PCIe lanes from CPU
     * @param int $chipsetLanes PCIe lanes from chipset
     * @param int $reservedLanes Reserved lanes (for onboard features)
     */
    public function __construct(int $cpuLanes, int $chipsetLanes = 0, int $reservedLanes = 0) {
        $this->cpuLanes = $cpuLanes;
        $this->chipsetLanes = $chipsetLanes;
        $this->reservedLanes = $reservedLanes;
        $this->totalLanes = $cpuLanes + $chipsetLanes;
    }

    /**
     * Factory: Create from configuration
     *
     * LOGIC:
     * 1. Extract CPU lanes from CPU spec
     * 2. Extract chipset lanes from motherboard spec
     * 3. Calculate reserved lanes (SATA, USB, etc.)
     * 4. Create pool instance
     *
     * @param array $configuration Server configuration
     * @return self PCIe lane pool instance
     */
    public static function createFromConfiguration(array $configuration): self {
        $cpuLanes = 0;
        $chipsetLanes = 0;
        $reservedLanes = 0;

        // Extract CPU lanes
        if (isset($configuration['cpu'][0]['pcie_lanes'])) {
            $cpuLanes = (int)$configuration['cpu'][0]['pcie_lanes'];
        }

        // Extract chipset lanes from motherboard
        if (isset($configuration['motherboard'][0]['chipset_pcie_lanes'])) {
            $chipsetLanes = (int)$configuration['motherboard'][0]['chipset_pcie_lanes'];
        }

        // Calculate reserved lanes (SATA, onboard NICs, etc.)
        // SATA typically uses 1 lane per 2 ports
        if (isset($configuration['motherboard'][0]['sata_ports'])) {
            $sataPorts = (int)$configuration['motherboard'][0]['sata_ports'];
            $reservedLanes += ceil($sataPorts / 2);
        }

        // Onboard NICs (typically 1-2 lanes each)
        if (isset($configuration['motherboard'][0]['onboard_nics'])) {
            $onboardNics = (int)$configuration['motherboard'][0]['onboard_nics'];
            $reservedLanes += $onboardNics; // 1 lane per NIC
        }

        return new self($cpuLanes, $chipsetLanes, $reservedLanes);
    }

    public function getTotalCapacity(): int {
        return $this->totalLanes;
    }

    public function getUsedCapacity(): int {
        $used = $this->reservedLanes; // Start with reserved lanes

        foreach ($this->allocations as $allocation) {
            // Check for M.2 exemption
            if (isset($allocation['metadata']['exemption']) &&
                $allocation['metadata']['exemption'] === 'motherboard_m2') {
                continue; // Skip - exempt from lane count
            }

            $used += $allocation['quantity'];
        }

        return $used;
    }

    public function getAvailableCapacity(): int {
        return $this->totalLanes - $this->getUsedCapacity();
    }

    public function isAvailable(int $quantity): bool {
        return $this->getAvailableCapacity() >= $quantity;
    }

    public function allocate(int $quantity, string $componentUuid, array $metadata = []): string {
        // Check exemption
        $isExempt = isset($metadata['exemption']) &&
                    $metadata['exemption'] === 'motherboard_m2';

        // If not exempt, check availability
        if (!$isExempt && !$this->isAvailable($quantity)) {
            throw new ResourceExhaustedException(
                $quantity,
                $this->getAvailableCapacity(),
                'PCIe lanes'
            );
        }

        // Create allocation
        $allocationId = 'lane_' . $this->nextAllocationId++;

        $this->allocations[$allocationId] = [
            'quantity' => $quantity,
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

        unset($this->allocations[$allocationId]);
        return true;
    }

    public function deallocateByComponent(string $componentUuid): int {
        $count = 0;

        foreach ($this->allocations as $allocationId => $allocation) {
            if ($allocation['component_uuid'] === $componentUuid) {
                unset($this->allocations[$allocationId]);
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
        $this->allocations = [];
        $this->nextAllocationId = 1;
    }

    public function getStats(): array {
        $total = $this->getTotalCapacity();
        $used = $this->getUsedCapacity();
        $available = $this->getAvailableCapacity();

        return [
            'total' => $total,
            'used' => $used,
            'available' => $available,
            'reserved' => $this->reservedLanes,
            'utilization_percent' => $total > 0 ? ($used / $total) * 100 : 0,
            'cpu_lanes' => $this->cpuLanes,
            'chipset_lanes' => $this->chipsetLanes,
            'allocation_count' => count($this->allocations)
        ];
    }

    /**
     * Get expansion lanes (excluding reserved)
     *
     * @return int Available expansion lanes
     */
    public function getExpansionLanes(): int {
        return $this->totalLanes - $this->reservedLanes;
    }
}
