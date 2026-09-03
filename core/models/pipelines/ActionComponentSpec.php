<?php
/**
 * ActionComponentSpec.php
 *
 * The hardware a request action is about, in the words of the spec sheet.
 *
 * An approver deciding on "Add a cpu to inventory" is being asked about a
 * physical part, and a uuid is not a part: it names one to the database and to
 * nobody else. This resolves the uuid the payload carries into the model's own
 * specification, read from the ims-data JSON that defines what a component IS
 * -- brand, model, and the fields that make one model different from the next.
 *
 * GENERIC on purpose. Twelve component types have twelve JSON shapes, and a
 * per-type field list would be twelve lists to keep in step with files that
 * change without this class hearing about it. So every scalar field the model
 * carries is shown, minus the ones that identify rather than describe (uuid,
 * brand, model) and the ones nobody reads in a summary (compatibility tables,
 * physical dimensions), capped so the panel stays a panel.
 *
 * DISPLAY ONLY, and fails open: a uuid that resolves to nothing returns null
 * and the panel shows what it showed before. Nothing here decides anything --
 * validateComponentUuid() at execution time is the boundary that refuses an
 * unknown component.
 *
 * @package BDC_IMS
 * @subpackage Pipelines
 */

require_once(__DIR__ . '/../components/ComponentDataService.php');

class ActionComponentSpec
{
    /** Rows shown before the list is cut. Ten fits the panel; the JSON has more. */
    const MAX_ROWS = 10;

    /** Longest value rendered. Past this a spec sheet is a paragraph. */
    const MAX_VALUE_LENGTH = 120;

    /** Identity, bulk and internal fields -- none of them describe the part. */
    private static $skip = [
        'uuid', 'model', 'brand', 'series', 'family', 'generation', 'manufacturer',
        'component_type', 'component_subtype', 'match_confidence', 'matched_by',
        'serial_numbers', 'sys_score', 'label', 'notes', 'dimensions', 'dimensions_mm',
        'use_cases', 'part_numbers',
    ];

    /**
     * Wrapper keys that add nothing to a label: "Cache (MB)" reads better than
     * "Specifications cache (MB)", and the parent name is not the fact.
     */
    private static $genericParents = [
        'specifications', 'features', 'performance', 'compatibility', 'details',
        'general', 'characteristics', 'specs',
    ];

    /** Words a title-cased label would otherwise mangle ("Tdp", "Pcie"). */
    private static $acronyms = [
        'tdp' => 'TDP', 'rpm' => 'RPM', 'cpu' => 'CPU', 'gpu' => 'GPU', 'pcie' => 'PCIe',
        'ecc' => 'ECC', 'm2' => 'M.2', 'l1' => 'L1', 'l2' => 'L2', 'l3' => 'L3',
        'usb' => 'USB', 'sas' => 'SAS', 'sata' => 'SATA', 'nvme' => 'NVMe', 'ssd' => 'SSD',
        'hdd' => 'HDD', 'io' => 'I/O', 'id' => 'ID', 'mtbf' => 'MTBF', 'bios' => 'BIOS',
        'sfp' => 'SFP', 'nic' => 'NIC', 'raid' => 'RAID', 'iops' => 'IOPS', 'dwpd' => 'DWPD',
        'tbw' => 'TBW', 'psu' => 'PSU', 'ddm' => 'DDM', 'dom' => 'DOM', 'lan' => 'LAN',
    ];

    /**
     * Units whose meaning is in their CAPITALS: MBps is megabytes a second and
     * Mbps is megabits, and a case-insensitive map would silently divide a
     * disk's transfer rate by eight.
     */
    private static $unitsExact = [
        'MBps' => 'MB/s', 'GBps' => 'GB/s', 'KBps' => 'KB/s', 'MTs' => 'MT/s',
    ];

    /** Trailing key tokens that are a unit, not a word: capacity_GB -> Capacity (GB). */
    private static $units = [
        'ghz' => 'GHz', 'mhz' => 'MHz', 'khz' => 'kHz', 'mts' => 'MT/s', 'mbps' => 'Mbps',
        'gbps' => 'Gbps', 'w' => 'W', 'v' => 'V', 'a' => 'A', 'tb' => 'TB', 'gb' => 'GB',
        'mb' => 'MB', 'kb' => 'KB', 'mm' => 'mm', 'nm' => 'nm',
    ];

    /**
     * The component an action operates on, or null when it names none.
     *
     * Every action that touches one model carries the uuid in one of three
     * places, so all three are read here rather than in the caller: inventory
     * adds put it inside `data`, the server commands carry it flat, and a
     * replace names the part going IN, which is the one an approver is judging.
     *
     * @param string $actionType
     * @param array  $payload
     * @return array|null ['type','name','brand','series','model','specs'=>[['label','value'],...]]
     */
    public static function forPayload($actionType, array $payload)
    {
        $type = isset($payload['component_type']) ? (string)$payload['component_type'] : '';
        if ($type === '') {
            return null;
        }

        $data = (isset($payload['data']) && is_array($payload['data'])) ? $payload['data'] : [];
        $uuid = '';
        foreach ([
            isset($data['UUID']) ? $data['UUID'] : null,
            isset($data['uuid']) ? $data['uuid'] : null,
            isset($payload['new_component_uuid']) ? $payload['new_component_uuid'] : null,
            isset($payload['component_uuid']) ? $payload['component_uuid'] : null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $uuid = trim($candidate);
                break;
            }
        }
        if ($uuid === '') {
            return null;
        }

        return self::describe($type, $uuid);
    }

    /**
     * One model, described. Null when ims-data does not know this uuid --
     * a component deleted from the catalogue after the request was raised, or a
     * type whose JSON has no entry for it.
     */
    public static function describe($componentType, $uuid)
    {
        try {
            $component = ComponentDataService::getInstance()
                ->findComponentByUuid($componentType, $uuid);
        } catch (Exception $e) {
            error_log("ActionComponentSpec::describe($componentType) error: " . $e->getMessage());
            return null;
        } catch (Throwable $e) {
            error_log("ActionComponentSpec::describe($componentType) error: " . $e->getMessage());
            return null;
        }

        if (!is_array($component) || empty($component)) {
            return null;
        }

        $brand = self::text($component, ['brand', 'manufacturer']);
        $model = self::text($component, ['model', 'name', 'part_number']);
        $series = self::text($component, ['series', 'family']);

        // Storage models carry no model name of their own -- the series IS the
        // name a datacentre uses ("Seagate Exos"), so it stands in.
        $name = trim($brand . ' ' . ($model !== '' ? $model : $series));
        if ($name === '') {
            $name = $componentType;
        }

        return [
            'type'   => $componentType,
            'name'   => $name,
            'brand'  => $brand,
            'series' => $series,
            'model'  => $model,
            'specs'  => self::specRows($component),
        ];
    }

    /**
     * The describing fields, in the order the spec file lists them -- which is
     * the order somebody who knows the hardware expects to read them in.
     *
     * Scalars first, then one level of nesting, because the top level is where
     * the headline numbers live and the nested objects are the detail behind
     * them. Two levels down is a compatibility matrix, and that is a different
     * screen.
     */
    private static function specRows(array $component)
    {
        $rows = [];
        $nested = [];

        foreach ($component as $key => $value) {
            if (self::isSkipped($key)) {
                continue;
            }
            $scalar = self::scalar($value);
            if ($scalar !== null) {
                $rows[] = ['label' => self::label($key), 'value' => $scalar];
            } elseif (is_array($value) && !empty($value)) {
                $nested[$key] = $value;
            }
        }

        foreach ($nested as $parentKey => $child) {
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
            foreach ($child as $childKey => $childValue) {
                if (!is_string($childKey) || self::isSkipped($childKey)) {
                    continue;
                }
                $scalar = self::scalar($childValue);
                if ($scalar === null) {
                    continue;
                }
                $rows[] = ['label' => self::nestedLabel($parentKey, $childKey), 'value' => $scalar];
            }
        }

        return array_slice($rows, 0, self::MAX_ROWS);
    }

    private static function isSkipped($key)
    {
        $lower = strtolower((string)$key);
        return in_array($lower, self::$skip, true) || strpos($lower, 'compatible') === 0;
    }

    /** The first of these keys that holds a non-empty string, or ''. */
    private static function text(array $component, array $keys)
    {
        foreach ($keys as $key) {
            if (isset($component[$key]) && is_scalar($component[$key]) && trim((string)$component[$key]) !== '') {
                return self::clean(trim((string)$component[$key]));
            }
        }
        return '';
    }

    /**
     * A displayable value, or null when the field is not one thing an approver
     * can read: an object, a list of objects, an empty anything.
     */
    private static function scalar($value)
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_int($value) || is_float($value)) {
            return self::truncate((string)$value);
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : self::truncate(self::clean($trimmed));
        }
        // A LIST of scalars is one value ("DDR4-2133, DDR4-1866"). An
        // associative array is not -- it is a group of named facts, and
        // flattening it would print "7200, 256, 150" for a disk's RPM, cache and
        // transfer rate. Those go to the nested pass, which keeps the names.
        if (is_array($value) && !empty($value) && array_keys($value) === range(0, count($value) - 1)) {
            $parts = [];
            foreach ($value as $item) {
                if (is_bool($item) || is_array($item) || is_object($item)) {
                    return null;   // a list of objects is a table, not a value
                }
                $parts[] = trim((string)$item);
            }
            $joined = trim(implode(', ', array_filter($parts, 'strlen')));
            return $joined === '' ? null : self::truncate(self::clean($joined));
        }
        return null;
    }

    /**
     * ims-data carries a few strings that were double-encoded before they were
     * committed ("12 Ã— 64 KB"). Repaired here rather than in the files, which
     * are the shared source of truth for the whole engine and not this panel's
     * to rewrite.
     */
    private static function clean($value)
    {
        // "Ã—" where a multiplication sign belongs, in the cache sizes.
        $value = str_replace("\xC3\x83\xE2\x80\x94", "\xC3\x97", $value);

        // "Â°C" where "°C" belongs -- the signature of a Latin-1 string encoded
        // as UTF-8 twice. The stray Â only ever sits in front of the character
        // it corrupted, so it is dropped rather than the string re-decoded.
        $repaired = preg_replace('/\x{00C2}(?=[\x{0080}-\x{00BF}])/u', '', $value);

        return $repaired === null ? $value : $repaired;
    }

    private static function truncate($value)
    {
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length <= self::MAX_VALUE_LENGTH) {
            return $value;
        }
        $cut = function_exists('mb_substr')
            ? mb_substr($value, 0, self::MAX_VALUE_LENGTH, 'UTF-8')
            : substr($value, 0, self::MAX_VALUE_LENGTH);
        return rtrim($cut) . '...';
    }

    /**
     * "base_frequency_GHz" -> "Base frequency (GHz)".
     *
     * The unit lives in the key in these files, so it is lifted out and put
     * where a spec sheet puts it instead of being title-cased into a word.
     */
    private static function label($key)
    {
        list($text, $unit) = self::labelParts($key);
        return $unit === null ? $text : $text . ' (' . $unit . ')';
    }

    private static function nestedLabel($parentKey, $childKey)
    {
        list($childText, $childUnit) = self::labelParts($childKey);
        list($parentText, $parentUnit) = self::labelParts($parentKey);

        if (in_array(strtolower((string)$parentKey), self::$genericParents, true)) {
            $text = $childText;
        } else {
            $text = $parentText . ' ' . self::lowerFirstWord($childText);
        }

        $unit = $childUnit !== null ? $childUnit : $parentUnit;
        return $unit === null ? $text : $text . ' (' . $unit . ')';
    }

    /** @return array [label text, unit or null] */
    private static function labelParts($key)
    {
        $parts = preg_split('/[_\s]+/', trim((string)$key));
        $parts = array_values(array_filter($parts, 'strlen'));
        if (empty($parts)) {
            return ['', null];
        }

        $unit = null;
        if (count($parts) > 1) {
            $raw = end($parts);
            $last = strtolower($raw);
            // The key has to SPELL the unit the way a unit is spelled -- with a
            // capital in it (capacity_GB, frequency_MHz), or as one of the two
            // that have none. Otherwise "power" would be read as watts and
            // every "..._a" as amps.
            $spelledAsUnit = preg_match('/[A-Z]/', $raw) === 1 || in_array($last, ['mm', 'nm'], true);
            if (isset(self::$unitsExact[$raw])) {
                $unit = self::$unitsExact[$raw];
                array_pop($parts);
            } elseif (isset(self::$units[$last]) && $spelledAsUnit) {
                $unit = self::$units[$last];
                array_pop($parts);
            }
        }

        $words = [];
        foreach ($parts as $index => $part) {
            $lower = strtolower($part);
            if (isset(self::$acronyms[$lower])) {
                $words[] = self::$acronyms[$lower];
            } elseif ($index === 0) {
                $words[] = ucfirst($lower);
            } else {
                $words[] = $lower;
            }
        }

        return [implode(' ', $words), $unit];
    }

    /** Lower-cases a continuation word, unless it is an acronym that must stay. */
    private static function lowerFirstWord($text)
    {
        $words = explode(' ', $text, 2);
        if (in_array($words[0], self::$acronyms, true)) {
            return $text;
        }
        $words[0] = lcfirst($words[0]);
        return implode(' ', $words);
    }
}
