<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';
require_once __DIR__ . '/../../shared/DataExtractionUtilities.php';

/**
 * RULE_MAP.md: storage.interface_path (E). Legacy:
 * StorageConnectionValidator::validate() (2009 lines total; only its first
 * ~200-line entry contract was read for this unit per the pack — "port
 * decisions, discard plumbing") + StorageConnectionAuthority.php (full,
 * 139 lines) + ComponentCompatibility chassis-bay decisions (was lines
 * 3076-3260).
 *
 * DELIBERATE SIMPLIFICATION (documented, not hidden): legacy's real path
 * search covers chassis bay / motherboard direct / HBA / PCIe adapter, and
 * only hard-BLOCKS when a SAS drive has neither an HBA nor a chassis with a
 * SAS backplane (`validate()`'s "SAS storage requires SAS HBA card OR
 * chassis with SAS backplane" branch, was lines 169-191) -- every other
 * protocol with no path is a WARNING only ("no_connection_path_yet"),
 * component-order-flexible by design, never a block. This rule ports
 * EXACTLY that blocking condition: SAS with no HBA present blocks; every
 * other case passes (bay/M.2/caddy capacity are separately enforced by
 * StorageBayCapacityRule/StorageM2CapacityRule/StorageCaddyPairingRule, and
 * the "does the chassis backplane itself support SAS" sub-check is NOT
 * ported -- ims-data chassis specs were not read for a SAS-backplane field
 * path within this unit's scope, so a chassis-SAS-backplane path this rule
 * cannot see is a KNOWN GAP where this rule may block something legacy
 * would allow; flagged in this session's handoff for human review, not
 * silently accepted as parity).
 */
final class StorageInterfacePathRule implements RuleInterface
{
    /** @var DataExtractionUtilities */
    private $dataUtils;

    public function __construct(?DataExtractionUtilities $dataUtils = null)
    {
        $this->dataUtils = $dataUtils ?? new DataExtractionUtilities();
    }

    public function id(): string
    {
        return 'storage.interface_path';
    }

    public function severity(): string
    {
        return Severity::ERROR;
    }

    public function triggers(): array
    {
        return [Trigger::ADD, Trigger::REPLACE, Trigger::VALIDATE];
    }

    public function scope(): string
    {
        return self::SCOPE_PAIR;
    }

    public function evaluate(TargetState $state): RuleResult
    {
        $hasHba = !empty($state->byType('hbacard'));

        foreach ($state->byType('storage') as $storage) {
            $spec = $this->dataUtils->getStorageByUUID($storage['spec_uuid']);
            if (!is_array($spec)) {
                continue;
            }
            $interface = strtolower((string)($spec['interface'] ?? ''));
            $isSas = strpos($interface, 'sas') !== false;

            if ($isSas && !$hasHba) {
                return new RuleResult($this->id(), $this->severity(), false,
                    'SAS storage requires SAS HBA card OR chassis with SAS backplane',
                    ['storage_id' => $storage['id'], 'interface' => $interface]);
            }
        }

        return new RuleResult($this->id(), $this->severity(), true, 'All storage has a viable connection path');
    }
}
