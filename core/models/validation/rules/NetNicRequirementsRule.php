<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';
require_once __DIR__ . '/../../shared/DataExtractionUtilities.php';

/**
 * RULE_MAP.md: net.nic_slot (E) — "folded into pcie.slot_placement; NIC-specific
 * checks from checkPCIeDecentralizedCompatibility not covered by U-R.3
 * (SR-IOV lane note: W)". The slot-placement half is genuinely covered by
 * PcieSlotPlacementRule (U-R.3), which already runs for nic/pciecard/hbacard
 * uniformly. This rule covers the leftover NIC-specific half.
 *
 * HONEST GAP (not fabricated): searched checkPCIeDecentralizedCompatibility
 * and the whole compatibility/ tree for any "SR-IOV" / "sriov" logic this
 * unit's pack referenced — there is none in this codebase today (grep
 * confirmed zero matches). Rather than invent SR-IOV lane-budget logic that
 * has no legacy counterpart to port (which would violate this migration's
 * "port decisions, don't invent" rule — see 04-validation-engine/README.md),
 * this rule implements the one concrete NIC-specific gap that IS real: a
 * non-onboard NIC declaring `ports` in its spec but no discoverable
 * `port_type` cannot ever host an SFP (NetSfpPortRule silently skips
 * type-checking when port_type is absent — see its "spec incomplete" branch)
 * — surfaced here as a WARNING so it isn't silently invisible. Flagged in
 * this session's handoff as needing a human decision on whether real
 * SR-IOV data exists anywhere (a different ims-data field this unit wasn't
 * pointed at) before more logic is added.
 */
final class NetNicRequirementsRule implements RuleInterface
{
    /** @var DataExtractionUtilities */
    private $dataUtils;

    public function __construct(?DataExtractionUtilities $dataUtils = null)
    {
        $this->dataUtils = $dataUtils ?? new DataExtractionUtilities();
    }

    public function id(): string
    {
        return 'net.nic_requirements';
    }

    public function severity(): string
    {
        return Severity::WARNING;
    }

    public function triggers(): array
    {
        return [Trigger::VALIDATE];
    }

    public function scope(): string
    {
        return self::SCOPE_PAIR;
    }

    public function evaluate(TargetState $state): RuleResult
    {
        foreach ($state->byType('nic') as $nic) {
            if (strpos((string)$nic['spec_uuid'], 'onboard-') === 0) {
                continue;
            }
            $spec = $this->dataUtils->getNICByUUID($nic['spec_uuid']);
            if (!is_array($spec)) {
                continue;
            }
            $hasPorts = isset($spec['ports']) && (int)$spec['ports'] > 0;
            $hasPortType = !empty($spec['port_type']);
            if ($hasPorts && !$hasPortType) {
                return new RuleResult($this->id(), $this->severity(), false,
                    "NIC {$nic['id']} declares ports but no port_type -- SFP compatibility cannot be checked",
                    ['nic_id' => $nic['id']]);
            }
        }

        return new RuleResult($this->id(), $this->severity(), true, 'No NIC requirement gaps found');
    }
}
