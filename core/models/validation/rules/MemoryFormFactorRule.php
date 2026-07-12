<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';
require_once __DIR__ . '/../../shared/DataExtractionUtilities.php';
require_once __DIR__ . '/../../shared/DataNormalizationUtils.php';

/**
 * RULE_MAP.md: memory.form_factor (E). Legacy:
 * ComponentValidator::validateMemoryFormFactor (ComponentValidator.php:824),
 * only reachable when a motherboard is present (ServerBuilder::validateRAMAddition
 * scenario 3). Motherboard form factor is NOT populated by
 * parseMotherboardSpecifications (no `memory.form_factor` wrapper key exists
 * anywhere in that method) so legacy's own default of 'DIMM' is effectively
 * unconditional today -- mirrored here as a hardcoded 'DIMM' comparison
 * target rather than inventing a raw-JSON field legacy never reads either.
 */
final class MemoryFormFactorRule implements RuleInterface
{
    /** @var DataExtractionUtilities */
    private $dataUtils;

    public function __construct(?DataExtractionUtilities $dataUtils = null)
    {
        $this->dataUtils = $dataUtils ?? new DataExtractionUtilities();
    }

    public function id(): string
    {
        return 'memory.form_factor';
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
        if (empty($state->byType('motherboard'))) {
            return new RuleResult($this->id(), $this->severity(), true, 'No motherboard -- form factor check does not apply');
        }

        $motherboardFormFactor = 'DIMM'; // see class docblock

        foreach ($state->byType('ram') as $ram) {
            $ramSpec = $this->dataUtils->getRAMByUUID($ram['spec_uuid']);
            $ramFormFactor = is_array($ramSpec) ? ($ramSpec['form_factor'] ?? 'DIMM (288-pin)') : 'DIMM (288-pin)';
            $normalizedRam = DataNormalizationUtils::normalizeFormFactor($ramFormFactor);

            if ($normalizedRam !== $motherboardFormFactor) {
                return new RuleResult($this->id(), $this->severity(), false,
                    "RAM form factor $normalizedRam incompatible with motherboard form factor $motherboardFormFactor",
                    ['ram_id' => $ram['id'], 'ram_form_factor' => $normalizedRam, 'motherboard_form_factor' => $motherboardFormFactor]);
            }
        }

        return new RuleResult($this->id(), $this->severity(), true, 'All RAM form factors compatible');
    }
}
