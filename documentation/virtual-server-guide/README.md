# Virtual Server Configuration - Complete API Guide

## Overview

Virtual server configurations allow you to design and test server builds **without using actual inventory**. This is useful for:
- Planning server configurations before purchasing hardware
- Testing compatibility rules with different component combinations
- Creating templates that can be imported multiple times
- Training and demonstration purposes

## Key Differences: Virtual vs Real Servers

| Feature | Virtual Server | Real Server |
|---------|---------------|-------------|
| Component availability check | Skipped | Required |
| Component locking (Status→2) | Skipped | Applied |
| JSON spec validation | Skipped | Required |
| Duplicate component UUIDs | Allowed | Blocked |
| Can be finalized | No | Yes |
| Can be imported | Yes (source) | No |

---

## API Endpoints

### 1. Create Virtual Server Configuration

**Endpoint:** `server-create-start`

**Request:**
```bash
curl -X POST "https://your-server/api/api.php" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=server-create-start" \
  -d "server_name=My Test Server" \
  -d "description=Testing dual-CPU configuration" \
  -d "location=Virtual Lab" \
  -d "rack_position=N/A" \
  -d "notes=Template for production servers" \
  -d "is_virtual=1"
```

**Response:**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Server configuration started successfully",
  "timestamp": "2025-11-21 18:00:00",
  "code": 200,
  "data": {
    "config_uuid": "abc12345-6789-4def-ghij-klmnopqrstuv",
    "server_name": "My Test Server",
    "is_virtual": true,
    "message": "Virtual server configuration created - inventory checks bypassed"
  }
}
```

---

### 2. Add Components to Virtual Server

**Endpoint:** `server-add-component`

Components can be added using **any UUID** - they don't need to exist in inventory or JSON specs.

**Request - Add First CPU:**
```bash
curl -X POST "https://your-server/api/api.php" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=server-add-component" \
  -d "config_uuid=abc12345-6789-4def-ghij-klmnopqrstuv" \
  -d "component_type=cpu" \
  -d "component_uuid=d3b5f1c2-9f4e-4c2a-8e6b-7a9f3e2d1c4b" \
  -d "quantity=1" \
  -d "slot_position=CPU_1"
```

**Response:**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Component added successfully",
  "timestamp": "2025-11-21 18:01:00",
  "code": 200,
  "data": {
    "component_added": {
      "type": "cpu",
      "uuid": "d3b5f1c2-9f4e-4c2a-8e6b-7a9f3e2d1c4b",
      "quantity": 1,
      "slot_position": "CPU_1",
      "is_virtual": true
    }
  }
}
```

**Request - Add Second CPU (Same UUID):**
```bash
curl -X POST "https://your-server/api/api.php" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=server-add-component" \
  -d "config_uuid=abc12345-6789-4def-ghij-klmnopqrstuv" \
  -d "component_type=cpu" \
  -d "component_uuid=d3b5f1c2-9f4e-4c2a-8e6b-7a9f3e2d1c4b" \
  -d "quantity=1" \
  -d "slot_position=CPU_2"
```

**Note:** Virtual servers allow adding the same component UUID multiple times. Each instance gets a unique virtual serial number like `VIRTUAL-CPU-2-1732215999`.

---

### 3. Add Other Components

**Add Motherboard:**
```bash
curl -X POST "https://your-server/api/api.php" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=server-add-component" \
  -d "config_uuid=abc12345-6789-4def-ghij-klmnopqrstuv" \
  -d "component_type=motherboard" \
  -d "component_uuid=mb-uuid-here" \
  -d "quantity=1"
```

**Add RAM (Multiple Sticks):**
```bash
# Add 4 RAM sticks of same model
for i in 1 2 3 4; do
  curl -X POST "https://your-server/api/api.php" \
    -H "Authorization: Bearer YOUR_JWT_TOKEN" \
    -d "action=server-add-component" \
    -d "config_uuid=abc12345-6789-4def-ghij-klmnopqrstuv" \
    -d "component_type=ram" \
    -d "component_uuid=ram-uuid-here" \
    -d "quantity=1"
done
```

**Add Chassis:**
```bash
curl -X POST "https://your-server/api/api.php" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=server-add-component" \
  -d "config_uuid=abc12345-6789-4def-ghij-klmnopqrstuv" \
  -d "component_type=chassis" \
  -d "component_uuid=chassis-uuid-here" \
  -d "quantity=1"
```

**Add Storage:**
```bash
curl -X POST "https://your-server/api/api.php" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=server-add-component" \
  -d "config_uuid=abc12345-6789-4def-ghij-klmnopqrstuv" \
  -d "component_type=storage" \
  -d "component_uuid=storage-uuid-here" \
  -d "quantity=1"
```

---

### 4. Get Virtual Server Configuration

**Endpoint:** `server-get-config`

**Request:**
```bash
curl -X GET "https://your-server/api/api.php?action=server-get-config&config_uuid=abc12345-6789-4def-ghij-klmnopqrstuv" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

**Response:**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Configuration retrieved successfully",
  "timestamp": "2025-11-21 18:05:00",
  "code": 200,
  "data": {
    "configuration": {
      "config_uuid": "abc12345-6789-4def-ghij-klmnopqrstuv",
      "server_name": "My Test Server",
      "description": "Testing dual-CPU configuration",
      "is_virtual": true,
      "configuration_status": 0,
      "components": {
        "cpu": [
          {
            "uuid": "d3b5f1c2-9f4e-4c2a-8e6b-7a9f3e2d1c4b",
            "serial_number": "VIRTUAL-CPU-1-1732215660",
            "quantity": 1,
            "added_at": "2025-11-21 18:01:00"
          },
          {
            "uuid": "d3b5f1c2-9f4e-4c2a-8e6b-7a9f3e2d1c4b",
            "serial_number": "VIRTUAL-CPU-2-1732215720",
            "quantity": 1,
            "added_at": "2025-11-21 18:02:00"
          }
        ],
        "motherboard": [...],
        "ram": [...],
        "chassis": [...]
      }
    },
    "summary": {
      "total_components": 8,
      "component_counts": {
        "cpu": 2,
        "motherboard": 1,
        "ram": 4,
        "chassis": 1
      }
    }
  }
}
```

---

### 5. List Virtual Server Configurations

**Endpoint:** `server-list`

**Request - List Only Virtual Servers:**
```bash
curl -X GET "https://your-server/api/api.php?action=server-list&include_virtual=true" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

**Request - List Only Real Servers:**
```bash
curl -X GET "https://your-server/api/api.php?action=server-list&include_virtual=false" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

**Request - List All Servers:**
```bash
curl -X GET "https://your-server/api/api.php?action=server-list&include_virtual=all" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

**Response:**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Configurations retrieved successfully",
  "code": 200,
  "data": {
    "configurations": [
      {
        "config_uuid": "abc12345-...",
        "server_name": "My Test Server",
        "is_virtual": true,
        "configuration_status": 0,
        "created_at": "2025-11-21 18:00:00"
      },
      {
        "config_uuid": "def67890-...",
        "server_name": "Production Server 1",
        "is_virtual": false,
        "configuration_status": 3,
        "created_at": "2025-11-20 10:00:00"
      }
    ],
    "total": 2
  }
}
```

---

### 6. Get Compatible Components for Virtual Server

**Endpoint:** `server-get-compatible`

For virtual servers, this returns **ALL components** regardless of availability status.

**Request:**
```bash
curl -X GET "https://your-server/api/api.php?action=server-get-compatible&config_uuid=abc12345-6789-4def-ghij-klmnopqrstuv&component_type=cpu" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

**Response:**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Compatible components retrieved",
  "code": 200,
  "data": {
    "compatible_components": [
      {
        "uuid": "cpu-1-uuid",
        "name": "Intel Xeon Gold 6248",
        "status": 1,
        "available": true
      },
      {
        "uuid": "cpu-2-uuid",
        "name": "Intel Xeon Gold 6248",
        "status": 2,
        "available": false,
        "note": "Currently in use - shown for virtual config"
      }
    ],
    "is_virtual_config": true,
    "note": "All components shown regardless of availability for virtual configuration"
  }
}
```

---

### 7. Import Virtual Server to Real Server

**Endpoint:** `server-import-virtual`

This creates a new **real server configuration** using available physical components from inventory.

**Request:**
```bash
curl -X POST "https://your-server/api/api.php" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=server-import-virtual" \
  -d "virtual_config_uuid=abc12345-6789-4def-ghij-klmnopqrstuv" \
  -d "server_name=Production Server from Template" \
  -d "description=Created from virtual template" \
  -d "location=Datacenter A" \
  -d "rack_position=Rack 5, Unit 10"
```

**Response - Successful Import:**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Virtual configuration imported successfully with 8 components",
  "timestamp": "2025-11-21 19:00:00",
  "code": 200,
  "data": {
    "real_config_uuid": "new-real-config-uuid-here",
    "server_name": "Production Server from Template",
    "source_virtual_uuid": "abc12345-6789-4def-ghij-klmnopqrstuv",
    "imported_components": [
      {
        "component_type": "cpu",
        "uuid": "d3b5f1c2-9f4e-4c2a-8e6b-7a9f3e2d1c4b",
        "serial_number": "CPU000001",
        "status": "imported"
      },
      {
        "component_type": "cpu",
        "uuid": "d3b5f1c2-9f4e-4c2a-8e6b-7a9f3e2d1c4b",
        "serial_number": "CPU000002",
        "status": "imported"
      },
      {
        "component_type": "motherboard",
        "uuid": "mb-uuid-here",
        "serial_number": "MB000001",
        "status": "imported"
      }
    ],
    "warnings": [],
    "summary": {
      "total_components": 8,
      "imported": 8,
      "missing": 0
    }
  }
}
```

**Response - Partial Import (Some Components Unavailable):**
```json
{
  "success": true,
  "authenticated": true,
  "message": "Virtual configuration imported with warnings - 6 of 8 components imported",
  "timestamp": "2025-11-21 19:00:00",
  "code": 200,
  "data": {
    "real_config_uuid": "new-real-config-uuid-here",
    "imported_components": [
      {
        "component_type": "cpu",
        "uuid": "d3b5f1c2-...",
        "serial_number": "CPU000001",
        "status": "imported"
      }
    ],
    "warnings": [
      {
        "component_type": "cpu",
        "uuid": "d3b5f1c2-...",
        "reason": "not_available",
        "message": "No available component found with this UUID"
      },
      {
        "component_type": "ram",
        "uuid": "ram-uuid-...",
        "reason": "not_available",
        "message": "No available component found with this UUID"
      }
    ],
    "summary": {
      "total_components": 8,
      "imported": 6,
      "missing": 2
    }
  }
}
```

---

## Complete Workflow Example

### Step 1: Create Virtual Server
```bash
# Create virtual configuration
curl -X POST "https://your-server/api/api.php" \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=server-create-start" \
  -d "server_name=Dual-CPU Template" \
  -d "is_virtual=1"

# Save the config_uuid from response
VIRTUAL_UUID="abc12345-..."
```

### Step 2: Build Virtual Configuration
```bash
# Add chassis
curl -X POST "https://your-server/api/api.php" \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=server-add-component" \
  -d "config_uuid=$VIRTUAL_UUID" \
  -d "component_type=chassis" \
  -d "component_uuid=chassis-uuid"

# Add motherboard
curl -X POST "https://your-server/api/api.php" \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=server-add-component" \
  -d "config_uuid=$VIRTUAL_UUID" \
  -d "component_type=motherboard" \
  -d "component_uuid=mb-uuid"

# Add 2 CPUs (same model)
curl -X POST "https://your-server/api/api.php" \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=server-add-component" \
  -d "config_uuid=$VIRTUAL_UUID" \
  -d "component_type=cpu" \
  -d "component_uuid=cpu-uuid" \
  -d "slot_position=CPU_1"

curl -X POST "https://your-server/api/api.php" \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=server-add-component" \
  -d "config_uuid=$VIRTUAL_UUID" \
  -d "component_type=cpu" \
  -d "component_uuid=cpu-uuid" \
  -d "slot_position=CPU_2"

# Add 8 RAM sticks
for i in {1..8}; do
  curl -X POST "https://your-server/api/api.php" \
    -H "Authorization: Bearer $TOKEN" \
    -d "action=server-add-component" \
    -d "config_uuid=$VIRTUAL_UUID" \
    -d "component_type=ram" \
    -d "component_uuid=ram-uuid"
done
```

### Step 3: Review Virtual Configuration
```bash
curl -X GET "https://your-server/api/api.php?action=server-get-config&config_uuid=$VIRTUAL_UUID" \
  -H "Authorization: Bearer $TOKEN"
```

### Step 4: Import to Real Server (Can Be Done Multiple Times)
```bash
# First import
curl -X POST "https://your-server/api/api.php" \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=server-import-virtual" \
  -d "virtual_config_uuid=$VIRTUAL_UUID" \
  -d "server_name=Production Server 1" \
  -d "location=DC-A"

# Second import (creates another real server)
curl -X POST "https://your-server/api/api.php" \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=server-import-virtual" \
  -d "virtual_config_uuid=$VIRTUAL_UUID" \
  -d "server_name=Production Server 2" \
  -d "location=DC-B"
```

---

## Important Notes

### Virtual Serial Numbers
- Virtual components get auto-generated serial numbers: `VIRTUAL-CPU-1-1732215999`
- These are **discarded during import**
- Real servers use actual serial numbers from physical inventory

### Compatibility Validation
- Virtual servers still run compatibility checks (socket types, slot limits, etc.)
- Only inventory availability and JSON spec validation are skipped

### Cannot Finalize Virtual Servers
- Attempting to finalize returns: `"Cannot finalize virtual/test configurations. Use server-import-virtual to convert to a real configuration first."`

### Virtual Config Remains Unchanged
- The source virtual configuration is never modified during import
- Can be imported multiple times to create multiple real servers

### Component Availability During Import
- Import finds physical components with matching UUIDs
- If multiple physical units exist (same UUID, different serial), picks first available
- Components are locked (Status → 2) in the real config
- Warnings returned for any components that couldn't be found

---

## Error Handling

### Common Errors

**Virtual config not found:**
```json
{
  "success": false,
  "code": 404,
  "message": "Virtual configuration not found"
}
```

**Trying to import a real config:**
```json
{
  "success": false,
  "code": 400,
  "message": "Configuration is not a virtual configuration"
}
```

**Trying to finalize a virtual config:**
```json
{
  "success": false,
  "code": 400,
  "message": "Cannot finalize virtual/test configurations. Use server-import-virtual to convert to a real configuration first."
}
```

**No available components during import:**
```json
{
  "success": true,
  "code": 200,
  "message": "Virtual configuration imported with warnings - 0 of 8 components imported",
  "data": {
    "warnings": [
      {"component_type": "cpu", "reason": "not_available"},
      {"component_type": "cpu", "reason": "not_available"},
      ...
    ],
    "summary": {
      "imported": 0,
      "missing": 8
    }
  }
}
```
