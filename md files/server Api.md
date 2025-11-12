# Server and Server Configuration API Documentation

## Base Configuration
- **Base URL**: `https://shubham.staging.cloudmate.in/bdc_ims/api/api.php`
- **Authentication**: Bearer Token (JWT) required in header
- **Content-Type**: `application/x-www-form-urlencoded` or `application/json`

---

## Server Configuration Creation APIs

### 1. Start Server Configuration Creation
**Method:** `POST`  
**URL:** `/api/api.php`  
**Permission:** `server.create`  
**Action:** `server-create-start`

**Request Body:**
```
action: server-create-start
server_name: Production Server 01
description: High-performance production server (optional)
category: custom (optional, default: "custom")
```

**Response (200):**
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

---

### 2. Add Component to Configuration
**Method:** `POST`  
**URL:** `/api/api.php`  
**Permission:** `server.create`  
**Action:** `server-add-component`

**Request Body:**
```
action: server-add-component
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
component_type: cpu
component_uuid: cpu-uuid-123
quantity: 2 (optional, default: 1)
```

**Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Component added successfully",
    "data": {
        "config_uuid": "d95e2554-8eb6-4a3c-90d7-45881af2a9d3",
        "component_added": {
            "type": "cpu",
            "uuid": "cpu-uuid-123",
            "quantity": 2
        },
        "compatibility_check": {
            "is_compatible": true,
            "warnings": [],
            "errors": []
        },
        "next_recommended": "ram",
        "progress": {
            "total_steps": 6,
            "completed_steps": 1,
            "current_step": "component_selection",
            "components_added": ["cpu"]
        }
    }
}
```

---

### 3. Remove Component from Configuration
**Method:** `POST`  
**URL:** `/api/api.php`  
**Permission:** `server.edit`  
**Action:** `server-remove-component`

**Request Body:**
```
action: server-remove-component
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
component_type: ram
component_uuid: ram-uuid-456 (optional - removes specific component)
```

**Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Component removed successfully",
    "data": {
        "config_uuid": "d95e2554-8eb6-4a3c-90d7-45881af2a9d3",
        "removed_component": {
            "type": "ram",
            "uuid": "ram-uuid-456"
        },
        "updated_configuration": {
            // Updated configuration details
        }
    }
}
```

---

## Server Configuration Management APIs

### 4. Get Configuration Details
**Method:** `GET` or `POST`  
**URL:** `/api/api.php`  
**Permission:** `server.view` (owner) or `server.view_all` (admin)  
**Action:** `server-get-config`

**Request Parameters:**
```
action: server-get-config
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
```

**Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Configuration retrieved successfully",
    "data": {
        "configuration": {
            "config_uuid": "d95e2554-8eb6-4a3c-90d7-45881af2a9d3",
            "server_name": "Production Server 01",
            "description": "High-performance production server",
            "category": "custom",
            "configuration_status": 1,
            "power_consumption": 850,
            "compatibility_score": 95,
            "ram_configuration": [],
            "storage_configuration": [],
            "nic_configuration": [],
            "caddy_configuration": [],
            "created_at": "2025-01-15 10:00:00",
            "updated_at": "2025-01-15 14:30:00"
        },
        "components": {
            "cpu": [/* CPU component details */],
            "motherboard": [/* Motherboard details */],
            "ram": [/* RAM modules */],
            "storage": [/* Storage devices */],
            "nic": [/* Network cards */],
            "caddy": [/* Caddy components */]
        },
        "component_counts": {
            "cpu": 2,
            "motherboard": 1,
            "ram": 4,
            "storage": 2,
            "nic": 2,
            "caddy": 1
        },
        "total_components": 12,
        "power_consumption": {
            "total_with_overhead_watts": 850
        },
        "configuration_status_text": "Validated"
    }
}
```

---

### 5. List Server Configurations
**Method:** `GET`  
**URL:** `/api/api.php`  
**Permission:** `server.view` (own configs) or `server.view_all` (all configs)  
**Action:** `server-list-configs`

**Request Parameters:**
```
action: server-list-configs
limit: 20 (optional, default: 20)
offset: 0 (optional, default: 0)
status: 1 (optional - filter by configuration status)
```

**Response (200):**
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
                "configuration_status_text": "Validated",
                "created_by": 1,
                "created_by_username": "admin",
                "created_at": "2025-01-15 10:00:00",
                "updated_at": "2025-01-15 14:30:00"
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

---

### 6. Finalize Configuration
**Method:** `POST`  
**URL:** `/api/api.php`  
**Permission:** `server.finalize` (owner) or admin permissions  
**Action:** `server-finalize-config`

**Request Body:**
```
action: server-finalize-config
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
notes: Final deployment notes (optional)
```

**Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Configuration finalized successfully",
    "data": {
        "config_uuid": "d95e2554-8eb6-4a3c-90d7-45881af2a9d3",
        "server_uuid": "srv-uuid-789",
        "configuration_status": 2,
        "configuration_status_text": "Built"
    }
}
```

---

### 7. Delete Configuration
**Method:** `POST`  
**URL:** `/api/api.php`  
**Permission:** `server.delete`  
**Action:** `server-delete-config`

**Request Body:**
```
action: server-delete-config
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
```

**Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Configuration deleted successfully"
}
```

---

## Server Creation Step-by-Step APIs

### 8. Initialize Server Creation
**Method:** `POST`  
**URL:** `/api/api.php`  
**Permission:** `server.create`  
**Action:** `server-initialize`

**Request Body:**
```
action: server-initialize
server_name: New Server
description: Server description
start_with: any (options: 'cpu', 'motherboard', 'any')
```

**Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Server creation initialized",
    "data": {
        "config_uuid": "new-config-uuid",
        "server_name": "New Server",
        "starting_options": {
            "cpu": [/* Available CPUs */],
            "motherboard": [/* Available Motherboards */],
            "ram": [/* Available RAM */],
            "storage": [/* Available Storage */],
            "nic": [/* Available NICs */],
            "caddy": [/* Available Caddies */]
        },
        "workflow_step": 1,
        "next_recommended": "any",
        "progress": {
            "total_steps": 6,
            "completed_steps": 0,
            "current_step": "component_selection"
        }
    }
}
```

---

### 9. Get Next Options (Step-by-Step)
**Method:** `POST`  
**URL:** `/api/api.php`  
**Permission:** `server.view`  
**Action:** `server-get_next_options`

**Request Body:**
```
action: server-get_next_options
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
component_type: ram (optional - specific type)
```

**Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Next options retrieved",
    "data": {
        "component_type": "ram",
        "options": [/* Compatible components */],
        "count": 15,
        "current_configuration": {/* Current config */},
        "configuration_summary": {/* Summary */},
        "progress": {/* Progress details */}
    }
}
```

---

### 10. Validate Current Configuration
**Method:** `POST`  
**URL:** `/api/api.php`  
**Permission:** `server.view`  
**Action:** `server-validate_current`

**Request Body:**
```
action: server-validate_current
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
```

**Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Configuration validation completed",
    "data": {
        "configuration": {/* Current configuration */},
        "configuration_summary": {/* Summary */},
        "validation": {
            "is_complete": true,
            "missing_components": [],
            "errors": [],
            "warnings": [],
            "critical_errors": []
        },
        "is_valid": true,
        "can_finalize": true
    }
}
```

---

### 11. Save Draft Configuration
**Method:** `POST`  
**URL:** `/api/api.php`  
**Permission:** `server.create`  
**Action:** `server-save_draft`

**Request Body:**
```
action: server-save_draft
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
draft_name: My Draft Config (optional)
```

**Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Draft saved successfully",
    "data": {
        "config_uuid": "d95e2554-8eb6-4a3c-90d7-45881af2a9d3",
        "draft_name": "My Draft Config",
        "saved_at": "2025-01-15 15:00:00"
    }
}
```

---

### 12. Load Draft Configuration
**Method:** `POST`  
**URL:** `/api/api.php`  
**Permission:** `server.view`  
**Action:** `server-load_draft`

**Request Body:**
```
action: server-load_draft
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
```

**Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Draft loaded successfully",
    "data": {
        "config_uuid": "d95e2554-8eb6-4a3c-90d7-45881af2a9d3",
        "configuration": {/* Configuration details */},
        "progress": {/* Progress details */}
    }
}
```

---

### 13. Get Server Progress
**Method:** `POST`  
**URL:** `/api/api.php`  
**Permission:** `server.view`  
**Action:** `server-get_server_progress`

**Request Body:**
```
action: server-get_server_progress
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
```

**Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Server progress retrieved",
    "data": {
        "config_uuid": "d95e2554-8eb6-4a3c-90d7-45881af2a9d3",
        "name": "Production Server 01",
        "status": "draft",
        "progress": {
            "total_steps": 6,
            "completed_steps": 3,
            "percentage": 50,
            "current_step": "ram_selection"
        },
        "next_step": "storage",
        "configuration_summary": {/* Summary */}
    }
}
```

---

### 14. Reset Configuration
**Method:** `POST`  
**URL:** `/api/api.php`  
**Permission:** `server.edit`  
**Action:** `server-reset_configuration`

**Request Body:**
```
action: server-reset_configuration
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
```

**Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Configuration reset successfully",
    "data": {
        "config_uuid": "d95e2554-8eb6-4a3c-90d7-45881af2a9d3",
        "configuration": {
            "components": []
        }
    }
}
```

---

### 15. List Draft Configurations
**Method:** `GET`  
**URL:** `/api/api.php`  
**Permission:** `server.view`  
**Action:** `server-list_drafts`

**Request Parameters:**
```
action: server-list_drafts
limit: 20 (optional, default: 20)
offset: 0 (optional, default: 0)
status: draft (options: 'draft', 'finalized', 'all')
```

**Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Draft configurations retrieved",
    "data": {
        "drafts": [
            {
                "config_uuid": "draft-uuid-123",
                "server_name": "Test Server",
                "status": "draft",
                "created_at": "2025-01-10 10:00:00",
                "updated_at": "2025-01-10 11:00:00",
                "component_count": 5
            }
        ],
        "pagination": {
            "total": 10,
            "limit": 20,
            "offset": 0,
            "has_more": false
        }
    }
}
```

---

### 16. Delete Draft Configuration
**Method:** `POST`  
**URL:** `/api/api.php`  
**Permission:** `server.delete`  
**Action:** `server-delete_draft`

**Request Body:**
```
action: server-delete_draft
config_uuid: draft-uuid-123
```

**Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Draft deleted successfully"
}
```

---

### 17. Get Compatible Components
**Method:** `POST`  
**URL:** `/api/api.php`  
**Permission:** `server.view`  
**Action:** `server-get-compatible`

**Request Body:**
```
action: server-get-compatible
config_uuid: d95e2554-8eb6-4a3c-90d7-45881af2a9d3
component_type: ram
```

**Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Compatible components retrieved",
    "data": {
        "component_type": "ram",
        "compatible_components": [
            {
                "uuid": "ram-uuid-123",
                "serial_number": "RAM12345",
                "specs": "32GB DDR4 3200MHz",
                "compatibility_score": 100
            }
        ],
        "total": 8
    }
}
```

---

### 18. Get Available Components
**Method:** `POST`  
**URL:** `/api/api.php`  
**Permission:** `server.view`  
**Action:** `server-get-available-components`

**Request Body:**
```
action: server-get-available-components
component_type: cpu (optional - specific type or 'all')
```

**Response (200):**
```json
{
    "success": true,
    "authenticated": true,
    "message": "Available components retrieved",
    "data": {
        "components": {
            "cpu": [/* Available CPUs */],
            "motherboard": [/* Available Motherboards */],
            "ram": [/* Available RAM */],
            "storage": [/* Available Storage */],
            "nic": [/* Available NICs */],
            "caddy": [/* Available Caddies */]
        },
        "counts": {
            "cpu": 15,
            "motherboard": 8,
            "ram": 32,
            "storage": 20,
            "nic": 12,
            "caddy": 25
        }
    }
}
```

---

## Configuration Status Codes

| Status Code | Text | Description |
|------------|------|-------------|
| 0 | Draft | Configuration in progress, not finalized |
| 1 | Validated | Configuration validated and ready |
| 2 | Built | Server physically built |
| 3 | Deployed | Server deployed and operational |

---

## Common Error Responses

### 400 Bad Request
```json
{
    "success": false,
    "authenticated": true,
    "message": "Server name is required",
    "code": 400
}
```

### 401 Unauthorized
```json
{
    "success": false,
    "authenticated": false,
    "message": "Authentication required",
    "code": 401
}
```

### 403 Forbidden
```json
{
    "success": false,
    "authenticated": true,
    "message": "Insufficient permissions: server.create required",
    "code": 403
}
```

### 404 Not Found
```json
{
    "success": false,
    "authenticated": true,
    "message": "Server configuration not found",
    "code": 404
}
```

### 500 Internal Server Error
```json
{
    "success": false,
    "authenticated": true,
    "message": "Failed to create configuration: Database error",
    "code": 500
}
```

---

## Required Permissions

| Action | Required Permission |
|--------|-------------------|
| Create configuration | `server.create` |
| View own configurations | `server.view` |
| View all configurations | `server.view_all` |
| Edit configuration | `server.edit` |
| Delete configuration | `server.delete` |
| Finalize configuration | `server.finalize` |
| View statistics | `server.view_statistics` |

---

## Notes

1. **Authentication**: All endpoints require JWT Bearer token in the Authorization header
2. **Configuration UUID**: Generated automatically when creating a new configuration
3. **Component Compatibility**: The system automatically checks component compatibility when adding components
4. **Power Consumption**: Calculated automatically based on added components
5. **Status Progression**: Configurations move from Draft → Validated → Built → Deployed
6. **Permissions**: Users can only view/edit their own configurations unless they have admin permissions
7. **Validation**: Configuration must pass validation before it can be finalized