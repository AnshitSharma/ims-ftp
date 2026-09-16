<?php
/**
 * Type-ahead search over MODELS, not units. (audit Phase 2.6 / JSON-010)
 *
 * WHAT IT REPLACES
 *   To let someone pick a model, the frontend downloads whole spec files and walks them in
 *   the browser: five hard-coded path maps in the JS, a traversal that re-implements the
 *   backend's nesting rules, and add-form.js stringifying entire model objects into
 *   `dataset.modelData`. The catalogue is 536 KB and growing, the browser parses all of it to
 *   show twenty rows, and the nesting rules are duplicated in a second language where they
 *   drift silently.
 *
 * WHERE THE DATA COMES FROM
 *   SpecRepository, which reads component_models when that table exists and the spec files
 *   otherwise. That fallback is the point: this endpoint works the moment the code deploys,
 *   before anyone runs the seeder, and gets faster afterwards without changing behaviour.
 *   When the table IS present the same query could be pushed into SQL with the FULLTEXT index
 *   -- worth doing once there are thousands of models; at 378 the in-memory scan is ~1 ms and
 *   SQL would mean two ranking implementations that have to agree.
 *
 * RANKING
 *   Deliberately simple and explained, because an unexplained ranking is one nobody can fix:
 *   exact match, then prefix, then word-boundary, then substring; ties broken by availability
 *   (models you can actually fit today rank above ones with no free units) and then name.
 */

require_once __DIR__ . '/SpecRepository.php';
require_once __DIR__ . '/SpecProjector.php';
require_once __DIR__ . '/ComponentSpecPaths.php';

class ModelSearch
{
    /** Hard ceiling on what one call can return, whatever the caller asks for. */
    const MAX_LIMIT = 50;

    /**
     * @param PDO         $pdo
     * @param string      $query      free text: model name, brand, part number
     * @param string|null $type       restrict to one component type, or null for all
     * @param int         $limit      requested number of matches
     * @param bool        $withCounts include free/total unit counts per model
     * @return array{models: array, total_matched: int, truncated: bool}
     */
    public static function search(PDO $pdo, string $query, ?string $type, int $limit = 20, bool $withCounts = true): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $needle = self::normalise($query);

        if ($needle === '') {
            return ['models' => [], 'total_matched' => 0, 'truncated' => false];
        }

        $types = $type !== null ? [$type] : array_keys(ComponentSpecPaths::getAll());

        $matches = [];
        foreach ($types as $componentType) {
            foreach (self::recordsFor($componentType) as $record) {
                $score = self::score($needle, $record);
                if ($score > 0) {
                    $record['_score'] = $score;
                    $matches[] = $record;
                }
            }
        }

        $totalMatched = count($matches);

        if ($withCounts && $matches) {
            self::attachCounts($pdo, $matches);
        }

        usort($matches, static function ($a, $b) {
            if ($a['_score'] !== $b['_score']) {
                return $b['_score'] <=> $a['_score'];
            }
            // A model with free units is more useful in a picker than one without.
            $availA = $a['available'] ?? 0;
            $availB = $b['available'] ?? 0;
            if ($availA !== $availB) {
                return $availB <=> $availA;
            }
            return strcasecmp($a['display_name'], $b['display_name']);
        });

        $page = array_slice($matches, 0, $limit);
        foreach ($page as &$row) {
            unset($row['_score']);
        }
        unset($row);

        return [
            'models' => $page,
            'total_matched' => $totalMatched,
            'truncated' => $totalMatched > $limit,
        ];
    }

    /**
     * One model by uuid, in the same shape a search row has. This is what replaces
     * add-form.js stringifying a whole model object into a DOM dataset attribute.
     */
    public static function get(PDO $pdo, string $specUuid, ?string $type = null): ?array
    {
        $types = $type !== null ? [$type] : array_keys(ComponentSpecPaths::getAll());

        foreach ($types as $componentType) {
            foreach (self::recordsFor($componentType) as $record) {
                if ($record['spec_uuid'] === $specUuid) {
                    $rows = [$record];
                    self::attachCounts($pdo, $rows);
                    $rows[0]['specs'] = SpecRepository::getInstance()->find($componentType, $specUuid);
                    return $rows[0];
                }
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------------------

    /** @var array<string, array> per-request memo */
    private static $cache = [];

    /**
     * Search rows for one type: identity plus the fields a picker shows.
     *
     * Built from SpecProjector rather than from SpecRepository::allOfType(), because the
     * projector already produces display_name, brand, part_number and the indexed
     * projections, whereas the repository returns the raw enriched spec whose name key
     * differs per type. Same walk, same 378 records; see SpecProjector's docblock.
     */
    private static function recordsFor(string $componentType): array
    {
        if (isset(self::$cache[$componentType])) {
            return self::$cache[$componentType];
        }

        $rows = [];
        try {
            $records = SpecProjector::projectType($componentType, ComponentSpecPaths::getPath($componentType));
        } catch (Throwable $e) {
            error_log("ModelSearch: cannot read $componentType: " . $e->getMessage());
            return self::$cache[$componentType] = [];
        }

        foreach ($records as $r) {
            $rows[] = [
                'spec_uuid'      => $r['spec_uuid'],
                'component_type' => $r['component_type'],
                'model_name'     => $r['model_name'],
                'display_name'   => $r['display_name'],
                'brand'          => $r['brand'],
                'series'         => $r['series'],
                'part_number'    => $r['part_number'],
                'capacity_gb'    => $r['capacity_gb'],
                'form_factor'    => $r['form_factor'],
            ];
        }

        return self::$cache[$componentType] = $rows;
    }

    /**
     * Add total/available unit counts, one query per type rather than one per model.
     *
     * A missing or unreadable inventory table leaves the counts at zero instead of failing
     * the search: a type can be live in the vocabulary before its table has been seeded.
     */
    private static function attachCounts(PDO $pdo, array &$rows): void
    {
        $byType = [];
        foreach ($rows as $i => $row) {
            $byType[$row['component_type']][$row['spec_uuid']][] = $i;
        }

        foreach ($byType as $componentType => $uuidMap) {
            if (!preg_match('/^[a-z0-9]+$/i', $componentType)) {
                continue;
            }
            $table = $componentType . 'inventory';
            $uuids = array_keys($uuidMap);
            $placeholders = implode(',', array_fill(0, count($uuids), '?'));

            try {
                $stmt = $pdo->prepare(
                    "SELECT UUID,
                            COUNT(*) AS total,
                            SUM(CASE WHEN Status = 1 THEN 1 ELSE 0 END) AS available
                     FROM `$table` WHERE UUID IN ($placeholders) GROUP BY UUID"
                );
                $stmt->execute($uuids);
                $counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                continue; // table not seeded yet, or unreadable
            }

            $found = [];
            foreach ($counts as $c) {
                $found[$c['UUID']] = $c;
            }

            foreach ($uuidMap as $uuid => $indexes) {
                foreach ($indexes as $i) {
                    $rows[$i]['total_units'] = (int)($found[$uuid]['total'] ?? 0);
                    $rows[$i]['available'] = (int)($found[$uuid]['available'] ?? 0);
                }
            }
        }

        // Types whose query failed still need the keys, so the response shape is uniform.
        foreach ($rows as &$row) {
            $row['total_units'] = $row['total_units'] ?? 0;
            $row['available'] = $row['available'] ?? 0;
        }
        unset($row);
    }

    /** Case-folded, whitespace-collapsed. Hyphens kept: they are part of part numbers. */
    private static function normalise(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower($value)));
    }

    /**
     * 0 = no match. Higher is better. The tiers are wide apart so that a weaker tier can
     * never outrank a stronger one through the availability tie-break.
     */
    private static function score(string $needle, array $record): int
    {
        $best = 0;

        $fields = [
            'part_number'  => 100, // a part number is typed when it is known exactly
            'model_name'   => 90,
            'display_name' => 80,
            'brand'        => 40,
            'series'       => 30,
        ];

        foreach ($fields as $field => $weight) {
            $value = $record[$field] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            $haystack = self::normalise($value);

            if ($haystack === $needle) {
                $best = max($best, $weight + 1000);
                continue;
            }
            if (strncmp($haystack, $needle, strlen($needle)) === 0) {
                $best = max($best, $weight + 500);
                continue;
            }
            // Word boundary: "8480" should match "Platinum 8480+" ahead of an incidental
            // substring hit inside a longer token.
            if (preg_match('/(?:^|[^a-z0-9])' . preg_quote($needle, '/') . '/', $haystack)) {
                $best = max($best, $weight + 200);
                continue;
            }
            if (strpos($haystack, $needle) !== false) {
                $best = max($best, $weight);
            }
        }

        return $best;
    }
}
