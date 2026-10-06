<?php
/**
 * Inventory list filters (2026-10-06, tasks/inventory-filters-import.md).
 *
 * Two kinds, applied together:
 *   - SPEC filters: what a unit IS -- brand, socket, capacity... That lives in ims-data,
 *     not on the row, so a choice resolves to `UUID IN (...)` over the matching catalogue
 *     models, the same mechanism model search uses in buildComponentSearchWhere().
 *   - INVENTORY filters: what is recorded ON the unit -- vendor, flag, warranty end date.
 *     Those are plain column predicates.
 *
 * Applied server-side through buildComponentSearchWhere(), so the row query and the count
 * query agree by construction. Filtering only the loaded page in the browser is the bug
 * fixed on 2026-09-16 and must not come back.
 *
 * Wire format: one `filters` field holding JSON, {key: [value, ...]}. Values within one key
 * are OR'd; keys are AND'd. Unknown keys and unknown values are IGNORED, like the status
 * param: a stale shared link shows more rows, never a mysteriously empty page.
 */

require_once __DIR__ . '/../models/components/SpecProjector.php';
require_once __DIR__ . '/../models/components/ComponentSpecPaths.php';

class InventoryFilters
{
    /**
     * Spec filters per type, in display order. `field` is a SpecProjector column; `path`
     * is a dotted path into the model's own spec object, for values the projector does not
     * lift out (motherboard socket.type, server platform chassis.form_factor).
     *
     * Chosen from the catalogue as it stood on 2026-10-06. A filter whose every model shares
     * one value (RAM form factor, PCIe and riser subtype) filters nothing and is left out.
     * networkdevice has no entry: its catalogue file is empty.
     */
    private const SPEC = [
        'cpu' => [
            'brand'  => ['label' => 'Brand',  'field' => 'brand'],
            'series' => ['label' => 'Series', 'field' => 'series'],
            'socket' => ['label' => 'Socket', 'field' => 'socket'],
        ],
        'ram' => [
            'brand'       => ['label' => 'Brand',       'field' => 'brand'],
            'memory_type' => ['label' => 'Memory type', 'field' => 'memory_type'],
            'capacity_gb' => ['label' => 'Capacity',    'field' => 'capacity_gb', 'format' => 'capacity'],
        ],
        'storage' => [
            'brand'       => ['label' => 'Brand',       'field' => 'brand'],
            'drive_type'  => ['label' => 'Drive type',  'path'  => 'subtype'],
            'capacity_gb' => ['label' => 'Capacity',    'field' => 'capacity_gb', 'format' => 'capacity'],
            'form_factor' => ['label' => 'Form factor', 'field' => 'form_factor'],
            'interface'   => ['label' => 'Interface',   'field' => 'interface'],
        ],
        'motherboard' => [
            'brand'       => ['label' => 'Brand',       'field' => 'brand'],
            'socket'      => ['label' => 'Socket',      'path'  => 'socket.type'],
            'form_factor' => ['label' => 'Form factor', 'field' => 'form_factor'],
        ],
        'nic' => [
            'brand'     => ['label' => 'Brand',     'field' => 'brand'],
            'ports'     => ['label' => 'Ports',     'field' => 'ports', 'format' => 'ports'],
            'interface' => ['label' => 'Interface', 'field' => 'interface'],
        ],
        'hbacard' => [
            'brand'     => ['label' => 'Brand',     'field' => 'brand'],
            'ports'     => ['label' => 'Ports',     'field' => 'ports', 'format' => 'ports'],
            'interface' => ['label' => 'Interface', 'field' => 'interface'],
        ],
        'pciecard' => [
            'brand'       => ['label' => 'Brand',       'field' => 'brand'],
            'form_factor' => ['label' => 'Form factor', 'field' => 'form_factor'],
            'interface'   => ['label' => 'Interface',   'field' => 'interface'],
        ],
        'risercard' => [
            'brand'       => ['label' => 'Brand',       'field' => 'brand'],
            'form_factor' => ['label' => 'Form factor', 'field' => 'form_factor'],
            'interface'   => ['label' => 'Interface',   'field' => 'interface'],
        ],
        'caddy' => [
            // brand is set on only 4 of 20 caddies, so it would hide most of them.
            'size'       => ['label' => 'Drive size', 'path' => 'compatibility.size'],
            'caddy_type' => ['label' => 'Caddy type', 'path' => 'type'],
        ],
        'chassis' => [
            'brand'        => ['label' => 'Brand',        'field' => 'brand'],
            'size'         => ['label' => 'Size',         'field' => 'form_factor'],
            'chassis_type' => ['label' => 'Chassis type', 'path'  => 'chassis_type'],
        ],
        'sfp' => [
            'brand'       => ['label' => 'Brand',       'field' => 'brand'],
            'module_type' => ['label' => 'Module type', 'path'  => 'type'],
            'fiber_type'  => ['label' => 'Fibre',       'path'  => 'fiber_type'],
        ],
        'serverplatform' => [
            'brand'  => ['label' => 'Brand',  'field' => 'brand'],
            'series' => ['label' => 'Series', 'field' => 'series'],
            'size'   => ['label' => 'Size',   'path'  => 'chassis.form_factor'],
        ],
    ];

    /** Keys that filter columns on the row rather than the catalogue. */
    const INVENTORY_KEYS = ['vendor', 'flag', 'warranty'];

    /** Disjoint buckets, so the four counts add up to the whole list. */
    const WARRANTY = [
        'active'   => 'In warranty',
        'expiring' => 'Expires within 90 days',
        'expired'  => 'Expired',
        'none'     => 'Not recorded',
    ];

    /** @var array<string, array> per-request memo of uuid => [key => value] */
    private static $valueCache = [];

    /**
     * Normalise the raw `filters` param for one type. Accepts the JSON string or an array.
     * Anything malformed collapses to "no filter" rather than an error.
     *
     * @return array<string, string[]>
     */
    public static function parse($raw, string $type): array
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                return [];
            }
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }

        $allowed = array_merge(array_keys(self::SPEC[$type] ?? []), self::INVENTORY_KEYS);
        $out = [];
        foreach ($raw as $key => $values) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                continue;
            }
            if (!is_array($values)) {
                $values = [$values];
            }
            $clean = [];
            foreach ($values as $value) {
                if (!is_scalar($value) || is_bool($value)) {
                    continue;
                }
                $value = trim((string)$value);
                if ($value !== '' && strlen($value) <= 200) {
                    $clean[$value] = true;
                }
            }
            if ($clean) {
                $out[$key] = array_slice(array_map('strval', array_keys($clean)), 0, 100);
            }
        }
        return $out;
    }

    /**
     * WHERE fragments for parsed filters. Returns [clauses, params] instead of appending to
     * the caller's params, so a failure part-way cannot leave placeholders and values out of
     * step.
     *
     * @return array{0: string[], 1: array}
     */
    public static function clauses(PDO $pdo, string $table, string $type, array $filters): array
    {
        $clauses = [];
        $params = [];

        foreach ($filters as $key => $values) {
            if (isset(self::SPEC[$type][$key])) {
                $uuids = [];
                foreach (self::modelValues($type) as $uuid => $vals) {
                    if (isset($vals[$key]) && in_array($vals[$key], $values, true)) {
                        $uuids[] = $uuid;
                    }
                }
                // Every value the panel offers names at least one catalogue model, so no
                // match means none of these values exist: ignore, like an unknown status.
                if ($uuids) {
                    $clauses[] = 'UUID IN (' . implode(',', array_fill(0, count($uuids), '?')) . ')';
                    $params = array_merge($params, $uuids);
                }
                continue;
            }

            if ($key === 'vendor' && SchemaHelper::hasColumn($pdo, $table, 'VendorID')) {
                $ids = [];
                $none = false;
                foreach ($values as $value) {
                    if ($value === 'none') {
                        $none = true;
                    } elseif (ctype_digit($value)) {
                        $ids[] = (int)$value;
                    }
                }
                $or = [];
                if ($ids) {
                    $or[] = 'VendorID IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                    $params = array_merge($params, $ids);
                }
                if ($none) {
                    $or[] = '(VendorID IS NULL OR VendorID = 0)';
                }
                if ($or) {
                    $clauses[] = '(' . implode(' OR ', $or) . ')';
                }
            } elseif ($key === 'flag' && SchemaHelper::hasColumn($pdo, $table, 'Flag')) {
                $names = [];
                $none = false;
                foreach ($values as $value) {
                    if ($value === 'none') {
                        $none = true;
                    } else {
                        $names[] = $value;
                    }
                }
                $or = [];
                if ($names) {
                    $or[] = 'Flag IN (' . implode(',', array_fill(0, count($names), '?')) . ')';
                    $params = array_merge($params, $names);
                }
                if ($none) {
                    $or[] = "(Flag IS NULL OR Flag = '')";
                }
                if ($or) {
                    $clauses[] = '(' . implode(' OR ', $or) . ')';
                }
            } elseif ($key === 'warranty' && SchemaHelper::hasColumn($pdo, $table, 'WarrantyEndDate')) {
                $or = [];
                foreach ($values as $value) {
                    if (isset(self::WARRANTY[$value])) {
                        $or[] = self::warrantyPredicate($value);
                    }
                }
                if ($or) {
                    $clauses[] = '(' . implode(' OR ', $or) . ')';
                }
            }
        }

        return [$clauses, $params];
    }

    /**
     * The filter panel's contents: every filter for the type with its values and unit
     * counts over the rows $where selects (search, site and status, but not the filters
     * themselves, so choosing one value never hides its siblings). Spec values that no
     * stocked unit has are not offered.
     */
    public static function options(PDO $pdo, string $type, string $table, string $where, array $params): array
    {
        $groups = [];

        $specDefs = self::SPEC[$type] ?? [];
        if ($specDefs) {
            $stmt = $pdo->prepare("SELECT UUID, COUNT(*) FROM `$table` $where GROUP BY UUID");
            $stmt->execute($params);
            $byUuid = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $models = self::modelValues($type);

            foreach ($specDefs as $key => $def) {
                $counts = [];
                foreach ($byUuid as $uuid => $n) {
                    $value = $models[$uuid][$key] ?? null;
                    if ($value !== null) {
                        $counts[$value] = ($counts[$value] ?? 0) + (int)$n;
                    }
                }
                $options = [];
                foreach ($counts as $value => $n) {
                    $value = (string)$value; // numeric-string keys come back as ints
                    $options[] = ['value' => $value, 'label' => self::label($def, $value), 'count' => $n];
                }
                $numeric = isset($def['format']);
                usort($options, static function ($a, $b) use ($numeric) {
                    return $numeric
                        ? ((float)$a['value'] <=> (float)$b['value'])
                        : strnatcasecmp($a['label'], $b['label']);
                });
                $groups[] = ['key' => $key, 'label' => $def['label'], 'kind' => 'spec', 'options' => $options];
            }
        }

        if (SchemaHelper::hasColumn($pdo, $table, 'VendorID')) {
            $stmt = $pdo->prepare("SELECT VendorID, COUNT(*) FROM `$table` $where GROUP BY VendorID");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);

            $ids = [];
            foreach ($rows as $row) {
                if ((int)$row[0] > 0) {
                    $ids[] = (int)$row[0];
                }
            }
            $names = [];
            if ($ids) {
                try {
                    $vstmt = $pdo->prepare('SELECT id, name FROM vendors WHERE id IN ('
                        . implode(',', array_fill(0, count($ids), '?')) . ')');
                    $vstmt->execute($ids);
                    $names = $vstmt->fetchAll(PDO::FETCH_KEY_PAIR);
                } catch (Throwable $e) {
                    error_log('InventoryFilters: vendor names unavailable: ' . $e->getMessage());
                }
            }

            $options = [];
            $noVendor = 0;
            foreach ($rows as $row) {
                $id = (int)$row[0];
                if ($id > 0) {
                    $options[] = [
                        'value' => (string)$id,
                        'label' => isset($names[$id]) ? (string)$names[$id] : "Vendor #$id",
                        'count' => (int)$row[1],
                    ];
                } else {
                    $noVendor += (int)$row[1];
                }
            }
            usort($options, static function ($a, $b) {
                return strnatcasecmp($a['label'], $b['label']);
            });
            if ($noVendor > 0) {
                $options[] = ['value' => 'none', 'label' => 'No vendor', 'count' => $noVendor];
            }
            $groups[] = ['key' => 'vendor', 'label' => 'Vendor', 'kind' => 'inventory', 'options' => $options];
        }

        if (SchemaHelper::hasColumn($pdo, $table, 'Flag')) {
            $stmt = $pdo->prepare("SELECT Flag, COUNT(*) FROM `$table` $where GROUP BY Flag");
            $stmt->execute($params);

            $options = [];
            $noFlag = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
                $flag = trim((string)$row[0]);
                if ($flag === '') {
                    $noFlag += (int)$row[1];
                } else {
                    $options[] = ['value' => $flag, 'label' => $flag, 'count' => (int)$row[1]];
                }
            }
            usort($options, static function ($a, $b) {
                return strnatcasecmp($a['label'], $b['label']);
            });
            if ($noFlag > 0) {
                $options[] = ['value' => 'none', 'label' => 'No flag', 'count' => $noFlag];
            }
            $groups[] = ['key' => 'flag', 'label' => 'Flag', 'kind' => 'inventory', 'options' => $options];
        }

        if (SchemaHelper::hasColumn($pdo, $table, 'WarrantyEndDate')) {
            $sums = [];
            foreach (array_keys(self::WARRANTY) as $bucket) {
                $sums[] = 'SUM(CASE WHEN ' . self::warrantyPredicate($bucket) . " THEN 1 ELSE 0 END) AS `$bucket`";
            }
            $stmt = $pdo->prepare('SELECT ' . implode(', ', $sums) . " FROM `$table` $where");
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $options = [];
            foreach (self::WARRANTY as $bucket => $label) {
                $options[] = ['value' => $bucket, 'label' => $label, 'count' => (int)($row[$bucket] ?? 0)];
            }
            $groups[] = ['key' => 'warranty', 'label' => 'Warranty', 'kind' => 'inventory', 'options' => $options];
        }

        return $groups;
    }

    // -------------------------------------------------------------------------------------

    /** Fixed SQL per bucket. No input reaches these strings. */
    private static function warrantyPredicate(string $bucket): string
    {
        switch ($bucket) {
            case 'active':
                return '(WarrantyEndDate > DATE_ADD(CURDATE(), INTERVAL 90 DAY))';
            case 'expiring':
                return '(WarrantyEndDate >= CURDATE() AND WarrantyEndDate <= DATE_ADD(CURDATE(), INTERVAL 90 DAY))';
            case 'expired':
                return '(WarrantyEndDate < CURDATE())';
            default:
                return '(WarrantyEndDate IS NULL)';
        }
    }

    /**
     * uuid => [filter key => value] for every catalogue model of $type. A list value
     * projects to its first element, as SpecProjector does for its own columns.
     */
    private static function modelValues(string $type): array
    {
        if (isset(self::$valueCache[$type])) {
            return self::$valueCache[$type];
        }

        $defs = self::SPEC[$type] ?? [];
        $out = [];
        if ($defs) {
            try {
                $records = SpecProjector::projectType($type, ComponentSpecPaths::getPath($type));
            } catch (Throwable $e) {
                error_log("InventoryFilters: cannot read $type specs: " . $e->getMessage());
                $records = [];
            }

            foreach ($records as $record) {
                $vals = [];
                foreach ($defs as $key => $def) {
                    $value = isset($def['field'])
                        ? ($record[$def['field']] ?? null)
                        : self::atPath($record['specs'] ?? [], $def['path']);
                    if (is_array($value)) {
                        $value = $value ? reset($value) : null;
                    }
                    if (is_string($value)) {
                        $value = trim($value);
                    }
                    if ($value === null || $value === '' || !is_scalar($value) || is_bool($value)) {
                        continue;
                    }
                    $vals[$key] = (string)$value;
                }
                $out[$record['spec_uuid']] = $vals;
            }
        }

        return self::$valueCache[$type] = $out;
    }

    private static function atPath(array $node, string $path)
    {
        foreach (explode('.', $path) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }
        return $node;
    }

    private static function label(array $def, string $value): string
    {
        $format = $def['format'] ?? null;
        if ($format === 'capacity' && is_numeric($value)) {
            $gb = (float)$value;
            return $gb >= 1000
                ? rtrim(rtrim(number_format($gb / 1000, 2, '.', ''), '0'), '.') . ' TB'
                : (int)$gb . ' GB';
        }
        if ($format === 'ports' && is_numeric($value)) {
            return (int)$value === 1 ? '1 port' : (int)$value . ' ports';
        }
        return $value;
    }
}
