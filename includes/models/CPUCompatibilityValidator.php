<?php
/**
 * CPU Compatibility Validator
 *
 * Validates CPU addition against existing server configuration components.
 * Performs bidirectional validation to ensure compatibility.
 *
 * VALIDATION CHECKS:
 * 1. Socket compatibility (CPU ↔ Motherboard)
 * 2. Memory type support (CPU ↔ RAM)
 * 3. Memory capacity limits (CPU max memory vs existing RAM)
 * 4. Memory frequency limits (CPU max frequency vs RAM speed)
 * 5. ECC requirements (CPU ECC requirement vs RAM ECC capability)
 * 6. PCIe lane budget (CPU PCIe lanes vs existing PCIe devices)
 * 7. PCIe version compatibility (CPU vs Motherboard)
 * 8. CPU socket count limits (multi-socket motherboards)
 *
 * @package BDC_IMS
 * @subpackage Validators
 * @version 1.0
 */

require_once __DIR__ . '/BaseComponentValidator.php';

class CPUCompatibilityValidator extends BaseComponentValidator {

    /**
     * Validate CPU addition to server configuration
     *
     * @param string $configUuid Server configuration UUID
     * @param string $cpuUuid CPU component UUID
     * @param array $existingComponents Existing components organized by type
     * @return array Validation result with status, errors, warnings, info
     */
    public function validateAddition($configUuid, $cpuUuid, $existingComponents) {
        $errors = [];
        $warnings = [];
        $info = [];

        try {
            // Get CPU specs from JSON
            $cpuSpecs = $this->getComponentSpecs('cpu', $cpuUuid);
            if (!$cpuSpecs) {
                return $this->buildComponentNotFoundResponse('cpu', $cpuUuid);
            }

            // Extract CPU specifications
            $cpuSocket = $cpuSpecs['socket'] ?? null;
            $cpuMemoryTypes = $this->dataUtils->extractMemoryTypes($cpuSpecs);
            $cpuMaxMemoryFreq = $this->dataUtils->extractMaxMemoryFrequency($cpuSpecs);
            $cpuMaxMemoryCapacity = $this->dataUtils->extractMaxMemoryCapacity($cpuSpecs);
            $cpuECCRequired = $this->dataUtils->extractECCSupport($cpuSpecs) === 'required';
            $cpuPCIeLanes = $this->dataUtils->extractPCIeLanes($cpuSpecs);
            $cpuPCIeVersion = $this->dataUtils->extractPCIeVersion($cpuSpecs);

            // FORWARD CHECKS: CPU → Motherboard
            if ($existingComponents['motherboard']) {
                $motherboardErrors = $this->validateCPUSocketCompatibility(
                    $cpuSocket,
                    $cpuPCIeVersion,
                    $existingComponents['motherboard'],
                    count($existingComponents['cpu'])
                );
                $errors = array_merge($errors, $motherboardErrors['errors']);
                $warnings = array_merge($warnings, $motherboardErrors['warnings']);
            } else {
                // No motherboard - informational warning
                $warnings[] = $this->recordValidationWarning(
                    'no_motherboard',
                    'No motherboard in configuration',
                    'low',
                    ['cpu_socket_required' => $cpuSocket],
                    "CPU requires $cpuSocket socket motherboard"
                );
            }

            // REVERSE CHECKS: Existing RAM → CPU
            if (!empty($existingComponents['ram'])) {
                $ramErrors = $this->validateCPUMemorySupport(
                    $cpuMemoryTypes,
                    $cpuMaxMemoryFreq,
                    $cpuMaxMemoryCapacity,
                    $cpuECCRequired,
                    $existingComponents['ram']
                );
                $errors = array_merge($errors, $ramErrors['errors']);
                $warnings = array_merge($warnings, $ramErrors['warnings']);
            }

            // REVERSE CHECKS: Existing PCIe devices → CPU
            if (!empty($existingComponents['nic']) || !empty($existingComponents['pciecard'])) {
                $pcieErrors = $this->validateCPUPowerRequirements(
                    $cpuPCIeLanes,
                    $existingComponents['nic'],
                    $existingComponents['pciecard']
                );
                $errors = array_merge($errors, $pcieErrors['errors']);
                $warnings = array_merge($warnings, $pcieErrors['warnings']);
            }

            // Add CPU specifications info
            $info[] = $this->recordValidationInfo(
                'cpu_specifications',
                'CPU specifications loaded',
                [
                    'socket' => $cpuSocket,
                    'memory_types' => $cpuMemoryTypes,
                    'max_memory_frequency' => $cpuMaxMemoryFreq ? $cpuMaxMemoryFreq . 'MHz' : 'N/A',
                    'max_memory_capacity' => $cpuMaxMemoryCapacity ? $cpuMaxMemoryCapacity . 'GB' : 'N/A',
                    'pcie_lanes' => $cpuPCIeLanes,
                    'pcie_version' => $cpuPCIeVersion,
                    'ecc_required' => $cpuECCRequired ? 'Yes' : 'No'
                ]
            );

            return $this->buildValidationResponse($errors, $warnings, $info);

        } catch (Exception $e) {
            error_log("CPUCompatibilityValidator::validateAddition Error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());

            return [
                'validation_status' => 'blocked',
                'critical_errors' => [[
                    'type' => 'cpu_validation_system_error',
                    'severity' => 'critical',
                    'message' => 'CPU validation system error: ' . $e->getMessage()
                ]],
                'warnings' => [],
                'info_messages' => []
            ];
        }
    }

    /**
     * Validate CPU socket compatibility with motherboard
     *
     * @param string|null $cpuSocket CPU socket type (e.g., "LGA 1700")
     * @param float|null $cpuPCIeVersion CPU PCIe version (e.g., 5.0)
     * @param array $motherboard Existing motherboard component
     * @param int $currentCPUCount Number of CPUs already in configuration
     * @return array Array with 'errors' and 'warnings' keys
     */
    protected function validateCPUSocketCompatibility($cpuSocket, $cpuPCIeVersion, $motherboard, $currentCPUCount) {
        $errors = [];
        $warnings = [];

        $motherboardSpecs = $this->getComponentSpecs('motherboard', $motherboard['component_uuid']);
        if (!$motherboardSpecs) {
            error_log("CPUCompatibilityValidator: Failed to load motherboard specs for " . $motherboard['component_uuid']);
            return ['errors' => $errors, 'warnings' => $warnings];
        }

        // Extract motherboard socket information
        $socketData = $motherboardSpecs['socket'] ?? null;

        // Handle socket format: string "LGA 4189" OR object {"type": "LGA 4189", "count": 2}
        if (is_array($socketData)) {
            $motherboardSocket = $socketData['type'] ?? null;
            $motherboardMaxCPUs = $socketData['count'] ?? 1;
        } else {
            $motherboardSocket = $socketData;
            $motherboardMaxCPUs = $motherboardSpecs['max_cpus'] ?? 1;
        }

        $motherboardPCIeVersion = $this->dataUtils->extractPCIeVersion($motherboardSpecs);

        // Socket mismatch check
        if ($cpuSocket && $motherboardSocket && $cpuSocket !== $motherboardSocket) {
            $errors[] = $this->recordValidationError(
                'socket_mismatch',
                "CPU socket $cpuSocket incompatible with motherboard socket $motherboardSocket",
                'critical',
                [
                    'cpu_socket' => $cpuSocket,
                    'motherboard_socket' => $motherboardSocket,
                    'motherboard_uuid' => $motherboard['component_uuid']
                ],
                "Remove motherboard and add $cpuSocket motherboard OR choose $motherboardSocket CPU"
            );
        }

        // CPU socket limit check
        if ($currentCPUCount >= $motherboardMaxCPUs) {
            $errors[] = $this->recordValidationError(
                'cpu_socket_limit_exceeded',
                "Motherboard supports $motherboardMaxCPUs CPU socket(s), $currentCPUCount already installed",
                'critical',
                [
                    'current_cpu_count' => $currentCPUCount,
                    'max_cpus' => $motherboardMaxCPUs
                ],
                "Remove existing CPU OR use motherboard with more CPU sockets"
            );
        }

        // PCIe version compatibility check
        if ($cpuPCIeVersion && $motherboardPCIeVersion && $cpuPCIeVersion > $motherboardPCIeVersion) {
            $warnings[] = $this->recordValidationWarning(
                'pcie_version_mismatch',
                "CPU supports PCIe $cpuPCIeVersion, but motherboard only supports PCIe $motherboardPCIeVersion",
                'medium',
                [
                    'cpu_pcie_version' => $cpuPCIeVersion,
                    'motherboard_pcie_version' => $motherboardPCIeVersion
                ],
                "PCIe devices will be limited to PCIe $motherboardPCIeVersion bandwidth. Choose PCIe $cpuPCIeVersion motherboard for full CPU capabilities"
            );
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Validate CPU memory support against existing RAM
     *
     * @param array $cpuMemoryTypes Supported memory types (e.g., ["DDR5-4800", "DDR5-5600"])
     * @param int|null $cpuMaxMemoryFreq Maximum memory frequency in MHz
     * @param int|null $cpuMaxMemoryCapacity Maximum memory capacity in GB
     * @param bool $cpuECCRequired Whether CPU requires ECC RAM
     * @param array $existingRAM Array of existing RAM components
     * @return array Array with 'errors' and 'warnings' keys
     */
    protected function validateCPUMemorySupport($cpuMemoryTypes, $cpuMaxMemoryFreq, $cpuMaxMemoryCapacity, $cpuECCRequired, $existingRAM) {
        $errors = [];
        $warnings = [];

        // Collect RAM specifications
        $ramTypes = [];
        $ramTotalCapacity = 0;
        $maxRamFreq = 0;

        foreach ($existingRAM as $ram) {
            $ramSpecs = $this->getComponentSpecs('ram', $ram['component_uuid']);
            if (!$ramSpecs) {
                continue;
            }

            // RAM type
            $ramType = $ramSpecs['memory_type'] ?? $ramSpecs['type'] ?? null;
            if ($ramType) {
                $ramTypes[] = $ramType;
            }

            // RAM capacity
            $capacity = $this->parseCapacity($ramSpecs['capacity_gb'] ?? $ramSpecs['capacity'] ?? 0);
            $ramTotalCapacity += $capacity * ($ram['quantity'] ?? 1);

            // RAM frequency
            $frequency = $this->parseFrequency($ramSpecs['frequency_mhz'] ?? $ramSpecs['frequency'] ?? 0);
            $maxRamFreq = max($maxRamFreq, $frequency);

            // ECC check
            if ($cpuECCRequired) {
                $ramIsECC = ($ramSpecs['ecc'] ?? $ramSpecs['is_ecc'] ?? false);
                if (!$ramIsECC) {
                    $errors[] = $this->recordValidationError(
                        'ecc_required',
                        "CPU requires ECC RAM, existing RAM is non-ECC",
                        'critical',
                        [
                            'cpu_ecc_requirement' => 'mandatory',
                            'affected_ram' => $ram['component_uuid']
                        ],
                        "Remove non-ECC RAM and add ECC RAM OR choose CPU without ECC requirement"
                    );
                    break; // One error is enough
                }
            }
        }

        // RAM type compatibility check
        $ramTypes = array_unique($ramTypes);
        if (!empty($ramTypes)) {
            $incompatibleTypes = [];
            foreach ($ramTypes as $ramType) {
                if (!$this->isMemoryTypeCompatible($ramType, $cpuMemoryTypes)) {
                    $incompatibleTypes[] = $ramType;
                }
            }

            if (!empty($incompatibleTypes)) {
                $errors[] = $this->recordValidationError(
                    'ram_type_incompatibility',
                    "CPU supports " . implode('/', $cpuMemoryTypes) . " only, existing RAM is " . implode('/', $ramTypes),
                    'critical',
                    [
                        'cpu_supports' => $cpuMemoryTypes,
                        'existing_ram_types' => $ramTypes,
                        'incompatible_types' => $incompatibleTypes
                    ],
                    "Remove " . implode('/', $incompatibleTypes) . " RAM and add " . implode('/', $cpuMemoryTypes) . " RAM OR choose CPU with " . implode('/', $ramTypes) . " support"
                );
            }
        }

        // RAM total capacity check
        if ($cpuMaxMemoryCapacity && $ramTotalCapacity > $cpuMaxMemoryCapacity) {
            $errors[] = $this->recordValidationError(
                'ram_capacity_exceeded',
                "Existing RAM total {$ramTotalCapacity}GB exceeds CPU maximum capacity {$cpuMaxMemoryCapacity}GB",
                'critical',
                [
                    'existing_ram_total' => $ramTotalCapacity . 'GB',
                    'cpu_max_capacity' => $cpuMaxMemoryCapacity . 'GB',
                    'excess' => ($ramTotalCapacity - $cpuMaxMemoryCapacity) . 'GB'
                ],
                "Remove RAM modules to reduce total to {$cpuMaxMemoryCapacity}GB OR choose CPU with higher memory capacity"
            );
        }

        // RAM frequency check
        if ($cpuMaxMemoryFreq && $maxRamFreq > $cpuMaxMemoryFreq) {
            $reduction = round((1 - ($cpuMaxMemoryFreq / $maxRamFreq)) * 100, 1);
            $warnings[] = $this->recordValidationWarning(
                'ram_frequency_downgrade',
                "Existing RAM frequency {$maxRamFreq}MHz exceeds CPU max {$cpuMaxMemoryFreq}MHz. RAM will downclock to {$cpuMaxMemoryFreq}MHz",
                'medium',
                [
                    'ram_frequency' => $maxRamFreq . 'MHz',
                    'cpu_max_frequency' => $cpuMaxMemoryFreq . 'MHz',
                    'effective_frequency' => $cpuMaxMemoryFreq . 'MHz'
                ],
                "~{$reduction}% performance reduction from rated speed"
            );
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Validate CPU PCIe lane budget against existing PCIe devices
     *
     * @param int|null $cpuPCIeLanes Number of PCIe lanes provided by CPU
     * @param array $existingNICs Array of existing NIC components
     * @param array $existingPCIeCards Array of existing PCIe card components
     * @return array Array with 'errors' and 'warnings' keys
     */
    protected function validateCPUPowerRequirements($cpuPCIeLanes, $existingNICs, $existingPCIeCards) {
        $errors = [];
        $warnings = [];

        if (!$cpuPCIeLanes) {
            return ['errors' => $errors, 'warnings' => $warnings];
        }

        $pcieLaneRequirement = 0;
        $pcieDevices = array_merge($existingNICs, $existingPCIeCards);

        foreach ($pcieDevices as $device) {
            $deviceSpecs = $this->getComponentFromInventory($device['component_type'], $device['component_uuid']);
            if ($deviceSpecs) {
                $lanes = $this->extractPCIeSlotSize($deviceSpecs);
                $pcieLaneRequirement += $lanes * ($device['quantity'] ?? 1);
            }
        }

        if ($pcieLaneRequirement > $cpuPCIeLanes) {
            $errors[] = $this->recordValidationError(
                'pcie_lane_budget_exceeded',
                "Existing PCIe cards require {$pcieLaneRequirement} lanes, CPU only provides {$cpuPCIeLanes} lanes",
                'critical',
                [
                    'total_lanes_required' => $pcieLaneRequirement,
                    'cpu_provides' => $cpuPCIeLanes,
                    'deficit' => $pcieLaneRequirement - $cpuPCIeLanes,
                    'device_count' => count($pcieDevices)
                ],
                "Remove PCIe devices OR choose CPU with more PCIe lanes (e.g., Threadripper: 128 lanes)"
            );
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
