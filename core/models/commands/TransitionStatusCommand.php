<?php

require_once __DIR__ . '/BaseCommand.php';
require_once __DIR__ . '/../state/StateMachine.php';
require_once __DIR__ . '/../validation/Trigger.php';

/**
 * TransitionStatusCommand — the command-layer strangler over
 * ServerBuilder::finalizeConfiguration() (was 3625-3738+). Scoped narrowly
 * to transitions whose StateMachine edge requires full validation (today:
 * only 'finalized', matching legacy's own always-validate-before-finalize
 * behavior) — see the "why trigger() is fixed to FINALIZE" note below for
 * why this command does not attempt to be a generic any-transition command.
 *
 * PD-6 (documented interpretation): the pack's "requires_validation=full ⇒
 * evaluate(FINALIZE)" reads as a CONDITIONAL evaluate. BaseCommand::execute()
 * is final and ALWAYS evaluates via trigger() (U-C.1's own fixed skeleton —
 * a "maybe validate" branch would need every command to carry that logic,
 * not just this one). Since this command is scoped to finalize-only
 * transitions, and finalize's own StateMachine edge always carries
 * requires_validation=full (confirmed: U-SM.2's transition table design —
 * there is no "finalize without full validation" edge), trigger() being
 * unconditionally Trigger::FINALIZE already IS "evaluate(FINALIZE) inside
 * the lock" for every transition this command is ever used for. A future
 * unit wanting a lighter (draft->building, etc.) transition command should
 * NOT reuse this class as-is; it would need its own trigger()/no-validation
 * handling.
 *
 * assertConfigTransition (legality + permission) runs in buildTarget(), the
 * SAME lock finalizeConfiguration()'s legacy call already held — this
 * closes audit V-1 structurally (the check can no longer race a concurrent
 * mutation the way an unlocked pre-check could) rather than by convention.
 *
 * apply(): StateMachine::applyConfigTransition() (status_v2 + mapped legacy
 * int + revision/event bump, atomically) + the separate `notes` column
 * write (StateMachine deliberately doesn't know about it) + inventory
 * allocated->installed promotions for every live component still sitting at
 * status_v2='allocated' (the state-machine vocabulary's "reserved but not
 * yet running" value) now that the config itself is finalized.
 */
final class TransitionStatusCommand extends BaseCommand
{
    /** @var string one of StatusMap::CONFIG_V2_TO_LEGACY's keys */
    private $toStatus;
    /** @var string */
    private $notes;
    /** @var int */
    private $userId;

    public function __construct(PDO $pdo, string $configUuid, string $toStatus, string $notes, int $userId, ?int $expectedRevision = null)
    {
        parent::__construct($pdo, $configUuid, $userId, $expectedRevision);
        $this->toStatus = $toStatus;
        $this->notes = $notes;
        $this->userId = $userId;
    }

    protected function trigger(): string
    {
        return Trigger::FINALIZE;
    }

    protected function buildTarget(TargetState $current, array $lockedRow): TargetState
    {
        $transitionCheck = StateMachine::assertConfigTransition($this->pdo, $this->configUuid, $this->toStatus, $this->userId);
        if (!$transitionCheck['allowed']) {
            throw new CommandFailed('transition_denied', $transitionCheck['reason'], 409);
        }

        // Identity transform: finalize adds/removes nothing. ValidationEngine
        // evaluates this SAME $current under Trigger::FINALIZE via
        // BaseCommand::execute()'s own generic step, inside the same lock
        // assertConfigTransition just ran under (closes V-1).
        return $current;
    }

    protected function apply(PDO $pdo, TargetState $target): void
    {
        StateMachine::applyConfigTransition($pdo, $this->configUuid, $this->toStatus, $this->actor);

        $stmt = $pdo->prepare('UPDATE server_configurations SET notes = ? WHERE config_uuid = ?');
        $stmt->execute([$this->notes, $this->configUuid]);

        foreach ($target->components() as $c) {
            if (($c['status_v2'] ?? null) === 'allocated' && $c['inventory_table'] !== null && $c['spec_uuid'] !== null) {
                StateMachine::applyInventoryTransition($pdo, $c['inventory_table'], $c['spec_uuid'], 'installed', $c['serial_number']);
            }
        }
    }
}
