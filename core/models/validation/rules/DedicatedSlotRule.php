<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';
require_once __DIR__ . '/../SlotPlanner.php';
require_once __DIR__ . '/../../shared/DataExtractionUtilities.php';

/**
 * card.dedicated_slot (E), added 2026-10-07 with tasks/dedicated-slot-cards.md.
 *
 * Cards whose spec names a `dedicated_slot` (rNDC, bNDC, FlexibleLOM, OCP 3.0, PERC
 * Mini, front PERC, HPE 'ar' daughter card) no longer take a pcie_slot -- they sit in
 * their own connector. This rule is what keeps that from meaning "unlimited": a
 * server has ONE connector of each kind, so a second rNDC in an R630 is refused.
 *
 * Capacity comes from the motherboard spec's `dedicated_slots` map ({"rndc": 1,
 * "mini_perc": 1}); a kind the map leaves out is 0 (QA-04). A board with no map at all
 * still gets 1 per kind, the pre-QA-04 default, until every board declares one. Platform-owned
 * rows count -- a platform's front PERC occupies the front PERC connector exactly as
 * a loose one would. Onboard NIC rows carry no spec field and are not counted.
 *
 * Standard PCIe cards carry no `dedicated_slot` and are untouched here; their slot
 * capacity stays pcie.slot_placement's job.
 */
final class DedicatedSlotRule implements RuleInterface
{
    /** Human names for the connector kinds, used only in messages. */
    const KIND_LABELS = [
        'rndc' => 'rNDC (rack network daughter card)',
        'bndc' => 'bNDC (blade network daughter card)',
        'flexlom' => 'FlexibleLOM',
        'ocp3' => 'OCP 3.0',
        'embedded_lom' => 'embedded LOM',
        'mini_perc' => 'PERC Mini (mini-monolithic)',
        'front_perc' => 'front PERC',
        'ar_slot' => "HPE 'ar' controller",
    ];

    /** @var DataExtractionUtilities */
    private $dataUtils;

    public function __construct(?DataExtractionUtilities $dataUtils = null)
    {
        $this->dataUtils = $dataUtils ?? new DataExtractionUtilities();
    }

    public function id(): string
    {
        return 'card.dedicated_slot';
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
        $occupants = [];
        foreach (['nic', 'hbacard'] as $type) {
            foreach ($state->byType($type) as $component) {
                $specUuid = (string)$component['spec_uuid'];
                if ($type === 'nic' && strpos($specUuid, 'onboard-') === 0) {
                    continue;
                }
                $spec = $type === 'nic'
                    ? $this->dataUtils->getNICByUUID($specUuid)
                    : $this->dataUtils->getHBACardByUUID($specUuid);
                if (!is_array($spec)) {
                    continue; // UUID validity is enforced elsewhere
                }
                $kind = SlotPlanner::dedicatedSlotKind($spec);
                if ($kind !== null) {
                    $occupants[$kind][] = $spec['model'] ?? $specUuid;
                }
            }
        }

        if (empty($occupants)) {
            return new RuleResult($this->id(), $this->severity(), true, 'No dedicated-connector cards');
        }

        // QA-04 (2026-10-10): a board that declares its connectors has exactly those,
        // and a kind it does not list is a connector it does not have -- an HPE
        // FlexibleLOM card was accepted in a Dell R630 because a silent board was
        // assumed to have one of every kind. A board with no map at all keeps that
        // one-per-kind assumption only until every board in ims-data declares one.
        $declared = $this->declaredCapacities($state);
        foreach ($occupants as $kind => $models) {
            $capacity = $declared === null ? 1 : ($declared[$kind] ?? 0);
            if (count($models) > $capacity) {
                $label = self::KIND_LABELS[$kind] ?? $kind;
                $message = $capacity === 0
                    ? sprintf('This server has no %s connector, but %s %s one: %s', $label,
                        count($models) === 1 ? 'a card' : count($models) . ' cards',
                        count($models) === 1 ? 'needs' : 'need', implode(', ', $models))
                    : sprintf('This server has %d %s connector%s, but %d cards need one: %s',
                        $capacity, $label, $capacity === 1 ? '' : 's', count($models), implode(', ', $models));
                return new RuleResult($this->id(), $this->severity(), false, $message,
                    ['kind' => $kind, 'capacity' => $capacity, 'count' => count($models), 'models' => $models]);
            }
        }

        return new RuleResult($this->id(), $this->severity(), true,
            'Every dedicated-connector card has its own connector');
    }

    /**
     * @return array<string,int>|null the board's dedicated_slots map, keys lowercased;
     *         null when there is no board or the board declares no map at all
     */
    private function declaredCapacities(TargetState $state): ?array
    {
        $boards = $state->byType('motherboard');
        if (empty($boards)) {
            return null;
        }
        $boardSpec = $this->dataUtils->getMotherboardByUUID($boards[0]['spec_uuid']);
        $map = is_array($boardSpec) ? ($boardSpec['dedicated_slots'] ?? null) : null;
        if (!is_array($map)) {
            return null;
        }
        $out = [];
        foreach ($map as $kind => $count) {
            if (is_string($kind) && is_numeric($count)) {
                $out[strtolower(trim($kind))] = max(0, (int)$count);
            }
        }
        return $out;
    }
}
