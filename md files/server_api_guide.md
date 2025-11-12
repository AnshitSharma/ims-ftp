# BDC IMS Complete API Reference Guide

## Base URL
```
https://shubham.staging.cloudmate.in/bdc_ims/api/api.php
```

## Authentication
Most endpoints require JWT authentication token in the header:
```
Authorization: Bearer {jwt_token}
```

---

## Quick Reference - All Available Endpoints (87 Total)

### Authentication & Security (7 endpoints)
- `auth-login` - User login and JWT token generation
- `auth-logout` - User logout and token revocation
- `auth-refresh` - Refresh JWT access token
- `auth-verify_token` - Verify JWT token validity
- `auth-register` - User registration (if enabled)
- `auth-forgot_password` - Password reset request
- `auth-reset_password` - Password reset completion

### Component Management (30 endpoints)
**CPU Management:**
- `cpu-list` - List all CPU components with filtering
- `cpu-get` - Get specific CPU component details
- `cpu-add` - Add new CPU component to inventory
- `cpu-update` - Update CPU component information
- `cpu-delete` - Remove CPU component from inventory

**RAM Management:**
- `ram-list` - List all RAM components with filtering
- `ram-get` - Get specific RAM component details
- `ram-add` - Add new RAM component to inventory
- `ram-update` - Update RAM component information
- `ram-delete` - Remove RAM component from inventory

**Storage Management:**
- `storage-list` - List all storage devices with filtering
- `storage-get` - Get specific storage device details
- `storage-add` - Add new storage device to inventory
- `storage-update` - Update storage device information
- `storage-delete` - Remove storage device from inventory

**Motherboard Management:**
- `motherboard-list` - List all motherboards with filtering
- `motherboard-get` - Get specific motherboard details
- `motherboard-add` - Add new motherboard to inventory
- `motherboard-update` - Update motherboard information
- `motherboard-delete` - Remove motherboard from inventory

**Network Interface Card Management:**
- `nic-list` - List all NICs with filtering
- `nic-get` - Get specific NIC details
- `nic-add` - Add new NIC to inventory
- `nic-update` - Update NIC information
- `nic-delete` - Remove NIC from inventory

**Caddy Management:**
- `caddy-list` - List all drive caddies with filtering
- `caddy-get` - Get specific caddy details
- `caddy-add` - Add new caddy to inventory
- `caddy-update` - Update caddy information
- `caddy-delete` - Remove caddy from inventory

### Server Management (15 endpoints)
- `server-create-start` - Initialize new server configuration
- `server-add-component` - Add component to server configuration
- `server-remove-component` - Remove component from configuration
- `server-get-config` - Get server configuration details
- `server-list-configs` - List all server configurations
- `server-finalize-config` - Finalize server configuration
- `server-delete-config` - Delete server configuration
- `server-validate-config` - Validate server configuration
- `server-get-available-components` - Get available components for server
- `server-get-compatible` - Get compatible components
- `server-clone-config` - Clone existing configuration
- `server-export-config` - Export configuration data
- `server-get-statistics` - Get server statistics
- `server-update-config` - Update configuration details
- `server-get-components` - Get configuration components

### Chassis Management (6 endpoints)
- `chassis-list` - List all chassis inventory
- `chassis-add` - Add new chassis to inventory
- `chassis-get` - Get chassis details and specifications
- `chassis-update` - Update chassis information
- `chassis-delete` - Remove chassis from inventory
- `chassis-get-available-bays` - Get available drive bays for chassis
- `chassis-json-validate` - Validate chassis JSON structure

### Compatibility Engine (14 endpoints)
- `compatibility-check_pair` - Check compatibility between two components
- `compatibility-check_multiple` - Check multiple component compatibility
- `compatibility-get_compatible_for` - Get compatible components for base component
- `compatibility-batch_check` - Batch compatibility checking
- `compatibility-analyze_configuration` - Analyze complete server configuration
- `compatibility-get_rules` - Get compatibility rules
- `compatibility-test_rule` - Test specific compatibility rule
- `compatibility-get_statistics` - Get compatibility statistics
- `compatibility-clear_cache` - Clear compatibility cache
- `compatibility-benchmark_performance` - Benchmark compatibility engine performance
- `compatibility-export_rules` - Export compatibility rules
- `compatibility-import_rules` - Import compatibility rules
- `compatibility-check_storage_direct` - Direct storage compatibility check
- `compatibility-check_storage_recursive` - Recursive storage compatibility check

### User Management (5 endpoints)
- `users-list` - List all users
- `users-create` - Create new user account
- `users-update` - Update user information
- `users-delete` - Delete user account
- `users-get` - Get specific user details

### Role & Permission Management (7 endpoints)
- `roles-list` - List all roles
- `roles-create` - Create new role
- `roles-update` - Update role information
- `roles-delete` - Delete role
- `roles-get` - Get specific role details
- `permissions-list` - List all permissions
- `permissions-get_by_category` - Get permissions by category

### ACL Operations (6 endpoints)
- `acl-get_user_permissions` - Get user's permissions
- `acl-assign_permission` - Assign permission to user
- `acl-revoke_permission` - Revoke permission from user
- `acl-assign_role` - Assign role to user
- `acl-revoke_role` - Revoke role from user
- `acl-check_permission` - Check if user has specific permission

### Search & Dashboard (3 endpoints)
- `search-global` - Global search across all components
- `dashboard-get_data` - Get dashboard statistics and data

---

## 1. Authentication & Security APIs

### 1.1 User Login
**Action:** `auth-login`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** No

**Purpose:** Authenticate user and receive JWT access token

**Request Body (form-data):**
```
action: auth-login
username: superadmin
password: password
remember_me: false (optional, default: false)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Login successful",
    "data": {
        "user": {
            "id": 1,
            "username": "superadmin",
            "email": "admin@example.com",
            "firstname": "Super",
            "lastname": "Admin"
        },
        "tokens": {
            "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
            "refresh_token": "refresh_token_string",
            "expires_in": 86400
        },
        "permissions": ["cpu.create", "server.view", ...]
    }
}
```

**Error Response (401):**
```json
{
    "success": false,
    "authenticated": false,
    "message": "Invalid credentials",
    "code": 401
}
```

### 1.2 User Logout
**Action:** `auth-logout`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes

**Purpose:** Logout user and revoke refresh tokens

**Request Body (form-data):**
```
action: auth-logout
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Logged out successfully"
}
```

### 1.3 Token Refresh
**Action:** `auth-refresh`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** No

**Purpose:** Refresh expired access token using refresh token

**Request Body (form-data):**
```
action: auth-refresh
refresh_token: refresh_token_string
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Token refreshed successfully",
    "data": {
        "access_token": "new_jwt_token",
        "expires_in": 86400,
        "user": {
            "id": 1,
            "username": "superadmin",
            "email": "admin@example.com"
        }
    }
}
```

### 1.4 Token Verification
**Action:** `auth-verify_token`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes

**Purpose:** Verify if current JWT token is valid

**Request Body (form-data):**
```
action: auth-verify_token
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Token is valid",
    "data": {
        "user": {
            "id": 1,
            "username": "superadmin",
            "email": "admin@example.com"
        }
    }
}
```

### 1.5 User Registration
**Action:** `auth-register`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** No

**Purpose:** Register new user account (if registration is enabled)

**Request Body (form-data):**
```
action: auth-register
username: newuser
email: user@example.com
password: securepassword
firstname: John (optional)
lastname: Doe (optional)
```

**Success Response (201):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Registration successful",
    "data": {
        "user_id": 5,
        "username": "newuser",
        "message": "Please login with your credentials"
    }
}
```

**Error Response (403):**
```json
{
    "success": false,
    "authenticated": false,
    "message": "Registration is disabled",
    "code": 403
}
```

---

## 2. Component Management APIs

### 2.1 CPU Management

#### 2.1.1 List CPU Components
**Action:** `cpu-list`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `cpu.view`

**Purpose:** List all CPU components with filtering and pagination

**Request Body (form-data):**
```
action: cpu-list
limit: 50 (optional, max 100, default: 50)
offset: 0 (optional, default: 0)
search: intel (optional)
status: 1 (optional - 0=Failed, 1=Available, 2=In Use)
sort_by: SerialNumber (optional, default: ID)
sort_order: ASC (optional, default: DESC)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Components retrieved successfully",
    "data": {
        "components": [
            {
                "ID": 1,
                "UUID": "cpu-uuid-123",
                "SerialNumber": "CPU001",
                "Status": 1,
                "StatusText": "Available",
                "ServerUUID": "",
                "Location": "Rack A1",
                "RackPosition": "U1",
                "Specifications_parsed": {
                    "model": "Intel Xeon E5-2680 v4",
                    "cores": 14,
                    "threads": 28
                }
            }
        ],
        "pagination": {
            "total": 45,
            "limit": 50,
            "offset": 0,
            "has_more": false
        },
        "statistics": {
            "total_components": 45,
            "status_counts": {
                "available": 30,
                "in_use": 10,
                "failed": 5
            }
        }
    }
}
```

#### 2.1.2 Get CPU Component
**Action:** `cpu-get`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `cpu.view`

**Purpose:** Get detailed information about specific CPU component

**Request Body (form-data):**
```
action: cpu-get
id: 1
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Component retrieved successfully",
    "data": {
        "component": {
            "ID": 1,
            "UUID": "cpu-uuid-123",
            "SerialNumber": "CPU001",
            "Status": 1,
            "StatusText": "Available",
            "history": [],
            "server_usage": {
                "configurations": [],
                "usage_count": 0
            }
        },
        "available_features": {
            "compatibility_checking": true,
            "server_configurations": true,
            "json_specifications": true
        }
    }
}
```

#### 2.1.3 Add CPU Component
**Action:** `cpu-add`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `cpu.create`

**Purpose:** Add new CPU component to inventory

**Request Body (form-data):**
```
action: cpu-add
SerialNumber: CPU001 (required)
Status: 1 (required - 0=Failed, 1=Available, 2=In Use)
UUID: cpu-uuid-123 (optional, auto-generated if not provided)
ServerUUID: server-uuid (optional, required if Status=2)
Location: Rack A1 (optional)
RackPosition: U1 (optional)
PurchaseDate: 2024-01-15 (optional)
InstallationDate: 2024-01-20 (optional)
WarrantyEndDate: 2027-01-15 (optional)
Flag: critical (optional)
Notes: High-performance CPU (optional)
Specifications: {"model":"Intel Xeon","cores":14} (optional JSON)
validate_compatibility: false (optional, default: false)
```

**Success Response (201):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Component added successfully",
    "data": {
        "component": {
            "ID": 25,
            "UUID": "cpu-uuid-123",
            "SerialNumber": "CPU001",
            "Status": 1,
            "StatusText": "Available"
        },
        "component_id": 25,
        "uuid": "cpu-uuid-123",
        "serial_number": "CPU001"
    }
}
```

**Error Response (409):**
```json
{
    "success": false,
    "authenticated": true,
    "message": "Component with this serial number already exists",
    "code": 409
}
```

#### 2.1.4 Update CPU Component
**Action:** `cpu-update`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `cpu.edit`

**Purpose:** Update existing CPU component information

**Request Body (form-data):**
```
action: cpu-update
id: 1 (required)
SerialNumber: CPU001-UPDATED (optional)
Status: 2 (optional)
ServerUUID: new-server-uuid (optional)
Location: Rack B2 (optional)
Notes: Updated notes (optional)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Component updated successfully",
    "data": {
        "component": {
            "ID": 1,
            "UUID": "cpu-uuid-123",
            "SerialNumber": "CPU001-UPDATED",
            "Status": 2,
            "StatusText": "In Use"
        },
        "changes": {
            "SerialNumber": {
                "old": "CPU001",
                "new": "CPU001-UPDATED"
            },
            "Status": {
                "old": "1",
                "new": "2"
            }
        },
        "fields_updated": 2
    }
}
```

#### 2.1.5 Delete CPU Component
**Action:** `cpu-delete`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `cpu.delete`

**Purpose:** Remove CPU component from inventory (soft delete by default)

**Request Body (form-data):**
```
action: cpu-delete
id: 1 (required)
hard_delete: false (optional, default: false)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Component marked as failed/decommissioned",
    "data": {
        "component_id": 1,
        "serial_number": "CPU001",
        "uuid": "cpu-uuid-123",
        "deletion_type": "soft",
        "timestamp": "2024-01-20 14:30:00"
    }
}
```

**Error Response (400):**
```json
{
    "success": false,
    "authenticated": true,
    "message": "Cannot delete component that is currently in use",
    "data": {
        "component_status": "In Use",
        "server_uuid": "server-123",
        "can_force_delete": false
    }
}
```

### 2.2 RAM, Storage, Motherboard, NIC, and Caddy Management

**Note:** RAM, Storage, Motherboard, NIC, and Caddy components follow the exact same API pattern as CPU components above, with the following actions:

**RAM Management:**
- `ram-list`, `ram-get`, `ram-add`, `ram-update`, `ram-delete`

**Storage Management:**
- `storage-list`, `storage-get`, `storage-add`, `storage-update`, `storage-delete`

**Motherboard Management:**
- `motherboard-list`, `motherboard-get`, `motherboard-add`, `motherboard-update`, `motherboard-delete`

**Network Interface Card Management:**
- `nic-list`, `nic-get`, `nic-add`, `nic-update`, `nic-delete`
- Additional fields for NIC: `MacAddress`, `IPAddress`, `NetworkName`

**Caddy Management:**
- `caddy-list`, `caddy-get`, `caddy-add`, `caddy-update`, `caddy-delete`

All follow the same request/response patterns as CPU management above, with component-specific fields where applicable.

---

## 3. Server Configuration Management APIs

### 3.1 Start Server Creation
**Action:** `server-create-start`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `server.create`

**Purpose:** Initialize new server configuration

**Request Body (form-data):**
```
action: server-create-start
server_name: Production Server 01
description: High-performance production server (optional)
category: custom (optional, default: "custom")
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Server configuration created successfully",
    "data": {
        "config_uuid": "d95e2554-8eb6-4a3c-90d7-45881af2a9d3",
        "server_name": "Production Server 01",
        "description": "High-performance production server",
        "category": "custom",
        "next_step": "motherboard",
        "progress": {
            "total_steps": 6,
            "completed_steps": 0,
            "current_step": "component_selection",
            "components_added": []
        },
        "compatibility_engine_available": true
    }
}
```

### 3.2 Add Component to Configuration
**Action:** `server-add-component`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `server.create` (owner) or `server.edit_all` (admin)

**Purpose:** Add hardware component to server configuration

**Request Body (form-data):**
```
action: server-add-component
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
component_type: cpu
component_uuid: 41849749-8d19-4366-b41a-afda6fa46b58
quantity: 1 (optional, default: 1)
slot_position: CPU_1 (optional)
notes: Primary CPU (optional)
override: false (optional, default: false - set to true to override status issues)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Component added successfully",
    "data": {
        "component_added": {
            "type": "cpu",
            "uuid": "41849749-8d19-4366-b41a-afda6fa46b58",
            "quantity": 1,
            "status_override_used": false,
            "original_status": "Component is Available"
        },
        "configuration_summary": {
            "config_uuid": "d95e2554-8eb6-4a3c-90d7-45881af2a9d3",
            "total_components": 1,
            "component_types": ["cpu"]
        },
        "next_recommendations": [
            "Consider adding compatible motherboard",
            "Add RAM modules for optimal performance"
        ],
        "compatibility_issues": []
    }
}
```

### 3.3 Remove Component from Configuration
**Action:** `server-remove-component`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `server.edit` (owner) or `server.edit_all` (admin)

**Purpose:** Remove component from server configuration

**Request Body (form-data):**
```
action: server-remove-component
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
component_type: cpu
component_uuid: 41849749-8d19-4366-b41a-afda6fa46b58
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Component removed successfully",
    "data": {
        "component_removed": {
            "type": "cpu",
            "uuid": "41849749-8d19-4366-b41a-afda6fa46b58"
        },
        "configuration_summary": {
            "config_uuid": "d95e2554-8eb6-4a3c-90d7-45881af2a9d3",
            "total_components": 0,
            "component_types": []
        }
    }
}
```

### 3.4 Get Configuration Details
**Action:** `server-get-config`
**Method:** `GET` or `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `server.view` (owner) or `server.view_all` (admin)

**Purpose:** Retrieve complete server configuration details

**Request Parameters (GET/POST):**
```
action: server-get-config
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Configuration retrieved successfully",
    "data": {
        "configuration": {
            "config_uuid": "d95e2554-8eb6-4a3c-90d7-45881af2a9d3",
            "server_name": "Production Server 01",
            "configuration_status": 1,
            "created_by": 1,
            "created_at": "2025-01-15 10:00:00",
            "components": [
                {
                    "component_type": "cpu",
                    "component_uuid": "cpu-uuid-123",
                    "quantity": 1,
                    "slot_position": "CPU_1"
                }
            ]
        },
        "summary": {
            "total_components": 1,
            "estimated_cost": 2500.00,
            "power_consumption": 150
        },
        "validation": {
            "is_valid": true,
            "compatibility_score": 0.95,
            "issues": [],
            "warnings": []
        }
    }
}
```

### 3.5 List Server Configurations
**Action:** `server-list-configs`
**Method:** `GET`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `server.view` (own configs) or `server.view_all` (all configs)

**Purpose:** List server configurations with pagination

**Request Parameters (GET):**
```
action: server-list-configs
limit: 20 (optional, default: 20)
offset: 0 (optional, default: 0)
status: 1 (optional - filter by configuration status)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Configurations retrieved successfully",
    "data": {
        "configurations": [
            {
                "config_uuid": "d95e2554-8eb6-4a3c-90d7-45881af2a9d3",
                "server_name": "Production Server 01",
                "configuration_status": 1,
                "created_by": 1,
                "created_by_username": "admin",
                "created_at": "2025-01-15 10:00:00",
                "component_count": 5,
                "estimated_cost": 12500.00
            }
        ],
        "pagination": {
            "total": 45,
            "limit": 20,
            "offset": 0,
            "has_more": true
        }
    }
}
```

### 3.6 Finalize Configuration
**Action:** `server-finalize-config`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `server.finalize` (owner) or admin permissions

**Purpose:** Finalize server configuration and deploy

**Request Body (form-data):**
```
action: server-finalize-config
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
notes: Final deployment notes (optional)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Configuration finalized successfully",
    "data": {
        "config_uuid": "d95e2554-8eb6-4a3c-90d7-45881af2a9d3",
        "finalization_details": {
            "components_assigned": 5,
            "deployment_date": "2025-01-15 14:30:00",
            "server_id": "SERVER-001"
        }
    }
}
```

### 3.7 Validate Configuration
**Action:** `server-validate-config`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `server.view` (owner) or `server.view_all` (admin)

**Purpose:** Validate server configuration compatibility

**Request Body (form-data):**
```
action: server-validate-config
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Configuration validation completed",
    "data": {
        "config_uuid": "d95e2554-8eb6-4a3c-90d7-45881af2a9d3",
        "validation": {
            "is_valid": true,
            "compatibility_score": 0.95,
            "issues": [],
            "warnings": ["Consider adding redundant power supply"],
            "recommendations": ["Add ECC RAM for production environment"]
        }
    }
}
```

### 3.8 Get Available Components
**Action:** `server-get-available-components`
**Method:** `GET` or `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `server.view`

**Purpose:** Get available components for server configuration

**Request Parameters (GET/POST):**
```
action: server-get-available-components
component_type: cpu (required - options: cpu, ram, storage, motherboard, nic, caddy)
include_in_use: false (optional, default: false)
limit: 50 (optional, default: 50)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Available components retrieved successfully",
    "data": {
        "component_type": "cpu",
        "components": [
            {
                "UUID": "41849749-8d19-4366-b41a-afda6fa46b58",
                "SerialNumber": "CPU001",
                "Status": 1,
                "Model": "Intel Xeon E5-2680 v4",
                "Cores": 14,
                "Threads": 28
            }
        ],
        "counts": {
            "total": 15,
            "available": 10,
            "in_use": 4,
            "failed": 1
        },
        "include_in_use": false,
        "total_returned": 10
    }
}
```

### 3.9 Delete Configuration
**Action:** `server-delete-config`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `server.delete` (owner) or admin permissions

**Purpose:** Delete server configuration

**Request Body (form-data):**
```
action: server-delete-config
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Configuration deleted successfully"
}
```

**Error Response (403 - Cannot Delete Finalized):**
```json
{
    "success": false,
    "authenticated": true,
    "message": "Cannot delete finalized configurations",
    "code": 403
}
```

---

## 4. Chassis Management APIs

### 4.1 List Chassis Inventory
**Action:** `chassis-list`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `chassis.view`

**Purpose:** List all chassis in inventory with specifications

**Request Body (form-data):**
```
action: chassis-list
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Chassis inventory retrieved successfully",
    "data": [
        {
            "id": 1,
            "UUID": "chassis-uuid-123",
            "SerialNumber": "CHASSIS001",
            "Status": 1,
            "Location": "Rack A1",
            "RackPosition": "U1-4",
            "specifications": {
                "model": "Dell PowerEdge R740",
                "brand": "Dell",
                "form_factor": "2U",
                "chassis_type": "rackmount",
                "total_bays": 8
            }
        }
    ],
    "count": 5,
    "timestamp": "2025-01-15 14:30:00"
}
```

### 4.2 Add Chassis
**Action:** `chassis-add`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `chassis.create`

**Purpose:** Add new chassis to inventory

**Request Body (form-data):**
```
action: chassis-add
uuid: chassis-uuid-123 (required)
serial_number: CHASSIS001 (required)
location: Rack A1 (optional)
rack_position: U1-4 (optional)
purchase_date: 2024-01-15 (optional)
warranty_end_date: 2027-01-15 (optional)
notes: Production chassis (optional)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Chassis added successfully",
    "data": {
        "id": 6,
        "uuid": "chassis-uuid-123",
        "serial_number": "CHASSIS001",
        "specifications": {
            "model": "Dell PowerEdge R740",
            "brand": "Dell",
            "total_bays": 8
        }
    }
}
```

### 4.3 Get Chassis Details
**Action:** `chassis-get`
**Method:** `GET`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `chassis.view`

**Purpose:** Get detailed chassis information and bay configuration

**Request Parameters (GET):**
```
action: chassis-get
uuid: chassis-uuid-123
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Chassis details retrieved successfully",
    "data": {
        "id": 1,
        "UUID": "chassis-uuid-123",
        "SerialNumber": "CHASSIS001",
        "Status": 1,
        "detailed_specifications": {
            "model": "Dell PowerEdge R740",
            "brand": "Dell",
            "form_factor": "2U",
            "drive_bays": {
                "total_bays": 8,
                "bay_types": ["SFF", "LFF"],
                "hot_swap": true
            }
        },
        "bay_configuration": {
            "success": true,
            "available_bays": 6,
            "occupied_bays": 2,
            "bay_details": [
                {
                    "bay_number": 1,
                    "status": "occupied",
                    "component": "storage-uuid-123"
                }
            ]
        }
    }
}
```

### 4.4 Get Available Bays
**Action:** `chassis-get-available-bays`
**Method:** `GET`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `chassis.view`

**Purpose:** Get available drive bays for specific chassis

**Request Parameters (GET):**
```
action: chassis-get-available-bays
chassis_uuid: chassis-uuid-123 (required)
config_uuid: server-config-uuid (optional)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Available bays retrieved successfully",
    "data": {
        "success": true,
        "chassis_uuid": "chassis-uuid-123",
        "total_bays": 8,
        "available_bays": 6,
        "bay_details": [
            {
                "bay_number": 3,
                "bay_type": "SFF",
                "available": true,
                "compatible_types": ["SATA", "SAS", "NVMe"]
            }
        ]
    }
}
```

---

## 5. Compatibility Engine APIs

### 5.1 Check Component Pair Compatibility
**Action:** `compatibility-check_pair`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `compatibility.check`

**Purpose:** Check compatibility between two specific components

**Request Body (form-data):**
```
action: compatibility-check_pair
component1_type: cpu
component1_uuid: cpu-uuid-123
component2_type: motherboard
component2_uuid: motherboard-uuid-456
include_details: true (optional, default: true)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Compatibility check completed",
    "data": {
        "component_1": {
            "type": "cpu",
            "uuid": "cpu-uuid-123"
        },
        "component_2": {
            "type": "motherboard",
            "uuid": "motherboard-uuid-456"
        },
        "compatibility_result": {
            "compatible": true,
            "compatibility_score": 0.95,
            "failures": [],
            "warnings": ["Consider BIOS update for optimal performance"],
            "applied_rules": ["socket_compatibility", "power_requirements"]
        },
        "summary": {
            "compatible": true,
            "score": 0.95,
            "issues_count": 0,
            "warnings_count": 1
        }
    }
}
```

### 5.2 Check Multiple Component Compatibility
**Action:** `compatibility-check_multiple`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `compatibility.check`

**Purpose:** Check compatibility among multiple components

**Request Body (form-data):**
```
action: compatibility-check_multiple
components: [
    {"type": "cpu", "uuid": "cpu-uuid-123"},
    {"type": "motherboard", "uuid": "motherboard-uuid-456"},
    {"type": "ram", "uuid": "ram-uuid-789"}
] (JSON array)
cross_check: true (optional, default: true)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Multiple component compatibility check completed",
    "data": {
        "components": [
            {"type": "cpu", "uuid": "cpu-uuid-123"},
            {"type": "motherboard", "uuid": "motherboard-uuid-456"},
            {"type": "ram", "uuid": "ram-uuid-789"}
        ],
        "check_type": "cross_check",
        "individual_results": [
            {
                "component_1": {"type": "cpu", "uuid": "cpu-uuid-123"},
                "component_2": {"type": "motherboard", "uuid": "motherboard-uuid-456"},
                "result": {
                    "compatible": true,
                    "compatibility_score": 0.95
                }
            }
        ],
        "overall_summary": {
            "compatible": true,
            "average_score": 0.92,
            "total_checks": 3,
            "total_issues": 0,
            "total_warnings": 1
        }
    }
}
```

### 5.3 Get Compatible Components
**Action:** `compatibility-get_compatible_for`
**Method:** `GET` or `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `compatibility.check`

**Purpose:** Find components compatible with a base component

**Request Parameters (GET/POST):**
```
action: compatibility-get_compatible_for
base_component_type: cpu
base_component_uuid: cpu-uuid-123
target_type: motherboard (optional - specific type or all types)
available_only: true (optional, default: true)
limit: 50 (optional, default: 50)
include_scores: true (optional, default: true)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Compatible components retrieved",
    "data": {
        "base_component": {
            "type": "cpu",
            "uuid": "cpu-uuid-123"
        },
        "target_type": "motherboard",
        "compatible_components": [
            {
                "UUID": "motherboard-uuid-456",
                "SerialNumber": "MB001",
                "Status": 1,
                "compatibility_score": 0.95,
                "compatibility_notes": "Excellent match - socket and chipset compatible"
            }
        ],
        "total_found": 12
    }
}
```

### 5.4 Batch Compatibility Check
**Action:** `compatibility-batch_check`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `compatibility.check`

**Purpose:** Perform multiple compatibility checks in batch

**Request Body (form-data):**
```
action: compatibility-batch_check
component_pairs: [
    {
        "component1": {"type": "cpu", "uuid": "cpu-uuid-123"},
        "component2": {"type": "motherboard", "uuid": "motherboard-uuid-456"}
    }
] (JSON array)
stop_on_first_failure: false (optional, default: false)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Batch compatibility check completed",
    "data": {
        "results": [
            {
                "pair_index": 0,
                "component_1": {"type": "cpu", "uuid": "cpu-uuid-123"},
                "component_2": {"type": "motherboard", "uuid": "motherboard-uuid-456"},
                "result": {
                    "compatible": true,
                    "compatibility_score": 0.95
                },
                "execution_time_ms": 15.67
            }
        ],
        "statistics": {
            "total_checks": 1,
            "successful_checks": 1,
            "failed_checks": 0,
            "average_score": 0.95,
            "success_rate": 100.0,
            "total_execution_time": 15.67
        }
    }
}
```

### 5.5 Analyze Server Configuration
**Action:** `compatibility-analyze_configuration`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `compatibility.check`

**Purpose:** Analyze complete server configuration for compatibility

**Request Body (form-data):**
```
action: compatibility-analyze_configuration
configuration: {
    "cpu": [{"uuid": "cpu-uuid-123"}],
    "motherboard": [{"uuid": "motherboard-uuid-456"}],
    "ram": [{"uuid": "ram-uuid-789"}]
} (JSON object)
include_recommendations: true (optional, default: true)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Configuration analysis completed",
    "data": {
        "configuration_analysis": {
            "valid": true,
            "overall_score": 0.92,
            "component_checks": [
                {
                    "components": "CPU-Motherboard",
                    "compatible": true,
                    "score": 0.95,
                    "issues": [],
                    "warnings": []
                }
            ],
            "global_checks": [
                {
                    "check": "power_consumption",
                    "passed": true,
                    "message": "Total power consumption within limits"
                }
            ]
        },
        "summary": {
            "overall_valid": true,
            "overall_score": 0.92,
            "component_check_count": 3,
            "global_check_count": 2
        },
        "recommendations": [
            {
                "type": "performance",
                "priority": "low",
                "message": "Consider upgrading to faster RAM",
                "details": "Current RAM speed is adequate but faster modules available"
            }
        ]
    }
}
```

### 5.6 Get Compatibility Statistics
**Action:** `compatibility-get_statistics`
**Method:** `GET` or `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `compatibility.check`

**Purpose:** Get compatibility engine usage statistics

**Request Parameters (GET/POST):**
```
action: compatibility-get_statistics
timeframe: 24 HOUR (optional, default: 24 HOUR)
include_details: false (optional, default: false)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Compatibility statistics retrieved",
    "data": {
        "timeframe": "24 HOUR",
        "statistics": {
            "total_checks": 1250,
            "successful_checks": 1180,
            "failed_checks": 70,
            "success_rate": 94.4,
            "average_execution_time_ms": 12.5,
            "most_checked_pairs": [
                {"pair": "cpu-motherboard", "count": 450},
                {"pair": "ram-motherboard", "count": 380}
            ]
        }
    }
}
```

---

## 6. User Management APIs

### 6.1 List Users
**Action:** `users-list`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `user.view`

**Purpose:** List all users in the system

**Request Body (form-data):**
```
action: users-list
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Users retrieved successfully",
    "data": {
        "users": [
            {
                "id": 1,
                "username": "superadmin",
                "email": "admin@example.com",
                "firstname": "Super",
                "lastname": "Admin",
                "status": "active",
                "created_at": "2024-01-01 10:00:00"
            }
        ]
    }
}
```

### 6.2 Create User
**Action:** `users-create`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `user.create`

**Purpose:** Create new user account

**Request Body (form-data):**
```
action: users-create
username: newuser (required)
email: user@example.com (required)
password: securepassword (required)
firstname: John (optional)
lastname: Doe (optional)
```

**Success Response (201):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "User created successfully",
    "data": {
        "user_id": 5
    }
}
```

### 6.3 Update User
**Action:** `users-update`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `user.edit`

**Purpose:** Update user information

**Request Body (form-data):**
```
action: users-update
user_id: 5 (required)
username: updateduser (optional)
email: newemail@example.com (optional)
firstname: Jane (optional)
lastname: Smith (optional)
status: active (optional)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "User updated successfully"
}
```

### 6.4 Delete User
**Action:** `users-delete`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `user.delete`

**Purpose:** Delete user account

**Request Body (form-data):**
```
action: users-delete
user_id: 5 (required)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "User deleted successfully"
}
```

### 6.5 Get User Details
**Action:** `users-get`
**Method:** `GET` or `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `user.view`

**Purpose:** Get specific user details

**Request Parameters (GET/POST):**
```
action: users-get
user_id: 5
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "User retrieved successfully",
    "data": {
        "user": {
            "id": 5,
            "username": "newuser",
            "email": "user@example.com",
            "firstname": "John",
            "lastname": "Doe",
            "status": "active",
            "created_at": "2024-01-15 14:30:00"
        }
    }
}
```

---

## 7. Role & Permission Management APIs

### 7.1 List Roles
**Action:** `roles-list`
**Method:** `GET` or `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `role.manage`

**Purpose:** List all roles in the system

**Request Body (form-data):**
```
action: roles-list
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Roles retrieved successfully",
    "data": {
        "roles": [
            {
                "id": 1,
                "name": "administrator",
                "display_name": "Administrator",
                "description": "Full system access",
                "created_at": "2024-01-01 10:00:00"
            }
        ],
        "total": 5
    }
}
```

### 7.2 Create Role
**Action:** `roles-create`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `role.manage`

**Purpose:** Create new role

**Request Body (form-data):**
```
action: roles-create
name: operator (required)
display_name: System Operator (optional)
description: Limited system access (optional)
```

**Success Response (201):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Role created successfully",
    "data": {
        "role_id": 6
    }
}
```

### 7.3 List Permissions
**Action:** `permissions-list`
**Method:** `GET` or `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `roles.view`

**Purpose:** List all available permissions

**Request Body (form-data):**
```
action: permissions-list
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Permissions retrieved successfully",
    "data": {
        "permissions": {
            "cpu": [
                {"name": "cpu.view", "description": "View CPU components"},
                {"name": "cpu.create", "description": "Create CPU components"}
            ],
            "server": [
                {"name": "server.view", "description": "View server configurations"},
                {"name": "server.create", "description": "Create server configurations"}
            ]
        },
        "total": 45,
        "categories": ["cpu", "server", "user", "role"]
    }
}
```

---

## 8. Search & Dashboard APIs

### 8.1 Global Search
**Action:** `search-global`
**Method:** `GET`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `search.use`

**Purpose:** Search across all components and configurations

**Request Parameters (GET):**
```
action: search-global
q: intel (required - search query)
limit: 20 (optional, default: 20)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Search completed",
    "data": {
        "query": "intel",
        "results": [
            {
                "ID": 1,
                "UUID": "cpu-uuid-123",
                "SerialNumber": "CPU001",
                "component_type": "cpu",
                "Status": 1,
                "Location": "Rack A1",
                "Notes": "Intel Xeon processor"
            }
        ],
        "total_found": 15
    }
}
```

### 8.2 Dashboard Data
**Action:** `dashboard-get_data`
**Method:** `GET`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `dashboard.view`

**Purpose:** Get dashboard statistics and overview data

**Request Parameters (GET):**
```
action: dashboard-get_data
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Dashboard data retrieved",
    "data": {
        "component_counts": {
            "cpu": {
                "total": 45,
                "available": 30,
                "in_use": 10,
                "failed": 5
            },
            "ram": {
                "total": 120,
                "available": 80,
                "in_use": 35,
                "failed": 5
            },
            "servers": {
                "total": 25,
                "draft": 5,
                "validated": 10,
                "built": 8,
                "finalized": 2
            }
        },
        "total_components": 450,
        "recent_activity": []
    }
}
```

---

## 9. ACL (Access Control List) Operations

### 9.1 Get User Permissions
**Action:** `acl-get_user_permissions`
**Method:** `GET` or `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `acl.manage`

**Purpose:** Get permissions for specific user

**Request Parameters (GET/POST):**
```
action: acl-get_user_permissions
user_id: 5
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "User permissions retrieved",
    "data": {
        "user_id": 5,
        "permissions": ["cpu.view", "server.view", "dashboard.view"],
        "roles": [
            {
                "id": 2,
                "name": "operator",
                "display_name": "System Operator"
            }
        ]
    }
}
```

### 9.2 Assign Permission
**Action:** `acl-assign_permission`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `acl.manage`

**Purpose:** Assign permission to user

**Request Body (form-data):**
```
action: acl-assign_permission
user_id: 5 (required)
permission: cpu.create (required)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Permission assigned successfully"
}
```

### 9.3 Revoke Permission
**Action:** `acl-revoke_permission`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `acl.manage`

**Purpose:** Revoke permission from user

**Request Body (form-data):**
```
action: acl-revoke_permission
user_id: 5 (required)
permission: cpu.create (required)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Permission revoked successfully"
}
```

### 9.4 Assign Role
**Action:** `acl-assign_role`
**Method:** `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `acl.manage`

**Purpose:** Assign role to user

**Request Body (form-data):**
```
action: acl-assign_role
user_id: 5 (required)
role_id: 2 (required)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Role assigned successfully"
}
```

### 9.5 Check Permission
**Action:** `acl-check_permission`
**Method:** `GET` or `POST`
**URL:** `api/api.php`
**Auth Required:** Yes
**Permission Required:** `acl.manage`

**Purpose:** Check if user has specific permission

**Request Parameters (GET/POST):**
```
action: acl-check_permission
user_id: 5 (optional, defaults to current user)
permission: cpu.create (required)
```

**Success Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Permission check completed",
    "data": {
        "user_id": 5,
        "permission": "cpu.create",
        "has_permission": true
    }
}
```

---

## Component Status Codes

- **0** - Failed/Defective (cannot be used)
- **1** - Available (can be assigned to servers)
- **2** - In Use (currently assigned, can be overridden)

---

## Configuration Status Codes

- **0** - Draft
- **1** - Active
- **2** - In Progress
- **3** - Finalized (cannot be modified without special permissions)

---

## Permission Categories

### Component Permissions:
- `cpu.view`, `cpu.create`, `cpu.edit`, `cpu.delete`
- `ram.view`, `ram.create`, `ram.edit`, `ram.delete`
- `storage.view`, `storage.create`, `storage.edit`, `storage.delete`
- `motherboard.view`, `motherboard.create`, `motherboard.edit`, `motherboard.delete`
- `nic.view`, `nic.create`, `nic.edit`, `nic.delete`
- `caddy.view`, `caddy.create`, `caddy.edit`, `caddy.delete`
- `chassis.view`, `chassis.create`, `chassis.edit`, `chassis.delete`

### Server Management Permissions:
- `server.create` - Create new server configurations
- `server.view` - View own server configurations
- `server.edit` - Edit own configurations
- `server.delete` - Delete own configurations
- `server.finalize` - Finalize configurations
- `server.view_all` - View all users' configurations (admin)
- `server.edit_all` - Edit all users' configurations (admin)

### System Permissions:
- `user.view`, `user.create`, `user.edit`, `user.delete`
- `role.manage` - Manage roles and permissions
- `acl.manage` - Manage access control
- `compatibility.check` - Use compatibility engine
- `search.use` - Use search functionality
- `dashboard.view` - Access dashboard

---

## Common Error Responses

### Authentication Required (401):
```json
{
    "success": false,
    "authenticated": false,
    "message": "Authentication required",
    "code": 401
}
```

### Invalid Action (400):
```json
{
    "success": false,
    "authenticated": true,
    "message": "Invalid action specified",
    "code": 400
}
```

### Insufficient Permissions (403):
```json
{
    "success": false,
    "authenticated": true,
    "message": "Insufficient permissions to modify this configuration",
    "code": 403
}
```

### Resource Not Found (404):
```json
{
    "success": false,
    "authenticated": true,
    "message": "Resource not found",
    "code": 404
}
```

### Server Error (500):
```json
{
    "success": false,
    "authenticated": true,
    "message": "Internal server error",
    "code": 500
}
```

---

## Authentication Workflow Example

```bash
# STEP 1: Login to get JWT token
curl -X POST "https://shubham.staging.cloudmate.in/bdc_ims/api/api.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "action=auth-login&username=superadmin&password=password"

# Response includes access_token in data.tokens.access_token
# Example: "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."

# STEP 2: Use JWT token for authenticated requests
curl -X POST "https://shubham.staging.cloudmate.in/bdc_ims/api/api.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=cpu-list&limit=10"

# Working Credentials:
# Username: superadmin
# Password: password
```

---

## Complete Server Creation Workflow

1. **Login and Get Token**
   ```
   POST: action=auth-login
   → Returns: access_token
   ```

2. **Start Server Configuration**
   ```
   POST: action=server-create-start
   → Returns: config_uuid
   ```

3. **Add Components** (repeat for each component type)
   ```
   POST: action=server-add-component
   → Add CPU, Motherboard, RAM, Storage, NIC, etc.
   ```

4. **Validate Configuration**
   ```
   POST: action=server-validate-config
   → Check compatibility and completeness
   ```

5. **Finalize Server**
   ```
   POST: action=server-finalize-config
   → Lock configuration and assign components
   ```

---

## Notes

- All timestamps are in MySQL datetime format (YYYY-MM-DD HH:MM:SS)
- UUIDs are auto-generated for all configurations and components
- Use `override=true` parameter to force add components with status issues
- Component availability is checked in real-time during addition
- Compatibility engine integration is available if `CompatibilityEngine` class exists
- Configuration validation is performed before finalization
- JWT tokens expire based on system configuration (default: 24 hours)
- Refresh tokens can be used to get new access tokens without re-authentication
- All component types support the same CRUD operations with consistent patterns