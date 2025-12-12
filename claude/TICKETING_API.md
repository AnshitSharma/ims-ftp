# Ticketing API Documentation

> **Complete reference for all Ticketing System endpoints with request/response formats and status codes**

## Table of Contents

1. [Overview](#overview)
2. [Standard Response Format](#standard-response-format)
3. [HTTP Status Codes](#http-status-codes)
4. [Authentication & Authorization](#authentication--authorization)
5. [API Endpoints](#api-endpoints)
   - [ticket-create](#ticket-create)
   - [ticket-list](#ticket-list)
   - [ticket-get](#ticket-get)
   - [ticket-update](#ticket-update)
   - [ticket-delete](#ticket-delete)
6. [Status Workflow](#status-workflow)
7. [Permission Matrix](#permission-matrix)
8. [Error Handling](#error-handling)
9. [Examples](#examples)

---

## Overview

The Ticketing System manages hardware component requests with a complete audit trail. Features include:

- **5 Primary Endpoints** for full CRUD operations
- **8 Status States** with enforced state machine
- **Audit Logging** of all changes
- **Component Validation** with compatibility checking
- **Role-Based Access Control** with fine-grained permissions
- **Soft & Hard Deletes** for data preservation/removal

**Technology Stack**:
- Method: REST API (HTTP POST/GET/DELETE)
- Format: JSON request/response
- Auth: JWT Bearer Token
- Database: MySQL PDO

---

## Standard Response Format

All endpoints return JSON in this format:

```json
{
  "success": true|false,
  "authenticated": true|false,
  "code": 200|201|400|401|403|404|500,
  "message": "Human-readable message",
  "timestamp": "2025-12-06 12:34:56",
  "data": {}
}
```

### Response Structure Explanation

| Field | Type | Meaning |
|-------|------|---------|
| `success` | boolean | Operation succeeded (true) or failed (false) |
| `authenticated` | boolean | User is authenticated (true) or not (false) |
| `code` | integer | HTTP-like status code (see below) |
| `message` | string | Human-readable description of result |
| `timestamp` | string | Server timestamp of response |
| `data` | object | Operation-specific data (null if not applicable) |

---

## HTTP Status Codes

### Success Codes (2xx)

| Code | Name | Meaning |
|------|------|---------|
| **200** | OK | Request succeeded, data returned |
| **201** | Created | Resource created successfully |

**When to expect:**
- `200`: ticket-list, ticket-get, ticket-update, ticket-delete (successful operations)
- `201`: ticket-create (new ticket created)

---

### Client Error Codes (4xx)

#### 400 - Bad Request
**Cause**: Invalid request data, missing required fields, validation failures

**Common Scenarios**:
- Missing required parameter (e.g., missing `title` in ticket-create)
- Invalid JSON format in request body
- Invalid parameter value (e.g., `ticket_id` not numeric)
- Validation failure (e.g., invalid status transition)
- Duplicate/conflicting data

**Example Response**:
```json
{
  "success": false,
  "authenticated": true,
  "code": 400,
  "message": "title is required",
  "timestamp": "2025-12-06 12:34:56",
  "data": null
}
```

**Common Causes**:
```
ticket-create:
  - Missing: title, description, items
  - Invalid JSON in items array
  - Empty items array

ticket-update:
  - Invalid status transition
  - rejection_reason missing when status=rejected
  - Attempting to edit non-draft ticket
  - No updates provided
  - Invalid assigned_to user ID

ticket-delete:
  - ticket_id not numeric
  - Ticket already deleted
```

---

#### 401 - Unauthorized
**Cause**: Missing or invalid JWT token

**When You See This**:
- No JWT token provided in Authorization header
- JWT token expired (default: 24 hours)
- JWT token malformed or invalid signature
- Token belongs to deleted/disabled user

**Example Response**:
```json
{
  "success": false,
  "authenticated": false,
  "code": 401,
  "message": "Unauthorized: No valid JWT token",
  "timestamp": "2025-12-06 12:34:56",
  "data": null
}
```

**Fix**: Re-authenticate via `auth-login` endpoint to get fresh token

---

#### 403 - Forbidden
**Cause**: Authenticated but lacks permission for operation

**When You See This**:
- User doesn't have required ACL permission
- Attempting to approve own ticket (separation of duties)
- Attempting hard-delete without `ticket.manage` permission
- Trying to view another user's private ticket

**Permission Denied Scenarios**:

```
ticket-create:
  ✗ Missing: ticket.create

ticket-list:
  ✗ Missing: ticket.view_all, ticket.view_own, ticket.view_assigned, ticket.manage

ticket-get:
  ✗ Not owner + missing ticket.view_all or ticket.manage
  ✗ Not assigned + missing ticket.view_all or ticket.manage

ticket-update:
  ✗ For status changes: Missing specific permission (ticket.approve, ticket.reject, etc.)
  ✗ For field edits: Ticket not in draft OR not owner
  ✗ For assignments: Missing ticket.assign or ticket.manage

ticket-delete:
  ✗ Missing: ticket.delete
  ✗ Hard delete missing: ticket.manage
```

**Example Response**:
```json
{
  "success": false,
  "authenticated": true,
  "code": 403,
  "message": "Permission denied: ticket.create required",
  "timestamp": "2025-12-06 12:34:56",
  "data": null
}
```

---

#### 404 - Not Found
**Cause**: Resource doesn't exist

**When You See This**:
- Ticket ID doesn't exist
- Ticket was deleted (hard delete)
- User trying to access non-existent ticket

**Example Response**:
```json
{
  "success": false,
  "authenticated": true,
  "code": 404,
  "message": "Ticket not found",
  "timestamp": "2025-12-06 12:34:56",
  "data": null
}
```

---

### Server Error Codes (5xx)

#### 500 - Internal Server Error
**Cause**: Unexpected server-side error

**When You See This**:
- Database connection failed
- Uncaught exception in code
- File system error (can't write logs)
- External service failure

**Example Response**:
```json
{
  "success": false,
  "authenticated": true,
  "code": 500,
  "message": "Failed to create ticket",
  "timestamp": "2025-12-06 12:34:56",
  "data": {
    "error": "Database connection failed"
  }
}
```

**Action**: Check server logs (`error_log()` output) for details

---

## Authentication & Authorization

### JWT Token

**How to Get Token**:
```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -d "action=auth-login" \
  -d "username=superadmin" \
  -d "password=password"
```

**Response**:
```json
{
  "success": true,
  "authenticated": true,
  "code": 200,
  "message": "Login successful",
  "data": {
    "tokens": {
      "access_token": "eyJhbGc...",
      "token_type": "Bearer",
      "expires_in": 86400
    }
  }
}
```

**How to Use Token**:
```bash
# In Authorization header
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "http://localhost:8000/api/api.php?action=ticket-list"
```

**Token Lifespan**: 24 hours (configured in `.env` as `JWT_EXPIRY_HOURS`)

---

### ACL Permissions

All ticketing permissions follow format: `ticket.{action}`

**Available Permissions**:
- `ticket.create` - Create new tickets
- `ticket.view_own` - View own tickets only
- `ticket.view_all` - View all tickets
- `ticket.view_assigned` - View assigned tickets
- `ticket.edit_own` - Edit own tickets
- `ticket.assign` - Assign tickets to users
- `ticket.approve` - Approve pending tickets
- `ticket.reject` - Reject pending tickets
- `ticket.deploy` - Move to in_progress/deployed
- `ticket.complete` - Mark as completed
- `ticket.cancel` - Cancel tickets
- `ticket.delete` - Delete tickets (soft delete)
- `ticket.manage` - All permissions + bypass restrictions

---

## API Endpoints

### ticket-create

**Creates a new ticket with component items**

#### Request

```http
POST /api/api.php
Authorization: Bearer {token}
Content-Type: application/x-www-form-urlencoded
```

**Parameters**:

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `action` | string | ✓ | - | Must be `ticket-create` |
| `title` | string | ✓ | - | Ticket title (1-255 chars) |
| `description` | string | ✓ | - | Detailed description |
| `priority` | string | ✗ | `medium` | Priority level: `low`, `medium`, `high`, `urgent` |
| `target_server_uuid` | string | ✗ | null | Target server configuration UUID |
| `items` | JSON array | ✓ | - | Component items to add (see format below) |

**Items Array Format**:
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
    "component_uuid": "550e8400-e29b-41d4-a716-446655440001",
    "quantity": 8,
    "action": "add"
  }
]
```

**Item Field Details**:

| Field | Type | Values | Description |
|-------|------|--------|-------------|
| `component_type` | string | cpu, ram, storage, motherboard, nic, caddy, chassis, pciecard, hbacard | Component type |
| `component_uuid` | string | Valid UUID | Must exist in `All-JSON/{type}-jsons/*.json` |
| `quantity` | integer | 1-99 | Number of units |
| `action` | string | add, remove, replace | Action to perform |

**Example Request**:
```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer eyJhbGc..." \
  -d "action=ticket-create" \
  -d "title=Upgrade Server CPU and RAM" \
  -d "description=Need to upgrade prod-server-01 with newer CPU and additional RAM for improved performance" \
  -d "priority=high" \
  -d "target_server_uuid=550e8400-e29b-41d4-a716-446655440000" \
  -d 'items=[
    {"component_type":"cpu","component_uuid":"550e8400-e29b-41d4-a716-446655440001","quantity":2,"action":"add"},
    {"component_type":"ram","component_uuid":"550e8400-e29b-41d4-a716-446655440002","quantity":4,"action":"add"}
  ]'
```

#### Response - Success (201 Created)

```json
{
  "success": true,
  "authenticated": true,
  "code": 201,
  "message": "Ticket created successfully",
  "timestamp": "2025-12-06 12:34:56",
  "data": {
    "ticket_id": 1,
    "ticket_number": "TKT-20251206-0001",
    "ticket": {
      "id": 1,
      "ticket_number": "TKT-20251206-0001",
      "title": "Upgrade Server CPU and RAM",
      "description": "Need to upgrade prod-server-01...",
      "status": "draft",
      "priority": "high",
      "target_server_uuid": "550e8400-e29b-41d4-a716-446655440000",
      "created_by": 1,
      "created_by_username": "john_admin",
      "assigned_to": null,
      "created_at": "2025-12-06 12:34:56",
      "updated_at": "2025-12-06 12:34:56",
      "submitted_at": null,
      "approved_at": null,
      "deployed_at": null,
      "completed_at": null,
      "rejection_reason": null,
      "deployment_notes": null,
      "completion_notes": null,
      "items": [
        {
          "id": 1,
          "ticket_id": 1,
          "component_type": "cpu",
          "component_uuid": "550e8400-e29b-41d4-a716-446655440001",
          "quantity": 2,
          "action": "add",
          "component_name": "Intel Xeon Platinum 8380",
          "component_specs": {
            "cores": 28,
            "threads": 56,
            "base_clock": "2.3 GHz"
          },
          "is_validated": true,
          "is_compatible": true,
          "compatibility_notes": "Compatible with motherboard"
        }
      ]
    }
  }
}
```

#### Response - Error (400 Bad Request)

```json
{
  "success": false,
  "authenticated": true,
  "code": 400,
  "message": "title is required",
  "timestamp": "2025-12-06 12:34:56",
  "data": null
}
```

#### Response - Error (403 Forbidden)

```json
{
  "success": false,
  "authenticated": true,
  "code": 403,
  "message": "Permission denied: ticket.create required",
  "timestamp": "2025-12-06 12:34:56",
  "data": null
}
```

#### Response - Error (500 Server Error)

```json
{
  "success": false,
  "authenticated": true,
  "code": 500,
  "message": "Failed to create ticket",
  "timestamp": "2025-12-06 12:34:56",
  "data": {
    "error": "Database error details"
  }
}
```

#### Required Permissions

- `ticket.create` - Required to create tickets

#### Ticket Numbering

Tickets auto-generate number in format: `TKT-YYYYMMDD-XXXX`
- `YYYYMMDD` = Creation date (e.g., 20251206)
- `XXXX` = Sequential counter for that day (e.g., 0001, 0002, etc.)

#### Initial Status

All created tickets start in `draft` status

---

### ticket-list

**List tickets with filtering, pagination, and sorting**

#### Request

```http
GET /api/api.php?action=ticket-list
Authorization: Bearer {token}
Content-Type: application/x-www-form-urlencoded
```

**Parameters** (all optional, support both GET and POST):

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `action` | string | - | Must be `ticket-list` |
| `status` | string | - | Filter: draft, pending, approved, in_progress, deployed, completed, rejected, cancelled |
| `priority` | string | - | Filter: low, medium, high, urgent |
| `created_by` | integer | - | Filter: user ID who created ticket |
| `assigned_to` | integer | - | Filter: user ID assigned to ticket |
| `search` | string | - | Search in: ticket_number, title, description |
| `created_from` | date | - | Filter: From date (YYYY-MM-DD) |
| `created_to` | date | - | Filter: To date (YYYY-MM-DD) |
| `page` | integer | 1 | Page number (starts at 1) |
| `limit` | integer | 20 | Items per page (max 100) |
| `order_by` | string | created_at | Sort field: created_at, updated_at, status, priority, ticket_number |
| `order_dir` | string | DESC | Sort direction: ASC, DESC |

**Permission Logic**:
- If have `ticket.view_all` → See all tickets
- If have `ticket.view_own` only → See only own created tickets
- If have `ticket.view_assigned` only → See only assigned tickets
- If have both `ticket.view_own` + `ticket.view_assigned` → See own OR assigned
- If have `ticket.manage` → See all tickets (bypass restrictions)

**Example Request - List All Pending Tickets**:
```bash
curl -H "Authorization: Bearer eyJhbGc..." \
  "http://localhost:8000/api/api.php?action=ticket-list&status=pending&limit=10&page=1"
```

**Example Request - Search and Filter**:
```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer eyJhbGc..." \
  -d "action=ticket-list" \
  -d "search=CPU Upgrade" \
  -d "priority=high" \
  -d "status=in_progress" \
  -d "created_from=2025-12-01" \
  -d "created_to=2025-12-06" \
  -d "order_by=created_at" \
  -d "order_dir=DESC" \
  -d "page=1" \
  -d "limit=20"
```

#### Response - Success (200 OK)

```json
{
  "success": true,
  "authenticated": true,
  "code": 200,
  "message": "Tickets retrieved successfully",
  "timestamp": "2025-12-06 12:34:56",
  "data": {
    "tickets": [
      {
        "id": 3,
        "ticket_number": "TKT-20251206-0003",
        "title": "Storage Array Expansion",
        "description": "Add 10TB to storage array",
        "status": "in_progress",
        "priority": "high",
        "target_server_uuid": null,
        "created_by": 1,
        "created_by_username": "john_admin",
        "assigned_to": 2,
        "assigned_to_username": "jane_tech",
        "created_at": "2025-12-06 09:15:32",
        "updated_at": "2025-12-06 11:22:45",
        "submitted_at": "2025-12-06 09:20:10",
        "approved_at": "2025-12-06 10:30:22",
        "deployed_at": null,
        "completed_at": null
      },
      {
        "id": 2,
        "ticket_number": "TKT-20251206-0002",
        "title": "RAM Upgrade",
        "description": "Upgrade server to 256GB RAM",
        "status": "pending",
        "priority": "medium",
        "target_server_uuid": "550e8400-e29b-41d4-a716-446655440000",
        "created_by": 3,
        "created_by_username": "bob_engineer",
        "assigned_to": null,
        "assigned_to_username": null,
        "created_at": "2025-12-06 08:45:10",
        "updated_at": "2025-12-06 08:45:10",
        "submitted_at": "2025-12-06 08:50:05",
        "approved_at": null,
        "deployed_at": null,
        "completed_at": null
      }
    ],
    "total": 2,
    "page": 1,
    "limit": 20,
    "total_pages": 1
  }
}
```

#### Response - Success with Empty Results (200 OK)

```json
{
  "success": true,
  "authenticated": true,
  "code": 200,
  "message": "Tickets retrieved successfully",
  "timestamp": "2025-12-06 12:34:56",
  "data": {
    "tickets": [],
    "total": 0,
    "page": 1,
    "limit": 20,
    "total_pages": 0
  }
}
```

#### Response - Error (403 Forbidden)

```json
{
  "success": false,
  "authenticated": true,
  "code": 403,
  "message": "Permission denied: No ticket view permissions",
  "timestamp": "2025-12-06 12:34:56",
  "data": null
}
```

#### Required Permissions

At least ONE of:
- `ticket.view_all` - View all tickets
- `ticket.view_own` - View own created tickets
- `ticket.view_assigned` - View assigned tickets
- `ticket.manage` - View all (bypass all restrictions)

---

### ticket-get

**Retrieve single ticket with full details and optional history**

#### Request

```http
GET /api/api.php?action=ticket-get&ticket_id=1
Authorization: Bearer {token}
```

**Parameters**:

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `action` | string | ✓ | - | Must be `ticket-get` |
| `ticket_id` | integer | ✓ | - | Ticket ID to retrieve |
| `include_history` | boolean | ✗ | false | Include change history (true/false, 1/0) |

**Example Request - Basic**:
```bash
curl -H "Authorization: Bearer eyJhbGc..." \
  "http://localhost:8000/api/api.php?action=ticket-get&ticket_id=1"
```

**Example Request - With History**:
```bash
curl -H "Authorization: Bearer eyJhbGc..." \
  "http://localhost:8000/api/api.php?action=ticket-get&ticket_id=1&include_history=true"
```

**Example Request - POST Method**:
```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer eyJhbGc..." \
  -d "action=ticket-get" \
  -d "ticket_id=1" \
  -d "include_history=true"
```

#### Response - Success (200 OK)

```json
{
  "success": true,
  "authenticated": true,
  "code": 200,
  "message": "Ticket retrieved successfully",
  "timestamp": "2025-12-06 12:34:56",
  "data": {
    "ticket": {
      "id": 1,
      "ticket_number": "TKT-20251206-0001",
      "title": "Upgrade Server CPU and RAM",
      "description": "Need to upgrade prod-server-01 with newer CPU and additional RAM",
      "status": "pending",
      "priority": "high",
      "target_server_uuid": "550e8400-e29b-41d4-a716-446655440000",
      "created_by": 1,
      "created_by_username": "john_admin",
      "assigned_to": 2,
      "assigned_to_username": "jane_tech",
      "created_at": "2025-12-06 12:00:00",
      "updated_at": "2025-12-06 12:30:00",
      "submitted_at": "2025-12-06 12:05:00",
      "approved_at": null,
      "deployed_at": null,
      "completed_at": null,
      "rejection_reason": null,
      "deployment_notes": null,
      "completion_notes": null,
      "items": [
        {
          "id": 1,
          "ticket_id": 1,
          "component_type": "cpu",
          "component_uuid": "550e8400-e29b-41d4-a716-446655440001",
          "quantity": 2,
          "action": "add",
          "component_name": "Intel Xeon Platinum 8380",
          "component_specs": {
            "cores": 28,
            "threads": 56,
            "base_clock": "2.3 GHz"
          },
          "is_validated": true,
          "is_compatible": true,
          "compatibility_notes": "Compatible with motherboard"
        }
      ],
      "history": [
        {
          "id": 1,
          "action": "created",
          "old_value": null,
          "new_value": "ticket_id=1",
          "changed_by": 1,
          "changed_by_username": "john_admin",
          "notes": "Ticket created",
          "ip_address": "192.168.1.100",
          "user_agent": "Mozilla/5.0...",
          "created_at": "2025-12-06 12:00:00"
        },
        {
          "id": 2,
          "action": "submitted",
          "old_value": "status=draft",
          "new_value": "status=pending",
          "changed_by": 1,
          "changed_by_username": "john_admin",
          "notes": "Ticket submitted for approval",
          "ip_address": "192.168.1.100",
          "user_agent": "Mozilla/5.0...",
          "created_at": "2025-12-06 12:05:00"
        },
        {
          "id": 3,
          "action": "assigned",
          "old_value": "assigned_to=null",
          "new_value": "assigned_to=2",
          "changed_by": 1,
          "changed_by_username": "john_admin",
          "notes": "Assigned to jane_tech",
          "ip_address": "192.168.1.100",
          "user_agent": "Mozilla/5.0...",
          "created_at": "2025-12-06 12:30:00"
        }
      ]
    }
  }
}
```

#### Response - Success (Without History)

When `include_history=false` (default), history array is omitted:

```json
{
  "success": true,
  "authenticated": true,
  "code": 200,
  "message": "Ticket retrieved successfully",
  "timestamp": "2025-12-06 12:34:56",
  "data": {
    "ticket": {
      "id": 1,
      "ticket_number": "TKT-20251206-0001",
      "title": "Upgrade Server CPU and RAM",
      "description": "...",
      "status": "pending",
      "priority": "high",
      "created_by": 1,
      "created_by_username": "john_admin",
      "assigned_to": 2,
      "assigned_to_username": "jane_tech",
      "created_at": "2025-12-06 12:00:00",
      "updated_at": "2025-12-06 12:30:00",
      "items": [...]
      // history field NOT included
    }
  }
}
```

#### Response - Error (404 Not Found)

```json
{
  "success": false,
  "authenticated": true,
  "code": 404,
  "message": "Ticket not found",
  "timestamp": "2025-12-06 12:34:56",
  "data": null
}
```

#### Response - Error (403 Forbidden)

```json
{
  "success": false,
  "authenticated": true,
  "code": 403,
  "message": "Permission denied: Cannot view this ticket",
  "timestamp": "2025-12-06 12:34:56",
  "data": null
}
```

#### Required Permissions

**For own tickets (creator)**:
- `ticket.view_own` OR `ticket.view_all` OR `ticket.manage`

**For assigned tickets**:
- `ticket.view_assigned` OR `ticket.view_all` OR `ticket.manage`

**For others' tickets**:
- `ticket.view_all` OR `ticket.manage`

---

### ticket-update

**Unified endpoint for all ticket updates: status changes, field edits, assignments**

#### Request

```http
POST /api/api.php
Authorization: Bearer {token}
Content-Type: application/x-www-form-urlencoded
```

**Parameters**:

| Parameter | Type | When Required | Description |
|-----------|------|---------------|-------------|
| `action` | string | Always | Must be `ticket-update` |
| `ticket_id` | integer | Always | Ticket ID to update |
| `status` | string | Optional | New status (see status transitions below) |
| `title` | string | Optional | New title (draft tickets only) |
| `description` | string | Optional | New description (draft tickets only) |
| `priority` | string | Optional | New priority: low, medium, high, urgent |
| `assigned_to` | integer | Optional | User ID to assign ticket to |
| `rejection_reason` | string | When status=rejected | Reason for rejection |
| `deployment_notes` | string | When status=deployed | Optional notes |
| `completion_notes` | string | When status=completed | Optional notes |

**Note**: At least one update parameter must be provided (status, title, description, priority, assigned_to, etc.)

#### Status Transitions & Permissions

The endpoint uses a **state machine** with enforced valid transitions:

```
DRAFT
  ↓
  └─→ PENDING (permission: ticket.create)
        ↓
        ├─→ APPROVED (permission: ticket.approve, cannot approve own)
        │     ↓
        │     ├─→ IN_PROGRESS (permission: ticket.deploy)
        │     │     ↓
        │     │     └─→ DEPLOYED (permission: ticket.deploy, optional: deployment_notes)
        │     │           ↓
        │     │           └─→ COMPLETED (permission: ticket.complete, optional: completion_notes)
        │     │
        │     └─→ (Can skip to deployed directly)
        │
        └─→ REJECTED (permission: ticket.reject, required: rejection_reason)

ANY STATUS → CANCELLED (permission: ticket.cancel)
```

**Detailed Status Transitions**:

| From | To | Condition | Permission | Additional Requirements |
|------|-----|-----------|-----------|----------------------|
| draft | pending | Always | ticket.create | - |
| pending | approved | Always | ticket.approve | Cannot approve own ticket |
| pending | rejected | Always | ticket.reject | rejection_reason required |
| approved | in_progress | Always | ticket.deploy | - |
| approved | deployed | Always | ticket.deploy | deployment_notes optional |
| in_progress | deployed | Always | ticket.deploy | deployment_notes optional |
| deployed | completed | Always | ticket.complete | completion_notes optional |
| any | cancelled | Always | ticket.cancel | - |

#### Example Request - Submit Ticket (draft → pending)

```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer eyJhbGc..." \
  -d "action=ticket-update" \
  -d "ticket_id=1" \
  -d "status=pending"
```

#### Example Request - Approve Ticket (pending → approved)

```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer eyJhbGc..." \
  -d "action=ticket-update" \
  -d "ticket_id=1" \
  -d "status=approved"
```

#### Example Request - Reject Ticket (pending → rejected)

```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer eyJhbGc..." \
  -d "action=ticket-update" \
  -d "ticket_id=1" \
  -d "status=rejected" \
  -d "rejection_reason=Requires CAB approval before proceeding"
```

#### Example Request - Deploy Ticket (approved → in_progress → deployed)

```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer eyJhbGc..." \
  -d "action=ticket-update" \
  -d "ticket_id=1" \
  -d "status=deployed" \
  -d "deployment_notes=Deployment scheduled for 2025-12-07 02:00 UTC"
```

#### Example Request - Complete Ticket (deployed → completed)

```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer eyJhbGc..." \
  -d "action=ticket-update" \
  -d "ticket_id=1" \
  -d "status=completed" \
  -d "completion_notes=Successfully upgraded CPU and RAM. All tests passed."
```

#### Example Request - Edit Draft Ticket

```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer eyJhbGc..." \
  -d "action=ticket-update" \
  -d "ticket_id=1" \
  -d "title=Updated Title" \
  -d "description=Updated description with more details" \
  -d "priority=urgent"
```

**Note**: Can only edit title/description if ticket is in `draft` status

#### Example Request - Assign Ticket

```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer eyJhbGc..." \
  -d "action=ticket-update" \
  -d "ticket_id=1" \
  -d "assigned_to=2"
```

#### Example Request - Multiple Updates at Once

```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer eyJhbGc..." \
  -d "action=ticket-update" \
  -d "ticket_id=1" \
  -d "status=in_progress" \
  -d "priority=urgent" \
  -d "assigned_to=2"
```

#### Response - Success (200 OK)

```json
{
  "success": true,
  "authenticated": true,
  "code": 200,
  "message": "Ticket updated successfully",
  "timestamp": "2025-12-06 12:34:56",
  "data": {
    "ticket": {
      "id": 1,
      "ticket_number": "TKT-20251206-0001",
      "title": "Upgrade Server CPU and RAM",
      "description": "...",
      "status": "approved",
      "priority": "high",
      "created_by": 1,
      "created_by_username": "john_admin",
      "assigned_to": 2,
      "assigned_to_username": "jane_tech",
      "created_at": "2025-12-06 12:00:00",
      "updated_at": "2025-12-06 12:45:30",
      "submitted_at": "2025-12-06 12:05:00",
      "approved_at": "2025-12-06 12:45:30",
      "deployed_at": null,
      "completed_at": null
    }
  }
}
```

#### Response - Error (400 Bad Request)

**Case 1: Invalid Status Transition**
```json
{
  "success": false,
  "authenticated": true,
  "code": 400,
  "message": "Validation failed",
  "timestamp": "2025-12-06 12:34:56",
  "data": {
    "errors": [
      "Can only approve pending tickets",
      "Cannot approve your own ticket (separation of duties)"
    ]
  }
}
```

**Case 2: Missing Required Field for Status**
```json
{
  "success": false,
  "authenticated": true,
  "code": 400,
  "message": "Validation failed",
  "timestamp": "2025-12-06 12:34:56",
  "data": {
    "errors": [
      "rejection_reason is required when rejecting a ticket"
    ]
  }
}
```

**Case 3: No Updates Provided**
```json
{
  "success": false,
  "authenticated": true,
  "code": 400,
  "message": "No updates provided",
  "timestamp": "2025-12-06 12:34:56",
  "data": null
}
```

#### Response - Error (403 Forbidden)

```json
{
  "success": false,
  "authenticated": true,
  "code": 403,
  "message": "Permission denied",
  "timestamp": "2025-12-06 12:34:56",
  "data": {
    "required_permission": "ticket.approve"
  }
}
```

#### Response - Error (404 Not Found)

```json
{
  "success": false,
  "authenticated": true,
  "code": 404,
  "message": "Ticket not found",
  "timestamp": "2025-12-06 12:34:56",
  "data": null
}
```

#### Required Permissions

**Dynamic based on update type**:

- `status=draft→pending`: `ticket.create`
- `status=pending→approved`: `ticket.approve`
- `status=pending→rejected`: `ticket.reject`
- `status=*→in_progress`: `ticket.deploy`
- `status=*→deployed`: `ticket.deploy`
- `status=*→completed`: `ticket.complete`
- `status=*→cancelled`: `ticket.cancel`
- Field edits (title/description/priority): `ticket.edit_own` (or `ticket.manage`)
- Assignment (`assigned_to`): `ticket.assign`
- Override all checks: `ticket.manage`

**Special Rules**:
- Cannot approve own ticket (requires manager to approve)
- Can only edit title/description in draft status
- Can only edit own tickets (unless have `ticket.manage`)

---

### ticket-delete

**Delete ticket (soft delete = mark cancelled, hard delete = permanent removal)**

#### Request

```http
POST /api/api.php
Authorization: Bearer {token}
Content-Type: application/x-www-form-urlencoded
```

OR

```http
DELETE /api/api.php?action=ticket-delete&ticket_id=1
Authorization: Bearer {token}
```

**Parameters**:

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `action` | string | ✓ | - | Must be `ticket-delete` |
| `ticket_id` | integer | ✓ | - | Ticket ID to delete |
| `hard_delete` | boolean | ✗ | false | Hard delete (true) vs soft delete (false) |

**Soft Delete vs Hard Delete**:

| Type | Effect | Permission | Recoverable |
|------|--------|-----------|------------|
| Soft Delete | Sets status to 'cancelled' | `ticket.delete` | ✓ Yes |
| Hard Delete | Permanent removal from DB | `ticket.delete` + `ticket.manage` | ✗ No |

#### Example Request - Soft Delete (Default)

```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer eyJhbGc..." \
  -d "action=ticket-delete" \
  -d "ticket_id=1"
```

#### Example Request - Hard Delete

```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer eyJhbGc..." \
  -d "action=ticket-delete" \
  -d "ticket_id=1" \
  -d "hard_delete=true"
```

#### Example Request - Using DELETE Method

```bash
curl -X DELETE "http://localhost:8000/api/api.php?action=ticket-delete&ticket_id=1" \
  -H "Authorization: Bearer eyJhbGc..."
```

#### Response - Success (200 OK)

**Soft Delete**:
```json
{
  "success": true,
  "authenticated": true,
  "code": 200,
  "message": "Ticket deleted successfully",
  "timestamp": "2025-12-06 12:34:56",
  "data": {
    "ticket_id": 1,
    "delete_type": "soft"
  }
}
```

**Hard Delete**:
```json
{
  "success": true,
  "authenticated": true,
  "code": 200,
  "message": "Ticket deleted successfully",
  "timestamp": "2025-12-06 12:34:56",
  "data": {
    "ticket_id": 1,
    "delete_type": "hard"
  }
}
```

#### Response - Error (400 Bad Request)

```json
{
  "success": false,
  "authenticated": true,
  "code": 400,
  "message": "ticket_id is required and must be numeric",
  "timestamp": "2025-12-06 12:34:56",
  "data": null
}
```

#### Response - Error (403 Forbidden)

**Case 1: Missing delete permission**
```json
{
  "success": false,
  "authenticated": true,
  "code": 403,
  "message": "Permission denied: ticket.delete required",
  "timestamp": "2025-12-06 12:34:56",
  "data": null
}
```

**Case 2: Hard delete without manage permission**
```json
{
  "success": false,
  "authenticated": true,
  "code": 403,
  "message": "Permission denied: ticket.manage required for hard delete",
  "timestamp": "2025-12-06 12:34:56",
  "data": null
}
```

#### Response - Error (404 Not Found)

```json
{
  "success": false,
  "authenticated": true,
  "code": 404,
  "message": "Ticket not found",
  "timestamp": "2025-12-06 12:34:56",
  "data": null
}
```

#### Required Permissions

- Soft Delete: `ticket.delete`
- Hard Delete: `ticket.delete` + `ticket.manage`

---

## Status Workflow

### Complete State Machine

```
┌─────────────────────────────────────────────────────────────┐
│                   TICKET STATUS WORKFLOW                     │
└─────────────────────────────────────────────────────────────┘

START
  │
  ↓
┌──────────┐
│  DRAFT   │  Initial state, editable, not visible to approvers
└──────────┘
  │ Submit (permission: ticket.create)
  ↓
┌──────────┐
│ PENDING  │  Awaiting approval, read-only, visible to approvers
└──────────┘
  │
  ├─→ [APPROVE] (permission: ticket.approve)
  │     ↓
  │   ┌──────────┐
  │   │ APPROVED │  Approved, ready to deploy, assignments possible
  │   └──────────┘
  │     │
  │     ├─→ [START WORK] (permission: ticket.deploy)
  │     │     ↓
  │     │   ┌─────────────┐
  │     │   │ IN_PROGRESS │  Currently being worked on
  │     │   └─────────────┘
  │     │     │
  │     │     └─→ [DEPLOY] (permission: ticket.deploy)
  │     │           ↓
  │     ↓
  │   ┌──────────┐
  │   │ DEPLOYED │  Changes live in production
  │   └──────────┘
  │     │
  │     └─→ [COMPLETE] (permission: ticket.complete)
  │           ↓
  │         ┌──────────┐
  │         │COMPLETED │  Finished, archived
  │         └──────────┘
  │
  └─→ [REJECT] (permission: ticket.reject)
        ↓
      ┌──────────┐
      │ REJECTED │  Returned to creator, reason provided
      └──────────┘

ANY STATUS → [CANCEL] (permission: ticket.cancel)
              ↓
            ┌──────────┐
            │CANCELLED │  Abandoned/cancelled
            └──────────┘
```

### Status Descriptions

| Status | Meaning | Editable | Visible To |
|--------|---------|----------|-----------|
| **draft** | Initial state, work in progress | ✓ Yes (creator) | Creator only |
| **pending** | Submitted, awaiting approval | ✗ No | Approvers, creator |
| **approved** | Approved, ready for implementation | ✗ No | Assigned person, creator, approvers |
| **in_progress** | Currently being implemented | ✗ No | Assigned person, managers |
| **deployed** | Changes deployed to production | ✗ No | Everyone |
| **completed** | All work finished | ✗ No | Everyone |
| **rejected** | Not approved, sent back to creator | - | Creator (with reason) |
| **cancelled** | Abandoned by creator/manager | ✗ No | Everyone |

### Timestamp Tracking

The system automatically records when key state transitions occur:

| Field | Set When | Purpose |
|-------|----------|---------|
| `created_at` | Ticket created | Initial creation time |
| `updated_at` | Any change | Last modification time |
| `submitted_at` | draft → pending | When creator submitted |
| `approved_at` | pending → approved | When approver approved |
| `deployed_at` | * → deployed | When deployed to production |
| `completed_at` | deployed → completed | When marked complete |

---

## Permission Matrix

### Create Tickets

| User Type | Can Create | Can View Own | Can Approve | Can Deploy | Can Complete |
|-----------|-----------|------------|-----------|-----------|------------|
| Creator | ✓ `ticket.create` | ✓ `ticket.view_own` | ✗ No | ✗ No | ✗ No |
| Approver | ✓ `ticket.create` | ✓ `ticket.view_assigned` | ✓ `ticket.approve` | ✗ No | ✗ No |
| Technician | ✓ `ticket.create` | ✓ `ticket.view_assigned` | ✗ No | ✓ `ticket.deploy` | ✓ `ticket.complete` |
| Manager | ✓ `ticket.manage` | ✓ `ticket.view_all` | ✓ (override) | ✓ (override) | ✓ (override) |

### Full Permission Reference

```
CREATION & VISIBILITY:
  ticket.create          → Create tickets, submit for approval
  ticket.view_own        → View own created tickets
  ticket.view_assigned   → View assigned tickets
  ticket.view_all        → View all tickets (system-wide)

STATE TRANSITIONS:
  ticket.edit_own        → Edit own draft tickets
  ticket.assign          → Assign tickets to other users
  ticket.approve         → Approve pending tickets
  ticket.reject          → Reject pending tickets
  ticket.deploy          → Start work (in_progress), deploy (deployed)
  ticket.complete        → Mark as completed
  ticket.cancel          → Cancel tickets

ADMINISTRATION:
  ticket.delete          → Soft delete (mark cancelled)
  ticket.manage          → All permissions + override restrictions

ACL ENTRY:
  user_id : ticket.{action}
```

---

## Error Handling

### Understanding Error Responses

All errors follow a consistent format with actionable messages:

```json
{
  "success": false,
  "authenticated": true|false,
  "code": 400|401|403|404|500,
  "message": "Short error message",
  "data": null | { "errors": [...], "error": "..." }
}
```

### Common Error Scenarios

#### 1. Missing Required Field

**Request**:
```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -d "action=ticket-create" \
  -d "description=Some description"
  # Missing: title, items
```

**Response**:
```json
{
  "success": false,
  "authenticated": true,
  "code": 400,
  "message": "title is required",
  "data": null
}
```

**Fix**: Add missing required parameters

---

#### 2. Invalid JSON in Items

**Request**:
```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -d "action=ticket-create" \
  -d "title=Test" \
  -d "description=Test" \
  -d 'items={"invalid json'
  # Malformed JSON
```

**Response**:
```json
{
  "success": false,
  "authenticated": true,
  "code": 400,
  "message": "Invalid JSON format for items: Syntax error",
  "data": null
}
```

**Fix**: Validate JSON before sending. Use tools like `jq` or online JSON validators

---

#### 3. Invalid Status Transition

**Request**:
```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer token" \
  -d "action=ticket-update" \
  -d "ticket_id=1" \
  -d "status=completed"
  # Assuming ticket is in 'draft' status
```

**Response**:
```json
{
  "success": false,
  "authenticated": true,
  "code": 400,
  "message": "Validation failed",
  "data": {
    "errors": [
      "Can only complete deployed tickets"
    ]
  }
}
```

**Fix**: Follow the proper status workflow

---

#### 4. Permission Denied

**Request**:
```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer token" \
  -d "action=ticket-create"
  # User doesn't have ticket.create permission
```

**Response**:
```json
{
  "success": false,
  "authenticated": true,
  "code": 403,
  "message": "Permission denied: ticket.create required",
  "data": null
}
```

**Fix**: Request admin to grant required ACL permission

---

#### 5. Token Expired

**Request**:
```bash
curl -H "Authorization: Bearer OLD_TOKEN_FROM_24H_AGO" \
  "http://localhost:8000/api/api.php?action=ticket-list"
```

**Response**:
```json
{
  "success": false,
  "authenticated": false,
  "code": 401,
  "message": "Unauthorized: JWT token expired",
  "data": null
}
```

**Fix**: Re-authenticate with login endpoint to get new token

---

#### 6. Database Error

**Request**:
```bash
# Some valid request that hits a DB error
curl -X POST "http://localhost:8000/api/api.php" \
  -d "action=ticket-create" \
  -d "title=Test" \
  -d "description=Test" \
  -d 'items=[]'
```

**Response**:
```json
{
  "success": false,
  "authenticated": true,
  "code": 500,
  "message": "Failed to create ticket",
  "data": {
    "error": "Database connection failed"
  }
}
```

**Fix**: Check server logs, verify DB connection, restart service

---

### Debugging Tips

1. **Check Logs**: Server logs in `error_log()` contain stack traces
2. **Verify Token**: Ensure JWT token not expired (24h validity)
3. **Test Permissions**: Use `ticket.manage` permission to bypass restrictions during testing
4. **Validate JSON**: Use `jq` or online tools to validate JSON before sending
5. **Use Postman**: Import collection, set authorization, test endpoints interactively
6. **Enable Debug Endpoint**: `ticket-debug` endpoint echoes received data

---

## Examples

### Complete Workflow: Create → Approve → Deploy → Complete

#### Step 1: Create Ticket (Creator)

```bash
# Login as creator
TOKEN=$(curl -s -X POST "http://localhost:8000/api/api.php" \
  -d "action=auth-login" \
  -d "username=creator_user" \
  -d "password=password" | jq -r '.data.tokens.access_token')

# Create ticket
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=ticket-create" \
  -d "title=Production Server Upgrade" \
  -d "description=Upgrade prod-server-01 to new specs" \
  -d "priority=high" \
  -d 'items=[
    {
      "component_type":"cpu",
      "component_uuid":"550e8400-e29b-41d4-a716-446655440001",
      "quantity":2,
      "action":"add"
    }
  ]' | jq '.'
```

**Response**:
```json
{
  "success": true,
  "code": 201,
  "data": {
    "ticket_id": 1,
    "ticket_number": "TKT-20251206-0001",
    "ticket": {
      "id": 1,
      "status": "draft",
      "created_by": 1
    }
  }
}
```

#### Step 2: Submit for Approval (Creator)

```bash
# Same creator submits ticket
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=ticket-update" \
  -d "ticket_id=1" \
  -d "status=pending" | jq '.'
```

**Response**:
```json
{
  "success": true,
  "code": 200,
  "data": {
    "ticket": {
      "id": 1,
      "status": "pending",
      "submitted_at": "2025-12-06 12:05:00"
    }
  }
}
```

#### Step 3: Approve Ticket (Approver)

```bash
# Login as approver
APPROVER_TOKEN=$(curl -s -X POST "http://localhost:8000/api/api.php" \
  -d "action=auth-login" \
  -d "username=approver_user" \
  -d "password=password" | jq -r '.data.tokens.access_token')

# Approve ticket
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer $APPROVER_TOKEN" \
  -d "action=ticket-update" \
  -d "ticket_id=1" \
  -d "status=approved" | jq '.'
```

**Response**:
```json
{
  "success": true,
  "code": 200,
  "data": {
    "ticket": {
      "id": 1,
      "status": "approved",
      "approved_at": "2025-12-06 13:30:00"
    }
  }
}
```

#### Step 4: Assign to Technician (Approver/Manager)

```bash
# Assign to technician
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer $APPROVER_TOKEN" \
  -d "action=ticket-update" \
  -d "ticket_id=1" \
  -d "assigned_to=3" | jq '.'
```

**Response**:
```json
{
  "success": true,
  "code": 200,
  "data": {
    "ticket": {
      "id": 1,
      "assigned_to": 3,
      "assigned_to_username": "tech_user"
    }
  }
}
```

#### Step 5: Start Work (Technician)

```bash
# Login as technician
TECH_TOKEN=$(curl -s -X POST "http://localhost:8000/api/api.php" \
  -d "action=auth-login" \
  -d "username=tech_user" \
  -d "password=password" | jq -r '.data.tokens.access_token')

# Start work
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer $TECH_TOKEN" \
  -d "action=ticket-update" \
  -d "ticket_id=1" \
  -d "status=in_progress" | jq '.'
```

**Response**:
```json
{
  "success": true,
  "code": 200,
  "data": {
    "ticket": {
      "id": 1,
      "status": "in_progress"
    }
  }
}
```

#### Step 6: Deploy Changes (Technician)

```bash
# Deploy
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer $TECH_TOKEN" \
  -d "action=ticket-update" \
  -d "ticket_id=1" \
  -d "status=deployed" \
  -d "deployment_notes=Deployed to prod at 2025-12-06 15:00:00 UTC. All tests passed." | jq '.'
```

**Response**:
```json
{
  "success": true,
  "code": 200,
  "data": {
    "ticket": {
      "id": 1,
      "status": "deployed",
      "deployed_at": "2025-12-06 15:00:00",
      "deployment_notes": "Deployed to prod..."
    }
  }
}
```

#### Step 7: Complete Ticket (Technician)

```bash
# Complete
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer $TECH_TOKEN" \
  -d "action=ticket-update" \
  -d "ticket_id=1" \
  -d "status=completed" \
  -d "completion_notes=Upgrade successful. New CPU and RAM installed and tested. Server performance improved by 45%." | jq '.'
```

**Response**:
```json
{
  "success": true,
  "code": 200,
  "data": {
    "ticket": {
      "id": 1,
      "status": "completed",
      "completed_at": "2025-12-06 15:45:00",
      "completion_notes": "Upgrade successful..."
    }
  }
}
```

#### Step 8: Review Complete Ticket with History

```bash
# Any user with view permission can see final ticket
curl -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/api/api.php?action=ticket-get&ticket_id=1&include_history=true" | jq '.'
```

**Response**:
```json
{
  "success": true,
  "code": 200,
  "data": {
    "ticket": {
      "id": 1,
      "ticket_number": "TKT-20251206-0001",
      "status": "completed",
      "created_at": "2025-12-06 12:00:00",
      "submitted_at": "2025-12-06 12:05:00",
      "approved_at": "2025-12-06 13:30:00",
      "deployed_at": "2025-12-06 15:00:00",
      "completed_at": "2025-12-06 15:45:00",
      "completion_notes": "Upgrade successful. New CPU and RAM installed and tested. Server performance improved by 45%.",
      "history": [
        {
          "action": "created",
          "changed_by_username": "creator_user",
          "created_at": "2025-12-06 12:00:00"
        },
        {
          "action": "submitted",
          "old_value": "status=draft",
          "new_value": "status=pending",
          "changed_by_username": "creator_user",
          "created_at": "2025-12-06 12:05:00"
        },
        {
          "action": "approved",
          "old_value": "status=pending",
          "new_value": "status=approved",
          "changed_by_username": "approver_user",
          "created_at": "2025-12-06 13:30:00"
        },
        {
          "action": "assigned",
          "old_value": "assigned_to=null",
          "new_value": "assigned_to=3",
          "changed_by_username": "approver_user",
          "created_at": "2025-12-06 13:31:00"
        },
        {
          "action": "status_change",
          "old_value": "status=approved",
          "new_value": "status=in_progress",
          "changed_by_username": "tech_user",
          "created_at": "2025-12-06 14:00:00"
        },
        {
          "action": "deployed",
          "old_value": "status=in_progress",
          "new_value": "status=deployed",
          "changed_by_username": "tech_user",
          "created_at": "2025-12-06 15:00:00",
          "notes": "Deployed to prod at 2025-12-06 15:00:00 UTC. All tests passed."
        },
        {
          "action": "completed",
          "old_value": "status=deployed",
          "new_value": "status=completed",
          "changed_by_username": "tech_user",
          "created_at": "2025-12-06 15:45:00",
          "notes": "Upgrade successful. New CPU and RAM installed and tested. Server performance improved by 45%."
        }
      ]
    }
  }
}
```

### Rejection Workflow Example

```bash
# Approver rejects ticket with reason
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer $APPROVER_TOKEN" \
  -d "action=ticket-update" \
  -d "ticket_id=2" \
  -d "status=rejected" \
  -d "rejection_reason=Requires architectural review before CAB can approve. Please update design document and resubmit." | jq '.'
```

**Response**:
```json
{
  "success": true,
  "code": 200,
  "data": {
    "ticket": {
      "id": 2,
      "ticket_number": "TKT-20251206-0002",
      "status": "rejected",
      "rejection_reason": "Requires architectural review before CAB can approve. Please update design document and resubmit."
    }
  }
}
```

### Filtering Examples

#### Example 1: List All High Priority Pending Tickets

```bash
curl -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/api/api.php?action=ticket-list&status=pending&priority=high" | jq '.'
```

#### Example 2: Search and Date Range

```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=ticket-list" \
  -d "search=CPU" \
  -d "created_from=2025-12-01" \
  -d "created_to=2025-12-06" \
  -d "order_by=priority" \
  -d "order_dir=ASC" | jq '.'
```

#### Example 3: My Assigned Tickets

```bash
curl -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/api/api.php?action=ticket-list&assigned_to=3&status=in_progress" | jq '.'
```

---

## Related Documentation

- [API_REFERENCE.md](API_REFERENCE.md) - Complete endpoint catalog
- [DEVELOPMENT_GUIDELINES.md](DEVELOPMENT_GUIDELINES.md) - Coding standards
- [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) - Ticket table structure
- [ARCHITECTURE.md](ARCHITECTURE.md) - System design and request flow

---

**Document Version**: 1.0
**Last Updated**: 2025-12-06
**Stability**: Stable
