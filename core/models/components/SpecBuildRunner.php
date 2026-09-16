<?php
/**
 * SpecBuildRunner -- the ims-data -> component_models projection, as one callable pipeline.
 *
 * This used to live inline in database/spec_build.php. It moved here when it became clear the
 * CLI can never be the only entry point: this deployment has no shell, so the script that
 * "must run after every ims-data upload" was unrunnable by the person who does the uploading.
 * Both entry points -- the CLI and the spec-build API action -- now drive this same class, so
 * there is exactly one implementation of the gate, the diff and the write.
 *
 * The pipeline: project -> uuid uniqueness gate -> schema validate -> diff -> upsert/retire.
 *
 * Every outcome is returned as a structured array; nothing is printed and nothing exits. The
 * CLI formats the array into the report it always printed, and the API returns it as JSON.
 *
 * REFUSALS ARE NOT ERRORS. A duplicate uuid stops the build deliberately: the runtime index
 * keeps the LAST writer, so the earlier model becomes unreachable and any inventory stocked
 * as it validates against the wrong compatibility envelope. Writing a projection we know is
 * wrong is worse than not writing one.
 */

class SpecBuildRunner
{
    /** Columns written to component_models, in bind order. */
    private const COLUMNS = ['spec_uuid', 'component_type', 'model_name', 'display_name',
        'brand', 'series', 'part_number', 'family', 'component_subtype', 'capacity_gb',
        'socket', 'form_factor', 'interface', 'memory_type', 'tdp_w', 'ports', 'specs',
        'source_checksum', 'source_file'];

    /**
     * @param PDO  $pdo
     * @param bool $checkOnly  Report drift, write nothing.
     * @return array {status, message, ...} -- status is one of:
     *         ok | stale | refused | schema_missing_table | projection_failed | write_failed
     */
    public static function run(PDO $pdo, $checkOnly = false)
    {
        $result = [
            'status'       => 'ok',
            'check_only'   => (bool)$checkOnly,
            'projected'    => 0,
            'files'        => 0,
            'uuids'        => 0,
            'collisions'   => [],
            'violations'   => null,
            'existing'     => 0,
            'changes'      => ['new' => 0, 'changed' => 0, 'retired' => 0, 'unchanged' => 0],
            'detail'       => ['new' => [], 'changed' => [], 'retired' => []],
            'written'      => false,
            'message'      => '',
        ];

        // 0. The table ships as a hand-run seeder and code deploys first, so "not yet" is an
        //    expected state, not a failure to shout about.
        try {
            $pdo->query('SELECT 1 FROM component_models LIMIT 1');
        } catch (PDOException $e) {
            $result['status'] = 'missing_table';
            $result['message'] = 'component_models does not exist yet. Apply '
                . 'ims-ftp/database/seeders/2026_09_16_002_component-models.sql first, then re-run.';
            return $result;
        }

        // 1. Project the files.
        try {
            $projected = SpecProjector::projectAll();
        } catch (RuntimeException $e) {
            $result['status'] = 'projection_failed';
            $result['message'] = 'Projection failed: ' . $e->getMessage() . '. Nothing was written.';
            return $result;
        }

        $records = [];
        foreach ($projected as $type => $rows) {
            foreach ($rows as $row) {
                $records[] = $row;
            }
        }
        $result['projected'] = count($records);
        $result['files'] = count($projected);

        // 2. Uniqueness gate. The catalogue is ONE namespace: lookups are type-scoped today,
        //    but that is a property of today's call sites, not of the data.
        $byUuid = [];
        foreach ($records as $r) {
            $byUuid[$r['spec_uuid']][] = $r;
        }
        $result['uuids'] = count($byUuid);

        $collisions = array_filter($byUuid, static function ($group) {
            return count($group) > 1;
        });

        if ($collisions) {
            foreach ($collisions as $uuid => $group) {
                $types = array_unique(array_column($group, 'component_type'));
                $result['collisions'][] = [
                    'uuid'       => $uuid,
                    'cross_type' => count($types) > 1,
                    'records'    => array_map(static function ($r) {
                        return [
                            'component_type' => $r['component_type'],
                            'display_name'   => $r['display_name'],
                            'source_file'    => $r['source_file'],
                        ];
                    }, $group),
                ];
            }
            $result['status'] = 'refused';
            $result['message'] = sprintf('REFUSING TO BUILD: %d duplicate uuid(s). One of each pair '
                . 'is unreachable at runtime. Renumber the record with no inventory behind it. '
                . 'Nothing was written.', count($collisions));
            return $result;
        }

        // 3. Validate against the schemas when present. They ship in ims-data, which has no
        //    watcher, so absence means "not uploaded yet" -- not a reason to refuse a build
        //    that would otherwise be correct. A violation warns; it never blocks.
        $validatorPath = __DIR__ . '/SpecValidator.php';
        if (is_readable($validatorPath)) {
            require_once $validatorPath;
            $violations = SpecValidator::validateAll($projected);
            $result['violations'] = $violations === null ? null : $violations;
        }

        // 4. Diff against the table.
        $existing = [];
        $stmt = $pdo->query('SELECT spec_uuid, source_checksum, is_retired FROM component_models');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing[$row['spec_uuid']] = $row;
        }
        $result['existing'] = count($existing);

        $toInsert = [];
        $toUpdate = [];
        $seen = [];

        foreach ($records as $r) {
            $r['source_checksum'] = SpecProjector::checksum($r['specs']);
            $seen[$r['spec_uuid']] = true;

            if (!isset($existing[$r['spec_uuid']])) {
                $toInsert[] = $r;
                continue;
            }

            $prior = $existing[$r['spec_uuid']];
            if ($prior['source_checksum'] !== $r['source_checksum'] || (int)$prior['is_retired'] === 1) {
                $toUpdate[] = $r;
            }
        }

        $toRetire = array_values(array_filter(array_keys($existing), static function ($uuid) use ($seen, $existing) {
            return !isset($seen[$uuid]) && (int)$existing[$uuid]['is_retired'] === 0;
        }));

        $label = static function ($r) {
            return $r['component_type'] . '  ' . $r['display_name'];
        };
        $result['changes'] = [
            'new'       => count($toInsert),
            'changed'   => count($toUpdate),
            'retired'   => count($toRetire),
            'unchanged' => count($records) - count($toInsert) - count($toUpdate),
        ];
        $result['detail'] = [
            'new'     => array_map($label, $toInsert),
            'changed' => array_map($label, $toUpdate),
            'retired' => $toRetire,
        ];

        $drift = count($toInsert) + count($toUpdate) + count($toRetire);

        if ($checkOnly) {
            $result['status'] = $drift > 0 ? 'stale' : 'ok';
            $result['message'] = $drift > 0
                ? sprintf('STALE: the table disagrees with the files in %d place(s). Run without check mode.', $drift)
                : 'OK: component_models matches ims-data.';
            return $result;
        }

        if ($drift === 0) {
            $result['message'] = 'OK: nothing to do.';
            return $result;
        }

        // 5. Write. One transaction: a half-built projection is worse than an old one,
        //    because nothing downstream can tell the difference.
        $placeholders = implode(', ', array_fill(0, count(self::COLUMNS), '?'));
        $updates = [];
        foreach (self::COLUMNS as $c) {
            if ($c === 'spec_uuid') continue;
            $updates[] = "$c = VALUES($c)";
        }
        $updates[] = 'is_retired = 0';

        $upsert = $pdo->prepare(
            'INSERT INTO component_models (' . implode(', ', self::COLUMNS) . ') VALUES (' . $placeholders . ') ' .
            'ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
        );
        $retire = $pdo->prepare('UPDATE component_models SET is_retired = 1 WHERE spec_uuid = ?');

        $pdo->beginTransaction();
        try {
            foreach (array_merge($toInsert, $toUpdate) as $r) {
                $upsert->execute([
                    $r['spec_uuid'], $r['component_type'], $r['model_name'], $r['display_name'],
                    $r['brand'], $r['series'], $r['part_number'],
                    $r['context']['family'], $r['context']['component_subtype'],
                    $r['capacity_gb'], $r['socket'],
                    $r['form_factor'], $r['interface'], $r['memory_type'], $r['tdp_w'], $r['ports'],
                    // JSON_PRESERVE_ZERO_FRACTION is load-bearing, not cosmetic: without it
                    // json_encode writes the float 2.0 as `2`, which decodes back as an
                    // INTEGER. A round-trip through this column would silently retype every
                    // whole-numbered float in the catalogue -- base_frequency_GHz, dimensions,
                    // power_consumption_W -- and the validation rules do compare types.
                    // Measured: 175 of 378 specs came back altered without this flag.
                    json_encode($r['specs'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                    $r['source_checksum'], $r['source_file'],
                ]);
            }
            foreach ($toRetire as $uuid) {
                $retire->execute([$uuid]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $result['status'] = 'write_failed';
            $result['message'] = 'Write failed, rolled back: ' . $e->getMessage();
            return $result;
        }

        $result['written'] = true;
        $result['message'] = sprintf('OK: component_models is current (%d live models).', count($records));
        return $result;
    }
}
