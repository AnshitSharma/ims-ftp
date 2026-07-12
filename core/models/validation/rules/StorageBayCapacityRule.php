<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';
require_once __DIR__ . '/../../shared/DataExtractionUtilities.php';

/**
 * RULE_MAP.md: storage.bay_capacity (E). Legacy:
 * ComponentCompatibility::checkChassisDecentralizedCompatibility() bay
 * section (was lines 3226-3243, via calculateRequiredBays() +
 * ComponentValidator::validateChassisBayCapacity()) + ServerBuilder
 * (was lines 7833-7873, a separate per-call count check on the same
 * concept). Unified into one row-count check against
 * ResourceCatalog::chassisDriveBayRows() (added this unit).
 *
 * "2.5/3.5 strict matching preserved exactly" (pack checklist): a 2.5"
 * drive can ONLY occupy a drive_bay_2_5 resource row, never drive_bay_3_5,
 * matching ComponentCompatibility.php:3193-3200's STRICT (no-adapter)
 * comment. M.2/U.2 storage bypasses bay validation entirely (both spellings
 * of "2.5"/"3.5" are excluded by definition since neither substring matches).
 */
final class StorageBayCapacityRule implements RuleInterface
{
    /** @var DataExtractionUtilities */
    private $dataUtils;

    public function __construct(?DataExtractionUtilities $dataUtils = null)
    {
        $this->dataUtils = $dataUtils ?? new DataExtractionUtilities();
    }

    public function id(): string
    {
        return 'storage.bay_capacity';
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
        return self::SCOPE_RESOURCE;
    }

    public function evaluate(TargetState $state): RuleResult
    {
        if (empty($state->byType('chassis'))) {
            return new RuleResult($this->id(), $this->severity(), true, 'No chassis -- bay check does not apply');
        }

        $counts = ['drive_bay_2_5' => 0, 'drive_bay_3_5' => 0];
        foreach ($state->byType('storage') as $storage) {
            $spec = $this->dataUtils->getStorageByUUID($storage['spec_uuid']);
            $formFactor = strtolower((string)(is_array($spec) ? ($spec['form_factor'] ?? '') : ''));
            if (strpos($formFactor, '2.5') !== false) {
                $counts['drive_bay_2_5']++;
            } elseif (strpos($formFactor, '3.5') !== false) {
                $counts['drive_bay_3_5']++;
            }
            // M.2/U.2/unknown: bypasses bay validation (matches legacy exactly).
        }

        foreach ($counts as $resource => $count) {
            if ($count === 0) {
                continue;
            }
            $capacity = 0;
            foreach ($state->byResource($resource) as $row) {
                $capacity += (int)$row['capacity'];
            }
            if ($count > $capacity) {
                return new RuleResult($this->id(), $this->severity(), false,
                    "Insufficient $resource bays: $count drive(s) need accommodation, chassis has $capacity",
                    ['resource' => $resource, 'count' => $count, 'capacity' => $capacity]);
            }
        }

        return new RuleResult($this->id(), $this->severity(), true, 'Bay capacity sufficient for all 2.5"/3.5" storage');
    }
}
