<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';
require_once __DIR__ . '/../../shared/DataExtractionUtilities.php';
require_once __DIR__ . '/../../compatibility/CpuGenerationResolver.php';

/**
 * RULE_MAP.md: cpu.generation_match (E). No legacy predecessor -- socket type
 * was the only CPU-to-board gate, and four sockets in the catalog are shared
 * across generations (LGA2011-3 = Xeon E5 v3 + v4, LGA3647 = Skylake-SP +
 * Cascade Lake, SP3 = EPYC 7002 + 7003, AM4 = Zen/Zen 2/Zen 3), so
 * cpu.socket_match passes CPUs that will not POST.
 *
 * Reads the board's optional `cpu_support` block ({generations, series}) and
 * defers every matching decision to CpuGenerationResolver. Boards without the
 * block are unconstrained, so this rule is inert until ims-data is populated --
 * which is also why code and data can deploy in either order.
 *
 * Pairs with, and does not duplicate, its neighbours: cpu.socket_match owns the
 * physical connector, cpu.mixed_models owns CPU-to-CPU pairing, and
 * cpu.requires_board owns the no-board case (which this rule passes on).
 *
 * Platform compute boards need no special case here: getMotherboardByUUID
 * resolves a platform's embedded system_board through PlatformSpecIndex under
 * type `motherboard`, so a platform build is checked by the same code reading
 * the same field paths as a loose spare.
 */
final class CpuGenerationMatchRule implements RuleInterface
{
    /** @var DataExtractionUtilities */
    private $dataUtils;

    public function __construct(?DataExtractionUtilities $dataUtils = null)
    {
        $this->dataUtils = $dataUtils ?? new DataExtractionUtilities();
    }

    public function id(): string
    {
        return 'cpu.generation_match';
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

        $cpus = $state->byType('cpu');
        if (empty($cpus)) {
            return new RuleResult($this->id(), $this->severity(), true, 'No CPU to check');
        }

        $mbSpec = $this->dataUtils->getMotherboardByUUID($motherboards[0]['spec_uuid']);
        if (!is_array($mbSpec)) {
            // Absent board spec is cpu.socket_match's failure to report; this
            // rule has no constraint to apply and stays quiet rather than
            // producing a second blocking result for one root cause.
            return new RuleResult($this->id(), $this->severity(), true, 'Motherboard specification unavailable');
        }

        $support = CpuGenerationResolver::boardSupport($mbSpec);
        if (empty($support)) {
            return new RuleResult($this->id(), $this->severity(), true,
                'Motherboard declares no CPU generation constraint');
        }

        $boardLabel = $mbSpec['model'] ?? 'motherboard';

        foreach ($cpus as $cpu) {
            $cpuSpec = $this->dataUtils->getCPUByUUID($cpu['spec_uuid']);
            if (!is_array($cpuSpec)) {
                return new RuleResult($this->id(), $this->severity(), false,
                    'CPU specifications not found in component database',
                    ['cpu_id' => $cpu['id'], 'cpu_spec_uuid' => $cpu['spec_uuid']]);
            }

            $verdict = CpuGenerationResolver::evaluate($mbSpec, $cpuSpec);
            if (!$verdict['constrained'] || $verdict['supported']) {
                continue;
            }

            return new RuleResult($this->id(), $this->severity(), false,
                $this->explain($boardLabel, $cpuSpec, $verdict),
                [
                    'cpu_id' => $cpu['id'],
                    'cpu_model' => $cpuSpec['model'] ?? null,
                    'cpu_architecture' => $cpuSpec['architecture'] ?? null,
                    'cpu_series' => $cpuSpec['series'] ?? null,
                    'cpu_family' => $cpuSpec['family'] ?? null,
                    'motherboard_model' => $mbSpec['model'] ?? null,
                    'supported_generations' => $verdict['generations'],
                    'supported_series' => $verdict['series'],
                    'failed_axis' => $verdict['failed_axis'],
                    'platform_owned' => !empty($mbSpec['platform_owned']),
                ]);
        }

        return new RuleResult($this->id(), $this->severity(), true,
            'All CPUs are a generation and series the motherboard supports');
    }

    /**
     * Name the CPU, the axis it failed and what the board actually takes --
     * an operator holding the part needs all three to know what to fetch
     * instead.
     */
    private function explain(string $boardLabel, array $cpuSpec, array $verdict): string
    {
        $cpuName = $cpuSpec['model'] ?? 'CPU';

        if ($verdict['failed_axis'] === 'unidentifiable') {
            return "$boardLabel restricts which CPUs it accepts, but $cpuName has no "
                . 'generation or series recorded in the component database';
        }

        if ($verdict['failed_axis'] === 'series') {
            $descriptor = $cpuSpec['family'] ?? ($cpuSpec['series'] ?? $cpuName);
            return "$cpuName ($descriptor) is not a CPU series $boardLabel supports; it supports "
                . $this->join($verdict['series']);
        }

        $descriptor = $cpuSpec['architecture'] ?? ($cpuSpec['series'] ?? $cpuName);
        return "$cpuName ($descriptor) is not a CPU generation $boardLabel supports; it supports "
            . $this->join($verdict['generations']);
    }

    private function join(array $items): string
    {
        if (count($items) <= 1) {
            return (string)($items[0] ?? 'none');
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }
}
