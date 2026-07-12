<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';
require_once __DIR__ . '/../../shared/DataExtractionUtilities.php';
require_once __DIR__ . '/../../compatibility/NICPortTracker.php';

/**
 * RULE_MAP.md: net.sfp_port (E). Legacy: ServerBuilder::validateSFPAddition
 * (was lines 4460-4569) + NICPortTracker::isCompatible() (port-type matching
 * table, core/models/compatibility/NICPortTracker.php:206-256).
 *
 * Calls NICPortTracker::isCompatible() directly (a static, PDO-free method)
 * rather than re-porting its matching table into a local const: the table
 * already had one drift bug fixed in it (H5: SFP+/SFP28 cages missing
 * backward-compat with 1G SFP) and duplicating it here would reopen exactly
 * that risk class. Requiring the file does not construct NICPortTracker (its
 * constructor takes PDO; isCompatible() is called statically), so this rule
 * stays PDO-free.
 *
 * Legacy DISTINGUISHES two different "no parent" situations (RULE_MAP lists
 * no intentional diff here — this rule preserves that distinction exactly):
 *   - no parent_id at all -> legacy's TP-4A staged/unassigned workflow,
 *     explicitly ALLOWED (an SFP added before its NIC, auto-mapped later).
 *   - a parent_id reference that does not resolve to a NIC actually present
 *     in the state -> legacy hard-blocks ("Parent NIC ... not found in
 *     configuration"), ported here as the actual blocking case.
 */
final class NetSfpPortRule implements RuleInterface
{
    /** @var DataExtractionUtilities */
    private $dataUtils;

    public function __construct(?DataExtractionUtilities $dataUtils = null)
    {
        $this->dataUtils = $dataUtils ?? new DataExtractionUtilities();
    }

    public function id(): string
    {
        return 'net.sfp_port';
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
        $portsSeenPerParent = [];

        foreach ($state->byType('sfp') as $sfp) {
            if ($sfp['parent_id'] === null) {
                continue; // staged/unassigned -- allowed (TP-4A)
            }

            $parentNic = $state->find($sfp['parent_id']);
            if ($parentNic === null || $parentNic['component_type'] !== 'nic') {
                return new RuleResult($this->id(), $this->severity(), false,
                    "Parent NIC not found in configuration for SFP {$sfp['id']}",
                    ['sfp_id' => $sfp['id']]);
            }

            if ($sfp['slot_ref'] !== null) {
                $key = $parentNic['id'] . '|' . $sfp['slot_ref'];
                if (isset($portsSeenPerParent[$key])) {
                    return new RuleResult($this->id(), $this->severity(), false,
                        "Port {$sfp['slot_ref']} on NIC {$parentNic['id']} is already occupied",
                        ['sfp_id' => $sfp['id'], 'nic_id' => $parentNic['id'], 'port' => $sfp['slot_ref']]);
                }
                $portsSeenPerParent[$key] = true;
            }

            $nicSpec = $this->dataUtils->getNICByUUID($parentNic['spec_uuid']);
            $sfpSpec = $this->dataUtils->getSFPByUUID($sfp['spec_uuid']);

            $nicPortType = is_array($nicSpec) ? ($nicSpec['port_type'] ?? null) : null;
            $sfpType = is_array($sfpSpec) ? ($sfpSpec['type'] ?? null) : null;

            if ($nicPortType === null || $sfpType === null) {
                continue; // spec incomplete -- not this rule's concern, matches legacy's fail-open-to-error-elsewhere posture
            }

            if (!NICPortTracker::isCompatible($nicPortType, $sfpType)) {
                return new RuleResult($this->id(), $this->severity(), false,
                    "SFP module type '$sfpType' is incompatible with NIC port type '$nicPortType'",
                    ['sfp_id' => $sfp['id'], 'nic_id' => $parentNic['id'], 'sfp_type' => $sfpType, 'nic_port_type' => $nicPortType]);
            }
        }

        return new RuleResult($this->id(), $this->severity(), true, 'All SFP ports valid');
    }
}
