<?php
/**
 * Complete Server Configuration Workflow Validation
 *
 * This script performs comprehensive end-to-end testing of the server build workflow:
 * - Creates 3 complete server configurations
 * - Tests compatibility logic for all component types
 * - Validates edge cases: riser slots, onboard NICs
 * - Logs all API interactions for analysis
 *
 * Usage: php tests/server_build_validation.php [base_url]
 */

class IMSApiClient {
    private $baseUrl;
    private $accessToken;
    private $logger;

    public function __construct($baseUrl, $logger) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->logger = $logger;
    }

    /**
     * Authenticate and get access token
     */
    public function authenticate($username = 'superadmin', $password = 'password') {
        $this->logger->log("Authenticating as $username...", 'INFO');

        $response = $this->call('auth-login', [
            'username' => $username,
            'password' => $password
        ]);

        if ($response['success'] && isset($response['data']['tokens']['access_token'])) {
            $this->accessToken = $response['data']['tokens']['access_token'];
            $this->logger->log("Authentication successful. Token: " . substr($this->accessToken, 0, 20) . "...", 'SUCCESS');
            return true;
        }

        $this->logger->log("Authentication failed: " . ($response['message'] ?? 'Unknown error'), 'ERROR');
        return false;
    }

    /**
     * Make API call
     */
    public function call($action, $params = [], $logResponse = true) {
        $params['action'] = $action;

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . '/api/api.php',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        if ($this->accessToken) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->accessToken
            ]);
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            $this->logger->log("CURL Error calling $action: $error", 'ERROR');
            return ['success' => false, 'message' => $error];
        }

        $data = json_decode($response, true);

        if ($logResponse) {
            $this->logger->logApiCall($action, $params, $data);
        }

        return $data ?? ['success' => false, 'message' => 'Invalid JSON response'];
    }

    /**
     * List components by type
     */
    public function listComponents($type) {
        return $this->call("{$type}-list", [], false);
    }

    /**
     * Create new server configuration
     */
    public function createServer($chassisUuid, $motherboardUuid, $serverName) {
        $this->logger->log("Creating server: $serverName", 'INFO');
        $this->logger->log("  Chassis UUID: $chassisUuid", 'DEBUG');
        $this->logger->log("  Motherboard UUID: $motherboardUuid", 'DEBUG');

        return $this->call('server-create', [
            'chassis_uuid' => $chassisUuid,
            'motherboard_uuid' => $motherboardUuid,
            'server_name' => $serverName
        ]);
    }

    /**
     * Get compatible components for current server configuration
     */
    public function getCompatibility($configId, $componentType) {
        $this->logger->log("Getting compatible {$componentType}s for config $configId", 'DEBUG');

        return $this->call('server-get-compatibility', [
            'config_id' => $configId,
            'component_type' => $componentType
        ]);
    }

    /**
     * Add component to server
     */
    public function addComponent($configId, $componentType, $componentUuid, $quantity = 1, $slotInfo = []) {
        $this->logger->log("Adding {$componentType}: $componentUuid (qty: $quantity)", 'INFO');

        $params = [
            'config_id' => $configId,
            'component_type' => $componentType,
            'component_uuid' => $componentUuid,
            'quantity' => $quantity
        ];

        // Add slot-specific information if provided
        $params = array_merge($params, $slotInfo);

        return $this->call('server-add-component', $params);
    }

    /**
     * Get current server configuration
     */
    public function getConfiguration($configId) {
        return $this->call('server-get-config', [
            'config_id' => $configId
        ]);
    }

    /**
     * Validate server configuration
     */
    public function validateConfiguration($configId) {
        $this->logger->log("Validating configuration $configId", 'INFO');
        return $this->call('server-validate-config', [
            'config_id' => $configId
        ]);
    }

    /**
     * Finalize server configuration
     */
    public function finalizeConfiguration($configId) {
        $this->logger->log("Finalizing configuration $configId", 'INFO');
        return $this->call('server-finalize-config', [
            'config_id' => $configId
        ]);
    }

    /**
     * Delete server configuration to free up components
     */
    public function deleteConfiguration($configId) {
        $this->logger->log("Deleting configuration $configId to free components...", 'INFO');
        return $this->call('server-delete-config', [
            'config_id' => $configId
        ]);
    }
}

class TestLogger {
    private $logFile;
    private $startTime;
    private $apiCallCount = 0;

    public function __construct($logFile) {
        $this->logFile = $logFile;
        $this->startTime = microtime(true);

        // Clear previous log
        file_put_contents($this->logFile, '');

        $this->writeHeader();
    }

    private function writeHeader() {
        $header = str_repeat('=', 100) . "\n";
        $header .= "BDC IMS - Complete Server Configuration Workflow Validation\n";
        $header .= "Test Started: " . date('Y-m-d H:i:s') . "\n";
        $header .= str_repeat('=', 100) . "\n\n";

        file_put_contents($this->logFile, $header, FILE_APPEND);
    }

    public function log($message, $level = 'INFO') {
        $elapsed = round(microtime(true) - $this->startTime, 3);
        $colors = [
            'ERROR' => "\033[31m",
            'SUCCESS' => "\033[32m",
            'WARNING' => "\033[33m",
            'INFO' => "\033[36m",
            'DEBUG' => "\033[90m",
        ];
        $reset = "\033[0m";

        $color = $colors[$level] ?? '';
        $timestamp = sprintf("[%07.3fs]", $elapsed);
        $formattedMessage = sprintf("%s [%s] %s", $timestamp, str_pad($level, 7), $message);

        // Console output with color
        echo $color . $formattedMessage . $reset . PHP_EOL;

        // File output without color
        file_put_contents($this->logFile, $formattedMessage . "\n", FILE_APPEND);
    }

    public function logApiCall($action, $params, $response) {
        $this->apiCallCount++;

        $log = "\n" . str_repeat('-', 100) . "\n";
        $log .= "API Call #{$this->apiCallCount}: $action\n";
        $log .= str_repeat('-', 100) . "\n";
        $log .= "Request Parameters:\n";
        $log .= json_encode($params, JSON_PRETTY_PRINT) . "\n\n";
        $log .= "Response:\n";
        $log .= json_encode($response, JSON_PRETTY_PRINT) . "\n";
        $log .= str_repeat('-', 100) . "\n\n";

        file_put_contents($this->logFile, $log, FILE_APPEND);
    }

    public function logSection($title) {
        $this->log('', 'INFO');
        $this->log(str_repeat('=', 80), 'INFO');
        $this->log($title, 'INFO');
        $this->log(str_repeat('=', 80), 'INFO');
    }

    public function logConfigSummary($configId, $config) {
        $summary = "\n" . str_repeat('=', 100) . "\n";
        $summary .= "SERVER CONFIGURATION SUMMARY - Config ID: $configId\n";
        $summary .= str_repeat('=', 100) . "\n";
        $summary .= "Server Name: " . ($config['server_name'] ?? 'N/A') . "\n";
        $summary .= "Status: " . ($config['status'] ?? 'N/A') . "\n";
        $summary .= "\nComponents:\n";

        if (isset($config['components']) && is_array($config['components'])) {
            foreach ($config['components'] as $type => $items) {
                $summary .= "  - $type: " . count($items) . " item(s)\n";
                foreach ($items as $item) {
                    $summary .= "      * " . ($item['name'] ?? $item['uuid'] ?? 'Unknown') . "\n";
                }
            }
        }

        $summary .= str_repeat('=', 100) . "\n\n";

        file_put_contents($this->logFile, $summary, FILE_APPEND);
        echo $summary;
    }

    public function finalize() {
        $elapsed = round(microtime(true) - $this->startTime, 2);
        $footer = "\n" . str_repeat('=', 100) . "\n";
        $footer .= "Test Completed: " . date('Y-m-d H:i:s') . "\n";
        $footer .= "Total Duration: {$elapsed}s\n";
        $footer .= "Total API Calls: {$this->apiCallCount}\n";
        $footer .= str_repeat('=', 100) . "\n";

        file_put_contents($this->logFile, $footer, FILE_APPEND);
        echo $footer;
    }
}

class ServerConfigurationBuilder {
    private $api;
    private $logger;
    private $configId;
    private $componentTypes = ['cpu', 'ram', 'storage', 'nic', 'pciecard', 'hbacard'];

    public function __construct(IMSApiClient $api, TestLogger $logger) {
        $this->api = $api;
        $this->logger = $logger;
    }

    /**
     * Build a complete server configuration
     */
    public function buildServer($chassisUuid, $motherboardUuid, $serverName, $options = []) {
        $this->logger->logSection("Building Server: $serverName");

        // Create server
        $createResponse = $this->api->createServer($chassisUuid, $motherboardUuid, $serverName);

        if (!$createResponse['success']) {
            $this->logger->log("Failed to create server: " . $createResponse['message'], 'ERROR');
            return false;
        }

        $this->configId = $createResponse['data']['config_id'];
        $this->logger->log("Server created successfully. Config ID: {$this->configId}", 'SUCCESS');

        // Get initial configuration to check for onboard components
        $this->checkOnboardComponents();

        // Handle riser card requirement if specified
        if (isset($options['requires_riser']) && $options['requires_riser']) {
            $this->logger->log("This motherboard requires a riser card for PCIe slots", 'WARNING');
            if (!$this->installRiserCard()) {
                $this->logger->log("Failed to install riser card", 'ERROR');
                return false;
            }
        }

        // Add components for each type
        foreach ($this->componentTypes as $type) {
            $this->addComponentsOfType($type);
        }

        // Get final configuration
        $finalConfig = $this->api->getConfiguration($this->configId);

        if ($finalConfig['success']) {
            $this->logger->logConfigSummary($this->configId, $finalConfig['data']);
        }

        // Validate configuration
        $validation = $this->api->validateConfiguration($this->configId);

        if ($validation['success']) {
            $isValid = $validation['data']['validation_result']['is_valid'] ?? false;
            if ($isValid) {
                $this->logger->log("Configuration validation: PASSED", 'SUCCESS');
            } else {
                $this->logger->log("Configuration validation: FAILED", 'ERROR');
                if (isset($validation['data']['validation_result']['errors'])) {
                    foreach ($validation['data']['validation_result']['errors'] as $error) {
                        $this->logger->log("  - $error", 'ERROR');
                    }
                }
            }
        }

        return $this->configId;
    }

    /**
     * Check for onboard components (e.g., onboard NIC)
     */
    private function checkOnboardComponents() {
        $this->logger->log("Checking for onboard components...", 'INFO');

        $config = $this->api->getConfiguration($this->configId);

        if ($config['success'] && isset($config['data']['onboard_components'])) {
            $onboardComponents = $config['data']['onboard_components'];

            if (!empty($onboardComponents)) {
                $this->logger->log("Found onboard components:", 'SUCCESS');
                foreach ($onboardComponents as $type => $components) {
                    foreach ($components as $component) {
                        $this->logger->log("  - Onboard $type: " . ($component['name'] ?? $component['uuid']), 'SUCCESS');
                    }
                }
            } else {
                $this->logger->log("No onboard components detected", 'INFO');
            }
        }
    }

    /**
     * Install riser card for motherboards with only riser slots
     */
    private function installRiserCard() {
        $this->logger->log("Installing riser card...", 'INFO');

        // Get compatible risers
        $compatibility = $this->api->getCompatibility($this->configId, 'pciecard');

        if (!$compatibility['success']) {
            $this->logger->log("Failed to get riser compatibility", 'ERROR');
            return false;
        }

        $compatibleComponents = $compatibility['data']['compatible_components'] ?? [];

        // Filter for riser cards
        $riserCards = array_filter($compatibleComponents, function($component) {
            $name = strtolower($component['name'] ?? '');
            return strpos($name, 'riser') !== false;
        });

        if (empty($riserCards)) {
            $this->logger->log("No compatible riser cards found", 'ERROR');
            return false;
        }

        $riser = reset($riserCards);
        $this->logger->log("Found compatible riser: " . $riser['name'], 'INFO');

        $addResult = $this->api->addComponent($this->configId, 'pciecard', $riser['uuid'], 1);

        if ($addResult['success']) {
            $this->logger->log("Riser card installed successfully", 'SUCCESS');
            $this->logger->log("PCIe slots should now be available", 'INFO');

            // Verify that PCIe slots are now available
            $this->verifyPCIeSlotsUnlocked();

            return true;
        }

        $this->logger->log("Failed to install riser card", 'ERROR');
        return false;
    }

    /**
     * Verify that PCIe slots are unlocked after riser installation
     */
    private function verifyPCIeSlotsUnlocked() {
        $this->logger->log("Verifying PCIe slots are now available...", 'INFO');

        // Check compatibility for NICs and HBA cards
        foreach (['nic', 'hbacard', 'pciecard'] as $type) {
            $compatibility = $this->api->getCompatibility($this->configId, $type);

            if ($compatibility['success']) {
                $count = count($compatibility['data']['compatible_components'] ?? []);
                if ($count > 0) {
                    $this->logger->log("  ✓ {$count} compatible {$type}(s) now available", 'SUCCESS');
                } else {
                    $this->logger->log("  ✗ No compatible {$type}s available", 'WARNING');
                }
            }
        }
    }

    /**
     * Add components of a specific type
     */
    private function addComponentsOfType($type) {
        $this->logger->log("Processing component type: $type", 'INFO');

        $compatibility = $this->api->getCompatibility($this->configId, $type);

        if (!$compatibility['success']) {
            $this->logger->log("Failed to get compatibility for $type: " . $compatibility['message'], 'WARNING');
            return;
        }

        $compatibleComponents = $compatibility['data']['compatible_components'] ?? [];
        $availableSlots = $compatibility['data']['available_slots'] ?? 1;

        $this->logger->log("Found " . count($compatibleComponents) . " compatible {$type}(s)", 'INFO');
        $this->logger->log("Available slots: $availableSlots", 'DEBUG');

        if (empty($compatibleComponents)) {
            $this->logger->log("No compatible {$type}s available, skipping", 'WARNING');
            return;
        }

        // Select and add components based on available slots
        $componentsToAdd = min($availableSlots, count($compatibleComponents));

        for ($i = 0; $i < $componentsToAdd; $i++) {
            if (!isset($compatibleComponents[$i])) break;

            $component = $compatibleComponents[$i];
            $uuid = $component['uuid'];

            // Determine quantity (for RAM, storage, etc., we might want multiple units)
            $quantity = $this->determineQuantity($type, $component, $availableSlots);

            $result = $this->api->addComponent($this->configId, $type, $uuid, $quantity);

            if ($result['success']) {
                $this->logger->log("Successfully added {$type}: " . ($component['name'] ?? $uuid), 'SUCCESS');

                // For some types, adding one might affect subsequent compatibility
                if (in_array($type, ['cpu', 'motherboard'])) {
                    break; // Re-check compatibility after critical components
                }
            } else {
                $this->logger->log("Failed to add {$type}: " . $result['message'], 'ERROR');
            }
        }
    }

    /**
     * Determine optimal quantity for component
     */
    private function determineQuantity($type, $component, $availableSlots) {
        switch ($type) {
            case 'cpu':
                // Dual CPU support
                return min(2, $availableSlots);

            case 'ram':
                // Fill half the available RAM slots for testing
                return min(4, ceil($availableSlots / 2));

            case 'storage':
                // Add 2-4 storage devices
                return min(2, $availableSlots);

            default:
                return 1;
        }
    }

    public function getConfigId() {
        return $this->configId;
    }
}

class ServerBuildValidator {
    private $api;
    private $logger;
    private $builder;

    public function __construct($baseUrl, $logFile) {
        $this->logger = new TestLogger($logFile);
        $this->api = new IMSApiClient($baseUrl, $this->logger);
        $this->builder = new ServerConfigurationBuilder($this->api, $this->logger);
    }

    public function run() {
        // Authenticate
        if (!$this->api->authenticate()) {
            $this->logger->log("Authentication failed. Exiting.", 'ERROR');
            return false;
        }

        // Get available chassis and motherboards
        $chassis = $this->getAvailableComponents('chassis');
        $motherboards = $this->getAvailableComponents('motherboard');

        if (empty($chassis) || empty($motherboards)) {
            $this->logger->log("No chassis or motherboards available. Cannot proceed.", 'ERROR');
            return false;
        }

        // Build 3 server configurations
        $this->buildConfiguration1($chassis, $motherboards);
        $this->buildConfiguration2($chassis, $motherboards);
        $this->buildConfiguration3($chassis, $motherboards);

        // Finalize
        $this->logger->finalize();

        $this->logger->log("All server configurations completed!", 'SUCCESS');
        $this->logger->log("Check detailed logs in: " . __DIR__ . '/reports/server_build_validation.log', 'INFO');

        return true;
    }

    /**
     * Configuration 1: Standard server with all components
     */
    private function buildConfiguration1($chassis, $motherboards) {
        $this->logger->logSection("CONFIGURATION 1: Standard Full Server Build");

        $chassisUuid = $chassis[0]['uuid'];
        $motherboardUuid = $motherboards[0]['uuid'];

        $configId = $this->builder->buildServer(
            $chassisUuid,
            $motherboardUuid,
            "Standard Server Build #1"
        );

        // Delete configuration to free up components for next test
        if ($configId) {
            $deleteResult = $this->api->deleteConfiguration($configId);
            if ($deleteResult['success']) {
                $this->logger->log("Configuration deleted. Components freed for reuse.", 'SUCCESS');
            } else {
                $this->logger->log("Failed to delete configuration: " . ($deleteResult['message'] ?? 'Unknown error'), 'WARNING');
            }
        }
    }

    /**
     * Configuration 2: Server with riser slots only (edge case)
     */
    private function buildConfiguration2($chassis, $motherboards) {
        $this->logger->logSection("CONFIGURATION 2: Riser Slot Only Motherboard (Edge Case)");

        // Find a motherboard with only riser slots
        $riserOnlyMotherboard = $this->findRiserOnlyMotherboard($motherboards);

        if (!$riserOnlyMotherboard) {
            $this->logger->log("No riser-only motherboard found, using standard motherboard for demonstration", 'WARNING');
            $riserOnlyMotherboard = $motherboards[min(1, count($motherboards) - 1)];
        }

        $chassisUuid = $chassis[min(1, count($chassis) - 1)]['uuid'];

        $configId = $this->builder->buildServer(
            $chassisUuid,
            $riserOnlyMotherboard['uuid'],
            "Riser Slot Server Build #2",
            ['requires_riser' => true]
        );

        // Delete configuration to free up components for next test
        if ($configId) {
            $deleteResult = $this->api->deleteConfiguration($configId);
            if ($deleteResult['success']) {
                $this->logger->log("Configuration deleted. Components freed for reuse.", 'SUCCESS');
            } else {
                $this->logger->log("Failed to delete configuration: " . ($deleteResult['message'] ?? 'Unknown error'), 'WARNING');
            }
        }
    }

    /**
     * Configuration 3: Server with onboard NIC detection
     */
    private function buildConfiguration3($chassis, $motherboards) {
        $this->logger->logSection("CONFIGURATION 3: Onboard NIC Detection Test");

        // Find a motherboard with onboard NIC
        $onboardNICMotherboard = $this->findMotherboardWithOnboardNIC($motherboards);

        if (!$onboardNICMotherboard) {
            $this->logger->log("No motherboard with onboard NIC found in available list", 'WARNING');
            $onboardNICMotherboard = $motherboards[min(2, count($motherboards) - 1)];
        }

        $chassisUuid = $chassis[min(2, count($chassis) - 1)]['uuid'];

        $configId = $this->builder->buildServer(
            $chassisUuid,
            $onboardNICMotherboard['uuid'],
            "Onboard NIC Server Build #3"
        );

        // Delete configuration to free up components for next test
        if ($configId) {
            $deleteResult = $this->api->deleteConfiguration($configId);
            if ($deleteResult['success']) {
                $this->logger->log("Configuration deleted. Components freed for reuse.", 'SUCCESS');
            } else {
                $this->logger->log("Failed to delete configuration: " . ($deleteResult['message'] ?? 'Unknown error'), 'WARNING');
            }
        }
    }

    /**
     * Get available components of a type
     */
    private function getAvailableComponents($type) {
        $this->logger->log("Fetching available {$type}s...", 'INFO');

        $response = $this->api->listComponents($type);

        if ($response['success']) {
            $components = $response['data']['components'] ?? [];
            $this->logger->log("Found " . count($components) . " {$type}(s)", 'INFO');
            return $components;
        }

        $this->logger->log("Failed to fetch {$type}s", 'ERROR');
        return [];
    }

    /**
     * Find a motherboard that has only riser slots
     */
    private function findRiserOnlyMotherboard($motherboards) {
        foreach ($motherboards as $mb) {
            $specs = $mb['specifications'] ?? [];
            $pciSlots = $specs['pci_slots'] ?? [];
            $riserSlots = $specs['riser_slots'] ?? [];

            // Check if it has riser slots but no direct PCIe slots
            if (!empty($riserSlots) && empty($pciSlots)) {
                $this->logger->log("Found riser-only motherboard: " . ($mb['name'] ?? $mb['uuid']), 'SUCCESS');
                return $mb;
            }
        }

        return null;
    }

    /**
     * Find a motherboard with onboard NIC
     */
    private function findMotherboardWithOnboardNIC($motherboards) {
        foreach ($motherboards as $mb) {
            $specs = $mb['specifications'] ?? [];
            $onboardNIC = $specs['onboard_nic'] ?? false;

            if ($onboardNIC) {
                $this->logger->log("Found motherboard with onboard NIC: " . ($mb['name'] ?? $mb['uuid']), 'SUCCESS');
                return $mb;
            }
        }

        return null;
    }
}

// Main execution
if (php_sapi_name() === 'cli') {
    $baseUrl = $argv[1] ?? 'http://localhost:8000';

    $reportsDir = __DIR__ . '/reports';
    if (!is_dir($reportsDir)) {
        mkdir($reportsDir, 0777, true);
    }

    $logFile = $reportsDir . '/server_build_validation_' . date('Y-m-d_H-i-s') . '.log';

    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════════════╗\n";
    echo "║    BDC IMS - Complete Server Configuration Workflow Validation          ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "Base URL: $baseUrl\n";
    echo "Log File: $logFile\n";
    echo "\n";

    $validator = new ServerBuildValidator($baseUrl, $logFile);
    $success = $validator->run();

    exit($success ? 0 : 1);
}
