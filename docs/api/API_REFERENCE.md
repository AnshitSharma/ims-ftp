# API_REFERENCE.md

**Complete BDC IMS API Endpoint Catalog (80+ Endpoints)**

> **Base URL**: `https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php`
>
> **Request Method**: POST (unless specified otherwise)
>
> **Authentication**: JWT Bearer token in `Authorization` header (except `auth-*` endpoints)
>
> **Total Endpoints**: 80+ covering 10 component types + system operations

## Table of Contents

- [Authentication](#authentication) (7 endpoints)
- [Server Configuration](#server-configuration) (25 endpoints)
  - [Core Configuration](#core-configuration)
  - [Retrieval & Validation](#retrieval--validation)
  - [Updates](#updates)
  - [SFP/Transceiver Management](#sfptransceiver-management)
  - [NIC Management](#nic-management)
- [Compatibility Checking](#compatibility-checking) (15 endpoints)
- [Component Management](#component-management) (54 endpoints - 9 types × 6 operations)
- [ACL & Permissions](#acl--permissions) (15 endpoints)
- [User Management](#user-management) (5 endpoints)
- [Dashboard & Search](#dashboard--search) (2 endpoints)

---

## Authentication

### auth-login
**Purpose**: Authenticate user and receive JWT tokens

**Parameters**:
- `username` (required): User's username
- `password` (required): User's password
- `remember_me` (optional): Boolean, extends token expiry

**Response**:
```json
{
  "success": true,
  "authenticated": true,
  "data": {
    "user": {
      "id": 1,
      "username": "admin",
      "email": "admin@example.com",
      "firstname": "Admin",
      "lastname": "User"
    },
    "tokens": {
      "access_token": "eyJ0eXAi...",
      "refresh_token": "a1b2c3...",
      "expires_in": 86400
    },
    "permissions": ["server.create", "cpu.view", ...]
  }
}
```

**Required Permission**: None (public)

---

### auth-logout
**Purpose**: Revoke refresh token and logout

**Parameters**:
- `refresh_token` (required): The refresh token to revoke

**Response**:
```json
{
  "success": true,
  "message": "Logged out successfully"
}
```

**Required Permission**: None (authenticated user)

---

### auth-refresh
**Purpose**: Refresh expired access token

**Parameters**:
- `refresh_token` (required): Valid refresh token

**Response**:
```json
{
  "success": true,
  "data": {
    "access_token": "new_token_here",
    "expires_in": 86400
  }
}
```

**Required Permission**: None (valid refresh token)

---

### auth-verify_token
**Purpose**: Verify if current access token is valid

**Parameters**: None (token in Authorization header)

**Response**:
```json
{
  "success": true,
  "data": {
    "valid": true,
    "user_id": 1,
    "username": "admin"
  }
}
```

**Required Permission**: None (any valid token)

---

### auth-register
**Purpose**: Create new user account

**Parameters**:
- `username` (required): Unique username
- `email` (required): Valid email address
- `password` (required): Password (min 8 chars)
- `firstname` (optional): User's first name
- `lastname` (optional): User's last name

**Response**:
```json
{
  "success": true,
  "data": {
    "user_id": 5,
    "username": "newuser",
    "message": "Please login with your credentials"
  }
}
```

**Required Permission**: None (public registration)

---

### auth-forgot_password
**Purpose**: Initiate password recovery process

**Parameters**:
- `email` (required): User's email address

**Response**:
```json
{
  "success": true,
  "message": "Password reset link sent to email"
}
```

**Required Permission**: None (public)

---

### auth-reset_password
**Purpose**: Reset password with recovery token

**Parameters**:
- `token` (required): Password reset token
- `new_password` (required): New password

**Response**:
```json
{
  "success": true,
  "message": "Password reset successfully"
}
```

**Required Permission**: None (valid reset token)

---

## Server Configuration

### Core Configuration

### server-initialize
**Purpose**: Start new server configuration

**Parameters**:
- `chassis_uuid` (required): UUID of chassis from inventory
- `config_name` (required): Name for this configuration

**Response**:
```json
{
  "success": true,
  "data": {
    "configuration_uuid": "config-abc123",
    "chassis_uuid": "chassis-dell-r740",
    "next_step": "add_motherboard"
  }
}
```

**Required Permission**: `server.create`

---

### server-add-component
**Purpose**: Add component to configuration with validation

**Parameters**:
- `config_uuid` (required): Configuration UUID
- `component_type` (required): One of: cpu, ram, storage, motherboard, nic, caddy, pciecard, hbacard
- `component_uuid` (required): Component UUID from inventory
- `quantity` (optional): Number of components (default: 1)

**Response**:
```json
{
  "success": true,
  "message": "Component added successfully",
  "data": {
    "component_uuid": "cpu-xeon-gold-6248r",
    "compatibility_status": "compatible",
    "pcie_slot_allocated": "slot_3_x8",
    "warnings": [],
    "next_compatible_types": ["storage", "nic", "ram"]
  }
}
```

**Required Permission**: `server.create`

---

### server-remove-component
**Purpose**: Remove component from configuration

**Parameters**:
- `config_uuid` (required): Configuration UUID
- `component_uuid` (required): Component UUID to remove

**Response**:
```json
{
  "success": true,
  "message": "Component removed successfully"
}
```

**Required Permission**: `server.edit`

---

### server-get-compatible
**Purpose**: Get list of compatible components for current configuration

**Parameters**:
- `config_uuid` (required): Configuration UUID
- `component_type` (required): Type to filter (cpu, ram, storage, etc.)

**Response**:
```json
{
  "success": true,
  "data": {
    "compatible_components": [
      {
        "UUID": "storage-sas-12gb-001",
        "model": "Seagate Exos X18 18TB SAS",
        "compatibility_score": 1.0,
        "connection_path": "hba_card",
        "warnings": []
      }
    ]
  }
}
```

**Required Permission**: `server.view`

---

### server-validate-config
**Purpose**: Validate entire server configuration

**Parameters**:
- `config_uuid` (required): Configuration UUID

**Response**:
```json
{
  "success": true,
  "data": {
    "valid": true,
    "overall_score": 0.98,
    "component_checks": [
      {
        "check": "cpu_motherboard_compatibility",
        "passed": true,
        "score": 1.0
      },
      {
        "check": "pcie_slot_allocation",
        "passed": true,
        "slots_used": 3,
        "slots_available": 8
      }
    ],
    "warnings": ["Power consumption near limit"],
    "recommendations": []
  }
}
```

**Required Permission**: `server.view`

---

### server-finalize-config
**Purpose**: Finalize configuration and mark components as in_use

**Parameters**:
- `config_uuid` (required): Configuration UUID

**Response**:
```json
{
  "success": true,
  "message": "Configuration finalized successfully",
  "data": {
    "configuration_uuid": "config-abc123",
    "components_marked_in_use": 12
  }
}
```

**Required Permission**: `server.create`

---

### server-get-config
**Purpose**: Retrieve complete configuration details

**Parameters**:
- `config_uuid` (required): Configuration UUID

**Response**:
```json
{
  "success": true,
  "data": {
    "configuration": {
      "uuid": "config-abc123",
      "name": "Production DB Server 01",
      "status": "active",
      "created_by": 1,
      "created_at": "2025-11-05 12:00:00"
    },
    "components": [
      {
        "type": "chassis",
        "uuid": "chassis-dell-r740",
        "quantity": 1
      },
      {
        "type": "cpu",
        "uuid": "cpu-xeon-gold-6248r",
        "quantity": 2
      }
    ]
  }
}
```

**Required Permission**: `server.view`

---

### server-list-configs
**Purpose**: List all server configurations

**Parameters**:
- `user_id` (optional): Filter by user (admins only)
- `status` (optional): Filter by status (draft, active, archived)

**Response**:
```json
{
  "success": true,
  "data": {
    "configurations": [
      {
        "uuid": "config-abc123",
        "name": "Production DB Server 01",
        "status": "active",
        "created_at": "2025-11-05 12:00:00"
      }
    ],
    "total_count": 15
  }
}
```

**Required Permission**: `server.view`

---

### server-delete-config
**Purpose**: Delete server configuration

**Parameters**:
- `config_uuid` (required): Configuration UUID

**Response**:
```json
{
  "success": true,
  "message": "Configuration deleted successfully"
}
```

**Required Permission**: `server.delete`

---

### server-save_draft
**Purpose**: Save current configuration as draft

**Parameters**:
- `config_uuid` (required): Configuration UUID
- `draft_name` (optional): Name for this draft

**Response**:
```json
{
  "success": true,
  "message": "Configuration saved as draft"
}
```

**Required Permission**: `server.edit`

---

### server-load_draft
**Purpose**: Load previously saved draft configuration

**Parameters**:
- `draft_id` (required): Draft ID

**Response**:
```json
{
  "success": true,
  "data": {
    "configuration_uuid": "config-abc123",
    "components": []
  }
}
```

**Required Permission**: `server.view`

---

### server-reset_configuration
**Purpose**: Reset current configuration to initial state

**Parameters**:
- `config_uuid` (required): Configuration UUID

**Response**:
```json
{
  "success": true,
  "message": "Configuration reset successfully"
}
```

**Required Permission**: `server.edit`

---

### server-clone-config
**Purpose**: Duplicate existing configuration

**Parameters**:
- `source_config_uuid` (required): Configuration to clone
- `new_config_name` (required): Name for cloned config

**Response**:
```json
{
  "success": true,
  "data": {
    "new_configuration_uuid": "config-xyz789",
    "cloned_from": "config-abc123"
  }
}
```

**Required Permission**: `server.create`

---

### server-update-config
**Purpose**: Update configuration details

**Parameters**:
- `config_uuid` (required): Configuration UUID
- `name` (optional): New configuration name
- `description` (optional): Configuration description

**Response**:
```json
{
  "success": true,
  "message": "Configuration updated successfully"
}
```

**Required Permission**: `server.edit`

---

### server-update-location
**Purpose**: Update component location and propagate changes

**Parameters**:
- `config_uuid` (required): Configuration UUID
- `new_location` (required): New physical location

**Response**:
```json
{
  "success": true,
  "message": "Location updated for all components",
  "data": {
    "components_updated": 12
  }
}
```

**Required Permission**: `server.edit`

---

### Retrieval & Validation

### server-get-available-components
**Purpose**: Get list of unallocated inventory components

**Parameters**:
- `component_type` (required): Component type to filter
- `limit` (optional): Results limit

**Response**:
```json
{
  "success": true,
  "data": {
    "available_components": [
      {
        "ID": 15,
        "UUID": "cpu-xeon-gold-6248r-001",
        "SerialNumber": "SN123456",
        "Status": 1
      }
    ],
    "total_count": 25
  }
}
```

**Required Permission**: `server.view`

---

### server-get-statistics
**Purpose**: Get configuration and inventory statistics

**Parameters**:
- `config_uuid` (optional): Specific config statistics

**Response**:
```json
{
  "success": true,
  "data": {
    "total_configurations": 45,
    "draft_configs": 8,
    "active_configs": 37,
    "components_in_use": 234,
    "components_available": 156
  }
}
```

**Required Permission**: `server.view`

---

### server-export-config
**Purpose**: Export configuration to file

**Parameters**:
- `config_uuid` (required): Configuration UUID
- `format` (optional): Export format (json, csv, xml)

**Response**:
```
Binary file download (JSON/CSV/XML)
```

**Required Permission**: `server.view`

---

### Updates

### SFP/Transceiver Management

### server-add-sfp
**Purpose**: Add SFP module to NIC

**Parameters**:
- `config_uuid` (required): Configuration UUID
- `nic_uuid` (required): NIC component UUID
- `sfp_uuid` (required): SFP transceiver UUID
- `port_number` (required): NIC port number (1-based)

**Response**:
```json
{
  "success": true,
  "message": "SFP module added successfully",
  "data": {
    "nic_uuid": "nic-intel-x520-001",
    "port_number": 1,
    "sfp_uuid": "sfp-10g-sr-001"
  }
}
```

**Required Permission**: `server.create`

---

### server-remove-sfp
**Purpose**: Remove SFP module from NIC

**Parameters**:
- `config_uuid` (required): Configuration UUID
- `nic_uuid` (required): NIC component UUID
- `port_number` (required): NIC port number

**Response**:
```json
{
  "success": true,
  "message": "SFP module removed successfully"
}
```

**Required Permission**: `server.edit`

---

### server-assign-sfp-to-nic
**Purpose**: Assign SFP module to specific NIC port

**Parameters**:
- `config_uuid` (required): Configuration UUID
- `sfp_uuid` (required): SFP UUID
- `nic_uuid` (required): NIC UUID
- `port_number` (required): Port number

**Response**:
```json
{
  "success": true,
  "message": "SFP assigned to NIC port successfully"
}
```

**Required Permission**: `server.edit`

---

### server-get-compatible-sfps
**Purpose**: Get SFP modules compatible with NIC

**Parameters**:
- `nic_uuid` (required): NIC component UUID
- `config_uuid` (required): Configuration UUID

**Response**:
```json
{
  "success": true,
  "data": {
    "compatible_sfps": [
      {
        "UUID": "sfp-10g-sr-001",
        "speed": "10G",
        "type": "SFP+",
        "compatibility_score": 1.0
      }
    ]
  }
}
```

**Required Permission**: `server.view`

---

### NIC Management

### server-replace-onboard-nic
**Purpose**: Replace motherboard onboard NIC with add-in NIC card

**Parameters**:
- `config_uuid` (required): Configuration UUID
- `motherboard_uuid` (required): Motherboard UUID
- `nic_uuid` (required): Add-in NIC UUID

**Response**:
```json
{
  "success": true,
  "message": "Onboard NIC replaced with add-in NIC",
  "data": {
    "disabled_ports": 2,
    "enabled_ports": 4
  }
}
```

**Required Permission**: `server.edit`

---

### server-fix-onboard-nics
**Purpose**: Synchronize onboard NIC specifications with motherboard specs

**Parameters**:
- `config_uuid` (required): Configuration UUID
- `motherboard_uuid` (required): Motherboard UUID

**Response**:
```json
{
  "success": true,
  "message": "Onboard NIC specifications synchronized"
}
```

**Required Permission**: `server.edit`

---

### server-debug-motherboard-nics
**Purpose**: Debug and display motherboard NIC specifications

**Parameters**:
- `motherboard_uuid` (required): Motherboard UUID

**Response**:
```json
{
  "success": true,
  "data": {
    "motherboard_uuid": "mb-supermicro-x11ssl-001",
    "onboard_nics": [
      {
        "port": 1,
        "speed": "1G",
        "type": "Ethernet"
      }
    ]
  }
}
```

**Required Permission**: `server.view`

---

## Compatibility Checking

### compatibility-check-pair
**Purpose**: Check compatibility between two components

**Parameters**:
- `component_type_1` (required): First component type
- `component_uuid_1` (required): First component UUID
- `component_type_2` (required): Second component type
- `component_uuid_2` (required): Second component UUID

**Response**:
```json
{
  "success": true,
  "data": {
    "compatible": true,
    "score": 1.0,
    "issues": [],
    "warnings": []
  }
}
```

**Required Permission**: `compatibility.check`

---

### compatibility-check-multiple
**Purpose**: Check compatibility of multiple components together

**Parameters**:
- `components` (required): JSON array of {type, uuid} objects

**Response**:
```json
{
  "success": true,
  "data": {
    "compatible": true,
    "overall_score": 0.95,
    "individual_checks": [],
    "warnings": ["High power consumption"]
  }
}
```

**Required Permission**: `compatibility.check`

---

### compatibility-get-compatible-for
**Purpose**: Get compatible components for a specific component type

**Parameters**:
- `component_type` (required): Component type to find matches for
- `reference_type` (required): Reference component type
- `reference_uuid` (required): Reference component UUID

**Response**:
```json
{
  "success": true,
  "data": {
    "compatible_components": [
      {
        "UUID": "component-uuid",
        "name": "Component Name",
        "compatibility_score": 0.95
      }
    ]
  }
}
```

**Required Permission**: `compatibility.check`

---

### compatibility-batch-check
**Purpose**: Perform batch compatibility validation

**Parameters**:
- `components` (required): Array of component objects

**Response**:
```json
{
  "success": true,
  "data": {
    "results": [
      {
        "pair": ["cpu-uuid", "mb-uuid"],
        "compatible": true,
        "score": 1.0
      }
    ]
  }
}
```

**Required Permission**: `compatibility.check`

---

### compatibility-analyze-configuration
**Purpose**: Full analysis of entire configuration

**Parameters**:
- `config_uuid` (required): Configuration UUID

**Response**:
```json
{
  "success": true,
  "data": {
    "analysis": {
      "cpu_count": 2,
      "ram_count": 12,
      "storage_count": 8,
      "potential_issues": [],
      "warnings": []
    }
  }
}
```

**Required Permission**: `compatibility.check`

---

### compatibility-get-rules
**Purpose**: Retrieve compatibility rules

**Parameters**: None

**Response**:
```json
{
  "success": true,
  "data": {
    "rules": [
      {
        "rule_id": 1,
        "type": "cpu_motherboard",
        "description": "CPU socket must match motherboard socket"
      }
    ]
  }
}
```

**Required Permission**: `compatibility.view_statistics`

---

### compatibility-test-rule
**Purpose**: Test specific compatibility rule

**Parameters**:
- `rule_id` (required): Rule ID
- `component_1` (required): Component 1 UUID
- `component_2` (required): Component 2 UUID

**Response**:
```json
{
  "success": true,
  "data": {
    "rule_passes": true,
    "details": "Socket type matches"
  }
}
```

**Required Permission**: `compatibility.check`

---

### compatibility-get-statistics
**Purpose**: Get compatibility validation statistics

**Parameters**: None

**Response**:
```json
{
  "success": true,
  "data": {
    "total_checks": 1542,
    "successful": 1480,
    "failed": 62,
    "success_rate": 0.96
  }
}
```

**Required Permission**: `compatibility.view_statistics`

---

### compatibility-clear-cache
**Purpose**: Clear compatibility cache

**Parameters**: None

**Response**:
```json
{
  "success": true,
  "message": "Compatibility cache cleared"
}
```

**Required Permission**: `compatibility.manage_rules`

---

### compatibility-benchmark-performance
**Purpose**: Benchmark compatibility checking performance

**Parameters**: None

**Response**:
```json
{
  "success": true,
  "data": {
    "average_check_time_ms": 45,
    "cache_hit_rate": 0.78
  }
}
```

**Required Permission**: `compatibility.manage_rules`

---

### compatibility-export-rules
**Purpose**: Export compatibility rules to file

**Parameters**:
- `format` (optional): Export format (json, csv)

**Response**:
```
Binary file download
```

**Required Permission**: `compatibility.manage_rules`

---

### compatibility-import-rules
**Purpose**: Import compatibility rules from file

**Parameters**:
- `file` (required): Uploaded file with rules

**Response**:
```json
{
  "success": true,
  "message": "Rules imported successfully",
  "data": {
    "rules_imported": 15
  }
}
```

**Required Permission**: `compatibility.manage_rules`

---

### compatibility-check-storage-direct
**Purpose**: Direct storage validation (storage→backplane/HBA)

**Parameters**:
- `storage_uuid` (required): Storage component UUID
- `chassis_uuid` (required): Chassis UUID
- `hba_uuid` (optional): HBA card UUID

**Response**:
```json
{
  "success": true,
  "data": {
    "compatible": true,
    "connection_path": "hba_card",
    "requires_caddy": false
  }
}
```

**Required Permission**: `compatibility.check`

---

### compatibility-check-storage-recursive
**Purpose**: Recursive storage validation through connection chain

**Parameters**:
- `storage_uuid` (required): Storage UUID
- `chassis_uuid` (required): Chassis UUID
- `depth` (optional): Max recursion depth

**Response**:
```json
{
  "success": true,
  "data": {
    "compatible": true,
    "connection_chain": [
      "storage",
      "hba_card",
      "motherboard_slot"
    ]
  }
}
```

**Required Permission**: `compatibility.check`

---

## Component Management

### {component}-list
**Pattern**: `cpu-list`, `ram-list`, `storage-list`, etc.

**Purpose**: List all components of specified type

**Parameters**:
- `status` (optional): Filter by status (0=failed, 1=available, 2=in_use)
- `limit` (optional): Number of results to return
- `offset` (optional): Pagination offset

**Response**:
```json
{
  "success": true,
  "data": {
    "components": [
      {
        "ID": 1,
        "UUID": "cpu-xeon-gold-6248r-001",
        "SerialNumber": "SN123456",
        "Status": 1,
        "Location": "Rack-A-Shelf-1",
        "Notes": "Intel Xeon Gold 6248R"
      }
    ],
    "total_count": 45
  }
}
```

**Required Permission**: `{component}.view`

**Component Types**: `cpu`, `ram`, `storage`, `motherboard`, `nic`, `caddy`, `chassis`, `pciecard`, `hbacard`, `sfp`

---

### {component}-add
**Pattern**: `cpu-add`, `ram-add`, `storage-add`, etc.

**Purpose**: Add new component to inventory

**Parameters**:
- `UUID` (required): Component UUID (must exist in JSON specs)
- `SerialNumber` (required): Unique serial number
- `Status` (optional): 0=failed, 1=available (default), 2=in_use
- `Location` (optional): Physical location
- `Notes` (optional): Additional notes

**Response**:
```json
{
  "success": true,
  "message": "Component added successfully",
  "data": {
    "component_id": 15,
    "uuid": "cpu-xeon-gold-6248r-001"
  }
}
```

**Required Permission**: `{component}.create`

**Note**: UUID **must exist** in corresponding `All-JSON/{component}-jsons/*.json` file

---

### {component}-get
**Pattern**: `cpu-get`, `ram-get`, etc.

**Purpose**: Get single component details

**Parameters**:
- `id` (required): Component ID

**Response**:
```json
{
  "success": true,
  "data": {
    "component": {
      "ID": 1,
      "UUID": "cpu-xeon-gold-6248r-001",
      "SerialNumber": "SN123456",
      "Status": 1,
      "Location": "Rack-A-Shelf-1",
      "Notes": "Intel Xeon Gold 6248R",
      "CreatedAt": "2025-11-05 10:00:00"
    }
  }
}
```

**Required Permission**: `{component}.view`

---

### {component}-update
**Pattern**: `cpu-update`, `ram-update`, etc.

**Purpose**: Update component details

**Parameters**:
- `id` (required): Component ID
- `Status` (optional): New status
- `Location` (optional): New location
- `Notes` (optional): Updated notes
- `SerialNumber` (optional): Updated serial number

**Response**:
```json
{
  "success": true,
  "message": "Component updated successfully"
}
```

**Required Permission**: `{component}.edit`

---

### {component}-delete
**Pattern**: `cpu-delete`, `ram-delete`, etc.

**Purpose**: Delete component from inventory

**Parameters**:
- `id` (required): Component ID

**Response**:
```json
{
  "success": true,
  "message": "Component deleted successfully"
}
```

**Required Permission**: `{component}.delete`

**Note**: Cannot delete if component Status = 2 (in_use)

---

### {component}-bulk_update
**Pattern**: `cpu-bulk_update`, `ram-bulk_update`, etc.

**Purpose**: Update multiple components at once

**Parameters**:
- `ids` (required): Array of component IDs
- `Status` (optional): New status
- `Location` (optional): New location
- `Notes` (optional): Updated notes

**Response**:
```json
{
  "success": true,
  "message": "Components updated successfully",
  "data": {
    "updated_count": 5
  }
}
```

**Required Permission**: `{component}.edit`

---

### {component}-bulk_delete
**Pattern**: `cpu-bulk_delete`, `ram-bulk_delete`, etc.

**Purpose**: Delete multiple components at once

**Parameters**:
- `ids` (required): Array of component IDs

**Response**:
```json
{
  "success": true,
  "message": "Components deleted successfully",
  "data": {
    "deleted_count": 3
  }
}
```

**Required Permission**: `{component}.delete`

**Note**: Cannot delete components with Status = 2 (in_use)

---

## ACL & Permissions

### acl-get_user_permissions
**Purpose**: Get all permissions for a user

**Parameters**:
- `user_id` (required): User ID

**Response**:
```json
{
  "success": true,
  "data": {
    "user_id": 5,
    "permissions": ["server.create", "cpu.view", "ram.view"],
    "roles": ["operator", "viewer"]
  }
}
```

**Required Permission**: `acl.manage`

---

### acl-assign_permission
**Purpose**: Grant permission to user

**Parameters**:
- `user_id` (required): User ID
- `permission` (required): Permission string (e.g., "server.create")

**Response**:
```json
{
  "success": true,
  "message": "Permission assigned successfully"
}
```

**Required Permission**: `acl.manage`

---

### acl-revoke_permission
**Purpose**: Remove permission from user

**Parameters**:
- `user_id` (required): User ID
- `permission` (required): Permission string

**Response**:
```json
{
  "success": true,
  "message": "Permission revoked successfully"
}
```

**Required Permission**: `acl.manage`

---

### acl-assign_role
**Purpose**: Assign role to user

**Parameters**:
- `user_id` (required): User ID
- `role_id` (required): Role ID

**Response**:
```json
{
  "success": true,
  "message": "Role assigned successfully"
}
```

**Required Permission**: `acl.manage`

---

### roles-list
**Purpose**: List all roles

**Parameters**: None

**Response**:
```json
{
  "success": true,
  "data": {
    "roles": [
      {
        "id": 1,
        "name": "admin",
        "description": "Full system access"
      },
      {
        "id": 2,
        "name": "operator",
        "description": "Server configuration access"
      }
    ]
  }
}
```

**Required Permission**: `role.view`

---

### permissions-list
**Purpose**: List all permissions

**Parameters**: None

**Response**:
```json
{
  "success": true,
  "data": {
    "permissions": [
      {
        "id": 1,
        "name": "server.create",
        "description": "Create server configurations"
      }
    ]
  }
}
```

**Required Permission**: `permission.view`

---

## User Management

### users-list
**Purpose**: List all users

**Parameters**:
- `limit` (optional): Results limit
- `offset` (optional): Pagination offset

**Response**:
```json
{
  "success": true,
  "data": {
    "users": [
      {
        "id": 1,
        "username": "admin",
        "email": "admin@example.com",
        "firstname": "Admin",
        "lastname": "User",
        "status": 1
      }
    ],
    "total_count": 25
  }
}
```

**Required Permission**: `user.view`

---

### users-create
**Purpose**: Create new user (admin function)

**Parameters**:
- `username` (required): Unique username
- `email` (required): Valid email
- `password` (required): Password
- `firstname` (optional): First name
- `lastname` (optional): Last name

**Response**:
```json
{
  "success": true,
  "data": {
    "user_id": 10
  }
}
```

**Required Permission**: `user.create`

---

### users-update
**Purpose**: Update user details

**Parameters**:
- `user_id` (required): User ID
- `username` (optional): New username
- `email` (optional): New email
- `firstname` (optional): New first name
- `lastname` (optional): New last name
- `status` (optional): New status

**Response**:
```json
{
  "success": true,
  "message": "User updated successfully"
}
```

**Required Permission**: `user.edit`

---

### users-delete
**Purpose**: Delete user account

**Parameters**:
- `user_id` (required): User ID to delete

**Response**:
```json
{
  "success": true,
  "message": "User deleted successfully"
}
```

**Required Permission**: `user.delete`

**Note**: Cannot delete own account

---

### users-get
**Purpose**: Get specific user details

**Parameters**:
- `user_id` (required): User ID

**Response**:
```json
{
  "success": true,
  "data": {
    "user": {
      "id": 5,
      "username": "operator1",
      "email": "operator1@example.com",
      "firstname": "John",
      "lastname": "Doe",
      "status": 1,
      "created_at": "2025-11-05 10:00:00"
    }
  }
}
```

**Required Permission**: `user.view`

---

## Dashboard & Search

### dashboard-get_statistics
**Purpose**: Get system-wide statistics

**Parameters**: None

**Response**:
```json
{
  "success": true,
  "data": {
    "total_servers": 45,
    "total_components": 523,
    "component_breakdown": {
      "cpu": 120,
      "ram": 240,
      "storage": 90
    },
    "recent_configurations": []
  }
}
```

**Required Permission**: `dashboard.view`

---

### search-global
**Purpose**: Search across all components and configurations

**Parameters**:
- `query` (required): Search term
- `type` (optional): Filter by component type

**Response**:
```json
{
  "success": true,
  "data": {
    "results": [
      {
        "type": "cpu",
        "uuid": "cpu-xeon-gold-6248r",
        "match_field": "model",
        "match_value": "Intel Xeon Gold 6248R"
      }
    ],
    "total_results": 15
  }
}
```

**Required Permission**: `search.use`

---

## Error Response Format

All errors return:
```json
{
  "success": false,
  "authenticated": true|false,
  "code": 400|401|403|404|500,
  "message": "Error description",
  "timestamp": "2025-11-05 12:34:56"
}
```

### Common Error Codes

- `400`: Bad Request - Invalid parameters
- `401`: Unauthorized - Missing or invalid JWT token
- `403`: Forbidden - Insufficient permissions
- `404`: Not Found - Resource doesn't exist
- `500`: Internal Server Error - Server-side error

---

## Usage Example

```bash
# 1. Login
TOKEN=$(curl -s -X POST http://localhost:8000/api/api.php \
  -d "action=auth-login" \
  -d "username=admin" \
  -d "password=admin123" | jq -r '.data.tokens.access_token')

# 2. List CPUs
curl -X POST http://localhost:8000/api/api.php \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=cpu-list"

# 3. Add CPU
curl -X POST http://localhost:8000/api/api.php \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=cpu-add" \
  -d "UUID=cpu-xeon-gold-6248r-001" \
  -d "SerialNumber=SN123456" \
  -d "Status=1"

# 4. Initialize server config
curl -X POST http://localhost:8000/api/api.php \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=server-initialize" \
  -d "chassis_uuid=chassis-dell-r740-001" \
  -d "config_name=Test_Server_01"
```

---

## Testing & Quick Reference

### Staging Server
```
https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php
```

### Quick Test Commands

#### 1. Login & Get Token
```bash
TOKEN=$(curl -s -X POST https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php \
  -d "action=auth-login" \
  -d "username=admin" \
  -d "password=admin123" | jq -r '.data.tokens.access_token')

echo "Token: $TOKEN"
```

#### 2. Verify Token (Quick Health Check)
```bash
curl -X POST https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=auth-verify_token"
```

#### 3. List Components (Test Data Access)
```bash
# List CPUs
curl -X POST https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=cpu-list" | jq

# List RAM
curl -X POST https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=ram-list" | jq

# List Storage
curl -X POST https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=storage-list" | jq
```

#### 4. Test Server Configuration Workflow
```bash
# Step 1: Initialize server config
CONFIG=$(curl -s -X POST https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=server-initialize" \
  -d "chassis_uuid=YOUR_CHASSIS_UUID" \
  -d "config_name=Test_Config_$(date +%s)" | jq -r '.data.configuration_uuid')

echo "Created config: $CONFIG"

# Step 2: Add component
curl -X POST https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=server-add-component" \
  -d "config_uuid=$CONFIG" \
  -d "component_type=cpu" \
  -d "component_uuid=YOUR_CPU_UUID" | jq

# Step 3: Validate config
curl -X POST https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=server-validate-config" \
  -d "config_uuid=$CONFIG" | jq
```

#### 5. Test Compatibility Checks
```bash
curl -X POST https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=compatibility-check" \
  -d "component_type_1=cpu" \
  -d "component_uuid_1=YOUR_CPU_UUID" \
  -d "component_type_2=motherboard" \
  -d "component_uuid_2=YOUR_MB_UUID" | jq
```

### Testing Workflow (Every 10 Seconds Push)

1. **Make code changes locally**
2. **Push to server** (auto-deploys)
3. **Get fresh token**:
   ```bash
   TOKEN=$(curl -s -X POST https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php \
     -d "action=auth-login" \
     -d "username=admin" \
     -d "password=admin123" | jq -r '.data.tokens.access_token')
   ```
4. **Test specific endpoint** that you changed
5. **Check response** for errors/changes

### Common Test Scenarios

#### Scenario A: Test Component Addition
```bash
curl -X POST https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=cpu-add" \
  -d "UUID=cpu-test-uuid-123" \
  -d "SerialNumber=SN-TEST-001" \
  -d "Status=1" | jq
```

#### Scenario B: Test Authentication
```bash
curl -X POST https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php \
  -d "action=auth-login" \
  -d "username=admin" \
  -d "password=admin123" | jq
```

#### Scenario C: Test Missing Authentication (Should Fail)
```bash
curl -X POST https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php \
  -d "action=cpu-list"
# Should return 401 Unauthorized
```

### Quick Bash Aliases (Add to Shell Profile)

```bash
alias test-api='curl -X POST https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php'
alias api-login='curl -s -X POST https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php \
  -d "action=auth-login" \
  -d "username=admin" \
  -d "password=admin123" | jq'
```

Then use:
```bash
api-login
# Get token easily
```
