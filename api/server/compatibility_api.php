<?php
/**
 * Infrastructure Management System - Compatibility API Endpoint
 * File: api/server/compatibility_api.php
 * 
 * Dedicated endpoint for compatibility checking operations
 */

// Include required files
require_once(__DIR__ . '/../../includes/db_config.php');
require_once(__DIR__ . '/../../includes/BaseFunctions.php');
require_once(__DIR__ . '/../../includes/models/ComponentCompatibility.php');

// Ensure user is authenticated
$user = authenticateWithJWT($pdo);
if (!$user) {
    send_json_response(0, 0, 401, "Authentication required");
}

// Check permissions
if (!hasPermission($pdo, 'compatibility.check', $user['id'])) {
    send_json_response(0, 1, 403, "Insufficient permissions for compatibility checking");
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'check_pair':
            handleCheckPairCompatibility();
            break;
            
        case 'check_multiple':
            handleCheckMultipleCompatibility();
            break;
            
        case 'get_compatible_for':
            handleGetCompatibleComponents();
            break;
            
        case 'batch_check':
            handleBatchCompatibilityCheck();
            break;
            
        case 'analyze_configuration':
            handleAnalyzeConfiguration();
            break;
            
        case 'get_rules':
            handleGetCompatibilityRules();
            break;
            
        case 'test_rule':
            handleTestCompatibilityRule();
            break;
            
        case 'get_statistics':
            handleGetCompatibilityStatistics();
            break;
            
        case 'clear_cache':
            handleClearCompatibilityCache();
            break;
            
        case 'benchmark_performance':
            handleBenchmarkPerformance();
            break;
            
        case 'export_rules':
            handleExportCompatibilityRules();
            break;
            
        case 'import_rules':
            handleImportCompatibilityRules();
            break;
            
        case 'check_storage_direct':
            handleCheckStorageCompatibilityDirect();
            break;
            
        case 'check_storage_recursive':
            handleCheckStorageCompatibilityRecursive();
            break;
            
        default:
            send_json_response(0, 1, 400, "Invalid compatibility action: $action");
    }
} catch (Exception $e) {
    error_log("Compatibility API error: " . $e->getMessage());
    send_json_response(0, 1, 500, "Compatibility check failed: " . $e->getMessage());
}

/**
 * Check compatibility between two components
 */
function handleCheckPairCompatibility() {
    global $pdo;
    
    $component1Type = $_POST['component1_type'] ?? '';
    $component1Uuid = $_POST['component1_uuid'] ?? '';
    $component2Type = $_POST['component2_type'] ?? '';
    $component2Uuid = $_POST['component2_uuid'] ?? '';
    $includeDetails = filter_var($_POST['include_details'] ?? true, FILTER_VALIDATE_BOOLEAN);
    
    if (empty($component1Type) || empty($component1Uuid) || empty($component2Type) || empty($component2Uuid)) {
        send_json_response(0, 1, 400, "All component parameters are required");
    }
    
    try {
        $componentCompatibility = new ComponentCompatibility($pdo);

        $component1 = ['type' => $component1Type, 'uuid' => $component1Uuid];
        $component2 = ['type' => $component2Type, 'uuid' => $component2Uuid];

        $result = $componentCompatibility->checkComponentPairCompatibility($component1, $component2);

        $response = [
            'component_1' => $component1,
            'component_2' => $component2,
            'compatibility_result' => $result,
            'summary' => [
                'compatible' => $result['compatible'],
                'issues_count' => count($result['issues'] ?? []),
                'warnings_count' => count($result['warnings'] ?? [])
            ]
        ];

        if ($includeDetails) {
            $response['detailed_analysis'] = $result;
        }

        send_json_response(1, 1, 200, "Compatibility check completed", $response);
        
    } catch (Exception $e) {
        error_log("Error in pair compatibility check: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to check compatibility");
    }
}

/**
 * Check compatibility among multiple components
 */
function handleCheckMultipleCompatibility() {
    global $pdo;
    
    $components = $_POST['components'] ?? [];
    $crossCheck = filter_var($_POST['cross_check'] ?? true, FILTER_VALIDATE_BOOLEAN);
    
    if (empty($components) || !is_array($components)) {
        send_json_response(0, 1, 400, "Components array is required");
    }
    
    if (count($components) < 2) {
        send_json_response(0, 1, 400, "At least 2 components required for compatibility check");
    }
    
    try {
        $componentCompatibility = new ComponentCompatibility($pdo);
        $results = [];
        $overallCompatible = true;
        $totalIssues = 0;
        $totalWarnings = 0;

        if ($crossCheck) {
            // Check every component against every other component
            for ($i = 0; $i < count($components); $i++) {
                for ($j = $i + 1; $j < count($components); $j++) {
                    $component1 = $components[$i];
                    $component2 = $components[$j];

                    $result = $componentCompatibility->checkComponentPairCompatibility($component1, $component2);

                    $results[] = [
                        'component_1' => $component1,
                        'component_2' => $component2,
                        'result' => $result
                    ];

                    if (!$result['compatible']) {
                        $overallCompatible = false;
                    }

                    $totalIssues += count($result['issues'] ?? []);
                    $totalWarnings += count($result['warnings'] ?? []);
                }
            }
        } else {
            // Check components sequentially (1-2, 2-3, 3-4, etc.)
            for ($i = 0; $i < count($components) - 1; $i++) {
                $component1 = $components[$i];
                $component2 = $components[$i + 1];

                $result = $componentCompatibility->checkComponentPairCompatibility($component1, $component2);

                $results[] = [
                    'component_1' => $component1,
                    'component_2' => $component2,
                    'result' => $result
                ];

                if (!$result['compatible']) {
                    $overallCompatible = false;
                }

                $totalIssues += count($result['issues'] ?? []);
                $totalWarnings += count($result['warnings'] ?? []);
            }
        }
        
        send_json_response(1, 1, 200, "Multiple component compatibility check completed", [
            'components' => $components,
            'check_type' => $crossCheck ? 'cross_check' : 'sequential',
            'individual_results' => $results,
            'overall_summary' => [
                'compatible' => $overallCompatible,
                'total_checks' => count($results),
                'total_issues' => $totalIssues,
                'total_warnings' => $totalWarnings
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error in multiple compatibility check: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to check multiple component compatibility");
    }
}

/**
 * Get compatible components for a specific component
 */
function handleGetCompatibleComponents() {
    global $pdo;

    $baseComponentType = $_GET['base_component_type'] ?? $_POST['base_component_type'] ?? '';
    $baseComponentUuid = $_GET['base_component_uuid'] ?? $_POST['base_component_uuid'] ?? '';
    $targetType = $_GET['target_type'] ?? $_POST['target_type'] ?? '';
    $availableOnly = filter_var($_GET['available_only'] ?? $_POST['available_only'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $limit = (int)($_GET['limit'] ?? $_POST['limit'] ?? 50);
    $includeScores = filter_var($_GET['include_scores'] ?? $_POST['include_scores'] ?? true, FILTER_VALIDATE_BOOLEAN);

    if (empty($baseComponentType) || empty($baseComponentUuid)) {
        send_json_response(0, 1, 400, "Base component type and UUID are required");
    }

    // DEPRECATED: Use server-get-compatible endpoint instead
    send_json_response(0, 1, 501, "This endpoint is deprecated. Use 'server-get-compatible' instead.");
    return;

    try {
        $componentCompatibility = new ComponentCompatibility($pdo);
        $baseComponent = ['type' => $baseComponentType, 'uuid' => $baseComponentUuid];
        
        if ($targetType) {
            // Get compatible components for specific type
            $compatibleComponents = $compatibilityEngine->getCompatibleComponents($baseComponent, $targetType, $availableOnly);
            
            // Apply limit
            if ($limit > 0) {
                $compatibleComponents = array_slice($compatibleComponents, 0, $limit);
            }
            
            $response = [
                'base_component' => $baseComponent,
                'target_type' => $targetType,
                'compatible_components' => $compatibleComponents,
                'total_found' => count($compatibleComponents)
            ];
        } else {
            // Get compatible components for all types
            $allTypes = ['cpu', 'motherboard', 'ram', 'storage', 'nic', 'caddy'];
            $allCompatible = [];
            
            foreach ($allTypes as $type) {
                if ($type !== $baseComponentType) {
                    $typeComponents = $compatibilityEngine->getCompatibleComponents($baseComponent, $type, $availableOnly);
                    
                    if ($limit > 0) {
                        $typeComponents = array_slice($typeComponents, 0, $limit);
                    }
                    
                    $allCompatible[$type] = $typeComponents;
                }
            }
            
            $response = [
                'base_component' => $baseComponent,
                'compatible_components' => $allCompatible,
                'summary' => array_map(function($components) {
                    return ['count' => count($components)];
                }, $allCompatible)
            ];
        }
        
        send_json_response(1, 1, 200, "Compatible components retrieved", $response);
        
    } catch (Exception $e) {
        error_log("Error getting compatible components: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to get compatible components");
    }
}

/**
 * Batch compatibility check for multiple component pairs
 */
function handleBatchCompatibilityCheck() {
    global $pdo;
    
    $componentPairs = $_POST['component_pairs'] ?? [];
    $stopOnFirstFailure = filter_var($_POST['stop_on_first_failure'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    if (empty($componentPairs) || !is_array($componentPairs)) {
        send_json_response(0, 1, 400, "Component pairs array is required");
    }
    
    try {
        $componentCompatibility = new ComponentCompatibility($pdo);
        $results = [];
        $overallStats = [
            'total_checks' => 0,
            'successful_checks' => 0,
            'failed_checks' => 0,
            'average_score' => 0.0,
            'total_execution_time' => 0
        ];
        
        foreach ($componentPairs as $index => $pair) {
            if (!isset($pair['component1']) || !isset($pair['component2'])) {
                $results[] = [
                    'pair_index' => $index,
                    'error' => 'Invalid component pair format'
                ];
                continue;
            }
            
            $startTime = microtime(true);
            
            $result = $componentCompatibility->checkComponentPairCompatibility($pair['component1'], $pair['component2']);
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            
            $results[] = [
                'pair_index' => $index,
                'component_1' => $pair['component1'],
                'component_2' => $pair['component2'],
                'result' => $result,
                'execution_time_ms' => round($executionTime, 2)
            ];
            
            // Update stats
            $overallStats['total_checks']++;
            $overallStats['total_execution_time'] += $executionTime;

            if ($result['compatible']) {
                $overallStats['successful_checks']++;
            } else {
                $overallStats['failed_checks']++;

                if ($stopOnFirstFailure) {
                    break;
                }
            }
        }
        
        // Calculate final averages
        if ($overallStats['total_checks'] > 0) {
            $overallStats['average_score'] /= $overallStats['total_checks'];
            $overallStats['success_rate'] = ($overallStats['successful_checks'] / $overallStats['total_checks']) * 100;
        }
        
        send_json_response(1, 1, 200, "Batch compatibility check completed", [
            'results' => $results,
            'statistics' => $overallStats,
            'stopped_early' => $stopOnFirstFailure && $overallStats['failed_checks'] > 0
        ]);
        
    } catch (Exception $e) {
        error_log("Error in batch compatibility check: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to perform batch compatibility check");
    }
}

/**
 * Analyze complete server configuration
 */
function handleAnalyzeConfiguration() {
    global $pdo;
    
    $configuration = $_POST['configuration'] ?? [];
    $includeRecommendations = filter_var($_POST['include_recommendations'] ?? true, FILTER_VALIDATE_BOOLEAN);
    
    if (empty($configuration)) {
        send_json_response(0, 1, 400, "Server configuration is required");
    }
    
    try {
        $componentCompatibility = new ComponentCompatibility($pdo);
        
        // Validate the configuration structure
        $validationResult = $compatibilityEngine->validateServerConfiguration($configuration);
        
        $response = [
            'configuration_analysis' => $validationResult,
            'summary' => [
                'overall_valid' => $validationResult['valid'],
                'overall_score' => $validationResult['overall_score'],
                'component_check_count' => count($validationResult['component_checks']),
                'global_check_count' => count($validationResult['global_checks'])
            ]
        ];
        
        if ($includeRecommendations) {
            $response['recommendations'] = generateConfigurationRecommendations($validationResult);
        }
        
        send_json_response(1, 1, 200, "Configuration analysis completed", $response);
        
    } catch (Exception $e) {
        error_log("Error analyzing configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to analyze configuration");
    }
}

/**
 * Get compatibility rules
 */
function handleGetCompatibilityRules() {
    global $pdo;
    
    $ruleType = $_GET['rule_type'] ?? $_POST['rule_type'] ?? '';
    $componentTypes = $_GET['component_types'] ?? $_POST['component_types'] ?? '';
    $activeOnly = filter_var($_GET['active_only'] ?? $_POST['active_only'] ?? true, FILTER_VALIDATE_BOOLEAN);
    
    try {
        $query = "SELECT * FROM compatibility_rules WHERE 1=1";
        $params = [];
        
        if ($ruleType) {
            $query .= " AND rule_type = ?";
            $params[] = $ruleType;
        }
        
        if ($componentTypes) {
            $query .= " AND component_types LIKE ?";
            $params[] = "%$componentTypes%";
        }
        
        if ($activeOnly) {
            $query .= " AND is_active = 1";
        }
        
        $query .= " ORDER BY rule_priority ASC, rule_name ASC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Decode JSON rule definitions
        foreach ($rules as &$rule) {
            $rule['rule_definition'] = json_decode($rule['rule_definition'], true);
        }
        
        send_json_response(1, 1, 200, "Compatibility rules retrieved", [
            'rules' => $rules,
            'total_count' => count($rules),
            'filters_applied' => [
                'rule_type' => $ruleType,
                'component_types' => $componentTypes,
                'active_only' => $activeOnly
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting compatibility rules: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to get compatibility rules");
    }
}

/**
 * Test a compatibility rule
 */
function handleTestCompatibilityRule() {
    global $pdo;
    
    $ruleId = $_POST['rule_id'] ?? '';
    $testComponents = $_POST['test_components'] ?? [];
    
    if (empty($ruleId) || empty($testComponents)) {
        send_json_response(0, 1, 400, "Rule ID and test components are required");
    }
    
    try {
        // Get the rule
        $stmt = $pdo->prepare("SELECT * FROM compatibility_rules WHERE id = ?");
        $stmt->execute([$ruleId]);
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$rule) {
            send_json_response(0, 1, 404, "Compatibility rule not found");
        }
        
        $rule['rule_definition'] = json_decode($rule['rule_definition'], true);
        
        // Test the rule against provided components
        $componentCompatibility = new ComponentCompatibility($pdo);
        $testResults = [];
        
        foreach ($testComponents as $testPair) {
            if (isset($testPair['component1']) && isset($testPair['component2'])) {
                $result = $componentCompatibility->checkComponentPairCompatibility($testPair['component1'], $testPair['component2']);
                
                $testResults[] = [
                    'component_1' => $testPair['component1'],
                    'component_2' => $testPair['component2'],
                    'rule_applied' => in_array($rule['rule_name'], $result['applied_rules']),
                    'result' => $result
                ];
            }
        }
        
        send_json_response(1, 1, 200, "Rule test completed", [
            'rule' => $rule,
            'test_results' => $testResults,
            'test_summary' => [
                'total_tests' => count($testResults),
                'rule_triggered' => count(array_filter($testResults, function($test) { return $test['rule_applied']; }))
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error testing compatibility rule: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to test compatibility rule");
    }
}

/**
 * Get compatibility statistics
 */
function handleGetCompatibilityStatistics() {
    global $pdo;
    
    $timeframe = $_GET['timeframe'] ?? $_POST['timeframe'] ?? '24 HOUR';
    $includeDetails = filter_var($_GET['include_details'] ?? $_POST['include_details'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    try {
        $componentCompatibility = new ComponentCompatibility($pdo);
        $statistics = $compatibilityEngine->getCompatibilityStatistics($timeframe);
        
        $response = [
            'timeframe' => $timeframe,
            'statistics' => $statistics
        ];
        
        if ($includeDetails) {
            // Get additional detailed statistics
            $response['detailed_stats'] = getDetailedCompatibilityStats($pdo, $timeframe);
        }
        
        send_json_response(1, 1, 200, "Compatibility statistics retrieved", $response);
        
    } catch (Exception $e) {
        error_log("Error getting compatibility statistics: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to get compatibility statistics");
    }
}

/**
 * Clear compatibility cache
 */
function handleClearCompatibilityCache() {
    global $pdo;
    
    $cacheType = $_POST['cache_type'] ?? 'all'; // 'rules', 'components', 'results', 'all'
    
    try {
        $clearedItems = 0;
        
        switch ($cacheType) {
            case 'rules':
                // Clear rules cache (implementation depends on caching system)
                $clearedItems = clearRulesCache($pdo);
                break;
                
            case 'components':
                // Clear component data cache
                $componentCompatibility = new ComponentCompatibility($pdo);
                $componentCompatibility->clearCache();
                $clearedItems = count($componentCompatibility->getCacheStats()['cached_components']);
                break;
                
            case 'results':
                // Clear compatibility results cache
                $stmt = $pdo->prepare("DELETE FROM compatibility_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
                $stmt->execute();
                $clearedItems = $stmt->rowCount();
                break;
                
            case 'all':
                // Clear all caches
                $clearedItems += clearRulesCache($pdo);
                $componentCompatibility = new ComponentCompatibility($pdo);
                $componentCompatibility->clearCache();
                $stmt = $pdo->prepare("DELETE FROM compatibility_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
                $stmt->execute();
                $clearedItems += $stmt->rowCount();
                break;
                
            default:
                send_json_response(0, 1, 400, "Invalid cache type. Use: rules, components, results, or all");
        }
        
        send_json_response(1, 1, 200, "Cache cleared successfully", [
            'cache_type' => $cacheType,
            'items_cleared' => $clearedItems,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        error_log("Error clearing compatibility cache: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to clear compatibility cache");
    }
}

/**
 * Benchmark compatibility engine performance
 */
function handleBenchmarkPerformance() {
    global $pdo;
    
    $iterations = (int)($_POST['iterations'] ?? 100);
    $includeDetails = filter_var($_POST['include_details'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    if ($iterations < 1 || $iterations > 1000) {
        send_json_response(0, 1, 400, "Iterations must be between 1 and 1000");
    }
    
    try {
        $componentCompatibility = new ComponentCompatibility($pdo);
        
        // Get sample components for testing
        $sampleComponents = getSampleComponentsForBenchmark($pdo);
        
        if (count($sampleComponents) < 2) {
            send_json_response(0, 1, 400, "Insufficient components for benchmarking");
        }
        
        $results = [];
        $totalTime = 0;
        $successCount = 0;
        
        for ($i = 0; $i < $iterations; $i++) {
            // Select random components
            $component1 = $sampleComponents[array_rand($sampleComponents)];
            $component2 = $sampleComponents[array_rand($sampleComponents)];
            
            if ($component1['type'] === $component2['type']) {
                continue; // Skip same type components
            }
            
            $startTime = microtime(true);
            
            $result = $componentCompatibility->checkComponentPairCompatibility($component1, $component2);
            
            $endTime = microtime(true);
            $executionTime = ($endTime - $startTime) * 1000;
            
            $totalTime += $executionTime;
            
            if ($result['compatible']) {
                $successCount++;
            }
            
            if ($includeDetails) {
                $results[] = [
                    'iteration' => $i + 1,
                    'component_1' => $component1,
                    'component_2' => $component2,
                    'execution_time_ms' => round($executionTime, 3),
                    'compatible' => $result['compatible']
                ];
            }
        }
        
        $benchmarkResults = [
            'iterations_completed' => count($includeDetails ? $results : [$iterations]),
            'total_execution_time_ms' => round($totalTime, 3),
            'average_execution_time_ms' => round($totalTime / $iterations, 3),
            'success_rate' => round(($successCount / $iterations) * 100, 2),
            'checks_per_second' => round($iterations / ($totalTime / 1000), 2)
        ];
        
        if ($includeDetails) {
            $benchmarkResults['detailed_results'] = $results;
        }
        
        send_json_response(1, 1, 200, "Performance benchmark completed", $benchmarkResults);
        
    } catch (Exception $e) {
        error_log("Error in performance benchmark: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to run performance benchmark");
    }
}

/**
 * Export compatibility rules
 */
function handleExportCompatibilityRules() {
    global $pdo;
    
    $format = $_GET['format'] ?? $_POST['format'] ?? 'json';
    $activeOnly = filter_var($_GET['active_only'] ?? $_POST['active_only'] ?? true, FILTER_VALIDATE_BOOLEAN);
    
    try {
        $query = "SELECT * FROM compatibility_rules";
        $params = [];
        
        if ($activeOnly) {
            $query .= " WHERE is_active = 1";
        }
        
        $query .= " ORDER BY rule_priority ASC, rule_name ASC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Decode JSON rule definitions
        foreach ($rules as &$rule) {
            $rule['rule_definition'] = json_decode($rule['rule_definition'], true);
        }
        
        $exportData = [
            'export_timestamp' => date('Y-m-d H:i:s'),
            'total_rules' => count($rules),
            'active_only' => $activeOnly,
            'rules' => $rules
        ];
        
        switch ($format) {
            case 'json':
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="compatibility_rules_' . date('Y-m-d_H-i-s') . '.json"');
                echo json_encode($exportData, JSON_PRETTY_PRINT);
                exit();
                
            case 'csv':
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="compatibility_rules_' . date('Y-m-d_H-i-s') . '.csv"');
                
                $output = fopen('php://output', 'w');
                fputcsv($output, ['ID', 'Rule Name', 'Rule Type', 'Component Types', 'Priority', 'Active', 'Created At']);
                
                foreach ($rules as $rule) {
                    fputcsv($output, [
                        $rule['id'],
                        $rule['rule_name'],
                        $rule['rule_type'],
                        $rule['component_types'],
                        $rule['rule_priority'],
                        $rule['is_active'] ? 'Yes' : 'No',
                        $rule['created_at']
                    ]);
                }
                
                fclose($output);
                exit();
                
            default:
                send_json_response(0, 1, 400, "Invalid export format. Use: json or csv");
        }
        
    } catch (Exception $e) {
        error_log("Error exporting compatibility rules: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to export compatibility rules");
    }
}

/**
 * Import compatibility rules
 */
function handleImportCompatibilityRules() {
    global $pdo, $user;
    
    if (!isset($_FILES['rules_file'])) {
        send_json_response(0, 1, 400, "Rules file is required");
    }
    
    $file = $_FILES['rules_file'];
    $overwriteExisting = filter_var($_POST['overwrite_existing'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    try {
        $fileContent = file_get_contents($file['tmp_name']);
        $importData = json_decode($fileContent, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            send_json_response(0, 1, 400, "Invalid JSON file format");
        }
        
        if (!isset($importData['rules']) || !is_array($importData['rules'])) {
            send_json_response(0, 1, 400, "Invalid rules file structure");
        }
        
        $importedCount = 0;
        $skippedCount = 0;
        $errors = [];
        
        $pdo->beginTransaction();
        
        foreach ($importData['rules'] as $ruleData) {
            try {
                // Check if rule already exists
                $stmt = $pdo->prepare("SELECT id FROM compatibility_rules WHERE rule_name = ?");
                $stmt->execute([$ruleData['rule_name']]);
                $existingRule = $stmt->fetch();
                
                if ($existingRule && !$overwriteExisting) {
                    $skippedCount++;
                    continue;
                }
                
                // Prepare rule data
                $ruleDefinition = is_array($ruleData['rule_definition']) 
                    ? json_encode($ruleData['rule_definition']) 
                    : $ruleData['rule_definition'];
                
                if ($existingRule && $overwriteExisting) {
                    // Update existing rule
                    $stmt = $pdo->prepare("
                        UPDATE compatibility_rules 
                        SET rule_type = ?, component_types = ?, rule_definition = ?, 
                            rule_priority = ?, failure_message = ?, is_override_allowed = ?, 
                            is_active = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $ruleData['rule_type'],
                        $ruleData['component_types'],
                        $ruleDefinition,
                        $ruleData['rule_priority'],
                        $ruleData['failure_message'],
                        $ruleData['is_override_allowed'] ?? 0,
                        $ruleData['is_active'] ?? 1,
                        $existingRule['id']
                    ]);
                } else {
                    // Insert new rule
                    $stmt = $pdo->prepare("
                        INSERT INTO compatibility_rules 
                        (rule_name, rule_type, component_types, rule_definition, rule_priority, 
                         failure_message, is_override_allowed, is_active, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([
                        $ruleData['rule_name'],
                        $ruleData['rule_type'],
                        $ruleData['component_types'],
                        $ruleDefinition,
                        $ruleData['rule_priority'],
                        $ruleData['failure_message'],
                        $ruleData['is_override_allowed'] ?? 0,
                        $ruleData['is_active'] ?? 1
                    ]);
                }
                
                $importedCount++;
                
            } catch (Exception $e) {
                $errors[] = "Rule '{$ruleData['rule_name']}': " . $e->getMessage();
            }
        }
        
        $pdo->commit();
        
        send_json_response(1, 1, 200, "Rules import completed", [
            'imported_count' => $importedCount,
            'skipped_count' => $skippedCount,
            'error_count' => count($errors),
            'errors' => $errors,
            'overwrite_mode' => $overwriteExisting
        ]);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error importing compatibility rules: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to import compatibility rules");
    }
}

/**
 * Generate configuration recommendations
 */
function generateConfigurationRecommendations($validationResult) {
    $recommendations = [];
    
    // Performance recommendations
    if ($validationResult['overall_score'] < 0.8) {
        $recommendations[] = [
            'type' => 'performance',
            'priority' => 'medium',
            'message' => 'Consider optimizing component selection for better compatibility',
            'details' => 'Current compatibility score is below optimal threshold',
            'suggested_actions' => [
                'Review component compatibility matrix',
                'Consider alternative components with higher compatibility scores',
                'Check for newer component revisions'
            ]
        ];
    }
    
    
    // Component-specific recommendations
    foreach ($validationResult['component_checks'] as $check) {
        if (!$check['compatible']) {
            $recommendations[] = [
                'type' => 'compatibility',
                'priority' => 'high',
                'message' => "Compatibility issue: {$check['components']}",
                'details' => implode(', ', $check['issues']),
                'suggested_actions' => [
                    'Review component specifications',
                    'Consider alternative components',
                    'Check for compatibility overrides if applicable'
                ]
            ];
        } elseif (!empty($check['warnings'])) {
            $recommendations[] = [
                'type' => 'warning',
                'priority' => 'low',
                'message' => "Minor issue: {$check['components']}",
                'details' => implode(', ', $check['warnings']),
                'suggested_actions' => [
                    'Monitor performance in production',
                    'Consider optimization opportunities',
                    'Review vendor compatibility notes'
                ]
            ];
        }
    }
    
    // Global check recommendations
    foreach ($validationResult['global_checks'] as $check) {
        if (!$check['passed']) {
            $priority = strpos(strtolower($check['message']), 'critical') !== false ? 'high' : 'medium';
            $recommendations[] = [
                'type' => 'system',
                'priority' => $priority,
                'message' => "System requirement: {$check['check']}",
                'details' => $check['message'],
                'suggested_actions' => [
                    'Address system-level requirements',
                    'Verify configuration standards',
                    'Consult documentation for best practices'
                ]
            ];
        }
    }
    
    
    return $recommendations;
}

/**
 * Get detailed compatibility statistics
 */
function getDetailedCompatibilityStats($pdo, $timeframe) {
    try {
        $stats = [];
        
        // Most common compatibility failures
        $stmt = $pdo->prepare("
            SELECT 
                component_type_1,
                component_type_2,
                COUNT(*) as failure_count,
                AVG(compatibility_score) as avg_score
            FROM compatibility_log 
            WHERE compatibility_result = 0 
                AND created_at >= DATE_SUB(NOW(), INTERVAL 1 $timeframe)
            GROUP BY component_type_1, component_type_2
            ORDER BY failure_count DESC
            LIMIT 10
        ");
        $stmt->execute();
        $stats['common_failures'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Performance metrics by component type
        $stmt = $pdo->prepare("
            SELECT 
                CONCAT(component_type_1, '-', component_type_2) as component_pair,
                COUNT(*) as check_count,
                AVG(execution_time_ms) as avg_execution_time,
                SUM(CASE WHEN compatibility_result = 1 THEN 1 ELSE 0 END) as success_count,
                MIN(execution_time_ms) as min_execution_time,
                MAX(execution_time_ms) as max_execution_time
            FROM compatibility_log 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 $timeframe)
                AND execution_time_ms IS NOT NULL
            GROUP BY component_type_1, component_type_2
            ORDER BY check_count DESC
        ");
        $stmt->execute();
        $stats['performance_metrics'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Rule effectiveness
        $stmt = $pdo->prepare("
            SELECT 
                JSON_UNQUOTE(JSON_EXTRACT(applied_rules, '$[0]')) as primary_rule,
                COUNT(*) as usage_count,
                SUM(CASE WHEN compatibility_result = 1 THEN 1 ELSE 0 END) as success_count,
                AVG(compatibility_score) as avg_score
            FROM compatibility_log 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 $timeframe)
                AND applied_rules IS NOT NULL
                AND JSON_LENGTH(applied_rules) > 0
            GROUP BY primary_rule
            ORDER BY usage_count DESC
            LIMIT 10
        ");
        $stmt->execute();
        $stats['rule_usage'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Hourly compatibility check trends
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as hour_period,
                COUNT(*) as total_checks,
                SUM(CASE WHEN compatibility_result = 1 THEN 1 ELSE 0 END) as successful_checks,
                AVG(execution_time_ms) as avg_execution_time
            FROM compatibility_log 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 $timeframe)
            GROUP BY hour_period
            ORDER BY hour_period DESC
            LIMIT 24
        ");
        $stmt->execute();
        $stats['hourly_trends'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Component usage statistics
        $stmt = $pdo->prepare("
            SELECT 
                component_type_1 as component_type,
                COUNT(*) as usage_count
            FROM compatibility_log 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 $timeframe)
            GROUP BY component_type_1
            UNION ALL
            SELECT 
                component_type_2 as component_type,
                COUNT(*) as usage_count
            FROM compatibility_log 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 $timeframe)
            GROUP BY component_type_2
        ");
        $stmt->execute();
        $componentUsage = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Aggregate component usage
        $aggregatedUsage = [];
        foreach ($componentUsage as $usage) {
            if (!isset($aggregatedUsage[$usage['component_type']])) {
                $aggregatedUsage[$usage['component_type']] = 0;
            }
            $aggregatedUsage[$usage['component_type']] += $usage['usage_count'];
        }
        arsort($aggregatedUsage);
        $stats['component_usage'] = $aggregatedUsage;
        
        return $stats;
        
    } catch (Exception $e) {
        error_log("Error getting detailed compatibility stats: " . $e->getMessage());
        return [];
    }
}

/**
 * Clear rules cache helper function
 */
function clearRulesCache($pdo) {
    try {
        // Implementation depends on your caching system
        // For now, just return a placeholder count
        return 0;
    } catch (Exception $e) {
        error_log("Error clearing rules cache: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get sample components for benchmark testing
 */
function getSampleComponentsForBenchmark($pdo) {
    try {
        $components = [];
        $tables = [
            'cpu' => 'cpuinventory',
            'motherboard' => 'motherboardinventory',
            'ram' => 'raminventory',
            'storage' => 'storageinventory',
            'nic' => 'nicinventory',
            'caddy' => 'caddyinventory'
        ];
        
        foreach ($tables as $type => $table) {
            $stmt = $pdo->prepare("SELECT UUID FROM $table WHERE Status = 1 LIMIT 5");
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($results as $result) {
                $components[] = [
                    'type' => $type,
                    'uuid' => $result['UUID']
                ];
            }
        }
        
        return $components;
        
    } catch (Exception $e) {
        error_log("Error getting sample components: " . $e->getMessage());
        return [];
    }
}

/**
 * ENHANCED STORAGE COMPATIBILITY API ENDPOINTS
 */

/**
 * Check storage compatibility directly (single component)
 */
function handleCheckStorageCompatibilityDirect() {
    global $pdo;
    
    $storageUuid = $_POST['storage_uuid'] ?? '';
    $motherboardUuid = $_POST['motherboard_uuid'] ?? '';
    $includeDetails = filter_var($_POST['include_details'] ?? true, FILTER_VALIDATE_BOOLEAN);
    
    if (empty($storageUuid) || empty($motherboardUuid)) {
        send_json_response(0, 1, 400, "Both storage_uuid and motherboard_uuid are required");
    }
    
    try {
        $componentCompatibility = new ComponentCompatibility($pdo);
        
        $result = $componentCompatibility->checkStorageCompatibilityDirect($storageUuid, $motherboardUuid);
        
        $response = [
            'storage_uuid' => $storageUuid,
            'motherboard_uuid' => $motherboardUuid,
            'compatibility_result' => $result,
            'timestamp' => date('Y-m-d H:i:s'),
            'check_type' => 'direct'
        ];
        
        if ($includeDetails) {
            $response['validation_details'] = [
                'json_validation' => 'Enhanced JSON-based validation using storage-level-3.json',
                'interface_checking' => 'SATA/SAS/NVMe interface compatibility validation',
                'form_factor_validation' => 'Physical size and connector compatibility',
                'pcie_bandwidth_checking' => 'PCIe generation and lane requirements',
                'fallback_support' => 'Database parsing when JSON data unavailable'
            ];
        }
        
        // Determine response message based on compatibility
        $message = $result['compatible']
            ? "Storage is compatible with motherboard"
            : "Storage compatibility issues found";
            
        send_json_response(1, 1, 200, $message, $response);
        
    } catch (Exception $e) {
        error_log("Error in direct storage compatibility check: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to check storage compatibility");
    }
}

/**
 * Check storage compatibility recursively (complete server configuration)
 */
function handleCheckStorageCompatibilityRecursive() {
    global $pdo;
    
    $serverConfigUuid = $_POST['server_config_uuid'] ?? '';
    $includeComponentDetails = filter_var($_POST['include_component_details'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $includeBayAnalysis = filter_var($_POST['include_bay_analysis'] ?? true, FILTER_VALIDATE_BOOLEAN);
    
    if (empty($serverConfigUuid)) {
        send_json_response(0, 1, 400, "server_config_uuid is required");
    }
    
    try {
        $componentCompatibility = new ComponentCompatibility($pdo);
        
        $result = $componentCompatibility->checkStorageCompatibilityRecursive($serverConfigUuid);
        
        $response = [
            'server_config_uuid' => $serverConfigUuid,
            'recursive_result' => $result,
            'timestamp' => date('Y-m-d H:i:s'),
            'check_type' => 'recursive'
        ];
        
        // Add summary statistics
        $response['summary'] = [
            'overall_compatible' => $result['compatible'],
            'overall_score' => $result['overall_score'],
            'total_issues' => count($result['issues']),
            'total_recommendations' => count($result['recommendations']),
            'components_checked' => count($result['component_results'])
        ];
        
        if (!$includeComponentDetails) {
            unset($response['recursive_result']['component_results']);
        }
        
        if (!$includeBayAnalysis) {
            unset($response['recursive_result']['bay_analysis']);
        }
        
        // Determine response message based on overall compatibility
        $message = $result['compatible']
            ? "Server configuration storage compatibility validated successfully (Score: " . number_format($result['overall_score'], 2) . ")"
            : "Server configuration has storage compatibility issues";
            
        send_json_response(1, 1, 200, $message, $response);
        
    } catch (Exception $e) {
        error_log("Error in recursive storage compatibility check: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to check server configuration storage compatibility");
    }
}
?>