<?php

require_once __DIR__ . '/ConfigComponentRepository.php';
require_once __DIR__ . '/../components/ComponentSpecPaths.php';

/**
 * ConfigReadRouter — U-X.1. The read-path seam for READ_FROM_ROWS (off|sample|on).
 *
 * One question, three answers: "what components does this config contain?"
 *   off    => ServerBuilder::extractComponentsFromJson() verbatim (identity; the
 *             router is a pass-through and adds one function call, nothing else).
 *   sample => run BOTH sides, compare canonical tuples, append the OUTCOME --
 *             agreement included -- to reports/shadow/read-<Ymd>.jsonl, and
 *             return the LEGACY result UNCONDITIONALLY. Nothing a caller sees
 *             depends on the rows side.
 *   on     => rows become the answer, mapped back to the legacy output shape.
 *
 * F-27: sample mode logged divergences ONLY until 2026-07-29, which made an
 * empty log unreadable -- "every read agreed" and "no read ever reached this
 * router" produce the identical artifact. Every row now carries a 'kind'
 * (compared | divergence | skipped_virtual) so the log has a denominator and
 * scripts/verify/read_report.php can distinguish silence from success.
 *
 * The configuration cache in ServerBuilder::getConfigurationDetails() sits ABOVE
 * this router (it short-circuits before the row is even fetched), so a cached
 * read never reaches here and no mode can poison a cache entry -- U-X.1
 * checklist item 3, satisfied structurally rather than by convention.
 *
 * ── PACK-VS-REALITY corrections (read before implementing from U-X.1.md) ──
 *
 * 1. The pack's signature is `components($configUuid)`. Taking a uuid would mean
 *    re-SELECTing a server_configurations row the only caller has *already*
 *    fetched, on a hot read path. This takes the fetched row instead and reads
 *    $configRow['config_uuid'] for the rows-side lookup. Same information, one
 *    query fewer.
 * 2. The pack lists "name enrichment via getComponentNameFromSpec" as part of
 *    the contract this router must reproduce. It is NOT: enrichment happens in
 *    getConfigurationDetails() (at the 'component_name' key it builds per
 *    component), which is ABOVE this router and runs identically whichever mode
 *    is active. extractComponentsFromJson() itself never enriches anything.
 *    Enrichment parity is therefore automatic, not something =on must re-derive.
 *    (Consistent with U-X.1-PLAN-20260712.md, which already found this pack's
 *    line citations stale by +99 and +256 lines.)
 * 3. The pack says to reuse equivalence_report.php's canonicalization consts.
 *    That file is a CLI report with its own bootstrap and top-level exit() calls
 *    -- it cannot be required from core/ without running it. The tuple rules are
 *    reimplemented in canonicalTuple() below, deliberately field-for-field
 *    identical to equivalence_report.php:97-131. THIS IS A DUPLICATE and the two
 *    must be changed together; extracting a shared canonicalizer class is left to
 *    U-D.3, which retires the JSON side of that report anyway.
 *
 * ── What =on structurally CANNOT reproduce (must be signed off before flipping) ──
 *
 * config_components stores one row per physical unit, with no column for these
 * three legacy JSON artifacts. Each is a real, observable difference at =on,
 * listed here rather than papered over:
 *   a. storage 'connection'. The legacy JSON carries the whole computed
 *      connection blob inline; rows do not. =on omits the key, which routes the
 *      caller into the recompute branch it ALREADY uses today whenever the
 *      stored blob is missing or reads 'not_connected' (the lazy-migration path
 *      in getConfigurationDetails). Same function, same inputs -- but it now
 *      runs for every drive, so a config whose stored blob disagreed with a
 *      fresh computation will read differently at =on than at =off.
 *   b. 'added_at' for motherboard/chassis (and the hbacard scalar fallback).
 *      Legacy hardcodes null -- those come from scalar columns with no timestamp.
 *      Rows always have a real added_at. =on surfaces a timestamp where legacy
 *      showed null. Strictly more information, still a shape difference.
 *   c. aggregated 'quantity'. A legacy JSON entry may carry quantity > 1 for one
 *      model; rows are per unit and always quantity 1. Component TOTALS agree
 *      (n rows of 1 == 1 entry of n) but the per-entry shape does not.
 * sample mode does not compare any of the three (canonicalTuple ignores
 * quantity/added_at/connection), which is what makes a zero-divergence sample
 * window meaningful about IDENTITY -- who is in the config -- and silent about
 * these three. tests/regression/read_router_test.php pins all of it.
 */
final class ConfigReadRouter
{
    /**
     * Legacy emission order, from the branch order of
     * extractComponentsFromJson(). =on reproduces it because downstream code is
     * order-sensitive: getConfigurationDetails() numbers storage bays by
     * position ($bayNumber = count(...) + 1) and de-duplicates serials in
     * iteration order.
     */
    /*
     * 'risercard' sits immediately before 'pciecard': legacy emits both from the
     * SINGLE pciecard_configurations array in array order, so the rows side cannot
     * reproduce an interleaving exactly once the two are separate types. Riser-first
     * is the closest match — it is the order Extractor::extractPciecards() writes
     * (risers resolve first so plain cards can parent to them), and the order adds
     * naturally happen in (a riser must exist before a card can sit on it).
     * A config that genuinely interleaves them will show as an ORDER divergence in
     * sample mode; that is a reporting artifact of the split, not a data problem.
     */
    private const LEGACY_TYPE_ORDER = [
        'cpu', 'ram', 'storage', 'caddy', 'nic', 'hbacard',
        'motherboard', 'chassis', 'risercard', 'pciecard', 'sfp',
    ];

    /** Legacy hardcodes added_at = null for components that come from scalar columns. */
    private const SCALAR_COLUMN_TYPES = ['motherboard', 'chassis'];

    /**
     * Row kinds in reports/shadow/read-<Ymd>.jsonl (F-27).
     *
     * BACKWARD COMPATIBILITY: rows written before 2026-07-29 carry no 'kind' at
     * all, and the only rows that existed then were divergences. A reader MUST
     * treat a missing 'kind' as KIND_DIVERGENCE -- never as a comparison, which
     * would let historical rows manufacture a denominator they never measured.
     */
    public const KIND_COMPARED        = 'compared';
    public const KIND_DIVERGENCE      = 'divergence';
    public const KIND_SKIPPED_VIRTUAL = 'skipped_virtual';

    /**
     * @return string one of "off", "sample", "on"
     */
    public static function mode(): string
    {
        $mode = getenv('READ_FROM_ROWS');
        if (!is_string($mode) || $mode === '') {
            $mode = $_ENV['READ_FROM_ROWS'] ?? 'off';
        }
        $mode = strtolower(trim((string)$mode));
        if (!in_array($mode, ['off', 'sample', 'on'], true)) {
            return 'off';
        }
        return $mode;
    }

    /**
     * The routed read.
     *
     * @param ServerBuilder $builder     the caller, for the legacy extraction
     * @param array         $configRow   an already-fetched server_configurations row
     * @param bool          $minimalOutput passed through to the legacy extractor
     * @return array components in extractComponentsFromJson()'s output shape
     */
    public static function components(ServerBuilder $builder, PDO $pdo, array $configRow, bool $minimalOutput = false): array
    {
        $legacy = $builder->extractComponentsFromJson($configRow, $minimalOutput);
        $mode = self::mode();

        if ($mode === 'off') {
            return $legacy;
        }

        $configUuid = (string)($configRow['config_uuid'] ?? '');
        if ($configUuid === '') {
            // No uuid means no rows side to consult. Never a divergence -- there is
            // nothing to compare -- and at =on there is nothing to return but legacy.
            return $legacy;
        }

        if ($mode === 'sample') {
            // A read must never fail because the SHADOW side of it failed. Sample
            // mode is observation only: anything at all going wrong below leaves
            // the caller with the byte-identical legacy answer it would have got
            // at =off, and leaves a line in the error log for the operator.
            try {
                self::sample($pdo, $builder, $configRow, $configUuid, $legacy);
            } catch (Throwable $e) {
                error_log('ConfigReadRouter sample-mode comparison failed for ' . $configUuid . ': ' . $e->getMessage());
            }
            return $legacy;
        }

        // =on. A throw here is NOT swallowed: at =on the rows side is the answer,
        // and silently serving legacy instead would be a lie about which store is
        // authoritative -- exactly the "fail open, look green" class this migration
        // has now hit four times (F-11, F-18, F-21, F-24). Let it surface.
        $rows = (new ConfigComponentRepository($pdo))->liveRows($configUuid);
        return self::rowsToLegacyShape($pdo, $rows, $configRow, $minimalOutput);
    }

    /**
     * Compare both sides and log a divergence row if they disagree. Returns
     * nothing -- sample mode's only output is the log.
     */
    private static function sample(PDO $pdo, ServerBuilder $builder, array $configRow, string $configUuid, array $legacy): void
    {
        // Virtual configs legitimately have no rows: BOTH dual-write hooks skip
        // them by their own guard (ServerBuilder's is_virtual checks), which is
        // also why every migration report scans is_virtual = 0. Comparing them
        // would manufacture a divergence for every virtual config on every read.
        //
        // The skip is RECORDED rather than silent (F-27): a window containing
        // nothing but virtual reads has performed no comparison, and must not be
        // read as "sample mode found nothing wrong". Same row, different kind.
        if ((int)($configRow['is_virtual'] ?? 0) === 1) {
            self::logRead([
                'kind'        => self::KIND_SKIPPED_VIRTUAL,
                'config_uuid' => $configUuid,
            ]);
            return;
        }

        $rows = (new ConfigComponentRepository($pdo))->liveRows($configUuid);

        $jsonTuples = self::canonicalizeJsonSide($builder, $configRow);
        $rowTuples = self::canonicalizeRowSide($rows);

        $onlyInJson = array_values(array_diff($jsonTuples, $rowTuples));
        $onlyInRows = array_values(array_diff($rowTuples, $jsonTuples));

        if (empty($onlyInJson) && empty($onlyInRows)) {
            // THE DENOMINATOR (F-27). Before this, agreement was silent and the
            // log held divergences only -- so an empty log meant either "every
            // read agreed" or "no read ever reached the router", and nothing
            // could tell them apart. U-X.2's acceptance criterion is literally
            // "divergence log must stay empty over >=72h", which a router that
            // never executes satisfies perfectly. That is the same fail-open
            // shape as F-10 (reports exiting 0 having run nothing) and F-8/F-23
            // (a ratio whose denominator was never established).
            //
            // Recording agreement costs one appended line per config-detail read
            // -- getConfigurationDetails() has exactly two callers and neither
            // loops -- against a comparison that already ran a liveRows() query
            // and canonicalized both sides. The write is the cheap part.
            self::logRead([
                'kind'         => self::KIND_COMPARED,
                'config_uuid'  => $configUuid,
                'legacy_count' => count($legacy),
                'rows_count'   => count($rows),
            ]);
            return;
        }

        // An entirely empty rows side is ONE finding ("this config was never
        // dual-written or backfilled"), not N component divergences -- but it is
        // still a finding and is still logged. The fleet is supposed to be fully
        // backfilled, so zero rows on a real config means the backfill missed it
        // or dual-write is off; either way the operator needs to see it, and
        // excusing it is the fail-open mistake F-21 was.
        self::logRead([
            'kind'           => self::KIND_DIVERGENCE,
            'config_uuid'    => $configUuid,
            'rows_side_empty' => empty($rows),
            'legacy_count'   => count($legacy),
            'rows_count'     => count($rows),
            'only_in_json'   => array_map(fn($t) => json_decode($t, true), $onlyInJson),
            'only_in_rows'   => array_map(fn($t) => json_decode($t, true), $onlyInRows),
        ]);
    }

    private static function logRead(array $record): void
    {
        $dir = __DIR__ . '/../../../reports/shadow';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $record = array_merge([
            'ts' => date('c'),
            // Which SAPI produced this row -- production requests are litespeed, a
            // local harness replay is always cli. Same field, same reason, as
            // ShadowRunner and CommandShadowLog (finding F-23): without it a reader
            // cannot tell real traffic from the test suite talking to itself, and
            // U-X.2's soak criterion is only meaningful if a local test run cannot
            // dirty that log.
            //
            // This was not hypothetical. On 2026-07-29 the production copy of
            // read-20260728.jsonl held 6 rows, ALL sapi=cli and ALL stamped
            // +02:00 -- production runs UTC, so they were written by a local tree
            // and carried up by SFTP (reports/ is not in the ignore list). Under
            // U-X.2's original wording those rows alone would have restarted a
            // 72h clock for a reason that had nothing to do with production.
            // read_report.php therefore filters on this field, exactly as the
            // other two parity reports do.
            'sapi' => PHP_SAPI,
        ], $record);
        @file_put_contents(
            $dir . '/read-' . date('Ymd') . '.jsonl',
            json_encode($record) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    // ------------------------------------------------------------------
    // Canonicalization. DUPLICATE of equivalence_report.php:97-131 -- see the
    // class docblock, correction 3. Change both or neither.
    // ------------------------------------------------------------------

    /**
     * Is this LEGACY-JSON pciecard entry actually a riser?
     *
     * The legacy pciecard_configurations column still holds risers after the
     * 2026-08-14 type split (see ServerBuilder::updatePcieCardConfiguration()'s
     * case comment for why no 11th JSON column was added), while the ROWS side
     * types them 'risercard'. Without this bridge every riser would read as a
     * divergence in sample mode and as the wrong type in =on mode.
     *
     * The historical 'riser-' UUID-prefix test only ever caught SYNTHETIC uuids —
     * none of the 20 real riser spec UUIDs carry that prefix — so catalog
     * membership is the test that actually works. Both are kept.
     *
     * Dies together with the *_configuration(s) columns at U-D.3.
     */
    private static function isRiserPciecard(string $type, ?string $uuid): bool
    {
        if ($type !== 'pciecard' || $uuid === null) {
            return false;
        }
        if (strpos($uuid, 'riser-') === 0) {
            return true;
        }
        return self::isKnownRiserSpecUuid($uuid);
    }

    /**
     * Membership test against the risercard catalog, memoized per request.
     * Never throws: an unreadable/absent spec file means "not a riser", which
     * degrades to the pre-split behaviour rather than breaking every read.
     */
    private static function isKnownRiserSpecUuid(string $uuid): bool
    {
        static $riserUuids = null;

        if ($riserUuids === null) {
            $riserUuids = [];
            try {
                $path = ComponentSpecPaths::getPath('risercard');
                $groups = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
                if (is_array($groups)) {
                    foreach ($groups as $group) {
                        foreach (($group['models'] ?? []) as $model) {
                            $specUuid = $model['UUID'] ?? ($model['uuid'] ?? null);
                            if (is_string($specUuid) && $specUuid !== '') {
                                $riserUuids[$specUuid] = true;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $riserUuids = [];
            }
        }

        return isset($riserUuids[$uuid]);
    }

    /**
     * @return array [$type, $specUuid, $serial, $slotRef]
     *
     * serial is compared for cpu only and slot for sfp only, because
     * extractComponentsFromJson() is the only decoder on the legacy side and it
     * reads a serial back out for cpu alone and exposes sfp's slot alone. Any
     * wider comparison diverges on every config that HAS the field row-side,
     * regardless of whether the two stores actually agree about the hardware.
     */
    private static function canonicalTuple(string $type, ?string $specUuid, ?string $serial, ?string $slotRef): array
    {
        if (self::isRiserPciecard($type, $specUuid)) {
            $type = 'risercard';
        }
        return [
            $type,
            $specUuid,
            $type === 'cpu' ? $serial : null,
            $type === 'sfp' ? $slotRef : null,
        ];
    }

    /** @return string[] JSON-encoded tuples, sorted */
    private static function canonicalizeJsonSide(ServerBuilder $builder, array $configRow): array
    {
        $entries = $builder->extractComponentsFromJson($configRow);

        // hbacard edge case, mirrored from equivalence_report.php: the scalar
        // hbacard_uuid column is the one live hbacard when hbacard_config is empty,
        // and extractComponentsFromJson()'s elseif already covers that -- but only
        // when hbacard_config is empty(), which '[]' is NOT.
        $hbacardConfigEmpty = empty($configRow['hbacard_config']) || $configRow['hbacard_config'] === '[]';
        if (!empty($configRow['hbacard_uuid']) && $hbacardConfigEmpty) {
            $alreadyPresent = false;
            foreach ($entries as $existing) {
                if (($existing['component_type'] ?? null) === 'hbacard') {
                    $alreadyPresent = true;
                    break;
                }
            }
            if (!$alreadyPresent) {
                $entries[] = ['component_type' => 'hbacard', 'component_uuid' => $configRow['hbacard_uuid']];
            }
        }

        $tuples = [];
        foreach ($entries as $entry) {
            $type = $entry['component_type'] ?? null;
            $specUuid = $entry['component_uuid'] ?? null;
            if ($type === null || $specUuid === null) {
                continue;
            }
            $slotRef = isset($entry['port_index']) && $entry['port_index'] !== null
                ? 'port_' . $entry['port_index']
                : null;
            $tuples[] = json_encode(self::canonicalTuple(
                (string)$type,
                (string)$specUuid,
                $entry['serial_number'] ?? null,
                $slotRef
            ));
        }
        sort($tuples);
        return $tuples;
    }

    /** @return string[] JSON-encoded tuples, sorted */
    private static function canonicalizeRowSide(array $rows): array
    {
        $tuples = [];
        foreach ($rows as $row) {
            $tuples[] = json_encode(self::canonicalTuple(
                (string)$row['component_type'],
                $row['spec_uuid'] === null ? null : (string)$row['spec_uuid'],
                $row['serial_number'] ?? null,
                $row['slot_ref'] ?? null
            ));
        }
        sort($tuples);
        return $tuples;
    }

    // ------------------------------------------------------------------
    // =on: rows mapped back into extractComponentsFromJson()'s output shape.
    // ------------------------------------------------------------------

    /**
     * @param array[] $rows  config_components live rows
     * @param array   $configRow the server_configurations row (for the sfp
     *                unassigned/assigned split's parent resolution only)
     */
    private static function rowsToLegacyShape(PDO $pdo, array $rows, array $configRow, bool $minimalOutput): array
    {
        // parent_id points at another config_components row; the legacy shape wants
        // the PARENT'S SPEC UUID (sfp's 'parent_nic_uuid'). Resolve from the rows we
        // already have -- a parent is always in the same config, so no extra query.
        $specById = [];
        foreach ($rows as $row) {
            $specById[(int)$row['id']] = $row['spec_uuid'];
        }

        $ordered = self::sortLikeLegacy($rows);

        $components = [];
        foreach ($ordered as $row) {
            $type = (string)$row['component_type'];

            $component = [
                'component_type' => $type,
                'component_uuid' => $row['spec_uuid'],
                // Rows are one-per-physical-unit, so quantity is always 1. See the
                // class docblock (c): totals agree with legacy, per-entry shape
                // need not.
                'quantity'       => 1,
                'added_at'       => in_array($type, self::SCALAR_COLUMN_TYPES, true)
                    // Legacy has no timestamp for scalar-column components and
                    // hardcodes null. Reproduced so =on does not invent one.
                    ? null
                    : ($row['added_at'] ?? null),
            ];

            if ($row['serial_number'] !== null) {
                $component['serial_number'] = $row['serial_number'];
            }
            // A-L5: the physical unit's inventory row id. Rows always have it, which
            // is the one place =on carries STRICTLY MORE identity than legacy JSON
            // (legacy only has it where a writer happened to store it).
            if ($row['inventory_id'] !== null) {
                $component['inventory_id'] = (int)$row['inventory_id'];
            }

            if ($type === 'sfp') {
                $parentId = $row['parent_id'] === null ? null : (int)$row['parent_id'];
                $component['parent_nic_uuid'] = $parentId !== null && isset($specById[$parentId])
                    ? $specById[$parentId]
                    : null;
                $component['port_index'] = self::portIndexFromSlotRef($row['slot_ref'] ?? null);
                if ($component['parent_nic_uuid'] === null) {
                    // Legacy tags the unassigned_sfps branch this way; a SFP with no
                    // parent row is the same state.
                    $component['status'] = 'unassigned';
                }
            }

            // NOTE (docblock item a): storage 'connection' is deliberately ABSENT.
            // config_components has no column for the computed blob, and omitting the
            // key routes getConfigurationDetails() into the recompute branch it
            // already uses whenever the stored blob is missing or 'not_connected'.

            $components[] = $component;
        }

        if ($minimalOutput) {
            $components = array_map(fn($c) => [
                'component_type' => $c['component_type'],
                'component_uuid' => $c['component_uuid'],
            ], $components);
            $components = array_values(array_filter($components, fn($c) => !empty($c['component_uuid'])));
        }

        return $components;
    }

    /**
     * Legacy JSON order: types in extractComponentsFromJson()'s branch order,
     * and within a type the array's append order. added_at then id reproduces
     * append order (id breaks ties for the same-second adds a JSON array would
     * still have kept apart).
     *
     * @param array[] $rows
     * @return array[]
     */
    private static function sortLikeLegacy(array $rows): array
    {
        $rank = array_flip(self::LEGACY_TYPE_ORDER);
        usort($rows, function ($a, $b) use ($rank) {
            $ra = $rank[$a['component_type']] ?? count($rank);
            $rb = $rank[$b['component_type']] ?? count($rank);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            $aAdded = (string)($a['added_at'] ?? '');
            $bAdded = (string)($b['added_at'] ?? '');
            if ($aAdded !== $bAdded) {
                return strcmp($aAdded, $bAdded);
            }
            return (int)$a['id'] <=> (int)$b['id'];
        });
        return $rows;
    }

    /** 'port_3' => 3; anything else => null. Inverse of the writer's slot_ref format. */
    private static function portIndexFromSlotRef(?string $slotRef): ?int
    {
        if ($slotRef === null || strpos($slotRef, 'port_') !== 0) {
            return null;
        }
        $index = substr($slotRef, 5);
        return $index === '' || !ctype_digit($index) ? null : (int)$index;
    }
}
