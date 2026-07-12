<?php

/**
 * Rule severities (RULE_MAP.md legend: E / VF / W).
 *
 * PLAN DEVIATION PD-2 (see migration/handoffs/SESSION-20260712-P4-VALIDATION-ENGINE.md):
 * the U-V.1 execution pack specifies "PHP 8 enums", but ims-ftp/CLAUDE.md pins the stack
 * at "PHP 7.4+" and no file anywhere in core/ uses PHP 8-only syntax. A native enum here
 * would be a hard parse error on 7.x, and this class is required unconditionally from
 * ServerBuilder.php once U-V.3 wires the hook (parsed on every request, not just when
 * ENGINE_MODE is on) — so a parse failure would take down production regardless of flag
 * state. Implemented as a final class of string constants instead: same vocabulary,
 * zero syntax risk.
 */
final class Severity
{
    const ERROR = 'ERROR';
    const VALIDATION_FAILURE = 'VALIDATION_FAILURE';
    const WARNING = 'WARNING';

    public static function all(): array
    {
        return [self::ERROR, self::VALIDATION_FAILURE, self::WARNING];
    }
}
