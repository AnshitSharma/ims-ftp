<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';
require_once __DIR__ . '/../../shared/DataExtractionUtilities.php';
require_once __DIR__ . '/../../shared/DataNormalizationUtils.php';

/**
 * RULE_MAP.md: memory.type (E). Legacy: ComponentValidator::validateMemoryTypeCompatibility
 * (core/models/components/ComponentValidator.php:730), called from
 * ServerBuilder::validateRAMAddition (was lines 4061/4013).
 *
 * Reads RAW specs (DataExtractionUtilities, no PDO) rather than the wrapped
 * 'specifications' shapes ServerBuilder builds — the wrapping formulas are
 * reproduced inline where they diverge from the raw field name (motherboard
 * memory.type is a single string in ims-data; ComponentValidator::parseMotherboardSpecifications
 * wraps it into a 1-element 'types' array, default ['DDR4'] — mirrored below).
 * CPU memory-type constraint reads raw `compatibility.memory_types`
 * (ComponentValidator::validateCPUExists passes raw JSON through verbatim
 * under this same key) — real ims-data fixtures rarely populate it, matching
 * legacy's own "cannot constrain" skip-continue behavior when absent.
 */
final class MemoryTypeRule implements RuleInterface
{
    /** @var DataExtractionUtilities */
    private $dataUtils;

    public function __construct(?DataExtractionUtilities $dataUtils = null)
    {
        $this->dataUtils = $dataUtils ?? new DataExtractionUtilities();
    }

    public function id(): string
    {
        return 'memory.type';
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
        $motherboards = $state->byType('motherboard');
        $cpus = $state->byType('cpu');

        // No motherboard AND no CPU: legacy allows any RAM (validateRAMAddition
        // scenario 1) -- no constraints to check.
        if (empty($motherboards) && empty($cpus)) {
            return new RuleResult($this->id(), $this->severity(), true, 'No motherboard or CPU -- no memory type constraints');
        }

        $mbTypes = ['DDR4'];
        if (!empty($motherboards)) {
            $mbSpec = $this->dataUtils->getMotherboardByUUID($motherboards[0]['spec_uuid']);
            $rawType = is_array($mbSpec) ? ($mbSpec['memory']['type'] ?? null) : null;
            $mbTypes = $rawType !== null ? [$rawType] : ['DDR4'];
        }

        foreach ($state->byType('ram') as $ram) {
            $ramSpec = $this->dataUtils->getRAMByUUID($ram['spec_uuid']);
            $ramType = is_array($ramSpec) ? ($ramSpec['memory_type'] ?? 'DDR4') : 'DDR4';
            $normalizedRamType = DataNormalizationUtils::normalizeMemoryType($ramType);

            if (!empty($motherboards)) {
                $mbCompatible = false;
                foreach ($mbTypes as $mbType) {
                    if (DataNormalizationUtils::normalizeMemoryType($mbType) === $normalizedRamType) {
                        $mbCompatible = true;
                        break;
                    }
                }
                if (!$mbCompatible) {
                    return new RuleResult($this->id(), $this->severity(), false,
                        "Memory type $normalizedRamType incompatible with motherboard supporting " . implode(', ', $mbTypes),
                        ['ram_id' => $ram['id'], 'ram_type' => $normalizedRamType, 'motherboard_types' => $mbTypes]);
                }
            }

            foreach ($cpus as $cpu) {
                $cpuSpec = $this->dataUtils->getCPUByUUID($cpu['spec_uuid']);
                $cpuMemoryTypes = is_array($cpuSpec) ? ($cpuSpec['compatibility']['memory_types'] ?? null) : null;
                if (!is_array($cpuMemoryTypes) || empty($cpuMemoryTypes)) {
                    continue; // this CPU does not declare memory types -- cannot constrain
                }
                $cpuCompatible = false;
                foreach ($cpuMemoryTypes as $cpuType) {
                    if (DataNormalizationUtils::normalizeMemoryType($cpuType) === $normalizedRamType) {
                        $cpuCompatible = true;
                        break;
                    }
                }
                if (!$cpuCompatible) {
                    return new RuleResult($this->id(), $this->severity(), false,
                        "Memory type $normalizedRamType incompatible with CPU supporting " . implode(', ', $cpuMemoryTypes),
                        ['ram_id' => $ram['id'], 'cpu_id' => $cpu['id'], 'ram_type' => $normalizedRamType, 'cpu_types' => $cpuMemoryTypes]);
                }
            }
        }

        return new RuleResult($this->id(), $this->severity(), true, 'All RAM memory types compatible');
    }
}
