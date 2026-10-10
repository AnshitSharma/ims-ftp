<?php

require_once __DIR__ . '/ValidationEngine.php';
require_once __DIR__ . '/TargetStateBuilder.php';
require_once __DIR__ . '/Trigger.php';
require_once __DIR__ . '/Severity.php';
require_once __DIR__ . '/RuleResult.php';
require_once __DIR__ . '/Verdict.php';

/**
 * ValidateConfigService — U-D.2. The read-only validation surface, backed by the
 * ValidationEngine rule registry.
 *
 * Replaces the two legacy ServerBuilder methods that P9 deleted:
 *
 *   validateConfigurationComprehensive()  ->  evaluate()   (server-validate-config)
 *   getConfigurationWarnings()            ->  current()    (server-get-config)
 *
 * Both were ~500 lines of hand-rolled per-type checks that duplicated, and
 * sometimes contradicted, the registry every WRITE path already evaluates
 * (AddComponentCommand, RemoveComponentCommand, TransitionStatusCommand). Routing
 * the read paths through the same registry is the point: a config can no longer
 * be reported valid by the endpoint and then refused by finalize, or vice versa.
 *
 * Read-only by construction — no writes, no transaction, no locking. The caller
 * (`handleValidateConfiguration`) persists the result to
 * `server_configurations.validation_results` itself, exactly as it always did.
 */
final class ValidateConfigService
{
    /**
     * Full-configuration verdict under Trigger::VALIDATE.
     *
     * Shape note: the legacy return carried `category_scores` (eight hardcoded
     * 100s), `required_components`, `resource_availability` and
     * `detailed_checks`. None of those were computed from anything a caller
     * could act on, no frontend read them, and the compatibility SCORE they fed
     * was removed on 2026-08-23. What survives is what was load-bearing:
     * `valid`, `errors`, `warnings`, `info` — plus `rule_results`, which is
     * strictly more information than the legacy shape ever offered, since every
     * entry carries the registry `rule_id` that produced it.
     *
     * @return array{valid:bool, errors:string[], warnings:string[], info:string[],
     *               rule_results:array[], trigger:string, evaluated_at:string}
     */
    public static function evaluate(PDO $pdo, string $configUuid): array
    {
        $state = TargetStateBuilder::fromCurrent($pdo, $configUuid);
        $verdict = (new ValidationEngine())->evaluate($state, Trigger::VALIDATE);

        $errors = [];
        $warnings = [];
        $info = [];
        $ruleResults = [];

        foreach ($verdict->results() as $result) {
            $ruleResults[] = [
                'rule_id'  => $result->ruleId(),
                'severity' => $result->severity(),
                'passed'   => $result->passed(),
                'message'  => $result->message(),
                'details'  => $result->details(),
            ];

            if ($result->passed()) {
                continue;
            }

            // Mirrors Verdict::blocking()'s matrix: under VALIDATE both ERROR and
            // VALIDATION_FAILURE block, so both are errors here. WARNING never
            // blocks and stays advisory.
            if ($result->severity() === Severity::WARNING) {
                $warnings[] = $result->message();
            } else {
                $errors[] = $result->message();
            }
        }

        return [
            'valid'        => !$verdict->blocking(),
            'errors'       => $errors,
            'warnings'     => $warnings,
            'info'         => $info,
            'rule_results' => $ruleResults,
            'trigger'      => $verdict->trigger(),
            'evaluated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Kept for workers still running cached bytecode of the pre-QA-12 get-config,
     * which called this. Removing it on 2026-10-10 produced intermittent 500s while
     * that bytecode lingered. Delete only once no caller can be running the old file.
     *
     * @return array[] each {type, severity, message, recommendation}
     */
    public static function warnings(PDO $pdo, string $configUuid): array
    {
        return self::current($pdo, $configUuid)['warnings'];
    }

    /**
     * The `validation` block `server-get-config` returns: validity, errors and the
     * warning list, all from ONE evaluation of the configuration as it is now.
     *
     * QA-12 (2026-10-10): get-config used to evaluate the engine for `warnings` but
     * take `is_valid` and `errors` from the stored `validation_results`, which only
     * `server-validate-config` writes. After a corrective edit the response said
     * invalid and repeated an error the build no longer had, beside a warning list
     * that had already moved on. `last_validated` was the config's updated_at, a
     * mutation time rather than an evaluation time.
     *
     * Same engine pass get-config already paid for, so this costs nothing extra.
     * The stored column is still written by validate-config; nothing reads it here.
     *
     * @return array{is_valid:bool, last_validated:string, warnings:array[], errors:string[]}
     */
    public static function current(PDO $pdo, string $configUuid): array
    {
        $state = TargetStateBuilder::fromCurrent($pdo, $configUuid);
        $verdict = (new ValidationEngine())->evaluate($state, Trigger::VALIDATE);

        $errors = [];
        foreach ($verdict->failures() as $result) {
            if ($result->severity() !== Severity::WARNING) {
                $errors[] = $result->message();
            }
        }

        return [
            'is_valid'       => !$verdict->blocking(),
            'last_validated' => date('Y-m-d H:i:s'),
            'warnings'       => self::warningEntries($verdict),
            'errors'         => $errors,
        ];
    }

    /**
     * The finding list `server-get-config` renders beside a build, every failed
     * result including the blocking ones.
     *
     * Keeps legacy getConfigurationWarnings()' element shape
     * ({type, severity, message, recommendation}) so the response contract is
     * unchanged. `type` is the registry rule id, which is a stable identifier; the
     * legacy ad-hoc slugs ('m2_slots_exceeded', 'missing_component') were not.
     *
     * @return array[] each {type, severity, message, recommendation}
     */
    private static function warningEntries(Verdict $verdict): array
    {
        $out = [];
        foreach ($verdict->failures() as $result) {
            $details = $result->details();
            $out[] = [
                'type'           => $result->ruleId(),
                'severity'       => self::legacySeverity($result->severity()),
                'message'        => $result->message(),
                'recommendation' => isset($details['recommendation']) && is_string($details['recommendation'])
                    ? $details['recommendation']
                    : null,
            ];
        }

        return $out;
    }

    /**
     * Registry severity -> the vocabulary the get-config response has always
     * used ('critical' | 'high' | 'info'). A VALIDATION_FAILURE is 'high'
     * rather than 'critical' because it blocks only VALIDATE/FINALIZE, not an
     * in-progress edit — which is exactly what 'high but not fatal' meant in
     * the legacy list.
     */
    private static function legacySeverity(string $severity): string
    {
        switch ($severity) {
            case Severity::ERROR:
                return 'critical';
            case Severity::VALIDATION_FAILURE:
                return 'high';
            default:
                return 'info';
        }
    }
}
