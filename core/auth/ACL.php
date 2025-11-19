<?php
/**
 * Advanced Access Control List (ACL) System
 * File: includes/ACL.php
 */

class ACL {
    private $pdo;
    private $userPermissions = [];
    private $userRoles = [];
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Create ACL tables if they don't exist
     */
    public function createTables() {
        try {
            // Permissions table
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS permissions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL UNIQUE,
                    display_name VARCHAR(200) NOT NULL,
                    description TEXT,
                    category VARCHAR(50) NOT NULL DEFAULT 'general',
                    is_basic BOOLEAN DEFAULT FALSE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // Roles table
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS roles (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(50) NOT NULL UNIQUE,
                    display_name VARCHAR(100) NOT NULL,
                    description TEXT,
                    is_system BOOLEAN DEFAULT FALSE,
                    is_default BOOLEAN DEFAULT FALSE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // Role permissions table
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS role_permissions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    role_id INT NOT NULL,
                    permission_id INT NOT NULL,
                    granted BOOLEAN DEFAULT TRUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
                    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
                    UNIQUE KEY unique_role_permission (role_id, permission_id)
                )
            ");
            
            // User roles table
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS user_roles (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    role_id INT NOT NULL,
                    assigned_by INT UNSIGNED NULL,
                    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
                    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
                    UNIQUE KEY unique_user_role (user_id, role_id)
                )
            ");
            
            return true;
        } catch (PDOException $e) {
            error_log("Error creating ACL tables: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Initialize default permissions
     */
    public function initializeDefaultPermissions() {
        $permissions = [
            // Authentication permissions
            ['name' => 'auth.login', 'display_name' => 'Login to System', 'category' => 'authentication', 'is_basic' => true],
            ['name' => 'auth.logout', 'display_name' => 'Logout from System', 'category' => 'authentication', 'is_basic' => true],
            ['name' => 'auth.change_password', 'display_name' => 'Change Own Password', 'category' => 'authentication', 'is_basic' => true],
            
            // Dashboard permissions
            ['name' => 'dashboard.view', 'display_name' => 'View Dashboard', 'category' => 'dashboard', 'is_basic' => true],
            ['name' => 'dashboard.admin', 'display_name' => 'Admin Dashboard Access', 'category' => 'dashboard', 'is_basic' => false],
            
            // CPU permissions
            ['name' => 'cpu.view', 'display_name' => 'View CPU Components', 'category' => 'inventory', 'is_basic' => true],
            ['name' => 'cpu.create', 'display_name' => 'Create CPU Components', 'category' => 'inventory', 'is_basic' => false],
            ['name' => 'cpu.edit', 'display_name' => 'Edit CPU Components', 'category' => 'inventory', 'is_basic' => false],
            ['name' => 'cpu.delete', 'display_name' => 'Delete CPU Components', 'category' => 'inventory', 'is_basic' => false],
            
            // RAM permissions
            ['name' => 'ram.view', 'display_name' => 'View RAM Components', 'category' => 'inventory', 'is_basic' => true],
            ['name' => 'ram.create', 'display_name' => 'Create RAM Components', 'category' => 'inventory', 'is_basic' => false],
            ['name' => 'ram.edit', 'display_name' => 'Edit RAM Components', 'category' => 'inventory', 'is_basic' => false],
            ['name' => 'ram.delete', 'display_name' => 'Delete RAM Components', 'category' => 'inventory', 'is_basic' => false],
            
            // Storage permissions
            ['name' => 'storage.view', 'display_name' => 'View Storage Components', 'category' => 'inventory', 'is_basic' => true],
            ['name' => 'storage.create', 'display_name' => 'Create Storage Components', 'category' => 'inventory', 'is_basic' => false],
            ['name' => 'storage.edit', 'display_name' => 'Edit Storage Components', 'category' => 'inventory', 'is_basic' => false],
            ['name' => 'storage.delete', 'display_name' => 'Delete Storage Components', 'category' => 'inventory', 'is_basic' => false],
            
            // Motherboard permissions
            ['name' => 'motherboard.view', 'display_name' => 'View Motherboard Components', 'category' => 'inventory', 'is_basic' => true],
            ['name' => 'motherboard.create', 'display_name' => 'Create Motherboard Components', 'category' => 'inventory', 'is_basic' => false],
            ['name' => 'motherboard.edit', 'display_name' => 'Edit Motherboard Components', 'category' => 'inventory', 'is_basic' => false],
            ['name' => 'motherboard.delete', 'display_name' => 'Delete Motherboard Components', 'category' => 'inventory', 'is_basic' => false],
            
            // NIC permissions
            ['name' => 'nic.view', 'display_name' => 'View NIC Components', 'category' => 'inventory', 'is_basic' => true],
            ['name' => 'nic.create', 'display_name' => 'Create NIC Components', 'category' => 'inventory', 'is_basic' => false],
            ['name' => 'nic.edit', 'display_name' => 'Edit NIC Components', 'category' => 'inventory', 'is_basic' => false],
            ['name' => 'nic.delete', 'display_name' => 'Delete NIC Components', 'category' => 'inventory', 'is_basic' => false],
            
            // Caddy permissions
            ['name' => 'caddy.view', 'display_name' => 'View Caddy Components', 'category' => 'inventory', 'is_basic' => true],
            ['name' => 'caddy.create', 'display_name' => 'Create Caddy Components', 'category' => 'inventory', 'is_basic' => false],
            ['name' => 'caddy.edit', 'display_name' => 'Edit Caddy Components', 'category' => 'inventory', 'is_basic' => false],
            ['name' => 'caddy.delete', 'display_name' => 'Delete Caddy Components', 'category' => 'inventory', 'is_basic' => false],

            // Chassis permissions
            ['name' => 'chassis.view', 'display_name' => 'View Chassis Components', 'category' => 'inventory', 'is_basic' => true],
            ['name' => 'chassis.create', 'display_name' => 'Create Chassis Components', 'category' => 'inventory', 'is_basic' => false],
            ['name' => 'chassis.edit', 'display_name' => 'Edit Chassis Components', 'category' => 'inventory', 'is_basic' => false],
            ['name' => 'chassis.delete', 'display_name' => 'Delete Chassis Components', 'category' => 'inventory', 'is_basic' => false],

            // PCIe Card permissions
            ['name' => 'pciecard.view', 'display_name' => 'View PCIe Card Components', 'category' => 'inventory', 'is_basic' => true],
            ['name' => 'pciecard.create', 'display_name' => 'Create PCIe Card Components', 'category' => 'inventory', 'is_basic' => false],
            ['name' => 'pciecard.edit', 'display_name' => 'Edit PCIe Card Components', 'category' => 'inventory', 'is_basic' => false],
            ['name' => 'pciecard.delete', 'display_name' => 'Delete PCIe Card Components', 'category' => 'inventory', 'is_basic' => false],

            // HBA Card permissions
            ['name' => 'hbacard.view', 'display_name' => 'View HBA Card Components', 'category' => 'inventory', 'is_basic' => true],
            ['name' => 'hbacard.create', 'display_name' => 'Create HBA Card Components', 'category' => 'inventory', 'is_basic' => false],
            ['name' => 'hbacard.edit', 'display_name' => 'Edit HBA Card Components', 'category' => 'inventory', 'is_basic' => false],
            ['name' => 'hbacard.delete', 'display_name' => 'Delete HBA Card Components', 'category' => 'inventory', 'is_basic' => false],

            // Server configuration permissions
            ['name' => 'server.view', 'display_name' => 'View Server Configurations', 'category' => 'server', 'is_basic' => true],
            ['name' => 'server.create', 'display_name' => 'Create Server Configurations', 'category' => 'server', 'is_basic' => false],
            ['name' => 'server.edit', 'display_name' => 'Edit Server Configurations', 'category' => 'server', 'is_basic' => false],
            ['name' => 'server.delete', 'display_name' => 'Delete Server Configurations', 'category' => 'server', 'is_basic' => false],

            // User management permissions
            ['name' => 'users.view', 'display_name' => 'View Users', 'category' => 'user_management', 'is_basic' => false],
            ['name' => 'users.create', 'display_name' => 'Create Users', 'category' => 'user_management', 'is_basic' => false],
            ['name' => 'users.edit', 'display_name' => 'Edit Users', 'category' => 'user_management', 'is_basic' => false],
            ['name' => 'users.delete', 'display_name' => 'Delete Users', 'category' => 'user_management', 'is_basic' => false],
            
            // Role management permissions
            ['name' => 'roles.view', 'display_name' => 'View Roles', 'category' => 'user_management', 'is_basic' => false],
            ['name' => 'roles.create', 'display_name' => 'Create Roles', 'category' => 'user_management', 'is_basic' => false],
            ['name' => 'roles.edit', 'display_name' => 'Edit Roles', 'category' => 'user_management', 'is_basic' => false],
            ['name' => 'roles.delete', 'display_name' => 'Delete Roles', 'category' => 'user_management', 'is_basic' => false],
            ['name' => 'roles.assign', 'display_name' => 'Assign Roles to Users', 'category' => 'user_management', 'is_basic' => false],
            
            // Search permissions
            ['name' => 'search.global', 'display_name' => 'Global Search', 'category' => 'search', 'is_basic' => true],
            ['name' => 'search.advanced', 'display_name' => 'Advanced Search', 'category' => 'search', 'is_basic' => false],
            
            // Reports permissions
            ['name' => 'reports.view', 'display_name' => 'View Reports', 'category' => 'reports', 'is_basic' => false],
            ['name' => 'reports.export', 'display_name' => 'Export Reports', 'category' => 'reports', 'is_basic' => false],
            
            // System permissions
            ['name' => 'system.settings', 'display_name' => 'System Settings', 'category' => 'system', 'is_basic' => false],
            ['name' => 'system.logs', 'display_name' => 'View System Logs', 'category' => 'system', 'is_basic' => false],
            ['name' => 'system.backup', 'display_name' => 'System Backup', 'category' => 'system', 'is_basic' => false]
        ];
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO permissions (name, display_name, description, category, is_basic) 
                VALUES (?, ?, ?, ?, ?)
            ");
            
            foreach ($permissions as $permission) {
                $stmt->execute([
                    $permission['name'],
                    $permission['display_name'],
                    $permission['description'] ?? null,
                    $permission['category'],
                    $permission['is_basic']
                ]);
            }
            
            return true;
        } catch (PDOException $e) {
            error_log("Error initializing permissions: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Initialize default roles
     */
    public function initializeDefaultRoles() {
        $roles = [
            [
                'name' => 'admin',
                'display_name' => 'Administrator',
                'description' => 'Full system access with all permissions',
                'is_system' => true,
                'is_default' => false
            ],
            [
                'name' => 'manager',
                'display_name' => 'Manager',
                'description' => 'Can manage inventory and view reports',
                'is_system' => true,
                'is_default' => false
            ],
            [
                'name' => 'technician',
                'display_name' => 'Technician',
                'description' => 'Can create and edit inventory components',
                'is_system' => true,
                'is_default' => false
            ],
            [
                'name' => 'viewer',
                'display_name' => 'Viewer',
                'description' => 'Read-only access to inventory',
                'is_system' => true,
                'is_default' => true
            ]
        ];
        
        try {
            $this->pdo->beginTransaction();
            
            foreach ($roles as $role) {
                // Insert role
                $stmt = $this->pdo->prepare("
                    INSERT IGNORE INTO roles (name, display_name, description, is_system, is_default) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $role['name'],
                    $role['display_name'],
                    $role['description'],
                    $role['is_system'],
                    $role['is_default']
                ]);
                
                // Get role ID
                $stmt = $this->pdo->prepare("SELECT id FROM roles WHERE name = ?");
                $stmt->execute([$role['name']]);
                $roleId = $stmt->fetchColumn();
                
                if ($roleId) {
                    // Assign permissions based on role
                    $this->assignRolePermissions($roleId, $role['name']);
                }
            }
            
            $this->pdo->commit();
            return true;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("Error initializing roles: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Assign permissions to roles based on role type
     */
    private function assignRolePermissions($roleId, $roleName) {
        $permissionSets = [
            'admin' => ['*'], // All permissions
            'manager' => [
                'auth.*', 'dashboard.*', 'search.*', 'reports.*',
                '*.view', '*.create', '*.edit', 'users.view', 'roles.view'
            ],
            'technician' => [
                'auth.*', 'dashboard.view', 'search.*',
                '*.view', '*.create', '*.edit'
            ],
            'viewer' => [
                'auth.*', 'dashboard.view', 'search.global',
                '*.view'
            ]
        ];
        
        $permissions = $permissionSets[$roleName] ?? [];
        
        if (in_array('*', $permissions)) {
            // Grant all permissions
            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO role_permissions (role_id, permission_id, granted)
                SELECT ?, id, 1 FROM permissions
            ");
            $stmt->execute([$roleId]);
        } else {
            foreach ($permissions as $permission) {
                if (strpos($permission, '*') !== false) {
                    // Wildcard permission
                    $pattern = str_replace('*', '%', $permission);
                    $stmt = $this->pdo->prepare("
                        INSERT IGNORE INTO role_permissions (role_id, permission_id, granted)
                        SELECT ?, id, 1 FROM permissions WHERE name LIKE ?
                    ");
                    $stmt->execute([$roleId, $pattern]);
                } else {
                    // Specific permission
                    $stmt = $this->pdo->prepare("
                        INSERT IGNORE INTO role_permissions (role_id, permission_id, granted)
                        SELECT ?, id, 1 FROM permissions WHERE name = ?
                    ");
                    $stmt->execute([$roleId, $permission]);
                }
            }
        }
    }
    
    /**
     * Check if user has specific permission
     */
    public function hasPermission($userId, $permission) {
        try {
            // Check cache first
            if (!isset($this->userPermissions[$userId])) {
                $this->loadUserPermissions($userId);
            }
            
            return isset($this->userPermissions[$userId][$permission]) && 
                   $this->userPermissions[$userId][$permission] == 1;
        } catch (Exception $e) {
            error_log("ACL hasPermission error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Load user permissions into cache
     */
    private function loadUserPermissions($userId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT DISTINCT p.name, rp.granted
                FROM users u
                JOIN user_roles ur ON u.id = ur.user_id
                JOIN roles r ON ur.role_id = r.id
                JOIN role_permissions rp ON r.id = rp.role_id
                JOIN permissions p ON rp.permission_id = p.id
                WHERE u.id = ? AND rp.granted = 1
            ");
            $stmt->execute([$userId]);
            
            $this->userPermissions[$userId] = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->userPermissions[$userId][$row['name']] = $row['granted'];
            }
            
        } catch (PDOException $e) {
            error_log("Error loading user permissions: " . $e->getMessage());
            $this->userPermissions[$userId] = [];
        }
    }
    
    /**
     * Get user roles
     */
    public function getUserRoles($userId) {
        try {
            if (!isset($this->userRoles[$userId])) {
                $stmt = $this->pdo->prepare("
                    SELECT r.id, r.name, r.display_name, r.description
                    FROM roles r
                    JOIN user_roles ur ON r.id = ur.role_id
                    WHERE ur.user_id = ?
                ");
                $stmt->execute([$userId]);
                $this->userRoles[$userId] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            return $this->userRoles[$userId];
        } catch (PDOException $e) {
            error_log("Error getting user roles: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Assign role to user
     */
    public function assignRole($userId, $roleId, $assignedBy = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO user_roles (user_id, role_id, assigned_by) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE assigned_by = VALUES(assigned_by), assigned_at = NOW()
            ");
            
            $result = $stmt->execute([$userId, $roleId, $assignedBy]);
            
            // Clear cache
            unset($this->userPermissions[$userId]);
            unset($this->userRoles[$userId]);
            
            return $result;
        } catch (PDOException $e) {
            error_log("Error assigning role: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Remove role from user
     */
    public function removeRole($userId, $roleId) {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_id = ?");
            $result = $stmt->execute([$userId, $roleId]);
            
            // Clear cache
            unset($this->userPermissions[$userId]);
            unset($this->userRoles[$userId]);
            
            return $result;
        } catch (PDOException $e) {
            error_log("Error removing role: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create new role
     */
    public function createRole($name, $displayName, $description = null, $basicPermissionsOnly = true) {
        try {
            $this->pdo->beginTransaction();
            
            // Insert role
            $stmt = $this->pdo->prepare("
                INSERT INTO roles (name, display_name, description) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$name, $displayName, $description]);
            $roleId = $this->pdo->lastInsertId();
            
            // Assign basic permissions if requested
            if ($basicPermissionsOnly) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO role_permissions (role_id, permission_id, granted)
                    SELECT ?, id, 1 FROM permissions WHERE is_basic = 1
                ");
                $stmt->execute([$roleId]);
            }
            
            $this->pdo->commit();
            return $roleId;
            
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("Error creating role: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update role permissions
     */
    public function updateRolePermissions($roleId, $permissions) {
        try {
            $this->pdo->beginTransaction();
            
            // Delete existing permissions for this role
            $stmt = $this->pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
            $stmt->execute([$roleId]);
            
            // Insert new permissions
            $stmt = $this->pdo->prepare("
                INSERT INTO role_permissions (role_id, permission_id, granted) 
                VALUES (?, ?, ?)
            ");
            
            foreach ($permissions as $permissionId => $granted) {
                $stmt->execute([$roleId, $permissionId, $granted ? 1 : 0]);
            }
            
            $this->pdo->commit();
            
            // Clear all user permission caches since role changed
            $this->userPermissions = [];
            
            return true;
            
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("Error updating role permissions: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get role permissions
     */
    public function getRolePermissions($roleId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT p.id, p.name, p.display_name, p.category, 
                       COALESCE(rp.granted, 0) as granted
                FROM permissions p
                LEFT JOIN role_permissions rp ON p.id = rp.permission_id AND rp.role_id = ?
                ORDER BY p.category, p.display_name
            ");
            $stmt->execute([$roleId]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting role permissions: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all roles
     */
    public function getAllRoles() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT r.*, 
                       COUNT(ur.user_id) as user_count,
                       COUNT(rp.permission_id) as permission_count
                FROM roles r
                LEFT JOIN user_roles ur ON r.id = ur.role_id
                LEFT JOIN role_permissions rp ON r.id = rp.role_id AND rp.granted = 1
                GROUP BY r.id
                ORDER BY r.is_system DESC, r.name
            ");
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting all roles: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all permissions grouped by category
     */
    public function getAllPermissions() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM permissions 
                ORDER BY category, display_name
            ");
            $stmt->execute();
            
            $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Group by category
            $grouped = [];
            foreach ($permissions as $permission) {
                $grouped[$permission['category']][] = $permission;
            }
            
            return $grouped;
        } catch (PDOException $e) {
            error_log("Error getting all permissions: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Delete role (only non-system roles)
     */
    public function deleteRole($roleId) {
        try {
            // Check if it's a system role
            $stmt = $this->pdo->prepare("SELECT is_system FROM roles WHERE id = ?");
            $stmt->execute([$roleId]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$role || $role['is_system']) {
                return false; // Cannot delete system roles
            }
            
            $stmt = $this->pdo->prepare("DELETE FROM roles WHERE id = ? AND is_system = 0");
            $result = $stmt->execute([$roleId]);
            
            // Clear cache
            $this->userPermissions = [];
            $this->userRoles = [];
            
            return $result;
        } catch (PDOException $e) {
            error_log("Error deleting role: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if user has any of the specified roles
     */
    public function hasRole($userId, $roles) {
        if (!is_array($roles)) {
            $roles = [$roles];
        }
        
        $userRoles = $this->getUserRoles($userId);
        $userRoleNames = array_column($userRoles, 'name');
        
        return !empty(array_intersect($roles, $userRoleNames));
    }
    
    /**
     * Get default role ID
     */
    public function getDefaultRoleId() {
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM roles WHERE is_default = 1 LIMIT 1");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ? $result['id'] : null;
        } catch (PDOException $e) {
            error_log("Error getting default role: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Auto-assign default role to user if they have no roles
     */
    public function ensureUserHasRole($userId) {
        try {
            // Check if user has any roles
            $stmt = $this->pdo->prepare("SELECT COUNT(*) as role_count FROM user_roles WHERE user_id = ?");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['role_count'] == 0) {
                $defaultRoleId = $this->getDefaultRoleId();
                if ($defaultRoleId) {
                    return $this->assignRole($userId, $defaultRoleId);
                }
            }
            
            return true;
        } catch (PDOException $e) {
            error_log("Error ensuring user has role: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clear permission cache for user
     */
    public function clearUserCache($userId) {
        unset($this->userPermissions[$userId]);
        unset($this->userRoles[$userId]);
    }
    
    /**
     * Log ACL actions
     */
    public function logAction($action, $componentType, $componentId, $oldData = null, $newData = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO inventory_log (component_type, component_id, action, old_data, new_data, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $componentType, 
                $componentId, 
                $action,
                $oldData ? json_encode($oldData) : null,
                $newData ? json_encode($newData) : null
            ]);
        } catch (PDOException $e) {
            error_log("Error logging ACL action: " . $e->getMessage());
        }
    }
    
    /**
     * Get user permissions for a specific category
     */
    public function getUserPermissionsByCategory($userId, $category) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT DISTINCT p.name, p.display_name, rp.granted
                FROM users u
                JOIN user_roles ur ON u.id = ur.user_id
                JOIN roles r ON ur.role_id = r.id
                JOIN role_permissions rp ON r.id = rp.role_id
                JOIN permissions p ON rp.permission_id = p.id
                WHERE u.id = ? AND p.category = ? AND rp.granted = 1
                ORDER BY p.display_name
            ");
            $stmt->execute([$userId, $category]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting user permissions by category: " . $e->getMessage());
            return [];
        }
    }
}
?>