<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';
require_once __DIR__ . '/../../shared/DataExtractionUtilities.php';
require_once __DIR__ . '/../../shared/DataNormalizationUtils.php';

/**
 * RULE_MAP.md: cpu.socket_match (E). Legacy:
 * ServerBuilder::validateCPUAddition -> ComponentValidator::validateCPUSocketCompatibility
 * (core/models/components/ComponentValidator.php:265). Ported decision logic
 * (normalize both sides with DataNormalizationUtils::normalizeSocketType,
 * reused verbatim — not duplicated) rather than the plumbing.
 *
 * When no motherboard is present this rule passes (defers to
 * CpuRequiresBoardRule, which owns that case) — RULE_MAP splits these into
 * two rules, so this one only judges socket compatibility, never presence.
 */
final class CpuSocketMatchRule implements RuleInterface
{
    /** @var DataExtractionUtilities */
    private $dataUtils;

    public function __construct(?DataExtractionUtilities $dataUtils = null)
    {
        $this->dataUtils = $dataUtils ?? new DataExtractionUtilities();
    }

    public function id(): string
    {
        return 'cpu.socket_match';
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
            return new RuleResult($this->id(), $this->severity(), true, 'No motherboard to check against');
        }
        $mbSpec = $this->dataUtils->getMotherboardByUUID($motherboards[0]['spec_uuid']);
        $mbSocket = is_array($mbSpec) ? ($mbSpec['socket']['type'] ?? null) : null;

        foreach ($state->byType('cpu') as $cpu) {
            $cpuSpec = $this->dataUtils->getCPUByUUID($cpu['spec_uuid']);
            $cpuSocket = is_array($cpuSpec) ? ($cpuSpec['socket'] ?? null) : null;

            if (!$cpuSocket || !$mbSocket) {
                return new RuleResult($this->id(), $this->severity(), false,
                    'Socket specifications not found in component database',
                    ['cpu_socket' => $cpuSocket, 'motherboard_socket' => $mbSocket]);
            }

            if (DataNormalizationUtils::normalizeSocketType($cpuSocket) !== DataNormalizationUtils::normalizeSocketType($mbSocket)) {
                return new RuleResult($this->id(), $this->severity(), false,
                    "CPU socket $cpuSocket incompatible with motherboard socket $mbSocket",
                    ['cpu_socket' => $cpuSocket, 'motherboard_socket' => $mbSocket, 'cpu_id' => $cpu['id']]);
            }
        }

        return new RuleResult($this->id(), $this->severity(), true, 'All CPU sockets match the motherboard');
    }
}
