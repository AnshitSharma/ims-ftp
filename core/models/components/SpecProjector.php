<?php
/**
 * Turns the ims-data JSON catalogue into flat, uniform model records. (audit Phase 2.2)
 *
 * This is the ONE place that knows how the twelve spec files are shaped. Everything
 * downstream -- the component_models projection, the model-search endpoint, the schemas --
 * consumes the uniform record this produces and never re-implements the traversal. The
 * audit's finding JSON-003 is that five separate resolvers each re-derive this; the fix
 * starts by there being something uniform to resolve TO.
 *
 * THE CORPUS IS NOT UNIFORM. Surveyed 2026-09-16 across all 12 files / 378 model objects:
 *
 *   nesting        10 types are a root ARRAY of brand groups each holding models[];
 *                  nic and sfp insert a series[] tier; chassis is an OBJECT wrapping
 *                  chassis_specifications.manufacturers[].series[].models[]; caddy is an
 *                  OBJECT wrapping a flat caddies[] with no brand tier at all.
 *   uuid key       `uuid` on 8 types, `UUID` on cpu, pciecard, risercard, hbacard.
 *   name key       `model` on 10 types; ram has NO `model` key and uses `label`; storage
 *                  carries `model` on only 46 of its 67 records.
 *   brand          lives on the PARENT group, not the model. Called `manufacturer` on
 *                  chassis. Entirely absent on caddy.
 *   series         a plain string on most groups, an object keyed `name` on nic/sfp, and
 *                  `series_name` on chassis.
 *
 * Rather than encode twelve nesting paths that drift the moment someone adds a tier, the
 * walk is generic: descend until a node carries a uuid, collecting brand/series context from
 * the ancestors on the way down.
 *
 * WHY THE WALK STOPS AT THE FIRST UUID
 *   A model object's children are its attributes, not more models -- with one exception that
 *   proves the rule. A serverplatform record embeds `system_board`, `chassis` and
 *   `included_storage_controller` objects that carry the SAME uuid as the loose motherboard /
 *   chassis / hbacard catalogue entry, because they are the same physical object described
 *   twice. Descending into them would produce 51 extra records that collide with the real
 *   ones on component_models.spec_uuid. Stopping at the first uuid is what makes the
 *   projection's UNIQUE constraint satisfiable, and it is why the 9 entries in
 *   tests/catalogue_uuid_gate.php's ALLOWED_DUPLICATES are allowed there but never appear
 *   here. 378 records in, 378 records out.
 *
 * Requires ComponentSpecPaths. No database, no cache, no side effects -- pure over the files,
 * so it is safe to call from a CLI build, a test, or a request.
 */

require_once __DIR__ . '/ComponentSpecPaths.php';

class SpecProjector
{
    /**
     * Keys on an ancestor node that describe the models beneath it. Collected on the way
     * down; a key set on the model itself always wins over an inherited one.
     */
    private const INHERITED_CONTEXT = [
        'brand'        => 'brand',
        'manufacturer' => 'brand',   // chassis spells it this way
        'series'       => 'series',
        'series_name'  => 'series',  // chassis series tier
        'name'         => 'series',  // nic / sfp series tier
        'category'     => 'category',
        'family'       => 'family',
        'generation'   => 'generation',
    ];

    /**
     * Project every component type. Returns [type => list<record>].
     *
     * @param array<string,string>|null $paths Override for tests; defaults to the real catalogue.
     * @return array<string, array<int, array>>
     */
    public static function projectAll(?array $paths = null): array
    {
        $paths = $paths ?? ComponentSpecPaths::getAll();
        $out = [];

        foreach ($paths as $type => $path) {
            $out[$type] = self::projectType($type, $path);
        }

        return $out;
    }

    /**
     * Project one spec file into flat model records.
     *
     * @throws RuntimeException if the file is missing or does not parse. A build that
     *         silently skips an unreadable file would quietly retire every model in it.
     * @return array<int, array>
     */
    public static function projectType(string $type, string $path): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException("Spec file for '$type' is not readable: " . basename($path));
        }

        $raw = file_get_contents($path);
        $blob = json_decode($raw, true);
        if (!is_array($blob)) {
            throw new RuntimeException(
                "Spec file for '$type' is not valid JSON (" . json_last_error_msg() . '): ' . basename($path)
            );
        }

        $records = [];
        self::walk($blob, $type, basename($path), [], '', $records);

        return $records;
    }

    /**
     * Descend until a uuid-bearing node is found, accumulating context.
     *
     * @param array  $node     current node
     * @param array  $context  inherited brand/series/... collected from ancestors
     * @param string $trail    dotted path, reported when a record is rejected
     */
    private static function walk(array $node, string $type, string $file, array $context, string $trail, array &$records): void
    {
        $uuid = self::firstString($node, ['uuid', 'UUID']);

        if ($uuid !== null) {
            $records[] = self::toRecord($node, $type, $file, $context, $trail, $uuid);
            return; // a model's children are attributes, not models -- see the docblock
        }

        // Not a model: absorb any context this node declares, then descend.
        $childContext = $context;
        foreach (self::INHERITED_CONTEXT as $key => $slot) {
            if (isset($node[$key]) && is_string($node[$key]) && $node[$key] !== '') {
                $childContext[$slot] = $node[$key];
            }
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                self::walk($value, $type, $file, $childContext, $trail === '' ? (string)$key : $trail . '.' . $key, $records);
            }
        }
    }

    /** Build the uniform record for one model object. */
    private static function toRecord(array $node, string $type, string $file, array $context, string $trail, string $uuid): array
    {
        // A model-level brand/series overrides the inherited one. chassis models carry both.
        $brand  = self::firstString($node, ['brand', 'manufacturer']) ?? ($context['brand'] ?? null);
        $series = self::firstString($node, ['series']) ?? ($context['series'] ?? null);

        $modelName = self::modelName($type, $node);

        return [
            'spec_uuid'      => $uuid,
            'component_type' => $type,
            'model_name'     => $modelName,
            'display_name'   => self::displayName($brand, $modelName),
            'brand'          => $brand,
            'series'         => $series,
            'part_number'    => self::firstString($node, ['part_number', 'partNumber', 'sku']),

            'capacity_gb'    => self::capacityGb($node),
            'socket'         => self::scalarOrFirst($node, ['socket']),
            'form_factor'    => self::scalarOrFirst($node, ['form_factor', 'module_type']),
            'interface'      => self::scalarOrFirst($node, ['interface', 'host_interface', 'port_type']),
            'memory_type'    => self::scalarOrFirst($node, ['memory_type']),
            'tdp_w'          => self::intOrNull($node, ['tdp_W', 'tdp_w', 'tdp']),
            'ports'          => self::intOrNull($node, ['ports', 'internal_ports']),

            'specs'          => $node,
            'source_file'    => $file,
            'source_trail'   => $trail,
        ];
    }

    /**
     * The catalogue name. ram has no `model` key and 21 storage records omit theirs, so this
     * falls through a chain rather than assuming one key -- the same chain, in the same
     * order, that ComponentNamer uses for the list endpoints. If the two ever disagree the
     * picker and the inventory list start showing different names for one part.
     */
    private static function modelName(string $type, array $node): string
    {
        $direct = self::firstString($node, ['model', 'label', 'name', 'model_name', 'product_name']);
        if ($direct !== null) {
            return $direct;
        }

        // Nothing nameable. Synthesise from the attributes that identify the part, which is
        // what an operator would read off the label anyway.
        if ($type === 'ram') {
            $bits = array_filter([
                self::scalarOrFirst($node, ['memory_type']),
                self::scalarOrFirst($node, ['module_type']),
                isset($node['capacity_GB']) ? $node['capacity_GB'] . 'GB' : null,
                isset($node['frequency_MHz']) ? $node['frequency_MHz'] . 'MHz' : null,
            ]);
            if ($bits) {
                return implode(' ', $bits);
            }
        }

        if ($type === 'storage') {
            $bits = array_filter([
                self::scalarOrFirst($node, ['storage_type', 'subtype']),
                self::scalarOrFirst($node, ['interface']),
                isset($node['capacity_GB']) ? self::humanCapacity((int)$node['capacity_GB']) : null,
            ]);
            if ($bits) {
                return implode(' ', $bits);
            }
        }

        // Last resort: never return '' -- model_name is NOT NULL and a blank name in a picker
        // is indistinguishable from a broken row.
        return ucfirst($type) . ' ' . substr(self::firstString($node, ['uuid', 'UUID']) ?? '', 0, 8);
    }

    /** "Brand Model", without repeating a brand the model name already carries. */
    private static function displayName(?string $brand, string $modelName): string
    {
        if ($brand === null || $brand === '') {
            return $modelName;
        }
        if (stripos($modelName, $brand) !== false) {
            return $modelName;
        }
        return $brand . ' ' . $modelName;
    }

    /** GB as an int. Handles capacity_TB records by converting; 1000, not 1024 (vendor convention). */
    private static function capacityGb(array $node): ?int
    {
        foreach (['capacity_GB', 'capacity_gb', 'kit_capacity_GB'] as $key) {
            if (isset($node[$key]) && is_numeric($node[$key])) {
                return (int)$node[$key];
            }
        }
        foreach (['capacity_TB', 'total_max_capacity_TB', 'max_capacity_TB'] as $key) {
            if (isset($node[$key]) && is_numeric($node[$key])) {
                return (int)round($node[$key] * 1000);
            }
        }
        return null;
    }

    private static function humanCapacity(int $gb): string
    {
        return $gb >= 1000 ? rtrim(rtrim(number_format($gb / 1000, 1, '.', ''), '0'), '.') . 'TB' : $gb . 'GB';
    }

    /** First key present as a non-empty string. */
    private static function firstString(array $node, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($node[$key]) && is_string($node[$key]) && trim($node[$key]) !== '') {
                return trim($node[$key]);
            }
        }
        return null;
    }

    /**
     * Same, but tolerates the field being a LIST. `socket` is a string on most boards and an
     * array on a few; `memory_types` is always a list. Projecting the first element keeps the
     * column filterable without pretending the list is not there -- `specs` still has all of it.
     */
    private static function scalarOrFirst(array $node, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!isset($node[$key])) {
                continue;
            }
            $value = $node[$key];
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_numeric($value)) {
                return (string)$value;
            }
            if (is_array($value) && $value !== []) {
                $first = reset($value);
                if (is_string($first) && trim($first) !== '') {
                    return trim($first);
                }
                if (is_numeric($first)) {
                    return (string)$first;
                }
            }
        }
        return null;
    }

    /** Leading integer of a numeric-ish field. "165W" and 165 both give 165. */
    private static function intOrNull(array $node, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (!isset($node[$key])) {
                continue;
            }
            $value = $node[$key];
            if (is_int($value)) {
                return $value;
            }
            if (is_float($value)) {
                return (int)round($value);
            }
            if (is_string($value) && preg_match('/-?\d+(\.\d+)?/', $value, $m)) {
                return (int)round((float)$m[0]);
            }
        }
        return null;
    }

    /**
     * Checksum of one model object, stable across key order and whitespace.
     *
     * Over the model alone, not the file: an edit to one record must not churn the other 377
     * rows, or "what changed since the last build" stops being answerable.
     */
    public static function checksum(array $specs): string
    {
        $canonical = $specs;
        self::ksortRecursive($canonical);
        return sha1(json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function ksortRecursive(array &$node): void
    {
        foreach ($node as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
        unset($value);

        // Only sort associative nodes. Sorting a list would reorder, say, memory channels.
        if (array_keys($node) !== range(0, count($node) - 1)) {
            ksort($node);
        }
    }
}
