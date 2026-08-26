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

    /**
     * A status move the transition table marked requires_validation != 'full'
     * (draft -> building, finalized -> deployed, ...). NO registered rule
     * declares it, deliberately: the transition table already said this edge is
     * not a deployability gate, so the engine has nothing to assess and returns
     * an empty, non-blocking Verdict.
     *
     * It exists because the alternative was reusing VALIDATE or FINALIZE, and
     * both of those mean "assess the whole configuration" — which would block
     * draft -> building on a half-built draft for missing CPU/RAM, i.e. block a
     * server from entering the very state it gets built in. Severity alone does
     * not save it: an ERROR result blocks under EVERY trigger (Verdict).
     */
    const TRANSITION = 'TRANSITION';

    public static function all(): array
    {
        return [self::ADD, self::REMOVE, self::REPLACE, self::VALIDATE, self::FINALIZE, self::TRANSITION];
    }
}
