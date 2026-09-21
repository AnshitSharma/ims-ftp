<?php

require_once __DIR__ . '/../validation/TargetState.php';
require_once __DIR__ . '/../validation/TargetStateBuilder.php';
require_once __DIR__ . '/../validation/ValidationEngine.php';
require_once __DIR__ . '/../validation/Verdict.php';
require_once __DIR__ . '/../state/StateGuard.php';

/*
 * U-D.4: the CommandLayer flag reader lived here. COMMAND_LAYER_ENABLED is
 * gone -- the commands below ARE the write path, unconditionally.
 */

/**
 * Thrown by BaseCommand::execute() for EVERY failure mode (fail-closed,
 * INV-5): config not found, StateGuard block, revision mismatch, a blocking
 * Verdict, or any other exception apply()/buildTarget() raised. One
 * exception class, one place callers need to catch, carrying enough detail
 * ($errorType/$httpStatus/$verdict) to render whatever response shape the
 * caller needs (API adapter, CLI, test).
 */
class CommandFailed extends \RuntimeException
{
    /** @var string machine-readable error type, e.g. 'config_not_found', 'revision_mismatch' */
    public $errorType;
    /** @var int HTTP-status-shaped code for API adapters (U-A.2) to map directly */
    public $httpStatus;
    /** @var Verdict|null set only when the failure was a blocking validation verdict */
    public $verdict;

    public function __construct(string $errorType, string $message, int $httpStatus = 400, ?Verdict $verdict = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->errorType = $errorType;
        $this->httpStatus = $httpStatus;
        $this->verdict = $verdict;
    }
}

/** Value object returned by a successful BaseCommand::execute(). */
final class CommandResult
{
    /** @var int the config's new revision after this command's write */
    public $revision;
    /** @var Verdict the (non-blocking) verdict the write was evaluated against */
    public $verdict;

    public function __construct(int $revision, Verdict $verdict)
    {
        $this->revision = $revision;
        $this->verdict = $verdict;
    }
}

/**
 * BaseCommand — the ONE transaction owner for every mutation this migration
 * introduces (INV-3's target state) and the ONE place revision+event bumps
 * are enforced to happen inside the same transaction as the row write
 * (INV-6). Concrete commands (AddComponentCommand, RemoveComponentCommand,
 * ReplaceComponentCommand, TransitionStatusCommand) implement only the four
 * abstract hooks below; execute() is final — the SESSION skeleton sequence
 * (target design §6) is not something a subclass can reorder or skip:
 *
 *   BEGIN -> lock config -> state guard -> revision match -> TargetState
 *   -> evaluate -> blocking? rollback : apply -> COMMIT -> afterCommit hooks
 *
 * "revision+event" is NOT a separate step BaseCommand performs itself —
 * apply() is responsible for the actual row write AND its own revision/event
 * bump, via ConfigComponentRepository::insert()/tombstone() (which already
 * bump atomically with the row write) or a bare
 * ConfigComponentRepository::bumpRevision() call for commands with no row
 * change (e.g. TransitionStatusCommand, per that repository's own docblock:
 * "callers doing a bare revision bump with no row change (e.g. 'transition')
 * may call this directly"). This is PD-3 (a plan deviation from the pack's
 * literal 5-box diagram, recorded because the diagram reads as if
 * BaseCommand itself calls a generic bumpRevision after every apply(), which
 * would double-bump for Add/Remove — apply() already does it via the
 * repository): documented here rather than silently interpreted.
 *
 * ownTransaction pattern (nestable, matches every legacy mutation method's
 * own convention): if the caller is already inside a transaction (e.g. a
 * command composing another command's apply() helper, or a legacy caller
 * mid-migration), this command joins it rather than starting a nested one —
 * BEGIN/COMMIT/ROLLBACK only ever happen at the outermost frame.
 *
 * PD-4 (buildTarget signature): the pack's literal text is
 * "buildTarget(TargetStateBuilder,$lockedRow): TargetState". TargetStateBuilder
 * exposes only static methods (fromCurrent/withAdd/withRemove/withReplace/
 * dependentsOf) — there is nothing for a passed-in instance to carry that a
 * subclass could not obtain by calling the class statically itself. Passing
 * the CURRENT TargetState (already built via TargetStateBuilder::fromCurrent()
 * inside execute()) instead is functionally equivalent and is what every
 * concrete command actually needs: a base to call withAdd()/withRemove()/
 * withReplace() against. Signature here is therefore
 * buildTarget(TargetState $current, array $lockedRow): TargetState.
 */
abstract class BaseCommand
{
    /** @var PDO */
    protected $pdo;
    /** @var string */
    protected $configUuid;
    /** @var int|string actor user id for config_events.actor / added_by */
    protected $actor;
    /** @var int|null null = skip the revision check (legacy adapters); U-A.2 exposes If-Match */
    protected $expectedRevision;

    public function __construct(PDO $pdo, string $configUuid, $actor = 0, ?int $expectedRevision = null)
    {
        $this->pdo = $pdo;
        $this->configUuid = $configUuid;
        $this->actor = $actor;
        $this->expectedRevision = $expectedRevision;
    }

    /** @return string one of Trigger::* — which trigger to evaluate the ValidationEngine registry under */
    abstract protected function trigger(): string;

    /** @return TargetState the TARGET (post-operation) state to evaluate and, if non-blocking, apply */
    abstract protected function buildTarget(TargetState $current, array $lockedRow): TargetState;

    /**
     * Perform the actual row write(s) (config_components insert/tombstone,
     * legacy JSON columns via ServerBuilder library calls, inventory status
     * transitions, revision+event bump) — everything the commit depends on.
     * Runs INSIDE the same transaction execute() holds the config lock in.
     */
    abstract protected function apply(PDO $pdo, TargetState $target): void;

    /**
     * Cache invalidation ONLY (closes audit E-1's "cache invalidated from N
     * scattered sites" finding — this is the single site). Runs AFTER COMMIT,
     * so never touches $this->pdo or any transaction state. Default: no-op.
     */
    protected function afterCommit(): void
    {
    }

    /**
     * Does StateGuard's mutability rule apply to this command? (2026-08-26)
     *
     * StateGuard answers ONE question: may this config's CONTENTS be changed —
     * its own docblock says "may be mutated (add/remove component)" — and it
     * answers it by asking which lifecycle state the config sits in
     * (draft/building/maintenance yes, anything else no).
     *
     * A STATUS TRANSITION is not a content change. It is the operation that
     * moves a config between those states, so gating it on "are you already in a
     * mutable state" made every state outside that set terminal: at
     * STATE_MACHINE_ENABLED=enforce, finalized -> building (the un-finalize
     * edge), finalized -> deployed, validated -> finalized, validated ->
     * building, validating -> validated and deployed -> maintenance were ALL
     * refused with 'config_immutable' before StateMachine was ever consulted.
     * A finalized server could never be reopened, and a validated one could
     * never be finalized -- config_status_transitions described a graph whose
     * edges the guard had already cut.
     *
     * The legacy finalize path draws the line correctly and is the precedent
     * followed here: ServerBuilder::finalizeConfiguration() at
     * StateGuard::mode()==='enforce' calls StateMachine::assertConfigTransition()
     * and NOT StateGuard::checkMutation(). A transition is gated by the
     * transition table (does the edge exist, does the actor hold the permission
     * it names, does it require full validation first); contents are gated by
     * StateGuard.
     *
     * Defaults to true, so Add/Remove/ReplaceComponentCommand -- every command
     * that really does change contents -- are untouched. Only
     * TransitionStatusCommand overrides it, and it is not thereby ungated:
     * assertConfigTransition() runs under the same lock, inside buildTarget().
     */
    protected function guardsMutation(): bool
    {
        return true;
    }

    /**
     * Replicates ServerBuilder::lockAndLoadConfigRow() (was 425-435) exactly
     * as its own copy — commands must not depend on ServerBuilder for this.
     * @return array|null
     */
    protected function lockAndLoadConfigRow(): ?array
    {
        if (!$this->pdo->inTransaction()) {
            throw new \RuntimeException(static::class . '::lockAndLoadConfigRow() must be called inside an active transaction');
        }
        $stmt = $this->pdo->prepare('SELECT * FROM server_configurations WHERE config_uuid = ? FOR UPDATE');
        $stmt->execute([$this->configUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Post-lock availability gate over a just-locked inventory row — the port
     * of legacy ServerBuilder::checkComponentAvailability() (was ~5301-5352)
     * plus its call-site override protocol (ServerBuilder.php:745), closing
     * the 2026-07-12 verify record's Finding A: without this, enforce-mode
     * add/replace could claim a failed or in-use physical unit (the
     * `ORDER BY Status ASC` pick sorts failed=0 FIRST and nothing else tests
     * Status — system.inventory_state only triggers on VALIDATE/FINALIZE).
     *
     * Mirrors legacy EXACTLY, including its two lenient edges:
     *   - options['override_used'] bypasses the whole gate (legacy's 745 gate
     *     tests only override_used, never can_override — so an override even
     *     claims a failed unit, as legacy always allowed);
     *   - a virtual config bypasses availability checks entirely
     *     (checkComponentAvailability()'s own is_virtual early-return,
     *     resolved here from the already-locked config row instead of a
     *     second query).
     * Status semantics: 0=failed blocks; 1=available passes; 2=in_use passes
     * only when already assigned to THIS config, else blocks (overridable);
     * unknown statuses block.
     */
    protected function assertInventoryAvailability(array $inventoryData, array $lockedRow, array $options): void
    {
        if (!empty($options['override_used'])) {
            return;
        }
        if (!empty($lockedRow['is_virtual'])) {
            return;
        }

        $status = (int)($inventoryData['Status'] ?? -1);
        $serverUuid = $inventoryData['ServerUUID'] ?? null;

        if ($status === 1) {
            return;
        }
        if ($status === 2 && $serverUuid === $this->configUuid) {
            return;
        }

        if ($status === 0) {
            $message = 'Component is marked as Failed/Defective';
        } elseif ($status === 2) {
            $message = $serverUuid
                ? "Component is currently in use in configuration: $serverUuid"
                : 'Component is currently In Use';
        } else {
            $message = "Component has unknown status: $status";
        }

        throw new CommandFailed('component_unavailable', $message, 409);
    }

    /**
     * Is this exact unit at the site the server stands at? [F-07]
     *
     * The rule already existed, in the wrong place and in a waivable form:
     * RequestActionExecutor::locationGate() applied it to approved add/replace
     * Requests only, matched on the spec uuid plus an optional serial rather than
     * on the unit, and returned "proceed" whenever it could not tell — an
     * unreadable location, a resolver exception, a part with no site. So a user
     * holding direct install rights simply called the endpoint and skipped the
     * handover the requester was made to raise, and the location sync that runs
     * afterwards then RELABELLED the part as being at the server's site,
     * recording a physical transfer that never happened.
     *
     * Here it is judged against the two rows this command has already locked, so
     * there is no second lookup to disagree with them and no window to race, and
     * it FAILS CLOSED: unknown is a refusal, not a waiver. That is affordable
     * because it was measured before shipping — on 2026-09-13 exactly 2 of 356
     * inventory rows had no location_uuid, and both were SourceType='onboard'
     * NICs, which is the one exemption below.
     *
     * An onboard port is not separately locatable: it is a region of silicon on a
     * board that is already installed in this server, it cannot be carried
     * anywhere on its own, and no handover could ever move it.
     *
     * The two columns it needs — location_uuid everywhere, SourceType on
     * nicinventory alone — are read here rather than added to each command's
     * locked SELECT: SourceType does not exist on the other eleven tables, and
     * location_uuid arrived in a seeder, so naming either unconditionally in a
     * shared statement is the deploy-ordering trap. The unit is already locked
     * FOR UPDATE by the caller, so re-reading it by ID sees the same row.
     *
     * @param string $table     the {type}inventory table the unit is in
     * @param int    $unitId    its ID, already locked by the caller
     * @param array  $lockedRow the locked server_configurations row
     */
    protected function assertUnitAtServerLocation(string $table, int $unitId, array $lockedRow): void
    {
        if (!empty($lockedRow['is_virtual'])) {
            return; // reserves no physical unit — nothing to be anywhere
        }

        require_once __DIR__ . '/../../helpers/SchemaHelper.php';

        if (!SchemaHelper::hasColumn($this->pdo, $table, 'location_uuid')
            || !SchemaHelper::hasColumn($this->pdo, 'server_configurations', 'location_uuid')) {
            // The seeder that adds the column has not been run yet. Nothing can
            // be compared, so nothing can be asserted — and this is the one case
            // that must NOT fail closed, because it would take every add on the
            // whole system down for the length of that window.
            return;
        }

        $select = 'location_uuid, Location'
            . (SchemaHelper::hasColumn($this->pdo, $table, 'SourceType') ? ', SourceType' : '');
        $stmt = $this->pdo->prepare("SELECT $select FROM `$table` WHERE ID = ?");
        $stmt->execute([$unitId]);
        $inventoryData = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        if (($inventoryData['SourceType'] ?? null) === 'onboard') {
            return;
        }

        $serverSite = trim((string)($lockedRow['location_uuid'] ?? ''));
        $unitSite   = trim((string)($inventoryData['location_uuid'] ?? ''));

        if ($serverSite === '') {
            throw new CommandFailed(
                'server_location_unknown',
                'This server has no site recorded, so there is no way to tell whether the part is where '
                . 'the server is. Set the server\'s location first.',
                409
            );
        }
        if ($unitSite === '') {
            throw new CommandFailed(
                'component_location_unknown',
                'This unit has no site recorded, so it cannot be shown to be where the server is. '
                . 'Set the unit\'s location, or raise a Hardware Handover request to move it here.',
                409
            );
        }
        if ($unitSite !== $serverSite) {
            $where = trim((string)($inventoryData['Location'] ?? '')) ?: 'another site';
            throw new CommandFailed(
                'location_mismatch',
                "This unit is at {$where}, and the server is somewhere else. Raise a Hardware Handover "
                . 'request to move the part first. Nothing has been changed.',
                409
            );
        }
    }

    /** @return int server_configurations.revision for this command's config, read fresh after apply(). */
    protected function currentRevision(): int
    {
        $stmt = $this->pdo->prepare('SELECT revision FROM server_configurations WHERE config_uuid = ?');
        $stmt->execute([$this->configUuid]);
        return (int)$stmt->fetchColumn();
    }

    final public function execute(): CommandResult
    {
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $lockedRow = $this->lockAndLoadConfigRow();
            if ($lockedRow === null) {
                throw new CommandFailed('config_not_found', "Configuration {$this->configUuid} not found", 404);
            }

            if ($this->guardsMutation()) {
                $guardVerdict = StateGuard::checkMutation($this->pdo, $lockedRow);
                if ($guardVerdict !== null) {
                    throw new CommandFailed($guardVerdict['error_type'] ?? 'config_immutable', $guardVerdict['message'] ?? 'Mutation not allowed', 409);
                }
            }

            if ($this->expectedRevision !== null && (int)($lockedRow['revision'] ?? 0) !== $this->expectedRevision) {
                throw new CommandFailed(
                    'revision_mismatch',
                    "Expected revision {$this->expectedRevision}, current is " . (int)($lockedRow['revision'] ?? 0),
                    409
                );
            }

            $current = TargetStateBuilder::fromCurrent($this->pdo, $this->configUuid);
            $target = $this->buildTarget($current, $lockedRow);

            $verdict = (new ValidationEngine())->evaluate($target, $this->trigger());
            if ($verdict->blocking()) {
                throw new CommandFailed('validation_blocked', 'Blocked by validation: ' . $this->summarizeFailures($verdict), 422, $verdict);
            }

            $this->apply($this->pdo, $target);

            $newRevision = $this->currentRevision();

            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (CommandFailed $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // B.3 (audit §12.2): this used to hand $e->getMessage() to the client,
            // and server_api.php sends a CommandFailed's message straight through.
            // A PDOException's message carries the SQLSTATE, the driver error and
            // often the failing statement — precisely what api.php's own hard rule
            // #8 says never to leak. The distinction already exists in the type:
            // a CommandFailed raised DELIBERATELY above keeps its operator-readable
            // text (it is rethrown unchanged), and only this generic catch — where
            // the message was never written for a reader — is flattened.
            error_log('Command failed (' . get_class($this) . '): ' . $e->getMessage());
            throw new CommandFailed('command_exception', 'Internal server error', 500, null, $e);
        }

        $this->afterCommit();
        return new CommandResult($newRevision, $verdict);
    }

    /**
     * Shadow-mode support: build + evaluate exactly as execute() would, but
     * NEVER call apply() and ALWAYS roll back — a pure read, whatever the
     * verdict. Used by an API handler's shadow hook to compare the command's
     * verdict against the legacy call's real outcome without risking a
     * second, divergent write for the same logical operation (INV-8).
     * Locks + rolls back rather than skipping the lock entirely so the
     * TargetState it evaluates reflects the same serialized snapshot
     * execute() would have used.
     *
     * @return Verdict
     * @throws CommandFailed if the config isn't found / StateGuard blocks /
     *         revision mismatches — the same fail-closed failures execute()
     *         would raise before ever reaching evaluate()
     */
    final public function dryRun(): Verdict
    {
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $lockedRow = $this->lockAndLoadConfigRow();
            if ($lockedRow === null) {
                throw new CommandFailed('config_not_found', "Configuration {$this->configUuid} not found", 404);
            }
            if ($this->guardsMutation()) {
                $guardVerdict = StateGuard::checkMutation($this->pdo, $lockedRow);
                if ($guardVerdict !== null) {
                    throw new CommandFailed($guardVerdict['error_type'] ?? 'config_immutable', $guardVerdict['message'] ?? 'Mutation not allowed', 409);
                }
            }
            $current = TargetStateBuilder::fromCurrent($this->pdo, $this->configUuid);
            $target = $this->buildTarget($current, $lockedRow);
            $verdict = (new ValidationEngine())->evaluate($target, $this->trigger());
        } finally {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
        return $verdict;
    }

    private function summarizeFailures(Verdict $verdict): string
    {
        $messages = array_map(function (RuleResult $r) {
            return "{$r->ruleId()}: {$r->message()}";
        }, $verdict->failures());
        return implode('; ', $messages);
    }
}
