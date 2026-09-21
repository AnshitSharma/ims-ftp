<?php
/**
 * Validates projected spec records against the per-type schemas. (audit Phase 2.3 / JSON-006)
 *
 * WHY THIS EXISTS
 *   Until now nothing described what a spec must contain. The consequence was not theoretical:
 *   the cpu memory rule read `compatibility.memory_types`, a key no CPU record has ever had,
 *   and so the constraint silently skipped on every CPU for months. A missing key and a key
 *   that is merely absent from one record are indistinguishable at runtime -- both arrive as
 *   null, and every rule treats null as "no constraint, pass".
 *
 *   A schema turns that silence into a message naming the file and the record.
 *
 * WHERE THE SCHEMAS LIVE
 *   schemas/{type}.schema.json, NEXT TO THIS FILE rather than in ims-data. ims-data has no
 *   deploy watcher, so a schema shipped there would arrive only when someone remembered to
 *   upload it, and a validator whose rules are missing is a validator that passes everything.
 *   The schemas describe the data but they are code, and they deploy with the code.
 *
 * WHAT THE SCHEMAS ENCODE
 *   Only what the corpus actually supports, measured across all 378 records on 2026-09-16,
 *   not what a tidy catalogue would look like. Where a field genuinely varies in type the
 *   schema says so and the variance is recorded as debt in the schema's own `$comment`.
 *   A schema that failed most of the corpus on day one would be turned off on day two.
 *
 * VIOLATIONS WARN, THEY DO NOT BLOCK. spec_build reports them and still writes the table:
 *   the known inconsistencies (width/height as string-with-units vs float vs int, `slot` as
 *   both a name and an index) are exactly what the table makes tractable to reconcile, so
 *   refusing to build until they are gone would be circular.
 *
 * This is a deliberately small subset of JSON Schema -- type, required, properties, items,
 * enum, pattern, ranges, additionalProperties. There is no Composer in this project and one
 * is not being introduced for this. Anything the subset cannot express is better written as
 * an explicit check than as a dependency.
 */

class SpecValidator
{
    private static $schemas = null;

    /**
     * Validate every projected record.
     *
     * @param array<string, array<int,array>> $projected output of SpecProjector::projectAll()
     * @return array<int,array>|null  list of violations, or NULL if no schemas are installed
     *                                (the caller distinguishes "clean" from "not checked")
     */
    public static function validateAll(array $projected): ?array
    {
        $schemas = self::schemas();
        if (!$schemas) {
            return null;
        }

        $violations = [];
        foreach ($projected as $type => $records) {
            if (!isset($schemas[$type])) {
                continue; // a type with no schema yet is not a failure
            }
            foreach ($records as $record) {
                foreach (self::check($record['specs'], $schemas[$type], '') as $message) {
                    $violations[] = [
                        'component_type' => $type,
                        'spec_uuid'      => $record['spec_uuid'],
                        'display_name'   => $record['display_name'],
                        'source_file'    => $record['source_file'],
                        'message'        => $message,
                    ];
                }
            }
        }

        return $violations;
    }

    /** @return array<string,array> type => decoded schema */
    private static function schemas(): array
    {
        if (self::$schemas !== null) {
            return self::$schemas;
        }

        self::$schemas = [];
        $dir = __DIR__ . '/schemas';
        if (!is_dir($dir)) {
            return self::$schemas;
        }

        foreach (glob($dir . '/*.schema.json') ?: [] as $file) {
            $type = basename($file, '.schema.json');
            $decoded = json_decode(file_get_contents($file), true);
            if (is_array($decoded)) {
                self::$schemas[$type] = $decoded;
            }
            // A schema that does not parse is skipped rather than fatal: this runs inside a
            // request path as well as the CLI build, and a typo in a schema must not 500 the API.
        }

        return self::$schemas;
    }

    /**
     * The validator proper. Returns a list of human-readable messages.
     *
     * @param mixed  $value
     * @param array  $schema
     * @param string $path  dotted location, so a message can name the field
     */
    private static function check($value, array $schema, string $path): array
    {
        $errors = [];
        $where = $path === '' ? '' : "$path: ";

        // -- type ---------------------------------------------------------------------------
        if (isset($schema['type'])) {
            $allowed = (array)$schema['type'];
            if (!self::matchesAnyType($value, $allowed)) {
                return [$where . 'expected ' . implode('|', $allowed) . ', got ' . self::typeOf($value)];
            }
        }

        if ($value === null) {
            return $errors; // null passed the type check above; nothing further applies
        }

        // -- enum ---------------------------------------------------------------------------
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[] = $where . 'value ' . self::brief($value) . ' is not one of '
                . implode(', ', array_map([self::class, 'brief'], $schema['enum']));
        }

        // -- strings ------------------------------------------------------------------------
        if (is_string($value)) {
            if (isset($schema['pattern']) && !preg_match('/' . str_replace('/', '\/', $schema['pattern']) . '/', $value)) {
                $errors[] = $where . self::brief($value) . ' does not match ' . $schema['pattern'];
            }
            if (isset($schema['minLength']) && strlen($value) < $schema['minLength']) {
                $errors[] = $where . 'shorter than minLength ' . $schema['minLength'];
            }
        }

        // -- numbers ------------------------------------------------------------------------
        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = $where . "$value is below minimum {$schema['minimum']}";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = $where . "$value is above maximum {$schema['maximum']}";
            }
        }

        // -- arrays -------------------------------------------------------------------------
        if (self::isList($value)) {
            if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
                $errors[] = $where . 'has ' . count($value) . ' items, needs ' . $schema['minItems'];
            }
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $i => $item) {
                    $errors = array_merge($errors, self::check($item, $schema['items'], self::join($path, (string)$i)));
                }
            }
        }

        // -- objects ------------------------------------------------------------------------
        if (is_array($value) && !self::isList($value)) {
            foreach ($schema['required'] ?? [] as $key) {
                if (!array_key_exists($key, $value)) {
                    $errors[] = $where . "missing required key '$key'";
                }
            }

            $properties = $schema['properties'] ?? [];
            foreach ($properties as $key => $sub) {
                if (array_key_exists($key, $value) && is_array($sub)) {
                    $errors = array_merge($errors, self::check($value[$key], $sub, self::join($path, $key)));
                }
            }

            // additionalProperties:false catches a typo'd key, which is the failure mode that
            // produced the cpu.memory_types outage -- a key nothing reads looks identical to a
            // key nothing wrote.
            if (($schema['additionalProperties'] ?? true) === false) {
                foreach (array_keys($value) as $key) {
                    if (!isset($properties[$key])) {
                        $errors[] = $where . "unexpected key '$key'";
                    }
                }
            }
        }

        return $errors;
    }

    private static function matchesAnyType($value, array $allowed): bool
    {
        foreach ($allowed as $type) {
            switch ($type) {
                case 'null':    if ($value === null) return true; break;
                case 'string':  if (is_string($value)) return true; break;
                case 'boolean': if (is_bool($value)) return true; break;
                case 'integer': if (is_int($value)) return true; break;
                case 'number':  if (is_int($value) || is_float($value)) return true; break;
                case 'array':   if (self::isList($value)) return true; break;
                case 'object':  if (is_array($value) && !self::isList($value)) return true; break;
            }
        }
        return false;
    }

    private static function typeOf($value): string
    {
        if ($value === null) return 'null';
        if (is_bool($value)) return 'boolean';
        if (is_int($value)) return 'integer';
        if (is_float($value)) return 'number';
        if (is_string($value)) return 'string';
        return self::isList($value) ? 'array' : 'object';
    }

    private static function isList($value): bool
    {
        return is_array($value) && ($value === [] || array_keys($value) === range(0, count($value) - 1));
    }

    private static function join(string $path, string $key): string
    {
        return $path === '' ? $key : $path . '.' . $key;
    }

    private static function brief($value): string
    {
        if (is_string($value)) return "'" . (strlen($value) > 30 ? substr($value, 0, 27) . '...' : $value) . "'";
        if (is_bool($value)) return $value ? 'true' : 'false';
        if ($value === null) return 'null';
        if (is_array($value)) return self::isList($value) ? '[' . count($value) . ' items]' : '{object}';
        return (string)$value;
    }
}
