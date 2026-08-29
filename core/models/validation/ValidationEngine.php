<?php

require_once __DIR__ . '/RuleInterface.php';
require_once __DIR__ . '/RuleResult.php';
require_once __DIR__ . '/Verdict.php';
require_once __DIR__ . '/Severity.php';
// Referenced directly by rulesFor()'s FINALIZE-subsumes-VALIDATE check (F-26),
// not just transitively through the rule files.
require_once __DIR__ . '/Trigger.php';
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
 * U-D.4 removed ENGINE_MODE: this registry is the sole validation authority and
 * has no rollout mode left to consult.
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

    /*
     * U-D.4: the ENGINE_MODE reader lived here. The registry below is the sole
     * validation authority now -- there is no 'off' to fall back to.
     */

    /**
     * F-26 (2026-07-29): FINALIZE SUBSUMES VALIDATE.
     *
     * Only 4 of the 22 registered rules declare Trigger::FINALIZE. Legacy's
     * finalize gate is validateConfigurationComprehensive() — the WHOLE
     * compatibility suite — and COMMAND_LAYER_ENABLED=enforce deletes that gate
     * in TransitionStatusCommand's favour (server_api.php's U-C.5 block). Under
     * strict trigger membership that swap silently dropped 18 rules from
     * finalize.
     *
     * Caught the first time a complete build was finalized under the new
     * finalize shadow hook: 2026-07-28T20:03:44Z, config a3177ce9 — legacy
     * blocked on missing_caddy + ram_type_incompatible, the command layer
     * allowed it with ZERO failures. Not a rule bug; a registry-coverage hole.
     *
     * Adding FINALIZE to 18 rule files would say the same thing 18 times and
     * invite the next rule to forget it. The relationship is structural instead:
     * VALIDATE means "assess this whole configuration", FINALIZE means "assess
     * it and then commit" — so anything that runs at VALIDATE must run at
     * FINALIZE, by construction, and a new rule inherits that automatically.
     *
     * NOT symmetric: ADD/REPLACE/REMOVE stay strict. Those are per-operation
     * triggers where a config-scope rule (e.g. system.required_set on a
     * half-built draft) would block a legitimate edit — the A-12 reasoning.
     *
     * Severity is untouched: VALIDATION_FAILURE blocks under VALIDATE/FINALIZE
     * and not under ADD (Verdict::blocking()), which is precisely why
     * storage.caddy_pairing — ADD,VALIDATE, VALIDATION_FAILURE — could not block
     * anywhere finalize actually reached before this.
     */
    private static function rulesFor(RuleInterface $rule, string $trigger): bool
    {
        $triggers = $rule->triggers();
        if (in_array($trigger, $triggers, true)) {
            return true;
        }
        return $trigger === Trigger::FINALIZE && in_array(Trigger::VALIDATE, $triggers, true);
    }

    /**
     * Evaluate every registered rule that applies to $trigger against $state
     * (see rulesFor() — FINALIZE additionally picks up every VALIDATE rule).
     * A rule that throws is NEVER swallowed (INV-5, fail-closed): its
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
            if (!self::rulesFor($rule, $trigger)) {
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
