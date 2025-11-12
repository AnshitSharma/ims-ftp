<?php
/**
 * Resource Pool Interface
 *
 * Defines contract for tracking finite resources (slots, lanes, ports, etc.)
 * All resource pool implementations must implement this interface.
 *
 * Resource Types:
 * - PCIe Slots (physical expansion slots)
 * - PCIe Lanes (bandwidth allocation)
 * - RAM Slots (memory slots)
 * - M.2 Slots (M.2 storage slots)
 * - U.2 Slots (U.2 storage slots)
 * - SATA Ports (SATA connections)
 * - HBA Ports (HBA controller ports)
 */
interface ResourcePoolInterface {

    /**
     * Get total resource capacity
     *
     * @return int Total capacity (e.g., 8 PCIe slots, 64 PCIe lanes)
     */
    public function getTotalCapacity(): int;

    /**
     * Get currently used capacity
     *
     * @return int Used capacity (e.g., 3 slots used, 48 lanes used)
     */
    public function getUsedCapacity(): int;

    /**
     * Get available capacity
     *
     * @return int Available capacity (total - used)
     */
    public function getAvailableCapacity(): int;

    /**
     * Check if resource is available
     *
     * @param int $quantity Quantity needed
     * @return bool True if available, false otherwise
     */
    public function isAvailable(int $quantity): bool;

    /**
     * Allocate resource
     *
     * LOGIC:
     * 1. Check if quantity available
     * 2. If available: mark as allocated, return allocation ID
     * 3. If not available: throw ResourceExhaustedException
     *
     * @param int $quantity Quantity to allocate
     * @param string $componentUuid Component UUID using this resource
     * @param array $metadata Additional metadata (optional)
     * @return string Allocation ID (for deallocation later)
     * @throws ResourceExhaustedException If not enough capacity
     */
    public function allocate(int $quantity, string $componentUuid, array $metadata = []): string;

    /**
     * Deallocate resource
     *
     * @param string $allocationId Allocation ID from allocate()
     * @return bool True if deallocated, false if allocation not found
     */
    public function deallocate(string $allocationId): bool;

    /**
     * Deallocate all resources for a component
     *
     * @param string $componentUuid Component UUID
     * @return int Number of allocations removed
     */
    public function deallocateByComponent(string $componentUuid): int;

    /**
     * Get all allocations
     *
     * @return array Array of allocations [allocation_id => [quantity, component_uuid, metadata]]
     */
    public function getAllocations(): array;

    /**
     * Get allocations for specific component
     *
     * @param string $componentUuid Component UUID
     * @return array Array of allocations for this component
     */
    public function getComponentAllocations(string $componentUuid): array;

    /**
     * Reset pool (clear all allocations)
     *
     * @return void
     */
    public function reset(): void;

    /**
     * Get pool statistics
     *
     * @return array Statistics [total, used, available, utilization_percent]
     */
    public function getStats(): array;
}

/**
 * Resource Exhausted Exception
 *
 * Thrown when attempting to allocate more resources than available
 */
class ResourceExhaustedException extends RuntimeException {
    private int $requested;
    private int $available;

    public function __construct(int $requested, int $available, string $resourceType) {
        $this->requested = $requested;
        $this->available = $available;

        parent::__construct(
            "Insufficient {$resourceType}: requested {$requested}, available {$available}"
        );
    }

    public function getRequested(): int {
        return $this->requested;
    }

    public function getAvailable(): int {
        return $this->available;
    }
}
