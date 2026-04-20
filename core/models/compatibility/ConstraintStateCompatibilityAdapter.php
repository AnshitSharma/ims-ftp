<?php
/**
 * ConstraintStateCompatibilityAdapter
 * File: core/models/compatibility/ConstraintStateCompatibilityAdapter.php
 *
 * Bridge between the legacy ComponentCompatibility path and the new
 * ServerConfigConstraintState path. Runs as a passive shadow during
 * Phases 1 and 2 of the rollout; returns verdicts to callers during
 * Phase 3+ once env flags are flipped.
 *
 * SAFETY CONTRACT:
 *   * Every public method swallows its own exceptions and either returns
 *     a benign fallback or logs silently. The legacy path must remain
 *     authoritative and undisturbed until cutover flags are set.
 *   * No caller needs to wrap these methods in try/catch.
 */

require_once __DIR__ . '/../server/ServerConfigConstraintState.php';
require_once __DIR__ . '/../server/ConstraintStateRepository.php';
require_once __DIR__ . '/../server/ConstraintDecision.php';

class ConstraintStateCompatibilityAdapter
{
    /** @var PDO */
    private $pdo;

    /** @var mixed ComponentDataService instance. */
    private $dataService;

    /** @var ConstraintStateRepository */
    private $repo;

    public function __construct(PDO $pdo, $dataService)
    {
        $this->pdo         = $pdo;
        $this->dataService = $dataService;
        $this->repo        = new ConstraintStateRepository($pdo, $dataService);
    }

    // ------------------------------------------------------------------ //
    // Feature flags                                                      //
    // ------------------------------------------------------------------ //

    public static function isDualRunReadEnabled(): bool
    {
        return self::boolEnv('COMPATIBILITY_DUALRUN_LOG');
    }

    public static function isDualRunWriteEnabled(): bool
    {
        return self::boolEnv('COMPATIBILITY_DUALRUN_WRITE');
    }

    public static function isReadCutover(): bool
    {
        return strtolower((string)getenv('COMPATIBILITY_READS')) === 'constraint_state';
    }

    public static function isWriteCutover(): bool
    {
        return strtolower((string)getenv('COMPATIBILITY_WRITES')) === 'constraint_state';
    }

    private static function boolEnv(string $name): bool
    {
        $raw = getenv($name);
        if ($raw === false || $raw === '') {
            return false;
        }
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    // ------------------------------------------------------------------ //
    // Candidate evaluation                                               //
    // ------------------------------------------------------------------ //

    /**
     * Evaluate a single candidate against the persisted constraint state.
     * Returns a normalised array shape regardless of failure mode so callers
     * can treat "adapter-failed" identically to "adapter-unknown".
     *
     * @return array{
     *     ok: bool,                 // true if a verdict was produced
     *     allowed: bool,            // the constraint verdict if ok=true
     *     reasons: string[],        // blocking + advisory reasons
     *     error: ?string            // non-null if ok=false
     * }
     */
    public function evaluateCandidate(
        string $configUuid,
        string $componentType,
        string $candidateUuid,
        array $candidateRow = null,
        int $quantity = 1
    ): array {
        try {
            $state = $this->repo->loadOrRebuild($configUuid);
            $spec  = [];
            if ($this->dataService !== null && method_exists($this->dataService, 'getComponentSpecifications')) {
                try {
                    $loaded = $this->dataService->getComponentSpecifications(
                        $componentType,
                        $candidateUuid,
                        $candidateRow
                    );
                    if (is_array($loaded)) {
                        $spec = $loaded;
                    }
                } catch (Throwable $specErr) {
                    // Spec load failure is non-fatal for the adapter: fall through with empty spec.
                }
            }

            $decision = $state->canAddComponent($componentType, $candidateUuid, $spec, [
                'quantity' => $quantity,
            ]);

            $reasons = array_merge(
                $decision->issues ?? [],
                $decision->warnings ?? [],
                $decision->recommendations ?? []
            );
            return [
                'ok'       => true,
                'allowed'  => (bool)$decision->allowed,
                'reasons'  => $reasons,
                'error'    => null,
                'decision' => $decision,
            ];
        } catch (Throwable $e) {
            return [
                'ok'      => false,
                'allowed' => false,
                'reasons' => [],
                'error'   => $e->getMessage(),
            ];
        }
    }

    // ------------------------------------------------------------------ //
    // Dual-run logging                                                   //
    // ------------------------------------------------------------------ //

    /**
     * Persist a single dual-run row. Returns true on success, false on
     * silent failure (e.g. audit table missing). Never throws.
     */
    public function logDualRun(
        string $configUuid,
        string $action,
        string $componentType,
        string $componentUuid,
        bool $legacyVerdict,
        bool $constraintVerdict,
        array $legacyReasons = [],
        array $constraintReasons = []
    ): bool {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO compatibility_dualrun_log
                    (config_uuid, action, component_type, component_uuid,
                     legacy_verdict, constraint_verdict, matched,
                     legacy_reasons, constraint_reasons)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            return $stmt->execute([
                $configUuid,
                $action,
                $componentType,
                $componentUuid,
                $legacyVerdict ? 1 : 0,
                $constraintVerdict ? 1 : 0,
                ($legacyVerdict === $constraintVerdict) ? 1 : 0,
                self::truncate(json_encode(array_values($legacyReasons)), 4000),
                self::truncate(json_encode(array_values($constraintReasons)), 4000),
            ]);
        } catch (Throwable $e) {
            error_log('ConstraintStateCompatibilityAdapter::logDualRun failed: ' . $e->getMessage());
            return false;
        }
    }

    // ------------------------------------------------------------------ //
    // Write-through (shadow + cutover)                                   //
    // ------------------------------------------------------------------ //

    /**
     * Apply a successful legacy add to the constraint state and persist.
     * Called AFTER the legacy path has committed (or inside the same
     * transaction when the caller passes $insideTransaction=true).
     *
     * Never throws — any failure invalidates the blob so the next read
     * rebuilds from line items.
     */
    public function applyAfterLegacy(
        string $configUuid,
        string $componentType,
        string $componentUuid,
        array $componentRow = null,
        int $quantity = 1,
        array $options = []
    ): bool {
        try {
            $state = $this->repo->loadOrRebuild($configUuid);
            $spec  = [];
            if ($this->dataService !== null && method_exists($this->dataService, 'getComponentSpecifications')) {
                try {
                    $loaded = $this->dataService->getComponentSpecifications(
                        $componentType,
                        $componentUuid,
                        $componentRow
                    );
                    if (is_array($loaded)) {
                        $spec = $loaded;
                    }
                } catch (Throwable $specErr) {
                    // Fall through with empty spec.
                }
            }
            $state->applyByType($componentType, $componentUuid, $spec, array_merge($options, [
                'quantity' => $quantity,
            ]));
            return $this->repo->persist($configUuid, $state);
        } catch (Throwable $e) {
            error_log(sprintf(
                'ConstraintStateCompatibilityAdapter::applyAfterLegacy(%s,%s,%s) failed: %s — invalidating blob.',
                $configUuid, $componentType, $componentUuid, $e->getMessage()
            ));
            try { $this->repo->invalidate($configUuid); } catch (Throwable $ignored) {}
            return false;
        }
    }

    /**
     * Mirror a successful legacy removal.
     */
    public function removeAfterLegacy(
        string $configUuid,
        string $componentType,
        string $componentUuid,
        array $options = []
    ): bool {
        try {
            $state = $this->repo->loadOrRebuild($configUuid);
            $state->removeByType($componentType, $componentUuid, [], $options);
            return $this->repo->persist($configUuid, $state);
        } catch (Throwable $e) {
            error_log(sprintf(
                'ConstraintStateCompatibilityAdapter::removeAfterLegacy(%s,%s,%s) failed: %s — invalidating blob.',
                $configUuid, $componentType, $componentUuid, $e->getMessage()
            ));
            try { $this->repo->invalidate($configUuid); } catch (Throwable $ignored) {}
            return false;
        }
    }

    /**
     * Hard-invalidate the persisted blob. Use when you know line-item JSON
     * was updated outside addComponent/removeComponent.
     */
    public function invalidate(string $configUuid): void
    {
        try { $this->repo->invalidate($configUuid); } catch (Throwable $ignored) {}
    }

    // ------------------------------------------------------------------ //

    private static function truncate(?string $s, int $limit): ?string
    {
        if ($s === null) return null;
        return strlen($s) > $limit ? substr($s, 0, $limit) . '…' : $s;
    }
}
