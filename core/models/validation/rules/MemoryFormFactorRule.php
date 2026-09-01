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
 * scenario 3).
 *
 * MADE REAL 2026-09-01. This rule previously compared every module against a
 * HARDCODED 'DIMM' and read no motherboard field at all -- mirroring legacy's
 * unconditional default. All 33 RAM entries in ims-data/ram/ram_detail.json
 * normalize to DIMM, so the comparison could not fail: a validation in name only,
 * reported to the operator as if a real memory/board pairing check had run.
 *
 * The catalog does express the constraint on BOTH sides, so the rule now reads it:
 *   board  memory.module_types  ["RDIMM", "LRDIMM"]   (all 23 catalog boards and
 *                                                      all 13 platform boards)
 *   module module_type          "RDIMM"                (all 33 RAM entries)
 *
 * Two findings, two severities, deliberately:
 *   - FORM FACTOR (DIMM vs SO-DIMM), derived from the board's module types, stays
 *     an ERROR. A SO-DIMM physically will not enter a DIMM slot; that is not
 *     advisory.
 *   - MODULE TYPE (a UDIMM in a board that only accepts RDIMM/LRDIMM) is reported
 *     at Severity::WARNING. It is a genuine electrical incompatibility, but this
 *     rule has never once fired in production, so promoting it straight to a hard
 *     block would newly refuse builds that are shipping today. Warning first is
 *     the same posture taken for the three storage rules in this pass.
 *
 * A board that declares no module_types at all cannot constrain either check, and
 * says so, rather than falling back to a guess.
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
        $motherboards = $state->byType('motherboard');
        if (empty($motherboards)) {
            return new RuleResult($this->id(), $this->severity(), true, 'No motherboard -- form factor check does not apply');
        }

        $boardUuid = $motherboards[0]['spec_uuid'];
        $boardSpec = $this->dataUtils->getMotherboardByUUID($boardUuid);
        $moduleTypes = is_array($boardSpec) ? ($boardSpec['memory']['module_types'] ?? null) : null;
        if (!is_array($moduleTypes) || empty($moduleTypes)) {
            // Honest skip: the board declares no accepted module types, so neither
            // check below has anything to compare against. Stated plainly rather
            // than dressed up as a pass of a check that did not run.
            return new RuleResult($this->id(), $this->severity(), true,
                'Motherboard declares no accepted memory module types -- form factor cannot be constrained',
                ['motherboard_uuid' => $boardUuid]);
        }

        $acceptedModuleTypes = [];
        $acceptedFormFactors = [];
        foreach ($moduleTypes as $moduleType) {
            $normalized = strtoupper(trim((string)$moduleType));
            if ($normalized === '') {
                continue;
            }
            $acceptedModuleTypes[$normalized] = true;
            $acceptedFormFactors[DataNormalizationUtils::normalizeFormFactor($normalized)] = true;
        }

        $moduleTypeMismatch = null;

        foreach ($state->byType('ram') as $ram) {
            $ramSpec = $this->dataUtils->getRAMByUUID($ram['spec_uuid']);
            if (!is_array($ramSpec)) {
                continue; // unreadable module spec: UUID validity is enforced elsewhere
            }

            $ramFormFactor = DataNormalizationUtils::normalizeFormFactor(
                (string)($ramSpec['form_factor'] ?? '')
            );
            if ($ramFormFactor !== '' && !isset($acceptedFormFactors[$ramFormFactor])) {
                // Physically will not seat. Blocks, as this rule's declared severity says.
                return new RuleResult($this->id(), $this->severity(), false,
                    "RAM form factor $ramFormFactor does not fit this motherboard, which takes "
                    . implode('/', array_keys($acceptedFormFactors)),
                    [
                        'ram_id' => $ram['id'],
                        'ram_form_factor' => $ramFormFactor,
                        'motherboard_form_factors' => array_keys($acceptedFormFactors),
                        'motherboard_uuid' => $boardUuid,
                    ]);
            }

            // Keep looking for a hard form-factor mismatch before reporting a soft one.
            $ramModuleType = strtoupper(trim((string)($ramSpec['module_type'] ?? '')));
            if ($moduleTypeMismatch === null
                && $ramModuleType !== ''
                && !isset($acceptedModuleTypes[$ramModuleType])
            ) {
                $moduleTypeMismatch = [
                    'ram_id' => $ram['id'],
                    'ram_module_type' => $ramModuleType,
                    'motherboard_module_types' => array_keys($acceptedModuleTypes),
                    'motherboard_uuid' => $boardUuid,
                    'recommendation' => 'Use a ' . implode('/', array_keys($acceptedModuleTypes))
                        . ' module — this board does not accept ' . $ramModuleType . '.',
                ];
            }
        }

        if ($moduleTypeMismatch !== null) {
            // Real, advisory (see class docblock).
            return new RuleResult($this->id(), Severity::WARNING, false,
                "Memory module type {$moduleTypeMismatch['ram_module_type']} is not listed by this motherboard, which accepts "
                . implode('/', $moduleTypeMismatch['motherboard_module_types']),
                $moduleTypeMismatch);
        }

        return new RuleResult($this->id(), $this->severity(), true, 'All RAM form factors compatible');
    }
}
