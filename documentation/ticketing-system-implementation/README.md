# Ticketing System Implementation Guide

## Table of Contents

1. [System Overview](#1-system-overview)
2. [Database Schema Changes](#2-database-schema-changes)
3. [Backend API Design](#3-backend-api-design)
4. [Frontend Implementation Plan](#4-frontend-implementation-plan)
5. [Feature List](#5-feature-list)
6. [Workflow Diagrams](#6-workflow-diagrams)
7. [Implementation Phases](#7-implementation-phases)
8. [Security Considerations](#8-security-considerations)
9. [API Integration Points](#9-api-integration-points)
10. [Testing Strategy](#10-testing-strategy)

---

## 1. System Overview

### 1.1 Feature Description

The Ticketing System extends the BDC IMS to allow users without direct server modification permissions to request component additions, removals, or modifications through a formal ticketing workflow. This ensures proper authorization, tracking, and auditing of all server configuration changes.

**Key Benefits:**
- Controlled access to server modifications
- Audit trail for all configuration changes
- Approval workflow for change requests
- Status tracking from request to deployment
- Role-based visibility and actions

### 1.2 Workflow Explanation

```
User Request Flow:
1. User creates ticket requesting component addition/removal
2. Ticket enters "pending" state
3. Authorized user reviews and approves/rejects
4. On approval: Components are validated and prepared
5. Technician deploys changes
6. Ticket marked as "deployed" or "completed"

Alternative Flow:
- Ticket can be rejected with comments
- Rejected tickets can be resubmitted with modifications
- Deployed tickets can be marked as completed or rolled back
```

### 1.3 User Roles Involved

**Ticket Requestor (Basic User)**
- Permissions: `ticket.create`, `ticket.view_own`
- Can: Create tickets, view own tickets, comment on own tickets
- Cannot: Approve, modify server configurations directly

**Ticket Approver (Manager/Admin)**
- Permissions: `ticket.view_all`, `ticket.approve`, `ticket.reject`
- Can: View all tickets, approve/reject requests, assign to technicians
- Cannot: Deploy without proper technical access

**Technician**
- Permissions: `ticket.view_assigned`, `ticket.deploy`, `server.edit`
- Can: View assigned tickets, execute approved changes, mark as deployed
- Cannot: Approve own requests (separation of duties)

**System Administrator**
- Permissions: All ticket permissions + `ticket.manage`, `ticket.delete`
- Can: Full control over ticketing system, override workflows, delete tickets

---

## 2. Database Schema Changes

### 2.1 Tickets Table

**Purpose**: Core table storing ticket metadata and request information.

```sql
CREATE TABLE tickets (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_number VARCHAR(20) NOT NULL UNIQUE COMMENT 'Human-readable: TKT-YYYYMMDD-XXXX',

    -- Request Details
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    ticket_type ENUM('component_addition', 'component_removal', 'component_replacement', 'configuration_change') NOT NULL DEFAULT 'component_addition',
    priority ENUM('low', 'normal', 'high', 'critical') NOT NULL DEFAULT 'normal',

    -- Target Server
    target_server_uuid VARCHAR(255) NOT NULL COMMENT 'FK to server_configurations.uuid',
    target_server_name VARCHAR(255) DEFAULT NULL COMMENT 'Cached for display',

    -- Status Tracking
    status ENUM('draft', 'pending', 'approved', 'rejected', 'in_progress', 'deployed', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',

    -- User References
    created_by INT(11) NOT NULL COMMENT 'FK to users.id - Requestor',
    assigned_to INT(11) DEFAULT NULL COMMENT 'FK to users.id - Technician',
    approved_by INT(11) DEFAULT NULL COMMENT 'FK to users.id - Approver',
    reviewed_by INT(11) DEFAULT NULL COMMENT 'FK to users.id - Final reviewer',

    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    submitted_at DATETIME DEFAULT NULL COMMENT 'When status changed from draft to pending',
    approved_at DATETIME DEFAULT NULL,
    rejected_at DATETIME DEFAULT NULL,
    deployed_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,

    -- Additional Fields
    estimated_duration INT(11) DEFAULT NULL COMMENT 'Minutes',
    scheduled_date DATETIME DEFAULT NULL,
    rejection_reason TEXT DEFAULT NULL,
    deployment_notes TEXT DEFAULT NULL,

    -- Indexes
    INDEX idx_ticket_number (ticket_number),
    INDEX idx_target_server (target_server_uuid),
    INDEX idx_status (status),
    INDEX idx_created_by (created_by),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_created_at (created_at),
    INDEX idx_status_created (status, created_at),

    -- Foreign Keys
    FOREIGN KEY (target_server_uuid) REFERENCES server_configurations(uuid) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Ticketing system for server configuration change requests';
```

**Ticket Number Format**: `TKT-20251115-0001`
- Prefix: `TKT-`
- Date: `YYYYMMDD`
- Sequence: 4-digit auto-increment per day

**Status Flow**:
```
draft → pending → approved → in_progress → deployed → completed
                     ↓
                  rejected
```

---

### 2.2 Ticket Items Table

**Purpose**: Junction table linking tickets to requested component changes.

```sql
CREATE TABLE ticket_items (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT(11) NOT NULL COMMENT 'FK to tickets.id',

    -- Component Details
    item_type ENUM('component', 'configuration') NOT NULL DEFAULT 'component',
    action ENUM('add', 'remove', 'replace', 'modify') NOT NULL,
    component_type VARCHAR(50) NOT NULL COMMENT 'cpu, ram, storage, etc.',
    component_uuid VARCHAR(255) DEFAULT NULL COMMENT 'Specific component UUID if selected',
    component_serial VARCHAR(255) DEFAULT NULL COMMENT 'Physical serial number',

    -- Specifications (for new additions without specific UUID)
    specifications JSON DEFAULT NULL COMMENT 'Component specs from JSON if UUID not selected',

    -- Quantity and Details
    quantity INT(11) NOT NULL DEFAULT 1,
    current_value TEXT DEFAULT NULL COMMENT 'For modifications - before state',
    requested_value TEXT DEFAULT NULL COMMENT 'For modifications - after state',

    -- Validation
    validation_status ENUM('pending', 'valid', 'invalid', 'warning') DEFAULT 'pending',
    validation_message TEXT DEFAULT NULL,
    compatibility_checked BOOLEAN DEFAULT FALSE,

    -- Fulfillment
    fulfilled BOOLEAN DEFAULT FALSE,
    fulfilled_at DATETIME DEFAULT NULL,
    fulfilled_component_uuid VARCHAR(255) DEFAULT NULL COMMENT 'Actual component used',
    fulfillment_notes TEXT DEFAULT NULL,

    -- Metadata
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_ticket_id (ticket_id),
    INDEX idx_component_type (component_type),
    INDEX idx_component_uuid (component_uuid),
    INDEX idx_validation_status (validation_status),

    -- Foreign Keys
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Individual items/components requested in tickets';
```

**Example Records**:

*Adding 2 SSDs*:
```json
{
    "ticket_id": 1,
    "action": "add",
    "component_type": "storage",
    "component_uuid": "storage-ssd-samsung-980pro-2tb-uuid",
    "quantity": 2,
    "specifications": {
        "capacity": "2TB",
        "interface": "NVMe",
        "form_factor": "M.2"
    }
}
```

*Replacing onboard NIC*:
```json
{
    "ticket_id": 2,
    "action": "replace",
    "component_type": "nic",
    "current_value": "onboard-nic-uuid-1",
    "requested_value": "intel-x710-4port-uuid",
    "quantity": 1
}
```

---

### 2.3 Ticket History Table

**Purpose**: Audit trail for all ticket changes and status transitions.

```sql
CREATE TABLE ticket_history (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT(11) NOT NULL COMMENT 'FK to tickets.id',

    -- Change Details
    action VARCHAR(50) NOT NULL COMMENT 'status_change, comment_added, assigned, approved, etc.',
    field_changed VARCHAR(100) DEFAULT NULL,
    old_value TEXT DEFAULT NULL,
    new_value TEXT DEFAULT NULL,

    -- Actor
    user_id INT(11) NOT NULL COMMENT 'FK to users.id - Who made the change',
    user_name VARCHAR(100) DEFAULT NULL COMMENT 'Cached username for display',

    -- Context
    comment TEXT DEFAULT NULL,
    metadata JSON DEFAULT NULL COMMENT 'Additional context',

    -- Timestamp
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_ticket_id (ticket_id),
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at),
    INDEX idx_ticket_created (ticket_id, created_at),

    -- Foreign Keys
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Audit trail for ticket changes';
```

**Example History Records**:

```json
// Ticket created
{
    "action": "ticket_created",
    "user_id": 5,
    "comment": "Requesting 2 SSDs for server expansion",
    "metadata": {"ip_address": "192.168.1.100"}
}

// Status changed
{
    "action": "status_change",
    "field_changed": "status",
    "old_value": "pending",
    "new_value": "approved",
    "user_id": 3,
    "comment": "Approved - components available"
}

// Assigned to technician
{
    "action": "assigned",
    "field_changed": "assigned_to",
    "old_value": null,
    "new_value": "7",
    "user_id": 3
}
```

---

### 2.4 Ticket Comments Table

**Purpose**: Discussion thread for tickets between requestors, approvers, and technicians.

```sql
CREATE TABLE ticket_comments (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT(11) NOT NULL COMMENT 'FK to tickets.id',

    -- Comment Content
    comment TEXT NOT NULL,
    comment_type ENUM('comment', 'internal_note', 'system') NOT NULL DEFAULT 'comment',
    is_internal BOOLEAN DEFAULT FALSE COMMENT 'Visible only to staff with ticket.view_internal permission',

    -- Author
    user_id INT(11) NOT NULL COMMENT 'FK to users.id',
    user_name VARCHAR(100) DEFAULT NULL COMMENT 'Cached for display',

    -- Attachments
    has_attachments BOOLEAN DEFAULT FALSE,

    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    edited BOOLEAN DEFAULT FALSE,

    -- Indexes
    INDEX idx_ticket_id (ticket_id),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at),
    INDEX idx_ticket_created (ticket_id, created_at),

    -- Foreign Keys
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Comments and discussions on tickets';
```

**Comment Types**:
- `comment`: Public comment visible to requestor and staff
- `internal_note`: Staff-only notes (requires `ticket.view_internal`)
- `system`: Auto-generated system messages

---

### 2.5 Ticket Attachments Table (Optional)

**Purpose**: Store file attachments (diagrams, specifications, photos).

```sql
CREATE TABLE ticket_attachments (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT(11) NOT NULL COMMENT 'FK to tickets.id',
    comment_id INT(11) DEFAULT NULL COMMENT 'FK to ticket_comments.id if attached to comment',

    -- File Details
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT(11) NOT NULL COMMENT 'Bytes',
    mime_type VARCHAR(100) NOT NULL,

    -- Metadata
    uploaded_by INT(11) NOT NULL COMMENT 'FK to users.id',
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    description TEXT DEFAULT NULL,

    -- Indexes
    INDEX idx_ticket_id (ticket_id),
    INDEX idx_comment_id (comment_id),
    INDEX idx_uploaded_by (uploaded_by),

    -- Foreign Keys
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (comment_id) REFERENCES ticket_comments(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='File attachments for tickets';
```

**Storage Path**: `uploads/tickets/{ticket_id}/{hashed_filename}`

**Allowed Types**: PDF, PNG, JPG, JPEG, DOC, DOCX, XLS, XLSX (configurable)

**Max Size**: 10MB per file (configurable)

---

### 2.6 Ticket Notifications Table (Optional - Phase 4)

**Purpose**: Email/in-app notification queue for ticket events.

```sql
CREATE TABLE ticket_notifications (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT(11) NOT NULL COMMENT 'FK to tickets.id',

    -- Notification Details
    notification_type VARCHAR(50) NOT NULL COMMENT 'ticket_created, approved, rejected, etc.',
    recipient_user_id INT(11) NOT NULL COMMENT 'FK to users.id',
    recipient_email VARCHAR(100) DEFAULT NULL,

    -- Content
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,

    -- Delivery Status
    status ENUM('pending', 'sent', 'failed', 'read') DEFAULT 'pending',
    sent_at DATETIME DEFAULT NULL,
    read_at DATETIME DEFAULT NULL,
    error_message TEXT DEFAULT NULL,

    -- Metadata
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_ticket_id (ticket_id),
    INDEX idx_recipient (recipient_user_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),

    -- Foreign Keys
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Notification queue for ticket events';
```

---

### 2.7 Migration Script

**File**: `migrations/add_ticketing_system.sql`

```sql
-- ============================================
-- BDC IMS Ticketing System Migration
-- Version: 1.0
-- Created: 2025-11-15
-- ============================================

START TRANSACTION;

-- 1. Create tickets table
CREATE TABLE IF NOT EXISTS tickets (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_number VARCHAR(20) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    ticket_type ENUM('component_addition', 'component_removal', 'component_replacement', 'configuration_change') NOT NULL DEFAULT 'component_addition',
    priority ENUM('low', 'normal', 'high', 'critical') NOT NULL DEFAULT 'normal',
    target_server_uuid VARCHAR(255) NOT NULL,
    target_server_name VARCHAR(255) DEFAULT NULL,
    status ENUM('draft', 'pending', 'approved', 'rejected', 'in_progress', 'deployed', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    created_by INT(11) NOT NULL,
    assigned_to INT(11) DEFAULT NULL,
    approved_by INT(11) DEFAULT NULL,
    reviewed_by INT(11) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    submitted_at DATETIME DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    rejected_at DATETIME DEFAULT NULL,
    deployed_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    estimated_duration INT(11) DEFAULT NULL,
    scheduled_date DATETIME DEFAULT NULL,
    rejection_reason TEXT DEFAULT NULL,
    deployment_notes TEXT DEFAULT NULL,
    INDEX idx_ticket_number (ticket_number),
    INDEX idx_target_server (target_server_uuid),
    INDEX idx_status (status),
    INDEX idx_created_by (created_by),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_created_at (created_at),
    INDEX idx_status_created (status, created_at),
    FOREIGN KEY (target_server_uuid) REFERENCES server_configurations(uuid) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Create ticket_items table
CREATE TABLE IF NOT EXISTS ticket_items (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT(11) NOT NULL,
    item_type ENUM('component', 'configuration') NOT NULL DEFAULT 'component',
    action ENUM('add', 'remove', 'replace', 'modify') NOT NULL,
    component_type VARCHAR(50) NOT NULL,
    component_uuid VARCHAR(255) DEFAULT NULL,
    component_serial VARCHAR(255) DEFAULT NULL,
    specifications JSON DEFAULT NULL,
    quantity INT(11) NOT NULL DEFAULT 1,
    current_value TEXT DEFAULT NULL,
    requested_value TEXT DEFAULT NULL,
    validation_status ENUM('pending', 'valid', 'invalid', 'warning') DEFAULT 'pending',
    validation_message TEXT DEFAULT NULL,
    compatibility_checked BOOLEAN DEFAULT FALSE,
    fulfilled BOOLEAN DEFAULT FALSE,
    fulfilled_at DATETIME DEFAULT NULL,
    fulfilled_component_uuid VARCHAR(255) DEFAULT NULL,
    fulfillment_notes TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ticket_id (ticket_id),
    INDEX idx_component_type (component_type),
    INDEX idx_component_uuid (component_uuid),
    INDEX idx_validation_status (validation_status),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Create ticket_history table
CREATE TABLE IF NOT EXISTS ticket_history (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT(11) NOT NULL,
    action VARCHAR(50) NOT NULL,
    field_changed VARCHAR(100) DEFAULT NULL,
    old_value TEXT DEFAULT NULL,
    new_value TEXT DEFAULT NULL,
    user_id INT(11) NOT NULL,
    user_name VARCHAR(100) DEFAULT NULL,
    comment TEXT DEFAULT NULL,
    metadata JSON DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ticket_id (ticket_id),
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at),
    INDEX idx_ticket_created (ticket_id, created_at),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Create ticket_comments table
CREATE TABLE IF NOT EXISTS ticket_comments (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT(11) NOT NULL,
    comment TEXT NOT NULL,
    comment_type ENUM('comment', 'internal_note', 'system') NOT NULL DEFAULT 'comment',
    is_internal BOOLEAN DEFAULT FALSE,
    user_id INT(11) NOT NULL,
    user_name VARCHAR(100) DEFAULT NULL,
    has_attachments BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    edited BOOLEAN DEFAULT FALSE,
    INDEX idx_ticket_id (ticket_id),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at),
    INDEX idx_ticket_created (ticket_id, created_at),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Create ticket_attachments table (optional)
CREATE TABLE IF NOT EXISTS ticket_attachments (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT(11) NOT NULL,
    comment_id INT(11) DEFAULT NULL,
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT(11) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    uploaded_by INT(11) NOT NULL,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    description TEXT DEFAULT NULL,
    INDEX idx_ticket_id (ticket_id),
    INDEX idx_comment_id (comment_id),
    INDEX idx_uploaded_by (uploaded_by),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (comment_id) REFERENCES ticket_comments(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Create ticket_notifications table (optional - Phase 4)
CREATE TABLE IF NOT EXISTS ticket_notifications (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT(11) NOT NULL,
    notification_type VARCHAR(50) NOT NULL,
    recipient_user_id INT(11) NOT NULL,
    recipient_email VARCHAR(100) DEFAULT NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('pending', 'sent', 'failed', 'read') DEFAULT 'pending',
    sent_at DATETIME DEFAULT NULL,
    read_at DATETIME DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ticket_id (ticket_id),
    INDEX idx_recipient (recipient_user_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;

-- ============================================
-- Add ticketing permissions to ACL system
-- ============================================

START TRANSACTION;

-- Insert ticketing permissions
INSERT IGNORE INTO permissions (name, display_name, description, category, is_basic) VALUES
-- Basic ticket permissions
('ticket.view_own', 'View Own Tickets', 'View tickets created by user', 'ticketing', TRUE),
('ticket.create', 'Create Tickets', 'Create new ticket requests', 'ticketing', TRUE),
('ticket.edit_own', 'Edit Own Tickets', 'Edit own tickets (draft status only)', 'ticketing', TRUE),
('ticket.comment', 'Comment on Tickets', 'Add comments to accessible tickets', 'ticketing', TRUE),

-- Advanced ticket permissions
('ticket.view_all', 'View All Tickets', 'View all tickets in system', 'ticketing', FALSE),
('ticket.view_assigned', 'View Assigned Tickets', 'View tickets assigned to user', 'ticketing', FALSE),
('ticket.approve', 'Approve Tickets', 'Approve ticket requests', 'ticketing', FALSE),
('ticket.reject', 'Reject Tickets', 'Reject ticket requests', 'ticketing', FALSE),
('ticket.assign', 'Assign Tickets', 'Assign tickets to technicians', 'ticketing', FALSE),
('ticket.deploy', 'Deploy Tickets', 'Mark tickets as deployed', 'ticketing', FALSE),
('ticket.complete', 'Complete Tickets', 'Mark tickets as completed', 'ticketing', FALSE),
('ticket.cancel', 'Cancel Tickets', 'Cancel tickets', 'ticketing', FALSE),
('ticket.delete', 'Delete Tickets', 'Permanently delete tickets', 'ticketing', FALSE),
('ticket.manage', 'Manage Tickets', 'Full ticket management access', 'ticketing', FALSE),
('ticket.view_internal', 'View Internal Notes', 'View internal staff notes', 'ticketing', FALSE);

COMMIT;

-- ============================================
-- Assign ticket permissions to default roles
-- ============================================

START TRANSACTION;

-- Get role IDs
SET @admin_role_id = (SELECT id FROM roles WHERE name = 'admin' LIMIT 1);
SET @manager_role_id = (SELECT id FROM roles WHERE name = 'manager' LIMIT 1);
SET @technician_role_id = (SELECT id FROM roles WHERE name = 'technician' LIMIT 1);
SET @viewer_role_id = (SELECT id FROM roles WHERE name = 'viewer' LIMIT 1);

-- Admin: All ticket permissions
INSERT IGNORE INTO role_permissions (role_id, permission_id, granted)
SELECT @admin_role_id, id, 1 FROM permissions WHERE category = 'ticketing';

-- Manager: View, approve, reject, assign
INSERT IGNORE INTO role_permissions (role_id, permission_id, granted)
SELECT @manager_role_id, id, 1 FROM permissions
WHERE name IN (
    'ticket.view_all', 'ticket.view_own', 'ticket.create', 'ticket.edit_own',
    'ticket.approve', 'ticket.reject', 'ticket.assign', 'ticket.comment',
    'ticket.view_internal', 'ticket.cancel'
);

-- Technician: View assigned, deploy, complete
INSERT IGNORE INTO role_permissions (role_id, permission_id, granted)
SELECT @technician_role_id, id, 1 FROM permissions
WHERE name IN (
    'ticket.view_assigned', 'ticket.view_own', 'ticket.create', 'ticket.edit_own',
    'ticket.deploy', 'ticket.complete', 'ticket.comment'
);

-- Viewer: Basic permissions only
INSERT IGNORE INTO role_permissions (role_id, permission_id, granted)
SELECT @viewer_role_id, id, 1 FROM permissions
WHERE name IN ('ticket.view_own', 'ticket.create', 'ticket.edit_own', 'ticket.comment');

COMMIT;
```

---

## 3. Backend API Design

### 3.1 API Endpoints

All ticketing endpoints follow the action-based routing pattern: `ticket-{operation}`

#### Core Endpoints Summary

| Action | Method | Permission | Description |
|--------|--------|------------|-------------|
| `ticket-create` | POST | `ticket.create` | Create new ticket |
| `ticket-list` | GET | `ticket.view_own/all/assigned` | List tickets |
| `ticket-get` | GET | Based on ownership | Get ticket details |
| `ticket-update` | POST | `ticket.edit_own/manage` | Update ticket |
| `ticket-approve` | POST | `ticket.approve` | Approve ticket |
| `ticket-reject` | POST | `ticket.reject` | Reject ticket |
| `ticket-assign` | POST | `ticket.assign` | Assign to technician |
| `ticket-deploy` | POST | `ticket.deploy` | Deploy changes |
| `ticket-complete` | POST | `ticket.complete` | Mark as completed |
| `ticket-cancel` | POST | `ticket.cancel/manage` | Cancel ticket |
| `ticket-add-comment` | POST | `ticket.comment` | Add comment |
| `ticket-validate-items` | POST | `ticket.create` | Validate components |

#### Example: Create Ticket

**Request**:
```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=ticket-create" \
  -d "title=Add 2 Samsung 980 Pro SSDs" \
  -d "description=Need additional storage for database expansion" \
  -d "ticket_type=component_addition" \
  -d "priority=normal" \
  -d "target_server_uuid=server-uuid-123" \
  -d 'items=[{"action":"add","component_type":"storage","component_uuid":"storage-ssd-samsung-980pro-2tb","quantity":2}]'
```

**Response**:
```json
{
    "success": true,
    "authenticated": true,
    "code": 201,
    "message": "Ticket created successfully",
    "timestamp": "2025-11-15 14:30:00",
    "data": {
        "ticket_id": 1,
        "ticket_number": "TKT-20251115-0001",
        "status": "pending",
        "validation": {
            "items_validated": true,
            "compatibility_checked": true,
            "warnings": []
        }
    }
}
```

---

## 4. Frontend Implementation Plan

### 4.1 Page Layouts

**Ticket List Page** (`/tickets`):
```
+----------------------------------------------------------+
| Tickets                                     [Create New] |
+----------------------------------------------------------+
| Filters: [Status ▼] [Priority ▼] [Assigned ▼] [Search] |
+----------------------------------------------------------+
| Ticket #        | Title         | Status   | Priority   |
|-----------------|---------------|----------|------------|
| TKT-...-0001   | Add 2 SSDs    | Pending  | Normal     |
| TKT-...-0002   | Replace NIC   | Approved | High       |
+----------------------------------------------------------+
```

**Ticket Detail Page**:
```
+----------------------------------------------------------+
| TKT-20251115-0001                    [Edit] [Cancel]    |
+----------------------------------------------------------+
| Add 2 Samsung 980 Pro SSDs                              |
| Status: Pending            Priority: Normal             |
+----------------------------------------------------------+
| Requested Components:                                   |
| - Storage: Samsung 980 Pro 2TB x2 (NVMe, M.2) ✓        |
+----------------------------------------------------------+
| [Approve] [Reject] [Assign]                            |
+----------------------------------------------------------+
| Comments & History                                      |
+----------------------------------------------------------+
```

---

## 5. Feature List

### Core Features
- ✅ Create ticket with components
- ✅ List/filter tickets by status
- ✅ View ticket details
- ✅ Approve/reject workflow
- ✅ Assign to technicians
- ✅ Deploy components
- ✅ Comment system
- ✅ History tracking

### Optional Features (Phase 2+)
- 📧 Email notifications
- 📎 File attachments
- 📊 Analytics dashboard
- 🔔 In-app notifications

---

## 6. Workflow Diagrams

### Complete Ticket Lifecycle

```
User (Requestor)
    ↓
[Create Ticket] → ticket-create API
    ↓
tickets table (status: pending)
    ↓
Manager/Admin reviews
    ↓
[Approve] → ticket-approve API ──OR── [Reject] → ticket-reject
    ↓                                       ↓
status: approved                     status: rejected
    ↓                                       ↓
[Assign to Tech]                     [End] or [Resubmit]
    ↓
Technician
    ↓
[Deploy] → ticket-deploy API
    ↓
Calls server-add-component
    ↓
Updates inventory status
    ↓
status: deployed
    ↓
[Complete] → ticket-complete API
    ↓
status: completed
```

---

## 7. Implementation Phases

### Phase 1: Core Ticketing (Weeks 1-2)
**Goal**: Basic CRUD operations

**Tasks**:
1. Create database tables
2. Add ACL permissions
3. Implement TicketManager class
4. Create API endpoints (create, list, get, update)
5. Build frontend pages (list, detail, create)

**Deliverables**: Users can create, view, and list tickets

---

### Phase 2: Approval Workflow (Weeks 3-4)
**Goal**: Implement approval process

**Tasks**:
1. Implement approve/reject endpoints
2. Add assignment functionality
3. Create comment system
4. Build approval UI

**Deliverables**: Full workflow from creation to approval

---

### Phase 3: Deployment Integration (Weeks 5-6)
**Goal**: Connect with server APIs

**Tasks**:
1. Implement deploy endpoint
2. Integrate with ServerBuilder
3. Update component status
4. Add validation logic

**Deliverables**: Tickets can be deployed to servers

---

### Phase 4: Enhancements (Weeks 7-8)
**Goal**: Polish and optimize

**Tasks**:
1. Email notifications
2. File attachments
3. Dashboard/analytics
4. Performance optimization

**Deliverables**: Production-ready system

---

## 8. Security Considerations

### Permission Validation
- Every endpoint checks ACL permissions
- Users can only view own tickets (unless have `view_all`)
- Cannot approve own tickets (separation of duties)

### Audit Logging
```php
function logTicketAction($ticketId, $userId, $action, $comment = null) {
    $stmt = $pdo->prepare("
        INSERT INTO ticket_history
        (ticket_id, action, user_id, user_name, comment, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    // Get username and execute
}
```

### Input Validation
- Sanitize all strings
- Validate enums against allowed values
- Check foreign keys exist
- Use prepared statements

---

## 9. API Integration Points

### Server Builder Integration

```php
class TicketManager {
    private $serverBuilder;

    public function deployTicket($ticketId, $userId) {
        $ticket = $this->loadTicket($ticketId);
        $items = $this->getTicketItems($ticketId);

        foreach ($items as $item) {
            if ($item['action'] === 'add') {
                $result = $this->serverBuilder->addComponent(
                    $ticket['target_server_uuid'],
                    $item['component_type'],
                    $item['component_uuid'],
                    $item['quantity']
                );

                if ($result['success']) {
                    $this->fulfillTicketItem($item['id'], $userId);
                }
            }
        }

        $this->updateTicketStatus($ticketId, 'deployed', $userId);
    }
}
```

### Component Validation Integration

```php
require_once(__DIR__ . '/ComponentDataService.php');
require_once(__DIR__ . '/FlexibleCompatibilityValidator.php');

function validateTicketItems($ticketId) {
    $componentService = ComponentDataService::getInstance();
    $validator = new FlexibleCompatibilityValidator($pdo);

    // Validate UUID exists
    $uuidValid = $componentService->validateComponentUuid(
        $item['component_type'],
        $item['component_uuid']
    );

    // Check compatibility
    $compatResult = $validator->validateComponent(
        $serverConfig,
        $item['component_type'],
        $item['component_uuid']
    );
}
```

---

## 10. Testing Strategy

### Unit Tests

```php
class TicketManagerTest extends PHPUnit\Framework\TestCase {
    public function testCreateTicket() {
        $data = [
            'title' => 'Test Ticket',
            'target_server_uuid' => 'server-test-uuid',
            'items' => [/*...*/]
        ];

        $result = $this->ticketManager->createTicket(1, $data);

        $this->assertNotNull($result['ticket_id']);
        $this->assertMatchesRegularExpression('/^TKT-\d{8}-\d{4}$/', $result['ticket_number']);
    }
}
```

### API Tests

```bash
# Create ticket
curl -X POST "$API_URL" \
  -H "Authorization: Bearer $TOKEN" \
  -d "action=ticket-create" \
  -d "title=Test Ticket" \
  -d "target_server_uuid=server-123"

# Approve ticket
curl -X POST "$API_URL" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -d "action=ticket-approve" \
  -d "ticket_id=1"
```

---

## Conclusion

This comprehensive guide provides a complete blueprint for implementing a production-ready ticketing system in the BDC IMS. The system follows existing architecture patterns, integrates seamlessly with current APIs, and provides robust security and audit capabilities.

**Implementation Timeline**: 8 weeks (4 phases)

**Next Steps**:
1. Review and approve this design
2. Run database migration script
3. Begin Phase 1 implementation
4. Deploy to staging for testing
5. Roll out to production

---

**Last Updated**: 2025-11-15
**Version**: 1.0
**Author**: Claude Code
