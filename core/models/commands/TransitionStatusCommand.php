<?php

require_once __DIR__ . '/BaseCommand.php';
require_once __DIR__ . '/../state/StateMachine.php';
require_once __DIR__ . '/../validation/Trigger.php';

/**
 * TransitionStatusCommand — the command-layer strangler over
 * ServerBuilder::finalizeConfiguration() (was 3625-3738+). Originally scoped
 * narrowly to transitions whose StateMachine edge requires full validation
 * (only 'finalized', matching legacy's own always-validate-before-finalize
 * behavior); since 2026-08-26 it serves any edge, evaluating under the trigger
 * that edge's requires_validation column asks for — see trigger().
 *
 * PD-6 (documented interpretation): the pack's "requires_validation=full ⇒
 * evaluate(FINALIZE)" reads as a CONDITIONAL evaluate. BaseCommand::execute()
 * is final and ALWAYS evaluates via trigger() (U-C.1's own fixed skeleton —
 * a "maybe validate" branch would need every command to carry that logic,
 * not just this one). That conditional now lives in trigger(), which
 * reads requires_validation off the edge inside the lock:
 * FINALIZE for a 'full' edge, the ruleless Trigger::TRANSITION otherwise.
 * Finalize's own edge carries 'full' (U-SM.2's transition table design —
 * there is no "finalize without full validation" edge), so finalize behaves
 * exactly as before. This replaces the older note here telling a future unit
 * NOT to reuse this class for a lighter draft->building transition: two callers
 * did reuse it, and making the trigger edge-driven is what makes that correct.
 *
 * SYSTEM-AUTHORIZED MODE (2026-08-26). An approved Request performs its work
 * through this command with $systemAuthorized = true. That skips the ACL half
 * of assertConfigTransition and nothing else: the edge must still exist, the
 * lock is still held, validation still runs. The requester never gains
 * server.edit -- there is no permission to gain, because no person is acting.
 * Without it the whole action was impossible: the submit-time preflight builds
 * commands with actor 0, so `draft -> building` was refused as "missing
 * permission 'server.edit'" for every requester including a super admin, and an
 * approval would then have been refused a second time under the requester's own
 * id.
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
    /** @var bool the caller is an approved Request, not a person — see the class note */
    private $systemAuthorized;
    /**
     * @var bool which trigger to evaluate under, read from the edge in
     * buildTarget(). Null until then, and trigger() treats null as FINALIZE:
     * trigger() cannot run before buildTarget() in either execute() or
     * dryRun(), and if that ever changes the wrong answer must be the strict
     * one.
     */
    private $requiresFullValidation = null;

    public function __construct(PDO $pdo, string $configUuid, string $toStatus, string $notes, int $userId, ?int $expectedRevision = null, bool $systemAuthorized = false)
    {
        parent::__construct($pdo, $configUuid, $userId, $expectedRevision);
        $this->toStatus = $toStatus;
        $this->notes = $notes;
        $this->userId = $userId;
        $this->systemAuthorized = $systemAuthorized;
    }

    /**
     * The trigger the EDGE asks for, not a fixed one.
     *
     * Was hardcoded to FINALIZE, which was correct while this command was only
     * ever used for validated -> finalized (the docblock above says so, and
     * says a lighter transition must not reuse the class as-is). It then
     * acquired two generic callers -- server-transition-status and the
     * server.config.transition Request action -- and the hardcoding became a
     * live bug: FINALIZE subsumes every VALIDATE rule (ValidationEngine F-26),
     * so draft -> building ran the full deployability suite and a draft with no
     * CPU yet was refused entry to the state where a CPU gets added.
     *
     * requires_validation is exactly the column that answers this, per edge, and
     * finalize's own edge carries 'full' -- so finalize is untouched.
     */
    protected function trigger(): string
    {
        return ($this->requiresFullValidation === false) ? Trigger::TRANSITION : Trigger::FINALIZE;
    }

    protected function buildTarget(TargetState $current, array $lockedRow): TargetState
    {
        $transitionCheck = StateMachine::assertConfigTransition(
            $this->pdo,
            $this->configUuid,
            $this->toStatus,
            $this->userId,
            $this->systemAuthorized
        );
        if (!$transitionCheck['allowed']) {
            throw new CommandFailed('transition_denied', $transitionCheck['reason'], 409);
        }
        $this->requiresFullValidation = (bool)$transitionCheck['requires_validation'];

        // Identity transform: finalize adds/removes nothing. ValidationEngine
        // evaluates this SAME $current under Trigger::FINALIZE via
        // BaseCommand::execute()'s own generic step, inside the same lock
        // assertConfigTransition just ran under (closes V-1).
        return $current;
    }

    protected function apply(PDO $pdo, TargetState $target): void
    {
        StateMachine::applyConfigTransition($pdo, $this->configUuid, $this->toStatus, $this->actor);

        // Only when the caller actually supplied a note. This was an
        // unconditional write while finalize was the sole caller and always
        // passed one; now that any edge can reach here, an empty note means
        // "say nothing", not "erase what the server's notes column says".
        if ($this->notes !== '') {
            $stmt = $pdo->prepare('UPDATE server_configurations SET notes = ? WHERE config_uuid = ?');
            $stmt->execute([$this->notes, $this->configUuid]);
        }

        // Finalizing is what makes a reserved unit installed. A lighter edge
        // (draft -> building, finalized -> deployed) must not promote anything:
        // before this command served those edges the loop could only ever run
        // under 'finalized', and its own comment below says so.
        if ($this->toStatus !== 'finalized') {
            return;
        }

        foreach ($target->components() as $c) {
            if (($c['status_v2'] ?? null) === 'allocated' && $c['inventory_table'] !== null && $c['spec_uuid'] !== null) {
                // inventory_id is the unit identity; spec_uuid alone is the MODEL and
                // serial_number is legitimately NULL for a serial-less unit. [F-22]
                StateMachine::applyInventoryTransition(
                    $pdo,
                    $c['inventory_table'],
                    $c['spec_uuid'],
                    'installed',
                    $c['serial_number'],
                    isset($c['inventory_id']) ? (int)$c['inventory_id'] : null
                );
            }
        }
    }
}
