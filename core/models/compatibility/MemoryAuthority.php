<?php
/**
 * MemoryAuthority — M11 Phase 3 / Phase 5 standalone class.
 *
 * Makes the finalize-time RAM compatibility check as strict as the add-time check.
 * The add-time path uses ComponentCompatibility::checkRAMDecentralizedCompatibility
 * (form factor, module type including RDIMM/UDIMM mixing, DDR-generation intersection
 * across ALL CPUs + motherboard). The legacy finalize path only called the weaker
 * validateRAMTypeCompatibility (motherboard type match only — misses RDIMM/UDIMM
 * mixing, form-factor mismatch, multi-CPU DDR intersection, etc.).
 * Extracted from the inline gate in ServerBuilder::validateConfigurationEnhanced() in Phase 5.
 *
 * Flag: MEMORY_AUTHORITY_ENABLED
 *   off     = legacy validateRAMTypeCompatibility at finalize-time (default; byte-identical)
 *   shadow  = also run comprehensive check, log divergence, still return legacy result
 *   enforce = comprehensive check is the authority for finalize-time RAM decisions
 *
 * Phase 5 wiring note:
 *   ServerBuilder::validateConfigurationEnhanced() delegates the inline gate to this class.
 *   A future ValidationPipeline::runFinalize() will call evaluate() directly once
 *   VALIDATION_PIPELINE_ENABLED moves to enforce for the finalize path.
 *
 * @see ComponentCompatibility::checkRAMDecentralizedCompatibility() — the comprehensive check
 * @see ComponentCompatibility::validateRAMTypeCompatibility() — the legacy weaker check
 * @see ServerBuilder::validateConfigurationEnhanced() — current call site
 */
class MemoryAuthority
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Mode reader — returns 'off' | 'shadow' | 'enforce'.
     * Reads MEMORY_AUTHORITY_ENABLED env var; any unrecognised value maps to 'off'.
     */
    public static function mode(): string
    {
        $m = getenv('MEMORY_AUTHORITY_ENABLED');
        if (!is_string($m) || $m === '') {
            $m = $_ENV['MEMORY_AUTHORITY_ENABLED'] ?? 'off';
        }
        $m = strtolower(trim((string)$m));
        return in_array($m, ['off', 'shadow', 'enforce'], true) ? $m : 'off';
    }

    /**
     * Evaluate RAM type compatibility at finalize-time.
     *
     * Reads its own MEMORY_AUTHORITY_ENABLED flag. In shadow mode logs any divergence
     * from the legacy validateRAMTypeCompatibility but never overrides it. In enforce
     * mode, the comprehensive check (checkRAMDecentralizedCompatibility) is the sole
     * authority and its result is returned as the new $typeResult.
     *
     * @param string $ramUuid                UUID of the RAM module being validated
     * @param array  $allComponentsForMemory All assembled components [['type'=>…,'uuid'=>…],…]
     *                                        (pre-built before the RAM loop; see ServerBuilder)
     * @param object $compatibility           ComponentCompatibility instance
     * @param array  $legacyTypeResult        Result from validateRAMTypeCompatibility
     *                                        (keys: compatible, error, details)
     * @param array  &$warningsAccumulator    Reference to $enhancedValidation['warnings'];
     *                                        authority warnings are appended on enforce
     * @return array|null  New typeResult (compatible/error/details) on enforce; null otherwise
     */
    public function evaluate(
        string $ramUuid,
        array $allComponentsForMemory,
        object $compatibility,
        array $legacyTypeResult,
        array &$warningsAccumulator
    ): ?array {
        $mode = self::mode();
        if ($mode === 'off') {
            return null;
        }

        try {
            // All OTHER assembled components become the "existing" context for the check
            $otherComponents = array_values(array_filter(
                $allComponentsForMemory,
                static function (array $c) use ($ramUuid): bool {
                    return $c['uuid'] !== $ramUuid;
                }
            ));

            $authorityResult = $compatibility->checkRAMDecentralizedCompatibility(
                ['type' => 'ram', 'uuid' => $ramUuid],
                $otherComponents
            );

            if ($mode === 'shadow') {
                $legacyPass   = (bool)$legacyTypeResult['compatible'];
                $authorityPass = (bool)$authorityResult['compatible'];
                if ($legacyPass !== $authorityPass) {
                    error_log(sprintf(
                        'MemoryAuthority shadow divergence (finalize): ram=%s legacy.pass=%s authority.pass=%s' .
                        ' legacy_error=%s authority_issues=%s',
                        $ramUuid,
                        $legacyPass ? 'true' : 'false',
                        $authorityPass ? 'true' : 'false',
                        json_encode($legacyTypeResult['error'] ?? null),
                        json_encode($authorityResult['issues'] ?? [])
                    ));
                }
                return null; // shadow never overrides
            }

            // enforce: comprehensive check is the authority for finalize-time type decisions
            foreach ($authorityResult['warnings'] ?? [] as $w) {
                $warningsAccumulator[] = $w;
            }
            return [
                'compatible' => $authorityResult['compatible'],
                'error'      => !empty($authorityResult['issues'])
                    ? implode('; ', $authorityResult['issues']) : null,
                'details'    => $authorityResult['details'] ?? null,
            ];

        } catch (\Throwable $e) {
            error_log('MemoryAuthority error (fail-open to legacy): ' . $e->getMessage());
            return null;
        }
    }
}
