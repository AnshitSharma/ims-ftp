<?php
/**
 * API Test Runner for BDC IMS Staging Server
 * Tests against: https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php
 */

class APITester {
    private $baseUrl = 'https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php';
    private $accessToken = null;
    private $results = [];
    private $callCount = 0;

    public function __construct() {
        $this->baseUrl = 'https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php';
    }

    /**
     * Make API call
     */
    private function apiCall($action, $params = []) {
        $this->callCount++;
        $params['action'] = $action;

        $ch = curl_init($this->baseUrl);
        curl_setopt_array($ch, [
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
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        $result = [
            'call_num' => $this->callCount,
            'action' => $action,
            'http_code' => $httpCode,
            'success' => $data['success'] ?? false,
            'message' => $data['message'] ?? $error,
            'timestamp' => date('Y-m-d H:i:s'),
            'full_response' => $data
        ];

        $this->results[] = $result;

        echo sprintf(
            "[%d] %s - HTTP:%d Success:%s\n",
            $this->callCount,
            $action,
            $httpCode,
            $data['success'] ? 'YES' : 'NO'
        );

        return $data;
    }

    /**
     * Run authentication test
     */
    public function testAuthentication() {
        echo "\n=== AUTHENTICATION TEST ===\n";

        $response = $this->apiCall('auth-login', [
            'username' => 'superadmin',
            'password' => 'password'
        ]);

        if ($response['success'] && isset($response['data']['tokens']['access_token'])) {
            $this->accessToken = $response['data']['tokens']['access_token'];
            echo "✓ Authentication successful\n";
            return true;
        } else {
            echo "✗ Authentication failed\n";
            return false;
        }
    }

    /**
     * Run component listing tests
     */
    public function testComponentListing() {
        echo "\n=== COMPONENT LISTING TESTS ===\n";

        $types = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy', 'chassis', 'pciecard', 'hbacard'];

        foreach ($types as $type) {
            $response = $this->apiCall("{$type}-list");
            $count = count($response['data']['components'] ?? []);
            echo "  → {$type}: {$count} items\n";
        }
    }

    /**
     * Run server build workflow tests
     */
    public function testServerBuildWorkflow() {
        echo "\n=== SERVER BUILD WORKFLOW TESTS ===\n";

        // Get chassis and motherboard
        $chassis = $this->apiCall('chassis-list');
        $motherboards = $this->apiCall('motherboard-list');

        $chassisUuid = $chassis['data']['components'][0]['uuid'] ?? null;
        $motherboardUuid = $motherboards['data']['components'][0]['uuid'] ?? null;

        if (!$chassisUuid || !$motherboardUuid) {
            echo "✗ Missing chassis or motherboard\n";
            return false;
        }

        // Create server
        echo "\n--- Creating Server Configuration ---\n";
        $createResponse = $this->apiCall('server-create', [
            'chassis_uuid' => $chassisUuid,
            'motherboard_uuid' => $motherboardUuid,
            'server_name' => 'Test Server ' . time()
        ]);

        $configId = $createResponse['data']['config_id'] ?? null;
        if (!$configId) {
            echo "✗ Failed to create server\n";
            return false;
        }
        echo "✓ Server created with config ID: $configId\n";

        // Get configuration
        echo "\n--- Getting Server Configuration ---\n";
        $this->apiCall('server-get-config', [
            'config_id' => $configId
        ]);

        // Test compatibility for different component types
        echo "\n--- Testing Component Compatibility ---\n";
        $types = ['cpu', 'ram', 'storage', 'nic'];

        foreach ($types as $type) {
            $response = $this->apiCall('server-get-compatibility', [
                'config_id' => $configId,
                'component_type' => $type
            ]);
            $count = count($response['data']['compatible_components'] ?? []);
            echo "  → {$type}: {$count} compatible items\n";
        }

        // Add CPU component
        echo "\n--- Adding Components ---\n";
        $cpuList = $this->apiCall('cpu-list');
        $cpuUuid = $cpuList['data']['components'][0]['uuid'] ?? null;

        if ($cpuUuid) {
            $this->apiCall('server-add-component', [
                'config_id' => $configId,
                'component_type' => 'cpu',
                'component_uuid' => $cpuUuid,
                'quantity' => 1
            ]);
        }

        // Add RAM
        $ramList = $this->apiCall('ram-list');
        $ramUuid = $ramList['data']['components'][0]['uuid'] ?? null;

        if ($ramUuid) {
            $this->apiCall('server-add-component', [
                'config_id' => $configId,
                'component_type' => 'ram',
                'component_uuid' => $ramUuid,
                'quantity' => 4
            ]);
        }

        // Validate configuration
        echo "\n--- Validating Configuration ---\n";
        $validationResponse = $this->apiCall('server-validate-config', [
            'config_id' => $configId
        ]);

        if ($validationResponse['success']) {
            $isValid = $validationResponse['data']['validation_result']['is_valid'] ?? false;
            echo "  → Validation Result: " . ($isValid ? 'VALID' : 'INVALID') . "\n";
            if (!$isValid && isset($validationResponse['data']['validation_result']['errors'])) {
                foreach ($validationResponse['data']['validation_result']['errors'] as $error) {
                    echo "    - $error\n";
                }
            }
        }

        // Finalize configuration
        echo "\n--- Finalizing Configuration ---\n";
        $this->apiCall('server-finalize-config', [
            'config_id' => $configId
        ]);

        // Delete configuration
        echo "\n--- Deleting Configuration ---\n";
        $this->apiCall('server-delete-config', [
            'config_id' => $configId
        ]);

        return true;
    }

    /**
     * Generate summary report
     */
    public function generateReport() {
        echo "\n\n";
        echo str_repeat('=', 100) . "\n";
        echo "TEST SUMMARY REPORT\n";
        echo str_repeat('=', 100) . "\n";
        echo "Total API Calls: " . $this->callCount . "\n";
        echo "Timestamp: " . date('Y-m-d H:i:s') . "\n";
        echo "\n";

        // Group by action
        $byAction = [];
        foreach ($this->results as $result) {
            $action = $result['action'];
            if (!isset($byAction[$action])) {
                $byAction[$action] = ['success' => 0, 'failed' => 0];
            }
            if ($result['success']) {
                $byAction[$action]['success']++;
            } else {
                $byAction[$action]['failed']++;
            }
        }

        echo "Results by Action:\n";
        echo str_repeat('-', 100) . "\n";
        printf("%-40s | %10s | %10s | %10s\n", "Action", "Success", "Failed", "Total");
        echo str_repeat('-', 100) . "\n";

        foreach ($byAction as $action => $counts) {
            $total = $counts['success'] + $counts['failed'];
            printf("%-40s | %10d | %10d | %10d\n", $action, $counts['success'], $counts['failed'], $total);
        }

        echo str_repeat('=', 100) . "\n";

        // Detailed results
        echo "\nDetailed Results:\n";
        echo str_repeat('-', 100) . "\n";

        foreach ($this->results as $result) {
            $status = $result['success'] ? '✓' : '✗';
            printf(
                "%s [%d] %s (HTTP:%d) - %s\n",
                $status,
                $result['call_num'],
                $result['action'],
                $result['http_code'],
                $result['message']
            );
        }

        echo str_repeat('=', 100) . "\n";

        // Save detailed JSON report
        $reportFile = __DIR__ . '/reports/api_test_' . date('Y-m-d_H-i-s') . '.json';
        @mkdir(dirname($reportFile), 0777, true);
        file_put_contents($reportFile, json_encode($this->results, JSON_PRETTY_PRINT));
        echo "\nDetailed report saved to: $reportFile\n";
    }

    /**
     * Run all tests
     */
    public function runAll() {
        echo "╔══════════════════════════════════════════════════════════════════════════╗\n";
        echo "║         BDC IMS - API Test Suite (Staging Server)                       ║\n";
        echo "║    URL: https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php   ║\n";
        echo "╚══════════════════════════════════════════════════════════════════════════╝\n";

        if (!$this->testAuthentication()) {
            return false;
        }

        $this->testComponentListing();
        $this->testServerBuildWorkflow();
        $this->generateReport();

        return true;
    }
}

// Run tests
$tester = new APITester();
$tester->runAll();
