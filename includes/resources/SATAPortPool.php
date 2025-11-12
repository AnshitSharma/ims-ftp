<?php
require_once __DIR__ . '/ResourcePoolInterface.php';

/**
 * SATA Port Pool
 *
 * Tracks SATA port allocation across motherboard and SATA controllers.
 *
 * SATA Port Sources:
 * 1. Motherboard SATA ports (most common)
 *    - Typically 2-8 ports on standard motherboards
 *    - Integrated into chipset
 *
 * 2. HBA/SATA Controller cards
 *    - 8-16+ ports per card
 *    - PCIe-based expansion
 *    - Consumes PCIe lanes
 *
 * Port States:
 * - Available: Can be allocated to storage devices
 * - Reserved: Used by onboard features (backplane controllers)
 * - Allocated: In use by storage component
 *
 * Allocation Strategy:
 * - Prefer motherboard ports (lowest latency)
 * - Fall back to HBA controller ports
 * - Simple round-robin allocation
 */
class SATAPortPool implements ResourcePoolInterface {

    /** @var array Ports [port_id => [source, location, available]] */
    private array $ports = [];

    /** @var array Allocations [allocation_id => [port_id, component_uuid, ...]] */
    private array $allocations = [];

    /** @var int Next allocation ID */
    private int $nextAllocationId = 1;

    /**
     * Constructor
     *
     * @param array $ports Port definitions
     */
    public function __construct(array $ports = []) {
        foreach ($ports as $port) {
            $this->addPort(
                $port['id'],
                $port['source'] ?? 'motherboard',
                $port['location'] ?? 'motherboard',
                $port['reserved'] ?? false
            );
        }
    }

    /**
     * Add SATA port to pool
     *
     * @param string $portId Unique port ID (e.g., "sata0", "hba0_port4")
     * @param string $source Port source (motherboard, hba, backplane)
     * @param string $location Location identifier
     * @param bool $reserved If true, port is reserved for internal use
     * @return void
     */
    public function addPort(string $portId, string $source = 'motherboard', string $location = 'motherboard', bool $reserved = false): void {
        $this->ports[$portId] = [
            'source' => $source,
            'location' => $location,
            'reserved' => $reserved,
            'available' => !$reserved  // Reserved ports start as unavailable
        ];
    }

    /**
     * Factory: Create from configuration
     *
     * LOGIC:
     * 1. Extract motherboard SATA ports
     * 2. Extract HBA controller SATA ports
     * 3. Calculate reserved ports for backplane controllers
     * 4. Build port pool
     *
     * @param array $configuration Server configuration
     * @return self SATA port pool instance
     */
    public static function createFromConfiguration(array $configuration): self {
        $ports = [];

        // Extract motherboard SATA ports
        if (isset($configuration['motherboard'][0]['sata_ports'])) {
            $sataPorts = (int)$configuration['motherboard'][0]['sata_ports'];

            for ($i = 0; $i < $sataPorts; $i++) {
                $ports[] = [
                    'id' => "mb_sata_{$i}",
                    'source' => 'motherboard',
                    'location' => 'motherboard',
                    'reserved' => false
                ];
            }
        }

        // Extract SATA ports from HBA/Storage Controller cards
        if (isset($configuration['hbacard'])) {
            foreach ($configuration['hbacard'] as $hbaIndex => $hba) {
                $hbaId = $hba['uuid'] ?? "hba_{$hbaIndex}";
                $portCount = (int)($hba['sata_ports'] ?? 0);

                for ($i = 0; $i < $portCount; $i++) {
                    $ports[] = [
                        'id' => "hba_{$hbaId}_sata_{$i}",
                        'source' => 'hba',
                        'location' => $hbaId,
                        'reserved' => false
                    ];
                }
            }
        }

        // Reserve ports for backplane controllers
        if (isset($configuration['chassis'][0]['sata_backplane_ports'])) {
            $backplanePorts = (int)$configuration['chassis'][0]['sata_backplane_ports'];

            for ($i = 0; $i < $backplanePorts; $i++) {
                $ports[] = [
                    'id' => "backplane_sata_{$i}",
                    'source' => 'backplane',
                    'location' => 'backplane',
                    'reserved' => true  // Reserved for internal backplane controller
                ];
            }
        }

        return new self($ports);
    }

    public function getTotalCapacity(): int {
        return count($this->ports);
    }

    public function getUsedCapacity(): int {
        return count($this->allocations);
    }

    public function getAvailableCapacity(): int {
        // Count non-reserved, available ports
        $available = 0;
        foreach ($this->ports as $port) {
            if (!$port['reserved'] && $port['available']) {
                $available++;
            }
        }
        return $available;
    }

    public function isAvailable(int $quantity): bool {
        return $this->getAvailableCapacity() >= $quantity;
    }

    /**
     * Find next available port
     *
     * LOGIC:
     * 1. Skip reserved ports
     * 2. Prefer motherboard ports
     * 3. Fall back to HBA ports
     * 4. Return first available port
     *
     * @param string|null $preferredLocation Preferred location (optional)
     * @return string|null Port ID or null if none available
     */
    public function findNextAvailablePort(?string $preferredLocation = null): ?string {
        // First pass: check preferred location
        if ($preferredLocation !== null) {
            foreach ($this->ports as $portId => $port) {
                if (!$port['reserved'] && $port['available'] && $port['location'] === $preferredLocation) {
                    return $portId;
                }
            }
        }

        // Second pass: prefer motherboard ports
        foreach ($this->ports as $portId => $port) {
            if (!$port['reserved'] && $port['available'] && $port['source'] === 'motherboard') {
                return $portId;
            }
        }

        // Third pass: HBA ports
        foreach ($this->ports as $portId => $port) {
            if (!$port['reserved'] && $port['available'] && $port['source'] === 'hba') {
                return $portId;
            }
        }

        // Fourth pass: any non-reserved available port
        foreach ($this->ports as $portId => $port) {
            if (!$port['reserved'] && $port['available']) {
                return $portId;
            }
        }

        return null;
    }

    public function allocate(int $quantity, string $componentUuid, array $metadata = []): string {
        $preferredLocation = $metadata['preferred_location'] ?? null;
        $portId = $this->findNextAvailablePort($preferredLocation);

        if ($portId === null) {
            throw new ResourceExhaustedException(
                $quantity,
                0,
                'SATA ports'
            );
        }

        // Mark port as unavailable
        $this->ports[$portId]['available'] = false;

        // Create allocation
        $allocationId = 'sata_port_' . $this->nextAllocationId++;

        $this->allocations[$allocationId] = [
            'port_id' => $portId,
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

        $portId = $this->allocations[$allocationId]['port_id'];
        $this->ports[$portId]['available'] = true;

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
        foreach ($this->ports as $portId => $port) {
            // Only reset non-reserved ports
            if (!$port['reserved']) {
                $this->ports[$portId]['available'] = true;
            }
        }
        $this->allocations = [];
        $this->nextAllocationId = 1;
    }

    public function getStats(): array {
        $total = $this->getTotalCapacity();
        $used = $this->getUsedCapacity();
        $reserved = 0;
        $available = $this->getAvailableCapacity();

        // Count by source
        $bySource = [];
        foreach ($this->ports as $port) {
            $source = $port['source'];
            if (!isset($bySource[$source])) {
                $bySource[$source] = ['total' => 0, 'reserved' => 0, 'used' => 0, 'available' => 0];
            }
            $bySource[$source]['total']++;

            if ($port['reserved']) {
                $bySource[$source]['reserved']++;
                $reserved++;
            } else {
                if (!$port['available']) {
                    $bySource[$source]['used']++;
                } else {
                    $bySource[$source]['available']++;
                }
            }
        }

        return [
            'total' => $total,
            'used' => $used,
            'available' => $available,
            'reserved' => $reserved,
            'utilization_percent' => ($total - $reserved) > 0 ? ($used / ($total - $reserved)) * 100 : 0,
            'by_source' => $bySource
        ];
    }
}
