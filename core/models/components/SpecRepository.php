<?php
/**
 * One resolver for component specs. (audit Phase 2.4 / JSON-003)
 *
 * THE PROBLEM IT REPLACES
 *   Five independent resolvers each re-derive "given a type and a uuid, give me the spec":
 *
 *     ComponentDataService        a uuid index, plus a linear fallback that re-implements
 *                                 the same traversal a second time inside one class
 *     DataExtractionUtilities     static cache, LINEAR, no index -- and it is the one every
 *                                 validation rule goes through, so the hot path is the slow one
 *     ComponentDataLoader         three uncached paths doing json_decode(file_get_contents())
 *                                 per invocation on 102 KB and 147 KB files, called from
 *                                 ComponentCompatibility in six places
 *     ChassisManager              per-instance cache, 1 hour TTL
 *     PlatformSpecIndex           static, and the one that is already shared
 *
 *   They do not merely duplicate work -- they disagree. PlatformSpecIndex exists because two
 *   of them disagreed about whether a board inside a server platform is findable, and adding
 *   any component to a platform build died with "Motherboard spec not found" while the same
 *   board as a loose spare validated fine. Its docblock records that outage.
 *
 * WHAT THIS IS
 *   One uuid index over the whole catalogue, built once through SpecProjector -- which is
 *   also what builds component_models, so the projection and the runtime resolver cannot
 *   drift from each other by construction. Backed by component_models when that table is
 *   present, and by the files otherwise.
 *
 * THE RETURN SHAPE IS NOT NEW. find() reproduces ComponentDataService::findComponentByUuid()
 * exactly, including its per-type enrichment quirks (caddy gets `component_type`, chassis gets
 * `manufacturer`, everything else gets `brand`/`series`/`family`/`component_subtype`). That is
 * deliberate: the point of the collapse is that the four other resolvers become thin adapters
 * over this one, and an adapter that returns a different shape is a rewrite of every call
 * site, not an adapter. tests/spec_repository_equivalence.php holds the proof across all 378
 * models. When the shape is eventually tidied, it is tidied once, here.
 *
 * NOT YET WIRED INTO ANY CALL SITE. Landing the repository and re-pointing the resolvers are
 * separate changes on purpose -- every file save here is a live deploy, so a change that both
 * introduces a resolver and moves the validation engine onto it has no safe intermediate
 * state. See the plan: "Ship adapter-by-adapter, verifying a build validation after each."
 */

require_once __DIR__ . '/ComponentSpecPaths.php';
require_once __DIR__ . '/SpecProjector.php';
require_once __DIR__ . '/PlatformSpecIndex.php';

class SpecRepository
{
    /** @var SpecRepository|null */
    private static $instance = null;

    /** @var array<string, array<string, array>> type => uuid => enriched spec */
    private $index = [];

    /** @var array<string, bool> types whose index has been built */
    private $loaded = [];

    /** @var PDO|null set only when component_models is usable */
    private $pdo = null;

    /** @var bool|null null = not yet probed */
    private $tableUsable = null;

    private function __construct() {}

    public static function getInstance(): SpecRepository
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * The spec for one uuid, or null.
     *
     * Platform-owned boards and chassis are checked FIRST, exactly as ComponentDataService
     * does: a board inside a server platform is described in the platform file, has no
     * inventory row of its own, and must still resolve under type 'motherboard'.
     */
    public function find(string $componentType, string $uuid)
    {
        if ($uuid === '') {
            return null;
        }

        $platformOwned = PlatformSpecIndex::find($componentType, $uuid);
        if ($platformOwned !== null) {
            return $platformOwned;
        }

        $this->ensureLoaded($componentType);

        return $this->index[$componentType][$uuid] ?? null;
    }

    /** Every spec of one type, keyed by uuid. */
    public function allOfType(string $componentType): array
    {
        $this->ensureLoaded($componentType);
        return $this->index[$componentType] ?? [];
    }

    /** Does this uuid exist for this type? The check validateComponentUuid() needs. */
    public function exists(string $componentType, string $uuid): bool
    {
        return $this->find($componentType, $uuid) !== null;
    }

    /** Drop the in-memory index. For tests and for after a spec_build. */
    public function clearCache(): void
    {
        $this->index = [];
        $this->loaded = [];
        $this->tableUsable = null;
    }

    // -------------------------------------------------------------------------------------

    private function ensureLoaded(string $componentType): void
    {
        if (isset($this->loaded[$componentType])) {
            return;
        }
        $this->loaded[$componentType] = true;
        $this->index[$componentType] = [];

        if ($this->loadFromDatabase($componentType)) {
            return;
        }

        $this->loadFromFiles($componentType);
    }

    /**
     * Read the type's models out of component_models.
     *
     * Returns false -- silently, and without touching the index -- whenever the table is
     * absent, empty for this type, or unreadable. Falling back to the files always produces
     * the right answer; the table is an accelerator, never the only source of truth. That
     * asymmetry is what makes it safe for this code to deploy before the seeder runs.
     */
    private function loadFromDatabase(string $componentType): bool
    {
        if ($this->pdo === null || $this->tableUsable === false) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT spec_uuid, specs, brand, series, family, component_subtype
                 FROM component_models WHERE component_type = ? AND is_retired = 0'
            );
            $stmt->execute([$componentType]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->tableUsable = false;
            return false;
        }

        $this->tableUsable = true;

        if (!$rows) {
            // The table exists but this type has not been projected yet -- a build that has
            // not been run since the type was added. The files are still correct.
            return false;
        }

        foreach ($rows as $row) {
            $specs = json_decode($row['specs'], true);
            if (!is_array($specs)) {
                continue;
            }
            // Every context key enrich() merges has to come back out of the row, or a
            // database-backed load answers differently from a file-backed one and the
            // resolver's behaviour depends on whether spec_build has been run. That is not a
            // hypothetical: family differed on 142 specs and component_subtype on 57 before
            // these two columns existed.
            $this->index[$componentType][$row['spec_uuid']] = $this->enrich(
                $componentType,
                $row['spec_uuid'],
                $specs,
                [
                    'brand'             => $row['brand'],
                    'series'            => $row['series'],
                    'family'            => $row['family'],
                    'component_subtype' => $row['component_subtype'],
                ]
            );
        }

        return true;
    }

    private function loadFromFiles(string $componentType): void
    {
        try {
            $path = ComponentSpecPaths::getPath($componentType);
            $records = SpecProjector::projectType($componentType, $path);
        } catch (Throwable $e) {
            // An unreadable or malformed spec file must not take the API down. Every caller
            // treats a null spec as "no constraint", which is the same degradation the five
            // resolvers this replaces already had.
            error_log('SpecRepository: cannot load ' . $componentType . ': ' . $e->getMessage());
            return;
        }

        foreach ($records as $record) {
            $this->index[$componentType][$record['spec_uuid']] =
                $this->enrich($componentType, $record['spec_uuid'], $record['specs'], $record['context']);
        }
    }

    /**
     * Merge group-tier context into the model object.
     *
     * This reproduces ComponentDataService::findComponentByUuidIndexed()'s per-type merges
     * exactly, quirks included. They are not consistent with each other -- caddy attaches
     * `component_type` and nothing else, chassis calls the vendor `manufacturer` where every
     * other type calls it `brand` -- but they are what the callers read today, and changing
     * them here would be a silent behaviour change in the validation engine.
     */
    private function enrich(string $componentType, string $uuid, array $specs, array $context): array
    {
        switch ($componentType) {
            case 'caddy':
                return array_merge($specs, [
                    'uuid' => $uuid,
                    'component_type' => 'caddy',
                ]);

            case 'chassis':
                return array_merge($specs, [
                    'uuid' => $uuid,
                    'manufacturer' => $context['brand'] ?? null,
                    'series' => $context['series'] ?? null,
                ]);

            case 'nic':
            case 'sfp':
                return array_merge($specs, [
                    'uuid' => $uuid,
                    'brand' => $context['brand'] ?? null,
                    'series' => $context['series'] ?? null,
                ]);

            default:
                return array_merge($specs, [
                    'uuid' => $uuid,
                    'brand' => $context['brand'] ?? null,
                    'series' => $context['series'] ?? null,
                    'family' => $context['family'] ?? null,
                    'component_subtype' => $context['component_subtype'] ?? null,
                ]);
        }
    }
}
