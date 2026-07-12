<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';
require_once __DIR__ . '/../../shared/DataExtractionUtilities.php';

/**
 * RULE_MAP.md: memory.ecc (W). Legacy: ComponentValidator::validateECCCompatibility
 * (ComponentValidator.php:861). ECC mismatches are warnings in legacy
 * (ServerBuilder::validateRAMAddition reads `$eccCheck['warning']`, never
 * blocks) -- ported at the same severity, not upgraded to E: RULE_MAP lists
 * no intentional diff for this row.
 */
final class MemoryEccRule implements RuleInterface
{
    /** @var DataExtractionUtilities */
    private $dataUtils;

    public function __construct(?DataExtractionUtilities $dataUtils = null)
    {
        $this->dataUtils = $dataUtils ?? new DataExtractionUtilities();
    }

    public function id(): string
    {
        return 'memory.ecc';
    }

    public function severity(): string
    {
        return Severity::WARNING;
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

        $motherboardEcc = false;
        if (!empty($motherboards)) {
            $mbSpec = $this->dataUtils->getMotherboardByUUID($motherboards[0]['spec_uuid']);
            $motherboardEcc = is_array($mbSpec) ? (bool)($mbSpec['memory']['ecc_support'] ?? false) : false;
        }

        $cpuEcc = true;
        foreach ($cpus as $cpu) {
            $cpuSpec = $this->dataUtils->getCPUByUUID($cpu['spec_uuid']);
            if (is_array($cpuSpec) && isset($cpuSpec['compatibility']) && array_key_exists('ecc_support', $cpuSpec['compatibility'])) {
                if (!$cpuSpec['compatibility']['ecc_support']) {
                    $cpuEcc = false;
                    break;
                }
            }
        }

        foreach ($state->byType('ram') as $ram) {
            $ramSpec = $this->dataUtils->getRAMByUUID($ram['spec_uuid']);
            $ramEcc = is_array($ramSpec) ? (bool)($ramSpec['features']['ecc_support'] ?? false) : false;

            if ($ramEcc && !empty($motherboards) && !$motherboardEcc) {
                return new RuleResult($this->id(), $this->severity(), false,
                    'ECC RAM selected but motherboard does not support ECC',
                    ['ram_id' => $ram['id'], 'ram_ecc' => true, 'motherboard_ecc' => false, 'cpu_ecc' => $cpuEcc]);
            }
            if ($ramEcc && !$cpuEcc) {
                return new RuleResult($this->id(), $this->severity(), false,
                    'ECC RAM selected but an installed CPU does not support ECC',
                    ['ram_id' => $ram['id'], 'ram_ecc' => true, 'motherboard_ecc' => $motherboardEcc, 'cpu_ecc' => false]);
            }
            if (!$ramEcc && $motherboardEcc && $cpuEcc && !empty($motherboards)) {
                return new RuleResult($this->id(), $this->severity(), false,
                    'System supports ECC but non-ECC RAM selected - ECC functionality will be disabled',
                    ['ram_id' => $ram['id'], 'ram_ecc' => false, 'motherboard_ecc' => true, 'cpu_ecc' => true]);
            }
        }

        return new RuleResult($this->id(), $this->severity(), true, 'No ECC mismatch');
    }
}
