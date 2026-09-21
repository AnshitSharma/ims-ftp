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
        $normalized = strtoupper($normalized);

        // Remove ECC/non-ECC suffixes (e.g., "DDR4 ECC" → "DDR4")
        // ECC is a feature flag, not a memory generation identifier
        $normalized = preg_replace('/\s+(ECC|NON-ECC|NONECC|REGISTERED|UNBUFFERED)$/i', '', $normalized);

        return $normalized;
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

        if ($protocol === 'sata') {
            // SATA generation is a Roman numeral / link rate, NOT the raw Gb/s number.
            // BUGFIX (M5): "SATA 6Gb/s" previously parsed as generation 6 instead of
            // SATA III (gen 3). Map both the Roman numeral and the link rate.
            //   SATA III / 6Gb/s   -> 3
            //   SATA II  / 3Gb/s   -> 2
            //   SATA I   / 1.5Gb/s -> 1
            // Check the higher generations first ("iii" contains "ii").
            if (strpos($interface, 'iii') !== false || strpos($interface, '6g') !== false || strpos($interface, 'sata3') !== false) {
                $generation = 3;
            } elseif (strpos($interface, 'ii') !== false || strpos($interface, '3g') !== false || strpos($interface, 'sata2') !== false) {
                $generation = 2;
            } elseif (strpos($interface, '1.5g') !== false || strpos($interface, 'sata1') !== false || preg_match('/\bi\b/', $interface)) {
                $generation = 1;
            } elseif (preg_match('/sata\s*([123])\b/', $interface, $m)) {
                $generation = (int)$m[1];
            }
        } else {
            // PCIe / NVMe / SAS generations: "4.0", "3.0", "5.0", or a plain integer.
            if (preg_match('/(\d+)\.(\d+)/', $interface, $matches)) {
                $generation = (float)($matches[1] . '.' . $matches[2]);
            } elseif (preg_match('/(\d+)/', $interface, $matches)) {
                $generation = (int)$matches[1];
            }
        }

        return [
            'protocol' => $protocol,
            'generation' => $generation,
            'original' => $interface
        ];
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
    /**
     * Normalize CPU/motherboard socket type for comparison
     * Handles: FC prefix (FCLGA2011-3 → lga2011-3), internal spaces (LGA 4189 → lga4189), case
     *
     * @param string|null $socketType The socket type to normalize
     * @return string Normalized socket type (lowercase, no spaces, no FC prefix), or '' if empty
     */
    public static function normalizeSocketType($socketType) {
        if (!$socketType) {
            return '';
        }
        $normalized = strtolower(trim($socketType));
        // Strip Intel "FC" (Flip-Chip) prefix: FCLGA2011-3 → lga2011-3
        $normalized = preg_replace('/^fc/', '', $normalized);
        // Remove internal spaces: "lga 4189" → "lga4189"
        $normalized = str_replace(' ', '', $normalized);
        return $normalized;
    }
}
?>
