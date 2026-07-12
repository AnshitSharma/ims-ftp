<?php

require_once __DIR__ . '/../validation/TargetState.php';
require_once __DIR__ . '/../validation/TargetStateBuilder.php';
require_once __DIR__ . '/../validation/ValidationEngine.php';
require_once __DIR__ . '/../validation/Verdict.php';
require_once __DIR__ . '/../state/StateGuard.php';

/**
 * COMMAND_LAYER_ENABLED reader (FLAGS.md, INV-12) — same getenv -> $_ENV ->
 * default 'off' -> whitelist pattern as ValidationEngine::mode()/StateGuard::mode().
 * shadow: an API handler builds+evaluates a command WITHOUT calling
 * execute()'s apply() (a dry verdict — see each command's own shadow-hook
 * site), compares to the legacy call's real outcome, logs divergence, then
 * runs the legacy path as today. enforce: the handler calls execute()
 * instead of the legacy method entirely.
 */
class CommandLayer
{
    public static function mode(): string
    {
        $mode = getenv('COMMAND_LAYER_ENABLED');
        if (!is_string($mode) || $mode === '') {
            $mode = $_ENV['COMMAND_LAYER_ENABLED'] ?? 'off';
        }
        $mode = strtolower(trim((string)$mode));
        if (!in_array($mode, ['off', 'shadow', 'enforce'], true)) {
            return 'off';
        }
        return $mode;
    }
}

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

            $guardVerdict = StateGuard::checkMutation($this->pdo, $lockedRow);
            if ($guardVerdict !== null) {
                throw new CommandFailed($guardVerdict['error_type'] ?? 'config_immutable', $guardVerdict['message'] ?? 'Mutation not allowed', 409);
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
            throw new CommandFailed('command_exception', $e->getMessage(), 500, null, $e);
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
            $guardVerdict = StateGuard::checkMutation($this->pdo, $lockedRow);
            if ($guardVerdict !== null) {
                throw new CommandFailed($guardVerdict['error_type'] ?? 'config_immutable', $guardVerdict['message'] ?? 'Mutation not allowed', 409);
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
