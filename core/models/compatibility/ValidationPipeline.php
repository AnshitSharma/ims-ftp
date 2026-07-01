<?php
/**
 * ValidationPipeline — M11 Phase 4 / Phase 5 coordination shell.
 *
 * Provides a single entry point that runs all Phase-3 add-time authorities in sequence,
 * returning a unified compatibility verdict. Designed to replace the per-authority
 * delegate blocks in ServerBuilder::validateComponentAddition() once all authority flags
 * are on >=shadow and their enforce paths have been soaked and verified.
 *
 * Standalone authority classes (Phase 5 extraction — all now exist):
 *   ims-ftp/core/models/compatibility/SlotAuthority.php
 *   ims-ftp/core/models/compatibility/StorageConnectionAuthority.php
 *   ims-ftp/core/models/compatibility/MemoryAuthority.php
 *
 * Current state (Phase 5 extraction complete, pipeline wiring pending):
 *   ServerBuilder::validateComponentAddition() delegates each authority inline gate
 *   to the standalone class (e.g. (new SlotAuthority($pdo))->evaluate(...)). The
 *   inline delegate blocks in ServerBuilder remain until VALIDATION_PIPELINE_ENABLED
 *   is flipped to enforce, at which point they are replaced by one run() call.
 *
 * Phase 5 final (operator action required first — all authority flags at enforce):
 *   1. Flip VALIDATION_PIPELINE_ENABLED=shadow, soak, then =enforce.
 *   2. Replace the per-authority delegate blocks in validateComponentAddition() with:
 *        $override = (new ValidationPipeline($this->pdo))->run(...);
 *        if ($override !== null) { $compatibilityResult = $override; }
 *   3. Remove the per-authority require_once + delegate blocks (now dead code).
 *
 * MemoryAuthority covers the finalize-time path (validateConfigurationEnhanced) and
 * is called through a separate runFinalize() call rather than run() here.
 *
 * VALIDATION_PIPELINE_ENABLED flag:
 *   off     = no-op; per-authority delegate blocks in ServerBuilder remain active (default)
 *   shadow  = (future) aggregate authority shadow results into one log entry
 *   enforce = (future) pipeline is the sole authority; per-authority delegates bypassed
 *
 * @see SlotAuthority, StorageConnectionAuthority — add-time authority classes (standalone)
 * @see MemoryAuthority — finalize-time authority class (standalone)
 * @see ServerBuilder::validateComponentAddition() — current call site for delegate blocks
 */
class ValidationPipeline
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Pipeline mode reader.
     * Returns 'off' | 'shadow' | 'enforce'.
     */
    public static function mode(): string
    {
        $m = getenv('VALIDATION_PIPELINE_ENABLED');
        if (!is_string($m) || $m === '') {
            $m = $_ENV['VALIDATION_PIPELINE_ENABLED'] ?? 'off';
        }
        $m = strtolower(trim((string)$m));
        return in_array($m, ['off', 'shadow', 'enforce'], true) ? $m : 'off';
    }

    /**
     * Run all add-time authorities for a component-add event.
     *
     * When VALIDATION_PIPELINE_ENABLED=off (default), returns null immediately so
     * per-authority delegate blocks in ServerBuilder remain in control.
     *
     * When enforce: calls SlotAuthority (for nic/pciecard/hbacard) and
     * StorageConnectionAuthority (for storage) in sequence, returning the first
     * non-null override. Each authority still respects its own flag (SLOT_AUTHORITY_ENABLED,
     * STORAGE_CONNECTION_AUTHORITY_ENABLED) — if an authority is off, it returns null
     * and the pipeline moves to the next.
     *
     * @param string $configUuid     Server configuration UUID
     * @param string $componentType  Component type being added (cpu/ram/storage/…)
     * @param string $componentUuid  UUID of the component being added
     * @param array  $flatExisting   Flat existing components: [['component_type'=>…,'component_uuid'=>…],…]
     * @param array  $keyedExisting  (reserved for future use; pass [] for now)
     *
     * @return array|null  Compatibility result ['compatible'=>bool,…] or null (defer to per-authority delegates)
     */
    public function run(
        string $configUuid,
        string $componentType,
        string $componentUuid,
        array $flatExisting,
        array $keyedExisting
    ): ?array {
        $pipelineMode = self::mode();

        if ($pipelineMode === 'off') {
            return null;
        }

        require_once __DIR__ . '/SlotAuthority.php';
        require_once __DIR__ . '/StorageConnectionAuthority.php';

        $override = null;

        // SlotAuthority — PCIe slot consumers only
        if (in_array($componentType, ['nic', 'pciecard', 'hbacard'], true)) {
            $slotResult = (new SlotAuthority($this->pdo))->evaluate(
                $configUuid, $componentType, $componentUuid, $override
            );
            if ($slotResult !== null) {
                $override = $slotResult;
            }
        }

        // StorageConnectionAuthority — storage components only
        if ($componentType === 'storage') {
            $scaResult = (new StorageConnectionAuthority($this->pdo))->evaluate(
                $configUuid, $componentUuid, $flatExisting, $override
            );
            if ($scaResult !== null) {
                $override = $scaResult;
            }
        }

        if ($pipelineMode === 'shadow') {
            // Authorities have logged their own divergences; pipeline returns null so
            // per-authority delegate blocks in ServerBuilder also run (no double-override).
            error_log(sprintf(
                'ValidationPipeline::run() shadow pass complete: config=%s type=%s uuid=%s',
                $configUuid, $componentType, $componentUuid
            ));
            return null;
        }

        // enforce: return the combined authority verdict (null = no authority had an opinion)
        return $override;
    }

    /**
     * Run the MemoryAuthority for a finalize-time RAM check.
     *
     * When VALIDATION_PIPELINE_ENABLED=off (default), returns null so the
     * MemoryAuthority delegate block in ServerBuilder::validateConfigurationEnhanced()
     * remains in control.
     *
     * @param string $ramUuid                UUID of the RAM module
     * @param array  $allComponentsForMemory All assembled components [['type'=>…,'uuid'=>…],…]
     * @param object $compatibility           ComponentCompatibility instance
     * @param array  $legacyTypeResult        Result from validateRAMTypeCompatibility
     * @param array  &$warningsAccumulator    Reference to enhanced-validation warnings array
     * @return array|null  New typeResult override, or null (defer to delegate block)
     */
    public function runFinalize(
        string $ramUuid,
        array $allComponentsForMemory,
        object $compatibility,
        array $legacyTypeResult,
        array &$warningsAccumulator
    ): ?array {
        if (self::mode() === 'off') {
            return null;
        }

        require_once __DIR__ . '/MemoryAuthority.php';
        $result = (new MemoryAuthority($this->pdo))->evaluate(
            $ramUuid, $allComponentsForMemory, $compatibility, $legacyTypeResult, $warningsAccumulator
        );

        if (self::mode() === 'shadow') {
            return null; // shadow: MemoryAuthority already logged; delegate block still runs
        }

        return $result; // enforce: return authority verdict
    }
}
