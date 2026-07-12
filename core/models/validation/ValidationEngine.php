<?php

require_once __DIR__ . '/RuleInterface.php';
require_once __DIR__ . '/RuleResult.php';
require_once __DIR__ . '/Verdict.php';
require_once __DIR__ . '/Severity.php';
require_once __DIR__ . '/rules/CpuSocketMatchRule.php';
require_once __DIR__ . '/rules/CpuSocketCountRule.php';
require_once __DIR__ . '/rules/CpuMixedModelsRule.php';
require_once __DIR__ . '/rules/CpuRequiresBoardRule.php';
require_once __DIR__ . '/rules/MemoryTypeRule.php';
require_once __DIR__ . '/rules/MemoryFormFactorRule.php';
require_once __DIR__ . '/rules/MemorySlotCountRule.php';
require_once __DIR__ . '/rules/MemoryEccRule.php';
require_once __DIR__ . '/rules/MemoryDownclockRule.php';
require_once __DIR__ . '/rules/PcieSlotPlacementRule.php';
require_once __DIR__ . '/rules/PcieLaneBudgetRule.php';
require_once __DIR__ . '/rules/StorageInterfacePathRule.php';
require_once __DIR__ . '/rules/StorageBayCapacityRule.php';
require_once __DIR__ . '/rules/StorageM2CapacityRule.php';
require_once __DIR__ . '/rules/StorageCaddyPairingRule.php';
require_once __DIR__ . '/rules/NetSfpPortRule.php';
require_once __DIR__ . '/rules/NetNicRequirementsRule.php';
require_once __DIR__ . '/rules/SystemRequiredSetRule.php';
require_once __DIR__ . '/rules/SystemSingletonRule.php';
require_once __DIR__ . '/rules/SystemPsuCapacityRule.php';
require_once __DIR__ . '/rules/SystemInventoryStateRule.php';
require_once __DIR__ . '/rules/DependencyBlockedRemovalRule.php';

/**
 * Single evaluate() path over the new Rule vocabulary. Registry starts
 * empty (U-V.1..U-V.4 ship no rules); U-R.* units append to RULES as each
 * family is ported.
 *
 * Rollout mode follows FLAGS.md's ENGINE_MODE / PcieLaneBudgetValidator::currentMode()
 * pattern exactly: getenv -> $_ENV fallback -> default 'off' -> whitelist.
 */
/**
 * Not final (unlike the U-V.1 value objects): tests extend this to swap
 * RULES via a subclass, since RULES is a class const (the pack's own
 * design: "registry: const RULES = [class-strings]").
 */
class ValidationEngine
{
    /** @var string[] fully-qualified class-strings implementing RuleInterface */
    const RULES = [
        // U-R.1 cpu.* (migration/04-validation-engine/RULE_MAP.md)
        CpuSocketMatchRule::class,
        CpuSocketCountRule::class,
        CpuMixedModelsRule::class,
        CpuRequiresBoardRule::class,
        // U-R.2 memory.* (migration/04-validation-engine/RULE_MAP.md)
        MemoryTypeRule::class,
        MemoryFormFactorRule::class,
        MemorySlotCountRule::class,
        MemoryEccRule::class,
        MemoryDownclockRule::class,
        // U-R.3 pcie.slot_placement (migration/04-validation-engine/RULE_MAP.md)
        PcieSlotPlacementRule::class,
        // U-R.4 pcie.lane_budget (migration/04-validation-engine/RULE_MAP.md)
        PcieLaneBudgetRule::class,
        // U-R.5 storage.* (migration/04-validation-engine/RULE_MAP.md)
        StorageInterfacePathRule::class,
        StorageBayCapacityRule::class,
        StorageM2CapacityRule::class,
        StorageCaddyPairingRule::class,
        // U-R.6 net.* (migration/04-validation-engine/RULE_MAP.md)
        NetSfpPortRule::class,
        NetNicRequirementsRule::class,
        // U-R.7 system.* (migration/04-validation-engine/RULE_MAP.md)
        SystemRequiredSetRule::class,
        SystemSingletonRule::class,
        SystemPsuCapacityRule::class,
        SystemInventoryStateRule::class,
        // U-R.8 dependency.blocked_removal (migration/04-validation-engine/RULE_MAP.md)
        DependencyBlockedRemovalRule::class,
    ];

    /**
     * @return string one of "off", "shadow", "enforce"
     */
    public static function mode(): string
    {
        $mode = getenv('ENGINE_MODE');
        if (!is_string($mode) || $mode === '') {
            $mode = $_ENV['ENGINE_MODE'] ?? 'off';
        }
        $mode = strtolower(trim((string)$mode));
        if (!in_array($mode, ['off', 'shadow', 'enforce'], true)) {
            return 'off';
        }
        return $mode;
    }

    /**
     * Evaluate every registered rule whose triggers() include $trigger against
     * $state. A rule that throws is NEVER swallowed (INV-5, fail-closed): its
     * exception is synthesized into a failed ERROR RuleResult
     * 'engine.rule_exception' so one broken rule cannot silently pass a
     * config through.
     */
    public function evaluate(TargetState $state, string $trigger): Verdict
    {
        $results = [];
        foreach (static::RULES as $ruleClass) {
            /** @var RuleInterface $rule */
            $rule = new $ruleClass();
            if (!in_array($trigger, $rule->triggers(), true)) {
                continue;
            }
            try {
                $results[] = $rule->evaluate($state);
            } catch (\Throwable $e) {
                error_log("ValidationEngine: rule {$rule->id()} threw during evaluate(): " . $e->getMessage());
                $results[] = new RuleResult(
                    'engine.rule_exception',
                    Severity::ERROR,
                    false,
                    "Rule {$rule->id()} raised an exception: " . $e->getMessage(),
                    ['rule_id' => $rule->id(), 'exception_class' => get_class($e)]
                );
            }
        }
        return new Verdict($results, $trigger);
    }
}
