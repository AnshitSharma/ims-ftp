<?php

/**
 * Rule triggers — the operations a rule may be evaluated under.
 * See Severity.php for why this is a constants class, not a PHP 8 enum (PD-2).
 */
final class Trigger
{
    const ADD = 'ADD';
    const REMOVE = 'REMOVE';
    const REPLACE = 'REPLACE';
    const VALIDATE = 'VALIDATE';
    const FINALIZE = 'FINALIZE';

    public static function all(): array
    {
        return [self::ADD, self::REMOVE, self::REPLACE, self::VALIDATE, self::FINALIZE];
    }
}
