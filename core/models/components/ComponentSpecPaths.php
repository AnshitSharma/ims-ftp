<?php

class ComponentSpecPaths {
    private const ENV_KEY = 'IMS_DATA_PATH';
    private const PATHS = [
        'cpu' => 'cpu/Cpu-details-level-3.json',
        'motherboard' => 'motherboard/motherboard-level-3.json',
        'ram' => 'ram/ram_detail.json',
        'storage' => 'storage/storage-level-3.json',
        'nic' => 'nic/nic-level-3.json',
        'caddy' => 'caddy/caddy_details.json',
        'pciecard' => 'pciecard/pci-level-3.json',
        'hbacard' => 'hbacard/hbacard-level-3.json',
        'sfp' => 'sfp/sfp-level-3.json',
        'chassis' => 'chassis/chasis-level-3.json',
    ];

    public static function getBasePath(): string {
        $configuredPath = self::getConfiguredBasePath();
        if ($configuredPath !== null) {
            return $configuredPath;
        }

        $projectRoot = dirname(__DIR__, 3);
        $candidatePaths = [
            dirname($projectRoot) . '/ims-data',
            $projectRoot . '/ims-data',
        ];

        foreach ($candidatePaths as $candidatePath) {
            if (is_dir($candidatePath)) {
                return self::normalizePath($candidatePath);
            }
        }

        throw new RuntimeException(
            'Unable to resolve component JSON path. Set IMS_DATA_PATH or add an ims-data directory next to the backend.'
        );
    }

    public static function getPath(string $componentType): string {
        if (!isset(self::PATHS[$componentType])) {
            throw new InvalidArgumentException("Unsupported component type: $componentType");
        }

        return self::getBasePath() . '/' . self::PATHS[$componentType];
    }

    public static function getAll(): array {
        $paths = [];

        foreach (self::PATHS as $componentType => $relativePath) {
            $paths[$componentType] = self::getBasePath() . '/' . $relativePath;
        }

        return $paths;
    }

    private static function getConfiguredBasePath(): ?string {
        $configuredPath = getenv(self::ENV_KEY);
        if (is_string($configuredPath) && trim($configuredPath) !== '') {
            return self::normalizePath($configuredPath);
        }

        $envPath = dirname(__DIR__, 3) . '/.env';
        if (!is_file($envPath)) {
            return null;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return null;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }

            list($key, $value) = explode('=', $line, 2);
            if (trim($key) !== self::ENV_KEY) {
                continue;
            }

            $value = trim($value);
            if ($value === '') {
                return null;
            }

            return self::normalizePath(trim($value, "\"'"));
        }

        return null;
    }

    private static function normalizePath(string $path): string {
        return rtrim(str_replace('\\', '/', trim($path)), '/');
    }
}
?>
