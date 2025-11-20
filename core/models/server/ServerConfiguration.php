<?php
/**
 * ServerConfiguration Class
 * File: includes/models/ServerConfiguration.php
 * 
 * Handles server configuration data management
 */

class ServerConfiguration {
    private $pdo;
    private $data;
    
    public function __construct($pdo, $data = []) {
        $this->pdo = $pdo;
        $this->data = $data;
    }
    
    /**
     * Create new server configuration
     */
    public static function create($pdo, $configData) {
        try {
            $configUuid = self::generateUuid();

            $stmt = $pdo->prepare("
                INSERT INTO server_configurations
                (config_uuid, server_name, description, created_by, configuration_status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ");

            $result = $stmt->execute([
                $configUuid,
                $configData['server_name'],
                $configData['description'] ?? '',
                $configData['created_by'],
                $configData['configuration_status'] ?? 0
            ]);

            if ($result) {
                // Load the created configuration
                return self::loadByUuid($pdo, $configUuid);
            }

            return false;

        } catch (Exception $e) {
            error_log("Error creating server configuration: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Load configuration by UUID
     */
    public static function loadByUuid($pdo, $configUuid) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($data) {
                return new self($pdo, $data);
            }

            return null;

        } catch (Exception $e) {
            error_log("Error loading server configuration: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Load configuration by ID
     */
    public static function loadById($pdo, $id) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM server_configurations WHERE id = ?");
            $stmt->execute([$id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($data) {
                return new self($pdo, $data);
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log("Error loading server configuration by ID: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update configuration
     */
    public function update($updateData) {
        try {
            $allowedFields = [
                'server_name', 'description', 'configuration_status',
                'validation_errors', 'notes', 'updated_at'
            ];
            
            $updateFields = [];
            $params = [];
            
            foreach ($updateData as $field => $value) {
                if (in_array($field, $allowedFields)) {
                    $updateFields[] = "$field = ?";
                    $params[] = $value;
                    $this->data[$field] = $value;
                }
            }
            
            if (empty($updateFields)) {
                return true; // Nothing to update
            }
            
            // Always update the updated_at timestamp
            if (!isset($updateData['updated_at'])) {
                $updateFields[] = "updated_at = NOW()";
            }
            
            $params[] = $this->data['config_uuid'];
            
            $sql = "UPDATE server_configurations SET " . implode(', ', $updateFields) . " WHERE config_uuid = ?";
            $stmt = $this->pdo->prepare($sql);
            
            return $stmt->execute($params);
            
        } catch (Exception $e) {
            error_log("Error updating server configuration: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete configuration
     */
    public function delete() {
        try {
            $this->pdo->beginTransaction();

            // Delete the configuration (components are stored in JSON columns, no separate delete needed)
            $stmt = $this->pdo->prepare("DELETE FROM server_configurations WHERE config_uuid = ?");
            $result = $stmt->execute([$this->data['config_uuid']]);

            $this->pdo->commit();
            return $result;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Error deleting server configuration: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get configuration data
     */
    public function getData() {
        return $this->data;
    }
    
    /**
     * Get specific field value
     */
    public function get($field) {
        return $this->data[$field] ?? null;
    }
    
    /**
     * Set specific field value
     */
    public function set($field, $value) {
        $this->data[$field] = $value;
    }
    
    /**
     * Get configuration components from JSON columns
     */
    public function getComponents() {
        try {
            $components = [];

            // CPU configuration
            if (!empty($this->data['cpu_configuration'])) {
                $cpuConfig = json_decode($this->data['cpu_configuration'], true);
                if (isset($cpuConfig['cpus']) && is_array($cpuConfig['cpus'])) {
                    foreach ($cpuConfig['cpus'] as $cpu) {
                        if (!empty($cpu['uuid'])) {
                            $components[] = [
                                'component_type' => 'cpu',
                                'component_uuid' => $cpu['uuid'],
                                'quantity' => $cpu['quantity'] ?? 1,
                                'added_at' => $cpu['added_at'] ?? null
                            ];
                        }
                    }
                }
            }

            // RAM configuration
            if (!empty($this->data['ram_configuration'])) {
                $ramConfigs = json_decode($this->data['ram_configuration'], true);
                if (is_array($ramConfigs)) {
                    foreach ($ramConfigs as $ram) {
                        if (!empty($ram['uuid'])) {
                            $components[] = [
                                'component_type' => 'ram',
                                'component_uuid' => $ram['uuid'],
                                'quantity' => $ram['quantity'] ?? 1,
                                'added_at' => $ram['added_at'] ?? null
                            ];
                        }
                    }
                }
            }

            // Storage configuration
            if (!empty($this->data['storage_configuration'])) {
                $storageConfigs = json_decode($this->data['storage_configuration'], true);
                if (is_array($storageConfigs)) {
                    foreach ($storageConfigs as $storage) {
                        if (!empty($storage['uuid'])) {
                            $components[] = [
                                'component_type' => 'storage',
                                'component_uuid' => $storage['uuid'],
                                'quantity' => $storage['quantity'] ?? 1,
                                'added_at' => $storage['added_at'] ?? null
                            ];
                        }
                    }
                }
            }

            // Caddy configuration
            if (!empty($this->data['caddy_configuration'])) {
                $caddyConfigs = json_decode($this->data['caddy_configuration'], true);
                if (is_array($caddyConfigs)) {
                    foreach ($caddyConfigs as $caddy) {
                        if (!empty($caddy['uuid'])) {
                            $components[] = [
                                'component_type' => 'caddy',
                                'component_uuid' => $caddy['uuid'],
                                'quantity' => $caddy['quantity'] ?? 1,
                                'added_at' => $caddy['added_at'] ?? null
                            ];
                        }
                    }
                }
            }

            // NIC configuration
            if (!empty($this->data['nic_config'])) {
                $nicConfig = json_decode($this->data['nic_config'], true);
                if (isset($nicConfig['nics']) && is_array($nicConfig['nics'])) {
                    foreach ($nicConfig['nics'] as $nic) {
                        if (!empty($nic['uuid'])) {
                            $components[] = [
                                'component_type' => 'nic',
                                'component_uuid' => $nic['uuid'],
                                'quantity' => 1,
                                'added_at' => null
                            ];
                        }
                    }
                }
            }

            // HBA Card
            if (!empty($this->data['hbacard_uuid'])) {
                $components[] = [
                    'component_type' => 'hbacard',
                    'component_uuid' => $this->data['hbacard_uuid'],
                    'quantity' => 1,
                    'added_at' => null
                ];
            }

            // Motherboard
            if (!empty($this->data['motherboard_uuid'])) {
                $components[] = [
                    'component_type' => 'motherboard',
                    'component_uuid' => $this->data['motherboard_uuid'],
                    'quantity' => 1,
                    'added_at' => null
                ];
            }

            // Chassis
            if (!empty($this->data['chassis_uuid'])) {
                $components[] = [
                    'component_type' => 'chassis',
                    'component_uuid' => $this->data['chassis_uuid'],
                    'quantity' => 1,
                    'added_at' => null
                ];
            }

            // PCIe Card configuration
            if (!empty($this->data['pciecard_configurations'])) {
                $pcieConfigs = json_decode($this->data['pciecard_configurations'], true);
                if (is_array($pcieConfigs)) {
                    foreach ($pcieConfigs as $pcie) {
                        if (!empty($pcie['uuid'])) {
                            $components[] = [
                                'component_type' => 'pciecard',
                                'component_uuid' => $pcie['uuid'],
                                'quantity' => $pcie['quantity'] ?? 1,
                                'added_at' => $pcie['added_at'] ?? null
                            ];
                        }
                    }
                }
            }

            return $components;

        } catch (Exception $e) {
            error_log("Error getting configuration components: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get components grouped by type
     */
    public function getComponentsByType() {
        $components = $this->getComponents();
        $grouped = [];
        
        foreach ($components as $component) {
            $type = $component['component_type'];
            if (!isset($grouped[$type])) {
                $grouped[$type] = [];
            }
            $grouped[$type][] = $component;
        }
        
        return $grouped;
    }
    
    /**
     * Check if configuration has specific component type
     */
    public function hasComponentType($componentType) {
        $components = $this->getComponents();
        foreach ($components as $component) {
            if ($component['component_type'] === $componentType) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Get component count for specific type
     */
    public function getComponentCount($componentType = null) {
        $components = $this->getComponents();
        $totalQuantity = 0;

        foreach ($components as $component) {
            if ($componentType === null || $component['component_type'] === $componentType) {
                $totalQuantity += $component['quantity'];
            }
        }

        return (int)$totalQuantity;
    }
    
    /**
     * Add component to configuration
     * DEPRECATED: Components are now managed through ServerBuilder which stores them in JSON columns
     * This method is kept for backward compatibility but does nothing
     */
    public function addComponent($componentType, $componentUuid, $options = []) {
        error_log("DEPRECATED: ServerConfiguration::addComponent() called. Use ServerBuilder for component management instead.");
        return true; // No-op, components are managed by ServerBuilder
    }

    /**
     * Remove component from configuration
     * DEPRECATED: Components are now managed through ServerBuilder which stores them in JSON columns
     * This method is kept for backward compatibility but does nothing
     */
    public function removeComponent($componentType, $componentUuid = null) {
        error_log("DEPRECATED: ServerConfiguration::removeComponent() called. Use ServerBuilder for component management instead.");
        return true; // No-op, components are managed by ServerBuilder
    }
    
    /**
     * Get configuration status text
     */
    public function getStatusText() {
        $statusMap = [
            0 => 'Draft',
            1 => 'Validated',
            2 => 'Built',
            3 => 'Deployed',
            4 => 'Archived'
        ];

        $status = $this->data['configuration_status'] ?? 0;
        $statusText = $statusMap[$status] ?? 'Unknown';

        return $statusText;
    }
    
    /**
     * Check if user can edit this configuration
     */
    public function canEdit($userId, $pdo = null) {
        if ($this->data['created_by'] == $userId) {
            return true;
        }
        
        if ($pdo && function_exists('hasPermission')) {
            return hasPermission($pdo, 'server.edit_all', $userId);
        }
        
        return false;
    }
    
    /**
     * Check if user can view this configuration
     */
    public function canView($userId, $pdo = null) {
        if ($this->data['created_by'] == $userId) {
            return true;
        }
        
        if ($pdo && function_exists('hasPermission')) {
            return hasPermission($pdo, 'server.view_all', $userId);
        }
        
        return false;
    }
    
    /**
     * Get configuration summary for display
     */
    public function getSummary() {
        $components = $this->getComponentsByType();
        
        return [
            'config_uuid' => $this->data['config_uuid'],
            'server_name' => $this->data['server_name'],
            'description' => $this->data['description'],
            'status' => $this->getStatusText(),
            'status_code' => $this->data['configuration_status'],
            'created_at' => $this->data['created_at'],
            'updated_at' => $this->data['updated_at'],
            'component_counts' => [
                'cpu' => count($components['cpu'] ?? []),
                'motherboard' => count($components['motherboard'] ?? []),
                'ram' => count($components['ram'] ?? []),
                'storage' => count($components['storage'] ?? []),
                'nic' => count($components['nic'] ?? []),
                'caddy' => count($components['caddy'] ?? [])
            ],
            'total_components' => $this->getComponentCount(),
            'power_consumption' => $this->data['power_consumption'] ?? null,
        ];
    }
    
    /**
     * Export configuration to array
     */
    public function toArray($includeComponents = true) {
        $data = $this->data;
        
        if ($includeComponents) {
            $data['components'] = $this->getComponentsByType();
        }
        
        return $data;
    }
    
    /**
     * Generate UUID for configuration
     */
    private static function generateUuid() {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * Get all configurations for a user
     */
    public static function getAllForUser($pdo, $userId, $options = []) {
        try {
            $limit = $options['limit'] ?? 50;
            $offset = $options['offset'] ?? 0;
            $status = $options['status'] ?? null;
            $search = $options['search'] ?? '';
            
            $conditions = ["created_by = ?"];
            $params = [$userId];
            
            if ($status !== null) {
                $conditions[] = "configuration_status = ?";
                $params[] = $status;
            }
            
            if (!empty($search)) {
                $conditions[] = "(server_name LIKE ? OR description LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            
            $whereClause = "WHERE " . implode(" AND ", $conditions);
            
            $sql = "SELECT * FROM server_configurations $whereClause ORDER BY updated_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $configurations = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $configurations[] = new self($pdo, $row);
            }
            
            return $configurations;
            
        } catch (Exception $e) {
            error_log("Error getting configurations for user: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Validate configuration data
     */
    public function validate() {
        $errors = [];
        
        // Required fields validation
        if (empty($this->data['server_name'])) {
            $errors[] = "Server name is required";
        }
        
        if (empty($this->data['created_by'])) {
            $errors[] = "Created by user is required";
        }
        
        // Server name length validation
        if (strlen($this->data['server_name']) > 255) {
            $errors[] = "Server name must be less than 255 characters";
        }
        
        // Description length validation
        if (!empty($this->data['description']) && strlen($this->data['description']) > 1000) {
            $errors[] = "Description must be less than 1000 characters";
        }
        
        // Status validation
        $validStatuses = [0, 1, 2, 3, 4];
        if (!in_array($this->data['configuration_status'], $validStatuses)) {
            $errors[] = "Invalid configuration status";
        }
        
        return $errors;
    }
    
    /**
     * Save configuration (create or update)
     */
    public function save() {
        $errors = $this->validate();
        if (!empty($errors)) {
            throw new Exception("Validation failed: " . implode(", ", $errors));
        }
        
        if (isset($this->data['config_uuid']) && !empty($this->data['config_uuid'])) {
            // Update existing configuration
            return $this->update($this->data);
        } else {
            // Create new configuration
            $newConfig = self::create($this->pdo, $this->data);
            if ($newConfig) {
                $this->data = $newConfig->getData();
                return true;
            }
            return false;
        }
    }
}
?>