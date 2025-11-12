<?php
/**
 * Infrastructure Management System - Data Normalization Utilities
 * File: includes/models/DataNormalizationUtils.php
 *
 * Static utility methods for normalizing and standardizing component data
 * Extracted from ComponentCompatibility.php for better maintainability
 */

class DataNormalizationUtils {

    /**
     * Normalize memory type to base DDR type (DDR5, DDR4, etc.) without speed suffix
     * Examples: "DDR5-4800" → "DDR5", "ddr4" → "DDR4"
     *
     * @param string|null $memoryType The memory type to normalize
     * @return string|null Normalized memory type or null if empty
     */
    public static function normalizeMemoryType($memoryType) {
        if (!$memoryType) {
            return null;
        }

        // Remove speed suffix (e.g., "-4800", "-3200")
        $normalized = preg_replace('/-\d+$/', '', trim($memoryType));

        // Uppercase for consistency (DDR5, DDR4, etc.)
        return strtoupper($normalized);
    }

    /**
     * Extract DDR generation number from memory type
     * Examples: "DDR5" → 5, "DDR4" → 4, "DDR5-4800" → 5
     *
     * @param string|null $memoryType The memory type to analyze
     * @return int The DDR generation number, or 0 if not detected
     */
    public static function getMemoryGeneration($memoryType) {
        if (!$memoryType) {
            return 0;
        }

        // Normalize first to handle formats like "DDR5-4800"
        $normalized = self::normalizeMemoryType($memoryType);

        // Extract generation number (DDR5 → 5, DDR4 → 4)
        if (preg_match('/DDR(\d+)/', $normalized, $matches)) {
            return (int)$matches[1];
        }

        return 0;
    }

    /**
     * Normalize RAM form factor (DIMM, SO-DIMM, etc.)
     * Handles variations like "DIMM (288-pin)" → "DIMM"
     *
     * @param string $formFactor The form factor to normalize
     * @return string Normalized form factor
     */
    public static function normalizeFormFactor($formFactor) {
        $formFactor = strtoupper(trim($formFactor));

        // Handle common variations
        if (strpos($formFactor, 'SO-DIMM') !== false || strpos($formFactor, 'SODIMM') !== false) {
            return 'SO-DIMM';
        }

        if (strpos($formFactor, 'DIMM') !== false) {
            return 'DIMM';
        }

        return $formFactor;
    }

    /**
     * Normalize storage interface to protocol and generation
     * Examples: "SATA III" → ['protocol' => 'sata', 'generation' => 3]
     *           "NVMe PCIe 4.0" → ['protocol' => 'nvme', 'generation' => 4.0]
     *
     * @param string $interface The storage interface to normalize
     * @return array ['protocol' => string|null, 'generation' => int|float|null, 'original' => string]
     */
    public static function normalizeStorageInterface($interface) {
        $interface = strtolower(trim($interface));

        // Detect protocol
        $protocol = null;
        if (strpos($interface, 'nvme') !== false) {
            $protocol = 'nvme';
        } elseif (strpos($interface, 'sata') !== false) {
            $protocol = 'sata';
        } elseif (strpos($interface, 'sas') !== false) {
            $protocol = 'sas';
        } elseif (strpos($interface, 'u.2') !== false || strpos($interface, 'u.3') !== false) {
            $protocol = 'nvme'; // U.2/U.3 are NVMe protocols
        }

        // Extract generation number
        $generation = null;

        // Match patterns like: "4.0", "3.0", "5.0", "III", "3", etc.
        if (preg_match('/(\d+)\.(\d+)/', $interface, $matches)) {
            // PCIe generations: 3.0, 4.0, 5.0
            $generation = (float)($matches[1] . '.' . $matches[2]);
        } elseif (preg_match('/(\d+)/', $interface, $matches)) {
            // Simple number: SATA3, SAS3, etc.
            $generation = (int)$matches[1];
        } elseif (strpos($interface, 'iii') !== false || strpos($interface, 'sata iii') !== false) {
            // SATA III = SATA3
            $generation = 3;
        } elseif (strpos($interface, 'ii') !== false) {
            // SATA II = SATA2
            $generation = 2;
        }

        return [
            'protocol' => $protocol,
            'generation' => $generation,
            'original' => $interface
        ];
    }

    /**
     * Determine storage connection path based on form factor and interface
     * Returns: 'chassis_bay', 'motherboard_m2', 'motherboard_u2', 'pcie_adapter'
     *
     * @param string $formFactor Storage form factor (e.g., "M.2", "2.5-inch")
     * @param string $interface Storage interface (e.g., "NVMe", "SATA")
     * @return string Connection path type
     */
    public static function determineStorageConnectionPath($formFactor, $interface) {
        $formFactorLower = strtolower($formFactor);
        $interfaceLower = strtolower($interface);

        // M.2 form factors → motherboard M.2 slots
        if (strpos($formFactorLower, 'm.2') !== false || strpos($formFactorLower, 'm2') !== false) {
            return 'motherboard_m2';
        }

        // U.2 form factors → motherboard U.2 slots or PCIe adapter
        if (strpos($formFactorLower, 'u.2') !== false || strpos($formFactorLower, 'u.3') !== false) {
            return 'motherboard_u2';
        }

        // 2.5-inch or 3.5-inch SATA/SAS → chassis bays
        if (strpos($formFactorLower, '2.5') !== false || strpos($formFactorLower, '3.5') !== false) {
            if (strpos($interfaceLower, 'sata') !== false || strpos($interfaceLower, 'sas') !== false) {
                return 'chassis_bay';
            }
        }

        // Default to chassis bay for traditional drives
        return 'chassis_bay';
    }

    /**
     * Extract form factor size (2.5-inch or 3.5-inch)
     * Used for strict chassis bay matching
     *
     * @param string $formFactor The form factor to analyze
     * @return string Standardized form factor size
     */
    public static function extractFormFactorSize($formFactor) {
        $formFactorLower = strtolower($formFactor);

        if (strpos($formFactorLower, '2.5') !== false) {
            return '2.5-inch';
        }
        if (strpos($formFactorLower, '3.5') !== false) {
            return '3.5-inch';
        }

        // Return as-is if not standard size
        return $formFactor;
    }

    /**
     * Extract PCIe generation from storage interface string
     * Examples: "NVMe PCIe 4.0" → 4.0, "PCIe 5.0" → 5.0
     *
     * @param string $interface The interface string to parse
     * @return float PCIe generation number (defaults to 3.0)
     */
    public static function extractStoragePCIeGeneration($interface) {
        // Match "PCIe 4.0", "NVMe PCIe 4.0", etc.
        if (preg_match('/pcie\s*(\d+(?:\.\d+)?)/i', $interface, $matches)) {
            return (float)$matches[1];
        }

        // Default to 3.0 if not specified
        return 3.0;
    }

    /**
     * Normalize form factor for comparison
     * Handles variations: "3.5-inch", "3.5\"", "3.5 inch", "3.5inch" → "3.5-inch"
     *
     * @param string $formFactor The form factor to normalize
     * @return string Normalized form factor for comparison
     */
    public static function normalizeFormFactorForComparison($formFactor) {
        if (empty($formFactor)) {
            return '';
        }

        // Convert to lowercase and remove extra spaces
        $normalized = strtolower(trim($formFactor));

        // Replace variations of inch notation
        $normalized = str_replace(['"', ' inch', 'inch', '_'], ['', '-inch', '-inch', '-'], $normalized);

        // Ensure consistent format: "2.5-inch" or "3.5-inch"
        $normalized = preg_replace('/(\d+\.?\d*)\s*-?\s*inch/', '$1-inch', $normalized);

        return $normalized;
    }
}
?>
