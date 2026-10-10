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
 * Three findings, two severities, deliberately:
 *   - FORM FACTOR (DIMM vs SO-DIMM), derived from the board's module types, stays
 *     an ERROR. A SO-DIMM physically will not enter a DIMM slot; that is not
 *     advisory.
 *   - BUFFERING CLASS (2026-09-29, audit §2.6 / HC-02): registered (RDIMM, LRDIMM,
 *     3DS, NVDIMM) vs unbuffered (UDIMM) is an ERROR. The two do not run in each
 *     other's boards, and a WARNING here let get-compatible offer every server
 *     RDIMM/LRDIMM in stock (~117 units) for the AM4 desktop board. Probed across
 *     all 74 live configs before promoting: none carries this mismatch, so nothing
 *     already built becomes frozen (an ADD blocks on ANY failed ERROR in the whole
 *     config, not only the new part's).
 *   - MODULE TYPE within one class (an LRDIMM in an RDIMM-only board) stays a
 *     WARNING. Support there is the vendor's list, not electrical, and Y682 16U
 *     (R750xs) runs LRDIMMs today pending an iDRAC check (audit §5); an ERROR
 *     would block every later add on that server. A type neither class
 *     recognises also stays a WARNING rather than being guessed at.
 *   - MIXING within the registered class (QA-01, 2026-10-10): RDIMMs and LRDIMMs
 *     in one build. Each module alone is fine, so the per-module checks above
 *     never saw it, but the set will not run together (Dell R630 memory rules,
 *     and the same rule on every RDIMM/LRDIMM platform). Judged on the whole set.
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
        $acceptedClasses = [];
        foreach ($moduleTypes as $moduleType) {
            $normalized = strtoupper(trim((string)$moduleType));
            if ($normalized === '') {
                continue;
            }
            $acceptedModuleTypes[$normalized] = true;
            $acceptedFormFactors[DataNormalizationUtils::normalizeFormFactor($normalized)] = true;
            $class = self::bufferClass($normalized);
            if ($class !== null) {
                $acceptedClasses[$class] = true;
            }
        }

        $moduleTypeMismatch = null;
        $registeredTypes = []; // module type => one ram row id carrying it

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

            $ramModuleType = strtoupper(trim((string)($ramSpec['module_type'] ?? '')));
            $ramClass = $ramModuleType === '' ? null : self::bufferClass($ramModuleType);
            if ($ramClass !== null && !empty($acceptedClasses) && !isset($acceptedClasses[$ramClass])) {
                // Registered vs unbuffered: will not run. Blocks (see class docblock).
                return new RuleResult($this->id(), $this->severity(), false,
                    "Memory module type $ramModuleType ($ramClass) will not run in this motherboard, which takes "
                    . implode('/', array_keys($acceptedModuleTypes)),
                    [
                        'ram_id' => $ram['id'],
                        'ram_module_type' => $ramModuleType,
                        'motherboard_module_types' => array_keys($acceptedModuleTypes),
                        'motherboard_uuid' => $boardUuid,
                        'recommendation' => 'Use a ' . implode('/', array_keys($acceptedModuleTypes)) . ' module.',
                    ]);
            }

            if ($ramClass === 'registered') {
                $registeredTypes[$ramModuleType] = $registeredTypes[$ramModuleType] ?? $ram['id'];
            }

            // Keep looking for a hard mismatch before reporting a soft one.
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

        if (count($registeredTypes) > 1) {
            // Mixed registered types (see class docblock). An ERROR: swept across all
            // 80 live builds on 2026-10-10 first, none carries the mix, so none freezes.
            $types = array_keys($registeredTypes);
            return new RuleResult($this->id(), $this->severity(), false,
                'Memory module types cannot be mixed in one server: ' . implode(' and ', $types) . ' installed together',
                [
                    'ram_module_types' => $types,
                    'ram_ids' => array_values($registeredTypes),
                    'motherboard_uuid' => $boardUuid,
                    'recommendation' => 'Use one module type throughout — all ' . $types[0] . ' or all ' . $types[1] . '.',
                ]);
        }

        if ($moduleTypeMismatch !== null) {
            // Same buffering class, not on the board's list: advisory (see class docblock).
            return new RuleResult($this->id(), Severity::WARNING, false,
                "Memory module type {$moduleTypeMismatch['ram_module_type']} is not listed by this motherboard, which accepts "
                . implode('/', $moduleTypeMismatch['motherboard_module_types']),
                $moduleTypeMismatch);
        }

        return new RuleResult($this->id(), $this->severity(), true, 'All RAM form factors compatible');
    }

    /**
     * 'registered', 'unbuffered', or null when the type names neither. UDIMM is
     * tested first; LRDIMM contains "RDIMM", UDIMM does not.
     */
    private static function bufferClass(string $moduleType): ?string
    {
        if (preg_match('/UDIMM|UNBUFFERED/', $moduleType)) {
            return 'unbuffered';
        }
        if (preg_match('/RDIMM|NVDIMM|REGISTERED|3DS/', $moduleType)) {
            return 'registered';
        }
        return null;
    }
}
