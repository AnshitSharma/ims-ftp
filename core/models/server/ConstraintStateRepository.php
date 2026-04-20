<?php
/**
 * ConstraintStateRepository
 * File: core/models/server/ConstraintStateRepository.php
 *
 * Sole owner of the server_configurations.constraint_state column.
 * Responsible for:
 *   - Hydrating ServerConfigConstraintState from the persisted blob.
 *   - Lazy-rebuilding the blob on first read when it is NULL (existing
 *     configs deployed before the migration).
 *   - Persisting updates inside the caller's transaction.
 *
 * PHASE 0: callable but unused. No production code path constructs this yet.
 */

require_once __DIR__ . '/ServerConfigConstraintState.php';

class ConstraintStateRepository
{
    /** @var PDO */
    private $pdo;

    /** @var mixed ComponentDataService instance (typed loose for PHP 7.4 compat). */
    private $dataService;

    public function __construct(PDO $pdo, $dataService)
    {
        $this->pdo         = $pdo;
        $this->dataService = $dataService;
    }

    /**
     * Return the current constraint state for a config. Builds and persists
     * it from existing line-item columns if constraint_state is NULL.
     *
     * Safe to call from read paths (get-compatible, get-config); the write
     * only happens the first time and is a harmless idempotent UPDATE.
     *
     * @throws RuntimeException if the config does not exist.
     */
    public function loadOrRebuild(string $configUuid): ServerConfigConstraintState
    {
        $row = $this->fetchConfigRow($configUuid);
        if ($row === null) {
            throw new RuntimeException("Server configuration not found: $configUuid");
        }
        if (!empty($row['constraint_state'])) {
            try {
                return ServerConfigConstraintState::hydrate($row['constraint_state']);
            } catch (Throwable $e) {
                error_log("ConstraintStateRepository: hydrate failed for $configUuid ({$e->getMessage()}), rebuilding.");
                // Fall through to rebuild.
            }
        }

        $state = ServerConfigConstraintState::rebuildFromLineItems($row, $this->dataService);
        $this->persist($configUuid, $state);
        return $state;
    }

    /**
     * Load without rebuilding. Returns null if not yet persisted.
     */
    public function loadIfPresent(string $configUuid): ?ServerConfigConstraintState
    {
        $row = $this->fetchConfigRow($configUuid);
        if ($row === null || empty($row['constraint_state'])) {
            return null;
        }
        try {
            return ServerConfigConstraintState::hydrate($row['constraint_state']);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Persist the state. Writes the blob, the schema version, and the
     * timestamp in one UPDATE. Intended to run inside the same transaction
     * that updates the JSON line-item columns (ServerBuilder::addComponent
     * already opens one).
     */
    public function persist(string $configUuid, ServerConfigConstraintState $state): bool
    {
        $sql = "UPDATE server_configurations
                   SET constraint_state = ?,
                       constraint_state_version = ?,
                       constraint_state_updated_at = NOW()
                 WHERE config_uuid = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            $state->serialize(),
            $state->schemaVersion,
            $configUuid,
        ]);
    }

    /**
     * Drop the persisted blob so the next read rebuilds from line items.
     * Call this if you know JSON specs changed out-of-band.
     */
    public function invalidate(string $configUuid): bool
    {
        $sql = "UPDATE server_configurations
                   SET constraint_state = NULL,
                       constraint_state_version = NULL,
                       constraint_state_updated_at = NULL
                 WHERE config_uuid = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$configUuid]);
    }

    // ------------------------------------------------------------------ //
    // Internal                                                           //
    // ------------------------------------------------------------------ //

    private function fetchConfigRow(string $configUuid): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM server_configurations WHERE config_uuid = ?"
        );
        $stmt->execute([$configUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
