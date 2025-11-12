<?php
require_once __DIR__ . '/PCIeLanePool.php';
require_once __DIR__ . '/PCIeSlotPool.php';
require_once __DIR__ . '/RAMSlotPool.php';
require_once __DIR__ . '/M2SlotPool.php';
require_once __DIR__ . '/U2SlotPool.php';
require_once __DIR__ . '/SATAPortPool.php';

/**
 * Resource Registry
 *
 * Central registry for all resource pools in a server configuration.
 * Provides unified interface to query and allocate resources.
 *
 * Manages:
 * 1. PCIe Lane Pool - PCIe expansion lane allocation
 * 2. PCIe Slot Pool - PCIe slot allocation (x1, x4, x8, x16)
 * 3. RAM Slot Pool - Memory DIMM allocation
 * 4. M.2 Slot Pool - M.2 SSD slot allocation
 * 5. U.2 Slot Pool - U.2 SSD slot allocation
 * 6. SATA Port Pool - SATA port allocation
 *
 * Single source of truth for resource availability.
 */
class ResourceRegistry {

    /** @var string Configuration UUID */
    private string $configUuid;

    /** @var PCIeLanePool PCIe lane pool */
    private PCIeLanePool $lanePool;

    /** @var PCIeSlotPool PCIe slot pool */
    private PCIeSlotPool $slotPool;

    /** @var RAMSlotPool RAM slot pool */
    private RAMSlotPool $ramPool;

    /** @var M2SlotPool M.2 slot pool */
    private M2SlotPool $m2Pool;

    /** @var U2SlotPool U.2 slot pool */
    private U2SlotPool $u2Pool;

    /** @var SATAPortPool SATA port pool */
    private SATAPortPool $sataPool;

    /**
     * Constructor
     *
     * @param string $configUuid Configuration UUID
     */
    public function __construct(string $configUuid) {
        $this->configUuid = $configUuid;
    }

    /**
     * Factory: Create from configuration
     *
     * LOGIC:
     * 1. Create all resource pools from configuration
     * 2. Return registry instance
     *
     * @param string $configUuid Configuration UUID
     * @param array $configuration Full configuration data
     * @return self Resource registry
     */
    public static function createFromConfiguration(string $configUuid, array $configuration): self {
        $registry = new self($configUuid);

        // Create all pools
        $registry->lanePool = PCIeLanePool::createFromConfiguration($configuration);
        $registry->slotPool = PCIeSlotPool::createFromConfiguration($configuration);
        $registry->ramPool = RAMSlotPool::createFromConfiguration($configuration);
        $registry->m2Pool = M2SlotPool::createFromConfiguration($configuration);
        $registry->u2Pool = U2SlotPool::createFromConfiguration($configuration);
        $registry->sataPool = SATAPortPool::createFromConfiguration($configuration);

        return $registry;
    }

    // Getters for each pool

    /**
     * Get configuration UUID
     *
     * @return string Configuration UUID
     */
    public function getConfigUuid(): string {
        return $this->configUuid;
    }

    /**
     * Get PCIe lane pool
     *
     * @return PCIeLanePool PCIe lane pool
     */
    public function getLanePool(): PCIeLanePool {
        return $this->lanePool;
    }

    /**
     * Get PCIe slot pool
     *
     * @return PCIeSlotPool PCIe slot pool
     */
    public function getSlotPool(): PCIeSlotPool {
        return $this->slotPool;
    }

    /**
     * Get RAM slot pool
     *
     * @return RAMSlotPool RAM slot pool
     */
    public function getRAMPool(): RAMSlotPool {
        return $this->ramPool;
    }

    /**
     * Get M.2 slot pool
     *
     * @return M2SlotPool M.2 slot pool
     */
    public function getM2Pool(): M2SlotPool {
        return $this->m2Pool;
    }

    /**
     * Get U.2 slot pool
     *
     * @return U2SlotPool U.2 slot pool
     */
    public function getU2Pool(): U2SlotPool {
        return $this->u2Pool;
    }

    /**
     * Get SATA port pool
     *
     * @return SATAPortPool SATA port pool
     */
    public function getSATAPool(): SATAPortPool {
        return $this->sataPool;
    }

    /**
     * Get comprehensive resource statistics
     *
     * LOGIC:
     * 1. Gather stats from all pools
     * 2. Return unified stats array
     *
     * @return array Statistics for all resource pools
     */
    public function getAllStats(): array {
        return [
            'config_uuid' => $this->configUuid,
            'pcie_lanes' => $this->lanePool->getStats(),
            'pcie_slots' => $this->slotPool->getStats(),
            'ram_slots' => $this->ramPool->getStats(),
            'm2_slots' => $this->m2Pool->getStats(),
            'u2_slots' => $this->u2Pool->getStats(),
            'sata_ports' => $this->sataPool->getStats()
        ];
    }

    /**
     * Get resource availability summary
     *
     * LOGIC:
     * 1. Check each pool for availability
     * 2. Return bottleneck resources
     *
     * @return array Summary of resource availability
     */
    public function getAvailabilitySummary(): array {
        return [
            'pcie_lanes' => [
                'total' => $this->lanePool->getTotalCapacity(),
                'available' => $this->lanePool->getAvailableCapacity(),
                'exhausted' => $this->lanePool->getAvailableCapacity() === 0
            ],
            'pcie_slots' => [
                'total' => $this->slotPool->getTotalCapacity(),
                'available' => $this->slotPool->getAvailableCapacity(),
                'exhausted' => $this->slotPool->getAvailableCapacity() === 0
            ],
            'ram_slots' => [
                'total' => $this->ramPool->getTotalCapacity(),
                'available' => $this->ramPool->getAvailableCapacity(),
                'exhausted' => $this->ramPool->getAvailableCapacity() === 0
            ],
            'm2_slots' => [
                'total' => $this->m2Pool->getTotalCapacity(),
                'available' => $this->m2Pool->getAvailableCapacity(),
                'exhausted' => $this->m2Pool->getAvailableCapacity() === 0
            ],
            'u2_slots' => [
                'total' => $this->u2Pool->getTotalCapacity(),
                'available' => $this->u2Pool->getAvailableCapacity(),
                'exhausted' => $this->u2Pool->getAvailableCapacity() === 0
            ],
            'sata_ports' => [
                'total' => $this->sataPool->getTotalCapacity(),
                'available' => $this->sataPool->getAvailableCapacity(),
                'exhausted' => $this->sataPool->getAvailableCapacity() === 0
            ]
        ];
    }

    /**
     * Reset all resource pools
     *
     * LOGIC:
     * 1. Reset each pool
     * 2. Clear all allocations
     *
     * @return void
     */
    public function resetAll(): void {
        $this->lanePool->reset();
        $this->slotPool->reset();
        $this->ramPool->reset();
        $this->m2Pool->reset();
        $this->u2Pool->reset();
        $this->sataPool->reset();
    }

    /**
     * Check if all critical resources are available
     *
     * LOGIC:
     * 1. Check each pool for minimum availability
     * 2. Return true if all pools have at least 1 slot/lane/port
     *
     * @return bool True if all pools have availability
     */
    public function hasAvailableResources(): bool {
        return $this->lanePool->getAvailableCapacity() > 0 ||
               $this->slotPool->getAvailableCapacity() > 0 ||
               $this->ramPool->getAvailableCapacity() > 0 ||
               $this->m2Pool->getAvailableCapacity() > 0 ||
               $this->u2Pool->getAvailableCapacity() > 0 ||
               $this->sataPool->getAvailableCapacity() > 0;
    }

    /**
     * Get bottleneck resources (nearly exhausted)
     *
     * LOGIC:
     * 1. Check each pool
     * 2. Return resources with < 20% utilization capacity
     *
     * @return array List of bottleneck resources
     */
    public function getBottleneckResources(): array {
        $bottlenecks = [];

        if ($this->lanePool->getAvailableCapacity() / max(1, $this->lanePool->getTotalCapacity()) < 0.2) {
            $bottlenecks[] = 'pcie_lanes';
        }

        if ($this->slotPool->getAvailableCapacity() / max(1, $this->slotPool->getTotalCapacity()) < 0.2) {
            $bottlenecks[] = 'pcie_slots';
        }

        if ($this->ramPool->getAvailableCapacity() / max(1, $this->ramPool->getTotalCapacity()) < 0.2) {
            $bottlenecks[] = 'ram_slots';
        }

        if ($this->m2Pool->getAvailableCapacity() / max(1, $this->m2Pool->getTotalCapacity()) < 0.2) {
            $bottlenecks[] = 'm2_slots';
        }

        if ($this->u2Pool->getAvailableCapacity() / max(1, $this->u2Pool->getTotalCapacity()) < 0.2) {
            $bottlenecks[] = 'u2_slots';
        }

        if ($this->sataPool->getAvailableCapacity() / max(1, $this->sataPool->getTotalCapacity()) < 0.2) {
            $bottlenecks[] = 'sata_ports';
        }

        return $bottlenecks;
    }
}
