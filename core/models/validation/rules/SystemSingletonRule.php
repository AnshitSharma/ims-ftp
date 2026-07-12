<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';

/**
 * RULE_MAP.md: system.singleton (E). Legacy audit A-5/D3: chassis/motherboard
 * singleton was enforced in FOUR places (ServerBuilder::validateSingleComponentConstraints
 * ~6799, an add-time check ~4236-4250, ComponentCompatibility.php:1161/4228-4231) with no
 * single owner. This rule is that one owner (INV-2): a config may have at
 * most one motherboard and at most one chassis.
 *
 * HBA cards are deliberately NOT included: validateSingleComponentConstraints()'s
 * own H8 bugfix comment (read in full for this unit) explicitly says multiple
 * HBA/RAID controllers are allowed — the real constraint is PCIe slot
 * availability (enforced by pcie.slot_placement, U-R.3), not an artificial
 * "one HBA" rule. Porting a since-removed legacy constraint would be a
 * regression, not a port.
 */
final class SystemSingletonRule implements RuleInterface
{
    /** Component types that may appear at most once per configuration. */
    const SINGLETON_TYPES = ['motherboard', 'chassis'];

    public function id(): string
    {
        return 'system.singleton';
    }

    public function severity(): string
    {
        return Severity::ERROR;
    }

    public function triggers(): array
    {
        return [Trigger::ADD, Trigger::REPLACE, Trigger::VALIDATE, Trigger::FINALIZE];
    }

    public function scope(): string
    {
        return self::SCOPE_CONFIG;
    }

    public function evaluate(TargetState $state): RuleResult
    {
        foreach (self::SINGLETON_TYPES as $type) {
            $count = count($state->byType($type));
            if ($count > 1) {
                return new RuleResult($this->id(), $this->severity(), false,
                    "Configuration has $count {$type}s. Only one $type allowed per server.",
                    ['type' => $type, 'count' => $count]);
            }
        }

        return new RuleResult($this->id(), $this->severity(), true, 'No singleton-type violations');
    }
}
