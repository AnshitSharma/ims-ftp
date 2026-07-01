<?php
/**
 * StorageConnectionAuthority — M11 Phase 3 / Phase 5 standalone class.
 *
 * Makes the add-time storage check as comprehensive as the finalize-time check by
 * calling StorageConnectionValidator::validate() at add-time. Closes the gap where
 * add-time (checkStorageDecentralizedCompatibility) is a lightweight check while
 * finalize (validateStorageConnections) runs the full 5-path connection validator.
 * Extracted from the inline gate in ServerBuilder::validateComponentAddition() in Phase 5.
 *
 * Flag: STORAGE_CONNECTION_AUTHORITY_ENABLED
 *   off     = legacy lightweight add-time check (default; golden-master byte-identical)
 *   shadow  = also run StorageConnectionValidator::validate(), log when results differ
 *   enforce = StorageConnectionValidator::validate() is the authority at add-time
 *
 * NOTE: distinct from STORAGE_BAY_AUTHORITY_ENABLED (bay counts only). Both may log
 * divergence for the same drive if the bay check inside validate() also fires — this
 * is expected overlap, not a bug. Review shadow logs together when enabling either.
 *
 * Phase 5 wiring note:
 *   ServerBuilder::validateComponentAddition() delegates the inline gate to this class.
 *   ValidationPipeline::run() will call evaluate() directly once VALIDATION_PIPELINE_ENABLED
 *   moves to enforce, at which point the inline delegate block in ServerBuilder is removed.
 *
 * @see StorageConnectionValidator — the comprehensive 5-path validator used at enforce
 * @see ServerBuilder::validateComponentAddition() — current call site
 */
class StorageConnectionAuthority
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Mode reader — returns 'off' | 'shadow' | 'enforce'.
     * Reads STORAGE_CONNECTION_AUTHORITY_ENABLED env var; any unrecognised value maps to 'off'.
     */
    public static function mode(): string
    {
        $m = getenv('STORAGE_CONNECTION_AUTHORITY_ENABLED');
        if (!is_string($m) || $m === '') {
            $m = $_ENV['STORAGE_CONNECTION_AUTHORITY_ENABLED'] ?? 'off';
        }
        $m = strtolower(trim((string)$m));
        return in_array($m, ['off', 'shadow', 'enforce'], true) ? $m : 'off';
    }

    /**
     * Evaluate storage connection path at add-time.
     *
     * Reads its own STORAGE_CONNECTION_AUTHORITY_ENABLED flag. In shadow mode logs
     * any divergence from the legacy lightweight check but never overrides it.
     * In enforce mode, StorageConnectionValidator::validate() is the sole authority.
     *
     * @param string     $configUuid    Server configuration UUID
     * @param string     $storageUuid   UUID of the storage component being added
     * @param array      $flatExisting  Flat existing-component array from extractComponentsFromJson
     *                                  (each entry has component_type + component_uuid keys)
     * @param array|null $legacyResult  Current $compatibilityResult from the legacy check
     * @return array|null  Override compatibility result on enforce+no-path; null otherwise
     */
    public function evaluate(
        string $configUuid,
        string $storageUuid,
        array $flatExisting,
        ?array $legacyResult
    ): ?array {
        $mode = self::mode();
        if ($mode === 'off') {
            return null;
        }

        try {
            require_once __DIR__ . '/StorageConnectionValidator.php';
            $validator = new StorageConnectionValidator($this->pdo);

            // Build the keyed-by-type map that StorageConnectionValidator::validate() expects
            $keyed = [
                'chassis'     => null,
                'motherboard' => null,
                'cpu'         => [],
                'ram'         => [],
                'storage'     => [],
                'nic'         => [],
                'pciecard'    => [],
                'hbacard'     => [],
                'caddy'       => [],
            ];
            foreach ($flatExisting as $comp) {
                $type = $comp['component_type'];
                if ($type === 'chassis' || $type === 'motherboard') {
                    $keyed[$type] = $comp;
                } elseif (array_key_exists($type, $keyed)) {
                    $keyed[$type][] = $comp;
                }
            }

            $scResult     = $validator->validate($configUuid, $storageUuid, $keyed);
            $legacyPass   = ($legacyResult === null || (bool)($legacyResult['compatible'] ?? true));
            $authorityPass = (bool)$scResult['valid'];

            if ($mode === 'shadow') {
                if ($legacyPass !== $authorityPass) {
                    error_log(sprintf(
                        'StorageConnectionAuthority shadow divergence (add-time):' .
                        ' config=%s storage=%s legacy.compatible=%s authority.valid=%s errors=%s',
                        $configUuid, $storageUuid,
                        $legacyPass ? 'true' : 'false',
                        $authorityPass ? 'true' : 'false',
                        json_encode(array_column($scResult['errors'] ?? [], 'message'))
                    ));
                }
                return null; // shadow never overrides
            }

            // enforce: StorageConnectionValidator is the authority
            if (!$authorityPass) {
                $errMsgs = array_column($scResult['errors'] ?? [], 'message');
                return [
                    'compatible'           => false,
                    'issues'               => $errMsgs ?: ['Storage has no valid connection path'],
                    'warnings'             => array_column($scResult['warnings'] ?? [], 'message'),
                    'recommendations'      => [],
                    'details'              => ['StorageConnectionAuthority: StorageConnectionValidator reports invalid'],
                    'compatibility_summary' => 'INCOMPATIBLE: No valid storage connection path found',
                ];
            }

            return null; // valid — no override needed

        } catch (\Throwable $e) {
            error_log('StorageConnectionAuthority error (fail-open to legacy): ' . $e->getMessage());
            return null;
        }
    }
}
