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
 * CORRECTED 2026-07-25 (shadow-parity finding, 8 divergent rows): this rule
 * previously implemented "2.5/3.5 strict matching" on the authority of
 * ComponentCompatibility.php:3193-3200's STRICT (no-adapter) comment, and
 * blocked whenever drive count exceeded bay capacity. Both were wrong against
 * the legacy code that actually RUNS:
 *   - ComponentValidator::validateChassisBayStorage():1024-1029 explicitly
 *     ACCEPTS a 2.5" drive in a 3.5" bay (caddy adapter) and records it as a
 *     WARNING, not an issue. 2.5" therefore falls back to drive_bay_3_5.
 *   - Legacy blocks on bay TYPE availability only (":983-987" no bays at all,
 *     ":1032-1035" no bay type supports this form factor). Its count-based
 *     branch (ComponentCompatibility.php:4696-4715) is documented dead code --
 *     $usedBays is never populated -- so legacy never blocks on overflow.
 * Overflow is therefore reported as a passing result carrying details, so the
 * signal survives for the post-cutover tightening pass without diverging from
 * legacy at enforce. 3.5" drives get NO 2.5" fallback (legacy has no such
 * branch). M.2/U.2 storage bypasses bay validation entirely (both spellings
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

        // Eligible bay pools per form factor. A 2.5" drive may occupy a 3.5" bay
        // via a caddy adapter (ComponentValidator.php:1024-1029); a 3.5" drive
        // has no equivalent fallback.
        $eligible = [
            'drive_bay_2_5' => ['drive_bay_2_5', 'drive_bay_3_5'],
            'drive_bay_3_5' => ['drive_bay_3_5'],
        ];

        $overflow = [];
        foreach ($counts as $resource => $count) {
            if ($count === 0) {
                continue;
            }
            $capacity = 0;
            foreach ($eligible[$resource] as $pool) {
                foreach ($state->byResource($pool) as $row) {
                    $capacity += (int)$row['capacity'];
                }
            }
            // Legacy's only blocking condition: no bay of any eligible type exists.
            if ($capacity === 0) {
                return new RuleResult($this->id(), $this->severity(), false,
                    "No chassis bay supports $resource storage",
                    ['resource' => $resource, 'count' => $count, 'capacity' => 0]);
            }
            if ($count > $capacity) {
                $overflow[] = ['resource' => $resource, 'count' => $count, 'capacity' => $capacity];
            }
        }

        if (!empty($overflow)) {
            // REAL, NON-BLOCKING (2026-09-01). Oversubscription used to be reported as
            // a PASSING result, and a passing result never reaches
            // Verdict::failures() -- so the one thing this branch computes was
            // invisible to warnings(), to the add response, and to the operator. It
            // now fails at Severity::WARNING: blocking() ignores WARNING under every
            // trigger, so the legacy-parity posture the 2026-07-25 correction
            // established is unchanged, but the finding is finally reported.
            $parts = [];
            foreach ($overflow as $o) {
                $size = $o['resource'] === 'drive_bay_2_5' ? '2.5"' : '3.5"';
                $parts[] = "{$o['count']} $size drive(s) against {$o['capacity']} eligible bay(s)";
            }
            return new RuleResult($this->id(), Severity::WARNING, false,
                'Chassis drive bays are oversubscribed: ' . implode('; ', $parts),
                [
                    'overflow' => $overflow,
                    'recommendation' => 'Remove drives, or use a chassis with more bays — '
                        . 'more drives are installed than there are bays to seat them in.',
                ]);
        }

        return new RuleResult($this->id(), $this->severity(), true, 'Bay capacity sufficient for all 2.5"/3.5" storage');
    }
}
