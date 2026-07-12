<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';
require_once __DIR__ . '/../../shared/DataExtractionUtilities.php';

/**
 * RULE_MAP.md: storage.caddy_pairing (VF). Legacy:
 * ServerBuilder::getConfigurationWarnings() caddy section (was lines
 * 2041-2113+) -- a READ-TIME warning only ('caddy_shortage'), never blocks.
 * RULE_MAP: "read-time -> VF". Only the SHORTAGE case blocks (caddies <
 * storage of that form factor); a caddy EXCESS is informational in legacy
 * and stays non-blocking here (no RuleResult failure path for it).
 */
final class StorageCaddyPairingRule implements RuleInterface
{
    /** @var DataExtractionUtilities */
    private $dataUtils;

    public function __construct(?DataExtractionUtilities $dataUtils = null)
    {
        $this->dataUtils = $dataUtils ?? new DataExtractionUtilities();
    }

    public function id(): string
    {
        return 'storage.caddy_pairing';
    }

    public function severity(): string
    {
        return Severity::VALIDATION_FAILURE;
    }

    public function triggers(): array
    {
        return [Trigger::ADD, Trigger::VALIDATE];
    }

    public function scope(): string
    {
        return self::SCOPE_PAIR;
    }

    public function evaluate(TargetState $state): RuleResult
    {
        $storageCounts = ['2.5' => 0, '3.5' => 0];
        foreach ($state->byType('storage') as $storage) {
            $spec = $this->dataUtils->getStorageByUUID($storage['spec_uuid']);
            $formFactor = strtolower((string)(is_array($spec) ? ($spec['form_factor'] ?? '') : ''));
            if (strpos($formFactor, '2.5') !== false) {
                $storageCounts['2.5']++;
            } elseif (strpos($formFactor, '3.5') !== false) {
                $storageCounts['3.5']++;
            }
        }

        $caddyCounts = ['2.5' => 0, '3.5' => 0];
        foreach ($state->byType('caddy') as $caddy) {
            $spec = $this->dataUtils->getCaddyByUUID($caddy['spec_uuid']);
            $formFactor = strtolower((string)(is_array($spec)
                ? ($spec['compatibility']['size'] ?? $spec['form_factor'] ?? $spec['type'] ?? '')
                : ''));
            if (strpos($formFactor, '2.5') !== false) {
                $caddyCounts['2.5']++;
            } elseif (strpos($formFactor, '3.5') !== false) {
                $caddyCounts['3.5']++;
            }
        }

        foreach (['2.5', '3.5'] as $size) {
            if ($storageCounts[$size] > 0 && $caddyCounts[$size] < $storageCounts[$size]) {
                $missing = $storageCounts[$size] - $caddyCounts[$size];
                return new RuleResult($this->id(), $this->severity(), false,
                    "Insufficient {$size}\" caddies: {$storageCounts[$size]} {$size}\" storage device(s) require {$storageCounts[$size]} caddies, but only {$caddyCounts[$size]} available",
                    ['size' => $size, 'storage_count' => $storageCounts[$size], 'caddy_count' => $caddyCounts[$size], 'missing' => $missing]);
            }
        }

        return new RuleResult($this->id(), $this->severity(), true, 'Caddy pairing sufficient for all storage');
    }
}
