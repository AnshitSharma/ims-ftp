<?php
/**
 * ACL Setup Script
 * Initializes ACL system and assigns admin role to admin user
 */

require_once(__DIR__ . '/includes/db_config.php');
require_once(__DIR__ . '/includes/ACL.php');

try {
    $acl = new ACL($pdo);

    echo "Setting up ACL system...\n";

    // Create ACL tables if they don't exist
    echo "Creating ACL tables...\n";
    if ($acl->createTables()) {
        echo "ACL tables created successfully\n";
    } else {
        echo "ACL tables already exist or error creating them\n";
    }

    // Initialize default permissions
    echo "Initializing default permissions...\n";
    if ($acl->initializeDefaultPermissions()) {
        echo "Default permissions initialized\n";
    } else {
        echo "Error initializing permissions\n";
    }

    // Initialize default roles
    echo "Initializing default roles...\n";
    if ($acl->initializeDefaultRoles()) {
        echo "Default roles initialized\n";
    } else {
        echo "Error initializing roles\n";
    }

    // Assign admin role to user ID 5 (admin user)
    echo "Assigning admin role to admin user (ID: 5)...\n";

    // Get admin role ID
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'admin' LIMIT 1");
    $stmt->execute();
    $adminRole = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($adminRole) {
        $roleId = $adminRole['id'];
        echo "Found admin role with ID: $roleId\n";

        if ($acl->assignRole(5, $roleId)) {
            echo "Successfully assigned admin role to user ID 5\n";
        } else {
            echo "Error assigning admin role to user ID 5\n";
        }
    } else {
        echo "Admin role not found\n";
    }

    // Verify user permissions
    echo "\nVerifying user permissions...\n";
    $userRoles = $acl->getUserRoles(5);
    echo "User roles: " . json_encode($userRoles, JSON_PRETTY_PRINT) . "\n";

    // Test a few permissions
    $testPermissions = ['cpu.view', 'chassis.view', 'server.create', 'server.view'];
    foreach ($testPermissions as $perm) {
        $has = $acl->hasPermission(5, $perm);
        echo "Permission '$perm': " . ($has ? 'GRANTED' : 'DENIED') . "\n";
    }

    echo "\nACL setup completed successfully!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    error_log("ACL setup error: " . $e->getMessage());
}
?>
