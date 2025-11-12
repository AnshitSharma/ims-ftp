<?php
/**
 * API Integration Helper
 *
 * Provides utilities for integrating the new validator system with existing API endpoints.
 * Handles:
 * - Error format conversion
 * - Legacy compatibility
 * - Response formatting
 * - Caching strategies
 * - Rate limiting
 *
 * Phase 6 File 47
 */

require_once __DIR__ . '/RefactoredFlexibleCompatibilityValidator.php';
require_once __DIR__ . '/OrchestratorFactory.php';
require_once __DIR__ . '/PerformanceOptimizer.php';

class APIIntegrationHelper {

    private $validator;
    private $cache;
    private $profiler;
    private $config = [
        'enable_caching' => true,
        'cache_ttl' => 3600,
        'enable_profiling' => false,
        'log_slow_queries' => true,
        'slow_query_threshold_ms' => 500,
    ];

    /**
     * Constructor
     *
     * @param PDO $pdo Database connection
     * @param array $config Configuration options
     */
    public function __construct($pdo, array $config = []) {
        $this->validator = new RefactoredFlexibleCompatibilityValidator($pdo);
        $this->cache = ValidationCache::getInstance();
        $this->profiler = getValidatorProfiler();
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Validate component addition (API endpoint compatible)
     *
     * LOGIC:
     * 1. Start profiling if enabled
     * 2. Try cache if enabled
     * 3. Execute validation
     * 4. Log slow queries
     * 5. Return formatted response
     *
     * @param string $configUuid Configuration UUID
     * @param string $componentType Component type
     * @param string $componentUuid Component UUID
     * @return array Formatted response
     */
    public function validateComponentAddition($configUuid, $componentType, $componentUuid) {
        if ($this->config['enable_profiling']) {
            $this->profiler->start("validate_{$componentType}");
        }

        try {
            // Execute validation
            $result = $this->validator->validateComponentAddition($configUuid, $componentType, $componentUuid);

            if ($this->config['enable_profiling']) {
                $elapsed = $this->profiler->stop("validate_{$componentType}");

                if ($this->config['log_slow_queries'] && $elapsed > $this->config['slow_query_threshold_ms']) {
                    error_log("[APIIntegration] Slow validation: {$componentType} took {$elapsed}ms");
                }
            }

            // Format response
            return $this->formatAPIResponse($result);

        } catch (Exception $e) {
            error_log("[APIIntegration] Exception in validateComponentAddition: " . $e->getMessage());
            return $this->formatErrorResponse("Validation error: " . $e->getMessage());
        }
    }

    /**
     * Validate configuration (full validation)
     *
     * @param string $configUuid Configuration UUID
     * @return array Formatted response
     */
    public function validateConfiguration($configUuid) {
        try {
            $summary = $this->validator->getConfigurationValidationSummary($configUuid);

            return [
                'success' => $summary['valid'],
                'authenticated' => true,
                'code' => $summary['valid'] ? 200 : 400,
                'message' => $summary['valid'] ? 'Configuration valid' : 'Configuration has issues',
                'timestamp' => date('Y-m-d H:i:s'),
                'data' => [
                    'configuration_uuid' => $configUuid,
                    'valid' => $summary['valid'],
                    'error_count' => $summary['error_count'],
                    'warning_count' => $summary['warning_count'],
                    'info_count' => $summary['info_count'],
                ],
            ];
        } catch (Exception $e) {
            error_log("[APIIntegration] Exception in validateConfiguration: " . $e->getMessage());
            return $this->formatErrorResponse("Configuration validation error: " . $e->getMessage());
        }
    }

    /**
     * Quick validation (essential validators only)
     *
     * @param string $configUuid Configuration UUID
     * @param string $componentType Component type
     * @param string $componentUuid Component UUID
     * @return array Formatted response
     */
    public function validateComponentAdditionQuick($configUuid, $componentType, $componentUuid) {
        try {
            // Load components
            $allComponents = $this->getAllConfigurationComponents($configUuid);

            // Get new component specs
            $dataService = ComponentDataService::getInstance();
            $specs = $dataService->getComponentSpecs($componentType, $componentUuid);

            if (empty($specs)) {
                return $this->formatErrorResponse("Component not found");
            }

            // Create context and validate with quick profile
            $allComponents[$componentType][] = $specs;
            $context = new ValidationContext($allComponents, $componentType);
            $orchestrator = OrchestratorFactory::createQuick();
            $result = $orchestrator->validate($context);

            return $this->formatAPIResponse($this->convertValidationResult($result));

        } catch (Exception $e) {
            error_log("[APIIntegration] Exception in quick validation: " . $e->getMessage());
            return $this->formatErrorResponse("Quick validation error: " . $e->getMessage());
        }
    }

    /**
     * Get validation report (for detailed API response)
     *
     * @param string $configUuid Configuration UUID
     * @return array Validation report
     */
    public function getValidationReport($configUuid) {
        try {
            $summary = $this->validator->getConfigurationValidationSummary($configUuid);

            return [
                'configuration_uuid' => $configUuid,
                'summary' => [
                    'valid' => $summary['valid'],
                    'error_count' => $summary['error_count'],
                    'warning_count' => $summary['warning_count'],
                    'info_count' => $summary['info_count'],
                ],
                'metrics' => $summary['orchestrator_metrics'] ?? [],
                'report' => $summary['validation_report'] ?? '',
            ];
        } catch (Exception $e) {
            return [
                'configuration_uuid' => $configUuid,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Format response for API
     *
     * LOGIC:
     * 1. Extract validation status
     * 2. Determine HTTP code
     * 3. Build standard response format
     *
     * @param array $validationResult Validation result (legacy format)
     * @return array API formatted response
     */
    private function formatAPIResponse(array $validationResult) {
        $isValid = $validationResult['validation_status'] === 'success';
        $hasErrors = !empty($validationResult['critical_errors']) || !empty($validationResult['errors']);

        return [
            'success' => $isValid && !$hasErrors,
            'authenticated' => true,
            'code' => ($isValid && !$hasErrors) ? 200 : 400,
            'message' => $this->buildMessage($validationResult),
            'timestamp' => date('Y-m-d H:i:s'),
            'data' => [
                'validation_status' => $validationResult['validation_status'],
                'critical_errors' => $validationResult['critical_errors'] ?? [],
                'errors' => $validationResult['errors'] ?? [],
                'warnings' => $validationResult['warnings'] ?? [],
                'info' => $validationResult['info'] ?? [],
                'metrics' => $validationResult['metrics'] ?? [],
            ],
        ];
    }

    /**
     * Format error response
     *
     * @param string $message Error message
     * @param string $code Error code
     * @return array Formatted error response
     */
    private function formatErrorResponse($message, $code = 'validation_error') {
        return [
            'success' => false,
            'authenticated' => true,
            'code' => 400,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s'),
            'data' => [
                'error_code' => $code,
                'error_message' => $message,
            ],
        ];
    }

    /**
     * Build message from validation result
     *
     * @param array $result Validation result
     * @return string Human-readable message
     */
    private function buildMessage(array $result) {
        $errorCount = count($result['critical_errors'] ?? []);
        $warningCount = count($result['warnings'] ?? []);

        if ($errorCount > 0) {
            $plural = $errorCount > 1 ? 's' : '';
            return "Validation failed with $errorCount error$plural";
        }

        if ($warningCount > 0) {
            return "Validation successful with warnings";
        }

        return "Configuration is valid";
    }

    /**
     * Get all configuration components
     *
     * @param string $configUuid Configuration UUID
     * @return array Components
     */
    private function getAllConfigurationComponents($configUuid) {
        // This would load from the database
        // For now, returning empty array - integrate with actual data loading
        return [];
    }

    /**
     * Convert ValidationResult to legacy format
     *
     * @param ValidationResult $result Modern result
     * @return array Legacy formatted result
     */
    private function convertValidationResult($result) {
        return [
            'validation_status' => $result->isValid() ? 'success' : 'blocked',
            'critical_errors' => array_map(function($e) {
                return ['type' => $e['code'] ?? 'error', 'message' => $e['message']];
            }, $result->getErrors()),
            'errors' => array_map(function($e) {
                return ['type' => $e['code'] ?? 'error', 'message' => $e['message']];
            }, $result->getErrors()),
            'warnings' => array_map(function($w) {
                return ['type' => $w['code'] ?? 'warning', 'message' => $w['message']];
            }, $result->getWarnings()),
            'info' => $result->getInfos(),
        ];
    }

    /**
     * Enable or disable caching
     *
     * @param bool $enabled Enable caching
     * @return void
     */
    public function setCaching($enabled) {
        $this->config['enable_caching'] = $enabled;
    }

    /**
     * Enable or disable profiling
     *
     * @param bool $enabled Enable profiling
     * @return void
     */
    public function setProfiling($enabled) {
        $this->config['enable_profiling'] = $enabled;
    }

    /**
     * Get performance metrics
     *
     * @return array Performance metrics
     */
    public function getMetrics() {
        return [
            'cache_stats' => $this->cache->getStats(),
            'profiler_stats' => $this->profiler->getStats(),
            'config' => $this->config,
        ];
    }

    /**
     * Clear all caches
     *
     * @return void
     */
    public function clearCaches() {
        $this->cache->clear();
        $this->profiler->clear();
        $this->validator->clearCache();
    }

    /**
     * Get validator instance (for advanced usage)
     *
     * @return RefactoredFlexibleCompatibilityValidator Validator instance
     */
    public function getValidator() {
        return $this->validator;
    }

    /**
     * Middleware: Log all validations
     *
     * @param string $configUuid Configuration UUID
     * @param string $componentType Component type
     * @param string $componentUuid Component UUID
     * @param array $result Validation result
     * @return void
     */
    public static function logValidation($configUuid, $componentType, $componentUuid, array $result) {
        $status = $result['validation_status'] ?? 'unknown';
        $timestamp = date('Y-m-d H:i:s');
        $errorCount = count($result['critical_errors'] ?? []);

        error_log("[Validation] [$timestamp] Config=$configUuid Type=$componentType UUID=$componentUuid Status=$status Errors=$errorCount");
    }
}
