<?php

/**
 * The single authority on "does this CPU belong to a generation / series the
 * board accepts". Kept out of CpuGenerationMatchRule so the listing path and
 * any future rule can reuse it, and so it unit-tests without a TargetState.
 *
 * Why this exists at all: socket type does not identify a generation. In the
 * current catalog LGA2011-3 carries Xeon E5 v3 AND v4, LGA3647 carries
 * Skylake-SP (Cascade Lake would join it), SP3 carries EPYC 7002 and 7003, and
 * AM4 carries Zen, Zen 2 and Zen 3. cpu.socket_match passes every one of those
 * pairings. See docs/RULE_MAP.md.
 *
 * Two independent axes, because neither alone covers both vendors:
 *
 *   generations - Intel's discriminator. A closed vocabulary, so board entries
 *                 resolve through ALIASES: "Broadwell-EP" and "Xeon E5 v4" are
 *                 the same canonical id. Matched against the CPU's
 *                 architecture, with series/family as a fallback.
 *
 *   series      - AMD's discriminator. architecture cannot express it: EPYC
 *                 7742 and Ryzen 5 3600 both say "Zen 2". The product-line fact
 *                 lives in the CPU's family ("EPYC 7002", "Ryzen 9 5000"), which
 *                 is an OPEN vocabulary, so this axis uses token-subset matching
 *                 instead of an alias table -- a board saying "Ryzen 5000"
 *                 matches family "Ryzen 9 5000", and "EPYC 7003" does not match
 *                 "EPYC 7002".
 *
 * Both board keys are optional and independent. Declared lists are ANDed: a
 * board declaring generations ["Zen 3"] and series ["EPYC 7003"] accepts Milan
 * only. Neither declared means no constraint, which is how every board behaves
 * until ims-data is populated.
 */
final class CpuGenerationResolver
{
    /**
     * canonical generation id => accepted spellings. Both codenames and vendor
     * marketing tags map to one id so ims-data can use either. Compared after
     * normalize(), so punctuation and case here are cosmetic.
     */
    const ALIASES = [
        'xeon-e5-v3' => ['Xeon E5 v3', 'Xeon E5-2600 v3', 'E5-2600 v3', 'E5 v3', 'Haswell-EP', 'Haswell-EX', 'Haswell'],
        'xeon-e5-v4' => ['Xeon E5 v4', 'Xeon E5-2600 v4', 'E5-2600 v4', 'E5 v4', 'Broadwell-EP', 'Broadwell-EX', 'Broadwell'],
        'xeon-sp-gen1' => ['Xeon Scalable Gen1', '1st Gen Xeon Scalable', 'Skylake-SP', 'Skylake'],
        'xeon-sp-gen2' => ['Xeon Scalable Gen2', '2nd Gen Xeon Scalable', 'Cascade Lake-SP', 'Cascade Lake'],
        'xeon-sp-gen3' => ['Xeon Scalable Gen3', '3rd Gen Xeon Scalable', 'Ice Lake-SP', 'Ice Lake', 'Cooper Lake'],
        'xeon-sp-gen4' => ['Xeon Scalable Gen4', '4th Gen Xeon Scalable', 'Sapphire Rapids'],
        'xeon-sp-gen5' => ['Xeon Scalable Gen5', '5th Gen Xeon Scalable', 'Emerald Rapids'],
        'core-gen12' => ['12th Gen Core', 'Alder Lake'],
        'core-gen13' => ['13th Gen Core', 'Raptor Lake'],
        'zen1' => ['Zen', 'Zen 1', 'Naples', 'Summit Ridge'],
        'zen2' => ['Zen 2', 'Rome', 'Matisse'],
        'zen3' => ['Zen 3', 'Milan', 'Vermeer'],
        'zen4' => ['Zen 4', 'Genoa', 'Bergamo', 'Raphael'],
        'zen5' => ['Zen 5', 'Turin'],
    ];

    /**
     * Strip everything but alphanumerics so "Broadwell-EP", "broadwell ep" and
     * "BROADWELL_EP" collapse together. Applied to both sides of every compare.
     */
    public static function normalize($value): string
    {
        if (!is_string($value)) {
            return '';
        }
        return preg_replace('/[^a-z0-9]+/', '', strtolower($value));
    }

    /** Alphanumeric tokens, for the open-vocabulary series axis. */
    public static function tokenize($value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $tokens = preg_split('/[^a-z0-9]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY);
        return $tokens === false ? [] : $tokens;
    }

    /** normalized spelling => canonical id, built once from ALIASES. */
    private static function aliasIndex(): array
    {
        static $index = null;
        if ($index === null) {
            $index = [];
            foreach (self::ALIASES as $canonical => $spellings) {
                $index[$canonical] = $canonical;
                foreach ($spellings as $spelling) {
                    $index[self::normalize($spelling)] = $canonical;
                }
            }
        }
        return $index;
    }

    /**
     * Canonical id for one spelling, or null when the vocabulary doesn't know it.
     * A null on the board side is not an error -- the caller falls back to
     * token matching so an unlisted-but-sensible tag still works.
     */
    public static function canonicalGeneration($spelling): ?string
    {
        $normalized = self::normalize($spelling);
        if ($normalized === '') {
            return null;
        }
        $index = self::aliasIndex();
        return $index[$normalized] ?? null;
    }

    /**
     * Every canonical generation the CPU spec resolves to. architecture is the
     * primary signal (present on all 32 catalog models); series and family are
     * consulted too because "Xeon E5 v4" identifies the generation just as well
     * as "Broadwell-EP" does.
     *
     * @return string[] canonical ids, deduplicated
     */
    public static function cpuGenerations(array $cpuSpec): array
    {
        $found = [];
        foreach (['architecture', 'series', 'family'] as $field) {
            $canonical = self::canonicalGeneration($cpuSpec[$field] ?? null);
            if ($canonical !== null) {
                $found[$canonical] = true;
            }
        }
        return array_keys($found);
    }

    /**
     * The raw strings that describe the CPU's product line, most specific
     * first. Used verbatim for token matching on both axes.
     *
     * @return string[]
     */
    public static function cpuSeriesLabels(array $cpuSpec): array
    {
        $labels = [];
        foreach (['family', 'series', 'architecture', 'model'] as $field) {
            $value = $cpuSpec[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $labels[] = $value;
            }
        }
        return $labels;
    }

    /**
     * True when every token of $boardEntry appears among $cpuLabel's tokens --
     * so "Ryzen 5000" matches family "Ryzen 9 5000", while "EPYC 7003" does not
     * match "EPYC 7002". Direction matters: the board entry is the subset.
     */
    private static function tokensCovered(string $boardEntry, string $cpuLabel): bool
    {
        $wanted = self::tokenize($boardEntry);
        if (empty($wanted)) {
            return false;
        }
        $have = self::tokenize($cpuLabel);
        foreach ($wanted as $token) {
            if (!in_array($token, $have, true)) {
                return false;
            }
        }
        return true;
    }

    /** The board's cpu_support block, or an empty array when it declares none. */
    public static function boardSupport(array $boardSpec): array
    {
        $support = $boardSpec['cpu_support'] ?? null;
        return is_array($support) ? $support : [];
    }

    /** One declared list off the board, normalized to a list of non-empty strings. */
    private static function declaredList(array $support, string $key): array
    {
        $list = $support[$key] ?? null;
        if (is_string($list)) {
            $list = [$list];
        }
        if (!is_array($list)) {
            return [];
        }
        $clean = [];
        foreach ($list as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $clean[] = trim($entry);
            }
        }
        return $clean;
    }

    /**
     * Evaluate one CPU against one board.
     *
     * @return array{
     *   constrained: bool,      board declared at least one usable list
     *   supported: bool,        CPU satisfies every declared list
     *   failed_axis: ?string,   'generations' | 'series' | 'unidentifiable'
     *   generations: string[],  what the board declared
     *   series: string[],
     *   cpu_generations: string[],
     *   cpu_label: ?string      most specific label we could name the CPU by
     * }
     */
    public static function evaluate(array $boardSpec, array $cpuSpec): array
    {
        $support = self::boardSupport($boardSpec);
        $wantGenerations = self::declaredList($support, 'generations');
        $wantSeries = self::declaredList($support, 'series');

        $cpuGenerations = self::cpuGenerations($cpuSpec);
        $cpuLabels = self::cpuSeriesLabels($cpuSpec);

        $result = [
            'constrained' => !empty($wantGenerations) || !empty($wantSeries),
            'supported' => true,
            'failed_axis' => null,
            'generations' => $wantGenerations,
            'series' => $wantSeries,
            'cpu_generations' => $cpuGenerations,
            'cpu_label' => $cpuLabels[0] ?? null,
        ];

        if (!$result['constrained']) {
            return $result;
        }

        // The board is asserting a constraint we cannot show the CPU satisfies.
        // Fail closed, matching CpuIdentityMatcher's posture on an unparseable
        // model name. Unreachable with current ims-data -- every CPU carries
        // architecture -- but a spec added without it must not slip through.
        if (empty($cpuGenerations) && empty($cpuLabels)) {
            $result['supported'] = false;
            $result['failed_axis'] = 'unidentifiable';
            return $result;
        }

        if (!empty($wantGenerations) && !self::satisfiesGenerations($wantGenerations, $cpuGenerations, $cpuLabels)) {
            $result['supported'] = false;
            $result['failed_axis'] = 'generations';
            return $result;
        }

        if (!empty($wantSeries) && !self::satisfiesSeries($wantSeries, $cpuLabels)) {
            $result['supported'] = false;
            $result['failed_axis'] = 'series';
            return $result;
        }

        return $result;
    }

    /**
     * Closed vocabulary first: any board entry resolving to a canonical id the
     * CPU also resolves to is a match. An entry the alias table doesn't know
     * falls back to token matching rather than being treated as unmatchable.
     */
    private static function satisfiesGenerations(array $wantGenerations, array $cpuGenerations, array $cpuLabels): bool
    {
        foreach ($wantGenerations as $entry) {
            $canonical = self::canonicalGeneration($entry);
            if ($canonical !== null) {
                if (in_array($canonical, $cpuGenerations, true)) {
                    return true;
                }
                continue;
            }
            foreach ($cpuLabels as $label) {
                if (self::tokensCovered($entry, $label)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Open vocabulary: token-subset against every label describing the CPU. */
    private static function satisfiesSeries(array $wantSeries, array $cpuLabels): bool
    {
        foreach ($wantSeries as $entry) {
            foreach ($cpuLabels as $label) {
                if (self::tokensCovered($entry, $label)) {
                    return true;
                }
            }
        }
        return false;
    }
}
