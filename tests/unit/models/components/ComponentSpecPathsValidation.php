<?php

require_once __DIR__ . '/../../../../core/models/components/ComponentSpecPaths.php';
require_once __DIR__ . '/../../../../core/models/components/ComponentDataService.php';

function fail($message) {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertTrue($condition, $message) {
    if (!$condition) {
        fail($message);
    }
}

function collectFirstUuid($componentType, $jsonData) {
    if ($componentType === 'caddy') {
        foreach ($jsonData['caddies'] ?? [] as $caddy) {
            $uuid = $caddy['uuid'] ?? $caddy['UUID'] ?? null;
            if ($uuid) {
                return $uuid;
            }
        }
        return null;
    }

    if ($componentType === 'chassis') {
        foreach ($jsonData['chassis_specifications']['manufacturers'] ?? [] as $manufacturer) {
            foreach ($manufacturer['series'] ?? [] as $series) {
                foreach ($series['models'] ?? [] as $model) {
                    $uuid = $model['uuid'] ?? $model['UUID'] ?? null;
                    if ($uuid) {
                        return $uuid;
                    }
                }
            }
        }
        return null;
    }

    if ($componentType === 'nic' || $componentType === 'sfp') {
        foreach ($jsonData as $brand) {
            foreach ($brand['series'] ?? [] as $series) {
                foreach ($series['models'] ?? [] as $model) {
                    $uuid = $model['uuid'] ?? $model['UUID'] ?? null;
                    if ($uuid) {
                        return $uuid;
                    }
                }
            }
        }
        return null;
    }

    foreach ($jsonData as $group) {
        foreach ($group['models'] ?? [] as $model) {
            $uuid = $model['uuid'] ?? $model['UUID'] ?? null;
            if ($uuid) {
                return $uuid;
            }
        }
    }

    return null;
}

function assertNoLegacySpecPathReferences($rootPath) {
    $paths = [$rootPath . '/core', $rootPath . '/api'];

    foreach ($paths as $path) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            assertTrue(
                strpos($content, 'resources/specifications') === false,
                "Found legacy resources/specifications reference in {$file->getPathname()}"
            );
        }
    }
}

$rootPath = dirname(__DIR__, 4);
$paths = ComponentSpecPaths::getAll();
$dataService = ComponentDataService::getInstance();

foreach ($paths as $componentType => $path) {
    assertTrue(file_exists($path), "Missing canonical JSON for {$componentType}: {$path}");

    $jsonData = json_decode(file_get_contents($path), true);
    assertTrue(is_array($jsonData), "Invalid JSON for {$componentType}: {$path}");

    $uuid = collectFirstUuid($componentType, $jsonData);
    assertTrue(!empty($uuid), "Could not find sample UUID in {$componentType}: {$path}");

    $component = $dataService->findComponentByUuid($componentType, $uuid);
    assertTrue(!empty($component), "Lookup failed for {$componentType} UUID {$uuid}");
}

assertNoLegacySpecPathReferences($rootPath);

fwrite(STDOUT, "Component spec path validation passed." . PHP_EOL);
?>
