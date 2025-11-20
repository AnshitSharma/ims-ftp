# Tickets API Reference

Complete API documentation for the BDC IMS Ticketing System with all request/response specifications.

---

## 📋 Table of Contents

1. [Overview](#overview)
2. [Authentication & Permissions](#authentication--permissions)
3. [API Endpoints](#api-endpoints)
   - [ticket-create](#1-ticket-create)
   - [ticket-list](#2-ticket-list)
   - [ticket-get](#3-ticket-get)
   - [ticket-update](#4-ticket-update)
   - [ticket-delete](#5-ticket-delete)
4. [Data Models](#data-models)
5. [Status Transitions](#status-transitions)
6. [Examples](#examples)

---

## Overview

**Base URL**: `https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php`

**Local Dev**: `http://localhost:8000/api/api.php`

**Format**: All requests use `application/x-www-form-urlencoded` or `multipart/form-data`

**Response Format**: JSON

---

## Authentication & Permissions

### JWT Authentication

All ticket endpoints require JWT authentication via the `Authorization` header:

```
Authorization: Bearer {access_token}
```

### Required Permissions

| Action | Permission | Description |
|--------|-----------|-------------|
| Create ticket | `ticket.create` | Create new tickets |
| View own tickets | `ticket.view_own` | View tickets created by you |
| View assigned tickets | `ticket.view_assigned` | View tickets assigned to you |
| View all tickets | `ticket.view_all` | View any ticket |
| Edit own tickets | `ticket.edit_own` | Edit your draft tickets |
| Assign tickets | `ticket.assign` | Assign tickets to users |
| Approve tickets | `ticket.approve` | Approve pending tickets (cannot approve own) |
| Reject tickets | `ticket.reject` | Reject pending tickets |
| Deploy tickets | `ticket.deploy` | Start work & deploy tickets |
| Complete tickets | `ticket.complete` | Mark deployed tickets as completed |
| Cancel tickets | `ticket.cancel` | Cancel any ticket |
| Delete tickets | `ticket.delete` | Soft delete tickets |
| Manage tickets | `ticket.manage` | Full admin access (bypasses all restrictions) |

---

## API Endpoints

### 1. ticket-create

**Create a new ticket with component items**

#### Request

**Method**: `POST`

**Permission**: `ticket.create`

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | ✅ | Must be `ticket-create` |
| `title` | string | ✅ | Ticket title (max 255 chars) |
| `description` | string | ✅ | Detailed description (min 10 chars) |
| `priority` | string | ❌ | Priority level: `low`, `medium`, `high`, `urgent` (default: `medium`) |
| `target_server_uuid` | string | ❌ | Target server configuration UUID (36 chars) |
| `items` | JSON string | ✅ | Array of component items (see Items Format below) |

**Items Format** (JSON array):

```json
[
  {
    "component_type": "cpu",
    "component_uuid": "550e8400-e29b-41d4-a716-446655440000",
    "quantity": 2,
    "action": "add"
  },
  {
    "component_type": "ram",
    "component_uuid": "660e8400-e29b-41d4-a716-446655440001",
    "quantity": 8,
    "action": "add"
  }
]
```

**Item Fields**:

| Field | Type | Required | Values |
|-------|------|----------|--------|
| `component_type` | string | ✅ | `cpu`, `ram`, `storage`, `motherboard`, `nic`, `caddy`, `chassis`, `pciecard`, `hbacard` |
| `component_uuid` | string | ✅ | Valid UUID from `All-JSON/{type}-jsons/*.json` files |
| `quantity` | integer | ✅ | Quantity requested (≥ 1) |
| `action` | string | ✅ | `add`, `remove`, `replace` |

#### Response

**Success (201 Created)**:

```json
{
  "success": true,
  "authenticated": true,
  "code": 201,
  "message": "Ticket created successfully",
  "timestamp": "2025-11-18 14:23:45",
  "data": {
    "ticket_id": 1,
    "ticket_number": "TKT-20251118-0001",
    "ticket": {
      "id": 1,
      "ticket_number": "TKT-20251118-0001",
      "title": "Add 2x Intel Xeon CPUs to Server",
      "description": "Need to upgrade server with dual Intel Xeon processors",
      "status": "draft",
      "priority": "high",
      "target_server_uuid": "550e8400-e29b-41d4-a716-446655440000",
      "created_by": 5,
      "created_by_username": "john_doe",
      "created_by_email": "john@example.com",
      "assigned_to": null,
      "assigned_to_username": null,
      "assigned_to_email": null,
      "rejection_reason": null,
      "deployment_notes": null,
      "completion_notes": null,
      "created_at": "2025-11-18 14:23:45",
      "updated_at": "2025-11-18 14:23:45",
      "submitted_at": null,
      "approved_at": null,
      "deployed_at": null,
      "completed_at": null,
      "items": [
        {
          "id": 1,
          "ticket_id": 1,
          "component_type": "cpu",
          "component_uuid": "550e8400-e29b-41d4-a716-446655440000",
          "quantity": 2,
          "action": "add",
          "component_name": "Intel Xeon E5-2690 v4",
          "component_specs": "{\"manufacturer\":\"Intel\",\"model\":\"E5-2690 v4\",...}",
          "is_validated": 1,
          "is_compatible": 1,
          "compatibility_notes": "Compatible with target motherboard",
          "created_at": "2025-11-18 14:23:45",
          "updated_at": "2025-11-18 14:23:45"
        }
      ]
    }
  }
}
```

**Error (400 Bad Request)**:

```json
{
  "success": false,
  "authenticated": true,
  "code": 400,
  "message": "Failed to create ticket",
  "timestamp": "2025-11-18 14:23:45",
  "data": {
    "errors": [
      "Title is required",
      "Item 0: Invalid component_uuid format",
      "Item 1: Component UUID not found in JSON files"
    ]
  }
}
```

---

### 2. ticket-list

**List tickets with filtering, pagination, and sorting**

#### Request

**Method**: `GET`

**Permission**: `ticket.view_own` OR `ticket.view_assigned` OR `ticket.view_all` OR `ticket.manage`

**Query Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | ✅ | Must be `ticket-list` |
| `status` | string | ❌ | Filter by status: `draft`, `pending`, `approved`, `in_progress`, `deployed`, `completed`, `rejected`, `cancelled` |
| `priority` | string | ❌ | Filter by priority: `low`, `medium`, `high`, `urgent` |
| `created_by` | integer | ❌ | Filter by creator user ID |
| `assigned_to` | integer | ❌ | Filter by assigned user ID |
| `search` | string | ❌ | Search in title, description, ticket_number |
| `created_from` | string | ❌ | Filter by created date (format: `YYYY-MM-DD`) |
| `created_to` | string | ❌ | Filter by created date (format: `YYYY-MM-DD`) |
| `page` | integer | ❌ | Page number (default: `1`) |
| `limit` | integer | ❌ | Items per page (default: `20`, max: `100`) |
| `order_by` | string | ❌ | Sort field: `created_at`, `updated_at`, `priority`, `status`, `ticket_number` (default: `created_at`) |
| `order_dir` | string | ❌ | Sort direction: `ASC`, `DESC` (default: `DESC`) |

**Permission Logic**:
- `ticket.view_all` or `ticket.manage`: Can see all tickets
- `ticket.view_own` only: Can only see tickets they created
- `ticket.view_assigned` only: Can only see tickets assigned to them
- Both `ticket.view_own` and `ticket.view_assigned`: Can see tickets they created OR are assigned to

#### Response

**Success (200 OK)**:

```json
{
  "success": true,
  "authenticated": true,
  "code": 200,
  "message": "Tickets retrieved successfully",
  "timestamp": "2025-11-18 14:30:00",
  "data": {
    "tickets": [
      {
        "id": 1,
        "ticket_number": "TKT-20251118-0001",
        "title": "Add 2x Intel Xeon CPUs to Server",
        "description": "Need to upgrade server with dual Intel Xeon processors",
        "status": "pending",
        "priority": "high",
        "target_server_uuid": "550e8400-e29b-41d4-a716-446655440000",
        "created_by": 5,
        "created_by_username": "john_doe",
        "created_by_email": "john@example.com",
        "assigned_to": 3,
        "assigned_to_username": "admin_user",
        "assigned_to_email": "admin@example.com",
        "created_at": "2025-11-18 14:23:45",
        "updated_at": "2025-11-18 14:25:30",
        "submitted_at": "2025-11-18 14:25:30",
        "approved_at": null,
        "deployed_at": null,
        "completed_at": null,
        "items": [
          {
            "id": 1,
            "component_type": "cpu",
            "component_uuid": "550e8400-e29b-41d4-a716-446655440000",
            "quantity": 2,
            "action": "add",
            "component_name": "Intel Xeon E5-2690 v4"
          }
        ]
      }
    ],
    "total": 50,
    "page": 1,
    "limit": 20,
    "total_pages": 3
  }
}
```

---

### 3. ticket-get

**Get single ticket with full details**

#### Request

**Method**: `GET`

**Permission**: Owner needs `ticket.view_own`, Assignee needs `ticket.view_assigned`, Others need `ticket.view_all` or `ticket.manage`

**Query Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | ✅ | Must be `ticket-get` |
| `ticket_id` | integer | ✅ | Ticket ID |
| `include_history` | string | ❌ | Include history: `true` or `false` (default: `false`) |

#### Response

**Success (200 OK)**:

```json
{
  "success": true,
  "authenticated": true,
  "code": 200,
  "message": "Ticket retrieved successfully",
  "timestamp": "2025-11-18 14:35:00",
  "data": {
    "ticket": {
      "id": 1,
      "ticket_number": "TKT-20251118-0001",
      "title": "Add 2x Intel Xeon CPUs to Server",
      "description": "Need to upgrade server with dual Intel Xeon processors",
      "status": "approved",
      "priority": "high",
      "target_server_uuid": "550e8400-e29b-41d4-a716-446655440000",
      "created_by": 5,
      "created_by_username": "john_doe",
      "created_by_email": "john@example.com",
      "assigned_to": 3,
      "assigned_to_username": "admin_user",
      "assigned_to_email": "admin@example.com",
      "rejection_reason": null,
      "deployment_notes": null,
      "completion_notes": null,
      "created_at": "2025-11-18 14:23:45",
      "updated_at": "2025-11-18 14:30:00",
      "submitted_at": "2025-11-18 14:25:30",
      "approved_at": "2025-11-18 14:30:00",
      "deployed_at": null,
      "completed_at": null,
      "items": [
        {
          "id": 1,
          "ticket_id": 1,
          "component_type": "cpu",
          "component_uuid": "550e8400-e29b-41d4-a716-446655440000",
          "quantity": 2,
          "action": "add",
          "component_name": "Intel Xeon E5-2690 v4",
          "component_specs": "{\"manufacturer\":\"Intel\",\"model\":\"E5-2690 v4\",\"cores\":14,\"threads\":28}",
          "is_validated": 1,
          "is_compatible": 1,
          "compatibility_notes": "Compatible with target motherboard",
          "created_at": "2025-11-18 14:23:45",
          "updated_at": "2025-11-18 14:23:45"
        }
      ],
      "history": [
        {
          "id": 1,
          "ticket_id": 1,
          "action": "created",
          "old_value": null,
          "new_value": "draft",
          "changed_by": 5,
          "changed_by_username": "john_doe",
          "notes": "Ticket created",
          "ip_address": "192.168.1.100",
          "user_agent": "Mozilla/5.0...",
          "created_at": "2025-11-18 14:23:45"
        },
        {
          "id": 2,
          "ticket_id": 1,
          "action": "status_change",
          "old_value": "draft",
          "new_value": "pending",
          "changed_by": 5,
          "changed_by_username": "john_doe",
          "notes": "Ticket submitted for approval",
          "ip_address": "192.168.1.100",
          "user_agent": "Mozilla/5.0...",
          "created_at": "2025-11-18 14:25:30"
        },
        {
          "id": 3,
          "ticket_id": 1,
          "action": "status_change",
          "old_value": "pending",
          "new_value": "approved",
          "changed_by": 3,
          "changed_by_username": "admin_user",
          "notes": "Ticket approved",
          "ip_address": "192.168.1.50",
          "user_agent": "Mozilla/5.0...",
          "created_at": "2025-11-18 14:30:00"
        }
      ]
    }
  }
}
```

**Error (404 Not Found)**:

```json
{
  "success": false,
  "authenticated": true,
  "code": 404,
  "message": "Ticket not found",
  "timestamp": "2025-11-18 14:35:00",
  "data": null
}
```

---

### 4. ticket-update

**Unified update endpoint - handles status changes, field updates, and assignments**

#### Request

**Method**: `POST`

**Permission**: Dynamic based on update type (see Status Transitions section)

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | ✅ | Must be `ticket-update` |
| `ticket_id` | integer | ✅ | Ticket ID to update |
| `status` | string | ❌ | New status (see Status Transitions) |
| `title` | string | ❌ | New title (only for `draft` tickets, requires `ticket.edit_own` or `ticket.manage`) |
| `description` | string | ❌ | New description (only for `draft` tickets, requires `ticket.edit_own` or `ticket.manage`) |
| `priority` | string | ❌ | New priority: `low`, `medium`, `high`, `urgent` (only for `draft` tickets) |
| `assigned_to` | integer | ❌ | User ID to assign (requires `ticket.assign` or `ticket.manage`) |
| `rejection_reason` | string | ⚠️ | **Required** when `status=rejected` |
| `deployment_notes` | string | ❌ | Notes when deploying (optional for `status=deployed`) |
| `completion_notes` | string | ❌ | Notes when completing (optional for `status=completed`) |

**⚠️ At least one optional parameter must be provided**

#### Status Transition Rules

| From | To | Permission | Notes |
|------|-----|-----------|-------|
| `draft` | `pending` | `ticket.create` | Submit for approval |
| `pending` | `approved` | `ticket.approve` | Cannot approve own ticket (unless `ticket.manage`) |
| `pending` | `rejected` | `ticket.reject` | Requires `rejection_reason` |
| `pending` | `cancelled` | `ticket.cancel` | - |
| `approved` | `in_progress` | `ticket.deploy` | Start deployment work |
| `approved` | `rejected` | `ticket.reject` | Requires `rejection_reason` |
| `approved` | `cancelled` | `ticket.cancel` | - |
| `in_progress` | `deployed` | `ticket.deploy` | Mark as deployed |
| `in_progress` | `rejected` | `ticket.reject` | Requires `rejection_reason` |
| `deployed` | `completed` | `ticket.complete` | Final completion |
| `rejected` | `pending` | `ticket.create` | Resubmit after fixes |
| `rejected` | `cancelled` | `ticket.cancel` | - |
| Any | `cancelled` | `ticket.cancel` | Cancel ticket |

**Editing Fields**:
- **title/description/priority**: Only editable in `draft` status
- **assigned_to**: Can be changed anytime with `ticket.assign` permission
- **ticket.manage** permission bypasses most restrictions

#### Response

**Success (200 OK)**:

```json
{
  "success": true,
  "authenticated": true,
  "code": 200,
  "message": "Ticket updated successfully",
  "timestamp": "2025-11-18 14:40:00",
  "data": {
    "ticket": {
      "id": 1,
      "ticket_number": "TKT-20251118-0001",
      "title": "Add 2x Intel Xeon CPUs to Server",
      "description": "Need to upgrade server with dual Intel Xeon processors",
      "status": "in_progress",
      "priority": "high",
      "target_server_uuid": "550e8400-e29b-41d4-a716-446655440000",
      "created_by": 5,
      "created_by_username": "john_doe",
      "assigned_to": 3,
      "assigned_to_username": "admin_user",
      "created_at": "2025-11-18 14:23:45",
      "updated_at": "2025-11-18 14:40:00"
    }
  }
}
```

**Error (400 Bad Request)**:

```json
{
  "success": false,
  "authenticated": true,
  "code": 400,
  "message": "Validation failed",
  "timestamp": "2025-11-18 14:40:00",
  "data": {
    "errors": [
      "Cannot approve your own ticket (separation of duties)",
      "rejection_reason is required when rejecting a ticket"
    ]
  }
}
```

**Error (403 Forbidden)**:

```json
{
  "success": false,
  "authenticated": true,
  "code": 403,
  "message": "Permission denied",
  "timestamp": "2025-11-18 14:40:00",
  "data": {
    "required_permission": "ticket.approve"
  }
}
```

---

### 5. ticket-delete

**Delete ticket (soft delete by default, hard delete for admins)**

#### Request

**Method**: `POST` or `DELETE`

**Permission**: `ticket.delete` (soft delete), `ticket.manage` (hard delete)

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | ✅ | Must be `ticket-delete` |
| `ticket_id` | integer | ✅ | Ticket ID to delete |
| `hard_delete` | string | ❌ | `true` for permanent deletion (requires `ticket.manage`), `false` or omit for soft delete (default: `false`) |

**Delete Types**:
- **Soft Delete**: Sets ticket `status` to `cancelled` (preserves data)
- **Hard Delete**: Permanently removes ticket and all related data (items, history)

#### Response

**Success (200 OK)**:

```json
{
  "success": true,
  "authenticated": true,
  "code": 200,
  "message": "Ticket deleted successfully",
  "timestamp": "2025-11-18 14:45:00",
  "data": {
    "ticket_id": 1,
    "delete_type": "soft"
  }
}
```

**Error (403 Forbidden)**:

```json
{
  "success": false,
  "authenticated": true,
  "code": 403,
  "message": "Permission denied: ticket.manage required for hard delete",
  "timestamp": "2025-11-18 14:45:00",
  "data": null
}
```

---

## Data Models

### Ticket Object

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Ticket ID |
| `ticket_number` | string | Unique ticket number (format: `TKT-YYYYMMDD-XXXX`) |
| `title` | string | Ticket title (max 255 chars) |
| `description` | text | Detailed description |
| `status` | enum | Ticket status (see Status Transitions) |
| `priority` | enum | Priority: `low`, `medium`, `high`, `urgent` |
| `target_server_uuid` | string/null | Target server configuration UUID |
| `created_by` | integer | Creator user ID |
| `created_by_username` | string | Creator username |
| `created_by_email` | string | Creator email |
| `assigned_to` | integer/null | Assigned user ID |
| `assigned_to_username` | string/null | Assigned username |
| `assigned_to_email` | string/null | Assigned email |
| `rejection_reason` | text/null | Reason for rejection |
| `deployment_notes` | text/null | Notes added during deployment |
| `completion_notes` | text/null | Notes added when completed |
| `created_at` | timestamp | Creation timestamp |
| `updated_at` | timestamp | Last update timestamp |
| `submitted_at` | timestamp/null | When submitted (draft → pending) |
| `approved_at` | timestamp/null | When approved |
| `deployed_at` | timestamp/null | When deployed |
| `completed_at` | timestamp/null | When completed |
| `items` | array | Array of ticket items |
| `history` | array | Array of history entries (if requested) |

### Ticket Item Object

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Item ID |
| `ticket_id` | integer | Parent ticket ID |
| `component_type` | enum | Component type |
| `component_uuid` | string | Component UUID from JSON files |
| `quantity` | integer | Quantity requested |
| `action` | enum | Action: `add`, `remove`, `replace` |
| `component_name` | string | Component name (snapshot) |
| `component_specs` | JSON string | Full component specs (snapshot) |
| `is_validated` | boolean | UUID validated against JSON files |
| `is_compatible` | boolean | Compatible with target server |
| `compatibility_notes` | text/null | Compatibility check results |
| `created_at` | timestamp | Creation timestamp |
| `updated_at` | timestamp | Last update timestamp |

### History Entry Object

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | History entry ID |
| `ticket_id` | integer | Parent ticket ID |
| `action` | string | Action: `created`, `status_change`, `field_update`, `assigned`, etc. |
| `old_value` | text/null | Previous value |
| `new_value` | text/null | New value |
| `changed_by` | integer | User ID who made change |
| `changed_by_username` | string | Username who made change |
| `notes` | text/null | Additional notes |
| `ip_address` | string/null | User's IP address |
| `user_agent` | string/null | User's browser/client info |
| `created_at` | timestamp | Change timestamp |

---

## Status Transitions

### Status Flow Diagram

```
draft → pending → approved → in_progress → deployed → completed
  ↓       ↓         ↓            ↓
cancelled rejected  cancelled   rejected
            ↓
         pending (resubmit)
```

### Status Definitions

| Status | Description | Can Edit Fields | Can Add Items |
|--------|-------------|----------------|---------------|
| `draft` | Initial state, being prepared | ✅ Yes | ✅ Yes |
| `pending` | Submitted, awaiting approval | ❌ No | ❌ No |
| `approved` | Approved, ready for deployment | ❌ No | ❌ No |
| `in_progress` | Deployment work started | ❌ No | ❌ No |
| `deployed` | Successfully deployed | ❌ No | ❌ No |
| `completed` | Final state, work completed | ❌ No | ❌ No |
| `rejected` | Rejected, can resubmit | ❌ No | ❌ No |
| `cancelled` | Final state, cancelled | ❌ No | ❌ No |

---

## Examples

### Example 1: Create Ticket

```bash
curl -X POST "https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=ticket-create" \
  -d "title=Upgrade Server CPUs" \
  -d "description=Replace existing CPUs with Intel Xeon E5-2690 v4 processors" \
  -d "priority=high" \
  -d 'items=[{"component_type":"cpu","component_uuid":"550e8400-e29b-41d4-a716-446655440000","quantity":2,"action":"add"}]'
```

### Example 2: List Pending Tickets

```bash
curl -X GET "https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php?action=ticket-list&status=pending&page=1&limit=20" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

### Example 3: Get Ticket with History

```bash
curl -X GET "https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php?action=ticket-get&ticket_id=1&include_history=true" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

### Example 4: Submit Ticket for Approval

```bash
curl -X POST "https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=ticket-update" \
  -d "ticket_id=1" \
  -d "status=pending"
```

### Example 5: Approve Ticket

```bash
curl -X POST "https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=ticket-update" \
  -d "ticket_id=1" \
  -d "status=approved"
```

### Example 6: Reject Ticket

```bash
curl -X POST "https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=ticket-update" \
  -d "ticket_id=1" \
  -d "status=rejected" \
  -d "rejection_reason=Insufficient justification for upgrade"
```

### Example 7: Deploy Ticket

```bash
curl -X POST "https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=ticket-update" \
  -d "ticket_id=1" \
  -d "status=deployed" \
  -d "deployment_notes=Successfully installed 2x Intel Xeon E5-2690 v4 CPUs in rack 3, slot 5"
```

### Example 8: Complete Ticket

```bash
curl -X POST "https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=ticket-update" \
  -d "ticket_id=1" \
  -d "status=completed" \
  -d "completion_notes=Server online, all tests passed, monitoring shows normal operation"
```

### Example 9: Assign Ticket

```bash
curl -X POST "https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=ticket-update" \
  -d "ticket_id=1" \
  -d "assigned_to=3"
```

### Example 10: Search Tickets

```bash
curl -X GET "https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php?action=ticket-list&search=CPU&priority=high&status=pending" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

### Example 11: Soft Delete Ticket

```bash
curl -X POST "https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=ticket-delete" \
  -d "ticket_id=1" \
  -d "hard_delete=false"
```

### Example 12: Hard Delete Ticket (Admin Only)

```bash
curl -X POST "https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=ticket-delete" \
  -d "ticket_id=1" \
  -d "hard_delete=true"
```

---

## Testing Credentials

**Staging Server**: `https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php`

**Default Admin**:
- Username: `superadmin`
- Password: `password`

**Get JWT Token**:

```bash
curl -s -X POST "https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php" \
  -d "action=auth-login" \
  -d "username=superadmin" \
  -d "password=password" | jq -r '.data.tokens.access_token'
```

---

## Error Codes

| Code | Meaning | Common Causes |
|------|---------|---------------|
| 200 | OK | Request successful |
| 201 | Created | Resource created successfully |
| 400 | Bad Request | Invalid parameters, validation errors |
| 401 | Unauthorized | Missing/invalid JWT token |
| 403 | Forbidden | Insufficient permissions |
| 404 | Not Found | Ticket/resource not found |
| 500 | Internal Server Error | Server-side error |

---

## Notes

1. **Separation of Duties**: Users cannot approve their own tickets (unless they have `ticket.manage` permission)
2. **UUID Validation**: All component UUIDs **must exist** in `All-JSON/{type}-jsons/*.json` files
3. **Status Constraints**: Field editing is restricted to `draft` status; status transitions follow strict workflow rules
4. **Audit Trail**: All changes are logged in `ticket_history` table with user, timestamp, and IP address
5. **Soft Delete Default**: Tickets are soft-deleted (status set to `cancelled`) by default; hard delete requires `ticket.manage` permission

---

**Document Version**: 1.0
**Last Updated**: 2025-11-18
**Project**: BDC IMS - Ticketing System
