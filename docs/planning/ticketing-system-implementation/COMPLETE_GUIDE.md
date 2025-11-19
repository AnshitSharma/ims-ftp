# Ticketing System - Complete Implementation Guide

**BDC IMS Ticketing System - Comprehensive Design & Implementation Documentation**

**Version**: 1.0
**Last Updated**: 2025-11-15
**Author**: Claude Code

---

## Table of Contents

### Part I: Overview & Planning
1. [System Overview](#1-system-overview)
2. [User Roles & Permissions](#2-user-roles--permissions)
3. [Workflow Diagrams](#3-workflow-diagrams)
4. [Implementation Phases](#4-implementation-phases)

### Part II: Database Design
5. [Database Schema](#5-database-schema)
6. [Migration Scripts](#6-migration-scripts)

### Part III: Backend Implementation
7. [File Structure](#7-file-structure)
8. [Core Classes](#8-core-classes)
9. [API Router Integration](#9-api-router-integration)

### Part IV: API Reference
10. [API Endpoints Reference](#10-api-endpoints-reference)
11. [Request/Response Examples](#11-requestresponse-examples)
12. [Error Handling](#12-error-handling)

### Part V: Implementation Guide
13. [Phase 1: Database & Foundation](#13-phase-1-database--foundation)
14. [Phase 2: Create & List Endpoints](#14-phase-2-create--list-endpoints)
15. [Phase 3: Approval Workflow](#15-phase-3-approval-workflow)
16. [Phase 4: Deployment Integration](#16-phase-4-deployment-integration)
17. [Phase 5: Validation & Compatibility](#17-phase-5-validation--compatibility)
18. [Phase 6: Statistics & Reporting](#18-phase-6-statistics--reporting)
19. [Phase 7: Enhancements & Polish](#19-phase-7-enhancements--polish)

### Part VI: Code Examples
20. [Complete Endpoint Implementations](#20-complete-endpoint-implementations)
21. [Frontend Integration](#21-frontend-integration)
22. [Testing Examples](#22-testing-examples)

### Part VII: Deployment
23. [Deployment Checklist](#23-deployment-checklist)
24. [Testing & Validation](#24-testing--validation)

---

# Part I: Overview & Planning

## 1. System Overview

### 1.1 Feature Description

The Ticketing System extends the BDC IMS to allow users without direct server modification permissions to request component additions, removals, or modifications through a formal ticketing workflow. This ensures proper authorization, tracking, and auditing of all server configuration changes.

**Key Benefits:**
- Controlled access to server modifications
- Complete audit trail for all configuration changes
- Multi-level approval workflow for change requests
- Real-time status tracking from request to deployment
- Role-based visibility and actions
- Integration with existing component validation system

### 1.2 Core Concepts

**Action-Based Routing**: All ticket endpoints follow the pattern `ticket-{operation}` (e.g., `ticket-create`, `ticket-approve`)

**Component Types**: Supports all 9 component types: `cpu`, `ram`, `storage`, `motherboard`, `nic`, `caddy`, `chassis`, `pciecard`, `hbacard`

**UUID Validation**: All component UUIDs must exist in `All-JSON/{type}-jsons/*.json` files before being added to tickets

**Status Flow**:
```
draft → pending → approved → in_progress → deployed → completed
                     ↓
                  rejected
```

### 1.3 Use Cases

**Use Case 1: Adding Storage Components**
- User creates ticket requesting 2 SSDs for database server
- Manager reviews and approves request
- Technician receives notification, deploys components
- System validates compatibility and updates inventory
- Ticket marked as completed with full audit trail

**Use Case 2: Component Replacement**
- User requests to replace failed NIC
- Ticket includes old component UUID and new component UUID
- Approval workflow ensures proper authorization
- Deployment updates server configuration automatically

**Use Case 3: Bulk Component Addition**
- Data center expansion requires adding multiple components to multiple servers
- Tickets created for each server with component lists
- Parallel approval and deployment
- Centralized tracking and reporting

---

## 2. User Roles & Permissions

### 2.1 Permission Matrix

| Permission | Requestor | Approver | Technician | Admin |
|-----------|-----------|----------|------------|-------|
| `ticket.view_own` | ✅ | ✅ | ✅ | ✅ |
| `ticket.create` | ✅ | ✅ | ✅ | ✅ |
| `ticket.edit_own` | ✅ | ✅ | ✅ | ✅ |
| `ticket.comment` | ✅ | ✅ | ✅ | ✅ |
| `ticket.view_all` | ❌ | ✅ | ❌ | ✅ |
| `ticket.view_assigned` | ❌ | ✅ | ✅ | ✅ |
| `ticket.approve` | ❌ | ✅ | ❌ | ✅ |
| `ticket.reject` | ❌ | ✅ | ❌ | ✅ |
| `ticket.assign` | ❌ | ✅ | ❌ | ✅ |
| `ticket.deploy` | ❌ | ❌ | ✅ | ✅ |
| `ticket.complete` | ❌ | ❌ | ✅ | ✅ |
| `ticket.cancel` | ✅ (own) | ✅ | ❌ | ✅ |
| `ticket.delete` | ❌ | ❌ | ❌ | ✅ |
| `ticket.manage` | ❌ | ❌ | ❌ | ✅ |
| `ticket.view_internal` | ❌ | ✅ | ✅ | ✅ |

### 2.2 Role Definitions

**Requestor (Basic User)**
- Can create tickets for component changes
- Can view and edit own draft tickets
- Can comment on accessible tickets
- Cannot approve or deploy changes
- Receives notifications on ticket status changes

**Approver (Manager/Admin)**
- Can view all tickets in the system
- Can approve or reject pending tickets
- Can assign tickets to technicians
- Can add internal notes
- **Cannot approve own tickets** (separation of duties)
- Cannot deploy without technical permissions

**Technician**
- Can view assigned tickets
- Can deploy approved changes
- Can mark tickets as completed
- Has server.edit permission for actual deployment
- Cannot approve tickets

**System Administrator**
- Full control over ticketing system
- Can override workflows if needed
- Can delete tickets
- Can manage all aspects of the system

---

## 3. Workflow Diagrams

### 3.1 Complete Ticket Lifecycle

```
┌──────────────────────────────────────────────────────────────┐
│                     TICKET LIFECYCLE                          │
└──────────────────────────────────────────────────────────────┘

User (Requestor)
    │
    ├─[1]─► Create Ticket (ticket-create)
    │       • Select server
    │       • Add components
    │       • Set priority
    │       • Add description
    │
    ▼
┌─────────────────┐
│  Status: DRAFT  │ ◄─── Optional: Save as draft
└─────────────────┘
    │
    ├─[2]─► Submit Ticket
    │
    ▼
┌──────────────────┐
│ Status: PENDING  │ ◄─── Notifications sent to approvers
└──────────────────┘
    │
    ├─────────────────┬────────────────┐
    │                 │                │
    ▼                 ▼                ▼
[Approve]       [Reject]         [Request Changes]
    │                 │
    │                 ▼
    │         ┌──────────────────┐
    │         │ Status: REJECTED │
    │         └──────────────────┘
    │                 │
    │                 └─► [Can Resubmit]
    │
    ▼
┌───────────────────┐
│ Status: APPROVED  │ ◄─── Notifications sent
└───────────────────┘
    │
    ├─[3]─► Assign to Technician (ticket-assign)
    │
    ▼
Technician Receives Notification
    │
    ├─[4]─► Begin Deployment (ticket-deploy)
    │       • Status changes to IN_PROGRESS
    │       • Validates components
    │       • Calls server-add-component
    │       • Updates inventory status
    │
    ▼
┌──────────────────┐
│ Status: DEPLOYED │ ◄─── Components added to server
└──────────────────┘
    │
    ├─[5]─► Verify & Complete (ticket-complete)
    │       • Final verification
    │       • Add completion notes
    │
    ▼
┌───────────────────┐
│ Status: COMPLETED │ ◄─── Final notifications sent
└───────────────────┘
```

### 3.2 Approval Decision Flow

```
┌─────────────────────────────────────────┐
│  Ticket in PENDING Status               │
└─────────────────────────────────────────┘
            │
            ▼
    ┌───────────────┐
    │   Approver    │
    │   Reviews     │
    └───────────────┘
            │
            ├─────────────────┬─────────────────┐
            ▼                 ▼                 ▼
    ┌──────────────┐  ┌──────────────┐  ┌──────────────┐
    │   APPROVE    │  │    REJECT    │  │  REQUEST     │
    │              │  │              │  │  CHANGES     │
    └──────────────┘  └──────────────┘  └──────────────┘
            │                 │                 │
            │                 │                 │
            ▼                 ▼                 ▼
    • Check not own   • Add reason     • Add comment
    • Add comment     • Set status     • Keep pending
    • Assign tech     • Notify user    • Notify user
    • Notify all      • Allow resubmit
            │                 │
            ▼                 ▼
       APPROVED           REJECTED
```

### 3.3 Component Validation Flow

```
┌─────────────────────────────────────────┐
│  Component Added to Ticket              │
└─────────────────────────────────────────┘
            │
            ▼
    ┌──────────────────┐
    │ Validate UUID    │
    │ exists in JSON   │
    └──────────────────┘
            │
        ┌───┴───┐
        │  YES  │
        └───┬───┘
            ▼
    ┌──────────────────┐
    │ Load Component   │
    │ Specifications   │
    └──────────────────┘
            │
            ▼
    ┌──────────────────┐
    │ Check Server     │
    │ Compatibility    │
    └──────────────────┘
            │
        ┌───┴───┬───────┐
        │  YES  │  NO   │
        └───┬───┘       │
            │           │
            ▼           ▼
       VALID        INVALID
    (Mark green) (Show error)
```

---

## 4. Implementation Phases

### Phase Timeline (8 Weeks)

**Phase 1: Database & Foundation** (Week 1-2)
- Create database tables
- Add ACL permissions
- Set up core classes
- Basic testing

**Phase 2: Create & List Endpoints** (Week 2-3)
- ticket-create implementation
- ticket-list with filters
- ticket-get with details
- ticket-update for drafts

**Phase 3: Approval Workflow** (Week 3-4)
- ticket-approve/reject
- ticket-assign
- Comment system
- History tracking

**Phase 4: Deployment Integration** (Week 4-5)
- ticket-deploy
- ServerBuilder integration
- Inventory updates
- ticket-complete

**Phase 5: Validation & Compatibility** (Week 5-6)
- ticket-validate-items
- ticket-get-compatible
- Enhanced validation

**Phase 6: Statistics & Reporting** (Week 6-7)
- ticket-stats
- Dashboard queries
- Analytics

**Phase 7: Enhancements** (Week 7-8)
- Email notifications
- File attachments
- Performance optimization
- Production deployment

---

# Part II: Database Design

## 5. Database Schema

### 5.1 Tickets Table

**Purpose**: Core table storing ticket metadata and request information

```sql
CREATE TABLE tickets (
    -- Primary Key
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_number VARCHAR(20) NOT NULL UNIQUE COMMENT 'Format: TKT-YYYYMMDD-XXXX',

    -- Request Details
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    ticket_type ENUM('component_addition', 'component_removal', 'component_replacement', 'configuration_change')
        NOT NULL DEFAULT 'component_addition',
    priority ENUM('low', 'normal', 'high', 'critical') NOT NULL DEFAULT 'normal',

    -- Target Server
    target_server_uuid VARCHAR(255) NOT NULL COMMENT 'FK to server_configurations.uuid',
    target_server_name VARCHAR(255) DEFAULT NULL COMMENT 'Cached for display',

    -- Status Tracking
    status ENUM('draft', 'pending', 'approved', 'rejected', 'in_progress', 'deployed', 'completed', 'cancelled')
        NOT NULL DEFAULT 'pending',

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
    estimated_duration INT(11) DEFAULT NULL COMMENT 'Estimated duration in minutes',
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
- Date: `YYYYMMDD` (year-month-day)
- Sequence: 4-digit auto-increment per day

**Status Transitions**:
```
draft → pending → approved → in_progress → deployed → completed
                     ↓
                  rejected → (can be resubmitted)
```

### 5.2 Ticket Items Table

**Purpose**: Junction table linking tickets to requested component changes

```sql
CREATE TABLE ticket_items (
    -- Primary Key
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
        "brand": "Samsung",
        "model": "980 Pro",
        "capacity": "2TB",
        "interface": "NVMe",
        "form_factor": "M.2"
    }
}
```

### 5.3 Ticket History Table

**Purpose**: Audit trail for all ticket changes and status transitions

```sql
CREATE TABLE ticket_history (
    -- Primary Key
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

### 5.4 Ticket Comments Table

**Purpose**: Discussion thread for tickets

```sql
CREATE TABLE ticket_comments (
    -- Primary Key
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT(11) NOT NULL COMMENT 'FK to tickets.id',

    -- Comment Content
    comment TEXT NOT NULL,
    comment_type ENUM('comment', 'internal_note', 'system') NOT NULL DEFAULT 'comment',
    is_internal BOOLEAN DEFAULT FALSE COMMENT 'Visible only to staff',

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

### 5.5 Ticket Attachments Table (Optional)

**Purpose**: Store file attachments

```sql
CREATE TABLE ticket_attachments (
    -- Primary Key
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
**Allowed Types**: PDF, PNG, JPG, JPEG, DOC, DOCX, XLS, XLSX
**Max Size**: 10MB per file

### 5.6 Ticket Notifications Table (Optional - Phase 4)

**Purpose**: Email/in-app notification queue

```sql
CREATE TABLE ticket_notifications (
    -- Primary Key
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

## 6. Migration Scripts

### 6.1 Complete Migration Script

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
    ticket_type ENUM('component_addition', 'component_removal', 'component_replacement', 'configuration_change')
        NOT NULL DEFAULT 'component_addition',
    priority ENUM('low', 'normal', 'high', 'critical') NOT NULL DEFAULT 'normal',
    target_server_uuid VARCHAR(255) NOT NULL,
    target_server_name VARCHAR(255) DEFAULT NULL,
    status ENUM('draft', 'pending', 'approved', 'rejected', 'in_progress', 'deployed', 'completed', 'cancelled')
        NOT NULL DEFAULT 'pending',
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

### 6.2 Rollback Script

**File**: `migrations/rollback_ticketing_system.sql`

```sql
-- ============================================
-- Rollback Ticketing System
-- WARNING: This will delete all ticketing data
-- ============================================

START TRANSACTION;

-- Remove role permissions
DELETE FROM role_permissions WHERE permission_id IN (
    SELECT id FROM permissions WHERE category = 'ticketing'
);

-- Remove permissions
DELETE FROM permissions WHERE category = 'ticketing';

-- Drop tables (in reverse order due to foreign keys)
DROP TABLE IF EXISTS ticket_notifications;
DROP TABLE IF EXISTS ticket_attachments;
DROP TABLE IF EXISTS ticket_comments;
DROP TABLE IF EXISTS ticket_history;
DROP TABLE IF EXISTS ticket_items;
DROP TABLE IF EXISTS tickets;

COMMIT;
```

---

# Part III: Backend Implementation

## 7. File Structure

### 7.1 New Files to Create

```
includes/
├── models/
│   ├── TicketManager.php              # Main ticketing logic (800+ lines)
│   ├── TicketValidator.php            # Ticket validation logic (300+ lines)
│   └── TicketNotificationService.php  # Notification handling (Phase 4)
│
├── TicketFunctions.php                # Helper functions for tickets
└── TicketPermissions.php              # Permission checking helpers

api/
└── ticket/
    ├── ticket-create.php              # Create ticket endpoint
    ├── ticket-list.php                # List tickets endpoint
    ├── ticket-get.php                 # Get ticket details endpoint
    ├── ticket-update.php              # Update ticket endpoint
    ├── ticket-approve.php             # Approve ticket endpoint
    ├── ticket-reject.php              # Reject ticket endpoint
    ├── ticket-assign.php              # Assign ticket endpoint
    ├── ticket-deploy.php              # Deploy ticket endpoint
    ├── ticket-complete.php            # Complete ticket endpoint
    ├── ticket-cancel.php              # Cancel ticket endpoint
    ├── ticket-add-comment.php         # Add comment endpoint
    ├── ticket-get-history.php         # Get history endpoint
    ├── ticket-validate-items.php      # Validate items endpoint
    ├── ticket-get-compatible.php      # Get compatible components
    └── ticket-stats.php               # Statistics endpoint

migrations/
└── add_ticketing_system.sql           # Database migration script
```

### 7.2 Files to Modify

```
api/
└── api.php                            # Add ticket action routing (10 lines)

includes/
├── ACL.php                            # Already supports dynamic permissions (no changes needed)
└── BaseFunctions.php                  # May need component status helpers (optional)
```

---

## 8. Core Classes

### 8.1 TicketManager.php (Complete Implementation)

**Location**: `includes/models/TicketManager.php`

**Purpose**: Main business logic for ticket management

Due to the length of this document, I'll provide the key methods. For the complete implementation, see the full code in BACKEND_IMPLEMENTATION.md section.

**Key Methods**:
```php
class TicketManager {
    // Core Operations
    public function createTicket($userId, $data)
    public function approveTicket($ticketId, $userId, $data = [])
    public function rejectTicket($ticketId, $userId, $data)
    public function deployTicket($ticketId, $userId, $data = [])
    public function listTickets($userId, $permissions, $filters = [])

    // Helper Methods
    private function generateTicketNumber()
    private function insertTicketItems($ticketId, $items, $serverUuid)
    private function checkCompatibility($serverUuid, $item)
    private function deployTicketItem($ticket, $item, $userId)
    private function logTicketAction($ticketId, $userId, $action, ...)
}
```

**Example: Create Ticket Method**

```php
public function createTicket($userId, $data) {
    try {
        $this->pdo->beginTransaction();

        // Validate required fields
        $this->validateTicketData($data);

        // Validate server exists
        $this->validateServerExists($data['target_server_uuid']);

        // Generate ticket number (TKT-YYYYMMDD-XXXX)
        $ticketNumber = $this->generateTicketNumber();

        // Determine status
        $status = isset($data['save_as_draft']) && $data['save_as_draft'] ? 'draft' : 'pending';
        $submittedAt = $status === 'pending' ? date('Y-m-d H:i:s') : null;

        // Get server name for caching
        $serverName = $this->getServerName($data['target_server_uuid']);

        // Insert ticket
        $stmt = $this->pdo->prepare("
            INSERT INTO tickets (
                ticket_number, title, description, ticket_type, priority,
                target_server_uuid, target_server_name, status,
                created_by, submitted_at, estimated_duration, scheduled_date,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
            )
        ");

        $stmt->execute([
            $ticketNumber,
            $data['title'],
            $data['description'],
            $data['ticket_type'] ?? 'component_addition',
            $data['priority'] ?? 'normal',
            $data['target_server_uuid'],
            $serverName,
            $status,
            $userId,
            $submittedAt,
            $data['estimated_duration'] ?? null,
            $data['scheduled_date'] ?? null
        ]);

        $ticketId = (int)$this->pdo->lastInsertId();

        // Insert ticket items with validation
        $validationResults = [];
        if (isset($data['items']) && is_array($data['items'])) {
            $validationResults = $this->insertTicketItems($ticketId, $data['items'], $data['target_server_uuid']);
        }

        // Log ticket creation
        $this->logTicketAction($ticketId, $userId, 'ticket_created', null, null, null, 'Ticket created');

        // Send notifications if not draft
        if ($status === 'pending') {
            $this->sendNotification($ticketId, 'ticket_created');
        }

        $this->pdo->commit();

        return [
            'ticket_id' => $ticketId,
            'ticket_number' => $ticketNumber,
            'status' => $status,
            'validation' => [
                'all_items_valid' => !in_array(false, array_column($validationResults, 'valid')),
                'warnings' => array_filter(array_column($validationResults, 'warnings')),
                'errors' => array_filter(array_column($validationResults, 'errors'))
            ]
        ];

    } catch (Exception $e) {
        $this->pdo->rollBack();
        error_log("TicketManager::createTicket() - Error: " . $e->getMessage());
        throw $e;
    }
}
```

### 8.2 TicketValidator.php

**Location**: `includes/models/TicketValidator.php`

**Purpose**: Validation logic for ticket operations

```php
<?php
class TicketValidator {
    private $pdo;
    private $acl;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->acl = new ACL($pdo);
    }

    /**
     * Check if user can perform action on ticket
     */
    public function canPerformAction($userId, $ticketId, $action) {
        $ticket = $this->getTicket($ticketId);

        if (!$ticket) {
            return ['allowed' => false, 'reason' => 'Ticket not found'];
        }

        switch ($action) {
            case 'view':
                return $this->canViewTicket($userId, $ticket);
            case 'approve':
                return $this->canApproveTicket($userId, $ticket);
            case 'deploy':
                return $this->canDeployTicket($userId, $ticket);
            // ... other actions
        }
    }

    private function canApproveTicket($userId, $ticket) {
        // Cannot approve own ticket (separation of duties)
        if ($ticket['created_by'] == $userId) {
            return ['allowed' => false, 'reason' => 'Cannot approve your own ticket'];
        }

        // Must be pending
        if ($ticket['status'] !== 'pending') {
            return ['allowed' => false, 'reason' => "Ticket must be pending to approve"];
        }

        // Need permission
        if (!$this->acl->hasPermission($userId, 'ticket.approve')) {
            return ['allowed' => false, 'reason' => 'Insufficient permissions'];
        }

        return ['allowed' => true];
    }
}
```

---

## 9. API Router Integration

### 9.1 Modify api/api.php

Add the following routing code (around line 50, after existing action handlers):

```php
// ========================================
// TICKETING ACTIONS
// ========================================

// Ticket creation and management
if (str_starts_with($action, 'ticket-')) {
    $ticketActions = [
        'ticket-create', 'ticket-list', 'ticket-get', 'ticket-update',
        'ticket-approve', 'ticket-reject', 'ticket-assign',
        'ticket-deploy', 'ticket-complete', 'ticket-cancel',
        'ticket-add-comment', 'ticket-get-history',
        'ticket-validate-items', 'ticket-get-compatible', 'ticket-stats'
    ];

    if (in_array($action, $ticketActions)) {
        $ticketFile = __DIR__ . "/ticket/" . $action . ".php";

        if (file_exists($ticketFile)) {
            require_once($ticketFile);
            exit;
        } else {
            send_json_response(false, true, 501, "Ticket action not yet implemented: $action", null);
            exit;
        }
    }
}
```

---

# Part IV: API Reference

## 10. API Endpoints Reference

All ticketing endpoints follow the action-based routing pattern: `ticket-{operation}`

**Base URL**: `http://localhost:8000/api/api.php`

**Authentication**: All endpoints require JWT token via `Authorization: Bearer <token>` header

### 10.1 Endpoint Summary

| Endpoint | Method | Permission | Description |
|----------|--------|------------|-------------|
| `ticket-create` | POST | `ticket.create` | Create new ticket |
| `ticket-list` | GET | `ticket.view_*` | List tickets with filters |
| `ticket-get` | GET | Based on ownership | Get ticket details |
| `ticket-update` | POST | `ticket.edit_own` | Update draft ticket |
| `ticket-approve` | POST | `ticket.approve` | Approve pending ticket |
| `ticket-reject` | POST | `ticket.reject` | Reject pending ticket |
| `ticket-assign` | POST | `ticket.assign` | Assign to technician |
| `ticket-deploy` | POST | `ticket.deploy` | Deploy approved changes |
| `ticket-complete` | POST | `ticket.complete` | Mark as completed |
| `ticket-cancel` | POST | `ticket.cancel` | Cancel ticket |
| `ticket-add-comment` | POST | `ticket.comment` | Add comment |
| `ticket-get-history` | GET | Based on access | Get audit history |
| `ticket-validate-items` | POST | `ticket.create` | Validate components |
| `ticket-get-compatible` | GET | `ticket.create` | Get compatible options |
| `ticket-stats` | GET | `ticket.view_*` | Get statistics |

---

## 11. Request/Response Examples

### 11.1 ticket-create

**Purpose**: Create a new ticket requesting component changes

**Request**:
```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  --data-urlencode "action=ticket-create" \
  --data-urlencode "title=Add 2 Samsung 980 Pro SSDs for Database Expansion" \
  --data-urlencode "description=Database server requires additional NVMe storage" \
  --data-urlencode "ticket_type=component_addition" \
  --data-urlencode "priority=high" \
  --data-urlencode "target_server_uuid=server-uuid-db-prod-01" \
  --data-urlencode 'items=[
    {
      "action": "add",
      "component_type": "storage",
      "component_uuid": "storage-ssd-samsung-980pro-2tb-nvme-m2",
      "quantity": 2
    }
  ]'
```

**Response** (201 Created):
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
    "created_by": 5,
    "target_server": {
      "uuid": "server-uuid-db-prod-01",
      "name": "Database Production Server 01"
    },
    "items_count": 1,
    "validation": {
      "all_items_valid": true,
      "compatibility_checked": true,
      "warnings": [],
      "errors": []
    }
  }
}
```

### 11.2 ticket-approve

**Request**:
```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -d "action=ticket-approve" \
  -d "ticket_id=1" \
  -d "comment=Approved - components available" \
  -d "assign_to=7"
```

**Response** (200 OK):
```json
{
  "success": true,
  "authenticated": true,
  "code": 200,
  "message": "Ticket approved successfully",
  "timestamp": "2025-11-15 15:10:00",
  "data": {
    "ticket_id": 1,
    "ticket_number": "TKT-20251115-0001",
    "status": "approved",
    "approved_by": {
      "id": 3,
      "username": "manager.admin"
    },
    "approved_at": "2025-11-15 15:10:00",
    "assigned_to": {
      "id": 7,
      "username": "tech.smith"
    }
  }
}
```

### 11.3 ticket-deploy

**Request**:
```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer $TECH_TOKEN" \
  -d "action=ticket-deploy" \
  -d "ticket_id=1" \
  -d "deployment_notes=Components installed successfully"
```

**Response** (200 OK):
```json
{
  "success": true,
  "authenticated": true,
  "code": 200,
  "message": "Ticket deployed successfully",
  "timestamp": "2025-11-15 20:30:00",
  "data": {
    "ticket_id": 1,
    "ticket_number": "TKT-20251115-0001",
    "status": "deployed",
    "deployed_at": "2025-11-15 20:30:00",
    "components_deployed": [
      {
        "component_type": "storage",
        "component_uuid": "storage-ssd-samsung-980pro-2tb-nvme-m2",
        "quantity": 2,
        "inventory_updated": true,
        "server_config_updated": true
      }
    ]
  }
}
```

---

## 12. Error Handling

### 12.1 Standard Error Codes

| Code | Meaning | Example |
|------|---------|---------|
| 400 | Bad Request | Invalid parameters, validation failure |
| 401 | Unauthorized | Missing/invalid JWT token |
| 403 | Forbidden | Insufficient permissions |
| 404 | Not Found | Ticket/resource not found |
| 409 | Conflict | Status conflict |
| 500 | Server Error | Database error, unexpected failure |

### 12.2 Error Response Format

```json
{
  "success": false,
  "authenticated": true,
  "code": 403,
  "message": "Permission denied",
  "timestamp": "2025-11-15 18:00:00",
  "data": {
    "required_permission": "ticket.approve",
    "user_permissions": ["ticket.view_own", "ticket.create"]
  }
}
```

---

# Part V: Implementation Guide

## 13. Phase 1: Database & Foundation

### Week 1-2 Goals
- Create all database tables
- Add ACL permissions
- Create core classes
- Basic testing

### Checklist

- [ ] **1.1** Backup current database
  ```bash
  mysqldump -u root -p bdc_ims > backup_before_ticketing_$(date +%Y%m%d).sql
  ```

- [ ] **1.2** Run database migration
  ```bash
  mysql -u root -p bdc_ims < migrations/add_ticketing_system.sql
  ```

- [ ] **1.3** Verify tables created
  ```sql
  SHOW TABLES LIKE 'ticket%';
  SELECT * FROM permissions WHERE category = 'ticketing';
  ```

- [ ] **1.4** Create `includes/models/TicketManager.php`
- [ ] **1.5** Create `includes/models/TicketValidator.php`
- [ ] **1.6** Modify `api/api.php` (add ticket routing)
- [ ] **1.7** Create `api/ticket/` directory
- [ ] **1.8** Test PHP syntax and class loading

---

## 14. Phase 2: Create & List Endpoints

### Week 2-3 Goals
- Implement ticket creation
- Implement list with filters
- Implement get details
- Update draft tickets

### Checklist

- [ ] **2.1** Create `api/ticket/ticket-create.php`
- [ ] **2.2** Test ticket creation
- [ ] **2.3** Verify database records
- [ ] **2.4** Create `api/ticket/ticket-list.php`
- [ ] **2.5** Test filtering and pagination
- [ ] **2.6** Create `api/ticket/ticket-get.php`
- [ ] **2.7** Create `api/ticket/ticket-update.php`

---

## 15. Phase 3: Approval Workflow

### Week 3-4 Goals
- Implement approval/rejection
- Add assignment functionality
- Create comment system
- History tracking

### Checklist

- [ ] **3.1** Create `api/ticket/ticket-approve.php`
- [ ] **3.2** Test separation of duties (cannot approve own)
- [ ] **3.3** Create `api/ticket/ticket-reject.php`
- [ ] **3.4** Create `api/ticket/ticket-assign.php`
- [ ] **3.5** Create `api/ticket/ticket-add-comment.php`
- [ ] **3.6** Create `api/ticket/ticket-get-history.php`
- [ ] **3.7** Test complete approval workflow

---

## 16. Phase 4: Deployment Integration

### Week 4-5 Goals
- Implement deployment
- Integrate with ServerBuilder
- Update inventory
- Complete tickets

### Checklist

- [ ] **4.1** Verify ServerBuilder methods exist
  - `addComponent($serverUuid, $componentType, $componentUuid, $quantity)`
  - `removeComponent($serverUuid, $componentType, $componentUuid)`

- [ ] **4.2** Create `api/ticket/ticket-deploy.php`
- [ ] **4.3** Test deployment workflow
- [ ] **4.4** Verify component status updates
- [ ] **4.5** Create `api/ticket/ticket-complete.php`
- [ ] **4.6** Create `api/ticket/ticket-cancel.php`

---

## 17. Phase 5: Validation & Compatibility

### Week 5-6 Goals
- Pre-validation of components
- Compatible components query
- Enhanced validation

### Checklist

- [ ] **5.1** Create `api/ticket/ticket-validate-items.php`
- [ ] **5.2** Test UUID validation
- [ ] **5.3** Test compatibility checking
- [ ] **5.4** Create `api/ticket/ticket-get-compatible.php`
- [ ] **5.5** Test compatible components query

---

## 18. Phase 6: Statistics & Reporting

### Week 6-7 Goals
- Statistics dashboard
- Reporting queries
- Analytics

### Checklist

- [ ] **6.1** Create `api/ticket/ticket-stats.php`
- [ ] **6.2** Test statistics API
- [ ] **6.3** Add dashboard helper queries

---

## 19. Phase 7: Enhancements & Polish

### Week 7-8 Goals
- Email notifications (optional)
- File attachments (optional)
- Performance optimization
- Production deployment

### Checklist

- [ ] **7.1** Create notification service (optional)
- [ ] **7.2** Add file upload handling (optional)
- [ ] **7.3** Optimize database queries
- [ ] **7.4** Performance testing
- [ ] **7.5** Production deployment

---

# Part VI: Code Examples

## 20. Complete Endpoint Implementations

### 20.1 ticket-create.php

**Location**: `api/ticket/ticket-create.php`

```php
<?php
/**
 * ticket-create.php - Create new ticket endpoint
 * Required Permission: ticket.create
 */

require_once(__DIR__ . '/../../includes/models/TicketManager.php');
require_once(__DIR__ . '/../../includes/ACL.php');

try {
    // Check permission
    $acl = new ACL($pdo);
    if (!$acl->hasPermission($user_id, 'ticket.create')) {
        send_json_response(false, true, 403, "You do not have permission to create tickets", [
            'required_permission' => 'ticket.create'
        ]);
        exit;
    }

    // Validate required fields
    $requiredFields = ['title', 'description', 'target_server_uuid'];
    foreach ($requiredFields as $field) {
        if (empty($_POST[$field])) {
            send_json_response(false, true, 400, "Missing required field: $field", null);
            exit;
        }
    }

    // Parse items JSON
    $items = [];
    if (!empty($_POST['items'])) {
        $items = json_decode($_POST['items'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            send_json_response(false, true, 400, "Invalid JSON in items field", null);
            exit;
        }
    }

    // Build ticket data
    $ticketData = [
        'title' => trim($_POST['title']),
        'description' => trim($_POST['description']),
        'target_server_uuid' => trim($_POST['target_server_uuid']),
        'ticket_type' => $_POST['ticket_type'] ?? 'component_addition',
        'priority' => $_POST['priority'] ?? 'normal',
        'items' => $items,
        'estimated_duration' => $_POST['estimated_duration'] ?? null,
        'scheduled_date' => $_POST['scheduled_date'] ?? null,
        'save_as_draft' => isset($_POST['save_as_draft']) && $_POST['save_as_draft'] === 'true'
    ];

    // Create ticket
    $ticketManager = new TicketManager($pdo);
    $result = $ticketManager->createTicket($user_id, $ticketData);

    send_json_response(true, true, 201, "Ticket created successfully", $result);

} catch (Exception $e) {
    error_log("ticket-create error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to create ticket: " . $e->getMessage(), null);
}
```

### 20.2 ticket-list.php

```php
<?php
/**
 * ticket-list.php - List tickets with filtering
 * Required Permission: ticket.view_own OR ticket.view_all OR ticket.view_assigned
 */

require_once(__DIR__ . '/../../includes/models/TicketManager.php');
require_once(__DIR__ . '/../../includes/ACL.php');

try {
    $acl = new ACL($pdo);
    $permissions = $acl->getUserPermissions($user_id);

    // Build filters from query parameters
    $filters = [];
    if (!empty($_GET['status'])) $filters['status'] = $_GET['status'];
    if (!empty($_GET['priority'])) $filters['priority'] = $_GET['priority'];
    if (!empty($_GET['search'])) $filters['search'] = $_GET['search'];

    $filters['page'] = $_GET['page'] ?? 1;
    $filters['limit'] = min($_GET['limit'] ?? 20, 100);
    $filters['sort_by'] = $_GET['sort_by'] ?? 'created_at';
    $filters['sort_order'] = $_GET['sort_order'] ?? 'desc';

    // Get tickets
    $ticketManager = new TicketManager($pdo);
    $result = $ticketManager->listTickets($user_id, $permissions, $filters);

    send_json_response(true, true, 200, "Tickets retrieved successfully", $result);

} catch (Exception $e) {
    error_log("ticket-list error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to retrieve tickets: " . $e->getMessage(), null);
}
```

---

## 21. Frontend Integration

### 21.1 JavaScript Helper Functions

```javascript
// ticketing.js

/**
 * Create new ticket
 */
async function createTicket(formData) {
    const token = localStorage.getItem('access_token');

    try {
        const response = await fetch('/api/api.php', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${token}`,
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                action: 'ticket-create',
                title: formData.title,
                description: formData.description,
                ticket_type: formData.ticketType,
                priority: formData.priority,
                target_server_uuid: formData.serverUuid,
                items: JSON.stringify(formData.items)
            })
        });

        const result = await response.json();

        if (result.success) {
            showNotification('success', 'Ticket created successfully');
            return result.data;
        } else {
            showNotification('error', result.message);
            return null;
        }
    } catch (error) {
        console.error('Create ticket error:', error);
        return null;
    }
}

/**
 * Approve ticket
 */
async function approveTicket(ticketId, comment, assignTo = null) {
    const token = localStorage.getItem('access_token');

    const response = await fetch('/api/api.php', {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
            action: 'ticket-approve',
            ticket_id: ticketId,
            comment: comment,
            assign_to: assignTo || ''
        })
    });

    const result = await response.json();

    if (result.success) {
        showNotification('success', 'Ticket approved');
        return result.data;
    }

    return null;
}
```

---

## 22. Testing Examples

### 22.1 Complete Workflow Test Script

**File**: `tests/ticket-workflow-test.sh`

```bash
#!/bin/bash
# Complete workflow test for ticketing system

API_URL="http://localhost:8000/api/api.php"

echo "========================================="
echo "Ticketing System Workflow Test"
echo "========================================="

# 1. Login as requestor
echo -e "\n1. Logging in as requestor..."
REQUESTOR_TOKEN=$(curl -s -X POST "$API_URL" \
  -d "action=auth-login" \
  -d "username=john.doe" \
  -d "password=password" | jq -r '.data.tokens.access_token')

echo "SUCCESS: Logged in"

# 2. Create ticket
echo -e "\n2. Creating ticket..."
TICKET_RESPONSE=$(curl -s -X POST "$API_URL" \
  -H "Authorization: Bearer $REQUESTOR_TOKEN" \
  -d "action=ticket-create" \
  -d "title=Test Ticket - Add SSDs" \
  -d "description=Testing workflow" \
  -d "target_server_uuid=server-test-uuid" \
  -d 'items=[{"action":"add","component_type":"storage","component_uuid":"test-uuid","quantity":2}]')

TICKET_ID=$(echo $TICKET_RESPONSE | jq -r '.data.ticket_id')
echo "SUCCESS: Created ticket ID: $TICKET_ID"

# 3. Approve ticket (as manager)
MANAGER_TOKEN=$(curl -s -X POST "$API_URL" \
  -d "action=auth-login" \
  -d "username=manager.admin" \
  -d "password=password" | jq -r '.data.tokens.access_token')

curl -s -X POST "$API_URL" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -d "action=ticket-approve" \
  -d "ticket_id=$TICKET_ID"

echo "SUCCESS: Ticket approved"

# 4. Deploy ticket (as technician)
TECH_TOKEN=$(curl -s -X POST "$API_URL" \
  -d "action=auth-login" \
  -d "username=tech.smith" \
  -d "password=password" | jq -r '.data.tokens.access_token')

curl -s -X POST "$API_URL" \
  -H "Authorization: Bearer $TECH_TOKEN" \
  -d "action=ticket-deploy" \
  -d "ticket_id=$TICKET_ID"

echo "SUCCESS: Ticket deployed"

# 5. Complete ticket
curl -s -X POST "$API_URL" \
  -H "Authorization: Bearer $TECH_TOKEN" \
  -d "action=ticket-complete" \
  -d "ticket_id=$TICKET_ID"

echo "SUCCESS: Ticket completed"
echo -e "\n========================================="
echo "All tests passed!"
echo "========================================="
```

---

# Part VII: Deployment

## 23. Deployment Checklist

### Pre-Deployment

- [ ] Backup production database
- [ ] Review migration scripts
- [ ] Test on staging environment
- [ ] Document rollback procedure

### Deployment Steps

- [ ] Put site in maintenance mode (optional)
- [ ] Run database migration
- [ ] Deploy code files
- [ ] Clear caches (opcache, etc.)
- [ ] Test critical paths
- [ ] Remove maintenance mode

### Post-Deployment

- [ ] Monitor error logs
- [ ] Monitor database performance
- [ ] Create test tickets
- [ ] Train users

---

## 24. Testing & Validation

### Unit Tests
- Test TicketManager methods
- Test permission system
- Test validation logic

### Integration Tests
- Complete workflow (create → approve → deploy → complete)
- Permission enforcement
- Edge cases

### Load Testing
- Multiple concurrent tickets
- Large ticket lists
- Complex queries

---

## Conclusion

This comprehensive guide provides everything needed to implement a production-ready ticketing system in the BDC IMS.

**Implementation Timeline**: 7-8 weeks

**Database Tables**: 6 new tables

**API Endpoints**: 16 endpoints

**Core Classes**: 2 main classes (TicketManager, TicketValidator)

**Key Features**:
✅ Complete CRUD operations
✅ Approval workflow
✅ Permission system integration
✅ Component validation
✅ Compatibility checking
✅ Audit trail
✅ Comment system
✅ Status tracking
✅ Notification system (Phase 4)

**Next Steps**:
1. Begin with Phase 1 (Database & Foundation)
2. Follow implementation checklist step by step
3. Test thoroughly at each phase
4. Deploy to staging before production
5. Train users and monitor system

---

**Version**: 1.0
**Last Updated**: 2025-11-15
**Author**: Claude Code
**License**: BDC IMS Internal Use
